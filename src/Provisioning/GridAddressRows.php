<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning;

use W4OS\Engine\Data\Connections;
use W4OS\Engine\Data\Database;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Services\SearchTables;

/**
 * The addresses of a grid its databases keep, changed with its address (GridAddress): planned, each column with the rows it would
 * change, then applied.
 *
 *  - its login (host:port): the services of its accounts (UserAccounts.ServiceURLs, HomeURI=...), the gatekeeper of the rows of its
 *    search (gatekeeperURL of its parcels, its popular places, its regions...), the gatekeeper of its picks;
 *  - its host alone, its simulators' ports kept: the address of its regions in its search (regionsregister.url), the host its
 *    simulators register by (hostsregister.host).
 *
 * The regions of Robust (regions.serverURI) are written again by the simulators at their start; what a user wrote (the address of
 * their profile) is theirs, never changed. A table or a column a database does not have is left out.
 */
final class GridAddressRows
{
    /** The search tables whose rows say the gatekeeper of their grid, besides the one of the regions. */
    private const GATEKEEPER_TABLES = ['allparcels', 'classifieds', 'events', 'hostsregister', 'objects', 'parcels', 'parcelsales', 'popularplaces'];

    public function __construct(private readonly Grid $grid) {}

    /**
     * @param list<string> $logins the old login URIs (its schemes), without a slash
     * @param string $login the new login URI, without a slash
     * @param string $oldHost its old host, $newHost the new one (the same when only the port changes)
     * @return list<array{database:string,table:string,column:string,before:string,after:string,rows:int}>
     */
    public function change(array $logins, string $login, string $oldHost, string $newHost, bool $apply = false): array
    {
        $config = $this->grid->config();
        if ($config === null) {
            return [];
        }
        $columns = [];
        $hosts = [];
        $done = [];
        foreach ([Database::Robust, Database::Search, Database::Profiles] as $kind) {
            $db = Connections::pdo($config, $kind);
            if ($db === null || !$db->connected) {
                continue;
            }
            $name = (string) Connections::key($config, $kind);
            $tables = match ($kind) {
                Database::Robust => [['UserAccounts', 'ServiceURLs']],
                Database::Search => [...array_map(static fn(string $t): array => [$t, 'gatekeeperURL'], [...self::GATEKEEPER_TABLES, \W4OS\Engine\Models\Region::searchTable($db)])],
                Database::Profiles => [['userpicks', 'gatekeeper']],
                default => [],
            };
            foreach ($tables as [$table, $column]) {
                // One database may be the robust, the search and the profiles at once: each column once
                if (!isset($done["$name.$table.$column"])) {
                    $columns[] = [$db, $name, $table, $column];
                    $done["$name.$table.$column"] = true;
                }
            }
            if ($kind === Database::Search && strcasecmp($oldHost, $newHost) !== 0) {
                $hosts[] = [$db, $name];
            }
        }

        $changes = [];
        foreach ($columns as [$db, $name, $table, $column]) {
            foreach ($logins as $old) {
                $rows = $this->login($db, $table, $column, $old, $login, $apply);
                $rows > 0 && ($changes[] = ['database' => $name, 'table' => $table, 'column' => $column, 'before' => $old, 'after' => $login, 'rows' => $rows]);
            }
        }
        foreach ($hosts as [$db, $name]) {
            $rows = $this->replace($db, 'regionsregister', 'url', "://$oldHost:", "://$newHost:", $apply);
            $rows > 0 && ($changes[] = ['database' => $name, 'table' => 'regionsregister', 'column' => 'url', 'before' => "://$oldHost:", 'after' => "://$newHost:", 'rows' => $rows]);
            $rows = $this->run($db, 'hostsregister', 'host', '`host` = ?', [$oldHost], 'UPDATE `hostsregister` SET `host` = ? WHERE `host` = ?', [$newHost, $oldHost], $apply);
            $rows > 0 && ($changes[] = ['database' => $name, 'table' => 'hostsregister', 'column' => 'host', 'before' => $oldHost, 'after' => $newHost, 'rows' => $rows]);
        }

        return $changes;
    }

    /** A login in a column: the value alone, or followed by a slash (an address under it, a list of URIs as ServiceURLs has). */
    private function login(OSPDO $db, string $table, string $column, string $old, string $new, bool $apply): int
    {
        return $this->run($db, $table, $column, "`$column` = ?", [$old], "UPDATE `$table` SET `$column` = ? WHERE `$column` = ?", [$new, $old], $apply)
            + $this->replace($db, $table, $column, "$old/", "$new/", $apply);
    }

    /** A text replaced in a column, wherever it is in the value. */
    private function replace(OSPDO $db, string $table, string $column, string $old, string $new, bool $apply): int
    {
        // A wider LIKE only counts more: REPLACE() changes the exact text
        $like = "%$old%";

        return $this->run($db, $table, $column, "`$column` LIKE ?", [$like], "UPDATE `$table` SET `$column` = REPLACE(`$column`, ?, ?) WHERE `$column` LIKE ?", [$old, $new, $like], $apply);
    }

    /** The rows a change takes, counted, then changed when it is applied; none for a table or a column the database has not. */
    private function run(OSPDO $db, string $table, string $column, string $where, array $values, string $update, array $params, bool $apply): int
    {
        try {
            $statement = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE $where");
            $statement->execute($values);
            $rows = (int) $statement->fetchColumn();
            if ($rows > 0 && $apply) {
                $db->prepare($update)->execute($params);
            }

            return $rows;
        } catch (\Throwable) {
            return 0;
        }
    }
}
