<?php

declare(strict_types=1);

/**
 * The list of the regions as Olivier sorts and filters it: the presence, the prims and the scripts in their own columns on the widest
 * screens, the type of a region (RegionType of its file) in place of them on the others.
 */

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Connections;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\I18n\Catalog;
use W4OS\Engine\Models\Region;

const LIST_ADA = '11111111-1111-1111-1111-111111111111';
const LIST_BOB = '22222222-2222-2222-2222-222222222222';

/** A grid of three regions, two of them with a type in their file, their simulators sharing one database. */
function region_list_grid(): GridConfig
{
    $dir = test_tmp() . '/region-list-' . bin2hex(random_bytes(4));
    $db = "[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=shared;User ID=os;Password=pw;\"\n";
    mkdir("$dir/sims/alpha/regions", 0o755, true);
    mkdir("$dir/sims/beta/regions", 0o755, true);
    file_put_contents("$dir/Robust.HG.ini", "[Const]\n BaseURL = \"http://play.example.org\"\n PublicPort = \"8002\"\n$db");
    foreach (['alpha', 'beta'] as $sim) {
        file_put_contents("$dir/sims/$sim.ini", "[Startup]\n regionload_regionsdir = \"$dir/sims/$sim/regions\"\n$db");
    }
    file_put_contents("$dir/sims/alpha/regions/Welcome.ini", "[Welcome]\nRegionUUID = aaaaaaaa-0000-0000-0000-000000000001\nLocation = 1000,1000\nRegionType = \"Mainland\"\n");
    file_put_contents("$dir/sims/alpha/regions/Garden.ini", "[Garden]\nRegionUUID = aaaaaaaa-0000-0000-0000-000000000002\nLocation = 1001,1000\nRegionType = \"Residences\"\n");
    file_put_contents("$dir/sims/beta/regions/Harbour.ini", "[Harbour]\nRegionUUID = aaaaaaaa-0000-0000-0000-000000000003\nLocation = 1002,1000\n");

    return GridConfig::fromFolder($dir);
}

/** The database of the grid and of its simulators: Garden with two avatars, Harbour with one and three prims, two of them scripted. */
function region_list_database(): OSPDO
{
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec('CREATE TABLE regions (uuid VARCHAR(36) PRIMARY KEY, regionHandle BIGINT, regionName VARCHAR(128), locX INT, locY INT, sizeX INT DEFAULT 256, sizeY INT DEFAULT 256, serverIP VARCHAR(64), serverPort INT, serverURI VARCHAR(255), flags INT DEFAULT 0, last_seen INT DEFAULT 0, owner_uuid CHAR(36), access INT DEFAULT 21, ScopeID CHAR(36), regionMapTexture VARCHAR(36))');
    $pdo->exec("INSERT INTO regions (uuid, regionName, locX, locY, serverURI, owner_uuid) VALUES
        ('aaaaaaaa-0000-0000-0000-000000000001', 'Welcome', 256000, 256000, 'http://10.0.0.1:9000/', '" . LIST_BOB . "'),
        ('aaaaaaaa-0000-0000-0000-000000000002', 'Garden', 256256, 256000, 'http://10.0.0.1:9000/', '" . LIST_ADA . "'),
        ('aaaaaaaa-0000-0000-0000-000000000003', 'Harbour', 256512, 256000, 'http://10.0.0.2:9010/', '00000000-0000-0000-0000-000000000000')");
    $pdo->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64))');
    $pdo->exec("INSERT INTO UserAccounts VALUES ('" . LIST_ADA . "', 'Ada', 'Love'), ('" . LIST_BOB . "', 'Bob', 'Stone')");
    $pdo->exec('CREATE TABLE Presence (UserID CHAR(36), RegionID CHAR(36))');
    $pdo->exec("INSERT INTO Presence VALUES ('" . LIST_ADA . "', 'aaaaaaaa-0000-0000-0000-000000000002'), ('visitor-1', 'aaaaaaaa-0000-0000-0000-000000000002'), ('" . LIST_BOB . "', 'aaaaaaaa-0000-0000-0000-000000000003')");
    $pdo->exec('CREATE TABLE prims (UUID CHAR(36), RegionUUID CHAR(36))');
    $pdo->exec('CREATE TABLE primitems (itemID CHAR(36), primID CHAR(36), invType INT)');
    $pdo->exec("INSERT INTO prims VALUES ('p1', 'aaaaaaaa-0000-0000-0000-000000000003'), ('p2', 'aaaaaaaa-0000-0000-0000-000000000003'), ('p3', 'aaaaaaaa-0000-0000-0000-000000000003'), ('p4', 'aaaaaaaa-0000-0000-0000-000000000001')");
    $pdo->exec("INSERT INTO primitems VALUES ('i1', 'p1', 10), ('i2', 'p2', 10)");

    return $pdo;
}

/** @return list<string> the names of the regions of a list asked with these options */
function region_list_names(array $options): array
{
    return array_column(Region::all(region_list_grid(), $options)->array(), 'name');
}

beforeEach(function () {
    $pdo = region_list_database();
    Connections::using(static fn() => $pdo);
});

afterEach(fn() => Connections::using(null));

describe('The list of the regions', function () {
    test('shows the presence, the prims and the scripts in their own columns, sortable, on the widest screens, the type in place of them', function () {
        $list = Region::listing()->document();
        $columns = array_column($list['columns'], null, 'name');
        $short = static fn(string $locale): array => array_map(
            static fn(string $name): string => Catalog::messages($locale, false)[$columns[$name]['label']] ?? '',
            ['avatars', 'prims', 'scripts'],
        );

        expect(array_keys($columns))->toBe(['name', 'ownerName', 'type', 'serverName', 'avatars', 'prims', 'scripts', 'place', 'lastSeen', 'flagNames', 'status'])
            ->and(array_map(static fn(string $name): array => [$columns[$name]['type'], $columns[$name]['sort'] ?? null, $columns[$name]['priority']], ['avatars', 'prims', 'scripts']))
            ->toBe([['number', 'avatars', 4], ['number', 'prims', 4], ['number', 'scripts', 4]])
            ->and(array_map(static fn(string $name): string => Catalog::MESSAGES[$columns[$name]['label']], ['avatars', 'prims', 'scripts']))->toBe(['av.', 'pr.', 'scr.'])
            ->and($short('fr_FR'))->not->toContain('')
            // Folded under the name: 0 av., 3 prims
            ->and(array_column(array_intersect_key($columns, ['avatars' => 1, 'prims' => 1, 'scripts' => 1]), 'count'))->toBe(['count_avatars_short', 'count_prims', 'count_scripts'])
            ->and($columns['type'])->toMatchArray(['label' => 'column_type', 'sort' => 'type', 'priority' => 2, 'mirrors' => 'type'])
            ->and($list['sort']['values'])->toContain('name', 'type', 'owner', 'simulator', 'avatars', 'prims', 'scripts', 'location')
            ->and(array_column($list['filters'], 'name'))->toContain('simulator', 'owner', 'type', 'size', 'flags')
            ->and(array_column($list['filters'], null, 'name')['owner'])->toMatchArray(['dynamic' => true])
            ->and(array_column($list['filters'], null, 'name')['type'])->toMatchArray(['dynamic' => true]);
    });

    test('gives the type of a region as its file says it (RegionType), none where the engine has no file of it', function () {
        $list = Region::all(region_list_grid());

        expect(array_column($list->array(), 'type', 'name'))->toBe(['Garden' => 'Residences', 'Harbour' => null, 'Welcome' => 'Mainland'])
            ->and($list->available()['type'])->toBe([['value' => 'Mainland', 'label' => 'Mainland'], ['value' => 'Residences', 'label' => 'Residences']])
            ->and(region_list_names(['filters' => ['type' => 'Residences']]))->toBe(['Garden'])
            ->and(region_list_names(['filters' => ['type' => 'mainland']]))->toBe(['Welcome'])
            ->and(region_list_names(['filters' => ['type' => 'Private']]))->toBe([]);
    });

    test('is filtered by owner, the owners of its regions by name', function () {
        $list = Region::all(region_list_grid());

        expect($list->available()['owner'])->toBe([['value' => LIST_ADA, 'label' => 'Ada Love'], ['value' => LIST_BOB, 'label' => 'Bob Stone']])
            ->and(region_list_names(['filters' => ['owner' => LIST_BOB]]))->toBe(['Welcome']);
    });

    test('sorts on what the table of the regions does not hold: the type, the owner, the simulator, the presence, the prims, the scripts', function () {
        $page = Region::all(region_list_grid(), ['sort' => 'prims desc', 'perPage' => 1, 'page' => 2]);

        expect(region_list_names(['sort' => 'type']))->toBe(['Welcome', 'Garden', 'Harbour'])
            ->and(region_list_names(['sort' => 'owner']))->toBe(['Garden', 'Welcome', 'Harbour'])
            ->and(region_list_names(['sort' => 'simulator desc']))->toBe(['Harbour', 'Garden', 'Welcome'])
            ->and(region_list_names(['sort' => 'avatars desc']))->toBe(['Garden', 'Harbour', 'Welcome'])
            ->and(region_list_names(['sort' => 'prims desc']))->toBe(['Harbour', 'Welcome', 'Garden'])
            ->and(region_list_names(['sort' => 'scripts desc']))->toBe(['Harbour', 'Garden', 'Welcome'])
            ->and(region_list_names(['sort' => 'location desc']))->toBe(['Harbour', 'Garden', 'Welcome'])
            // A page of them, as the sort orders them all
            ->and([array_column($page->array(), 'name'), $page->total(), $page->sort()])->toBe([['Welcome'], 3, 'prims desc']);
    });
});
