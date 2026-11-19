<?php

declare(strict_types=1);

/**
 * The web assets service of a grid needs imagick that reads JPEG 2000 where it runs. Without it, the grid sets an external web
 * assets service (AssetsURL of [Helpers], Helpers.ini), as any service of the grid may be given by another server: then nothing here
 * converts its pictures.
 */

use OpenSim\Assets\WebAssets;
use OpenSim\Health\Health;
use OpenSim\Models\Grid;
use OpenSim\Testing\Machine;

/** The grid `demo` with its helpers under play.example.org/helpers and the web assets service $assets gives, if any. */
function assets_grid(?string $assets): Grid
{
    $machine = new Machine();
    $machine->write(
        $machine->grid . '/Helpers.ini',
        "[Helpers]\n    HelpersURL = \"https://play.example.org/helpers\"\n" . ($assets !== null ? "    AssetsURL = \"$assets\"\n" : ''),
    );

    return Grid::find($machine->environment, 'demo');
}

describe('The web assets service of a grid', function () {
    test('is external when the grid sets one that is not under its helpers', function () {
        expect([
            WebAssets::external(assets_grid('https://assets.example.org/a/')),
            WebAssets::external(assets_grid('https://play.example.org/helpers/assets')),
            WebAssets::external(assets_grid(null)),
        ])->toBe(['https://assets.example.org/a', null, null]);
    });

    test('gives the address of a picture there, as w4os-wp asks it, when nothing here reads JPEG 2000', function () {
        $external = assets_grid('https://assets.example.org/a/');

        expect([
            WebAssets::elsewhere($external, 'CB2052AE-D161-43E9-B11B-C834217823CD', 'png', readsHere: false),
            WebAssets::elsewhere($external, 'cb2052ae-d161-43e9-b11b-c834217823cd', 'png', readsHere: true),
            WebAssets::elsewhere(assets_grid(null), 'cb2052ae-d161-43e9-b11b-c834217823cd', 'png', readsHere: false),
            // The pictures the kit gives are never asked elsewhere
            WebAssets::elsewhere($external, WebAssets::PERSON, 'png', readsHere: false),
        ])->toBe(['https://assets.example.org/a/cb2052ae-d161-43e9-b11b-c834217823cd.png', null, null, null]);
    });

    test('is where the API sends a picture when nothing here reads JPEG 2000', function () {
        $machine = new Machine();
        $machine->write($machine->grid . '/Helpers.ini', "[Helpers]\n    HelpersURL = \"https://play.example.org/helpers\"\n    AssetsURL = \"https://assets.example.org/a\"\n");
        $router = new OpenSim\Api\Router(new OpenSim\Api\Api($machine->environment, null, static fn(Grid $grid): ?OpenSim\Config\GridConfig => null));
        $response = $router->get('/grids/demo/assets/cb2052ae-d161-43e9-b11b-c834217823cd.jpg');

        expect([$response->status, $response->headers['Location'] ?? null])
            ->toBe([302, 'https://assets.example.org/a/cb2052ae-d161-43e9-b11b-c834217823cd.jpg']);
    })->skip(fn() => OpenSim\Assets\ImagickConverter::available(), 'imagick reads JPEG 2000 here');
});

describe('The health of the pictures of the world', function () {
    test('says imagick is required, and its JPEG 2000 by the web assets service', function () {
        $rows = array_column(Health::extensions(), null, 'label');

        expect($rows['imagick']['detail'])
            ->toContain('web assets')
            ->and($rows['imagick JPEG 2000']['detail'])
            ->toContain('web assets service');
    });

    test('is an error without JPEG 2000 and without an external service, naming the setting that gives one', function () {
        $setting = ['file' => 'Helpers.ini', 'section' => 'Helpers', 'key' => 'AssetsURL'];

        expect(Health::jpeg2000(true, null, $setting))
            ->toMatchArray(['kind' => 'requirement', 'installed' => true, 'level' => 'ok', 'status' => 'reads JPEG 2000'])
            ->and(Health::jpeg2000(false, null, $setting))
            ->toMatchArray(['installed' => false, 'level' => 'error', 'status' => 'cannot read JPEG 2000', 'setting' => $setting])
            ->and(Health::jpeg2000(false, null, $setting)['detail'])
            ->toContain('external web assets service')
            ->toContain('Helpers.ini · [Helpers] · AssetsURL')
            // An external service converts them: nothing is needed here
            ->and(Health::jpeg2000(false, 'https://assets.example.org/a', $setting))
            ->toMatchArray(['installed' => false, 'level' => 'ok', 'url' => 'https://assets.example.org/a'])
            ->and(Health::jpeg2000(false, 'https://assets.example.org/a', $setting)['status'])
            ->toBe('external web assets service');
    });

    test('of a grid tells whether its web assets service is external', function () {
        $machine = new Machine();
        $machine->write($machine->grid . '/Helpers.ini', "[Helpers]\n    HelpersURL = \"https://play.example.org/helpers\"\n    AssetsURL = \"https://assets.example.org/a\"\n");
        $api = new OpenSim\Api\Api($machine->environment, null, static fn(Grid $grid): ?OpenSim\Config\GridConfig => null);
        $groups = array_column($api->health('demo', refresh: true)->document['data']['attributes']['groups'], null, 'file');
        $row = array_column($groups['PHP']['rows'], null, 'label')['imagick JPEG 2000'];

        expect($row['setting'])->toBe(['file' => 'Helpers.ini', 'section' => 'Helpers', 'key' => 'AssetsURL'])
            ->and($row['level'])->toBe('ok')
            ->and($row['installed'] ? null : $row['url'])->toBe($row['installed'] ? null : 'https://assets.example.org/a');
    });
});
