<?php

declare(strict_types=1);

/**
 * A grid of another machine known by its login URI alone says what it is from its get_grid_info: its login, its name, its pages,
 * asked once, then read as it was kept; a grid that does not answer has what the site gave of it.
 */

use W4OS\Engine\Models\Grid;

/** A grid of another machine, at far.example.org:8002, with nothing but its description. */
function remote_info_grid(W4OS\Engine\Testing\Machine $machine, string $helpers = ''): void
{
    $directory = $machine->etc . '/grids/far';
    $machine->write("$directory/far.conf", "[Grid]\nRemote = true\nGridName = Far\nGridNick = far\nBaseHostname = far.example.org\nPublicPort = 8002\n");
    $helpers === '' || $machine->write("$directory/Helpers.ini", $helpers);
}

const REMOTE_SAYS = '<gridinfo><platform>OpenSim</platform><login>http://far.example.org:8002/</login><gridname>Far World</gridname>'
    . '<gridnick>far</gridnick><welcome>https://far.example.org/welcome</welcome><register>https://far.example.org/register</register></gridinfo>';

describe('A grid of another machine known by its login URI', function () {
    test('says its login, its name and its pages from its get_grid_info, asked once', function () {
        $answers = ['http://far.example.org:8002/get_grid_info' => REMOTE_SAYS];
        $asked = [];
        $machine = grid_info_world($answers, $asked);
        remote_info_grid($machine);

        $grid = Grid::find($machine->environment, 'far');
        $info = [$grid->loginUrl(), $grid->loginUri(), $grid->gridInfo()['gridname'] ?? null, $grid->gridInfo()['register'] ?? null, $grid->gridInfoIsLive()];
        $again = Grid::find($machine->environment, 'far');

        expect($grid->isRemote())->toBeTrue()
            ->and($info)->toBe(['http://far.example.org:8002', 'far.example.org:8002', 'Far World', 'https://far.example.org/register', true])
            ->and($again->loginUri())->toBe('far.example.org:8002')
            ->and($asked)->toBe(['http://far.example.org:8002/get_grid_info']);
    });

    test('that does not answer has what the site gave of it, and is not asked again at once', function () {
        $answers = [];
        $asked = [];
        $machine = grid_info_world($answers, $asked);
        remote_info_grid($machine, "[GridInfoService]\n    login = http://far.example.org:8002/\n    gridname = Far World\n");

        $grid = Grid::find($machine->environment, 'far');
        $info = [$grid->loginUri(), $grid->gridInfo()['gridname'] ?? null, $grid->gridInfoIsLive()];
        Grid::find($machine->environment, 'far')->loginUri();

        expect($info)->toBe(['far.example.org:8002', 'Far World', false])
            ->and($asked)->toBe(['http://far.example.org:8002/get_grid_info']);
    });
});
