<?php

declare(strict_types=1);

/**
 * A simulator of an older install moved into a grid of this machine: the layout of opensim-debian (0.9.2, the Speculoos servers),
 * its config in etc/opensim.d with a shared include, its region in etc/grids/<grid>/sims/<sim>/regions. Planned, then applied.
 */

use OpenSim\Exceptions\InvalidValue;
use OpenSim\Exceptions\NotFound;
use OpenSim\Provisioning\Import\SimulatorImport;
use OpenSim\Provisioning\NullUi;
use OpenSim\Testing\Machine;

/** The install of opensim-debian, as on redoutable: its config in opensim.d, the grid's shared one included, one region. */
function old_install(Machine $machine, string $sim = 'redoutable', string $region = 'Redoutable', string $uuid = '11111111-2222-4333-8444-555555555555', int $port = 9250, string $location = '1000,1000'): string
{
    $etc = $machine->root . '/old/opensim/etc';
    $machine->write(
        "$etc/grids/speculoos/OpenSim.ini",
        "[DatabaseService]\nStorageProvider = \"OpenSim.Data.MySQL.dll\"\n[XEngine]\nMinThreads = 10\n",
    );
    $machine->write(
        "$etc/grids/speculoos/sims/$sim/regions/$region.ini",
        "[$region]\nRegionUUID = $uuid\nLocation = $location\nSizeX = 256\nInternalPort = " . ($port + 1) . "\nExternalHostName = $sim.speculoos.world\n",
    );
    $machine->write(
        "$etc/opensim.d/$sim.ini",
        "[Launch]\nSimName = $sim\nBinDir = /opt/opensim/lib/opensim-0.9.2.2/bin\n" .
            "[Const]\nEtcDirectory = \"$etc\"\nDataDirectory = \"/opt/opensim/var/$sim\"\n" .
            "[Startup]\nregionload_regionsdir = \"\${Const|EtcDirectory}/grids/speculoos/sims/$sim/regions\"\nInclude-Grid = \"$etc/grids/speculoos/OpenSim.ini\"\n" .
            "[Network]\nhttp_listener_port = $port\nConsolePass = \"hidden\"\n" .
            "[DatabaseService]\nConnectionString = \"Data Source=localhost;Database=os$sim;User ID=opensim;Password=secret;Old Guids=true;\"\n" .
            "[Estates]\nDefaultEstateName = \"Redoutable Estate\"\nDefaultEstateOwnerName = \"Gudule Lapointe\"\n",
    );

    return "$etc/opensim.d/$sim.ini";
}

describe('A simulator imported', function () {
    test('is planned first: what is written and where, its database and its region kept, nothing written', function () {
        $machine = new Machine();
        $import = SimulatorImport::prepare($machine->environment, old_install($machine), 'demo');
        $plan = $import->document();

        expect([$plan['simulator'], $plan['slug'], $plan['grid']])
            ->toBe(['redoutable', 'demo.redoutable', 'demo'])
            ->and($plan['database'])
            ->toBe(['host' => 'localhost', 'name' => 'osredoutable', 'user' => 'opensim', 'kept' => true])
            ->and($plan['ports'])
            ->toBe(['http' => 9250, 'console' => null])
            ->and($plan['regions'])
            ->toHaveCount(1)
            ->and($plan['regions'][0])
            ->toMatchArray(['name' => 'Redoutable', 'uuid' => '11111111-2222-4333-8444-555555555555', 'location' => '1000,1000'])
            ->and($plan['writes'])
            ->toContain($plan['config'], $plan['regions'][0]['to'], $plan['report'])
            ->and(array_column($plan['settings'], 'value', 'key'))
            ->toHaveKey('MinThreads')
            // What is not kept is said too, with why: the database the new config writes the same, the layout of the old install
            ->and(array_column($plan['dropped'], 'reason', 'key'))
            ->toMatchArray(['ConnectionString' => 'same', 'SimName' => 'layout', 'regionload_regionsdir' => 'layout', 'http_listener_port' => 'same'])
            // Only the password masked: several simulators share one user, the host tells them apart
            ->and(array_column($plan['dropped'], 'value', 'key')['ConnectionString'])
            ->toBe('Data Source=localhost;Database=osredoutable;User ID=opensim;Password=***;Old Guids=true;')
            // A password kept is not shown
            ->and(json_encode($plan))
            ->not->toContain('hidden')
            ->and($import->report())
            ->not->toContain('hidden')
            ->and(array_column($plan['reminders'], 'code'))
            ->toBe(['import-read-access', 'import-stop-source', 'import-enable-start'])
            ->and($plan['reminders'][2]['detail'])
            ->toBe('Then: opensim enable demo.redoutable, opensim start demo.redoutable.')
            ->and(is_file($plan['config']))
            ->toBeFalse()
            ->and(is_file($plan['regions'][0]['to']))
            ->toBeFalse();
    });

    test('is written disabled: its config on the same database, its region file as it was, the settings it keeps, 640', function () {
        $machine = new Machine();
        $source = old_install($machine);
        $import = SimulatorImport::prepare($machine->environment, $source, 'demo');
        $import->apply($machine->environment, new NullUi());
        $plan = $import->document();
        $config = (string) file_get_contents($plan['config']);

        expect($config)
            ->toContain('Database=osredoutable;User ID=opensim;Password=secret;Old Guids=true;')
            ->toContain('http_listener_port = 9250')
            ->toContain('MinThreads = 10')
            ->and(file_get_contents($plan['regions'][0]['to']))
            ->toBe(file_get_contents(dirname($source, 2) . '/grids/speculoos/sims/redoutable/regions/Redoutable.ini'))
            ->and(file_get_contents($plan['report']))
            ->toContain('Database: osredoutable @ localhost (user opensim), kept as it is', 'setting(s) of the original not kept', '[Launch] SimName = redoutable (layout)')
            ->and(fileperms($plan['config']) & 0o777)
            ->toBe(0o640)
            ->and($machine->processes->all())
            ->toBe([]);
    });

    test('is refused when the grid has it, or one of its regions, already', function () {
        $machine = new Machine();
        SimulatorImport::prepare($machine->environment, old_install($machine), 'demo')->apply($machine->environment, new NullUi());
        $again = old_install($machine, 'redoutable');
        $sameRegion = old_install($machine, 'redoutable2', 'Redoutable', '99999999-2222-4333-8444-555555555555');

        expect(fn() => SimulatorImport::prepare($machine->environment, $again, 'demo'))
            ->toThrow(InvalidValue::class, 'the grid already has the simulator demo.redoutable')
            ->and(fn() => SimulatorImport::prepare($machine->environment, $sameRegion, 'demo'))
            ->toThrow(InvalidValue::class, 'the region Redoutable')
            ->and(fn() => SimulatorImport::prepare($machine->environment, $again, 'nowhere'))
            ->toThrow(NotFound::class);
    });
});

describe('A simulator of Speculoos imported', function () {
    test('finds its regions in the folder its config names with the keys of [Launch] (${Launch|simslug})', function () {
        $machine = new Machine();
        $etc = $machine->root . '/speculoos/etc';
        $machine->write("$etc/sims/recital/regions/Recital.ini", "[Recital]\nRegionUUID = 22222222-3333-4444-8555-666666666666\nLocation = 2000,2000\nInternalPort = 9301\n");
        $machine->write(
            "$etc/opensim.d/recital.ini",
            "[Launch]\nSimName = Recital\nsimslug = recital\n" .
                "[Const]\nEtcDirectory = \"$etc\"\n" .
                "[Startup]\nregionload_regionsdir = \"\${Const|EtcDirectory}/sims/\${Launch|simslug}/regions\"\n" .
                "[Network]\nhttp_listener_port = 9300\n" .
                "[DatabaseService]\nConnectionString = \"Data Source=localhost;Database=osrecital;User ID=opensim;Password=secret;\"\n",
        );
        $plan = SimulatorImport::prepare($machine->environment, "$etc/opensim.d/recital.ini", 'demo')->document();

        expect($plan['regions'])
            ->toHaveCount(1)
            ->and($plan['regions'][0])
            ->toMatchArray(['name' => 'Recital', 'uuid' => '22222222-3333-4444-8555-666666666666', 'location' => '2000,2000'])
            ->and(array_column($plan['reminders'], 'code'))
            ->toContain('import-stop-source');
    });

    test('says so when its config names a folder of regions that has none, instead of the regions to stop', function () {
        $machine = new Machine();
        $etc = $machine->root . '/empty/etc';
        $machine->write(
            "$etc/opensim.d/lonely.ini",
            "[Launch]\nSimName = Lonely\n[Startup]\nregionload_regionsdir = \"$etc/sims/lonely/regions\"\n[Network]\nhttp_listener_port = 9310\n" .
                "[DatabaseService]\nConnectionString = \"Data Source=localhost;Database=oslonely;User ID=opensim;Password=secret;\"\n",
        );
        $plan = SimulatorImport::prepare($machine->environment, "$etc/opensim.d/lonely.ini", 'demo')->document();
        $codes = array_column($plan['reminders'], 'code');

        expect($plan['regions'])
            ->toBe([])
            ->and($codes)
            ->toContain('import-no-region')
            ->not->toContain('import-stop-source')
            ->and($plan['reminders'][array_search('import-no-region', $codes, true)]['params']['folder'])
            ->toBe("$etc/sims/lonely/regions");
    });
});

describe('The folders of a simulator imported', function () {
    test('are the ones of the new simulator, never the ones of the old install, and the plan says they are replaced', function () {
        $machine = new Machine();
        $source = old_install($machine);
        $var = $machine->root . '/old/opensim/var/redoutable';
        $text = (string) file_get_contents($source);
        file_put_contents($source, $text .
            "[DataSnapshot]\nsnapshot_cache_directory = \"$var/DataSnapshot\"\n" .
            "[XEngine]\nScriptEnginesPath = \"$var/ScriptEngines\"\n" .
            "[YEngine]\nScriptEnginesPath = \"$var/ScriptEngines\"\n" .
            "[AssetCache]\nCacheDirectory = \"$var/assetcache\"\n" .
            "[GridService]\nMapTileDirectory = \"$var/maptiles\"\n");
        $import = SimulatorImport::prepare($machine->environment, $source, 'demo');
        $plan = $import->document();
        $replaced = array_filter($plan['dropped'], static fn(array $d): bool => $d['reason'] === 'replaced');
        $keys = array_map(static fn(array $d): string => "{$d['section']}.{$d['key']}", $replaced);

        expect(array_map(static fn(array $s): string => "{$s['section']}.{$s['key']}", $plan['settings']))
            ->not->toContain('DataSnapshot.snapshot_cache_directory', 'XEngine.ScriptEnginesPath', 'YEngine.ScriptEnginesPath', 'AssetCache.CacheDirectory', 'GridService.MapTileDirectory')
            ->and(array_values($keys))
            ->toContain('DataSnapshot.snapshot_cache_directory', 'XEngine.ScriptEnginesPath', 'YEngine.ScriptEnginesPath', 'AssetCache.CacheDirectory', 'GridService.MapTileDirectory')
            ->and(array_column($replaced, 'replacement', 'key')['MapTileDirectory'])
            ->toBe($import->plan->cacheDirectory . '/maptiles')
            ->and(array_column(array_values(array_filter($replaced, static fn(array $d): bool => $d['section'] === 'XEngine')), 'replacement')[0])
            ->toBe($import->plan->dataDirectory . '/ScriptEngines');
    });
});

describe('Several simulators imported together', function () {
    test('are checked between them: no two get one port, one region name or UUID, or one place of the map', function () {
        $machine = new Machine();
        $a = old_install($machine, 'redoutable', 'Redoutable', '11111111-2222-4333-8444-555555555555');
        $b = old_install($machine, 'residences', 'Redoutable', '11111111-2222-4333-8444-555555555555');
        $imports = OpenSim\Provisioning\Import\SimulatorImports::prepare($machine->environment, [['file' => $a], ['file' => $b]], 'demo');

        expect(array_keys($imports->problems))
            ->toBe(['simulators.1'])
            ->and($imports->problems['simulators.1'])
            ->toContain('the port 9250', 'the region name Redoutable', 'the region UUID 11111111-2222-4333-8444-555555555555', 'the port 9251', 'the place 1000,1000')
            ->and(fn() => $imports->apply($machine->environment, new NullUi()))
            ->toThrow(InvalidValue::class, 'simulators.1')
            ->and(is_file($imports->document()['steps'][0]['config']))
            ->toBeFalse();
    });

    test('are planned together and written in their order when nothing is wrong', function () {
        $machine = new Machine();
        $a = old_install($machine, 'redoutable', 'Redoutable', '11111111-2222-4333-8444-555555555555', 9250, '1000,1000');
        $b = old_install($machine, 'residences', 'Residence', '99999999-2222-4333-8444-555555555555', 9260, '1001,1000');
        $imports = OpenSim\Provisioning\Import\SimulatorImports::prepare($machine->environment, [['file' => $a], ['file' => $b]], 'demo');
        $imports->apply($machine->environment, new NullUi());
        $plan = $imports->document();

        expect($plan['problems'])
            ->toBe([])
            ->and(array_column($plan['steps'], 'slug'))
            ->toBe(['demo.redoutable', 'demo.residences'])
            ->and(array_filter(array_column($plan['steps'], 'config'), 'is_file'))
            ->toHaveCount(2);
    });
});

describe('The API, for an import', function () {
    test('plans by default, applies when asked, and says why it refuses', function () {
        $machine = new Machine();
        $api = new OpenSim\Api\Api($machine->environment);
        $source = old_install($machine);

        $plan = $api->import(['kind' => 'simulator', 'file' => $source, 'grid' => 'demo']);
        $writtenByPlan = is_file($plan->document['data']['attributes']['config']);
        $applied = $api->import(['file' => $source, 'grid' => 'demo', 'mode' => 'apply']);
        $again = $api->import(['file' => $source, 'grid' => 'demo']);

        $two = $api->import(['grid' => 'demo', 'simulators' => [
            ['file' => old_install($machine, 'first', 'First', '66666666-2222-4333-8444-555555555555', 9280, '1003,1000')],
            ['file' => old_install($machine, 'other', 'Other', '77777777-2222-4333-8444-555555555555', 9270, '1002,1000')],
        ]]);

        expect([$two->status, $two->document['data']['type'], count($two->document['data']['attributes']['steps']), $two->document['data']['attributes']['problems']])
            ->toBe([200, 'imports', 2, []])
            ->and([$plan->status, $plan->document['data']['attributes']['applied'], $plan->document['data']['id']])
            ->toBe([200, false, 'demo.redoutable'])
            ->and($writtenByPlan)
            ->toBeFalse()
            ->and(is_file($applied->document['data']['attributes']['config']))
            ->toBeTrue()
            ->and([$applied->status, $applied->document['data']['attributes']['applied']])
            ->toBe([200, true])
            ->and([$again->status, $again->document['code'] ?? null])
            ->toBe([400, 'invalid-request'])
            ->and($api->import(['file' => $source, 'grid' => 'demo', 'mode' => 'now'])->status)
            ->toBe(400);
    });
});
