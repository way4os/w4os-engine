<?php

declare(strict_types=1);

/**
 * The web assets server is a tool of its own, not a page of the site: provided, its path is under the site, anywhere, as the prefix
 * of the economy, routed apart to the engine (provided_grid() of ProvideChoicesTest, address_patch() of ProvideAddressesTest).
 */

describe('The address of the web assets', function () {
    test('provided, is a path under the site, the helpers\' by default, routed to the engine, not a page of the site', function () {
        [$machine, $router] = provided_grid();

        $default = $router->get('/grids/demo/settings/assets')->document;
        $moved = address_patch($router, 'assets', ['provide' => true, 'path' => 'assets/']);

        expect(provided_fields('assets')['path']['base'])->toBe('site')
            // Its help says the engine answers wherever the path is
            ->and(provided_fields('assets')['path']['description'])->toBe(['assets_path_help'])
            ->and(W4OS\Engine\I18n\Catalog::MESSAGES['assets_path_help'])->toBe('Any path under the site: the engine serves the pictures there, whatever the helpers.')
            ->and($default['data']['attributes']['path'])->toBe('helpers/assets')
            ->and($moved->status)->toBe(200)
            ->and($moved->document['data']['attributes']['path'])->toBe('assets/')
            ->and(file_get_contents($machine->grid . '/Helpers.ini'))->toContain('AssetsURL = "https://play.example.org/assets/"')
            ->and($moved->document['meta']['service'])->toMatchArray(['prefix' => 'https://play.example.org/', 'handledBy' => 'site', 'block' => null, 'endpoint' => 'GET /assets/{id}.{format}']);
    });
});
