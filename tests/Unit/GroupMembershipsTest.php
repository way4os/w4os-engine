<?php

declare(strict_types=1);

/**
 * The groups of the accounts of a list (Groups V2, the tables of Robust): each avatar in its groups, with a role; a group or a role
 * that does not exist an error, or made as OpenSimulator makes them (--create-groups), the owner of the grid founding them; nothing
 * taken away; a plan writes nothing.
 */

use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Provisioning\GroupMemberships;

const GROUPS_OWNER = 'aaaaaaaa-0000-0000-0000-000000000001';
const GROUPS_ADA = 'bbbbbbbb-0000-0000-0000-000000000002';

/** The tables of a grid with its owner (the estate owner of its default region), a group Speakers with a role Moderators. */
function groups_database(): OSPDO
{
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec("CREATE TABLE os_groups_groups (GroupID CHAR(36) PRIMARY KEY, Location VARCHAR(255) NOT NULL DEFAULT '', Name VARCHAR(255) NOT NULL DEFAULT '', Charter TEXT NOT NULL DEFAULT '', InsigniaID CHAR(36) NOT NULL DEFAULT '', FounderID CHAR(36) NOT NULL DEFAULT '', MembershipFee INT NOT NULL DEFAULT 0, OpenEnrollment VARCHAR(255) NOT NULL DEFAULT '', ShowInList INT NOT NULL DEFAULT 0, AllowPublish INT NOT NULL DEFAULT 0, MaturePublish INT NOT NULL DEFAULT 0, OwnerRoleID CHAR(36) NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE os_groups_membership (GroupID CHAR(36), PrincipalID VARCHAR(255), SelectedRoleID CHAR(36) NOT NULL DEFAULT '', Contribution INT NOT NULL DEFAULT 0, ListInProfile INT NOT NULL DEFAULT 1, AcceptNotices INT NOT NULL DEFAULT 1, AccessToken CHAR(36) NOT NULL DEFAULT '', PRIMARY KEY (GroupID, PrincipalID))");
    $pdo->exec("CREATE TABLE os_groups_roles (GroupID CHAR(36), RoleID CHAR(36), Name VARCHAR(255) NOT NULL DEFAULT '', Description VARCHAR(255) NOT NULL DEFAULT '', Title VARCHAR(255) NOT NULL DEFAULT '', Powers BIGINT NOT NULL DEFAULT 0, PRIMARY KEY (GroupID, RoleID))");
    $pdo->exec('CREATE TABLE os_groups_rolemembership (GroupID CHAR(36), RoleID CHAR(36), PrincipalID VARCHAR(255), PRIMARY KEY (GroupID, RoleID, PrincipalID))');
    $pdo->exec("CREATE TABLE os_groups_principals (PrincipalID VARCHAR(255) PRIMARY KEY, ActiveGroupID CHAR(36) NOT NULL DEFAULT '')");
    $pdo->exec('CREATE TABLE regions (uuid CHAR(36), regionName VARCHAR(128), flags INT, owner_uuid CHAR(36))');
    $pdo->exec("INSERT INTO regions VALUES ('r1', 'Welcome', 1, '" . GROUPS_OWNER . "')");
    $pdo->exec("INSERT INTO os_groups_groups (GroupID, Name, FounderID, OwnerRoleID) VALUES ('g1', 'Speakers', '" . GROUPS_OWNER . "', 'own1')");
    $pdo->exec("INSERT INTO os_groups_roles (GroupID, RoleID, Name, Powers) VALUES ('g1', '00000000-0000-0000-0000-000000000000', 'Everyone', " . GroupMemberships::EVERYONE . "), ('g1', 'mod1', 'Moderators', 1)");

    return $pdo;
}

/** @return array<string,array<string,mixed>> the results of an avatar by their group */
function groups_by(array $results): array
{
    return array_column($results, null, 'group');
}

describe('The groups of the accounts of a list', function () {
    test('put an avatar in its groups, with its role, as OpenSimulator does; an error for a group or a role that does not exist', function () {
        $pdo = groups_database();
        $results = groups_by((new GroupMemberships($pdo))->join(GROUPS_ADA, ['Speakers:Moderators', 'VIP'], true));

        expect($results['Speakers'])->toMatchArray(['role' => 'Moderators', 'status' => 'joined'])
            ->and($results['VIP'])->toMatchArray(['status' => 'error'])
            ->and($results['VIP']['detail'])->toContain('--create-groups')
            ->and($pdo->get_var("SELECT COUNT(*) FROM os_groups_membership WHERE GroupID = 'g1' AND PrincipalID = '" . GROUPS_ADA . "'"))->toBe(1)
            ->and(array_column($pdo->get_results("SELECT RoleID FROM os_groups_rolemembership WHERE PrincipalID = '" . GROUPS_ADA . "' ORDER BY RoleID", [], PDO::FETCH_ASSOC), 'RoleID'))->toBe(['00000000-0000-0000-0000-000000000000', 'mod1'])
            ->and($pdo->get_var("SELECT ActiveGroupID FROM os_groups_principals WHERE PrincipalID = '" . GROUPS_ADA . "'"))->toBe('g1')
            // The role that does not exist: an error, the avatar stays in the group
            ->and((new GroupMemberships($pdo))->join(GROUPS_ADA, ['Speakers:Nobody'], true)[0])->toMatchArray(['status' => 'error']);
    });

    test('make a group and a role that do not exist with --create-groups: the roles of OpenSimulator, the owner of the grid founding it', function () {
        $pdo = groups_database();
        $results = groups_by((new GroupMemberships($pdo, create: true))->join(GROUPS_ADA, ['VIP:Hosts', 'Speakers:Ushers'], true));
        $vip = (string) $pdo->get_var("SELECT GroupID FROM os_groups_groups WHERE Name = 'VIP'");
        $roles = array_column($pdo->get_results("SELECT Name, Powers FROM os_groups_roles WHERE GroupID = :g", ['g' => $vip], PDO::FETCH_ASSOC), 'Powers', 'Name');

        expect($results['VIP'])->toMatchArray(['role' => 'Hosts', 'status' => 'joined'])
            ->and($results['VIP']['detail'])->toContain('group made')
            ->and($results['Speakers']['detail'])->toContain('role made')
            ->and(array_map('intval', $roles))->toEqual(['Everyone' => GroupMemberships::EVERYONE, 'Officers' => GroupMemberships::OFFICERS, 'Owners' => GroupMemberships::OWNERS, 'Hosts' => GroupMemberships::EVERYONE])
            ->and($pdo->get_var('SELECT FounderID FROM os_groups_groups WHERE GroupID = :g', ['g' => $vip]))->toBe(GROUPS_OWNER)
            // The founder in its Owners and Officers roles, as OpenSimulator makes a group
            ->and((int) $pdo->get_var('SELECT COUNT(*) FROM os_groups_rolemembership WHERE GroupID = :g AND PrincipalID = :p', ['g' => $vip, 'p' => GROUPS_OWNER]))->toBe(3)
            ->and((int) $pdo->get_var("SELECT Powers FROM os_groups_roles WHERE GroupID = 'g1' AND Name = 'Ushers'"))->toBe(GroupMemberships::EVERYONE);
    });

    test('take nothing away: a member already is told, the role asked added; a plan writes nothing and sees what it would make', function () {
        $pdo = groups_database();
        (new GroupMemberships($pdo))->join(GROUPS_ADA, ['Speakers'], true);
        $again = (new GroupMemberships($pdo))->join(GROUPS_ADA, ['Speakers:Moderators'], true)[0];
        $plan = new GroupMemberships($pdo, create: true);
        $first = groups_by($plan->join('cccccccc-0000-0000-0000-000000000003', ['Donors', 'Speakers'], false));
        $second = groups_by($plan->join('dddddddd-0000-0000-0000-000000000004', ['Donors'], false));

        expect($again)->toMatchArray(['status' => 'already'])
            ->and($again['detail'])->toContain('role added')
            ->and((int) $pdo->get_var("SELECT COUNT(*) FROM os_groups_rolemembership WHERE PrincipalID = '" . GROUPS_ADA . "' AND RoleID = 'mod1'"))->toBe(1)
            ->and($first['Donors'])->toMatchArray(['status' => 'would join'])
            ->and($first['Donors']['detail'])->toContain('would make the group')
            // The group the plan would make is known to the next lines
            ->and($second['Donors'])->toMatchArray(['status' => 'would join', 'detail' => ''])
            ->and($first['Speakers'])->toMatchArray(['status' => 'would join'])
            ->and((int) $pdo->get_var("SELECT COUNT(*) FROM os_groups_groups WHERE Name = 'Donors'"))->toBe(0);
    });

    test('read a group named with a colon as it is, else the role after the last one', function () {
        $pdo = groups_database();
        $pdo->exec("INSERT INTO os_groups_groups (GroupID, Name) VALUES ('g2', 'Club: Night')");
        $pdo->exec("INSERT INTO os_groups_roles (GroupID, RoleID, Name, Powers) VALUES ('g2', '00000000-0000-0000-0000-000000000000', 'Everyone', 0)");

        expect((new GroupMemberships($pdo))->join(GROUPS_ADA, ['Club: Night'], false)[0])->toMatchArray(['group' => 'Club: Night', 'role' => null, 'status' => 'would join'])
            ->and((new GroupMemberships($pdo))->join(GROUPS_ADA, ['Speakers:Moderators'], false)[0])->toMatchArray(['group' => 'Speakers', 'role' => 'Moderators']);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('A list of accounts with their groups', function () {
    test('gives the groups of each, a list in YAML or JSON, a column separated by | in CSV', function () {
        $csv = W4OS\Engine\Provisioning\AccountList::parse("first,last,groups\nJane,Doe,\"Speakers|VIP:Moderators\"\nJohn,Roe,\n");
        $json = W4OS\Engine\Provisioning\AccountList::parse('[{"first":"Ann","last":"Lee","groups":["Donors","VIP:Hosts"]}]');

        expect(array_column($csv['accounts'], 'groups', 'first'))->toBe(['Jane' => ['Speakers', 'VIP:Moderators'], 'John' => []])
            ->and($json['accounts'][0]['groups'])->toBe(['Donors', 'VIP:Hosts']);
    });

    test('puts each account in its groups, the ones that exist too (a list of groups alone), never in a plan', function () {
        $pdo = groups_database();
        $pdo->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64), UserLevel INT DEFAULT 0, Created INT DEFAULT 0)');
        $pdo->exec("INSERT INTO UserAccounts VALUES ('" . GROUPS_ADA . "', 'Ada', 'Love', 0, 1)");
        $importer = new W4OS\Engine\Provisioning\AccountImporter(
            static fn(): array => ['Ada Love'],
            static fn(): ?string => null,
            static function (?W4OS\Engine\Models\Grid $grid, array $values) use ($pdo): void {
                $pdo->exec("INSERT INTO UserAccounts VALUES ('eeeeeeee-0000-0000-0000-000000000005', " . $pdo->quote($values['firstName']) . ', ' . $pdo->quote($values['lastName']) . ', 0, 2)');
            },
            static fn(?W4OS\Engine\Models\Grid $grid, ?W4OS\Engine\Config\GridConfig $config, bool $create): GroupMemberships => new GroupMemberships($pdo, $create),
        );
        $list = W4OS\Engine\Provisioning\AccountList::parse("first,last,groups\nAda,Love,Speakers:Moderators\nBob,Root,Speakers|VIP\n")['accounts'];
        $config = new W4OS\Engine\Config\GridConfig([]);

        $plan = $importer->run($config, $list, false);
        $planned = (int) $pdo->get_var('SELECT COUNT(*) FROM os_groups_membership');
        $done = $importer->run($config, $list, true, createGroups: true);
        $by = array_column($done['results'], 'groups', 'first');

        expect($plan['results'][0]['groups'][0])->toMatchArray(['group' => 'Speakers', 'role' => 'Moderators', 'status' => 'would join'])
            ->and($planned)->toBe(0)
            ->and(array_column($done['results'], 'status'))->toBe([W4OS\Engine\Provisioning\AccountImporter::EXISTS, W4OS\Engine\Provisioning\AccountImporter::CREATED])
            ->and(array_column($by['Ada'], 'status'))->toBe(['joined'])
            ->and(array_column($by['Bob'], 'status', 'group'))->toBe(['Speakers' => 'joined', 'VIP' => 'joined'])
            ->and((int) $pdo->get_var("SELECT COUNT(*) FROM os_groups_membership WHERE PrincipalID = 'eeeeeeee-0000-0000-0000-000000000005'"))->toBe(2);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');
