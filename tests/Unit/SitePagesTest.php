<?php

declare(strict_types=1);

/**
 * Who serves each page of a grid, the helpers or the site: a page whose address is under the base of the helpers is theirs, any
 * other is the site's. The base is HelpersURL of Helpers.ini, else the economy, else WebURL/helpers.
 */

use OpenSim\Config\IniEditor;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\Web\Services;
use OpenSim\Provisioning\Web\SitePages;
use OpenSim\Testing\Machine;

/**
 * The grid of a test machine with a web site, what its Robust says of its addresses and what its Helpers.ini says.
 *
 * @return array{0:Machine,1:\Closure():Grid}
 */
function pages_grid(string $gridInfo = '', string $helpers = ''): array
{
    $machine = new Machine();
    $robust = (string) file_get_contents($machine->grid . '/Robust.HG.ini');
    $robust = str_replace("[Const]\n", "[Const]\n    WebURL = \"https://demo.example.org\"\n", $robust);
    $machine->write($machine->grid . '/Robust.HG.ini', $robust . ($gridInfo === '' ? '' : "\n$gridInfo\n"));
    $helpers === '' || $machine->write($machine->grid . '/Helpers.ini', "[Helpers]\n$helpers\n");

    return [$machine, static fn(): Grid => Grid::find($machine->environment, 'demo')];
}

describe('The base of the helpers', function () {
    test('is HelpersURL of Helpers.ini, else the economy, else WebURL/helpers, and says where it comes from', function () {
        $base = static function (string $gridInfo, string $helpers): array {
            [, $grid] = pages_grid($gridInfo, $helpers);

            return [$grid()->helpersUrl(), $grid()->helpersUrlSource()];
        };

        expect($base('', ''))
            ->toBe(['https://demo.example.org/helpers', 'default'])
            ->and($base("[GridInfoService]\n    economy = \"https://demo.example.org/shop\"", ''))
            ->toBe(['https://demo.example.org/shop', 'economy'])
            ->and($base("[GridInfoService]\n    economy = \"https://demo.example.org/shop\"", 'HelpersURL = "https://demo.example.org/helperz/"'))
            ->toBe(['https://demo.example.org/helperz', 'config'])
            ->and($base('', 'HelpersURL = "https://demo.example.org/"'))
            ->toBe(['https://demo.example.org', 'config'])
            ->and($base("[GridInfoService]\n    economy = \"https://demo.example.org/\"", ''))
            ->toBe(['https://demo.example.org', 'economy'])
            ->and($base('', 'HelpersURL = "https://demo.example.org"'))
            ->toBe(['https://demo.example.org', 'config']);
    });

    test('is the one place that says whether an address is served by the helpers', function () {
        [, $grid] = pages_grid('', 'HelpersURL = "https://demo.example.org/helperz"');
        [, $root] = pages_grid('', 'HelpersURL = "https://demo.example.org/"');
        $served = static fn(Grid $grid, array $urls): array => array_map($grid->servedByHelpers(...), $urls);

        expect($served($grid(), [
            'https://demo.example.org/helperz/search',
            'https://demo.example.org/helperz',
            'https://DEMO.example.org/helperz/profile/[AGENT_NAME]',
            'https://demo.example.org/helperzz/search',
            'https://demo.example.org/helpers/search',
            'https://demo.example.org/search',
            'https://other.example.org/helperz/search',
            '',
        ]))
            ->toBe([true, true, true, false, false, false, false, false])
            ->and($served($root(), ['https://demo.example.org/search', 'https://demo.example.org/', 'https://other.example.org/search']))
            ->toBe([true, true, false])
            ->and(Services::isUnder('http://a.org:8080/h/x', 'http://a.org:8080/h'))
            ->toBeTrue()
            ->and(Services::isUnder('http://a.org/h/x', 'http://a.org:8080/h'))
            ->toBeFalse();
    });
});

describe('The pages of a grid', function () {
    test('are under the base of the helpers by default, at the root when the base is the site', function () {
        [, $grid] = pages_grid('', 'HelpersURL = "https://demo.example.org/helperz"');
        [, $root] = pages_grid('', 'HelpersURL = "https://demo.example.org/"');
        $addresses = static fn(Grid $grid): array => array_map(
            static fn(string $key): ?string => $grid->address($key),
            ['search' => 'search', 'welcome' => 'welcome', 'guide' => 'guide', 'status' => 'grid-status', 'directory' => 'directory'],
        );

        expect($addresses($grid()))
            ->toBe([
                'search' => 'https://demo.example.org/helperz/search',
                'welcome' => 'https://demo.example.org/helperz/welcome',
                'guide' => 'https://demo.example.org/helperz/guide',
                'status' => 'https://demo.example.org/helperz/grid_status',
                'directory' => 'https://demo.example.org/helperz/directory_info',
            ])
            ->and($addresses($root()))
            ->toBe([
                'search' => 'https://demo.example.org/search',
                'welcome' => 'https://demo.example.org/welcome',
                'guide' => 'https://demo.example.org/guide',
                'status' => 'https://demo.example.org/grid_status',
                'directory' => 'https://demo.example.org/directory_info',
            ])
            ->and((new Services('/helperz'))->path('currency'))
            ->toBe('/helperz/currency.php');
    });

    test('follow the base of the helpers when the grid gives no web site of its own', function () {
        $machine = new Machine();
        $machine->write($machine->grid . '/Helpers.ini', "[Helpers]\nHelpersURL = \"https://site.example.org:8443/helperz\"\n");
        $grid = Grid::find($machine->environment, 'demo');

        expect([$grid->helpersUrl(), $grid->address('guide'), $grid->helperUrl('currency')])
            ->toBe([
                'https://site.example.org:8443/helperz',
                'https://site.example.org:8443/helperz/guide',
                'https://site.example.org:8443/helperz/currency.php',
            ])
            ->and(Services::originOf('https://site.example.org:8443/helperz/x'))
            ->toBe('https://site.example.org:8443');
    });

    test('are listed with who serves each: the helpers under their base, the site elsewhere', function () {
        [, $grid] = pages_grid(
            "[GridInfoService]\n    search = \"https://demo.example.org/search\"\n    web_profile_url = \"https://demo.example.org/helpers/profile/[AGENT_NAME]\"",
        );
        $pages = array_column(SitePages::rows($grid()), null, 'page');

        expect(array_keys($pages))
            ->toBe(['helpers', 'search', 'profile', 'guide', 'welcome', 'status', 'directory'])
            ->and($pages['helpers'])
            ->toMatchArray(['url' => 'https://demo.example.org/helpers', 'source' => 'default'])
            ->and($pages['search'])
            ->toMatchArray(['url' => 'https://demo.example.org/search', 'source' => 'config', 'servedBy' => 'site'])
            ->and($pages['profile'])
            ->toMatchArray([
                'url' => 'https://demo.example.org/helpers/profile/[AGENT_NAME]',
                'source' => 'config',
                'servedBy' => 'helpers',
            ])
            ->and($pages['guide'])
            ->toMatchArray(['url' => 'https://demo.example.org/helpers/guide', 'source' => 'default', 'servedBy' => 'helpers']);
    });

    test('are set one at a time, in the one key of each, and say what must restart', function () {
        [$machine, $grid] = pages_grid("[LoginService]\n    SearchURL = \"https://demo.example.org/helpers/search\"");
        $robust = $machine->grid . '/Robust.HG.ini';

        $search = SitePages::set($grid(), 'search', 'https://site.example.org/search');
        $profile = SitePages::set($grid(), 'profile', 'https://site.example.org/profile');
        $guide = SitePages::set($grid(), 'guide', 'https://site.example.org/guide');
        $directory = SitePages::set($grid(), 'directory', 'https://site.example.org/directory');
        $helpers = SitePages::set($grid(), 'helpers', 'https://demo.example.org/helperz/');
        $ini = IniEditor::load($robust);
        $refused = static function (string $page, string $url) use ($grid): ?string {
            try {
                SitePages::set($grid(), $page, $url);
            } catch (\InvalidArgumentException $e) {
                return $e->getMessage();
            }

            return null;
        };

        expect([$ini->get('GridInfoService', 'search'), $ini->get('LoginService', 'SearchURL')])
            ->toBe(['https://site.example.org/search', 'https://site.example.org/search'])
            ->and($search)
            ->toMatchArray(['file' => $robust, 'keys' => ['[GridInfoService] search', '[LoginService] SearchURL'], 'restart' => 'Robust'])
            ->and($ini->get('GridInfoService', 'web_profile_url'))
            ->toBe('https://site.example.org/profile/[AGENT_NAME]')
            ->and($profile['keys'])
            ->toBe(['[GridInfoService] web_profile_url'])
            ->and($ini->get('LoginService', 'DestinationGuide'))
            ->toBe('https://site.example.org/guide')
            ->and($guide['keys'])
            ->toBe(['[LoginService] DestinationGuide'])
            ->and($directory)
            ->toMatchArray(['file' => $machine->grid . '/Helpers.ini', 'keys' => ['[Helpers] DirectoryURL'], 'restart' => null])
            ->and($helpers)
            ->toMatchArray(['file' => $machine->grid . '/Helpers.ini', 'keys' => ['[Helpers] HelpersURL'], 'restart' => null])
            ->and([$grid()->helpersUrl(), $grid()->address('directory'), $grid()->address('welcome')])
            ->toBe(['https://demo.example.org/helperz', 'https://site.example.org/directory', 'https://demo.example.org/helperz/welcome'])
            ->and($refused('menu', 'https://site.example.org/menu'))
            ->toContain('menu')
            ->and($refused('search', 'not an address'))
            ->toContain('not an address');
    });
});

describe('The addresses of a grid, for the pages', function () {
    test('say where the base of the helpers comes from and who serves each page', function () {
        [$machine] = pages_grid(
            "[GridInfoService]\n    search = \"https://demo.example.org/search\"",
            'HelpersURL = "https://demo.example.org/helperz"',
        );
        $router = new OpenSim\Api\Router(new OpenSim\Api\Api($machine->environment));

        $attributes = $router->get('/grids/demo/addresses')->document['data']['attributes'];

        expect($attributes['helpers'])
            ->toBe('https://demo.example.org/helperz')
            ->and($attributes['sources']['helpers'] ?? null)
            ->toBe('config')
            ->and($attributes['servedBy'])
            ->toMatchArray(['search' => 'site', 'welcome' => 'helpers', 'guide' => 'helpers']);
    });
});
