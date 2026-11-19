<?php

declare(strict_types=1);

/**
 * A grid an application describes (a site that bundles the engine) is what the description says: never a folder of the machine,
 * even one of the same name where the layout of the engine puts the grids. Only the engine of the machine reads its configs; a
 * site asks it through the API.
 */

use OpenSim\Models\Grid;
use OpenSim\Testing\Machine;

describe('A grid an application describes', function () {
    test('reads no folder of the machine, even the one of its name', function () {
        $machine = new Machine();
        $demo = Grid::described($machine->environment, ['nick' => 'demo', 'name' => 'Demo', 'remote' => true]);

        expect(is_dir($machine->grid))->toBeTrue()
            ->and($demo->config())->toBeNull()
            ->and($demo->access())->not->toContain('files')->not->toContain('settings')
            ->and($demo->directory())->toBe('')
            ->and($demo->destinations())->toBe([])
            ->and($demo->site())->toBeNull();
    });
});
