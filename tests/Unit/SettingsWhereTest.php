<?php

declare(strict_types=1);

/**
 * Where a setting a page changes goes, at the best of what this process can do: the settings the application keeps, and the file of
 * the grid when it can write it (checked, not assumed). What Robust or the simulators read in a file this process cannot write is
 * kept by the application, the page showing it, and its lines are given to apply by hand (`meta.byHand`); the health tells the
 * difference. Without settings kept by the application, such a change is unavailable, with its reason.
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

    test('read by Robust or the simulators, is kept by the application too, the lines to apply by hand given, the grid unchanged', function () {
        [$router, $store, $machine] = readonly_grid();
        $grid = OpenSim\Models\Grid::find($machine->environment->withSettings($store), 'demo');

        $done = patch_page($router, 'demo', 'guide', ['provide' => false, 'destinationGuide' => 'https://elsewhere.example.org/guide']);
        $byHand = $router->get('/grids/demo/settings/guide')->document['meta']['byHand'] ?? [];
        $economy = $router->get('/grids/demo/settings/economy')->document['meta']['byHand'] ?? [];
        $health = OpenSim\Health\Health::byHand((new OpenSim\Models\GridSettings($grid))->pending());
        chmod($machine->grid, 0o755);

        expect($done->status)->toBe(200)
            // The page shows what was chosen; the grid still reads its file
            ->and($done->document['data']['attributes']['destinationGuide'])->toBe('https://elsewhere.example.org/guide')
            ->and($store->all())->toBe(['LoginService' => ['DestinationGuide' => 'https://elsewhere.example.org/guide']])
            ->and($grid->config()->find('LoginService', 'DestinationGuide'))->toBe('https://play.example.org/guide')
            ->and(array_keys($byHand))->toBe(['path', 'destinationGuide'])
            ->and($byHand['destinationGuide'])->toMatchArray(['file' => 'Robust.HG.ini', 'text' => "[LoginService]\n    DestinationGuide = https://elsewhere.example.org/guide"])
            ->and($byHand['destinationGuide']['reason'])->toMatchArray(['code' => 'setting-file-unwritable', 'params' => ['file' => 'Robust.HG.ini']])
            ->and(OpenSim\I18n\Catalog::notice('fr_FR', $byHand['destinationGuide']['reason']['code'], $byHand['destinationGuide']['reason']['params']))
            ->toContain('Robust.HG.ini')->not->toBe($byHand['destinationGuide']['reason']['detail'])
            // Nothing to apply where the files say what was chosen, nor for what the helpers read in the settings of the application
            ->and($economy)->toBe([])
            // The health tells the difference
            ->and(array_column($health, 'label'))->toBe(['DestinationGuide'])
            ->and($health[0])->toMatchArray(['level' => 'warning', 'text' => 'https://elsewhere.example.org/guide']);
    })->skip(fn() => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes everywhere');

    test('without settings kept by the application, is unavailable, with its reason', function () {
        $machine = readonly_grid()[2];
        $router = new Router(new Api($machine->environment));

        $refused = patch_page($router, 'demo', 'guide', ['provide' => false, 'destinationGuide' => 'https://elsewhere.example.org/guide']);
        $same = patch_page($router, 'demo', 'guide', ['provide' => false, 'destinationGuide' => 'https://play.example.org/guide']);
        chmod($machine->grid, 0o755);

        expect([$refused->status, $refused->document['code'] ?? null, $same->status])->toBe([503, 'channel-unavailable', 200])
            ->and($refused->document['reason']['code'] ?? null)->toBe('setting-file-unwritable-unkept');
    })->skip(fn() => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes everywhere');
});

describe('A setting of a grid that has no files here', function () {
    test('is kept by the application, the lines to apply by hand given for what the grid reads', function () {
        $store = new ArraySettings();
        $environment = (new Machine())->environment->withSettings($store);
        $far = Grid::described($environment, ['nick' => 'far', 'name' => 'Far', 'remote' => true]);
        $router = new Router(new Api($environment, grids: static fn(string $nick): ?Grid => $nick === 'far' ? $far : null));

        $rate = patch_page($router, 'far', 'economy', ['currencyRate' => '7']);
        $address = patch_page($router, 'far', 'economy', ['provide' => false, 'economyUrl' => 'https://shop.example.org/']);
        $fixed = $router->get('/grids/far/settings/economy')->document['meta']['byHand'] ?? [];

        expect([$rate->status, $address->status])->toBe([200, 200])
            ->and($store->all())->toBe(['Helpers' => ['CurrencyRate' => '7'], 'GridInfoService' => ['economy' => 'https://shop.example.org/']])
            ->and($fixed['economyUrl']['reason']['code'] ?? null)->toBe('setting-no-files')
            ->and($fixed['economyUrl']['file'] ?? null)->toBe('Robust.ini')
            ->and(Settings::named('moneyScriptKey')['readers'] ?? null)->toBe('helpers')
            ->and(Settings::named('economyUrl')['readers'] ?? null)->toBe('grid');
    });
});
