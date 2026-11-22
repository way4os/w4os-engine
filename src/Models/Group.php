<?php

declare(strict_types=1);

namespace W4OS\Engine\Models;

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Collection;
use W4OS\Engine\Data\Database;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Text\Uuid;

/**
 * A group of the grid (the groups service of Robust, os_groups_groups), as the search of the viewers shows it to anyone: its name,
 * its insignia, its charter, its founder, whether anyone can join and at what fee, its content rating, its members.
 *
 * The groups found are the ones OpenSimulator lists (GroupsService::FindGroups): shown in the search, of this grid (not the copy of a
 * group of another grid), with members; the members shown are the ones of a role that shows its members (MemberVisible), as
 * OpenSimulator shows them to who is not a member.
 */
final class Group extends Model
{
    /** The power of a role that shows its members to who is not a member (GroupPowers.MemberVisible). */
    public const MEMBER_VISIBLE = 1 << 47;

    /** The sorts the list takes: its column in the query. */
    private const SORTS = ['name' => 'g.Name', 'members' => 'members'];

    private const TABLES = ['os_groups_groups', 'os_groups_membership', 'os_groups_roles'];

    public function id(): string
    {
        return (string) $this->row['GroupID'];
    }

    public function uuid(): ?string
    {
        return $this->id();
    }

    public function name(): string
    {
        return (string) $this->row['Name'];
    }

    public function slug(): string
    {
        return $this->id();
    }

    /**
     * @return array{id:string,name:string,charter:string,picture:?string,founder:?string,members:int,open:bool,fee:int,mature:bool}
     */
    public function detail(): array
    {
        $picture = trim((string) ($this->row['InsigniaID'] ?? ''));
        $founder = trim((string) ($this->row['FounderID'] ?? ''));

        return [
            'id' => $this->id(),
            'name' => $this->name(),
            'charter' => trim((string) ($this->row['Charter'] ?? '')),
            'picture' => $picture === '' || Uuid::isNullKey($picture) ? null : $picture,
            'founder' => $founder === '' || Uuid::isNullKey($founder) ? null : $founder,
            'members' => (int) ($this->row['members'] ?? 0),
            // OpenEnrollment is kept as text: 1 or True
            'open' => in_array(strtolower(trim((string) ($this->row['OpenEnrollment'] ?? ''))), ['1', 'true'], true),
            'fee' => (int) ($this->row['MembershipFee'] ?? 0),
            'mature' => (int) ($this->row['MaturePublish'] ?? 0) === 1,
        ];
    }

    /**
     * Its members a role shows (MemberVisible), by name: each with the title of the role they wear, whether they are in the world,
     * when they last came (a Unix time, null when unknown).
     *
     * @return list<array{id:string,name:string,title:?string,online:bool,login:?int}>
     */
    public function members(): array
    {
        $pdo = self::groups($this->config);
        if ($pdo === null) {
            return [];
        }
        // Powers is a 64-bit unsigned number: the database tests its bit
        $rows = $pdo->get_results(
            'SELECT m.PrincipalID, r.Title FROM os_groups_membership m'
                . ' JOIN os_groups_roles r ON r.GroupID = m.GroupID AND r.RoleID = m.SelectedRoleID'
                . ' WHERE m.GroupID = :id AND (r.Powers & ' . self::MEMBER_VISIBLE . ') <> 0',
            ['id' => $this->id()],
            \PDO::FETCH_ASSOC,
        );
        $members = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $avatar = Avatar::find($this->config, (string) $row['PrincipalID']);
            if ($avatar === null) {
                continue;
            }
            $seen = $avatar->lastSeen();
            $members[] = [
                'id' => (string) $row['PrincipalID'],
                'name' => $avatar->name(),
                'title' => trim((string) ($row['Title'] ?? '')) === '' ? null : (string) $row['Title'],
                'online' => $avatar->isOnline(),
                'login' => $seen?->getTimestamp(),
            ];
        }
        usort($members, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $members;
    }

    /**
     * The groups the search of the viewers finds. Options: `search` (words of their name; none: every one), `sort` ("name", the
     * default, or "members", then asc or desc), `page` and `perPage`.
     */
    public static function all(GridConfig $config, array $options = []): Collection
    {
        [$sort, $direction] = array_pad(preg_split('/\s+/', trim((string) ($options['sort'] ?? 'name'))) ?: [], 2, 'asc');
        isset(self::SORTS[$sort]) || ($sort = 'name');
        $direction = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';
        $search = trim((string) ($options['search'] ?? ''));
        $perPage = max(0, (int) ($options['perPage'] ?? 0));
        $page = max(1, (int) ($options['page'] ?? 1));
        $pdo = self::groups($config);
        [$rows, $total] = [[], 0];
        if ($pdo !== null) {
            $from = 'FROM os_groups_groups g JOIN os_groups_membership m ON m.GroupID = g.GroupID'
                . " WHERE g.ShowInList = 1 AND g.Location = '' AND g.Name LIKE :search ESCAPE '!'";
            $values = ['search' => self::contains($search)];
            $total = (int) $pdo->get_var("SELECT COUNT(DISTINCT g.GroupID) $from", $values);
            $sql = "SELECT g.*, COUNT(m.PrincipalID) AS members $from GROUP BY g.GroupID ORDER BY " . self::SORTS[$sort] . " $direction, g.Name";
            $perPage > 0 && ($sql .= " LIMIT $perPage OFFSET " . ($page - 1) * $perPage);
            $rows = $pdo->get_results($sql, $values, \PDO::FETCH_ASSOC);
        }

        return new Collection(array_map(static fn(array $row): self => new self($config, $row), is_array($rows) ? $rows : []), [
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'sort' => "$sort $direction",
            'search' => $search,
        ]);
    }

    public static function find(GridConfig $config, string $id): ?static
    {
        $pdo = self::groups($config);
        $row = $pdo?->get_row(
            'SELECT g.*, (SELECT COUNT(*) FROM os_groups_membership m WHERE m.GroupID = g.GroupID) AS members FROM os_groups_groups g WHERE g.GroupID = :id',
            ['id' => $id],
            \PDO::FETCH_ASSOC,
        );

        return is_array($row) ? new self($config, $row) : null;
    }

    /** The database of the groups, null when the grid has none. */
    private static function groups(GridConfig $config): ?OSPDO
    {
        $pdo = self::optional($config, Database::forTable('os_groups_groups') ?? Database::Robust, null, 'read the groups');

        return $pdo !== null && $pdo->tables_exist(self::TABLES) ? $pdo : null;
    }
}
