<?php

declare(strict_types=1);

/**
 * The groups the search of the viewers finds, as OpenSimulator finds them (GroupsService::FindGroups): shown in the search, of this
 * grid, with members; and the members a role shows to who is not a member.
 */

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Connections;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Models\Group;

/** A grid with groups: shown or not in the search, a copy of a group of another grid, one without members. */
function group_grid(): GridConfig
{
    $dir = test_tmp() . '/groups-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o755, true);
    file_put_contents("$dir/Robust.HG.ini", "[Const]\n BaseURL = \"http://play.example.org\"\n PublicPort = \"8002\"\n[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=robust;User ID=os;Password=pw;\"\n");
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36) PRIMARY KEY, ScopeID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64), Email VARCHAR(64), Created INT, UserLevel INT DEFAULT 0, UserFlags INT DEFAULT 0, UserTitle VARCHAR(64) DEFAULT \'\', active INT DEFAULT 1)');
    $pdo->exec('CREATE TABLE GridUser (UserID VARCHAR(255) PRIMARY KEY, HomeRegionID CHAR(36), LastRegionID CHAR(36), Online CHAR(5) DEFAULT \'false\', Login CHAR(16) DEFAULT \'0\', Logout CHAR(16) DEFAULT \'0\')');
    $pdo->exec("INSERT INTO UserAccounts (PrincipalID, FirstName, LastName, Created) VALUES
        ('11111111-1111-1111-1111-111111111111', 'Ada', 'Love', 1700000000),
        ('22222222-2222-2222-2222-222222222222', 'Bob', 'Root', 1600000000),
        ('33333333-3333-3333-3333-333333333333', 'Cy', 'Hidden', 1650000000)");
    $pdo->exec("INSERT INTO GridUser (UserID, Online, Login) VALUES ('11111111-1111-1111-1111-111111111111', 'true', '1700000100'), ('22222222-2222-2222-2222-222222222222', 'false', '1700000200')");
    $pdo->exec('CREATE TABLE os_groups_groups (GroupID CHAR(36), Location VARCHAR(255), Name VARCHAR(255), Charter TEXT, InsigniaID CHAR(36), FounderID CHAR(36), MembershipFee INT, OpenEnrollment VARCHAR(255), ShowInList INT, AllowPublish INT, MaturePublish INT, OwnerRoleID CHAR(36))');
    $pdo->exec('CREATE TABLE os_groups_membership (GroupID CHAR(36), PrincipalID VARCHAR(255), SelectedRoleID CHAR(36), Contribution INT, ListInProfile INT, AcceptNotices INT, AccessToken CHAR(36))');
    $pdo->exec('CREATE TABLE os_groups_roles (GroupID CHAR(36), RoleID CHAR(36), Name VARCHAR(255), Description VARCHAR(255), Title VARCHAR(255), Powers BIGINT)');
    $pdo->exec("INSERT INTO os_groups_groups VALUES
        ('g1', '', 'Builders', 'We build', 'aaaaaaaa-0000-4000-8000-000000000002', '11111111-1111-1111-1111-111111111111', 10, '1', 1, 1, 1, 'r1'),
        ('g2', '', 'Garden builders', '', '00000000-0000-0000-0000-000000000000', '22222222-2222-2222-2222-222222222222', 0, '', 1, 1, 0, 'r3'),
        ('g3', '', 'Secret builders', '', '', '', 0, '', 0, 0, 0, ''),
        ('g4', 'http://other.example.org:8002', 'Visiting builders', '', '', '', 0, '', 1, 1, 0, ''),
        ('g5', '', 'Empty builders', '', '', '', 0, '', 1, 1, 0, '')");
    $visible = Group::MEMBER_VISIBLE;
    $pdo->exec("INSERT INTO os_groups_roles VALUES
        ('g1', 'r1', 'Owners', '', 'Chief', $visible + 1),
        ('g1', 'r2', 'Hidden', '', 'Ghost', 1),
        ('g2', 'r3', 'Everyone', '', '', $visible)");
    $pdo->exec("INSERT INTO os_groups_membership VALUES
        ('g1', '11111111-1111-1111-1111-111111111111', 'r1', 0, 1, 1, ''),
        ('g1', '22222222-2222-2222-2222-222222222222', 'r1', 0, 1, 1, ''),
        ('g1', '33333333-3333-3333-3333-333333333333', 'r2', 0, 1, 1, ''),
        ('g2', '22222222-2222-2222-2222-222222222222', 'r3', 0, 1, 1, ''),
        ('g3', '22222222-2222-2222-2222-222222222222', 'r3', 0, 1, 1, ''),
        ('g4', '22222222-2222-2222-2222-222222222222', 'r3', 0, 1, 1, '')");
    Connections::using(static fn() => $pdo);

    return GridConfig::fromFolder($dir);
}

afterEach(fn() => Connections::using(null));

describe('The groups the search finds', function () {
    test('are the ones shown in the search, of this grid, with members, their name with the words', function () {
        $grid = group_grid();
        $found = Group::all($grid, ['search' => 'builders']);

        expect(array_map(static fn(Group $g): string => $g->name(), $found->items()))->toBe(['Builders', 'Garden builders'])
            ->and($found->total())->toBe(2)
            ->and(array_map(static fn(Group $g): string => $g->name(), Group::all($grid, ['search' => 'garden'])->items()))->toBe(['Garden builders']);
    });

    test('sort by their members, then by name, and come by pages', function () {
        $grid = group_grid();
        $byMembers = Group::all($grid, ['search' => 'builders', 'sort' => 'members desc']);
        $second = Group::all($grid, ['search' => 'builders', 'perPage' => 1, 'page' => 2]);

        expect(array_map(static fn(Group $g): array => [$g->name(), $g->detail()['members']], $byMembers->items()))->toBe([['Builders', 3], ['Garden builders', 1]])
            ->and(array_map(static fn(Group $g): string => $g->name(), $second->items()))->toBe(['Garden builders'])
            ->and($second->total())->toBe(2);
    });

    test('say what anyone sees of them: charter, insignia, founder, who can join, the fee, the content rating', function () {
        $detail = Group::find(group_grid(), 'g1')->detail();

        expect($detail)->toBe([
            'id' => 'g1', 'name' => 'Builders', 'charter' => 'We build', 'picture' => 'aaaaaaaa-0000-4000-8000-000000000002',
            'founder' => '11111111-1111-1111-1111-111111111111', 'members' => 3, 'open' => true, 'fee' => 10, 'mature' => true,
        ])->and(Group::find(group_grid(), 'g2')->detail()['picture'])->toBeNull();
    });

    test('show the members of the roles that show them, with their title and whether they are in the world', function () {
        expect(Group::find(group_grid(), 'g1')->members())->toBe([
            ['id' => '11111111-1111-1111-1111-111111111111', 'name' => 'Ada Love', 'title' => 'Chief', 'online' => true, 'login' => 1700000100],
            ['id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Bob Root', 'title' => 'Chief', 'online' => false, 'login' => 1700000200],
        ]);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');
