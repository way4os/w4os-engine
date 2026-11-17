<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Exceptions\InvalidValue;
use OpenSim\Exceptions\NotFound;
use OpenSim\Models\Grid;
use OpenSim\Network\Ports;
use OpenSim\Runtime\Environment;

/**
 * A setup file (SetupFile) applied to a grid of this machine, without a question: the simulators it names are made, or given the
 * regions it names when they exist. What would be written, and where, is told first (prepare(), document()); apply() writes it.
 *
 *     $setup = SetupApply::prepare($environment, SetupFile::read($text));
 *     $setup->document();          // the plan, nothing written; `problems` by the place of the answer in the file
 *     $setup->apply($ui);
 *
 * The grid is the one of `grid.nick`, or the only grid there is; a file that makes a grid (`grid.name`) is the quick setup's. The
 * answers the file does not give are the ones the planners propose (ports, places, database), as they do for the questions of a
 * setup; two simulators or regions of the same file never get the same port or the same place.
 */
final class SetupApply
{
    /**
     * @param list<array{kind:string,plan:SimulatorPlan}> $steps what is written, in order: a simulator (with its first region), a region
     * @param array<string,string> $problems by the place of the answer in the file (simulators.0.regions.1.location)
     */
    private function __construct(
        private readonly Environment $environment,
        public readonly Grid $grid,
        public readonly array $steps,
        public readonly array $problems,
    ) {}

    /**
     * What the file stands for, checked, nothing written.
     *
     * @param array<string,mixed> $data a setup (SetupFile::read()), checked here
     * @param ?DatabaseService $database what reads the grid's registry of regions (default: the one of the machine)
     * @throws NotFound for a grid this machine does not have
     * @throws InvalidValue for a file that makes a grid, or names none when the machine has not exactly one
     */
    public static function prepare(Environment $environment, array $data, ?DatabaseService $database = null): self
    {
        $nick = trim((string) ($data['grid']['nick'] ?? ''));
        $known = array_map(static fn(Grid $grid): string => $grid->nick(), Grid::all($environment)->items());
        if (trim((string) ($data['grid']['name'] ?? '')) !== '' && !in_array($nick, $known, true)) {
            throw InvalidValue::because('invalid-request', 'this file makes a grid: that is the setup of a grid (opensim setup --apply)');
        }
        if ($nick === '') {
            count($known) === 1 || throw InvalidValue::because('invalid-request', 'the file names no grid (grid.nick), and this machine has not exactly one');
            $nick = $known[0];
        }
        $grid = Grid::find($environment, $nick) ?? throw NotFound::for("grid called '$nick'");
        $ui = new NullUi();
        $database ??= new DatabaseService($ui);
        $registry = new GridRegistry($database);
        // What the grid has, and what this file takes as it goes: no two of its regions get one port, one place or one name
        $taken = ['names' => $registry->names($grid), 'places' => $registry->locations($grid), 'ports' => $registry->ports($grid) ?? []];
        // The ports the simulators of this machine and the regions the grids know take, for the block of a new simulator
        $foreign = $registry->knownPorts($environment, $grid);

        // What the file says of itself, the simulators the grid has apart (no database is made for them)
        $existing = [];
        foreach ($data['simulators'] ?? [] as $i => $simulator) {
            trim((string) ($simulator['name'] ?? '')) !== '' && $grid->simulator((string) $simulator['name']) !== null && ($existing[] = $i);
        }
        $steps = [];
        $problems = [];
        foreach (SetupFile::problems($data, $existing) as $n => $message) {
            $problems["file.$n"] = $message;
        }
        foreach ($data['simulators'] ?? [] as $i => $simulator) {
            $name = trim((string) ($simulator['name'] ?? ''));
            $regions = array_values((array) ($simulator['regions'] ?? []));
            // A simulator of the grid that has this name (its instance, or its own name without the grid) is given the regions
            $found = $name !== '' ? $grid->simulator($name) : null;
            $exists = $found !== null;
            if ($found !== null) {
                $plan = self::existing($grid, $found);
            } else {
                $planner = SimulatorPlanner::forGrid($grid, $database, $ui, null, $regions[0]['name'] ?? null);
                $answers = SetupFile::simulatorValues($data, $i) + $planner->ownerAnswers(SetupFile::owner($data, $i));
                $planner = $planner->with(['block' => self::block($grid, [...$foreign, ...$taken['ports']])]);
                foreach ($planner->problems($answers) as $field => $message) {
                    $problems["simulators.$i.$field"] = $message;
                }
                $plan = $planner->plan($answers);
                // Written, not started: the operator starts it (reminders)
                $plan->start = false;
                $taken['ports'] = array_merge($taken['ports'], range($plan->httpPort, $plan->httpPort + 9));
            }
            // A new simulator is written with its first region, the others after it
            $added = [];
            foreach ($regions as $j => $_) {
                $regionPlan = $j === 0 && !$exists ? $plan : self::regionOf($plan);
                $values = SetupFile::regionValues($data, $i, $j);
                $planner = RegionPlanner::forSimulator($regionPlan, $grid, $database, context: [
                    'taken' => $taken['names'],
                    'known' => $taken['places'],
                    'ports' => $taken['ports'],
                ]);
                foreach ($planner->problems($values) as $field => $message) {
                    $problems["simulators.$i.regions.$j.$field"] = $message;
                }
                $planner->apply($regionPlan, $values);
                $taken['names'][strtolower($regionPlan->regionName)] = true;
                [$x, $y] = LocationFinder::parse($regionPlan->regionLocation) ?? [0, 0];
                $span = max(1, intdiv($regionPlan->regionSize, 256));
                LocationFinder::take($taken['places'], $x, $y, $span, $span);
                $taken['ports'][] = $regionPlan->regionPort;
                $regionPlan === $plan || ($added[] = ['kind' => 'region', 'plan' => $regionPlan]);
            }
            $exists || ($steps[] = ['kind' => 'simulator', 'plan' => $plan]);
            array_push($steps, ...$added);
        }

        return new self($environment, $grid, $steps, $problems);
    }

    /**
     * What would be written, and where: the plan, as the API gives it.
     *
     * @return array{grid:string,steps:list<array<string,mixed>>,writes:list<string>,problems:array<string,string>,reminders:list<string>}
     */
    public function document(): array
    {
        $steps = [];
        $writes = [];
        foreach ($this->steps as ['kind' => $kind, 'plan' => $plan]) {
            $step = ['kind' => $kind, 'simulator' => $plan->simulatorName, 'slug' => $plan->slug];
            if ($kind === 'simulator') {
                $step += [
                    'config' => $plan->iniPath(),
                    'ports' => ['http' => $plan->httpPort, 'console' => $plan->consoleMode === 'rest' ? $plan->consolePort : null],
                    'database' => ['host' => $plan->dbHost, 'name' => $plan->dbName, 'user' => $plan->dbUser],
                ];
                $writes[] = $plan->iniPath();
            }
            if ($plan->createRegion) {
                $step['region'] = [
                    'name' => $plan->regionName,
                    'location' => $plan->regionLocation,
                    'size' => $plan->regionSize,
                    'roles' => $plan->regionRoles,
                    'port' => $plan->regionPort,
                    'file' => $plan->regionIni(),
                ];
                $writes[] = $plan->regionIni();
            }
            $steps[] = $step;
        }

        $reminders = [];
        foreach ($this->steps as ['kind' => $kind, 'plan' => $plan]) {
            $reminders[$plan->slug] = $kind === 'simulator'
                ? "opensim start {$plan->slug}"
                : $reminders[$plan->slug] ?? "opensim restart {$plan->slug} (it loads its new regions when it starts)";
        }

        return [
            'grid' => $this->grid->nick(),
            'steps' => $steps,
            'writes' => $writes,
            'problems' => $this->problems,
            'reminders' => array_values($reminders),
        ];
    }

    /**
     * Write what the file stands for, in order: the database of a new simulator first (made when it is missing), then its files.
     *
     * @param ?\Closure(GridPlan, UiInterface):int $database how a database is checked and made (default: DatabaseService::ensure())
     * @throws InvalidValue when the file has problems, or a database cannot be had
     */
    public function apply(UiInterface $ui, ?\Closure $database = null): void
    {
        if ($this->problems !== []) {
            throw InvalidValue::because('invalid-request', 'the setup has problems: ' . implode('; ', array_map(
                static fn(string $place, string $message): string => "$place: $message",
                array_keys($this->problems),
                $this->problems,
            )));
        }
        $database ??= static fn(GridPlan $db, UiInterface $said): int => (new DatabaseService($said))->ensure($db);
        foreach ($this->steps as ['kind' => $kind, 'plan' => $plan]) {
            if ($kind === 'simulator' && !$plan->standalone && $database($plan->databasePlan(), $ui) !== DatabaseService::OK) {
                throw InvalidValue::because('invalid-request', "the database of the simulator {$plan->slug} cannot be had: {$plan->dbName} @ {$plan->dbHost}");
            }
            (new SimulatorProvisioner($ui))->apply($plan, $this->environment);
        }
    }

    /** The first block of ten ports of a simulator of the grid that none of these takes. */
    private static function block(Grid $grid, array $ports): int
    {
        return (new Ports($grid->environment()))->nextBlock(Ports::simulatorsFrom($grid->publicPort()), $ports);
    }

    /** The plan of a simulator of the grid that exists, which is given a region. */
    private static function existing(Grid $grid, \OpenSim\Models\Simulator $simulator): SimulatorPlan
    {
        $plan = new SimulatorPlan();
        $plan->gridNick = $grid->nick();
        $plan->gridName = $grid->name();
        $plan->gridDir = $grid->directory();
        $plan->hypergrid = $grid->isHypergrid();
        $plan->logsDirectory = $grid->logsDirectory();
        $plan->simulatorName = $simulator->displayName();
        $plan->slug = $simulator->slug();
        $plan->httpPort = (int) $simulator->httpPort();
        $plan->start = false;

        return self::regionOf($plan);
    }

    /** The plan of a region of the simulator of this plan: only its region is written. */
    private static function regionOf(SimulatorPlan $simulator): SimulatorPlan
    {
        $plan = clone $simulator;
        $plan->regionOnly = true;
        $plan->createRegion = true;
        $plan->start = false;
        // The address the regions of the simulator announce, when it has some
        $files = glob("{$plan->regionsDir()}/*.ini") ?: [];
        $files === [] || ($plan->externalHost = Import\SimulatorImporter::externalHost($files));

        return $plan;
    }
}
