<?php

declare(strict_types=1);

/**
 * A grid of an existing install the suite runs where it is: its [Grid <nick>] section in the config of the suite says where its
 * files are, and is the only thing written; the install stays as it was.
 */

use OpenSim\Config\InPlaceGrids;
use OpenSim\Config\Layout;
use OpenSim\Models\Grid;
use OpenSim\OpenSim;
use OpenSim\Testing\Machine;

/** A machine with an existing install beside it, registered in place: its Robust and one simulator, where they are. */
function in_place_machine(bool $enabled = false): array
{
    $machine = new Machine();
    $old = $machine->root . '/old';
    mkdir("$old/bin/Regions", 0o755, true);
    file_put_contents("$old/bin/Robust.HG.ini", "[Const]\n    BaseHostname = \"old.example.org\"\n    PublicPort = \"8102\"\n    PrivatePort = \"8103\"\n[GridInfoService]\n    gridname = \"Old Grid\"\n    gridnick = \"oldgrid\"\n[DatabaseService]\n    ConnectionString = \"Data Source=localhost;Database=oldgrid;User ID=old;Password=oldpass;\"\n");
    file_put_contents("$old/bin/OpenSim.ini", "[Startup]\n    regionload_regionsdir = \"$old/bin/Regions\"\n[Network]\n    http_listener_port = 9100\n");
    file_put_contents("$old/bin/Regions/Welcome.ini", "[Welcome]\nRegionUUID = 22222222-2222-4222-8222-222222222222\nLocation = 1000,1000\nInternalPort = 9101\n");
    $conf = $machine->etc . '/' . Layout::FILE;
    file_put_contents($conf, "[Defaults]\nEtcRoot = {$machine->etc}\n\n[Grid oldgrid]\nRobustIni = $old/bin/Robust.HG.ini\nCoreDirectory = $old\nSimulators = welcome:$old/bin/OpenSim.ini\n" . ($enabled ? "Enabled = yes\nEnabledSimulators = welcome\n" : ''));
    $environment = $machine->environment->withLayout(Layout::load($conf, $machine->environment->layout->all()));

    return [$machine, $environment, $old, $conf];
}

describe('A grid in place', function () {
    test('is a grid of the suite, read where its files are, nothing written in the install', function () {
        [$machine, $environment, $old] = in_place_machine();
        $before = [file_get_contents("$old/bin/Robust.HG.ini"), file_get_contents("$old/bin/OpenSim.ini")];

        $grid = Grid::find($environment, 'oldgrid');

        expect($grid)
            ->not->toBeNull()
            ->and($grid->name())
            ->toBe('Old Grid')
            ->and($grid->robustIni())
            ->toBe("$old/bin/Robust.HG.ini")
            ->and($grid->publicPort())
            ->toBe(8102)
            ->and($grid->databaseName())
            ->toBe('oldgrid')
            ->and(array_map(static fn($simulator): string => $simulator->name(), $grid->simulators()))
            ->toBe(['oldgrid.welcome'])
            ->and($grid->simulators()[0]->httpPort())
            ->toBe(9100)
            ->and(array_map(static fn(Grid $g): string => $g->nick(), Grid::all($environment)->items()))
            ->toContain('demo', 'oldgrid')
            ->and([file_get_contents("$old/bin/Robust.HG.ini"), file_get_contents("$old/bin/OpenSim.ini")])
            ->toBe($before);
    });

    test('is enabled in its section only, and started from its own files', function () {
        [$machine, $environment, $old, $conf] = in_place_machine();
        $grid = Grid::find($environment, 'oldgrid');

        expect($grid->isEnabled())->toBeFalse();
        $grid->enable();
        $grid->simulators()[0]->enable();
        $environment = $environment->withLayout(Layout::load($conf, $machine->environment->layout->all()));
        $enabled = array_map(static fn($instance): string => $instance->name() . ' ' . $instance->ini(), OpenSim::enabled($environment));

        expect(InPlaceGrids::section($conf, 'oldgrid'))
            ->toMatchArray(['Enabled' => 'yes', 'EnabledSimulators' => 'welcome'])
            ->and($enabled)
            ->toContain("oldgrid $old/bin/Robust.HG.ini", "oldgrid.welcome $old/bin/OpenSim.ini")
            ->and(glob($machine->etc . '/robust.d/oldgrid*'))
            ->toBe([])
            ->and(Grid::find($environment, 'oldgrid')->isEnabled())
            ->toBeTrue();
    });
});

describe('The settings of the helpers of a grid in place', function () {
    test('go to its section of the config of the suite, never in a file of the install', function () {
        [$machine, $environment, $old, $conf] = in_place_machine();
        $router = new \OpenSim\Api\Router(new \OpenSim\Api\Api($environment));

        $changed = $router->patch('/grids/oldgrid/settings/guide', ['data' => ['type' => 'settings', 'id' => 'guide', 'attributes' => ['guideSource' => 'https://guide.example.org/list.txt']]]);
        $read = $router->get('/grids/oldgrid/settings/guide');

        expect($changed->status)
            ->toBe(200)
            ->and(InPlaceGrids::section($conf, 'oldgrid')['Helpers.GuideSource'] ?? null)
            ->toBe('https://guide.example.org/list.txt')
            ->and($read->document['data']['attributes']['guideSource'] ?? null)
            ->toBe('https://guide.example.org/list.txt')
            ->and(glob("$old/bin/*elpers*"))
            ->toBe([]);
    });
});

describe('A grid of an existing install, through the API', function () {
    test('is registered in place (planned first), then forgotten: only its section is written, the install as it was', function () {
        [$machine, , $old] = in_place_machine();
        $conf = $machine->etc . '/' . Layout::FILE;
        file_put_contents($conf, "[Defaults]\nEtcRoot = {$machine->etc}\n");
        $environment = $machine->environment->withLayout(Layout::load($conf, $machine->environment->layout->all()));
        $router = new \OpenSim\Api\Router(new \OpenSim\Api\Api($environment));
        $ask = static fn(array $more) => $router->post('/setups/imports', ['data' => ['type' => 'imports', 'attributes' => $more + ['kind' => 'robust', 'file' => "$old/bin/Robust.HG.ini", 'inPlace' => true, 'simulators' => [['file' => "$old/bin/OpenSim.ini", 'name' => 'welcome']]]]]);
        $before = file_get_contents("$old/bin/Robust.HG.ini");

        $plan = $ask([]);
        $planned = InPlaceGrids::section($conf, 'oldgrid');
        $applied = $ask(['mode' => 'apply']);
        $section = InPlaceGrids::section($conf, 'oldgrid');
        $forgotten = $ask(['mode' => 'apply', 'forget' => true]);

        expect($plan->status)
            ->toBe(200)
            ->and($plan->document['data']['attributes']['section'])
            ->toMatchArray(['RobustIni' => "$old/bin/Robust.HG.ini", 'CoreDirectory' => $old, 'Simulators' => "welcome:$old/bin/OpenSim.ini"])
            ->and($planned)
            ->toBe([])
            ->and($applied->document['data']['attributes']['applied'])
            ->toBeTrue()
            ->and($section['RobustIni'] ?? null)
            ->toBe("$old/bin/Robust.HG.ini")
            ->and($forgotten->status)
            ->toBe(200)
            ->and(InPlaceGrids::section($conf, 'oldgrid'))
            ->toBe([])
            ->and(file_get_contents("$old/bin/Robust.HG.ini"))
            ->toBe($before);
    });
});
