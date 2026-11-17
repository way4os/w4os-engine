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
function old_install(Machine $machine, string $sim = 'redoutable', string $region = 'Redoutable', string $uuid = '11111111-2222-4333-8444-555555555555'): string
{
    $etc = $machine->root . '/old/opensim/etc';
    $machine->write(
        "$etc/grids/speculoos/OpenSim.ini",
        "[DatabaseService]\nStorageProvider = \"OpenSim.Data.MySQL.dll\"\n[XEngine]\nMinThreads = 10\n",
    );
    $machine->write(
        "$etc/grids/speculoos/sims/$sim/regions/$region.ini",
        "[$region]\nRegionUUID = $uuid\nLocation = 1000,1000\nSizeX = 256\nInternalPort = 9251\nExternalHostName = $sim.speculoos.world\n",
    );
    $machine->write(
        "$etc/opensim.d/$sim.ini",
        "[Launch]\nSimName = $sim\nBinDir = /opt/opensim/lib/opensim-0.9.2.2/bin\n" .
            "[Const]\nEtcDirectory = \"$etc\"\nDataDirectory = \"/opt/opensim/var/$sim\"\n" .
            "[Startup]\nregionload_regionsdir = \"\${Const|EtcDirectory}/grids/speculoos/sims/$sim/regions\"\nInclude-Grid = \"$etc/grids/speculoos/OpenSim.ini\"\n" .
            "[Network]\nhttp_listener_port = 9250\n" .
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
            ->and($plan['reminders'][1])
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
            ->toContain('Database: osredoutable @ localhost (user opensim), kept as it is')
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

describe('The API, for an import', function () {
    test('plans by default, applies when asked, and says why it refuses', function () {
        $machine = new Machine();
        $api = new OpenSim\Api\Api($machine->environment);
        $source = old_install($machine);

        $plan = $api->import(['kind' => 'simulator', 'file' => $source, 'grid' => 'demo']);
        $writtenByPlan = is_file($plan->document['data']['attributes']['config']);
        $applied = $api->import(['file' => $source, 'grid' => 'demo', 'mode' => 'apply']);
        $again = $api->import(['file' => $source, 'grid' => 'demo']);

        expect([$plan->status, $plan->document['data']['attributes']['applied'], $plan->document['data']['id']])
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
