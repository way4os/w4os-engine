<?php

declare(strict_types=1);

/**
 * Where a setting a page changes goes, at the best of what this process can do: the file of the grid when it can write it (checked,
 * not assumed), else the settings the application keeps, but only for what the helpers and the site read (`readers` helpers); what
 * Robust or the simulators read stays in their files: read-only here, with its reason and the lines to add by hand (`meta.fixed`).
 */

use OpenSim\Api\Api;
use OpenSim\Api\Router;
use OpenSim\Config\ArraySettings;
use OpenSim\Config\Settings;
use OpenSim\Models\Grid;
use OpenSim\Testing\Machine;

/** @return array{0:Router,1:ArraySettings,2:Machine} the API of the grid demo, the files of its folder read only, a store of the site */
function readonly_grid(): array
{
    $machine = new Machine();
    $machine->write($machine->grid . '/Robust.HG.ini', file_get_contents($machine->grid . '/Robust.HG.ini') . "\n[LoginService]\n    DestinationGuide = \"https://play.example.org/guide\"\n");
    $machine->write($machine->grid . '/Helpers.ini', "[Helpers]\n    CurrencyRate = 5\n");
    foreach (glob($machine->grid . '/*.ini') as $file) {
        chmod($file, 0o444);
    }
    chmod($machine->grid, 0o555);
    register_shutdown_function(static fn() => @chmod($machine->grid, 0o755));
    $store = new ArraySettings();

    return [new Router(new Api($machine->environment->withSettings($store))), $store, $machine];
}

/** @return OpenSim\Api\Response the PATCH of a page of settings */
function patch_page(Router $router, string $grid, string $page, array $attributes): OpenSim\Api\Response
{
    return $router->patch("/grids/$grid/settings/$page", ['data' => ['type' => 'settings', 'id' => $page, 'attributes' => $attributes]]);
}

describe('A setting of a grid whose files this process cannot write', function () {
    test('read by the helpers only, is kept by the application, the files untouched', function () {
        [$router, $store, $machine] = readonly_grid();
        $before = file_get_contents($machine->grid . '/Helpers.ini');

        $done = patch_page($router, 'demo', 'economy', ['currencyRate' => '7', 'moneyScriptKey' => 'k3y']);
        chmod($machine->grid, 0o755);

        expect($done->status)->toBe(200)
            ->and($store->all())->toBe(['Helpers' => ['CurrencyRate' => '7'], 'MoneyServer' => ['MoneyScriptAccessKey' => 'k3y']])
            // The application's is read first
            ->and($done->document['data']['attributes']['currencyRate'])->toBe(7.0)
            ->and(file_get_contents($machine->grid . '/Helpers.ini'))->toBe($before);
    })->skip(fn() => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes everywhere');

    test('read by Robust or the simulators, is read-only here: why, and the lines to add by hand', function () {
        [$router, $store, $machine] = readonly_grid();

        $refused = patch_page($router, 'demo', 'guide', ['provide' => false, 'destinationGuide' => 'https://elsewhere.example.org/guide']);
        $same = patch_page($router, 'demo', 'guide', ['provide' => false, 'destinationGuide' => 'https://play.example.org/guide']);
        $fixed = $router->get('/grids/demo/settings/guide')->document['meta']['fixed'] ?? [];
        $economy = $router->get('/grids/demo/settings/economy')->document['meta']['fixed'] ?? [];
        chmod($machine->grid, 0o755);

        expect([$refused->status, $refused->document['code'] ?? null, $same->status])->toBe([503, 'channel-unavailable', 200])
            ->and($refused->document['detail'])->toContain('Robust.HG.ini')->toContain('by hand')
            ->and($store->all())->toBe([])
            ->and(array_keys($fixed))->toBe(['path', 'destinationGuide'])
            ->and($fixed['destinationGuide'])->toMatchArray(['file' => 'Robust.HG.ini', 'text' => "[LoginService]\n    DestinationGuide = https://play.example.org/guide"])
            ->and($fixed['destinationGuide']['reason'])->toContain('cannot write')
            // What the helpers read is not fixed: the application keeps it
            ->and(array_keys($economy))->not->toContain('currencyRate')->toContain('economyUrl');
    })->skip(fn() => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes everywhere');
});

describe('A setting of a grid that has no files here', function () {
    test('read by the helpers only, is kept by the application; read by the grid, read-only', function () {
        $store = new ArraySettings();
        $environment = (new Machine())->environment->withSettings($store);
        $far = Grid::described($environment, ['nick' => 'far', 'name' => 'Far', 'remote' => true]);
        $router = new Router(new Api($environment, grids: static fn(string $nick): ?Grid => $nick === 'far' ? $far : null));

        $rate = patch_page($router, 'far', 'economy', ['currencyRate' => '7']);
        $address = patch_page($router, 'far', 'economy', ['provide' => false, 'economyUrl' => 'https://shop.example.org/']);
        $fixed = $router->get('/grids/far/settings/economy')->document['meta']['fixed'] ?? [];

        expect([$rate->status, $address->status])->toBe([200, 503])
            ->and($store->all())->toBe(['Helpers' => ['CurrencyRate' => '7']])
            ->and($address->document['detail'])->toContain('no files')
            ->and($fixed['economyUrl']['reason'] ?? '')->toContain('no files')
            ->and(Settings::named('moneyScriptKey')['readers'] ?? null)->toBe('helpers')
            ->and(Settings::named('economyUrl')['readers'] ?? null)->toBe('grid');
    });
});
