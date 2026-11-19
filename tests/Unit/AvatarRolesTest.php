<?php

declare(strict_types=1);

/**
 * The avatars of the list (avatar_grid() and avatar_database() of AvatarTest): the role column says a model and the account of the
 * services as roles of their own, as w4os-wp; each says how many estates, regions and parcels it owns.
 */

use OpenSim\Data\Connections;
use OpenSim\Models\Avatar;

afterEach(fn() => Connections::using(null));

describe('The avatars of a list', function () {
    test('show a model and the account of the services in the role column, the others their role', function () {
        $pdo = avatar_database();
        [$first, $last] = Avatar::TECHNICAL;
        $pdo->exec("INSERT INTO UserAccounts (PrincipalID, FirstName, LastName, Email, Created, UserLevel, UserFlags, active) VALUES
            ('44444444-4444-4444-4444-444444444444', 'Default', 'Ruth', '', 1700000000, 0, 0, 1),
            ('55555555-5555-5555-5555-555555555555', '$first', '$last', '', 1700000000, 0, 0, 1)");
        Connections::using(static fn() => $pdo);

        $rows = array_column(Avatar::all(avatar_grid("[Models]\n Match = first\n MatchPattern = Default\n"))->array(), null, 'name');
        $column = array_column(Avatar::listing()->columns, null, 'name');

        expect(array_map(static fn(array $row): string => $row['shownRole'], $rows))->toMatchArray([
            'Ada Love' => 'resident', 'Bob Root' => 'maintenance', 'Default Ruth' => 'model', "$first $last" => 'service-account',
        ])
            ->and($column['shownRole'])->toMatchArray(['label' => 'role', 'type' => 'role', 'sort' => 'level'])
            ->and(OpenSim\I18n\Catalog::MESSAGES[OpenSim\I18n\Catalog::roleKey('service-account')])->toBe('Service Account');
    });

    test('say how many estates, regions and parcels each owns', function () {
        $pdo = avatar_database();
        $pdo->exec("INSERT INTO regions (uuid, regionName, locX, locY, sizeX, sizeY, owner_uuid) VALUES ('r1', 'Plaza', 1, 1, 256, 256, '11111111-1111-1111-1111-111111111111'), ('r2', 'Garden', 2, 1, 256, 256, '11111111-1111-1111-1111-111111111111')");
        $pdo->exec('CREATE TABLE estate_settings (EstateID INT, EstateName VARCHAR(64), EstateOwner CHAR(36))');
        $pdo->exec("INSERT INTO estate_settings VALUES (101, 'Ada estate', '11111111-1111-1111-1111-111111111111')");
        $pdo->exec('CREATE TABLE land (UUID CHAR(36), RegionUUID CHAR(36), OwnerUUID CHAR(36), IsGroupOwned INT)');
        $pdo->exec("INSERT INTO land VALUES ('l1', 'r1', '22222222-2222-2222-2222-222222222222', 0), ('l2', 'r1', '22222222-2222-2222-2222-222222222222', 1)");
        Connections::using(static fn() => $pdo);
        // A simulator of the grid, whose database holds the estates and the parcels
        $dir = dirname(avatar_grid()->files()[0]);
        mkdir("$dir/sims", 0o755, true);
        file_put_contents("$dir/sims/alpha.ini", "[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=alpha;User ID=os;Password=pw;\"\n");
        $config = OpenSim\Config\GridConfig::fromFolder($dir);

        $rows = array_column(Avatar::all($config)->array(), null, 'name');
        $column = array_column(Avatar::listing()->columns, null, 'name');

        expect([$rows['Ada Love']['estates'], $rows['Ada Love']['regions'], $rows['Bob Root']['regions'], $rows['Bob Root']['parcels'], $rows['Ada Love']['parcels']])->toBe([1, 2, 0, 1, 0])
            ->and([$column['estates']['type'] ?? null, $column['regions']['type'] ?? null, $column['parcels']['type'] ?? null])->toBe(['number', 'number', 'number']);
    });
});
