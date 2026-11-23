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
    test('defines in its config the constants of the old one its kept settings use, and refuses what none defines', function () {
        [$machine, $robust] = old_robust_install();
        $text = str_replace("[Const]\n", "[Const]\n    BaseURL = \"http://old.example.org\"\n    PrivURL = \${Const|BaseURL}\n    WebURL = \"http://site.example.org\"\n", file_get_contents($robust));
        file_put_contents($robust, $text . "[Hypergrid]\n    PrivateHelper = \"\${Const|PrivURL}:\${Const|PrivatePort}/helper\"\n    WelcomeSite = \"\${Const|WebURL}/welcome\"\n");
        $import = GridImport::prepare($machine->environment, $robust);
        $import->apply($machine->environment, new \W4OS\Engine\Provisioning\NullUi(), grid_import_no_dump());
        $written = ConfigReader::file($import->plan->robustIni());

        [$other, $broken] = old_robust_install();
        file_put_contents($broken, file_get_contents($broken) . "[Hypergrid]\n    Lost = \"\${Const|Nowhere}/lost\"\n");
        $failing = GridImport::prepare($other->environment, $broken);
        $failed = null;
        try {
            $failing->apply($other->environment, new \W4OS\Engine\Provisioning\NullUi(), grid_import_no_dump());
        } catch (\W4OS\Engine\Exceptions\InvalidValue $e) {
            $failed = $e;
        }

        expect(trim($written['Const']['PrivURL'] ?? '', '"'))->toBe('${Const|BaseURL}')
            ->and(trim($written['Const']['WebURL'] ?? '', '"'))->toBe('http://site.example.org')
            ->and(\W4OS\Engine\Provisioning\Import\RobustImporter::unresolved([$import->plan->robustIni()], true))->toBe([])
            ->and($failed?->getMessage())->toContain('${Const|Nowhere}')
            ->and(is_dir($failing->plan->gridDir))->toBeFalse();
    });

    /** The old install with folders of data its config names, in its own tree: [machine, its Robust config, its assets folder]. */
    $withData = static function (): array {
        [$machine, $robust] = old_robust_install();
        $old = dirname($robust, 2);
        mkdir("$old/var/data/OldGrid/fsassets/data/ab", 0o755, true);
        mkdir("$old/var/cache/OldGrid/maptiles", 0o755, true);
        file_put_contents("$old/var/data/OldGrid/fsassets/data/ab/abcdef.gz", 'an asset');
        file_put_contents("$old/var/cache/OldGrid/maptiles/map-1.jpg", 'a tile');
        file_put_contents($robust, file_get_contents($robust)
            . "[AssetService]\n    BaseDirectory = \"$old/var/data/\${Launch|MachineName}/fsassets/data\"\n[GridService]\n    MapTileDirectory = \"$old/var/cache/\${Launch|MachineName}/maptiles\"\n");
        rename($robust, dirname($robust) . '/OldGrid.ini');
        $example = $machine->core . '/bin/Robust.HG.ini.example';
        file_put_contents($example, file_get_contents($example) . "[AssetService]\n    BaseDirectory = \"\${Const|DataDirectory}/fsassets/data\"\n[GridService]\n    MapTileDirectory = \"\${Const|CacheDirectory}/maptiles\"\n");

        return [$machine, dirname($robust) . '/OldGrid.ini', $old];
    };

    test('moves the folders of its data where its new config has them, named in the plan without measuring them', function () use ($withData) {
        [$machine, $robust, $old] = $withData();
        $import = GridImport::prepare($machine->environment, $robust);
        $planned = $import->document()['data'];
        $import->apply($machine->environment, new \W4OS\Engine\Provisioning\NullUi(), grid_import_no_dump());

        expect(array_column($planned, 'from'))->toBe(["$old/var/cache/OldGrid/maptiles", "$old/var/data/OldGrid/fsassets/data"])
            ->and(array_column($planned, 'to'))->toBe([$import->plan->cacheDirectory . '/maptiles', $import->plan->dataDirectory . '/fsassets/data'])
            ->and(array_column($planned, 'mode'))->toBe(['move', 'move'])
            ->and(file_get_contents($import->plan->dataDirectory . '/fsassets/data/ab/abcdef.gz'))->toBe('an asset')
            ->and(is_dir("$old/var/data/OldGrid/fsassets/data"))->toBeFalse()
            // The new config reads them there, nothing points to the old install
            ->and(file_get_contents($import->plan->robustIni()))->not->toContain("$old/var");
    });

    test('finds the folders its launcher named after its folder of config, not after the name of its config', function () use ($withData) {
        [$machine, $robust, $old] = $withData();
        // opensim-debian: the instance named after the folder of the config of its grid (ConfigDirectory), kimbe.red
        rename("$old/var/data/OldGrid", "$old/var/data/kimbe.red");
        rename("$old/var/cache/OldGrid", "$old/var/cache/kimbe.red");
        mkdir("$old/etc/kimbe.red", 0o755, true);
        file_put_contents($robust, file_get_contents($robust) . "[Startup]\n    ConfigDirectory = \"$old/etc/kimbe.red\"\n");
        $import = GridImport::prepare($machine->environment, $robust);

        expect(array_column($import->document()['data'], 'from'))->toBe(["$old/var/cache/kimbe.red/maptiles", "$old/var/data/kimbe.red/fsassets/data"]);
    });

    test('copies them instead when asked, the old install left whole', function () use ($withData) {
        [$machine, $robust, $old] = $withData();
        $import = GridImport::prepare($machine->environment, $robust, copyData: true);
        $import->apply($machine->environment, new \W4OS\Engine\Provisioning\NullUi(), grid_import_no_dump());

        expect(array_column($import->document()['data'], 'mode'))->toBe(['copy', 'copy'])
            ->and(file_get_contents($import->plan->dataDirectory . '/fsassets/data/ab/abcdef.gz'))->toBe('an asset')
            ->and(file_get_contents("$old/var/data/OldGrid/fsassets/data/ab/abcdef.gz"))->toBe('an asset');
    });

    test('that fails once its data is moved moves it back', function () use ($withData) {
        [$machine, $robust, $old] = $withData();
        $import = GridImport::prepare($machine->environment, $robust);
        // The place of the second folder has files already: it cannot be brought there
        mkdir($import->plan->dataDirectory . '/fsassets/data', 0o755, true);
        file_put_contents($import->plan->dataDirectory . '/fsassets/data/other', 'x');
        $failed = null;
        try {
            $import->apply($machine->environment, new \W4OS\Engine\Provisioning\NullUi(), grid_import_no_dump());
        } catch (RuntimeException $e) {
            $failed = $e;
        }

        expect($failed?->getMessage())->toContain('has files already')
            ->and(file_get_contents("$old/var/cache/OldGrid/maptiles/map-1.jpg"))->toBe('a tile')
            ->and(file_get_contents("$old/var/data/OldGrid/fsassets/data/ab/abcdef.gz"))->toBe('an asset')
            ->and(is_dir($import->plan->gridDir))->toBeFalse();
    });

    test('moves into this install under its account, never the one of the old install', function () {
        [$machine, $robust] = old_robust_install();
        $conf = $machine->etc . '/' . \W4OS\Engine\Config\Layout::FILE;
        file_put_contents($conf, "[Defaults]\nEtcRoot = {$machine->etc}\nSystemUser = nobody-here\n");
        $router = new \W4OS\Engine\Api\Router(new \W4OS\Engine\Api\Api($machine->environment->withLayout(\W4OS\Engine\Config\Layout::load($conf, $machine->environment->layout->all()))));
        $moved = $router->post('/setups/imports', ['data' => ['type' => 'imports', 'attributes' => ['kind' => 'robust', 'file' => $robust]]])->document['data']['attributes'];

        expect($moved)->not->toHaveKey('accounts')
            ->and(file_get_contents($conf))->toContain('SystemUser = nobody-here');
    });

    test('that fails takes back what it made, and is applied again once the cause is fixed', function () {
        $failing = static fn(array $database, string $to) => throw new RuntimeException("cannot connect to {$database['name']}");
        $failed = null;
        [$machine, $robust] = old_robust_install();
        file_put_contents($robust, str_replace('Data Source=db.example.org;', 'Data Source=localhost;', file_get_contents($robust)));
        $import = GridImport::prepare($machine->environment, $robust);
        try {
            $import->apply($machine->environment, new \W4OS\Engine\Provisioning\NullUi(), $failing);
        } catch (RuntimeException $e) {
            $failed = $e;
        }
        $left = is_dir($import->plan->gridDir);
        $again = GridImport::prepare($machine->environment, $robust);
        $again->apply($machine->environment, new \W4OS\Engine\Provisioning\NullUi(), grid_import_no_dump());

        expect($failed?->getMessage())->toContain('cannot connect to oldgrid')
            ->and($left)->toBeFalse()
            ->and(is_file($again->plan->robustIni()))->toBeTrue();
    });

    test('backs up the databases of this machine, and names the ones of another server without ever failing on them', function () {
        [$machine, $robust] = old_robust_install();
        file_put_contents($robust, str_replace('Data Source=db.example.org;', 'Data Source=localhost;', file_get_contents($robust))
            . "[Gloebit]\n    GLBSpecificConnectionString = \"Data Source=glb.example.org;Database=osgloebit;User ID=glb;Password=glbpass;\"\n");
        $dumped = [];
        $dump = static function (array $database, string $to) use (&$dumped): void {
            $database['host'] === 'localhost' || throw new RuntimeException("cannot connect to {$database['name']}");
            $dumped[] = $database['name'];
            file_put_contents($to, "-- {$database['name']}\n");
        };
        $import = GridImport::prepare($machine->environment, $robust);
        $import->apply($machine->environment, new \W4OS\Engine\Provisioning\NullUi(), $dump);

        expect($import->document()['backup'])->toMatchArray(['databases' => ['oldgrid'], 'remote' => ['osgloebit @ glb.example.org']])
            ->and($dumped)->toBe(['oldgrid'])
            ->and(is_file((string) $import->document()['backup']['file']))->toBeTrue();
    });

    test('takes the nick of its config made a nick here, as an import in place does', function () {
        [$machine, $robust] = old_robust_install();
        file_put_contents($robust, str_replace('gridnick = "oldgrid"', 'gridnick = "W4osDemoGrid"', file_get_contents($robust)));

        expect(GridImport::prepare($machine->environment, $robust)->document()['grid'])->toBe('w4osdemogrid');
    });

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
        // Its Robust does not start: said, never taken for started
        $machine->launcher->exitsAtOnce = true;
        $results = $import->switch($machine->environment, new NullUi());

        expect(array_keys($results))
            ->toBe(['oldgrid'])
            ->and($results['oldgrid'])->toBeInstanceOf(\W4OS\Engine\Exceptions\InstanceFailed::class)
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
