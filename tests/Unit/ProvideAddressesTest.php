<?php

declare(strict_types=1);

/**
 * The address of a service is always changed on its page (provided_grid() of ProvideChoicesTest): off, the whole address; on, the
 * path under the site (the guide, the prefix of the economy: currency.php and landtool.php are fixed under it), or under the helpers
 * for what the viewers and the simulators call (the search, the registration, the offline messages); the web assets under the site,
 * a tool of their own. The address
 * of another search is kept apart, so that it is not lost when Provide is switched.
 */

/** The PATCH of a page of the demo grid. */
function address_patch(W4OS\Engine\Api\Router $router, string $page, array $attributes): W4OS\Engine\Api\Response
{
    return $router->patch("/grids/demo/settings/$page", ['data' => ['type' => 'settings', 'id' => $page, 'attributes' => $attributes]]);
}

describe('The address of a service', function () {
    test('on, is a path under the site, or under the helpers for what the viewers and the simulators call', function () {
        [$machine, $router] = provided_grid();
        $bases = array_map(static fn(string $page): ?string => provided_fields($page)['path']['base'] ?? null, PROVIDED_PAGES);

        $search = address_patch($router, 'search', ['provide' => true, 'path' => 'search/query.php']);
        $economy = address_patch($router, 'economy', ['provide' => true, 'path' => '/money/']);

        expect($bases)->toBe(['site', 'helpers', 'site', 'helpers', 'site'])
            ->and($search->document['data']['attributes']['path'])->toBe('search/query.php')
            ->and(file_get_contents($machine->grid . '/OpenSim.ini'))->toContain('SearchURL = "https://play.example.org/helpers/search/query.php"')
            ->and($economy->document['data']['attributes']['path'])->toBe('money/')
            ->and(file_get_contents($machine->grid . '/Robust.HG.ini'))->toContain('${Const|WebURL}/money/');
    });

    test('of another search engine is kept apart, 2do.directory by default, and not lost when Provide is switched', function () {
        [$machine, $router] = provided_grid();
        $fields = provided_fields('search');

        $default = $router->get('/grids/demo/settings/search')->document['data']['attributes'];
        $off = address_patch($router, 'search', ['provide' => false, 'searchExternalUrl' => 'https://search.example.org/query.php', 'registers' => ['https://search.example.org/register.php']]);
        $offIni = file_get_contents($machine->grid . '/OpenSim.ini');
        $on = address_patch($router, 'search', ['provide' => true, 'registers' => ['http://2do.directory/helpers/register.php', 'https://search.example.org/register.php']]);
        $onIni = file_get_contents($machine->grid . '/OpenSim.ini');

        expect([$fields['searchExternalUrl']['condition'], $fields['registerPath']['condition']])->toBe([['provide' => [false]], ['provide' => [true]]])
            ->and($fields['registers'])->toMatchArray(['type' => 'url', 'multiple' => true])
            ->and($fields['registers'])->not->toHaveKey('condition')
            ->and($fields['registerPath']['base'])->toBe('helpers')
            ->and(array_keys($fields))->not->toContain('searchUrl')->not->toContain('dataServices')->not->toContain('registerExternalUrl')
            // 2do.directory, recommended, by its box; the list the other search engines
            ->and([$default['searchExternalUrl'], $default['include2do'], $default['registers']])->toBe(['http://2do.directory/helpers/query.php', true, []])
            ->and($off->status)->toBe(200)
            ->and($offIni)->toContain('SearchURL = "https://search.example.org/query.php"')->toContain('DATA_SRV_SearchExampleOrg = "https://search.example.org/register.php"')
            ->and($on->document['data']['attributes'])->toMatchArray(['provide' => true, 'searchExternalUrl' => 'https://search.example.org/query.php'])
            ->and([$on->document['data']['attributes']['include2do'], $on->document['data']['attributes']['registers']])->toBe([true, ['https://search.example.org/register.php']])
            ->and($onIni)->toContain('SearchURL = "https://play.example.org/helpers/query')->toContain('= "https://play.example.org/helpers/register')
            ->and($onIni)->toContain('DATA_SRV_2do = "http://2do.directory/helpers/register.php"');
    });

    test('of the web search is the page people search in, apart from what the simulators call, given at login and by get_grid_info', function () {
        [$machine, $router] = provided_grid();
        $fields = provided_fields('search');

        $done = address_patch($router, 'search', ['webSearchUrl' => 'https://play.example.org/search/']);
        $robust = file_get_contents($machine->grid . '/Robust.HG.ini');

        expect($fields['webSearchUrl'])->toMatchArray(['label' => 'web_search_url', 'tip' => 'tip_web_search', 'description' => ['web_search_help']])
            ->and(W4OS\Engine\I18n\Catalog::MESSAGES[$fields['path']['tip']])->toBe('Used directly by the simulators')
            ->and(W4OS\Engine\I18n\Catalog::MESSAGES['tip_web_search'])->toBe('User-facing search form')
            ->and($done->status)->toBe(200)
            ->and($robust)->toContain('[LoginService]')->toContain('SearchURL = "${Const|WebURL}/search/"')->toContain('search = "${Const|WebURL}/search/"');
    });

    test('shows the lines of the files for what is chosen, once', function () {
        [, $router] = provided_grid();

        $snippets = $router->get('/grids/demo/settings/search')->document['meta']['snippets'] ?? [];

        $opensim = array_column($snippets, 'text', 'file')['OpenSim.ini'] ?? '';

        expect(array_count_values(array_column($snippets, 'file'))['OpenSim.ini'] ?? 0)->toBe(1)
            ->and(substr_count($opensim, 'SearchURL'))->toBe(1)
            // Its own register once, and 2do.directory's
            ->and(substr_count($opensim, 'DATA_SRV_Demo'))->toBe(1)
            ->and(substr_count($opensim, 'DATA_SRV_2do'))->toBe(1);
    });
});

describe('The path of a service', function () {
    test('never starts with a slash: the slash ends its base, the part that is not changed (meta.service.prefix)', function () {
        [, $router] = provided_grid();
        $read = static fn(string $page): array => $router->get("/grids/demo/settings/$page")->document;

        $paths = array_map(static fn(string $page): string => (string) $read($page)['data']['attributes']['path'], PROVIDED_PAGES);
        $prefixes = array_map(static fn(string $page): string => (string) ($read($page)['meta']['service']['prefix'] ?? ''), PROVIDED_PAGES);
        $saved = address_patch($router, 'guide', ['provide' => true, 'path' => '/places/guide'])->document['data']['attributes']['path'];

        expect(array_filter($paths, static fn(string $path): bool => str_starts_with($path, '/')))->toBe([])
            ->and($paths[0])->toBe('helpers/')
            ->and($prefixes)->toBe(['https://play.example.org/', 'https://play.example.org/helpers/', 'https://play.example.org/', 'https://play.example.org/helpers/', 'https://play.example.org/'])
            ->and($saved)->toBe('places/guide');
    });
});
