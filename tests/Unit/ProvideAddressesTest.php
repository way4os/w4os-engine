<?php

declare(strict_types=1);

/**
 * The address of a service is always changed on its page (provided_grid() of ProvideChoicesTest): off, the whole address; on, the
 * path under the site (the guide, the prefix of the economy: currency.php and landtool.php are fixed under it), or under the helpers
 * for what the viewers and the simulators call (the search, the registration, the offline messages, the web assets). The address
 * of another search is kept apart, so that it is not lost when Provide is switched.
 */

/** The PATCH of a page of the demo grid. */
function address_patch(OpenSim\Api\Router $router, string $page, array $attributes): OpenSim\Api\Response
{
    return $router->patch("/grids/demo/settings/$page", ['data' => ['type' => 'settings', 'id' => $page, 'attributes' => $attributes]]);
}

describe('The address of a service', function () {
    test('on, is a path under the site, or under the helpers for what the viewers and the simulators call', function () {
        [$machine, $router] = provided_grid();
        $bases = array_map(static fn(string $page): ?string => provided_fields($page)['path']['base'] ?? null, PROVIDED_PAGES);

        $search = address_patch($router, 'search', ['provide' => true, 'path' => 'search/query.php']);
        $economy = address_patch($router, 'economy', ['provide' => true, 'path' => '/money/']);

        expect($bases)->toBe(['site', 'helpers', 'site', 'helpers', 'helpers'])
            ->and($search->document['data']['attributes']['path'])->toBe('search/query.php')
            ->and(file_get_contents($machine->grid . '/OpenSim.ini'))->toContain('SearchURL = "https://play.example.org/helpers/search/query.php"')
            ->and($economy->document['data']['attributes']['path'])->toBe('/money/')
            ->and(file_get_contents($machine->grid . '/Robust.HG.ini'))->toContain('${Const|WebURL}/money/');
    });

    test('of another search engine is kept apart, 2do.directory by default, and not lost when Provide is switched', function () {
        [$machine, $router] = provided_grid();
        $fields = provided_fields('search');

        $default = $router->get('/grids/demo/settings/search')->document['data']['attributes'];
        $off = address_patch($router, 'search', ['provide' => false, 'searchExternalUrl' => 'https://search.example.org/query.php', 'registerExternalUrl' => 'https://search.example.org/register.php']);
        $offIni = file_get_contents($machine->grid . '/OpenSim.ini');
        $on = address_patch($router, 'search', ['provide' => true]);
        $onIni = file_get_contents($machine->grid . '/OpenSim.ini');

        expect([$fields['searchExternalUrl']['condition'], $fields['registerExternalUrl']['condition'], $fields['registerPath']['condition']])
            ->toBe([['provide' => [false]], ['provide' => [false]], ['provide' => [true]]])
            ->and($fields['registerPath']['base'])->toBe('helpers')
            ->and(array_keys($fields))->not->toContain('searchUrl')->not->toContain('dataServices')
            ->and([$default['searchExternalUrl'], $default['registerExternalUrl']])->toBe(['http://2do.directory/helpers/query.php', 'http://2do.directory/helpers/register.php'])
            ->and($off->status)->toBe(200)
            ->and($offIni)->toContain('SearchURL = "https://search.example.org/query.php"')->toContain('DATA_SRV_2do = "https://search.example.org/register.php"')
            ->and($on->document['data']['attributes'])->toMatchArray(['provide' => true, 'searchExternalUrl' => 'https://search.example.org/query.php', 'registerExternalUrl' => 'https://search.example.org/register.php'])
            ->and($onIni)->toContain('SearchURL = "https://play.example.org/helpers/query')->toContain('= "https://play.example.org/helpers/register');
    });

    test('shows the lines of the files for what is chosen, once', function () {
        [, $router] = provided_grid();

        $snippets = $router->get('/grids/demo/settings/search')->document['meta']['snippets'] ?? [];

        expect(array_map(static fn(array $one): string => $one['text'], $snippets['path'] ?? []))->toHaveCount(1)
            ->and(substr_count($snippets['path'][0]['text'], 'SearchURL'))->toBe(1)
            ->and(substr_count($snippets['registerPath'][0]['text'] ?? '', 'DATA_SRV_'))->toBe(1);
    });
});
