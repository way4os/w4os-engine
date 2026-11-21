<?php

declare(strict_types=1);

/**
 * What a site says when something is not there: a neutral text for the code not-found, whatever is missing, and the text of the
 * resource the problem names (`resource`: an avatar, a region, a grid...) when the catalog has one, in the words of w4os-wp.
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Problem;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Connections;
use W4OS\Engine\Exceptions\NotFound;
use W4OS\Engine\I18n\Catalog;

afterEach(fn() => Connections::using(null));

describe('The text of what is not there', function () {
    test('is neutral for any resource, and names the resource the problem names when the catalog has its text', function () {
        expect(Catalog::MESSAGES['error_not_found'])
            ->toBe('Not found')
            ->and([
                Catalog::errorKey('not-found'),
                Catalog::errorKey('not-found', 'avatars'),
                Catalog::errorKey('not-found', 'regions'),
                Catalog::errorKey('not-found', 'avatar-models'),
                Catalog::errorKey('not-found', 'tasks'),
                Catalog::errorKey('name-taken', 'avatars'),
            ])
            ->toBe([
                'error_not_found',
                'error_not_found_avatar',
                'error_not_found_region',
                'error_not_found_avatar_model',
                'error_not_found',
                'error_name_taken',
            ])
            // The words of w4os-wp
            ->and([Catalog::MESSAGES['error_not_found_avatar'], Catalog::messages('fr_FR')['error_not_found_avatar']])
            ->toBe(['Avatar not found', 'Avatar inexistant']);
    });

    test('is translated in every language', function () {
        $keys = array_values(array_filter(array_keys(Catalog::MESSAGES), static fn(string $key): bool => str_starts_with($key, 'error_not_found')));

        expect(count($keys))->toBeGreaterThan(8);
        foreach (Catalog::LOCALES as $language) {
            $texts = Catalog::messages($language);
            expect(array_filter($keys, static fn(string $key): bool => ($texts[$key] ?? '') === Catalog::MESSAGES[$key]))->toBe([]);
        }
    });
});

describe('A problem of something not there', function () {
    test('names the resource that is not there', function () {
        expect(Problem::from(NotFound::for("grid called 'x'", 'grids'))->toArray())
            ->toMatchArray(['status' => 404, 'code' => 'not-found', 'resource' => 'grids'])
            ->and(Problem::notFound("avatar called 'x'", null, 'avatars')->toArray()['resource'])
            ->toBe('avatars')
            ->and(Problem::notFound('address')->toArray())
            ->not->toHaveKey('resource');
    });

    test('through the API: the grid, the avatar, none for a kind of resource that is not one', function () {
        avatars_in(avatar_database());
        $machine = new W4OS\Engine\Testing\Machine();
        $config = avatar_grid();
        $router = new Router(new Api($machine->environment, config: static fn(): GridConfig => $config));
        $resource = static fn(string $path): ?string => $router->get($path)->document['resource'] ?? null;

        expect([
            $resource('/grids/nowhere/avatars/Ada.Love'),
            $resource('/grids/demo/avatars/No.Body'),
            $resource('/grids/demo/avatars/No.Body/picks'),
            $resource('/grids/demo/nothings/x'),
        ])->toBe(['grids', 'avatars', 'avatars', null]);
    });
});
