<?php

declare(strict_types=1);

/**
 * The addresses a grid advertised before (AddressHistory): a site answers them still, the viewers keep them until they read the
 * info of the grid again. Each pair of a service, the old address and the new one, and when it changed.
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Testing\Machine;

/** A grid at old.example.org:8002, its site at http://10.0.0.5:8830, advertising the pages of its site. */
function history_grid(): array
{
    $machine = new Machine();
    $grid = $machine->grid;
    $machine->write("$grid/Robust.HG.ini", str_replace("[Const]\n", "[Const]\n    BaseHostname = \"old.example.org\"\n    BaseURL = \"http://old.example.org\"\n    WebURL = \"http://10.0.0.5:8830\"\n", file_get_contents("$grid/Robust.HG.ini"))
        . "\n[GridInfoService]\n    login = \${Const|BaseURL}:\${Const|PublicPort}/\n    gridname = \"Demo\"\n"
        . "    register = \${Const|WebURL}/register\n    password = \"\${Const|WebURL}/forgot_password\"\n    about = \"https://elsewhere.example.org/about\"\n");
    $machine->write("$grid/setup.json", json_encode(['grid' => ['hostname' => 'old.example.org', 'web_url' => 'http://10.0.0.5:8830', 'public_port' => 8002]], JSON_PRETTY_PRINT));

    return [$machine, new Router(new Api($machine->environment))];
}

/** @return list<array<string,mixed>> the pairs the API gives of the demo grid */
function history_pairs(Router $router): array
{
    return $router->get('/grids/demo/addresses')->document['data']['attributes']['previous'];
}

describe('The addresses a grid advertised before', function () {
    test('are recorded when its address changes: the old one and the new one of each service that changed, and when', function () {
        [$machine, $router] = history_grid();
        $machine->clock->now = 1_800_000_000;

        $before = history_pairs($router);
        $router->post('/grids/demo/address', ['data' => ['type' => 'address', 'attributes' => ['host' => 'new.example.org', 'site' => 'https://site.example.org', 'mode' => 'apply']]]);
        $pairs = history_pairs($router);

        expect($before)->toBe([])
            ->and($pairs)->toBe([
                ['service' => 'login', 'old' => 'http://old.example.org:8002/', 'new' => 'http://new.example.org:8002/', 'date' => '2027-01-15T08:00:00Z', 'source' => 'change'],
                ['service' => 'register', 'old' => 'http://10.0.0.5:8830/register', 'new' => 'https://site.example.org/register', 'date' => '2027-01-15T08:00:00Z', 'source' => 'change'],
                ['service' => 'password', 'old' => 'http://10.0.0.5:8830/forgot_password', 'new' => 'https://site.example.org/forgot_password', 'date' => '2027-01-15T08:00:00Z', 'source' => 'change'],
            ])
            // Kept beside its config, the grid's own
            ->and(json_decode((string) file_get_contents("{$machine->grid}/previous-addresses.json"), true))->toBe(array_map(static fn(array $pair): array => array_diff_key($pair, ['source' => 1]), $pairs));
    });

    test('before it came to this install are the ones its import report kept or replaced, to the ones it advertises now', function () {
        [$machine, $router] = history_grid();
        $machine->write("{$machine->grid}/demo.import-report.txt", "Grid Demo (demo), imported from /old/install/Robust.HG.ini\n\n"
            . "4 setting(s) of the original kept in the new config:\n"
            . "  [GridInfoService] about = https://elsewhere.example.org/about\n"
            . "  [GridInfoService] register = https://old-site.example.org/wp-login.php?action=register\n"
            . "  [GridInfoService] password = ***\n"
            . "  [GridInfoService] help = \${Const|WebURL}/help (not a setting of the core)\n\n"
            . "3 setting(s) of the original not kept, with why (same: ...):\n"
            . "  [Const] BaseURL = http://old.example.org (layout)\n"
            . "  [Const] WebURL = https://old-site.example.org (layout)\n"
            . "  [GridInfoService] login = \${Const|BaseURL}:\${Const|PublicPort}/ (same)\n");

        expect(history_pairs($router))->toBe([
            // Nothing for what is the same now (about, the login: its port unknown), what the report masked (password), nor for a
            // service it does not advertise any more (help)
            ['service' => 'register', 'old' => 'https://old-site.example.org/wp-login.php?action=register', 'new' => 'http://10.0.0.5:8830/register', 'date' => null, 'source' => 'import'],
        ]);
    });

    test('are recorded when its settings change one: the search page of its site', function () {
        [$machine, $router] = history_grid();
        $search = static fn(string $url) => $router->patch('/grids/demo/settings/search', ['data' => ['type' => 'settings', 'id' => 'search', 'attributes' => ['webSearchUrl' => $url]]]);

        // A page it did not advertise has no old address
        $search('https://site.example.org/search');
        $first = history_pairs($router);
        $search('https://other.example.org/search');

        expect($first)->toBe([])
            ->and(array_map(static fn(array $pair): array => [$pair['service'], $pair['old'], $pair['new'], $pair['source']], history_pairs($router)))
            ->toBe([['search', 'https://site.example.org/search', 'https://other.example.org/search', 'change']]);
    });

    test('a change and the import together: each old address once', function () {
        [$machine, $router] = history_grid();
        $machine->write("{$machine->grid}/demo.import-report.txt", "  [Const] WebURL = http://10.0.0.5:8830 (layout)\n  [GridInfoService] register = \${Const|WebURL}/register\n");

        $router->post('/grids/demo/address', ['data' => ['type' => 'address', 'attributes' => ['site' => 'https://site.example.org', 'mode' => 'apply']]]);

        expect(array_column(history_pairs($router), 'old', 'service'))->toBe(['register' => 'http://10.0.0.5:8830/register', 'password' => 'http://10.0.0.5:8830/forgot_password']);
    });
});
