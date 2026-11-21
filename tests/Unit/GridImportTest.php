<?php

declare(strict_types=1);

/**
 * The grid of another install migrated into this one: the same grid (its database kept as it is), its Robust config made the way
 * this install makes one with the settings of the old one put back, written disabled, then switched once the old Robust is stopped.
 */

use W4OS\Engine\Config\ConfigReader;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\Import\GridImport;
use W4OS\Engine\Provisioning\NullUi;
use W4OS\Engine\Testing\Machine;

/** A machine whose core has the example of Robust, and an old install beside it, with its Robust config. */
function old_robust_install(): array
{
    $machine = new Machine();
    file_put_contents(
        $machine->core . '/bin/Robust.HG.ini.example',
        "[Const]\n    BaseHostname = \"127.0.0.1\"\n    PublicPort = \"8002\"\n    PrivatePort = \"8003\"\n[DatabaseService]\n    ConnectionString = \"Data Source=localhost;Database=opensim;User ID=opensim;Password=***;Old Guids=true;\"\n[GridInfoService]\n    gridname = \"the lost continent of hippo\"\n    gridnick = \"hippogrid\"\n[LoginService]\n    WelcomeMessage = \"Welcome to OpenSim!\"\n    SearchURL = \"\"\n",
    );
    $old = $machine->root . '/old/opensim';
    mkdir("$old/bin", 0o755, true);
    file_put_contents(
        "$old/bin/Robust.HG.ini",
        "[Const]\n    BaseHostname = \"old.example.org\"\n    PublicPort = \"8102\"\n    PrivatePort = \"8103\"\n[Startup]\n    PIDFile = \"$old/bin/Robust.pid\"\n[DatabaseService]\n    ConnectionString = \"Data Source=db.example.org;Database=oldgrid;User ID=olduser;Password=oldpass;Old Guids=true;\"\n[GridInfoService]\n    gridname = \"Old Grid\"\n    gridnick = \"oldgrid\"\n[LoginService]\n    WelcomeMessage = \"Hello from the old grid\"\n",
    );

    return [$machine, "$old/bin/Robust.HG.ini"];
}

/** A dump that writes nothing of a database: the tests have none. */
function grid_import_no_dump(): Closure
{
    return static function (array $database, string $to): void {
        file_put_contents($to, "-- {$database['name']}\n");
    };
}

describe('The import of a grid', function () {
    test('is planned: the same grid and database, the settings of the old one kept, the secrets masked, nothing written', function () {
        [$machine, $robust] = old_robust_install();

        $plan = GridImport::prepare($machine->environment, $robust)->document();
        $kept = array_map(static fn(array $s): string => "{$s['section']}.{$s['key']}", $plan['settings']);

        expect($plan)
            ->toMatchArray(['grid' => 'oldgrid', 'name' => 'Old Grid', 'hypergrid' => true, 'source' => realpath($robust)])
            ->and($plan['database'])
            ->toBe(['host' => 'db.example.org', 'name' => 'oldgrid', 'user' => 'olduser', 'kept' => true])
            ->and($plan['ports'])
            ->toMatchArray(['public' => 8102, 'private' => 8103])
            ->and($kept)
            ->toContain('LoginService.WelcomeMessage')
            ->and(array_column($plan['dropped'], 'reason', 'key'))
            ->toMatchArray(['PIDFile' => 'layout'])
            ->and(array_column($plan['reminders'], 'code'))
            ->toContain('import-stop-grid', 'import-switch')
            ->and(json_encode($plan))
            ->not->toContain('oldpass')
            ->and(is_dir($machine->etc . '/grids/oldgrid'))
            ->toBeFalse();
    });

    test('is written disabled, with the settings kept and a report, the old install untouched', function () {
        [$machine, $robust] = old_robust_install();
        $before = file_get_contents($robust);

        $import = GridImport::prepare($machine->environment, $robust);
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());
        $written = ConfigReader::file($import->plan->robustIni());
        $grid = Grid::find($machine->environment, 'oldgrid');

        expect($grid)
            ->not->toBeNull()
            ->and($grid->isEnabled())
            ->toBeFalse()
            ->and(trim($written['LoginService']['WelcomeMessage'] ?? '', '"'))
            ->toBe('Hello from the old grid')
            ->and($written['DatabaseService']['ConnectionString'] ?? '')
            ->toContain('Database=oldgrid')
            ->and(is_file($import->document()['report']))
            ->toBeTrue()
            ->and(file_get_contents($import->document()['report']))
            ->not->toContain('oldpass')
            ->and(is_file((string) $import->document()['backup']['file']))
            ->toBeTrue()
            ->and(file_get_contents($robust))
            ->toBe($before);
    });

    test('brings its simulators in the same run, planned against the grid it writes first', function () {
        [$machine, $robust] = old_robust_install();
        $bin = dirname($robust);
        mkdir("$bin/Regions");
        file_put_contents("$bin/OpenSim.ini", "[Startup]\n    regionload_regionsdir = \"$bin/Regions\"\n[Network]\n    http_listener_port = 9100\n[DatabaseService]\n    ConnectionString = \"Data Source=db.example.org;Database=oldsim;User ID=olduser;Password=oldpass;Old Guids=true;\"\n");
        file_put_contents("$bin/Regions/Welcome.ini", "[Welcome]\nRegionUUID = 22222222-2222-4222-8222-222222222222\nLocation = 1000,1000\nInternalPort = 9101\n");

        $import = GridImport::prepare($machine->environment, $robust, simulators: [['file' => "$bin/OpenSim.ini", 'name' => 'Welcome']]);
        $plan = $import->document();
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());
        $grid = Grid::find($machine->environment, 'oldgrid');

        expect($plan['problems'])
            ->toBe([])
            ->and(array_column($plan['simulators']['steps'], 'slug'))
            ->toBe(['oldgrid.welcome'])
            ->and(array_map(static fn($s): string => $s->name(), $grid->simulators()))
            ->toContain('oldgrid.welcome')
            ->and($grid->simulator('oldgrid.welcome')?->isEnabled())
            ->toBeFalse();
    });

    test('refuses a grid this install has already', function () {
        [$machine, $robust] = old_robust_install();

        expect(fn() => GridImport::prepare($machine->environment, $robust, 'demo'))->toThrow(InvalidArgumentException::class, 'demo already');
    });

    test('switches only once the old Robust is stopped: then the grid is enabled and started here', function () {
        [$machine, $robust] = old_robust_install();
        $import = GridImport::prepare($machine->environment, $robust);
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());

        // The old Robust holds the public port of the grid
        $machine->processes->listening = [8102];
        expect(fn() => $import->switch($machine->environment, new NullUi()))->toThrow(InvalidArgumentException::class, 'still runs');

        $machine->processes->listening = [];
        $started = $import->switch($machine->environment, new NullUi());

        expect($started)
            ->toBe(['oldgrid'])
            ->and(Grid::find($machine->environment, 'oldgrid')->isEnabled())
            ->toBeTrue();
    });

    test('goes through the API: kind robust, planned, applied, switched', function () {
        [$machine, $robust] = old_robust_install();
        $router = new W4OS\Engine\Api\Router(new W4OS\Engine\Api\Api($machine->environment, dumper: grid_import_no_dump()));
        $ask = static fn(array $more) => $router->post('/setups/imports', ['data' => ['type' => 'imports', 'attributes' => $more + ['kind' => 'robust', 'file' => $robust]]]);

        $plan = $ask([]);
        $applied = $ask(['mode' => 'apply']);
        $again = $ask(['mode' => 'apply']);

        expect($plan->status)
            ->toBe(200)
            ->and($plan->document['data']['attributes'])
            ->toMatchArray(['kind' => 'robust', 'grid' => 'oldgrid', 'applied' => false])
            ->and($applied->document['data']['attributes']['applied'])
            ->toBeTrue()
            ->and($again->status)
            ->toBe(400);
    });
});

describe('The setup from an existing install', function () {
    test('asks its Robust config, shows the plan, then writes the grid disabled and says how to switch', function () {
        [$machine, $robust] = old_robust_install();
        $env = $machine->environment;
        W4OS\Engine\Provisioning\QuickSetup::through(['dumper' => grid_import_no_dump()]);

        $type = W4OS\Engine\Provisioning\QuickSetup::next($env, []);
        $form = W4OS\Engine\Provisioning\QuickSetup::next($env, ['state' => $type['state'], 'answers' => ['type' => 'existing']]);
        $wrong = W4OS\Engine\Provisioning\QuickSetup::next($env, ['state' => $form['state'], 'answers' => ['robust_config' => '/nowhere/Robust.ini']]);
        $review = W4OS\Engine\Provisioning\QuickSetup::next($env, ['state' => $form['state'], 'answers' => ['robust_config' => $robust]]);
        $done = W4OS\Engine\Provisioning\QuickSetup::next($env, ['state' => $review['state'], 'button' => 'next']);
        W4OS\Engine\Provisioning\QuickSetup::through();

        expect(array_column($type['items'], 'key'))
            ->toContain('existing')
            ->and($form['kind'])
            ->toBe('form')
            ->and(array_column($form['fields'], 'name'))
            ->toBe(['robust_config', 'simulator_configs', 'nick'])
            ->and($wrong['kind'])
            ->toBe('form')
            ->and($wrong['notice'])
            ->toContain('cannot read')
            ->and($review['kind'])
            ->toBe('review')
            ->and(implode("\n", array_column($review['rows'], 'value')))
            ->toContain('Old Grid', 'oldgrid @ db.example.org')
            ->and($done['action'])
            ->toBe('quit')
            ->and($done['message'])
            ->toContain('--switch')
            ->and(Grid::find($env, 'oldgrid')?->isEnabled())
            ->toBeFalse();
    });
});
