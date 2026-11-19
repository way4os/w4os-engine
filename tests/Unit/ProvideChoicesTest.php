<?php

declare(strict_types=1);

/**
 * The pages of the services of the helpers as Olivier chose them where w4os-wp differs: Provide on by default for every service;
 * off, the whole address of the service and nothing else; on, the site read-only and the path under it.
 */

use OpenSim\Api\Api;
use OpenSim\Api\Router;
use OpenSim\Config\Settings;
use OpenSim\Models\GridSettings;
use OpenSim\Testing\Machine;

const PROVIDED_PAGES = ['economy', 'search', 'guide', 'offline', 'assets'];

/** @return array{0:Machine,1:Router} the demo grid with a site, its money server in its own file */
function provided_grid(string $opensim = '', string $money = ''): array
{
    $machine = new Machine();
    $machine->write($machine->grid . '/Robust.HG.ini', file_get_contents($machine->grid . '/Robust.HG.ini') . "\n[Const]\n    WebURL = \"https://play.example.org\"\n");
    $machine->write($machine->grid . '/Helpers.ini', "[Helpers]\n    HelpersURL = \"https://play.example.org/helpers\"\n");
    $opensim === '' || $machine->write($machine->grid . '/OpenSim.ini', (is_file($machine->grid . '/OpenSim.ini') ? file_get_contents($machine->grid . '/OpenSim.ini') : '') . "\n$opensim");
    $money === '' || $machine->write($machine->grid . '/MoneyServer.ini', $money);

    return [$machine, new Router(new Api($machine->environment))];
}

/** @return array<string,array<string,mixed>> the fields of a page, by name */
function provided_fields(string $page): array
{
    return array_column(GridSettings::form($page)['fields'], null, 'name');
}

describe('Provide', function () {
    test('is on by default for every service, search and the web assets too', function () {
        [, $router] = provided_grid();

        $provide = array_map(static fn(string $page): mixed => $router->get("/grids/demo/settings/$page")->document['data']['attributes']['provide'] ?? null, PROVIDED_PAGES);

        expect($provide)->toBe(array_fill(0, 5, true));
    });

    test('off, shows the whole address of the service and hides the rest of its settings', function () {
        foreach (PROVIDED_PAGES as $page) {
            $fields = provided_fields($page);
            $setting = array_values(array_filter($fields, static fn(array $field): bool => ($field['condition'] ?? null) === ['provide' => [false]]));
            $others = array_diff_key($fields, ['provide' => 1, $setting[0]['name'] => 1]);

            expect($setting)->toHaveCount(1)
                ->and($setting[0]['type'])->toBe('url')
                // Read-only ones too: the module of the economy, of the offline messages, where the search registers
                ->and(array_filter($others, static fn(array $field): bool => ($field['condition']['provide'] ?? null) !== [true]))->toBe([]);
        }
    });

    test('on, shows the site read-only and the path under it, the one that is changed', function () {
        [$machine, $router] = provided_grid();

        $done = $router->patch('/grids/demo/settings/guide', ['data' => ['type' => 'settings', 'id' => 'guide', 'attributes' => ['provide' => true, 'path' => '/places/guide']]]);

        expect(provided_fields('guide')['path'])->toMatchArray(['type' => 'path', 'base' => 'site'])
            ->and(provided_fields('guide')['path'])->not->toHaveKey('readOnly')
            ->and($done->document['data']['attributes']['path'])->toBe('/places/guide')
            ->and($done->document['meta']['service']['base'])->toBe('https://play.example.org')
            ->and(file_get_contents($machine->grid . '/Robust.HG.ini'))->toContain('https://play.example.org/places/guide');
    });
});
