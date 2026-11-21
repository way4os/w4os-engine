<?php

declare(strict_types=1);

/**
 * The logos the engine ships for every bridge (w4os, OpenSimulator), and the logo of a grid, which is its own setting.
 */

use W4OS\Engine\Api\Router;
use W4OS\Engine\Api\Api;
use W4OS\Engine\Assets\Logos;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Testing\Machine;

describe('The logos of w4os and OpenSimulator', function () {
    test('are shipped by their file names, with a note of where they come from', function () {
        $sizes = array_map(
            static fn(string $file): string => implode('x', array_slice((array) getimagesize($file), 0, 2)),
            Logos::all(),
        );

        expect(array_keys(Logos::all()))
            ->toBe(Logos::FILES)
            ->and($sizes)
            ->toMatchArray([
                'w4os-logo-v1-long-300x75.png' => '300x75',
                'cropped-w4os-logo-v1-square-32x32.png' => '32x32',
                'cropped-w4os-logo-v1-square-180x180.png' => '180x180',
                'cropped-w4os-logo-v1-square-192x192.png' => '192x192',
                'opensimulator-logo-24x14.png' => '24x14',
            ])
            ->and(array_map(Logos::file(...), [Logos::W4OS_LONG, Logos::W4OS_SQUARE, Logos::W4OS_ICON, Logos::OPENSIMULATOR]))
            ->not->toContain(null)
            ->and(Logos::file('w4os-icon-v1.png'))
            ->toBe(Logos::all()['w4os-icon-v1.png'])
            ->and(Logos::file('../languages/w4os-engine.pot'))
            ->toBeNull()
            ->and(file_get_contents(Logos::directory() . '/README.md'))
            ->toContain('OpenSimulator');
    });

    test('are served by the API to whoever asks, without a key', function () {
        $router = new Router(new Api((new Machine())->environment));

        $logo = $router->get('/logos/w4os-logo-v1-long-300x75.png');
        $unknown = $router->get('/logos/logo.svg');

        expect($logo->status)
            ->toBe(200)
            ->and($logo->headers['Content-Type'])
            ->toBe('image/png')
            ->and($logo->body())
            ->toBe(file_get_contents((string) Logos::file('w4os-logo-v1-long-300x75.png')))
            ->and($unknown->status)
            ->toBe(404)
            ->and(Router::PUBLIC)
            ->toContain('GET /logos/{name}')
            ->and(W4OS\Engine\Api\Contract::document()['paths']['/logos/{name}']['get']['parameters'][0]['schema']['enum'])
            ->toBe(Logos::FILES);
    });
});

describe('The logo of a grid', function () {
    test('is the LogoURL of its helpers, said with its addresses and its details', function () {
        $machine = new Machine();
        $grid = static fn(): Grid => Grid::find($machine->environment, 'demo');
        $router = new Router(new Api($machine->environment));

        $info = $router->get('/grids/demo/addresses')->document['data']['attributes'];
        $none = [$grid()->logoUrl(), array_key_exists('logo', $info) ? $info['logo'] : 'missing'];
        $machine->write($machine->grid . '/Helpers.ini', "[Helpers]\nLogoURL = \"https://demo.example.org/logo.svg\"\n");

        expect($none)
            ->toBe([null, null])
            ->and($grid()->logoUrl())
            ->toBe('https://demo.example.org/logo.svg')
            ->and($grid()->detail()['logoUrl'])
            ->toBe('https://demo.example.org/logo.svg')
            ->and($router->get('/grids/demo/addresses')->document['data']['attributes']['logo'])
            ->toBe('https://demo.example.org/logo.svg')
            ->and($router->get('/grids/demo/info')->document['data']['attributes']['logo'])
            ->toBe('https://demo.example.org/logo.svg');
    });
});
