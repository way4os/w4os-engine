<?php

declare(strict_types=1);

namespace OpenSim\Data;

use OpenSim\Config\GridConfig;

/**
 * The numbers of a grid: its members, who is in world, its regions and its area. Each is counted in the database of its own
 * service (the accounts in the one of UserAccountService, the last logins in GridUserService, who is in world in PresenceService,
 * the regions in GridService): Robust's unless the service has its own. Two services on one database are counted with one query, on
 * two databases a count asks the one for the identifiers and the other for who they are.
 */
final class Stats
{
    /** How many identifiers are asked at once to the database of the accounts. */
    private const GROUP = 500;

    /**
     * The numbers of a grid.
     *
     * @return array<string,int|float>|null null when a database cannot be reached
     */
    public static function of(GridConfig $config, ?int $now = null): ?array
    {
        return self::counts(static fn(string $service): ?OSPDO => Connections::pdo($config, $service), $now ?? time());
    }

    /**
     * Who is in each region: the members of the grid (its accounts) and the visitors of other grids, by region (in lower case). Only
     * people: a NPC never reaches Presence (PresenceDetector of OpenSimulator leaves them out). Null when a database cannot be reached.
     *
     * @param callable(string):?OSPDO $dbFor gives the database of a service of Robust
     * @return ?array<string,array{members:int,visitors:int}>
     */
    public static function presence(callable $dbFor): ?array
    {
        $accounts = $dbFor('UserAccountService');
        $presence = $dbFor('PresenceService');
        if ($accounts === null || empty($accounts->connected) || $presence === null || empty($presence->connected)) {
            return null;
        }
        if (!$presence->table_exists('Presence')) {
            return [];
        }
        $statement = $presence->prepareAndExecute('SELECT UserID, RegionID FROM Presence WHERE RegionID != :zero', ['zero' => NULL_KEY]);
        $rows = $statement ? $statement->fetchAll(\PDO::FETCH_NUM) : [];
        // Which of them are accounts of the grid, by groups of identifiers
        $members = [];
        foreach (array_chunk(array_values(array_unique(array_column($rows, 0))), self::GROUP) as $group) {
            $marks = implode(',', array_fill(0, count($group), '?'));
            $found = $accounts->prepareAndExecute("SELECT PrincipalID FROM UserAccounts WHERE PrincipalID IN ($marks)", $group);
            foreach ($found ? $found->fetchAll(\PDO::FETCH_COLUMN) : [] as $id) {
                $members[strtolower((string) $id)] = true;
            }
        }
        $regions = [];
        foreach ($rows as [$user, $region]) {
            $region = strtolower((string) $region);
            $regions[$region] ??= ['members' => 0, 'visitors' => 0];
            isset($members[strtolower((string) $user)]) ? $regions[$region]['members']++ : $regions[$region]['visitors']++;
        }

        return $regions;
    }

    /**
     * The numbers, from the databases a function gives.
     *
     * @param callable(string):?OSPDO $dbFor gives the database of a service of Robust
     * @return array{members:int,active_members:int,members_in_world:int,active_users:int,total_users:int,regions:int,area:int|float}|null
     *         null when a database cannot be reached
     */
    public static function counts(callable $dbFor, int $now): ?array
    {
        $accounts = $dbFor('UserAccountService');
        $users = $dbFor('GridUserService');
        $presence = $dbFor('PresenceService');
        $grid = $dbFor('GridService');
        foreach ([$accounts, $users, $presence, $grid] as $db) {
            if ($db === null || empty($db->connected)) {
                return null;
            }
        }
        // An integer in the statement: a bound value would be text, which a number is not compared with in every database
        $month = (int) ($now - 30 * 86400);
        $zero = NULL_KEY;
        $one = static function (OSPDO $db, string $sql, array $params = []): int|float|string {
            $statement = $db->prepareAndExecute($sql, $params);

            return $statement ? $statement->fetchColumn() : 0;
        };
        // How many of the users are accounts: one query when both are in one database, else by groups of identifiers
        $accountsAmong = static function (OSPDO $db, string $sql, array $params, string $idsSql) use (
            $accounts,
            $one,
        ): int {
            if ($db === $accounts) {
                return (int) $one($accounts, $sql, $params);
            }
            $statement = $db->prepareAndExecute($idsSql, $params);
            $ids = $statement ? $statement->fetchAll(\PDO::FETCH_COLUMN) : [];
            $count = 0;
            foreach (array_chunk($ids, self::GROUP) as $group) {
                $marks = implode(',', array_fill(0, count($group), '?'));
                $count += (int) $one(
                    $accounts,
                    "SELECT COUNT(*) FROM UserAccounts WHERE PrincipalID IN ($marks)",
                    $group,
                );
            }

            return $count;
        };

        $here = self::presence($dbFor) ?? [];

        return [
            'members' => (int) $one($accounts, 'SELECT COUNT(*) FROM UserAccounts WHERE active = 1'),
            'active_members' => $accountsAmong(
                $users,
                "SELECT COUNT(*) FROM GridUser g, UserAccounts u WHERE u.PrincipalID = g.UserID AND g.Login + 0 > $month",
                [],
                "SELECT UserID FROM GridUser WHERE Login + 0 > $month",
            ),
            // Who is in world, as each region counts them (presence()): one definition for the grid and its lists
            'members_in_world' => array_sum(array_column($here, 'members')),
            'active_users' => (int) $one($users, "SELECT COUNT(*) FROM GridUser WHERE Login + 0 > $month"),
            'total_users' => (int) array_sum(array_map('array_sum', $here)),
            'regions' => (int) $one($grid, 'SELECT COUNT(*) FROM regions'),
            // Square kilometers, exact (a region of 256 m is 0.065536)
            'area' => ($area = $one($grid, 'SELECT SUM(sizeX * sizeY) FROM regions')) ? (int) $area / 1000000 : 0,
        ];
    }
}
