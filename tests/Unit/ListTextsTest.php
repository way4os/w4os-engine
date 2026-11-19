<?php

declare(strict_types=1);

/**
 * The texts of a list, as keys of the catalog the bridges translate (`x-list`): its title, its label in a menu, the placeholder of its
 * search, what it says when it is empty, how it counts its rows. The words of w4os-wp where it has them: the avatars, the regions,
 * the posts of Flux.
 */

use OpenSim\Api\Contract;
use OpenSim\I18n\Catalog;

/** @return array<string,mixed> the x-list of a list of a grid */
function list_of(string $type): array
{
    return Contract::document()['paths']["/grids/{grid}/$type"]['get']['x-list'];
}

describe('The texts of a list', function () {
    test('are the words of w4os-wp: the avatars, the regions, the posts of Flux', function () {
        $texts = static fn(string $type): array => array_map(
            static fn(string $key): string => Catalog::MESSAGES[$key],
            array_intersect_key(list_of($type), array_flip(['title', 'menu', 'placeholder', 'empty'])),
        );

        expect($texts('avatars'))->toBe(['title' => 'Avatars', 'menu' => 'Avatars', 'placeholder' => 'Search Avatars', 'empty' => 'No items found.'])
            ->and($texts('regions'))->toBe(['title' => 'Regions', 'menu' => 'Regions', 'placeholder' => 'Search Regions', 'empty' => 'No items found.'])
            ->and($texts('posts'))->toBe(['title' => 'Flux Posts', 'menu' => 'Flux', 'placeholder' => 'Search Flux Posts', 'empty' => 'No Flux Posts found'])
            ->and(Catalog::messages('fr_FR')[list_of('posts')['placeholder']])->toBe('Rechercher les publications Flux');
    });

    test('count the rows in the form of their number, in every language', function () {
        $count = static fn(string $type, string $locale, int $n): string => Catalog::plural($locale, list_of($type)['count'], $n);

        expect([$count('avatars', 'en', 1), $count('avatars', 'en', 3), $count('regions', 'fr_FR', 2), $count('posts', 'en', 1), $count('posts', 'fr_FR', 2)])
            ->toBe(['%d avatar', '%d avatars', '%d régions', '%d post', '%d publications']);
    });
});
