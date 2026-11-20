<?php

declare(strict_types=1);

/**
 * The registers of the search (provided_grid() of ProvideChoicesTest, address_patch() of ProvideAddressesTest): the own register of
 * the grid, under its helpers, is its path, whatever the key the simulators have it under; the list is the other search engines.
 */

describe('The registers of the search', function () {
    test('give the own register of the grid once, as its path, whatever its key; the others in the list', function () {
        // The simulators made with the nick of the grid as it is, the page with its name
        [$machine, $router] = provided_grid("[DataSnapshot]\n    DATA_SRV_demo_grid = \"https://play.example.org/helpers/register.php\"\n    DATA_SRV_2do = \"https://2do.directory/helpers/register.php\"\n");

        $read = $router->get('/grids/demo/settings/search')->document['data']['attributes'];
        $saved = address_patch($router, 'search', $read)->document['data']['attributes'];
        $live = preg_grep('/^\s*DATA_SRV_.*play\.example\.org\/helpers\/register\.php/', file($machine->grid . '/OpenSim.ini'));

        expect([$read['registerPath'], $read['registers']])->toBe(['register.php', ['https://2do.directory/helpers/register.php']])
            ->and([$saved['registerPath'], $saved['registers']])->toBe(['register.php', ['https://2do.directory/helpers/register.php']])
            // Saved, never a second key of the own register
            ->and($live)->toHaveCount(1);
    });
});
