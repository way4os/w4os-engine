<?php

declare(strict_types=1);

/**
 * The settings page of a service of the helpers starts with Provide (on by default), as w4os-wp's pages do. On: the address of the
 * site is fixed, only the path is given; under the base of the helpers the helpers handle the requests, elsewhere the site does, on a
 * page that holds the block of the service. Off: the whole address of the service, somebody else's. The setting kept is the address,
 * in its file, as before.
 */

use OpenSim\Api\Api;
use OpenSim\Api\Router;
use OpenSim\Config\ConfigReader;
use OpenSim\I18n\Catalog;
use OpenSim\Models\GridSettings;
use OpenSim\Testing\Machine;

/** @return array{0:Machine,1:Router} a grid whose web site is https://play.example.org, its helpers under /helpers */
function provide_grid(string $more = ''): array
{
    $machine = new Machine();
    $machine->write(
        $machine->grid . '/Robust.HG.ini',
        file_get_contents($machine->grid . '/Robust.HG.ini') . "\n[Const]\n    WebURL = \"https://play.example.org\"\n$more",
    );
    $machine->write($machine->grid . '/Helpers.ini', "[Helpers]\n    HelpersURL = \"https://play.example.org/helpers\"\n");

    return [$machine, new Router(new Api($machine->environment))];
}

describe('A settings page of a service of the helpers', function () {
    test('starts with Provide, on by default, then the path under the site, or the whole address when off', function () {
        $pages = ['economy', 'search', 'guide', 'offline', 'assets'];
        // Provide first; the path and the whole address where w4os-wp shows the address
        $first = static function (string $page): array {
            $fields = array_column(GridSettings::form($page)['fields'], null, 'name');
            $url = array_values(array_filter($fields, static fn(array $field): bool => ($field['condition'] ?? []) === ['provide' => [false]]))[0];

            return [GridSettings::form($page)['fields'][0], $fields['path'], $url];
        };
        $economy = array_column(GridSettings::form('economy')['fields'], null, 'name');

        foreach ($pages as $page) {
            [$provide, $path, $url] = $first($page);
            expect($provide)->toMatchArray(['name' => 'provide', 'type' => 'boolean', 'widget' => 'switch'])
                ->and($path)->toMatchArray(['name' => 'path', 'type' => 'path', 'base' => 'site', 'condition' => ['provide' => [true]]])
                ->and($url)->toMatchArray(['type' => 'url', 'condition' => ['provide' => [false]]]);
        }
        expect(array_map(static fn(string $page): string => Catalog::MESSAGES[$first($page)[0]['label']], $pages))
            ->toBe(['Provide Economy Helper', 'Provide Search', 'Provide Destinations Guide Service', 'Provide Offline Messages Service', 'Provide Web Assets Server'])
            ->and(Catalog::messages('fr_FR')[$first('economy')[0]['label']])->toBe("Fournir l'assistant d'économie")
            // What the helpers need is asked only when they provide it, as w4os-wp shows it
            ->and($economy['currencyRate']['condition'])->toMatchArray(['provide' => [true]])
            ->and($economy['economyModule'])->not->toHaveKey('condition');
    });

    test('says who handles the requests: the helpers under their base, the site elsewhere on it, nobody here when off', function () {
        [, $router] = provide_grid("[LoginService]\n    DestinationGuide = \"https://play.example.org/guide\"\n[GridInfoService]\n    economy = \"https://shop.example.org/money/\"\n");
        $read = static fn(string $page): array => $router->get("/grids/demo/settings/$page")->document;

        $assets = $read('assets');
        $guide = $read('guide');
        $economy = $read('economy');

        expect($assets['data']['attributes'])->toMatchArray(['provide' => true, 'path' => '/helpers/assets'])
            ->and($assets['meta']['service'])->toMatchArray(['base' => 'https://play.example.org', 'helpers' => 'https://play.example.org/helpers', 'handledBy' => 'helpers', 'block' => null, 'endpoint' => 'GET /assets/{id}.{format}'])
            ->and($guide['data']['attributes'])->toMatchArray(['provide' => true, 'path' => '/guide'])
            ->and($guide['meta']['service'])->toMatchArray(['handledBy' => 'site', 'block' => 'destination_guide'])
            ->and($economy['data']['attributes'])->toMatchArray(['provide' => false, 'economyUrl' => 'https://shop.example.org/money/'])
            ->and($economy['meta']['service']['handledBy'])->toBe('elsewhere');
    });

    test('keeps the address the page says: the site and the path when on, the whole address when off', function () {
        [$machine, $router] = provide_grid();
        $patch = static fn(string $page, array $attributes) => $router->patch("/grids/demo/settings/$page", ['data' => ['type' => 'settings', 'id' => $page, 'attributes' => $attributes]]);
        $robust = static fn(): array => ConfigReader::load($machine->grid . '/Robust.HG.ini', expand: false);

        $on = $patch('guide', ['provide' => true, 'path' => 'destinations']);
        $off = $patch('economy', ['provide' => false, 'economyUrl' => 'https://shop.example.org/money/']);

        expect([$on->status, $off->status])->toBe([200, 200])
            ->and($robust()['LoginService']['DestinationGuide'] ?? null)->toBe('https://play.example.org/destinations')
            ->and($on->document['meta']['service']['handledBy'])->toBe('site')
            ->and($robust()['GridInfoService']['economy'] ?? null)->toBe('https://shop.example.org/money/')
            ->and($patch('economy', ['provide' => true, 'path' => '/helpers/'])->document['meta']['service']['handledBy'])->toBe('helpers')
            ->and($robust()['GridInfoService']['economy'] ?? null)->toBe('https://play.example.org/helpers/')
            // Off, the address is needed
            ->and($patch('assets', ['provide' => false, 'assetsUrl' => ''])->status)->toBe(400);
    });

    test('cannot provide a service of a grid that has no web site: its whole address is asked', function () {
        $machine = new Machine();
        $router = new Router(new Api($machine->environment));
        $patch = $router->patch('/grids/demo/settings/guide', ['data' => ['type' => 'settings', 'id' => 'guide', 'attributes' => ['provide' => true, 'path' => '/guide']]]);

        expect($patch->status)->toBe(400)
            ->and($patch->document['detail'])->toContain('WebURL');
    });
});
