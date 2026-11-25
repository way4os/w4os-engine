<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning;

use W4OS\Engine\Data\OSPDO;

/**
 * The groups of the accounts of a list, in the tables of Groups V2 (Robust), as OpenSimulator's GroupsService writes them: an avatar
 * in a group (its membership, the Everyone role and the role asked, its active group when it has none), a group made with the
 * roles OpenSimulator gives one (Everyone, Officers, Owners, the founder in Owners and Officers), a role with the powers of
 * Everyone. Nothing is taken away. A plan writes nothing, and remembers what it would make for the next lines.
 *
 *     (new GroupMemberships($pdo, create: true))->join($avatarId, ['Speakers', 'VIP:Moderators'], apply: true);
 */
final class GroupMemberships
{
    /** The powers of the roles of a group OpenSimulator 0.9.3 makes (GroupsService: DefaultEveryonePowers, OfficersPowers, OwnerPowers). */
    public const EVERYONE = 62672565501952;

    public const OFFICERS = 560750921775310;

    public const OWNERS = 4503599627370494;

    /** The role every member is in. */
    private const EVERYONE_ROLE = '00000000-0000-0000-0000-000000000000';

    /** @var array<string,array{id:?string,roles:array<string,?string>}> the groups known, by their name in lower case (null id: one a plan would make) */
    private array $groups = [];

    /**
     * @param bool $create make a group or a role that does not exist (--create-groups), else tell it
     * @param ?string $founder the founder of a group made: the owner of the grid (founder()) when none is given
     */
    public function __construct(private readonly OSPDO $pdo, private readonly bool $create = false, private ?string $founder = null) {}

    /**
     * Put an avatar in its groups, each `Group` or `Group:Role` (a group whose whole name has a colon is that group).
     *
     * @param list<string> $specs
     * @return list<array{group:string,role:?string,status:string,detail:string}> status: joined, would join, already, error
     */
    public function join(string $avatar, array $specs, bool $apply): array
    {
        $results = [];
        foreach ($specs as $spec) {
            [$group, $role] = $this->split(trim($spec));
            $row = ['group' => $group, 'role' => $role, 'status' => '', 'detail' => ''];
            try {
                $results[] = $this->one($avatar, $group, $role, $apply) + $row;
            } catch (\Throwable $error) {
                $results[] = ['status' => 'error', 'detail' => $error->getMessage()] + $row;
            }
        }

        return $results;
    }

    /** The UUID of an account of the grid by its name, null when it has none. */
    public function avatar(string $first, string $last): ?string
    {
        $id = $this->pdo->table_exists('UserAccounts') ? $this->pdo->get_var('SELECT PrincipalID FROM UserAccounts WHERE LOWER(FirstName) = :f AND LOWER(LastName) = :l', ['f' => strtolower($first), 'l' => strtolower($last)]) : null;

        return is_string($id) && $id !== '' ? strtolower($id) : null;
    }

    /** The founder of a group made: the owner of the estate of the default region of the grid, else its first god. */
    public function founder(): ?string
    {
        if ($this->founder !== null) {
            return $this->founder;
        }
        $owner = $this->pdo->table_exists('regions') ? $this->pdo->get_var('SELECT owner_uuid FROM regions WHERE (flags & 1) = 1 ORDER BY regionName LIMIT 1') : null;
        if (!is_string($owner) || !preg_match('/^[0-9a-f-]{36}$/i', $owner) || $owner === self::EVERYONE_ROLE) {
            $owner = $this->pdo->table_exists('UserAccounts') ? $this->pdo->get_var('SELECT PrincipalID FROM UserAccounts WHERE UserLevel >= 200 ORDER BY Created LIMIT 1') : null;
        }

        return $this->founder = is_string($owner) && $owner !== '' ? strtolower($owner) : null;
    }

    /** @return array{status:string,detail:string} */
    private function one(string $avatar, string $group, ?string $role, bool $apply): array
    {
        $notes = [];
        $known = $this->group($group);
        if ($known === null) {
            $this->create || throw new \RuntimeException("group \"$group\" does not exist (--create-groups makes it)");
            $founder = $this->founder() ?? throw new \RuntimeException("group \"$group\" does not exist, and the grid has no owner to found it");
            $known = $this->groups[strtolower($group)] = ['id' => $apply ? $this->makeGroup($group, $founder) : null, 'roles' => ['everyone' => self::EVERYONE_ROLE]];
            $notes[] = $apply ? 'group made' : 'would make the group';
        }
        $roleId = self::EVERYONE_ROLE;
        if ($role !== null) {
            $roleId = $known['roles'][strtolower($role)] ?? ($known['id'] === null ? null : $this->role((string) $known['id'], $role));
            if ($roleId === null && !array_key_exists(strtolower($role), $known['roles'])) {
                $this->create || throw new \RuntimeException("role \"$role\" of \"$group\" does not exist (--create-groups makes it)");
                $roleId = $apply && $known['id'] !== null ? $this->makeRole((string) $known['id'], $role, $group) : null;
                $this->groups[strtolower($group)]['roles'][strtolower($role)] = $roleId;
                $notes[] = $apply ? 'role made' : 'would make the role';
            }
        }
        if ($known['id'] === null || $roleId === null && !$apply) {
            return ['status' => 'would join', 'detail' => implode(', ', $notes)];
        }
        $member = (int) $this->pdo->get_var('SELECT COUNT(*) FROM os_groups_membership WHERE GroupID = :g AND PrincipalID = :p', ['g' => $known['id'], 'p' => $avatar]) > 0;
        $inRole = $roleId === null || (int) $this->pdo->get_var('SELECT COUNT(*) FROM os_groups_rolemembership WHERE GroupID = :g AND RoleID = :r AND PrincipalID = :p', ['g' => $known['id'], 'r' => $roleId, 'p' => $avatar]) > 0;
        if ($member) {
            $inRole || $notes[] = $apply ? 'role added' : 'would add the role';
            $apply && !$inRole && $this->inRole((string) $known['id'], (string) $roleId, $avatar);

            return ['status' => 'already', 'detail' => implode(', ', $notes)];
        }
        if (!$apply) {
            return ['status' => 'would join', 'detail' => implode(', ', $notes)];
        }
        $this->member((string) $known['id'], (string) $roleId, $avatar);

        return ['status' => 'joined', 'detail' => implode(', ', $notes)];
    }

    /** @return array{0:string,1:?string} a group and its role: the whole name when a group has it, else the role after the last colon */
    private function split(string $spec): array
    {
        $colon = strrpos($spec, ':');
        if ($colon === false || $this->group($spec) !== null) {
            return [$spec, null];
        }
        $role = trim(substr($spec, $colon + 1));

        return [trim(substr($spec, 0, $colon)), $role === '' ? null : $role];
    }

    /** @return ?array{id:?string,roles:array<string,?string>} a group of this grid by its name (not the copy of one of another grid) */
    private function group(string $name): ?array
    {
        $key = strtolower($name);
        if (!isset($this->groups[$key])) {
            $id = $this->pdo->get_var("SELECT GroupID FROM os_groups_groups WHERE Location = '' AND LOWER(Name) = :n", ['n' => $key]);
            if (!is_string($id) || $id === '') {
                return null;
            }
            $this->groups[$key] = ['id' => $id, 'roles' => []];
        }

        return $this->groups[$key];
    }

    private function role(string $group, string $name): ?string
    {
        $id = $this->pdo->get_var('SELECT RoleID FROM os_groups_roles WHERE GroupID = :g AND LOWER(Name) = :n', ['g' => $group, 'n' => strtolower($name)]);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** A group as GroupsService::CreateGroup makes it: shown in the search, enrollment closed, no fee. */
    private function makeGroup(string $name, string $founder): string
    {
        $id = AccountWriter::uuid();
        $owners = AccountWriter::uuid();
        $officers = AccountWriter::uuid();
        $this->pdo->insert('os_groups_groups', ['GroupID' => $id, 'Location' => '', 'Name' => $name, 'Charter' => '', 'InsigniaID' => self::EVERYONE_ROLE, 'FounderID' => $founder, 'MembershipFee' => 0, 'OpenEnrollment' => '0', 'ShowInList' => 1, 'AllowPublish' => 0, 'MaturePublish' => 0, 'OwnerRoleID' => $owners]);
        $this->addRole($id, self::EVERYONE_ROLE, 'Everyone', 'Everyone in the group is in the everyone role.', "Member of $name", self::EVERYONE);
        $this->addRole($id, $officers, 'Officers', 'The officers of the group, with more powers than regular members.', "Officer of $name", self::OFFICERS);
        $this->addRole($id, $owners, 'Owners', 'Owners of the group', "Owner of $name", self::OWNERS);
        $this->member($id, $owners, $founder);
        $this->inRole($id, $officers, $founder);
        $this->groups[strtolower($name)] = ['id' => $id, 'roles' => ['everyone' => self::EVERYONE_ROLE, 'officers' => $officers, 'owners' => $owners]];

        return $id;
    }

    /** A role with the powers of Everyone, its name its title. */
    private function makeRole(string $group, string $name, string $groupName): string
    {
        $id = AccountWriter::uuid();
        $this->addRole($group, $id, $name, '', $name, self::EVERYONE);

        return $id;
    }

    private function addRole(string $group, string $role, string $name, string $description, string $title, int $powers): void
    {
        $this->pdo->insert('os_groups_roles', ['GroupID' => $group, 'RoleID' => $role, 'Name' => $name, 'Description' => $description, 'Title' => $title, 'Powers' => $powers]);
    }

    /** A member as GroupsService::_AddAgentToGroup makes one: its membership, Everyone and its role, its active group when it has none. */
    private function member(string $group, string $role, string $avatar): void
    {
        $this->pdo->insert('os_groups_membership', ['GroupID' => $group, 'PrincipalID' => $avatar, 'SelectedRoleID' => $role, 'Contribution' => 0, 'ListInProfile' => 1, 'AcceptNotices' => 1, 'AccessToken' => '']);
        $this->inRole($group, self::EVERYONE_ROLE, $avatar);
        $role === self::EVERYONE_ROLE || $this->inRole($group, $role, $avatar);
        $active = $this->pdo->get_var('SELECT ActiveGroupID FROM os_groups_principals WHERE PrincipalID = :p', ['p' => $avatar]);
        if ($active === null || $active === false) {
            $this->pdo->insert('os_groups_principals', ['PrincipalID' => $avatar, 'ActiveGroupID' => $group]);
        } elseif ($active === '' || $active === self::EVERYONE_ROLE) {
            $this->pdo->query("UPDATE os_groups_principals SET ActiveGroupID = " . $this->pdo->quote($group) . ' WHERE PrincipalID = ' . $this->pdo->quote($avatar));
        }
    }

    private function inRole(string $group, string $role, string $avatar): void
    {
        $this->pdo->insert('os_groups_rolemembership', ['GroupID' => $group, 'RoleID' => $role, 'PrincipalID' => $avatar]);
    }
}
