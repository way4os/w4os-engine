<?php

declare(strict_types=1);

/**
 * A grid found by its nick written in another case: a nick is in small letters, a site that writes it in capitals still finds its grid.
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Testing\Machine;

describe('The nick of a grid', function () {
    test('in capitals finds the grid, which keeps its own', function () {
        $machine = new Machine();
        $router = new Router(new Api($machine->environment));

        $answer = $router->get('/grids/Demo');

        expect(Grid::find($machine->environment, 'DEMO')?->nick())->toBe('demo')
            ->and([$answer->status, $answer->document['data']['id'] ?? null])->toBe([200, 'demo'])
            ->and(Grid::find($machine->environment, 'nowhere'))->toBeNull()
            ->and($router->get('/grids/No%20such')->status)->toBe(404);
    });
});
