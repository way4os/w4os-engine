<?php

declare(strict_types=1);

namespace OpenSim\Services;

use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Data\OSPDO;
use OpenSim\Health\HealthCheck;
use OpenSim\Models\Grid;
use OpenSim\Models\Region;
use OpenSim\Runtime\Environment;
use OpenSim\Scheduling\Scheduler;
use OpenSim\Scheduling\Task;

/**
 * The tasks of the search of a grid: read the snapshots of the registered simulators (`search-parse`, once a minute: a host that is
 * due is read, the others are left alone), read the events of the calendar (`events-parse`, every 55 minutes), and ask the grids
 * of the hypergrid destinations of its guide for their pictures (`guide-pictures`, once an hour: a destination that is due is
 * asked, RemotePictures), and check its health (`health`, every 15 minutes, HealthCheck: a page shows the last check at once).
 *
 *     SearchTasks::add($scheduler, $environment, $config);
 */
final class SearchTasks
{
    /** Seconds between two looks at the registered simulators. */
    public const PARSE_EVERY = 60;

    /** Seconds between two reads of the calendar of events. */
    public const EVENTS_EVERY = 3300;

    /** Seconds between two looks at the pictures of the hypergrid destinations of the guide. */
    public const PICTURES_EVERY = 3600;

    /** @var array<string,true> the search databases whose tables are made, in this process */
    private static array $prepared = [];

    /** The calendar the events are read from unless the grid says another one (`HypEventsURL` of `[Helpers]`). */
    public const EVENTS_SOURCE = 'https://2do.directory/events';

    /** The nick of a grid: the name of the folder of its config (Robust, else OpenSim.ini, else Helpers.ini). */
    private static function gridOf(GridConfig $config): string
    {
        $folder = self::folderOf($config);

        return $folder === '' ? '' : basename($folder);
    }

    /** The folder of the config of a grid (Robust, else OpenSim.ini, else Helpers.ini), empty when it has no file. */
    private static function folderOf(GridConfig $config): string
    {
        foreach (['Robust', 'OpenSim', 'Helpers'] as $role) {
            $file = $config->file($role);
            if ($file !== null && $file !== '') {
                return dirname($file);
            }
        }

        return '';
    }

    /**
     * Run a task of the search of a grid alone, whoever runs it: the lock of the scheduler is a file of its own folder of the tasks,
     * and two runners may have their own (a site run by the web server, a timer of the install). This one is a lock of the search
     * database of the grid (GET_LOCK of MySQL and MariaDB, released at the end, or with the connection): a run that finds it taken
     * does nothing. The tables of the search are made first, when they are not there yet (a grid where no simulator registered).
     * A database without such locks (SQLite) runs it as it is.
     */
    public static function exclusively(OSPDO $db, string $task, \Closure $run): string
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return (string) $run();
        }
        $database = (string) $db->query('SELECT DATABASE()')->fetchColumn();
        // The tables of the search, before a simulator registers (which makes them too): once a process, for each database
        if (!isset(self::$prepared[$database])) {
            SearchTables::ensure($db);
            self::$prepared[$database] = true;
        }
        $name = self::lockName($task, $database);
        if ((int) $db->query('SELECT GET_LOCK(' . $db->quote($name) . ', 0)')->fetchColumn() !== 1) {
            return 'already running elsewhere';
        }
        try {
            return (string) $run();
        } finally {
            $db->query('SELECT RELEASE_LOCK(' . $db->quote($name) . ')');
        }
    }

    /** The name of the lock of a task on a database: the server keeps one per name, 64 characters at most. */
    public static function lockName(string $task, string $database): string
    {
        return substr('opensim-' . $task, 0, 31) . '-' . md5($database);
    }

    /**
     * Add the tasks that the grid can run: the ones of the search need its search database. Each is the grid's own (its state, its
     * lock): an install that serves several grids reads each on its own schedule.
     *
     * @param ?string $grid the nick of the grid, else the name of the folder of its config
     */
    public static function add(Scheduler $scheduler, Environment $environment, GridConfig $config, ?string $grid = null): void
    {
        $db = Connections::pdo($config, Database::Search);
        if ($db === null || !$db->connected) {
            return;
        }
        $scope = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $grid ?? self::gridOf($config));
        $regions = Region::searchTable($db);
        $scheduler->add(
            new Task(
                'search-parse',
                self::PARSE_EVERY,
                static fn(): string => self::exclusively($db, 'search-parse', static function () use ($db, $regions, $environment, $config): string {
                    $registry = new SearchRegistry($db, $regions, $environment->clock);
                    $hosts = (new SearchParser($registry, $db, $regions, $environment->http))->parse();
                    // The land for sale of the regions of the grid, from their land: shown in search or not
                    $sales = LandSales::sync($config, $db);

                    return ($hosts === 0 ? 'no simulator due' : "$hosts simulator(s) read") . ", $sales parcel(s) for sale";
                }),
                'Read the snapshots of the registered simulators',
                $scope,
            ),
        );
        $source = rtrim($config->find('Helpers', 'HypEventsURL') ?? self::EVENTS_SOURCE, '/');
        $scheduler->add(
            new Task(
                'events-parse',
                self::EVENTS_EVERY,
                static fn(): string => self::exclusively($db, 'events-parse', static function () use ($db, $source, $environment): string {
                    $kept = (new EventsParser($db, $source, $environment->http, clock: $environment->clock))->parse();

                    $kept < 0 && throw new \RuntimeException("the calendar $source did not answer");

                    return "$kept event(s) kept";
                }),
                'Read the events of the calendar',
                $scope,
            ),
        );
        $folder = self::folderOf($config);
        $folder !== '' && is_dir($folder) && $scheduler->add(
            new Task(
                'guide-pictures',
                self::PICTURES_EVERY,
                static fn(): string => self::exclusively($db, 'guide-pictures', static function () use ($environment, $config, $folder): string {
                    $found = Grid::at($environment, $folder)->refreshGuidePictures($config);

                    return "$found picture(s) of hypergrid destinations found";
                }),
                'Ask the grids of the destinations of the guide for their pictures',
                $scope,
            ),
        );
        // The health of the grid, checked in the background: a page shows the last check at once
        $folder !== '' && is_dir($folder) && $scheduler->add(
            new Task(
                'health',
                HealthCheck::EVERY,
                static function () use ($environment, $config, $folder, $scheduler): string {
                    $check = HealthCheck::refresh($environment, Grid::at($environment, $folder), $config, $scheduler->directory());

                    return "health checked: {$check['level']}";
                },
                'Check the health of the grid',
                $scope,
            ),
        );
    }
}
