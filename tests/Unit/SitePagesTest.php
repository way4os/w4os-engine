<?php

declare(strict_types=1);

/**
 * Who serves each page of a grid, the helpers or the site: a page whose address is under the base of the helpers is theirs, any
 * other is the site's. The base is HelpersURL of Helpers.ini, else WebURL/helpers for a grid that has helpers, never the economy.
 * The site declares the pages it serves, never over an address given otherwise.
 */

use W4OS\Engine\Config\IniEditor;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\Web\Services;
use W4OS\Engine\Provisioning\Web\SitePages;
use W4OS\Engine\Testing\Machine;

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
    test('is HelpersURL of Helpers.ini, else /helpers for a grid that has helpers, never the economy nor a guess, and says where it comes from', function () {
        $base = static function (string $gridInfo, string $helpers): array {
            [, $grid] = pages_grid($gridInfo, $helpers);

            return [$grid()->helpersUrl(), $grid()->helpersUrlSource()];
        };

        // A grid that sets no helpers has none: /helpers is no base of its own
        expect($base('', ''))
            ->toBe([null, null])
            // An economy on its site says it has helpers, which keep their own path: the economy follows them, not the reverse
            ->and($base("[GridInfoService]\n    economy = \"https://demo.example.org/shop\"", ''))
            ->toBe(['https://demo.example.org/helpers', 'default'])
            ->and($base('', 'LogoURL = "https://demo.example.org/logo.png"'))
            ->toBe(['https://demo.example.org/helpers', 'default'])
            ->and($base("[GridInfoService]\n    economy = \"https://demo.example.org/shop\"", 'HelpersURL = "https://demo.example.org/helperz/"'))
            ->toBe(['https://demo.example.org/helperz', 'config'])
            ->and($base('', 'HelpersURL = "https://demo.example.org/"'))
            ->toBe(['https://demo.example.org', 'config'])
            ->and($base("[GridInfoService]\n    economy = \"https://demo.example.org/\"", ''))
            ->toBe(['https://demo.example.org/helpers', 'default'])
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
    test('are the ones the grid sets, never the addresses they would have under the helpers', function () {
        [, $grid] = pages_grid('', 'HelpersURL = "https://demo.example.org/helperz"');
        $addresses = static fn(Grid $grid): array => array_map(
            static fn(string $key): ?string => $grid->address($key),
            ['search' => 'search', 'welcome' => 'welcome', 'guide' => 'guide', 'status' => 'grid-status', 'directory' => 'directory'],
        );
        $pages = array_column(SitePages::rows($grid()), null, 'page');

        expect($addresses($grid()))
            ->toBe(['search' => null, 'welcome' => null, 'guide' => null, 'status' => null, 'directory' => null])
            // Where each would be under the helpers is said apart, for a site that sets it
            ->and($pages['guide'])
            ->toMatchArray(['url' => null, 'source' => null, 'servedBy' => null, 'helpersDefault' => 'https://demo.example.org/helperz/guide'])
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
                null,
                'https://site.example.org:8443/helperz/currency.php',
            ])
            ->and(Services::originOf('https://site.example.org:8443/helperz/x'))
            ->toBe('https://site.example.org:8443');
    });

    test('are listed with who serves each: the helpers under their base, the site elsewhere', function () {
        [, $grid] = pages_grid(
            "[GridInfoService]\n    economy = \"https://demo.example.org/helpers\"\n    search = \"https://demo.example.org/search\"\n    web_profile_url = \"https://demo.example.org/helpers/profile/[AGENT_NAME]\"",
        );
        $pages = array_column(SitePages::rows($grid()), null, 'page');

        expect(array_keys($pages))
            ->toBe(['helpers', 'search', 'profile', 'guide', 'welcome', 'status', 'directory'])
            // The helpers of a grid that sets no HelpersURL: their default path
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
            // A page the grid does not set has no address, where it would be under the helpers said apart
            ->toMatchArray(['url' => null, 'source' => null, 'servedBy' => null, 'helpersDefault' => 'https://demo.example.org/helpers/guide']);
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
            ->toBe(['https://demo.example.org/helperz', 'https://site.example.org/directory', null])
            ->and($refused('menu', 'https://site.example.org/menu'))
            ->toContain('menu')
            ->and($refused('search', 'not an address'))
            ->toContain('not an address');
    });
});

describe('The addresses of a grid, for the pages', function () {
    test('say where the base of the helpers comes from and who serves each page', function () {
        [$machine] = pages_grid(
            "[GridInfoService]\n    search = \"https://demo.example.org/search\"\n    welcome = \"https://demo.example.org/helperz/welcome\"",
            'HelpersURL = "https://demo.example.org/helperz"',
        );
        $router = new W4OS\Engine\Api\Router(new W4OS\Engine\Api\Api($machine->environment));

        $attributes = $router->get('/grids/demo/addresses')->document['data']['attributes'];

        expect($attributes['helpers'])
            ->toBe('https://demo.example.org/helperz')
            ->and($attributes['sources']['helpers'] ?? null)
            ->toBe('config')
            ->and($attributes['servedBy'])
            ->toMatchArray(['search' => 'site', 'welcome' => 'helpers', 'guide' => null]);
    });
});

describe('The pages a site declares', function () {
    /** Declare a page through the API: [status, attributes or detail, meta]. */
    $put = static function (Grid $grid, string $page, string $url): array {
        $router = new W4OS\Engine\Api\Router(new W4OS\Engine\Api\Api($grid->environment()));
        $answer = $router->put("/grids/demo/site-pages/$page", ['data' => ['type' => 'site-pages', 'attributes' => ['url' => $url]]]);

        return [$answer->status, $answer->document['data']['attributes'] ?? ($answer->document['code'] ?? null), $answer->document['meta'] ?? [], $answer->document['detail'] ?? ''];
    };

    test('are written on the site of the grid, kept as the site\'s, the restart said; the site changes its own again', function () use ($put) {
        [$machine, $grid] = pages_grid('', 'LogoURL = "https://demo.example.org/logo.png"');

        [$status, $page, $meta] = $put($grid(), 'profile', 'https://demo.example.org/opensim/profile');
        $again = $put($grid(), 'profile', 'https://demo.example.org/site/profile');
        $read = (new W4OS\Engine\Api\Router(new W4OS\Engine\Api\Api($machine->environment)))->get('/grids/demo/site-pages/profile')->document['data']['attributes'] ?? [];

        expect($status)->toBe(200)
            ->and($page)->toMatchArray(['url' => 'https://demo.example.org/opensim/profile/[AGENT_NAME]', 'declared' => true, 'servedBy' => 'site'])
            ->and(array_column($meta['reminders'] ?? [], 'code'))->toBe(['restart-grid'])
            // Written from the web site of the grid, which it follows
            ->and(IniEditor::load($machine->grid . '/Robust.HG.ini')->get('GridInfoService', 'web_profile_url'))->toBe('${Const|WebURL}/site/profile/[AGENT_NAME]')
            ->and([$again[0], $again[2]['previous'] ?? null])->toBe([200, 'https://demo.example.org/opensim/profile/[AGENT_NAME]'])
            ->and($read)->toMatchArray(['url' => 'https://demo.example.org/site/profile/[AGENT_NAME]', 'declared' => true]);
    });

    test('take the place of the address this install gave (the default of the helpers), never of one given otherwise', function () use ($put) {
        [, $grid] = pages_grid("[GridInfoService]\n    web_profile_url = \"https://demo.example.org/helpers/profile/[AGENT_NAME]\"\n    register = \"https://elsewhere.example.org/join\"", 'LogoURL = "https://demo.example.org/logo.png"');

        $default = $put($grid(), 'profile', 'https://demo.example.org/opensim/profile');
        [$status, $code, , $detail] = $put($grid(), 'register', 'https://demo.example.org/opensim/register');

        expect($default[0])->toBe(200)
            ->and([$status, $code])->toBe([409, 'page-set-elsewhere'])
            ->and($detail)->toContain('https://elsewhere.example.org/join', 'given otherwise than by the site')
            ->and(SitePages::current($grid(), 'register'))->toBe('https://elsewhere.example.org/join');
    });

    test('never take the place of the address the grid it was migrated from had, but of one its import added', function () use ($put) {
        [$machine, $grid] = pages_grid("[GridInfoService]\n    about = \"https://old.example.org/about\"\n    welcome = \"https://demo.example.org/opensim/economy/welcome\"", 'LogoURL = "https://demo.example.org/logo.png"');
        // The report of its import: what it kept of the old grid, what this install added
        file_put_contents($machine->grid . '/demo.import-report.txt', "Grid Demo (demo), imported from /old/Robust.HG.ini\n\n1 setting(s) of the original kept in the new config:\n  [GridInfoService] about = https://old.example.org/about\n\n1 setting(s) added by this install, which the old config had not (what w4os needs, its options):\n  [GridInfoService] welcome = https://demo.example.org/opensim/economy/welcome (Robust)\n\n0 setting(s) of the original not kept\n");

        [$status, $code, , $detail] = $put($grid(), 'about', 'https://demo.example.org/opensim/about');
        $welcome = $put($grid(), 'welcome', 'https://demo.example.org/opensim/welcome');

        expect([$status, $code])->toBe([409, 'page-set-elsewhere'])
            ->and($detail)->toContain('the one of the grid it was migrated from')
            ->and($welcome[0])->toBe(200)
            ->and(SitePages::current($grid(), 'welcome'))->toBe('https://demo.example.org/opensim/welcome');
    });

    test('are left to the site by the options: they never lay theirs over a page it declared', function () {
        [$machine, $grid] = pages_grid('', 'LogoURL = "https://demo.example.org/logo.png"');
        SitePages::declare($grid(), 'profile', 'https://demo.example.org/opensim/profile');

        expect(SitePages::declaredKeys($grid()))->toBe(['gridinfoservice|web_profile_url'])
            ->and(SitePages::declare($grid(), 'help', 'https://demo.example.org/opensim/help')['keys'])->toBe(['[GridInfoService] help']);
    });

    test('are pages a site serves only: not the base of the helpers, nor a page there is not', function () use ($put) {
        [, $grid] = pages_grid('', 'LogoURL = "https://demo.example.org/logo.png"');

        expect($put($grid(), 'helpers', 'https://demo.example.org/x')[0])->toBe(404)
            ->and($put($grid(), 'nowhere', 'https://demo.example.org/x')[0])->toBe(404)
            ->and($put($grid(), 'profile', 'not an address')[0])->toBe(400);
    });
});
