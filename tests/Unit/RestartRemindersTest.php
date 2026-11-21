<?php

declare(strict_types=1);

/**
 * What a change of the settings of a grid leaves to do: the restart of the instances that read the file it went to (Robust for
 * Robust.ini, the simulators for theirs), none for what the helpers and the site read at each request, none when nothing changed.
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Testing\Machine;

function restart_patch(Router $router, string $page, array $attributes)
{
    return $router->patch("/grids/demo/settings/$page", ['data' => ['type' => 'settings', 'id' => $page, 'attributes' => $attributes]]);
}

describe('A change of the settings of a grid', function () {
    test('in the config of Robust says the grid restart is required, once', function () {
        $router = new Router(new Api((new Machine())->environment));

        $changed = restart_patch($router, 'economy', ['currencySymbol' => 'ZZ$']);
        $again = restart_patch($router, 'economy', ['currencySymbol' => 'ZZ$']);
        $reminders = $changed->document['meta']['reminders'] ?? [];

        expect(array_column($reminders, 'code'))
            ->toBe(['restart-grid'])
            ->and($reminders[0]['params']['restart'])
            ->toBe('opensim restart demo')
            ->and($reminders[0]['detail'])
            ->toStartWith('Grid restart is required')
            ->and($again->document['meta']['reminders'] ?? [])
            ->toBe([]);
    });

    test('in the files of the simulators says their restart is required, with their names', function () {
        $router = new Router(new Api((new Machine())->environment));

        $changed = restart_patch($router, 'grid', ['assetServerUri' => 'http://assets.example.org:8003']);
        $reminders = $changed->document['meta']['reminders'] ?? [];

        expect($changed->status)
            ->toBe(200)
            ->and(array_column($reminders, 'code'))
            ->toBe(['restart-simulators'])
            ->and($reminders[0]['params']['restart'])
            ->toBe('opensim restart alpha beta')
            ->and($reminders[0]['detail'])
            ->toStartWith('Simulator(s) restart is required');
    });

    test('that the helpers read says nothing to restart', function () {
        $router = new Router(new Api((new Machine())->environment));

        expect(restart_patch($router, 'models', ['match' => 'last', 'pattern' => 'Model'])->document['meta']['reminders'] ?? [])->toBe([]);
    });
});

describe('A change of a simulator', function () {
    test('says its restart is required', function () {
        $machine = new Machine();
        $router = new Router(new Api($machine->environment));

        $changed = $router->patch('/simulators/alpha', ['data' => ['type' => 'simulators', 'attributes' => ['httpPort' => 9020]]]);

        expect($changed->status)
            ->toBe(200)
            ->and(array_column($changed->document['meta']['reminders'] ?? [], 'code'))
            ->toBe(['restart-simulators'])
            ->and($changed->document['meta']['reminders'][0]['params']['restart'])
            ->toBe('opensim restart alpha');
    });
});
