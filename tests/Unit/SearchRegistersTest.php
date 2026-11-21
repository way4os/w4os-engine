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

        expect([$read['registerPath'], $read['registers'], $read['include2do']])->toBe(['register.php', [], true])
            ->and([$saved['registerPath'], $saved['registers'], $saved['include2do']])->toBe(['register.php', [], true])
            // Saved, never a second key of the own register
            ->and($live)->toHaveCount(1);
    });

    test('include 2do.directory with a box, on by default; the list is the other search engines only', function () {
        [$machine, $router] = provided_grid();
        $fields = provided_fields('search');
        $live = static fn(): array => array_values(preg_grep('/^\s*DATA_SRV_2do\b/', file($machine->grid . '/OpenSim.ini')));

        $default = $router->get('/grids/demo/settings/search')->document['data']['attributes'];
        $off = address_patch($router, 'search', ['include2do' => false, 'registers' => ['https://search.example.org/register.php']])->document['data']['attributes'];
        $offLines = $live();
        $on = address_patch($router, 'search', ['include2do' => true])->document['data']['attributes'];

        expect($fields['include2do'])->toMatchArray(['type' => 'boolean', 'default' => true, 'label' => 'search_include_2do', 'description' => ['search_register_help_2do']])
            ->and($fields['registers'])->not->toHaveKey('default')
            ->and($fields['registers']['description'])->toBe(['search_register_help_several'])
            ->and([$default['include2do'], $default['registers']])->toBe([true, []])
            ->and([$off['include2do'], $off['registers']])->toBe([false, ['https://search.example.org/register.php']])
            ->and($offLines)->toBe([])
            ->and([$on['include2do'], $on['registers']])->toBe([true, ['https://search.example.org/register.php']])
            ->and($live())->toHaveCount(1)
            ->and(W4OS\Engine\I18n\Catalog::MESSAGES['search_include_2do'])->toBe('Include 2do.directory');
    });
});
