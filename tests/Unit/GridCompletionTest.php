<?php

declare(strict_types=1);

/**
 * What a package that is installed or upgraded does to the grids of the machine, without anybody: the files a grid is missing, and
 * the options of a grid its simulators miss laid again (the repair of the options), so that an upgrade needs nothing by hand.
 */

use OpenSim\Config\GridConfig;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\GridCompletion;
use OpenSim\Provisioning\GridOptions;
use OpenSim\Testing\Machine;

describe('The completion of the grids at an upgrade', function () {
    test('lays again the options their simulators miss, once: a second run has nothing to do', function () {
        $machine = new Machine();
        $before = (new GridOptions(Grid::find($machine->environment, 'demo')))->repair(false)['simulators'];

        $first = GridCompletion::run($machine->environment, $machine->etc);
        $second = GridCompletion::run($machine->environment, $machine->etc);

        expect($before)
            ->toHaveKey('alpha')
            ->and(implode("\n", $first))
            ->toContain('demo: options laid again', 'profiles', 'restart')
            ->and($second)
            ->toBe([])
            ->and((string) file_get_contents(GridConfig::simulatorsDirectory($machine->grid) . '/alpha.ini'))
            ->toContain('[UserProfiles]');
    });
});
