<?php

declare(strict_types=1);

/**
 * The columns of the lists: the regions in the words and the order of w4os-wp (wordpress/classes/class-w4os3-region.php,
 * admin_columns), within the rules of the new interface (the address under the name, the status last in one column); and a column
 * that repeats the filter of a parent (the region of the parcels of a region) says so, for a site to leave it out when that filter is
 * fixed.
 */

use OpenSim\Api\Contract;
use OpenSim\I18n\Catalog;
use OpenSim\Models\Avatar;
use OpenSim\Models\Parcel;
use OpenSim\Models\Region;

describe('The columns of the regions', function () {
    test('are the ones of w4os-wp in its order and its words, what the region holds after its presence, the address under the name and the status last', function () {
        $columns = Region::listing()->document()['columns'];
        $byName = array_column($columns, null, 'name');

        expect(array_column($columns, 'name'))
            ->toBe(['name', 'ownerName', 'serverName', 'usage', 'place', 'lastSeen', 'flagNames', 'status'])
            ->and(array_map(static fn(array $column): string => Catalog::MESSAGES[$column['label']], $columns))
            ->toBe(['Region Name', 'Owner', 'Simulator', 'Usage', 'Location', 'Last Activity', 'Flags', 'Status'])
            ->and(array_map(static fn(array $column): string => Catalog::messages('fr_FR')[$column['label']], [$byName['name'], $byName['place'], $byName['lastSeen']]))
            ->toBe(['Nom de la région', 'Lieu', 'Dernière activité'])
            ->and(array_intersect_key($byName['name'], ['subtitle' => 1, 'subtitleLink' => 1]))
            ->toBe(['subtitle' => ['uri'], 'subtitleLink' => 'teleport'])
            // The control of w4os-wp (enabled, its button) and online, in the one column of the status
            ->and(array_column($byName['status']['indicators'], 'name'))
            ->toBe(['enabled', 'online']);
    });
});

describe('A column that repeats a filter', function () {
    test('says which (mirrors): the region of the parcels, the owner and the simulator of the regions', function () {
        $mirrors = static fn(array $columns): array => array_filter(array_column($columns, 'mirrors', 'name'));

        expect($mirrors(Parcel::listing()->document()['columns']))
            ->toBe(['regionName' => 'region', 'ownerName' => 'owner'])
            ->and($mirrors(Region::listing()->document()['columns']))
            ->toBe(['ownerName' => 'owner', 'serverName' => 'simulator'])
            ->and($mirrors(Avatar::listing()->document()['columns']))
            ->toBe([])
            // Each names a filter of its list
            ->and(array_diff(
                $mirrors(Parcel::listing()->document()['columns']),
                array_column(Parcel::listing()->filters, 'name'),
            ))
            ->toBe([])
            ->and(Contract::document()['paths']['/grids/{grid}/parcels']['get']['x-list']['columns'][1]['mirrors'] ?? null)
            ->toBe('region');
    });

    test('shows the picture of a parcel beside its name', function () {
        expect(Parcel::listing()->document()['columns'][0])->toMatchArray(['picture' => 'snapshot', 'default' => 'place']);
    });
});
