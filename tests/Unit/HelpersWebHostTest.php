<?php

declare(strict_types=1);

/**
 * The helpers on a web host that has no OpenSimulator: the grid is a description of a grid of another machine, and Helpers.ini
 * holds what Robust would say (its [Const], its [GridInfoService]) in the same sections.
 */

use W4OS\Engine\Models\Grid;
use W4OS\Engine\Testing\Machine;

describe('The helpers on a web host without OpenSimulator', function () {
    test('read the site of the grid and its addresses from Helpers.ini, beside its description', function () {
        $machine = new Machine();
        $directory = $machine->etc . '/grids/twodo';
        $machine->write("$directory/twodo.conf", "[Grid]\nRemote = true\nGridName = \"2do Directory\"\nGridNick = twodo\nBaseHostname = 2do.directory\nPublicPort = 8002\n");
        $machine->write("$directory/Helpers.ini", "[Const]\n    WebURL = \"https://2do.directory\"\n[Helpers]\n    HelpersURL = \"https://2do.directory/charm\"\n" .
            "[GridInfoService]\n    search = \"\${Const|WebURL}/charm/search\"\n");
        $grid = Grid::find($machine->environment, 'twodo');

        expect($grid?->site())->toBe('https://2do.directory')
            ->and($grid?->helpersUrl())->toBe('https://2do.directory/charm')
            ->and($grid?->servedByHelpers('https://2do.directory/charm/search'))->toBeTrue()
            ->and($grid?->servedByHelpers('https://2do.directory/other'))->toBeFalse();
    });
});
