<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning\Import;

use W4OS\Engine\Exceptions\InvalidValue;
use W4OS\Engine\Models\Region;
use W4OS\Engine\Provisioning\UiInterface;
use W4OS\Engine\Runtime\Environment;

/**
 * Several simulators of older installs moved into one grid of this machine, planned together (SimulatorImport for each, against
 * the grid), then checked between them: two of them never get one slug, one port, one region name or UUID, or one square of the
 * map. Applied in their order, only when nothing is wrong.
 *
 *     $imports = SimulatorImports::prepare($environment, [['file' => '/opt/opensim/etc/opensim.d/a.ini'], ['file' => '.../b.ini']], 'speculoos');
 *     $imports->document();   // `steps` (the plan of each), `problems` (by the place of the simulator: simulators.1)
 *     $imports->apply($environment, $ui);
 */
final class SimulatorImports
{
    /**
     * @param array<int,SimulatorImport> $imports by the place of the simulator in the list
     * @param array<string,string> $problems by the place of the simulator (simulators.1), what is wrong
     */
    private function __construct(public readonly string $grid, public readonly array $imports, public readonly array $problems) {}

    /**
     * What the imports would do, checked each against the grid, then between them; nothing written.
     *
     * @param list<array{file:string,name?:?string,core?:?string,scripts?:bool}> $simulators
     * @param ?\W4OS\Engine\Models\Grid $planned the grid they join when it is not written yet (GridImport)
     */
    public static function prepare(Environment $environment, array $simulators, string $grid, ?\W4OS\Engine\Models\Grid $planned = null): self
    {
        $simulators !== [] || throw InvalidValue::because('invalid-request', 'no simulator to import (simulators)');
        $imports = [];
        $problems = [];
        foreach (array_values($simulators) as $i => $simulator) {
            try {
                $imports[$i] = SimulatorImport::prepare(
                    $environment,
                    (string) ($simulator['file'] ?? ''),
                    $grid,
                    ($simulator['name'] ?? '') !== '' ? (string) $simulator['name'] : null,
                    ($simulator['core'] ?? '') !== '' ? (string) $simulator['core'] : null,
                    !\W4OS\Engine\Result::isFalse($simulator['scripts'] ?? true),
                    $planned,
                );
            } catch (\W4OS\Engine\Exceptions\NotFound $error) {
                throw $error;
            } catch (\InvalidArgumentException $error) {
                $problems["simulators.$i"] = $error->getMessage();
            }
        }

        // Between them: what one takes, the next ones may not
        $taken = ['slug' => [], 'port' => [], 'name' => [], 'uuid' => [], 'square' => []];
        foreach ($imports as $i => $import) {
            $plan = $import->plan;
            $wrong = [];
            $claim = static function (string $kind, string $key, string $what) use (&$taken, &$wrong, $i): void {
                if ($key === '' || $key === '0') {
                    return;
                }
                isset($taken[$kind][$key]) && $taken[$kind][$key] !== $i
                    ? ($wrong[] = sprintf('%s is also the one of the simulator %d', $what, $taken[$kind][$key] + 1))
                    : ($taken[$kind][$key] = $i);
            };
            $claim('slug', $plan->slug, "the slug {$plan->slug}");
            $claim('port', (string) $plan->httpPort, "the port {$plan->httpPort}");
            $plan->consoleMode === 'rest' && $claim('port', (string) $plan->consolePort, "the console port {$plan->consolePort}");
            foreach ($import->regions as $region) {
                $claim('name', strtolower($region['name']), "the region name {$region['name']}");
                $claim('uuid', $region['uuid'], "the region UUID {$region['uuid']}");
                $claim('port', (string) $region['port'], "the port {$region['port']} of the region {$region['name']}");
                [$x, $y] = array_map('intval', explode(',', $region['location'] . ',0'));
                $span = max(1, intdiv($region['size'], Region::SIZE));
                for ($dx = 0; $dx < $span; $dx++) {
                    for ($dy = 0; $dy < $span; $dy++) {
                        $claim('square', ($x + $dx) . ',' . ($y + $dy), "the place {$region['location']} of the region {$region['name']}");
                    }
                }
            }
            $wrong === [] || ($problems["simulators.$i"] = implode('; ', array_unique($wrong)));
        }

        return new self($grid, $imports, $problems);
    }

    /**
     * The plan of each, in its order, and what is wrong between them.
     *
     * @return array{grid:string,steps:list<array<string,mixed>>,writes:list<string>,problems:array<string,string>,reminders:list<array<string,mixed>>}
     */
    public function document(): array
    {
        $steps = array_values(array_map(static fn(SimulatorImport $import): array => $import->document(), $this->imports));

        return [
            'grid' => $this->grid,
            'steps' => $steps,
            'writes' => array_values(array_merge(...array_map(static fn(array $step): array => $step['writes'], $steps ?: [['writes' => []]]))),
            'problems' => $this->problems,
            'reminders' => array_values(array_merge(...array_map(static fn(array $step): array => $step['reminders'], $steps ?: [['reminders' => []]]))),
        ];
    }

    /**
     * Write them, in their order, each disabled (SimulatorImport::apply(), its source backed up first). A database several of them
     * name is dumped once: the backup of each holds a copy of that dump, so that each can be put back on its own.
     *
     * @param ?\Closure $dump writes the dump of a database (default: DatabaseDump::write())
     * @throws InvalidValue when something is wrong: nothing is written then
     */
    public function apply(Environment $environment, UiInterface $ui, ?\Closure $dump = null): void
    {
        if ($this->problems !== []) {
            throw InvalidValue::because('invalid-request', 'the imports have problems: ' . implode('; ', array_map(
                static fn(string $place, string $message): string => "$place: $message",
                array_keys($this->problems),
                $this->problems,
            )));
        }
        $dump ??= \W4OS\Engine\Archives\DatabaseDump::write(...);
        // A database several of them name (the estates, a module of the grid) is dumped once, each backup keeping a copy of it
        $cache = sys_get_temp_dir() . '/opensim-dumps-' . bin2hex(random_bytes(6));
        mkdir($cache, 0o700);
        $dumped = [];
        $once = static function (array $db, string $file) use ($dump, $cache, &$dumped): void {
            $key = strtolower("{$db['host']}/{$db['name']}");
            if (!isset($dumped[$key])) {
                $dump($db, $file);
                $dumped[$key] = $cache . '/' . count($dumped) . '.sql.gz';
                copy($file, $dumped[$key]) || throw new \RuntimeException("cannot keep the dump of {$db['name']}");

                return;
            }
            copy($dumped[$key], $file) || throw new \RuntimeException("cannot copy the dump of {$db['name']}");
        };
        try {
            foreach ($this->imports as $import) {
                $import->apply($environment, $ui, $once);
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($cache));
        }
    }
}
