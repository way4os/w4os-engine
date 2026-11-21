<?php

declare(strict_types=1);

/**
 * What the regions hold, from the databases of their simulators (parcel_config() and parcel_database() of ParcelTest): their prims
 * (the rows of `prims`) and their scripts (the items of `primitems` of the type LSL, 10), counted once for a list.
 */

use W4OS\Engine\Data\Connections;
use W4OS\Engine\Data\Stats;
use W4OS\Engine\Models\Parcel;
use W4OS\Engine\Models\Region;

const CONTENT_REGION = 'aaaaaaaa-0000-0000-0000-000000000001';

/** A simulator database with three prims in a region, two of them with a script, one with a notecard. */
function content_database(): W4OS\Engine\Data\OSPDO
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

        $usage = array_column(array_intersect_key($columns, ['prims' => 1, 'scripts' => 1]), 'type', 'name');

        expect([$plaza['prims'] ?? null, $plaza['scripts'] ?? null])->toBe([3, 2])
            // Its location with a space after the comma, where a narrow column breaks the line
            ->and($plaza['location'] ?? null)->toBe('3, 3')
            ->and([$usage['prims'] ?? null, $usage['scripts'] ?? null])->toBe(['number', 'number'])
            ->and(count(iterator_to_array(Parcel::simulatorDatabases(parcel_config()))))->toBe(2);
    });
});

describe('The size of a region', function () {
    test('is its dimensions in meters, a type of its own: 256 x 256, spaces where a narrow column breaks the line, not the size of a file', function () {
        $columns = array_column(Region::listing()->columns, null, 'name');

        expect(array_column($columns['place']['parts'], 'type', 'name')['size'])->toBe('dimensions')
            ->and(sprintf(W4OS\Engine\I18n\Catalog::MESSAGES['unit_dimensions'], '256', '256'))->toBe('256 x 256')
            ->and(sprintf(W4OS\Engine\I18n\Catalog::messages('fr_FR', false)['unit_dimensions'] ?? '', '256', '256'))->toBe('256 x 256')
            // The size of a file is in bytes
            ->and(array_column(W4OS\Engine\Models\Asset::listing()->columns, null, 'name')['size']['type'])->toBe('size');
    });
});

describe('The columns of a list that hold several values', function () {
    test('give each with its label: the usage of a region, its location and size; the port on its page only', function () {
        $columns = array_column(Region::listing()->document()['columns'], null, 'name');
        $simulators = array_column(W4OS\Engine\Models\Simulator::listing()->document()['columns'], null, 'name');
        $csv = array_column(Region::columns(), 'name');
        $page = array_column(Region::itemForm()->document()['fields'] ?? [], null, 'name');

        expect($columns['place'])->toMatchArray(['type' => 'group', 'label' => 'profile_location', 'sort' => 'location', 'priority' => 3])
            ->and(array_column($columns['place']['parts'], 'type', 'name'))->toBe(['location' => 'text', 'size' => 'dimensions'])
            ->and(array_keys($columns))->not->toContain('internalPort')->not->toContain('usage')->not->toContain('size')
            // The simulators: the presence and the prims each in its own column, sorted on
            ->and(array_keys($simulators))->not->toContain('httpPort')->not->toContain('usage')
            ->and([$simulators['avatars']['sort'] ?? null, $simulators['prims']['sort'] ?? null, $simulators['prims']['priority']])->toBe(['avatars', 'prims', 3])
            // A list exported in CSV has each value in its own column
            ->and($csv)->toContain('avatars')->toContain('prims')->toContain('location')->toContain('size')
            // On the page, its name and why: a port that needs only to be unique on its machine, not a public one
            ->and($page['internalPort'] ?? [])->toMatchArray(['label' => 'column_internal_port', 'description' => 'internal_port_help'])
            ->and(W4OS\Engine\I18n\Catalog::MESSAGES['column_internal_port'])->toBe('Internal Port')
            ->and(W4OS\Engine\I18n\Catalog::messages('fr_FR', false)['internal_port_help'] ?? '')->not->toBe('');
    });

    test('read without their labels: the location and the size as they are, the usage folded as counted texts, 0 av., 3 prims', function () {
        $columns = array_column(Region::listing()->document()['columns'], null, 'name');
        $simulators = array_column(W4OS\Engine\Models\Simulator::listing()->document()['columns'], null, 'name');
        $count = static fn(string $key, int $n, string $locale = 'en'): string => sprintf(W4OS\Engine\I18n\Catalog::plural($locale, $key, $n), $n);
        $usage = array_intersect_key($columns, ['avatars' => 1, 'prims' => 1, 'scripts' => 1]);

        expect(array_column($columns['place']['parts'], 'bare', 'name'))->toBe(['location' => true, 'size' => true])
            ->and(array_column($usage, 'bare', 'name'))->toBe(['avatars' => true, 'prims' => true, 'scripts' => true])
            ->and(array_column($usage, 'count', 'name'))->toBe(['avatars' => 'count_avatars_short', 'prims' => 'count_prims', 'scripts' => 'count_scripts'])
            ->and([$simulators['avatars']['count'], $simulators['prims']['count']])->toBe(['count_avatars_short', 'count_prims'])
            // A column whose parts all read alone reads alone
            ->and([$columns['place']['bare'], $columns['flagNames']['bare']])->toBe([true, true])
            ->and([$count('count_avatars_short', 0), $count('count_prims', 1), $count('count_prims', 3), $count('count_scripts', 2)])->toBe(['0 av.', '1 prim', '3 prims', '2 scripts'])
            ->and([$count('count_avatars_short', 2, 'fr_FR'), $count('count_scripts', 2, 'de_DE')])->toBe(['2 av.', '2 Skripte'])
            // An area reads alone too: 12 km²
            ->and($simulators['area']['bare'])->toBeTrue();
    });
});
