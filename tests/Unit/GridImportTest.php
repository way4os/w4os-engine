<?php

declare(strict_types=1);

/**
 * The grid of another install migrated into this one: the same grid (its database kept as it is), its Robust config made the way
 * this install makes one with the settings of the old one put back, written disabled, then switched once the old Robust is stopped.
 */

use W4OS\Engine\Config\ConfigReader;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\Import\DataMoves;
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
    /** kimbe-red: the folder of its config holds the GridCommon of its simulators, which Robust does not read; its places named after its launcher. */
    $kimbe = static function (bool $literal = true, string $configs = 'kimbe.red'): array {
        [$machine, $robust] = old_robust_install();
        $old = dirname($robust, 2);
        mkdir("$old/var/data/kimbe.red/fsassets/data/ab", 0o755, true);
        file_put_contents("$old/var/data/kimbe.red/fsassets/data/ab/abcdef.gz", 'an asset');
        mkdir("$old/etc/$configs", 0o755, true);
        file_put_contents("$old/etc/$configs/KimbeRed-GridCommon.ini", "[Const]\n    BaseHostname = \"other.example.org\"\n    BaseURL = \"http://other.example.org\"\n    WebURL = \"http://other.example.org\"\n    DataDirectory = \"$old/var/data/\${Launch|MachineName}\"\n[Startup]\n    ConfigDirectory = /etc/opensim/\${Const|BaseHostname}\n[GridInfoService]\n    welcome = \${Const|WebURL}\n");
        $text = file_get_contents($robust);
        $text = str_replace("[Const]\n", "[Const]\n    BaseURL = \"http://old.example.org\"\n" . ($literal ? "    DataDirectory = \"$old/var/data/kimbe.red\"\n" : "    DataDirectory = \"$old/var/data/\${Launch|MachineName}\"\n"), $text);
        $text = str_replace("[GridInfoService]\n", "[GridInfoService]\n    welcome = \"\${Const|BaseURL}/opensim/welcome\"\n", $text);
        file_put_contents($robust, $text . "[Startup]\n    ConfigDirectory = \"$old/etc/$configs\"\n[AssetService]\n    LocalServiceModule = \"OpenSim.Services.FSAssetService.dll:FSAssetConnector\"\n    BaseDirectory = \"\${Const|DataDirectory}/fsassets/data\"\n");
        $example = $machine->core . '/bin/Robust.HG.ini.example';
        file_put_contents($example, file_get_contents($example) . "[AssetService]\n    BaseDirectory = \"\${Const|DataDirectory}/fsassets/data\"\n");

        return [$machine, $robust, $old];
    };

    test('of kimbe-red moves its assets, the folder of its config never changing its own constants', function () use ($kimbe) {
        [$machine, $robust, $old] = $kimbe();
        $import = GridImport::prepare($machine->environment, $robust, 'kimberedcharm');
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());

        expect(array_column($import->document()['data'], 'from'))->toBe(["$old/var/data/kimbe.red/fsassets/data"])
            ->and(file_get_contents($import->plan->dataDirectory . '/fsassets/data/ab/abcdef.gz'))->toBe('an asset')
            // Its grid info its own, never the one of the simulators beside it
            ->and(ConfigReader::load($robust, [], false)['GridInfoService']['welcome'] ?? '')->toBe('${Const|BaseURL}/opensim/welcome')
            ->and(ConfigReader::expand(ConfigReader::file($import->plan->robustIni()))['GridInfoService']['welcome'] ?? '')->toBe('http://old.example.org/opensim/welcome');
    });

    test('refuses the data it cannot tell, never leaves it behind silently', function () use ($kimbe) {
        // Its launcher named after nothing this config knows: the folder of its config is another
        [$machine, $robust, $old] = $kimbe(literal: false, configs: 'common');
        $import = GridImport::prepare($machine->environment, $robust, 'kimberedcharm');

        expect($import->document()['data'])->toBe([])
            ->and($import->document()['problems']['data'] ?? '')->toContain('cannot tell which folder [AssetService] BaseDirectory is', "$old/var/data/kimbe.red");
    });

    /** Its assets of FSAssets in the folder it runs in, `./fsassets/data`; the new config names their place, or not. */
    $relative = static function (): array {
        [$machine, $robust] = old_robust_install();
        $old = dirname($robust, 2);
        mkdir("$old/bin/fsassets/data/ab", 0o755, true);
        file_put_contents("$old/bin/fsassets/data/ab/abcdef.gz", 'an asset');
        file_put_contents($robust, file_get_contents($robust) . "[AssetService]\n    LocalServiceModule = \"OpenSim.Services.FSAssetService.dll:FSAssetConnector\"\n    BaseDirectory = \"./fsassets/data\"\n");
        $example = $machine->core . '/bin/Robust.HG.ini.example';
        file_put_contents($example, file_get_contents($example) . "[AssetService]\n    BaseDirectory = \"\${Const|DataDirectory}/fsassets/data\"\n");

        return [$machine, $robust, $old];
    };

    test('moves its assets of a place relative to the folder it runs in', function () use ($relative) {
        [$machine, $robust, $old] = $relative();
        $import = GridImport::prepare($machine->environment, $robust, 'relativegrid');
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());

        expect(array_column($import->document()['data'], 'from'))->toBe(["$old/bin/fsassets/data"])
            ->and(file_get_contents($import->plan->dataDirectory . '/fsassets/data/ab/abcdef.gz'))->toBe('an asset');
    });

    test('refuses to go when data that must come would stay behind', function () {
        $old = test_tmp() . '/old-' . bin2hex(random_bytes(4));
        mkdir("$old/bin/fsassets/data/ab", 0o755, true);
        file_put_contents("$old/bin/fsassets/data/ab/abcdef.gz", 'an asset');
        $required = ['[AssetService] BaseDirectory' => './fsassets/data'];

        $left = DataMoves::plan([], ['AssetService' => ['BaseDirectory' => '/new/data/fsassets/data']], [], false, "$old/bin", $required);
        $inPlace = DataMoves::plan([], ['AssetService' => ['BaseDirectory' => "$old/bin/fsassets/data"]], [], false, "$old/bin", $required);

        expect($left->problems())->toBe(["[AssetService] BaseDirectory of the old install is $old/bin/fsassets/data, which holds data the import does not bring to /new/data/fsassets/data"])
            ->and($inPlace->problems())->toBe([]);
    });

    test('makes the grid info it keeps ASCII, said in its plan and its report: OpenSim cannot send other characters', function () {
        [$machine, $robust] = old_robust_install();
        file_put_contents($robust, str_replace('gridname = "Old Grid"', 'gridname = "Kimbé Rèd, pa moli"', file_get_contents($robust)));
        $import = GridImport::prepare($machine->environment, $robust, 'kimbered');
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());
        $written = ConfigReader::file($import->plan->robustIni());

        expect($import->plan->gridName)->toBe('Kimbe Red, pa moli')
            ->and($import->document()['gridInfo'])->toBe(["[GridInfoService] gridname 'Kimbé Rèd, pa moli' -> 'Kimbe Red, pa moli': OpenSim's grid info cannot send non-ASCII characters"])
            ->and(trim($written['GridInfoService']['gridname'] ?? '', '"'))->toBe('Kimbe Red, pa moli')
            ->and(file_get_contents($import->document()['report']))->toContain('Grid info made ASCII:');
    });

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
            ->and(\W4OS\Engine\Provisioning\Import\RobustImporter::unresolved([$import->plan->robustIni()]))->toBe([])
            ->and($failed?->getMessage())->toContain('${Const|Nowhere}')
            ->and(is_dir($failing->plan->gridDir))->toBeFalse();
    });

    test('leaves the paths of the install to the kit, said corrected in its report: the old ones are not there', function () {
        [$machine, $robust] = old_robust_install();
        $old = dirname($robust, 2);
        $example = $machine->core . '/bin/Robust.HG.ini.example';
        file_put_contents($example, file_get_contents($example) . "[AssetService]\n    AssetLoaderArgs = \"./assets/AssetSets.xml\"\n[LibraryService]\n    DefaultLibrary = \"./inventory/Libraries.xml\"\n");
        $text = str_replace("[Const]\n", "[Const]\n    EtcDirectory = \"$old/etc\"\n", file_get_contents($robust));
        file_put_contents($robust, $text . "[AssetService]\n    AssetLoaderArgs = \"\${Const|EtcDirectory}/assets/AssetSets.xml\"\n[LibraryService]\n    DefaultLibrary = \"\${Const|EtcDirectory}/inventory/Libraries.xml\"\n");
        $import = GridImport::prepare($machine->environment, $robust, 'pathgrid');
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());
        $read = ConfigReader::load($import->plan->robustIni());
        $report = (string) file_get_contents($import->document()['report']);
        $data = $import->plan->dataDirectory;

        expect($read['AssetService']['AssetLoaderArgs'])->toBe("$data/assets/AssetSets.xml")
            ->and($read['LibraryService']['DefaultLibrary'])->toBe("$data/inventory/Libraries.xml")
            ->and($report)->toContain(
                '[AssetService] AssetLoaderArgs = ${Const|EtcDirectory}/assets/AssetSets.xml (corrected, now ${Const|DataDirectory}/assets/AssetSets.xml: a path of the install)',
                '[LibraryService] DefaultLibrary = ${Const|EtcDirectory}/inventory/Libraries.xml (corrected, now ${Const|DataDirectory}/inventory/Libraries.xml: a path of the install)',
            );
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

    test('says the folders it cannot see into, never takes them for absent', function () use ($withData) {
        [$machine, $robust, $old] = $withData();
        chmod("$old/var/data", 0o000);
        $import = GridImport::prepare($machine->environment, $robust);
        chmod("$old/var/data", 0o755);

        expect(array_column($import->document()['data'], 'from'))->toBe(["$old/var/cache/OldGrid/maptiles"])
            ->and($import->document()['problems']['data'] ?? '')->toContain("cannot see whether $old/var/data/", 'run the import as root');
    })->skip(fn() => posix_geteuid() === 0, 'root sees everything');

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

    test('keeps the offline messages of its simulators, V1 and a helper outside this install: never switched to V2', function () {
        [$machine, $robust] = old_robust_install();
        // A core with its defaults: the grid has a master, which gives its simulators what the grid decides
        $machine->write($machine->core . '/bin/OpenSimDefaults.ini', "[Startup]\n    Other = core\n");
        $machine->write($machine->core . '/bin/OpenSim.ini.example', "[Startup]\n;Other = example\n");
        $bin = dirname($robust);
        $common = dirname($bin) . '/etc/kimbe/GridCommon.ini';
        mkdir(dirname($common), 0o755, true);
        // The simulators and the Robust read the same file of the grid (the folder of its config), V1 and its helper elsewhere
        file_put_contents($common, "[Messaging]\n    OfflineMessageModule = OfflineMessageModule\n    OfflineMessageURL = \"https://im.example.org/helper/offline.php\"\n    ForwardOfflineGroupMessages = false\n");
        file_put_contents($robust, file_get_contents($robust) . "[Startup]\n    ConfigDirectory = \"" . dirname($common) . "\"\n");
        mkdir("$bin/Regions");
        file_put_contents("$bin/OpenSim.ini", "[Startup]\n    regionload_regionsdir = \"$bin/Regions\"\n[Network]\n    http_listener_port = 9100\n[DatabaseService]\n    ConnectionString = \"Data Source=db.example.org;Database=oldsim;User ID=olduser;Password=oldpass;Old Guids=true;\"\n[Includes]\n    Include-Common = \"$common\"\n");
        file_put_contents("$bin/Regions/Welcome.ini", "[Welcome]\nRegionUUID = 22222222-2222-4222-8222-222222222222\nLocation = 1000,1000\nInternalPort = 9101\n");

        $import = GridImport::prepare($machine->environment, $robust, simulators: [['file' => "$bin/OpenSim.ini", 'name' => 'Welcome']], helpersHere: false);
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());
        $grid = Grid::find($machine->environment, 'oldgrid');
        $sim = $grid->simulator('oldgrid.welcome')->controller();
        $master = W4OS\Engine\Provisioning\SimulatorMaster::path($grid->directory());
        $read = ConfigReader::expand(ConfigReader::load([$master, ...$sim->files()], [], false, false, $sim->lastFiles()));
        $settings = (new W4OS\Engine\Models\GridSettings($grid))->read('offline');
        $report = (string) file_get_contents($import->document()['simulators']['steps'][0]['report']);

        // What the simulators run (the master of the grid, then their own file): the module V1, its helper, the group messages not forwarded
        expect(is_file($master))->toBeTrue()
            ->and(array_intersect_key($read['Messaging'] ?? [], array_flip(['OfflineMessageModule', 'OfflineMessageURL', 'ForwardOfflineGroupMessages'])))
            ->toBe(['OfflineMessageModule' => 'OfflineMessageModule', 'OfflineMessageURL' => 'https://im.example.org/helper/offline.php', 'ForwardOfflineGroupMessages' => 'false'])
            ->and((new W4OS\Engine\Provisioning\GridOptions($grid))->enabled())->toContain('offline-helpers')->not->toContain('offline-robust')
            // Its settings say what the simulators run, read where they read it, not in the Robust, which does not use them
            ->and([$settings['offlineModule'] ?? null, $settings['offlineMessageUrl'] ?? null])->toBe(['OfflineMessageModule', 'https://im.example.org/helper/offline.php'])
            ->and((string) file_get_contents($import->plan->robustIni()))->not->toContain('OfflineMessageModule')
            // The report of the simulator says they are the grid's, never replaced
            ->and($report)->toContain('[Messaging] OfflineMessageModule = OfflineMessageModule (grid')->not->toContain('OfflineMessageModule = OfflineMessageModule (replaced');
    });

    test('keeps the economy of its simulators, Gloebit and its keys, its module where an old config has it ([Startup])', function () {
        [$machine, $robust] = old_robust_install();
        $machine->write($machine->core . '/bin/OpenSimDefaults.ini', "[Startup]\n    Other = core\n");
        $machine->write($machine->core . '/bin/OpenSim.ini.example', "[Startup]\n;Other = example\n");
        $bin = dirname($robust);
        mkdir("$bin/Regions");
        // The money module where OpenSimulator reads it first, [Startup], and the settings of Gloebit
        file_put_contents("$bin/OpenSim.ini", "[Startup]\n    EconomyModule = Gloebit\n    regionload_regionsdir = \"$bin/Regions\"\n[Network]\n    http_listener_port = 9100\n" .
            "[DatabaseService]\n    ConnectionString = \"Data Source=db.example.org;Database=oldsim;User ID=olduser;Password=oldpass;Old Guids=true;\"\n" .
            "[Gloebit]\n    Enabled = true\n    GLBEnvironment = production\n    GLBKey = kimbe-key\n    GLBSecret = kimbe-secret\n    GLBOwnerName = \"Pam Molly\"\n");
        file_put_contents("$bin/Regions/Welcome.ini", "[Welcome]\nRegionUUID = 22222222-2222-4222-8222-222222222222\nLocation = 1000,1000\nInternalPort = 9101\n");

        $import = GridImport::prepare($machine->environment, $robust, simulators: [['file' => "$bin/OpenSim.ini", 'name' => 'Welcome']]);
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());
        $grid = Grid::find($machine->environment, 'oldgrid');
        $sim = $grid->simulator('oldgrid.welcome')->controller();
        $master = W4OS\Engine\Provisioning\SimulatorMaster::path($grid->directory());
        $read = ConfigReader::expand(ConfigReader::load([$master, ...$sim->files()], [], false, false, $sim->lastFiles()));
        // As SampleMoneyModule and Gloebit read it: [Startup] first, then [Economy]
        $module = ($read['Startup']['economymodule'] ?? $read['Startup']['EconomyModule'] ?? '') ?: ($read['Economy']['economymodule'] ?? '');
        $dropped = $import->document()['simulators']['steps'][0]['dropped'] ?? [];
        $economyAsLayout = array_filter($dropped, static fn(array $d): bool => $d['reason'] === 'layout' && stripos($d['key'], 'economy') !== false);

        expect($module)->toBe('Gloebit')
            ->and((new W4OS\Engine\Provisioning\GridOptions($grid))->enabled())->toContain('economy-gloebit')
            ->and(array_intersect_key($read['Gloebit'] ?? [], array_flip(['GLBEnvironment', 'GLBKey', 'GLBSecret', 'GLBOwnerName'])))
            ->toBe(['GLBEnvironment' => 'production', 'GLBKey' => 'kimbe-key', 'GLBSecret' => 'kimbe-secret', 'GLBOwnerName' => 'Pam Molly'])
            ->and((new W4OS\Engine\Models\GridSettings($grid))->read('economy')['economyModule'] ?? null)->toBe('Gloebit')
            ->and($economyAsLayout)->toBe([]);
    });

    test('reads what OpenSimulator reads, never a file of a folder of config nothing includes (kimbe-red: Gloebit left aside)', function () {
        [$machine, $robust] = old_robust_install();
        $machine->write($machine->core . '/bin/OpenSimDefaults.ini', "[Startup]\n    Other = core\n");
        $machine->write($machine->core . '/bin/OpenSim.ini.example', "[Startup]\n;Other = example\n");
        $bin = dirname($robust);
        $configs = dirname($bin) . '/etc/kimbe.red';
        mkdir($configs, 0o755, true);
        // The folder of config of Robust and of the simulators holds a Gloebit file that neither includes
        file_put_contents("$configs/KimbeGrid.Gloebit.ini", "[Economy]\n    economymodule = Gloebit\n[Gloebit]\n    Enabled = true\n    GLBEnvironment = production\n    GLBKey = kimbe-key\n");
        file_put_contents($robust, file_get_contents($robust) . "[Startup]\n    ConfigDirectory = \"$configs\"\n");
        mkdir("$bin/Regions");
        file_put_contents("$bin/OpenSim.ini", "[Startup]\n    ConfigDirectory = \"$configs\"\n    regionload_regionsdir = \"$bin/Regions\"\n[Network]\n    http_listener_port = 9100\n" .
            "[DatabaseService]\n    ConnectionString = \"Data Source=db.example.org;Database=oldsim;User ID=olduser;Password=oldpass;Old Guids=true;\"\n");
        file_put_contents("$bin/Regions/Welcome.ini", "[Welcome]\nRegionUUID = 22222222-2222-4222-8222-222222222222\nLocation = 1000,1000\nInternalPort = 9101\n");

        $import = GridImport::prepare($machine->environment, $robust, simulators: [['file' => "$bin/OpenSim.ini", 'name' => 'Welcome']], helpersHere: false);
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());
        $grid = Grid::find($machine->environment, 'oldgrid');
        $sim = $grid->simulator('oldgrid.welcome')->controller();
        $read = ConfigReader::load([W4OS\Engine\Provisioning\SimulatorMaster::path($grid->directory()), ...$sim->files()], [], false, false, $sim->lastFiles());
        $plan = $import->document();
        $gloebit = static fn(array $settings): array => array_filter($settings, static fn(array $s): bool => in_array($s['section'], ['Gloebit', 'Economy'], true));

        expect((new W4OS\Engine\Provisioning\GridOptions($grid))->enabled())->not->toContain('economy-gloebit')
            ->and($read['Economy']['economymodule'] ?? '')->not->toBe('Gloebit')
            ->and($read['Gloebit']['GLBKey'] ?? null)->toBeNull()
            ->and([...$gloebit($plan['settings']), ...$gloebit($plan['dropped'])])->toBe([])
            ->and((string) file_get_contents($import->plan->robustIni()))->not->toContain('[Gloebit]')
            ->and((string) file_get_contents($plan['report']))->not->toContain('Gloebit');
    });

    test('leaves Gloebit off when its simulators load it without its keys, and says it in the report', function () {
        [$machine, $robust] = old_robust_install();
        $machine->write($machine->core . '/bin/OpenSimDefaults.ini', "[Startup]\n    Other = core\n");
        $machine->write($machine->core . '/bin/OpenSim.ini.example', "[Startup]\n;Other = example\n");
        $bin = dirname($robust);
        $common = dirname($bin) . '/etc/grids/KimbeRed/GridCommon.ini';
        mkdir(dirname($common), 0o755, true);
        // Loaded through the file the simulators include, its keys given nowhere
        file_put_contents($common, "[Economy]\n    economymodule = Gloebit\n[Gloebit]\n    Enabled = true\n    GLBEnvironment = production\n");
        mkdir("$bin/Regions");
        file_put_contents("$bin/OpenSim.ini", "[Startup]\n    regionload_regionsdir = \"$bin/Regions\"\n[Network]\n    http_listener_port = 9100\n" .
            "[DatabaseService]\n    ConnectionString = \"Data Source=db.example.org;Database=oldsim;User ID=olduser;Password=oldpass;Old Guids=true;\"\n[Includes]\n    Include-Common = \"$common\"\n");
        file_put_contents("$bin/Regions/Welcome.ini", "[Welcome]\nRegionUUID = 22222222-2222-4222-8222-222222222222\nLocation = 1000,1000\nInternalPort = 9101\n");

        $import = GridImport::prepare($machine->environment, $robust, simulators: [['file' => "$bin/OpenSim.ini", 'name' => 'Welcome']], helpersHere: false);
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());
        $grid = Grid::find($machine->environment, 'oldgrid');
        $sim = $grid->simulator('oldgrid.welcome')->controller();
        $read = ConfigReader::expand(ConfigReader::load([W4OS\Engine\Provisioning\SimulatorMaster::path($grid->directory()), ...$sim->files()], [], false, false, $sim->lastFiles()));
        $module = ($read['Startup']['economymodule'] ?? '') ?: ($read['Economy']['economymodule'] ?? '');

        expect((new W4OS\Engine\Provisioning\GridOptions($grid))->enabled())->not->toContain('economy-gloebit')
            ->and(strtolower($module))->not->toBe('gloebit')
            ->and($read['Gloebit']['Enabled'] ?? null)->not->toBe('true')
            ->and((string) file_get_contents($import->document()['report']))->toContain('economy-gloebit', 'GLBKey', 'not turned on');
    });

    /** An old install whose simulators keep their offline messages V1 with the helper at $url: [machine, Robust config, simulators]. */
    $offlineV1 = static function (string $url): array {
        [$machine, $robust] = old_robust_install();
        $machine->write($machine->core . '/bin/OpenSimDefaults.ini', "[Startup]\n    Other = core\n");
        $machine->write($machine->core . '/bin/OpenSim.ini.example', "[Startup]\n;Other = example\n");
        file_put_contents($robust, str_replace("[Const]\n", "[Const]\n    WebURL = \"https://old.example.org\"\n", file_get_contents($robust)));
        $bin = dirname($robust);
        mkdir("$bin/Regions");
        file_put_contents("$bin/OpenSim.ini", "[Startup]\n    regionload_regionsdir = \"$bin/Regions\"\n[Network]\n    http_listener_port = 9100\n" .
            "[DatabaseService]\n    ConnectionString = \"Data Source=db.example.org;Database=oldsim;User ID=olduser;Password=oldpass;Old Guids=true;\"\n" .
            "[Messaging]\n    OfflineMessageModule = OfflineMessageModule\n    OfflineMessageURL = \"$url\"\n");
        file_put_contents("$bin/Regions/Welcome.ini", "[Welcome]\nRegionUUID = 22222222-2222-4222-8222-222222222222\nLocation = 1000,1000\nInternalPort = 9101\n");

        return [$machine, $robust, [['file' => "$bin/OpenSim.ini", 'name' => 'Welcome']]];
    };
    $offlineUrl = static function (Machine $machine): string {
        $grid = Grid::find($machine->environment, 'oldgrid');
        $sim = $grid->simulator('oldgrid.welcome')->controller();
        $master = W4OS\Engine\Provisioning\SimulatorMaster::path($grid->directory());

        return (string) (ConfigReader::expand(ConfigReader::load([$master, ...$sim->files()], [], false, false, $sim->lastFiles()))['Messaging']['OfflineMessageURL'] ?? '');
    };

    test('leaves out what no core of the kit reads, said in the report: the MuteListURL of the helpers of before', function () use ($offlineV1) {
        [$machine, $robust, $simulators] = $offlineV1('https://im.example.org/helper/offline.php');
        file_put_contents($simulators[0]['file'], file_get_contents($simulators[0]['file']) . "    MuteListURL = \"https://old.example.org/mute.php\"\n");
        $import = GridImport::prepare($machine->environment, $robust, simulators: $simulators, helpersHere: false);
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());
        $grid = Grid::find($machine->environment, 'oldgrid');
        $sim = $grid->simulator('oldgrid.welcome')->controller();
        $report = (string) file_get_contents($import->document()['simulators']['steps'][0]['report']);

        expect((string) file_get_contents($grid->directory() . '/OpenSim.ini'))->not->toContain('MuteListURL')
            ->and((string) file_get_contents($sim->ini()))->not->toContain('MuteListURL')
            ->and($report)->toContain('[Messaging] MuteListURL = https://old.example.org/mute.php (retired, now')
            // What else the grid has of the module V1 stays
            ->and((string) file_get_contents($grid->directory() . '/OpenSim.ini'))->toContain('OfflineMessageURL');
    });

    test('turns its helpers on when a choice it keeps needs them and this install serves them, and says it', function () use ($offlineV1, $offlineUrl) {
        // V1 and its helper on the site of the grid, the helpers installed here
        [$machine, $robust, $simulators] = $offlineV1('https://old.example.org/helpers/helper/offline.php');
        $import = GridImport::prepare($machine->environment, $robust, simulators: $simulators, helpersHere: true);
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());
        $grid = Grid::find($machine->environment, 'oldgrid');
        $report = (string) file_get_contents($import->document()['report']);

        expect($import->plan->helpers)->toBeTrue()
            // Written, its helpers are its Helpers.ini and the addresses of their services
            ->and(is_file(W4OS\Engine\Provisioning\Web\HelpersConfig::path($grid->directory())))->toBeTrue()
            ->and($grid->helperUrl('offline'))->toStartWith('https://old.example.org/')
            ->and((new W4OS\Engine\Provisioning\GridOptions($grid))->enabled())->toContain('offline-helpers')->not->toContain('offline-robust')
            // The helpers of this install serve it where the old one had it: its address is never rewritten (Grid::helperPaths())
            ->and($offlineUrl($machine))->toBe('https://old.example.org/helpers/helper/offline.php')
            ->and($grid->servedByHelpers('https://old.example.org/helpers/helper/offline.php/SaveMessage/'))->toBeTrue()
            ->and($report)->toContain('Helpers on: offline-helpers needs them, and this install serves them (w4os-helpers)');
    });

    test('of kimbe-red keeps every address of its helpers as it is, its four prefixes, its web search made a page, and says what it adds', function () {
        [$machine, $robust] = old_robust_install();
        $machine->write($machine->core . '/bin/OpenSimDefaults.ini', "[Startup]\n    Other = core\n");
        $machine->write($machine->core . '/bin/OpenSim.ini.example', "[Startup]\n;Other = example\n");
        $text = str_replace("[Const]\n", "[Const]\n    WebURL = \"https://old.example.org\"\n    BaseURL = \"http://old.example.org\"\n", file_get_contents($robust));
        // The login gives Robust itself as its search page
        $text = str_replace("[LoginService]\n", "[LoginService]\n    SearchURL = \"\${Const|BaseURL}:\${Const|PublicPort}/\"\n", $text);
        $text = str_replace("[GridInfoService]\n", "[GridInfoService]\n    search = \"\${Const|WebURL}/helper/query.php\"\n    message = \"\${Const|WebURL}/helper/offline.php\"\n" .
            "    economy = \"\${Const|WebURL}/opensim/economy\"\n    welcome = \"\${Const|WebURL}/opensim/welcome\"\n", $text);
        file_put_contents($robust, $text);
        $bin = dirname($robust);
        mkdir("$bin/Regions");
        // Its simulators: the module V1 and the search at the addresses of the helpers of before
        file_put_contents("$bin/OpenSim.ini", "[Const]\n    WebURL = \"https://old.example.org\"\n[Startup]\n    regionload_regionsdir = \"$bin/Regions\"\n[Network]\n    http_listener_port = 9100\n" .
            "[DatabaseService]\n    ConnectionString = \"Data Source=db.example.org;Database=oldsim;User ID=olduser;Password=oldpass;Old Guids=true;\"\n" .
            "[Messaging]\n    OfflineMessageModule = OfflineMessageModule\n    OfflineMessageURL = \"\${Const|WebURL}/helpers/helper/offline.php\"\n" .
            "[Search]\n    Module = OpenSimSearch\n    SearchURL = \"\${Const|WebURL}/helpers/query.php\"\n");
        file_put_contents("$bin/Regions/Welcome.ini", "[Welcome]\nRegionUUID = 22222222-2222-4222-8222-222222222222\nLocation = 1000,1000\nInternalPort = 9101\n");

        $import = GridImport::prepare($machine->environment, $robust, simulators: [['file' => "$bin/OpenSim.ini", 'name' => 'Welcome']], helpersHere: true);
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());
        $grid = Grid::find($machine->environment, 'oldgrid');
        $sim = $grid->simulator('oldgrid.welcome')->controller();
        $master = W4OS\Engine\Provisioning\SimulatorMaster::path($grid->directory());
        $runs = ConfigReader::expand(ConfigReader::load([$master, ...$sim->files()], [], false, false, $sim->lastFiles()));
        $read = ConfigReader::expand(ConfigReader::load($grid->robustIni(), [], false));
        $info = $read['GridInfoService'] ?? [];
        $report = (string) file_get_contents($import->document()['report']);
        $options = (new W4OS\Engine\Provisioning\GridOptions($grid))->enabled();

        // What the simulators and the viewers are told, as the old install told them
        expect([$runs['Messaging']['OfflineMessageURL'] ?? null, $runs['Search']['SearchURL'] ?? null])
            ->toBe(['https://old.example.org/helpers/helper/offline.php', 'https://old.example.org/helpers/query.php'])
            ->and(array_intersect_key($info, array_flip(['message', 'economy', 'welcome'])))
            ->toEqual(['message' => 'https://old.example.org/helper/offline.php', 'economy' => 'https://old.example.org/opensim/economy', 'welcome' => 'https://old.example.org/opensim/welcome'])
            // The web search the viewers open: a page of this install, not the search the simulators call nor Robust, said
            ->and($info['search'] ?? '')->toStartWith('https://old.example.org/')->not->toContain('query.php')
            ->and($read['LoginService']['SearchURL'] ?? '')->toBe($info['search'] ?? '')
            ->and($report)->toContain('[GridInfoService] search = ${Const|WebURL}/helper/query.php (corrected', 'XML-RPC', '[LoginService] SearchURL = ${Const|BaseURL}:${Const|PublicPort}/ (corrected', 'the address of Robust')
            // Served where they are
            ->and($grid->servedByHelpers('https://old.example.org/helper/offline.php'))->toBeTrue()
            // The three configs of the web servers, each saying what to add to its server (3bd3129), as a setup makes them
            ->and($options)->toContain('webserver-caddy', 'webserver-apache', 'webserver-nginx')
            // What the kit adds, the old one had not: said as added, with its value
            ->and($report)->toContain('added by this install');
    });

    test('keeps a web search that is no page when this install has none to give, the health saying it', function () {
        [$machine, $robust] = old_robust_install();
        file_put_contents($robust, str_replace("[GridInfoService]\n", "[GridInfoService]\n    search = \"https://old.example.org/helper/query.php\"\n", file_get_contents($robust)));

        // Without helpers here, this install gives no web search
        $import = GridImport::prepare($machine->environment, $robust);
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());
        $info = ConfigReader::expand(ConfigReader::load($import->plan->robustIni(), [], false))['GridInfoService'] ?? [];

        expect($info['search'] ?? null)->toBe('https://old.example.org/helper/query.php')
            ->and(array_column(array_filter($import->document()['dropped'], static fn(array $s): bool => $s['key'] === 'search'), 'reason'))->not->toContain('corrected');
    });

    test('of kimbe-red keeps its addresses through its own constants (BackOffice), and adds its pages under the default base of the helpers', function () {
        [$machine, $robust] = old_robust_install();
        $machine->write($machine->core . '/bin/OpenSimDefaults.ini', "[Startup]\n    Other = core\n");
        $machine->write($machine->core . '/bin/OpenSim.ini.example', "[Startup]\n;Other = example\n");
        $text = str_replace("[Const]\n", "[Const]\n    WebURL = \"https://old.example.org\"\n    BackOffice = \"\${Const|WebURL}/helper\"\n", file_get_contents($robust));
        $text = str_replace("[GridInfoService]\n", "[GridInfoService]\n    search = \"\${Const|BackOffice}/query.php\"\n    economy = \"\${Const|WebURL}/opensim/economy\"\n", $text);
        file_put_contents($robust, $text . "\n[Search]\n    SearchURL = \"\${Const|BackOffice}/query.php\"\n");
        $bin = dirname($robust);
        mkdir("$bin/Regions");
        file_put_contents("$bin/OpenSim.ini", "[Const]\n    WebURL = \"https://old.example.org\"\n[Startup]\n    regionload_regionsdir = \"$bin/Regions\"\n[Network]\n    http_listener_port = 9100\n" .
            "[DatabaseService]\n    ConnectionString = \"Data Source=db.example.org;Database=oldsim;User ID=olduser;Password=oldpass;Old Guids=true;\"\n" .
            "[Messaging]\n    OfflineMessageModule = OfflineMessageModule\n    OfflineMessageURL = \"\${Const|WebURL}/helpers/helper/offline.php\"\n" .
            "[Search]\n    Module = OpenSimSearch\n    SearchURL = \"\${Const|WebURL}/helpers/query.php\"\n");
        file_put_contents("$bin/Regions/Welcome.ini", "[Welcome]\nRegionUUID = 22222222-2222-4222-8222-222222222222\nLocation = 1000,1000\nInternalPort = 9101\n");

        $import = GridImport::prepare($machine->environment, $robust, simulators: [['file' => "$bin/OpenSim.ini", 'name' => 'Welcome']], helpersHere: true);
        $import->apply($machine->environment, new NullUi(), grid_import_no_dump());
        $grid = Grid::find($machine->environment, 'oldgrid');
        $info = ConfigReader::expand(ConfigReader::load($grid->robustIni(), [], false))['GridInfoService'] ?? [];
        $dropped = array_filter($import->document()['dropped'], static fn(array $s): bool => $s['section'] === 'GridInfoService' && $s['key'] === 'search');

        // The web search of the viewers told the search the simulators call: the page of the kit in its place, said
        expect($info['search'] ?? '')->toBe('https://old.example.org/helpers/search')
            ->and(array_column($dropped, 'reason'))->toBe(['corrected'])
            // The pages the kit adds are under the default base of the helpers, never the economy, which keeps its own address
            ->and($import->plan->helpersPath)->toBe('/helpers')
            ->and($info['economy'] ?? null)->toBe('https://old.example.org/opensim/economy')
            ->and((string) ($info['web_profile_url'] ?? ''))->toStartWith('https://old.example.org/helpers/profile/')
            ->and($grid->servedByHelpers(str_replace('[AGENT_NAME]', 'Pam.Molly2', (string) ($info['web_profile_url'] ?? ''))))->toBeTrue()
            // Every address its config names on its site is the helpers', Robust's own too
            ->and(array_keys($grid->helperPaths()))->toContain('/helper/query.php', '/helpers/query.php', '/helpers/helper/offline.php');
    });

    test('keeps the helper of a choice outside this install where it is, and says it: never the helpers of this install', function () use ($offlineV1, $offlineUrl) {
        // A helper of another server; and one on the site of the grid where this install has no helpers
        foreach ([['https://im.example.org/helper/offline.php', true], ['https://old.example.org/helpers/helper/offline.php', false]] as [$url, $here]) {
            [$machine, $robust, $simulators] = $offlineV1($url);
            $import = GridImport::prepare($machine->environment, $robust, simulators: $simulators, helpersHere: $here);
            $import->apply($machine->environment, new NullUi(), grid_import_no_dump());
            $report = (string) file_get_contents($import->document()['report']);

            expect($import->plan->helpers)->toBeFalse()
                ->and($offlineUrl($machine))->toBe($url)
                ->and($report)->toContain("offline-helpers: served by the helper at $url, outside this install");
        }
    });

    test('with its simulators is still the import of its Robust: its source, its backup and the switch it says are the Robust\'s', function () {
        [$machine, $robust] = old_robust_install();
        $bin = dirname($robust);
        mkdir("$bin/Regions");
        file_put_contents("$bin/OpenSim.ini", "[Startup]\n    regionload_regionsdir = \"$bin/Regions\"\n[Network]\n    http_listener_port = 9100\n[DatabaseService]\n    ConnectionString = \"Data Source=db.example.org;Database=oldsim;User ID=olduser;Password=oldpass;Old Guids=true;\"\n");
        file_put_contents("$bin/Regions/Welcome.ini", "[Welcome]\nRegionUUID = 22222222-2222-4222-8222-222222222222\nLocation = 1000,1000\nInternalPort = 9101\n");

        $import = GridImport::prepare($machine->environment, $robust, simulators: [['file' => "$bin/OpenSim.ini", 'name' => 'Welcome']]);
        $plan = $import->document();
        $switch = array_values(array_filter($plan['reminders'] ?? [], static fn(array $r): bool => $r['code'] === 'import-switch'))[0]['params']['command'] ?? '';

        expect($import->source)->toBe(realpath($robust))
            ->and($plan['source'])->toBe(realpath($robust))
            ->and($switch)->toBe('opensim import --robust ' . realpath($robust) . ' --switch')
            ->and($plan['backup']['files'] ?? [])->toContain(realpath($robust));
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

describe('The settings of Gloebit in the config of Robust', function () {
    test('are left out, said in the report: Robust has no module of Gloebit, they are the simulators\'', function () {
        [$machine, $robust] = old_robust_install();
        // Enabled is a key of other sections of Robust: in [Gloebit], still the simulators'
        $example = $machine->core . '/bin/Robust.HG.ini.example';
        file_put_contents($example, file_get_contents($example) . "[HGInstantMessageService]\n    ; Enabled = false\n");
        file_put_contents($robust, file_get_contents($robust) . "[Gloebit]\n    Enabled = true\n    GLBEnvironment = production\n    GLBKey = \"a-key\"\n");

        $plan = GridImport::prepare($machine->environment, $robust)->document();

        expect(array_filter($plan['settings'], static fn(array $s): bool => $s['section'] === 'Gloebit'))->toBe([])
            ->and(array_unique(array_column(array_filter($plan['dropped'], static fn(array $s): bool => $s['section'] === 'Gloebit'), 'reason')))->toBe(['simulators']);
    });
});
