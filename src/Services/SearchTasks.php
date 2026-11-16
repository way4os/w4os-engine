<?php

declare(strict_types=1);

namespace OpenSim\Services;

use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Data\OSPDO;
use OpenSim\Models\Region;
use OpenSim\Runtime\Environment;
use OpenSim\Scheduling\Scheduler;
use OpenSim\Scheduling\Task;

/**
 * The tasks of the search of a grid: read the snapshots of the registered simulators (`search-parse`, once a minute: a host that is
 * due is read, the others are left alone), and read the events of the calendar (`events-parse`, every 55 minutes).
 *
 *     SearchTasks::add($scheduler, $environment, $config);
 */
final class SearchTasks
{
    /** Seconds between two looks at the registered simulators. */
    public const PARSE_EVERY = 60;

    /** Seconds between two reads of the calendar of events. */
    public const EVENTS_EVERY = 3300;

    /** The calendar the events are read from unless the grid says another one (`HypEventsURL` of `[Helpers]`). */
    public const EVENTS_SOURCE = 'https://2do.directory/events';

    /**
     * Run a task of the search of a grid alone, whoever runs it: the lock of the scheduler is a file of its own folder of the tasks,
     * and two runners may have their own (a site run by the web server, a timer of the install). This one is a lock of the search
     * database of the grid (GET_LOCK of MySQL and MariaDB, released at the end, or with the connection): a run that finds it taken
     * does nothing. A database without such locks (SQLite) runs it as it is.
     */
    public static function exclusively(OSPDO $db, string $task, \Closure $run): string
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return (string) $run();
        }
        $name = self::lockName($task, (string) $db->query('SELECT DATABASE()')->fetchColumn());
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

    /** Add the tasks that the grid can run: the ones of the search need its search database. */
    public static function add(Scheduler $scheduler, Environment $environment, GridConfig $config): void
    {
        $db = Connections::pdo($config, Database::Search);
        if ($db === null || !$db->connected) {
            return;
        }
        $regions = Region::searchTable($db);
        $scheduler->add(
            new Task(
                'search-parse',
                self::PARSE_EVERY,
                static fn(): string => self::exclusively($db, 'search-parse', static function () use ($db, $regions, $environment): string {
                    $registry = new SearchRegistry($db, $regions, $environment->clock);
                    $hosts = (new SearchParser($registry, $db, $regions, $environment->http))->parse();

                    return $hosts === 0 ? 'no simulator due' : "$hosts simulator(s) read";
                }),
                'Read the snapshots of the registered simulators',
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
            ),
        );
    }
}
