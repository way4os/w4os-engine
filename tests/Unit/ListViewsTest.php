<?php

declare(strict_types=1);

/**
 * The views of a list, the row of links above it in w4os-wp (All (n) | Default Model (n) | ...): the filters a site shows that way,
 * in their order, each value with its label, its count in `meta.counts`.
 */

use OpenSim\Api\Contract;
use OpenSim\I18n\Catalog;
use OpenSim\Models\Avatar;
use OpenSim\Models\Region;

describe('The views of a list', function () {
    test('are the ones of w4os-wp for the avatars: their type, active or not, in the world or not, in its words', function () {
        $views = Avatar::listing(true)->document()['views'];
        $en = Catalog::MESSAGES;
        $fr = Catalog::messages('fr_FR');
        $labels = static fn(array $texts): array => array_map(
            static fn(array $view): array => array_map(static fn(array $value): string => $texts[$value['label']] ?? $value['label'], $view['values']),
            $views['filters'],
        );

        expect($views['all'])
            ->toBe('view_all')
            ->and(array_column($views['filters'], 'filter'))
            ->toBe(['type', 'active', 'online'])
            ->and(array_map(static fn(array $view): array => array_column($view['values'], 'value'), $views['filters']))
            ->toBe([['model', 'service', 'user'], ['yes', 'no'], ['offline', 'online']])
            ->and([$en['view_all'], ...array_merge(...$labels($en))])
            ->toBe(['All', 'Default Model', 'Service Account', 'User Avatar', 'Active', 'Inactive', 'Offline', 'Online'])
            ->and([$fr['view_all'], ...$labels($fr)[0]])
            ->toBe(['Tout', 'Modèle par défaut', 'Compte de service', "Avatar de l'utilisateur"])
            // A list without the visits has no presence, nor its view
            ->and(array_column(Avatar::listing(false)->document()['views']['filters'], 'filter'))
            ->toBe(['type', 'active']);
    });

    test('are the size and the status for the regions, the sizes the ones the grid has', function () {
        $views = Region::listing()->document()['views']['filters'];

        expect(array_column($views, 'filter'))
            ->toBe(['size', 'status'])
            ->and($views[0])
            ->toBe(['filter' => 'size', 'dynamic' => true, 'values' => []])
            ->and(array_column($views[1]['values'], 'value'))
            ->toBe(['offline', 'online'])
            // In the contract, with the keys of the catalog
            ->and(Contract::document()['paths']['/grids/{grid}/regions']['get']['x-list']['views']['filters'][1]['filter'] ?? null)
            ->toBe('status')
            ->and(array_diff(Region::listing()->keys(), array_keys(Catalog::MESSAGES)))
            ->toBe([]);
    });
});
