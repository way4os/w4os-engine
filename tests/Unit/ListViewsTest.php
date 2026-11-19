<?php

declare(strict_types=1);

/**
 * The views of a list, the row of links above it in w4os-wp (All (n) | Default Model (n) | ...): the filters a site shows that way,
 * in their order, each value with the label of its filter and its count in `meta.counts`.
 */

use OpenSim\Api\Contract;
use OpenSim\I18n\Catalog;
use OpenSim\Models\Avatar;
use OpenSim\Models\Region;

describe('The views of a list', function () {
    test('are the ones of w4os-wp for the avatars, in its order and its words: the type, active, online', function () {
        $listing = Avatar::listing(true);
        $filters = array_column($listing->filters, null, 'name');
        $label = static fn(array $texts, string $filter): array => array_map(static fn(array $value): string => $texts[$value['label']], $filters[$filter]['values']);
        $en = Catalog::MESSAGES;
        $fr = Catalog::messages('fr_FR');

        expect($listing->document()['views'])
            ->toBe(['type', 'active', 'online'])
            ->and([$en['all'], ...$label($en, 'type'), ...$label($en, 'active'), ...$label($en, 'online')])
            ->toBe(['All', 'User Avatar', 'Default Model', 'Service Account', 'Active', 'Inactive', 'Online', 'Offline'])
            ->and([$fr['all'], ...$label($fr, 'type'), ...$label($fr, 'online')])
            ->toBe(['Tout', "Avatar de l'utilisateur", 'Modèle par défaut', 'Compte de service', 'En ligne', 'Hors ligne'])
            // A list without the visits has no presence, nor its view
            ->and(Avatar::listing(false)->document()['views'])
            ->toBe(['type', 'active'])
            ->and(array_diff($listing->keys(), array_keys($en)))
            ->toBe([]);
    });

    test('are the status and the size of the regions, in the contract', function () {
        expect(Region::listing()->document()['views'])
            ->toBe(['status', 'size'])
            ->and(Contract::document()['paths']['/grids/{grid}/regions']['get']['x-list']['views'] ?? null)
            ->toBe(['status', 'size'])
            ->and(Contract::document()['paths']['/grids/{grid}/avatars']['get']['x-list']['views'] ?? null)
            ->toBe(['type', 'active', 'online']);
    });

    test('count the user avatars only for active and online, as w4os-wp shows them', function () {
        avatars_in(avatar_database());
        $counts = Avatar::all(avatar_grid())->counts();
        $users = Avatar::all(avatar_grid(), ['filters' => ['type' => 'user']])->total();

        expect(($counts['active']['yes'] ?? 0) + ($counts['active']['no'] ?? 0))
            ->toBe($users)
            ->and(($counts['online']['online'] ?? 0) + ($counts['online']['offline'] ?? 0))
            ->toBe($users)
            ->and(array_sum($counts['type']))
            ->toBe(Avatar::all(avatar_grid())->total());
    });
});
