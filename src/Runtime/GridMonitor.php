<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

use OpenSim\Models\Simulator;

use OpenSim\Config\ConfigReader;
use OpenSim\Config\GridConfig;
use OpenSim\Controllers\OpenSimController;
use OpenSim\Data\Stats;
use OpenSim\Models\Grid;
use OpenSim\Models\Region;
use OpenSim\OpenSim;

/**
 * The state of a grid at a glance: its Robust, its simulators (the ones of this machine and the ones that run elsewhere, which its
 * regions tell), the regions that are online, who is in world. The same lines as `opensim status`, for a page, for a prim, for a
 * script: the diagnosis, address by address, is the health.
 *
 *     $monitor = GridMonitor::of($grid, $config);
 *     echo GridMonitor::text($monitor);
 */
final class GridMonitor
{
    /** Its services answer, whatever its simulators and regions do (they have their own counts). */
    public const OK = 'ok';

    /** The grid is partly broken: a Robust of several does not run, or the database of its grid service does not answer. */
    public const DEGRADED = 'degraded';

    /** Its Robust is not running (the simulator of a standalone, which is its grid). */
    public const DOWN = 'down';

    /** The machine cannot tell: the grid has no Robust here and no database to ask. */
    public const UNKNOWN = 'unknown';

    /**
     * @param ?GridConfig $config the config of the grid, to ask its databases (default: its folder)
     * @return array{grid:string,name:string,level:string,login:?string,instances:list<array<string,mixed>>,simulators:list<array<string,mixed>>,regions:array{total:int,online:int},users:?array<string,int|float>,up:int,down:int,summary:string}
     */
    public static function of(Grid $grid, ?GridConfig $config = null, ?Status $status = null): array
    {
        $status ??= OpenSim::status($grid->environment());
        // The config of the grid, its folder's or the one an application gives (a grid a site describes)
        $config ??= $grid->config();

        // The instances of the grid on this machine, with what they use
        $mine = array_map(static fn($controller): string => $controller->name(), $grid->instances(true));
        $instances = [];
        foreach ($status->instances as $item) {
            if (!in_array($item->name(), $mine, true)) {
                continue;
            }
            $process = $item->process();
            $instances[] = [
                'name' => $item->name(),
                'type' => $item->type() === 'robust' ? 'robust' : 'simulator',
                'running' => $process !== null,
                'pid' => $process?->pid,
                'cpu' => $process?->cpu,
                'memory' => $process?->memory,
                'rss' => $process?->rss,
                'httpPort' => $item instanceof OpenSimController ? $item->httpPort() : null,
            ];
        }

        // The regions the grid knows tell where the simulators are, the ones of other machines included
        $regions = ['total' => 0, 'online' => 0];
        $byServer = [];
        foreach ($config === null ? [] : self::regions($config) as $region) {
            $uri = (string) ($region->detail()['serverUri'] ?? '');
            // A region its config describes and the grid does not know is not in the count, nor is a link to another grid
            if ($uri === '' || $region->isHyperlink()) {
                continue;
            }
            $regions['total']++;
            $region->isOnline() && $regions['online']++;
            $byServer[$uri]['regions'] = ($byServer[$uri]['regions'] ?? 0) + 1;
            $byServer[$uri]['online'] = ($byServer[$uri]['online'] ?? 0) + ($region->isOnline() ? 1 : 0);
        }
        $simulators = [];
        $claimed = [];
        foreach ($instances as $instance) {
            if ($instance['type'] === 'robust') {
                continue;
            }
            $match = null;
            foreach (array_keys($byServer) as $uri) {
                if ($instance['httpPort'] !== null && parse_url($uri, PHP_URL_PORT) === $instance['httpPort']) {
                    $match = $uri;
                }
            }
            $match !== null && ($claimed[$match] = true);
            $simulators[] = [
                // The name to show, its instance apart (the commands take it)
                'name' => Simulator::displayNameOf($instance['name'], $grid->nick()),
                'slug' => $instance['name'],
                'uri' => $match,
                'local' => true,
                'running' => $instance['running'],
                'regions' => $byServer[$match]['regions'] ?? 0,
                'online' => $byServer[$match]['online'] ?? 0,
            ];
        }
        // A standalone keeps no table of regions: its simulator loads the ones it describes, with it
        if ($grid->isStandalone()) {
            foreach ($simulators as &$simulator) {
                $described = self::described($grid, $simulator['slug']);
                $simulator['regions'] = $described;
                $simulator['online'] = $simulator['running'] ? $described : 0;
                $regions['total'] += $described;
                $regions['online'] += $simulator['online'];
            }
            unset($simulator);
        }
        foreach ($byServer as $uri => $counts) {
            if ($uri === '' || isset($claimed[$uri])) {
                continue;
            }
            // A simulator elsewhere: it is up when its regions say they are
            $simulators[] = [
                'name' => (string) parse_url($uri, PHP_URL_HOST) . ':' . parse_url($uri, PHP_URL_PORT),
                'slug' => null,
                'uri' => $uri,
                'local' => false,
                'running' => $counts['online'] > 0,
                'regions' => $counts['regions'],
                'online' => $counts['online'],
            ];
        }

        $robust = array_values(array_filter($instances, static fn(array $i): bool => $i['type'] === 'robust'));
        $up = count(array_filter($instances, static fn(array $i): bool => $i['running']));
        $down = count($instances) - $up;
        // The level of the grid is the one of its own services: a simulator stopped, regions offline happen and leave it working
        $robustUp = count(array_filter($robust, static fn(array $i): bool => $i['running']));
        $standalone = $grid->isStandalone();
        $level = match (true) {
            $robust !== [] && $robustUp === 0 => self::DOWN,
            // The simulator of a standalone is its grid
            $standalone && $up === 0 && $instances !== [] => self::DOWN,
            $robust === [] && !$standalone && $regions['total'] === 0 && $instances === [] => self::UNKNOWN,
            // A Robust of several down, or the database of its grid service not answering
            $robustUp < count($robust) || ($robust !== [] && self::databaseDown($config)) => self::DEGRADED,
            default => self::OK,
        };
        $users = $config === null ? null : Stats::of($config);
        $totals = [
            'cpu' => array_sum(array_column($instances, 'cpu')),
            'memory' => array_sum(array_column($instances, 'memory')),
            'rss' => (int) array_sum(array_column($instances, 'rss')),
        ];

        return [
            'grid' => $grid->nick(),
            'name' => $grid->name(),
            'level' => $level,
            'login' => $grid->loginUrl(),
            'instances' => $instances,
            'simulators' => $simulators,
            'regions' => $regions,
            'users' => $users,
            'up' => $up,
            'down' => $down,
            'summary' => sprintf(
                '%d up%s - regions %d/%d%s - cpu %.1f%% - mem %.1f%% - rss %d',
                $up,
                $down > 0 ? ", $down down" : '',
                $regions['online'],
                $regions['total'],
                $users !== null ? ' - in world ' . ($users['members_in_world'] ?? 0) : '',
                $totals['cpu'],
                $totals['memory'],
                $totals['rss'],
            ),
        ];
    }

    /** The number of regions a simulator of the grid describes in its files. */
    private static function described(Grid $grid, string $instance): int
    {
        $count = 0;
        $folder = GridConfig::simulatorsDirectory($grid->directory()) . '/' . $instance . '/regions';
        foreach (glob("$folder/*.ini") ?: [] as $file) {
            $count += count(ConfigReader::file($file));
        }

        return $count;
    }

    /**
     * The key of the text of a level in the catalog (Online, Degraded, Offline), for a page; null when the machine cannot tell.
     * The viewers read their own keyword (ONLINE, DEGRADED, OFFLINE), never translated.
     */
    public static function labelKey(string $level): ?string
    {
        return [self::OK => 'online', self::DEGRADED => 'degraded', self::DOWN => 'offline'][$level] ?? null;
    }

    /**
     * The lines of the monitor, as `opensim status` shows its table: a title, the instances and the simulators elsewhere, the summary.
     *
     * @param array<string,mixed> $monitor see of()
     */
    public static function text(array $monitor): string
    {
        $rows = [['NAME', 'TYPE', 'STATE', 'REGIONS', '%CPU', '%MEM']];
        foreach ($monitor['instances'] as $instance) {
            $simulator = null;
            foreach ($monitor['simulators'] as $candidate) {
                $candidate['local'] && $candidate['slug'] === $instance['name'] && ($simulator = $candidate);
            }
            $rows[] = [
                $instance['name'],
                $instance['type'],
                $instance['running'] ? 'up' : 'down',
                $simulator !== null ? "{$simulator['online']}/{$simulator['regions']}" : '-',
                $instance['running'] ? sprintf('%.1F', $instance['cpu']) : '-',
                $instance['running'] ? sprintf('%.1F', $instance['memory']) : '-',
            ];
        }
        foreach ($monitor['simulators'] as $simulator) {
            $simulator['local'] ||
                ($rows[] = [
                    $simulator['name'],
                    'remote',
                    $simulator['running'] ? 'up' : 'down',
                    "{$simulator['online']}/{$simulator['regions']}",
                    '-',
                    '-',
                ]);
        }
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, strlen($cell));
            }
        }
        $text = strtoupper($monitor['level']) . ' - ' . $monitor['name'] . "\n";
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $column => $cell) {
                $cells[] = $column === count($row) - 1 ? $cell : str_pad($cell, $widths[$column] + 2);
            }
            $text .= implode('', $cells) . "\n";
        }

        return $text . $monitor['summary'] . "\n";
    }

    /** @return list<Region> */
    /** Whether the grid says the database of its grid service and it does not answer. */
    private static function databaseDown(?GridConfig $config): bool
    {
        if ($config === null || \OpenSim\Data\Connections::key($config, 'GridService') === null) {
            return false;
        }
        $pdo = \OpenSim\Data\Connections::pdo($config, 'GridService');

        return $pdo === null || !$pdo->connected;
    }

    private static function regions(GridConfig $config): array
    {
        try {
            return Region::all($config)->items();
        } catch (\Throwable) {
            return [];
        }
    }
}
