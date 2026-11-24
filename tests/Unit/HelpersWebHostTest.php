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

    test('read what Robust would tell from Helpers.ini: its web search, its name', function () {
        $machine = new Machine();
        $directory = $machine->etc . '/grids/twodo';
        $machine->write("$directory/twodo.conf", "[Grid]\nRemote = true\nGridName = \"2do Directory\"\nGridNick = twodo\nBaseHostname = 2do.directory\nPublicPort = 8002\n");
        $machine->write("$directory/Helpers.ini", "[Const]\n    WebURL = \"https://2do.directory\"\n[Helpers]\n    HelpersURL = \"https://2do.directory/charm\"\n" .
            "[GridInfoService]\n    search = \"\${Const|WebURL}/charm/search\"\n    gridname = \"2do HYPEvents\"\n[LoginService]\n    SearchURL = \"\${Const|WebURL}/charm/search\"\n");
        $grid = Grid::find($machine->environment, 'twodo');

        expect($grid?->address('search'))->toBe('https://2do.directory/charm/search')
            ->and($grid?->configuredInfo()['gridname'] ?? null)->toBe('2do HYPEvents')
            // No database of a grid: its people, its groups, its regions are none of this host's
            ->and($grid?->hasDatabase())->toBeFalse();
        // A grid of this machine, its database in its Robust config
        $machine->write($machine->grid . '/Robust.HG.ini', (string) file_get_contents($machine->grid . '/Robust.HG.ini') .
            "\n[DatabaseService]\n    ConnectionString = \"Data Source=localhost;Database=demo;User ID=opensim;Password=x;\"\n");
        expect(Grid::find($machine->environment, 'demo')?->hasDatabase())->toBeTrue();
    });

    test('give the brand of the site in Helpers.ini: its icon, its accent color, its font; what is not said or wrong, none', function () {
        $machine = new Machine();
        $brand = static function (string $helpers) use ($machine): array {
            $machine->write($machine->grid . '/Helpers.ini', "[Helpers]\n$helpers");

            return Grid::find($machine->environment, 'demo')->brand();
        };
        $icon = 'https://2do.directory/w/wp-content/uploads/2019/09/2do-logo-square-80x80-bordertransp.png';

        expect($brand("IconURL = \"$icon\"\nAccentColor = \"#7e3c68\"\nFont = \"Roboto\"\nFontURL = \"https://fonts.googleapis.com/css2?family=Roboto:wght@400;700\"\n"))
            ->toBe(['icon' => $icon, 'accent' => '#7e3c68', 'font' => 'Roboto', 'fontUrl' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;700'])
            ->and($brand(''))->toBe(['icon' => null, 'accent' => null, 'font' => null, 'fontUrl' => null])
            // Never a value that would break out of the style of the page
            ->and($brand("AccentColor = \"red; } body { display: none\"\nFont = \"Roboto; }\"\nIconURL = \"javascript:alert(1)\"\n"))
            ->toBe(['icon' => null, 'accent' => null, 'font' => null, 'fontUrl' => null]);
    });
});

