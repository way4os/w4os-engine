<?php

declare(strict_types=1);

/**
 * What the regions hold, from the databases of their simulators (parcel_config() and parcel_database() of ParcelTest): their prims
 * (the rows of `prims`) and their scripts (the items of `primitems` of the type LSL, 10), counted once for a list.
 */

use OpenSim\Data\Connections;
use OpenSim\Data\Stats;
use OpenSim\Models\Parcel;
use OpenSim\Models\Region;

const CONTENT_REGION = 'aaaaaaaa-0000-0000-0000-000000000001';

/** A simulator database with three prims in a region, two of them with a script, one with a notecard. */
function content_database(): OpenSim\Data\OSPDO
{
    $pdo = parcel_database([]);
    $pdo->exec('CREATE TABLE prims (UUID CHAR(36), RegionUUID CHAR(36))');
    $pdo->exec('CREATE TABLE primitems (itemID CHAR(36), primID CHAR(36), invType INT)');
    foreach (['p1', 'p2', 'p3'] as $prim) {
        $pdo->exec("INSERT INTO prims VALUES ('$prim', '" . CONTENT_REGION . "')");
    }
    $pdo->exec("INSERT INTO primitems VALUES ('i1', 'p1', 10), ('i2', 'p2', 10), ('i3', 'p2', 7)");

    return $pdo;
}

afterEach(fn() => Connections::using(null));

describe('What the regions hold', function () {
    test('is counted in the databases of their simulators: prims and scripts', function () {
        $db = content_database();

        expect(Stats::content([$db]))->toBe([CONTENT_REGION => ['prims' => 3, 'scripts' => 2]])
            // A database without these tables holds nothing it can tell
            ->and(Stats::content([parcel_database([])]))->toBe([]);
    });

    test('is in the list of the regions, and its columns', function () {
        $db = content_database();
        $db->exec("INSERT INTO regions VALUES ('" . CONTENT_REGION . "', 0, 'Plaza', 256, 256, 1000, 1000, '127.0.0.1', 9000, 'http://127.0.0.1:9000/', 0, 0, '11111111-1111-1111-1111-111111111111', 0, '00000000-0000-0000-0000-000000000000', '00000000-0000-0000-0000-000000000000')");
        Connections::using(static fn() => $db);

        $plaza = Region::all(parcel_config())->array()[0] ?? [];
        $columns = array_column(Region::listing()->columns, null, 'name');

        expect([$plaza['prims'] ?? null, $plaza['scripts'] ?? null])->toBe([3, 2])
            ->and([$columns['prims']['type'] ?? null, $columns['scripts']['type'] ?? null])->toBe(['number', 'number'])
            ->and(count(iterator_to_array(Parcel::simulatorDatabases(parcel_config()))))->toBe(2);
    });
});

describe('The size of a region', function () {
    test('is its dimensions in meters, a type of its own: 256 × 256 m, not the size of a file', function () {
        $columns = array_column(Region::listing()->columns, null, 'name');

        expect($columns['size']['type'])->toBe('dimensions')
            ->and(sprintf(OpenSim\I18n\Catalog::MESSAGES['unit_dimensions'], '256', '256'))->toBe('256 × 256 m')
            ->and(sprintf(OpenSim\I18n\Catalog::messages('fr_FR', false)['unit_dimensions'] ?? '', '256', '256'))->toBe('256 × 256 m')
            // The size of a file is in bytes
            ->and(array_column(OpenSim\Models\Asset::listing()->columns, null, 'name')['size']['type'])->toBe('size');
    });
});
