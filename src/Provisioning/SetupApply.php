<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning;

use W4OS\Engine\Archives\DatabaseDump;
use W4OS\Engine\Console\InstanceConsoleService;
use W4OS\Engine\Exceptions\InvalidValue;
use W4OS\Engine\Exceptions\NotFound;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Network\Ports;
use W4OS\Engine\Result;
use W4OS\Engine\Runtime\Environment;
use W4OS\Engine\Text\Notice;

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
     * @param array<string,array{0:string,1:string}> $admins the administrator of the database server of a new simulator, by its slug
     */
    private function __construct(
        private readonly Environment $environment,
        public readonly Grid $grid,
        public readonly array $steps,
        public readonly array $problems,
        private readonly array $admins = [],
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
        $grid = Grid::find($environment, $nick) ?? throw NotFound::for("grid called '$nick'", 'grids');
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
        $admins = [];
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
                // A running simulator loads its new regions through its console (start), a stopped one when it starts
                $plan->start = InstanceConsoleService::running($grid->simulatorLink($found->slug()), $environment);
            } else {
                $planner = SimulatorPlanner::forGrid($grid, $database, $ui, null, $regions[0]['name'] ?? null);
                $owner = SetupFile::owner($data, $i);
                // A simulator made for a region the grid has, taken over: its estate is the one of the owner of the region
                if ($owner['name'] === '' && Result::isTrue($regions[0]['existing'] ?? false)) {
                    $owner['name'] = self::ownerOf($grid, (string) ($regions[0]['name'] ?? ''));
                }
                $answers = SetupFile::simulatorValues($data, $i) + $planner->ownerAnswers($owner);
                // A region joins the estate of the name its simulator gives (DefaultEstateName), whoever owns it: a simulator that
                // names its owner has an estate of that owner, unless it names the estate
                $own = trim((string) ($simulator['estate']['owner'] ?? ''));
                if ($own !== '' && trim((string) ($simulator['estate']['name'] ?? '')) === '') {
                    $answers['estate_name'] = "$own Estate";
                }
                $planner = $planner->with(['block' => self::block($grid, [...$foreign, ...$taken['ports']])]);
                foreach ($planner->problems($answers) as $field => $message) {
                    $problems["simulators.$i.$field"] = $message;
                }
                $plan = $planner->plan($answers);
                // Written, not started: the operator starts it (reminders)
                $plan->start = false;
                // The administrator that makes its database: the one of the simulator, else the one of the grid
                $admin = array_filter([
                    $simulator['database']['admin_user'] ?? ($data['grid']['database']['admin_user'] ?? null),
                    $simulator['database']['admin_password'] ?? ($data['grid']['database']['admin_password'] ?? null),
                ], static fn($value): bool => $value !== null);
                isset($admin[0]) && ($admins[$plan->slug] = [(string) $admin[0], (string) ($admin[1] ?? '')]);
                // Its ports are taken, its block with them (the next simulator gets another); its regions are given the free ones of it
                $taken['ports'] = array_merge($taken['ports'], array_filter([$plan->httpPort, $plan->consolePort]));
            }
            // A new simulator is written with its first region, the others after it
            $added = [];
            foreach ($regions as $j => $_) {
                $regionPlan = $j === 0 && !$exists ? $plan : self::regionOf($plan);
                $values = SetupFile::regionValues($data, $i, $j);
                $planner = RegionPlanner::forSimulator($regionPlan, $grid, $database, context: array_intersect_key(self::$robust, ['registered' => 1, 'serving' => 1]) + [
                    'taken' => $taken['names'],
                    'known' => $taken['places'],
                    'ports' => $taken['ports'],
                ]);
                foreach ($planner->problems($values) as $field => $message) {
                    $problems["simulators.$i.regions.$j.$field"] = $message;
                }
                $planner->apply($regionPlan, $values);
                foreach (self::content($regions[$j] ?? [], $j === 0 && !$exists) as $field => $value) {
                    str_starts_with($value, '!')
                        ? ($problems["simulators.$i.regions.$j.$field"] = substr($value, 1))
                        : ($regionPlan->{$field === 'oar' ? 'regionArchive' : 'regionDump'} = $value);
                }
                // What the region is loaded with is loaded once it runs: its simulator starts
                if ($regionPlan->regionArchive !== '' || $regionPlan->regionDump !== '') {
                    $regionPlan->start = true;
                    $plan->start = true;
                }
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

        return new self($environment, $grid, $steps, $problems, $admins);
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
                    // The estate its regions join (DefaultEstateName), made for its owner when no estate has that name
                    'estate' => ['name' => $plan->estateName, 'owner' => $plan->estateOwner === '+' ? null : $plan->estateOwner],
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
                ] + ($plan->regionTakenOver ? ['existing' => true, 'uuid' => $plan->regionUuid] : [])
                    + array_filter(['oar' => $plan->regionArchive, 'dump' => $plan->regionDump]);
                $writes[] = $plan->regionIni();
            }
            $steps[] = $step;
        }

        $reminders = [];
        $roles = [];
        foreach ($this->steps as ['kind' => $kind, 'plan' => $plan]) {
            $start = ['slug' => $plan->slug, 'command' => "opensim start {$plan->slug}"];
            if ($kind === 'simulator') {
                $plan->start || ($reminders[$plan->slug] = Notice::of('start-simulator', $start));
            } elseif (!$plan->start) {
                $reminders[$plan->slug] ??= Notice::of('start-simulator-regions', $start);
            }
            // The roles of a region a running simulator loads are given then; the others when Robust has read its config
            $plan->start || $plan->regionRoles === [] || ($roles[] = Notice::of('region-roles-later', [
                'region' => $plan->regionName,
                'roles' => implode(', ', $plan->regionRoles),
                'slug' => $plan->slug,
                'restart' => 'opensim restart ' . $this->grid->nick(),
                'command' => sprintf('opensim region set %s "%s" --roles %s', $this->grid->nick(), $plan->regionName, implode(',', $plan->regionRoles)),
            ]));
        }

        return [
            'grid' => $this->grid->nick(),
            'steps' => $steps,
            'writes' => $writes,
            'problems' => $this->problems,
            'reminders' => [...array_values($reminders), ...$roles],
        ];
    }

    /**
     * Write what the file stands for, in order: the database of a new simulator first (made when it is missing), then its files.
     *
     * @param ?\Closure(GridPlan, UiInterface):int $database how a database is checked and made (default: DatabaseService::ensure())
     * @param ?\Closure(SimulatorPlan):void $load how the database of a new simulator is made from a dump (default: DatabaseDump::intoEmpty())
     * @throws InvalidValue when the file has problems, or a database cannot be had or made from its dump
     */
    public function apply(UiInterface $ui, ?\Closure $database = null, ?\Closure $load = null): void
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
            if ($kind === 'simulator' && !$plan->standalone) {
                isset($this->admins[$plan->slug]) && DatabaseService::rememberAdmin($plan->dbHost, ...$this->admins[$plan->slug]);
                DatabaseService::remember($plan->dbHost, $plan->dbUser, $plan->dbPass);
            }
            $mark = $ui instanceof NullUi ? count($ui->said) : 0;
            if ($kind === 'simulator' && !$plan->standalone && $database($plan->databasePlan(), $ui) !== DatabaseService::OK) {
                // What the database service said: why, and the commands to run by hand
                $said = $ui instanceof NullUi ? array_column(array_slice($ui->said, $mark), 1) : [];
                throw InvalidValue::because('invalid-request', implode("\n", [
                    "the database of the simulator {$plan->slug} cannot be had: {$plan->dbName} @ {$plan->dbHost}",
                    ...$said,
                    'An administrator of the database server makes it: database.admin_user and database.admin_password of the file (OPENSIM_DB_ADMIN_USER and OPENSIM_DB_ADMIN_PASS for opensim), or the commands above run by hand.',
                ]));
            }
            if ($kind === 'simulator' && $plan->regionDump !== '') {
                try {
                    ($load ?? static fn(SimulatorPlan $made): int => DatabaseDump::intoEmpty(
                        ['host' => $made->dbHost, 'name' => $made->dbName, 'user' => $made->dbUser, 'pass' => $made->dbPass],
                        $made->regionDump,
                    ))($plan);
                } catch (\RuntimeException $error) {
                    throw InvalidValue::because('invalid-request', "the database of the simulator {$plan->slug} cannot be made from {$plan->regionDump}: {$error->getMessage()}");
                }
                $ui->note(sprintf(_('Database %s made from %s.'), $plan->dbName, $plan->regionDump));
            }
            (new SimulatorProvisioner($ui))->apply($plan, $this->environment);
        }
    }

    /**
     * What a region of the file is loaded with: its archive (oar), or the dump of the database of the simulator it comes from
     * (dump), which makes the database of a new simulator only. A problem is the message after a '!'.
     *
     * @param array<string,mixed> $region
     * @return array<string,string> by the key of the file
     */
    private static function content(array $region, bool $newDatabase): array
    {
        $content = [];
        foreach (['oar', 'dump'] as $key) {
            $file = trim((string) ($region[$key] ?? ''));
            if ($file === '') {
                continue;
            }
            $content[$key] = match (true) {
                !is_file($file) || !is_readable($file) => "!cannot read $file",
                $key === 'dump' && !$newDatabase => '!the dump of a database makes the database of a new simulator: give the region a simulator of its own, or load its archive (oar)',
                default => $file,
            };
        }
        if (isset($content['oar'], $content['dump']) && $content['oar'][0] !== '!' && $content['dump'][0] !== '!') {
            $content['oar'] = '!an archive (oar) or the dump of a database (dump), not both';
        }

        return $content;
    }

    /** The name of the owner the grid keeps for a region, '' when it has none or does not answer. */
    private static function ownerOf(Grid $grid, string $region): string
    {
        $registered = isset(self::$robust['registered']) ? (self::$robust['registered'])($region) : RegionPlanner::registeredIn($grid, $region);
        if (!is_array($registered) || $registered['owner'] === '') {
            return '';
        }
        $name = isset(self::$robust['accountName'])
            ? (self::$robust['accountName'])($registered['owner'])
            : RobustGrid::accountName($grid->isRemote() ? $grid->baseHostname() : '127.0.0.1', $grid->privatePort(), $registered['owner']);

        return is_string($name) ? $name : '';
    }

    /**
     * What the Robust of the grid answers, for the tests (default: it is asked): registered (what it keeps of a region, by name),
     * serving (whether a simulator answers at an address), accountName (the name of an account, by UUID).
     *
     * @var array{registered?:\Closure,serving?:\Closure,accountName?:\Closure}
     */
    public static array $robust = [];

    /** The first block of ten ports of a simulator of the grid that none of these takes. */
    private static function block(Grid $grid, array $ports): int
    {
        return (new Ports($grid->environment()))->nextBlock(Ports::simulatorsFrom($grid->publicPort()), $ports);
    }

    /** The plan of a simulator of the grid that exists, which is given a region. */
    private static function existing(Grid $grid, \W4OS\Engine\Models\Simulator $simulator): SimulatorPlan
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
        // Loaded through the console when the simulator runs (start, as the simulator's plan says), else written only
        $plan = clone $simulator;
        $plan->regionOnly = true;
        $plan->createRegion = true;
        // The address the regions of the simulator announce, when it has some
        $files = glob("{$plan->regionsDir()}/*.ini") ?: [];
        $files === [] || ($plan->externalHost = Import\SimulatorImporter::externalHost($files));

        return $plan;
    }
}
