<?php

declare(strict_types=1);

/**
 * What a site needs of a grid for its helpers, from the engine of the machine that has its files (GET
 * /grids/{grid}/site-description): its addresses, the names of its databases, its destinations (the text of a local list), the
 * settings of its helpers; never a secret, the access to the databases being the site's own. The engine a site bundles makes its
 * grid of it (SiteDescription::grid()), never of a folder.
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Contract;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Config\Layout;
use W4OS\Engine\Models\SiteDescription;
use W4OS\Engine\Runtime\Environment;
use W4OS\Engine\Testing\Machine;

describe('The description of a grid for a site', function () {
    test('gives what its helpers need, never a secret', function () {
        $machine = new Machine();
        $machine->write($machine->grid . '/Robust.HG.ini', file_get_contents($machine->grid . '/Robust.HG.ini')
            . "\n[DatabaseService]\n    ConnectionString = \"Data Source=db.example.org;Database=robust;User ID=opensim;Password=db-pass;Old Guids=true;\"\n"
            . "[GridInfoService]\n    gridname = \"Demo\"\n    economy = \"https://play.example.org/helpers/\"\n[Network]\n    ConsolePass = console-pass\n");
        $machine->write($machine->grid . '/Helpers.ini', "[Helpers]\n    GuideSource = destinations.txt\n    CurrencyRate = 5\n    SearchConnectionString = \"Data Source=db.example.org;Database=search;User ID=search;Password=search-pass;\"\n");
        $machine->write($machine->grid . '/destinations.txt', "Welcome\nPlaza|play.example.org:8002/Plaza\n");
        $machine->write($machine->grid . '/MoneyServer.ini', "[MoneyServer]\n    MoneyScriptAccessKey = money-key\n[MySql]\n    hostname = db.example.org\n    database = money\n    username = money\n    password = money-pass\n");
        $router = new Router(new Api($machine->environment));

        $read = $router->get('/grids/demo/site-description');
        $text = json_encode($read->document);
        $attributes = $read->document['data']['attributes'] ?? [];

        expect($read->status)->toBe(200)
            ->and($attributes['grid'])->toMatchArray(['nick' => 'demo'])
            ->and($attributes['config']['Robust']['DatabaseService']['ConnectionString'])->toBe('Data Source=;Database=robust;')
            ->and($attributes['config']['Helpers']['Helpers'])->toMatchArray(['CurrencyRate' => '5', 'SearchConnectionString' => 'Data Source=;Database=search;'])
            ->and($attributes['config']['MoneyServer']['MySql'] ?? [])->toBe(['database' => 'money'])
            ->and($attributes['destinations'])->toBe("Welcome\nPlaza|play.example.org:8002/Plaza\n")
            ->and($attributes['checkedAt'])->toBeString()
            ->and($text)->not->toContain('db-pass')->not->toContain('search-pass')->not->toContain('money-pass')
            ->not->toContain('money-key')->not->toContain('console-pass')->not->toContain('db.example.org')
            ->and(Contract::document()['paths']['/grids/{grid}/site-description']['get']['x-access']['needs'] ?? [])->toBe(['files']);

        return $attributes;
    });

    test('makes the grid of the engine a site bundles, without a folder', function (array $attributes) {
        $site = test_tmp() . '/site-description-' . bin2hex(random_bytes(4));
        mkdir($site);
        $environment = Environment::system(new Layout(['EtcRoot' => "$site/etc", 'CacheRoot' => "$site/cache", 'StateRoot' => "$site/state"]));

        $grid = SiteDescription::grid($environment, $attributes);

        expect($grid->nick())->toBe('demo')
            ->and($grid->access())->not->toContain('files')
            ->and($grid->config()?->find('Helpers', 'CurrencyRate'))->toBe('5')
            ->and(array_keys($grid->destinations()))->toBe(['Welcome'])
            ->and($grid->destinations()['Welcome'][0]['name'] ?? null)->toBe('Plaza');
    })->depends('gives what its helpers need, never a secret');
});

/** The addresses of a grid a site uses. */
const ADDRESSES = ['helpersUrl', 'site', 'welcomeUrl', 'guideUrl', 'motdUrl', 'searchUrl', 'uri'];

describe('The grid a site makes of a description', function () {
    test('says of itself what the grid of the folder says: the same get_grid_info', function () {
        $machine = new Machine();
        $machine->write($machine->grid . '/Robust.HG.ini', file_get_contents($machine->grid . '/Robust.HG.ini')
            . "\n[GridInfoService]\n    gridname = \"Friendly Fowl\"\n    gridnick = \"demo\"\n    login = \"http://localhost:8002/\"\n    economy = \"https://play.example.org/helpers/\"\n    welcome = \"https://play.example.org/welcome\"\n    register = \"https://play.example.org/register\"\n");
        $folder = W4OS\Engine\Models\Grid::find($machine->environment, 'demo');
        $site = test_tmp() . '/site-description-info-' . bin2hex(random_bytes(4));
        mkdir($site);
        $environment = Environment::system(new Layout(['EtcRoot' => "$site/etc", 'CacheRoot' => "$site/cache", 'StateRoot' => "$site/state"]));

        $described = SiteDescription::grid($environment, SiteDescription::of($folder));

        expect($folder->gridInfo())->toMatchArray(['gridname' => 'Friendly Fowl', 'login' => 'http://localhost:8002/'])
            ->and($described->gridInfo())->toBe($folder->gridInfo())
            ->and($described->loginUrl())->toBe($folder->loginUrl())
            // Its addresses too, read in the config it was given
            ->and(array_map(static fn(string $method): mixed => $described->$method(), ADDRESSES))
            ->toBe(array_map(static fn(string $method): mixed => $folder->$method(), ADDRESSES));
    });
});

describe('The get_grid_info of a grid a site makes of a description', function () {
    test('is the one of Robust, element by element: platform and home added as Robust adds them, the password page kept', function () {
        $machine = new Machine();
        $machine->write($machine->grid . '/Robust.HG.ini', file_get_contents($machine->grid . '/Robust.HG.ini') . "\n" . file_get_contents(dirname(__DIR__) . '/fixtures/grid-info/Robust.ini.part'));
        $robust = [];
        foreach (simplexml_load_file(dirname(__DIR__) . '/fixtures/grid-info/answer.xml')->children() as $element) {
            $robust[$element->getName()] = (string) $element;
        }
        $site = test_tmp() . '/site-description-robust-' . bin2hex(random_bytes(4));
        mkdir($site);
        $environment = Environment::system(new Layout(['EtcRoot' => "$site/etc", 'CacheRoot' => "$site/cache", 'StateRoot' => "$site/state"]));

        $described = SiteDescription::grid($environment, SiteDescription::of(W4OS\Engine\Models\Grid::find($machine->environment, 'demo')));

        expect($described->gridInfo())->toBe($robust)
            ->and(W4OS\Engine\Models\Grid::find($machine->environment, 'demo')->gridInfo())->toBe($robust);
    });
});
