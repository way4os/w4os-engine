<?php

declare(strict_types=1);

namespace W4OS\Engine\Data;

use W4OS\Engine\Config\GridConfig;

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
        return self::counts(
            static fn(string $service): ?OSPDO => Connections::pdo($config, $service),
            $now ?? time(),
            self::excluded($config),
            \W4OS\Engine\Models\AvatarModel::condition($config, 'u.'),
        );
    }

    /** What the numbers of a grid leave out, as it says it (`Exclude` of `[Stats]`): the models and the accounts without email by default. */
    public static function excluded(GridConfig $config): array
    {
        $said = $config->find('Stats', 'Exclude');

        return $said === null ? self::EXCLUDED : array_values(array_intersect(preg_split('/[\s,;"]+/', strtolower($said), -1, PREG_SPLIT_NO_EMPTY) ?: [], ['models', 'nomail', 'hypergrid']));
    }

    /** What the numbers leave out by default, as w4os-wp: the avatar models, the accounts without email (made in the console). */
    public const EXCLUDED = ['models', 'nomail'];

    /**
     * Who is in each region: the members of the grid (its accounts) and the visitors of other grids, by region (in lower case). Only
     * people: a NPC never reaches Presence (PresenceDetector of OpenSimulator leaves them out). Null when a database cannot be reached.
     *
     * @param callable(string):?OSPDO $dbFor gives the database of a service of Robust
     * @return ?array<string,array{members:int,visitors:int}>
     */
    public static function presence(callable $dbFor, string $kept = '', array $params = []): ?array
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
        // Each account found, and whether it is kept (an account left out is counted nowhere)
        $members = [];
        foreach (array_chunk(array_values(array_unique(array_column($rows, 0))), self::GROUP) as $group) {
            [$marks, $ids] = self::marks($group);
            $found = $accounts->prepareAndExecute("SELECT u.PrincipalID, CASE WHEN 1 = 1$kept THEN 1 ELSE 0 END FROM UserAccounts u WHERE u.PrincipalID IN ($marks)", $ids + $params);
            foreach ($found ? $found->fetchAll(\PDO::FETCH_NUM) : [] as [$id, $keep]) {
                $members[strtolower((string) $id)] = (bool) $keep;
            }
        }
        $regions = [];
        foreach ($rows as [$user, $region]) {
            $region = strtolower((string) $region);
            $regions[$region] ??= ['members' => 0, 'visitors' => 0];
            $member = $members[strtolower((string) $user)] ?? null;
            $member === null ? $regions[$region]['visitors']++ : ($member && $regions[$region]['members']++);
        }

        return $regions;
    }

    /**
     * The numbers, from the databases a function gives.
     *
     * @param callable(string):?OSPDO $dbFor gives the database of a service of Robust
     * @param list<string> $exclude what they leave out: `models` (the avatar models), `nomail` (the accounts without email),
     *        `hypergrid` (the visitors of other grids)
     * @param ?array{0:string,1:array<string,string>} $models the condition on UserAccounts (`u.`) of the models (AvatarModel::condition())
     * @return array{members:int,active_members:int,members_in_world:int,active_users:int,total_users:int,regions:int,area:int|float}|null
     *         null when a database cannot be reached
     */
    public static function counts(callable $dbFor, int $now, array $exclude = [], ?array $models = null): ?array
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
            // A sum of no line is null, no line is false: none
            $value = $statement ? $statement->fetchColumn() : 0;

            return $value === null || $value === false ? 0 : $value;
        };
        // The accounts kept (`u.`): the ones left out are counted nowhere
        $kept = '';
        $params = [];
        in_array('nomail', $exclude, true) && ($kept .= " AND COALESCE(u.Email, '') <> ''");
        if (in_array('models', $exclude, true) && $models !== null) {
            $kept .= " AND NOT ({$models[0]})";
            $params = $models[1];
        }
        $visitors = !in_array('hypergrid', $exclude, true);
        // How many of the users are accounts: one query when both are in one database, else by groups of identifiers
        $accountsAmong = static function (OSPDO $db, string $sql, string $idsSql) use ($accounts, $one, $kept, $params): int {
            if ($db === $accounts) {
                return (int) $one($accounts, $sql . $kept, $params);
            }
            $statement = $db->prepareAndExecute($idsSql);
            $ids = $statement ? $statement->fetchAll(\PDO::FETCH_COLUMN) : [];
            $count = 0;
            foreach (array_chunk($ids, self::GROUP) as $group) {
                [$marks, $named] = self::marks($group);
                $count += (int) $one($accounts, "SELECT COUNT(*) FROM UserAccounts u WHERE u.PrincipalID IN ($marks)$kept", $named + $params);
            }

            return $count;
        };

        $here = self::presence($dbFor, $kept, $params) ?? [];
        $active = $accountsAmong(
            $users,
            "SELECT COUNT(*) FROM GridUser g, UserAccounts u WHERE u.PrincipalID = g.UserID AND g.Login + 0 > $month",
            "SELECT UserID FROM GridUser WHERE Login + 0 > $month",
        );

        return [
            'members' => (int) $one($accounts, 'SELECT COUNT(*) FROM UserAccounts u WHERE u.active = 1' . $kept, $params),
            'active_members' => $active,
            // Who is in world, as each region counts them (presence()): one definition for the grid and its lists
            'members_in_world' => array_sum(array_column($here, 'members')),
            // Everybody who came this month; with something left out, the members kept and the visitors of other grids when they are
            'active_users' => $exclude === []
                ? (int) $one($users, "SELECT COUNT(*) FROM GridUser WHERE Login + 0 > $month")
                : $active + ($visitors ? (int) $one($users, "SELECT COUNT(*) FROM GridUser WHERE Login + 0 > $month AND UserID LIKE '%;%'") : 0),
            'total_users' => (int) array_sum(array_column($here, 'members')) + ($visitors ? (int) array_sum(array_column($here, 'visitors')) : 0),
            'regions' => (int) $one($grid, 'SELECT COUNT(*) FROM regions'),
            // Square kilometers, exact (a region of 256 m is 0.065536)
            'area' => ($area = $one($grid, 'SELECT SUM(sizeX * sizeY) FROM regions')) ? (int) $area / 1000000 : 0,
        ];
    }

    /**
     * Named marks for a group of identifiers, so that they go with the named parameters of a condition.
     *
     * @param list<string> $group
     * @return array{0:string,1:array<string,string>}
     */
    private static function marks(array $group): array
    {
        $named = [];
        foreach (array_values($group) as $i => $id) {
            $named["id$i"] = (string) $id;
        }

        return [implode(',', array_map(static fn(string $key): string => ":$key", array_keys($named))), $named];
    }

    /**
     * What the regions hold, in the databases of their simulators: their prims (the rows of `prims`) and their scripts (the items of
     * `primitems` of the type LSL, 10), by region. A database without these tables tells nothing.
     *
     * @param iterable<OSPDO> $databases the databases of the simulators, each once
     * @return array<string,array{prims:int,scripts:int}> by the UUID of the region, in lower case
     */
    public static function content(iterable $databases): array
    {
        $content = [];
        foreach ($databases as $db) {
            if (empty($db->connected) || !$db->table_exists('prims')) {
                continue;
            }
            $prims = $db->prepareAndExecute('SELECT RegionUUID, COUNT(*) FROM prims GROUP BY RegionUUID', []);
            foreach ($prims ? $prims->fetchAll(\PDO::FETCH_NUM) : [] as [$region, $count]) {
                $content[strtolower((string) $region)] = ['prims' => (int) $count, 'scripts' => 0];
            }
            if (!$db->table_exists('primitems')) {
                continue;
            }
            $scripts = $db->prepareAndExecute('SELECT p.RegionUUID, COUNT(*) FROM primitems i JOIN prims p ON p.UUID = i.primID WHERE i.invType = 10 GROUP BY p.RegionUUID', []);
            foreach ($scripts ? $scripts->fetchAll(\PDO::FETCH_NUM) : [] as [$region, $count]) {
                isset($content[strtolower((string) $region)]) && ($content[strtolower((string) $region)]['scripts'] = (int) $count);
            }
        }

        return $content;
    }
}
