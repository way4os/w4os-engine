<?php

declare(strict_types=1);

/**
 * What can be enabled: a grid and its simulators, by the link the launcher finds them with.
 */

use W4OS\Engine\Interfaces\Enablable;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Models\Simulator;
use W4OS\Engine\Runtime\Environment;
use W4OS\Engine\Testing\Machine;
use W4OS\Engine\Testing\EnvironmentFake;

describe('grids and simulators that can be enabled', function () {
    test('are Enablable, enabled by the link of their config and disabled by removing it', function () {
        $machine = new Machine();
        $grid = Grid::find($machine->environment, 'demo');
        $beta = $grid->simulator('beta');

        expect($grid)
            ->toBeInstanceOf(Enablable::class)
            ->and($beta)
            ->toBeInstanceOf(Enablable::class)
            ->and($grid->isEnabled())
            ->toBeTrue()
            ->and($beta->isEnabled())
            ->toBeTrue();

        $beta->disable();
        $grid->disable();
        expect(file_exists($machine->etc . '/opensim.d/beta.ini'))
            ->toBeFalse()
            ->and(is_file($machine->etc . '/opensim.d/alpha.ini'))
            ->toBeTrue()
            ->and(Grid::find($machine->environment, 'demo')->isEnabled())
            ->toBeFalse();

        expect(Grid::find($machine->environment, 'demo')->enable())
            ->toBeTrue()
            ->and(Grid::find($machine->environment, 'demo')->simulator('beta')->enable())
            ->toBeTrue()
            ->and(is_link($machine->etc . '/robust.d/demo.ini'))
            ->toBeTrue()
            ->and(realpath($machine->etc . '/opensim.d/beta.ini'))
            ->toBe(realpath($machine->grid . '/sims/beta.ini'));
    });

    test('are refused when they have no config to link', function () {
        $machine = new Machine();
        $ghost = new Simulator($machine->environment, $machine->grid . '/sims/ghost.ini');

        expect($ghost->enable())->toBeFalse()->and($ghost->isEnabled())->toBeFalse();
    });

    test('tell the names and the links of the simulators of a grid, enabled or not', function () {
        $machine = new Machine();
        $grid = Grid::find($machine->environment, 'demo');

        expect($grid->simulatorNames())
            ->toBe(['alpha', 'beta'])
            ->and($grid->simulatorLink('beta'))
            ->toBe($machine->etc . '/opensim.d/beta.ini');
    });
});

describe('environment of a profile', function () {
    test('finds the grids of the install the profile describes', function () {
        $machine = new Machine();
        $environment = EnvironmentFake::with(['EtcRoot' => $machine->etc]);

        expect(Grid::find($environment, 'demo')?->robustIni())
            ->toBe($machine->grid . '/Robust.HG.ini')
            ->and(Grid::find($environment, 'nowhere'))
            ->toBeNull();
    });
});
