<?php

declare(strict_types=1);

/**
 * A column that repeats the filter of a parent (the region of the parcels of a region) says so, for a site to leave it out when that
 * filter is fixed.
 */

use OpenSim\Api\Contract;
use OpenSim\Models\Avatar;
use OpenSim\Models\Parcel;
use OpenSim\Models\Region;

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
