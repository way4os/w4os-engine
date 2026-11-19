<?php

declare(strict_types=1);

/**
 * The settings of an old grid brought into the OpenSim.ini of a grid of this machine (its search, its data services, its economy,
 * its offline messages, its profiles, its groups), read as OpenSimulator reads them: so that the repair of the options lays them
 * in its simulators, without anybody editing a file.
 */

use OpenSim\Config\ConfigReader;
use OpenSim\Config\GridConfig;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\GridOptions;
use OpenSim\Provisioning\Import\GridSettingsImport;
use OpenSim\Testing\Machine;

const GRID_SETTINGS = __DIR__ . '/../fixtures/grid-settings/OpenSim.ini';

describe('The settings of an old grid', function () {
    test('are read as OpenSimulator reads them: its includes, the last word of each, its quotes, its comments; its constants kept', function () {
        $machine = new Machine();
        $plan = GridSettingsImport::prepare($machine->environment, GRID_SETTINGS, 'demo');
        $settings = $plan->settings();
        $gloebit = $plan->settings('Gloebit.ini');
        $notes = implode("\n", $plan->document()['notes']);

        // The constants as they are: the ones this machine has (the address of the grid), the others defined for the grid (its site)
        expect($settings['Search'])
            ->toBe(['Module' => 'OpenSimSearch', 'SearchURL' => '${Const|WebURL}/helpers/query.php?gk=${Const|BaseURL}:${Const|PublicPort}'])
            ->and($settings['Const'])
            ->toBe(['WebURL' => 'https://web.example.org'])
            // The data service of the helpers of the grid by its name now, DATA_SRV_<nick>, and the shared directory; the cache of a
            // snapshot is the simulator's own
            ->and($settings['DataSnapshot'])
            ->toBe([
                'index_sims' => 'true',
                'gridname' => 'Example',
                'default_snapshot_period' => '3600',
                'DATA_SRV_demo' => '${Const|WebURL}/helpers/register.php',
                'DATA_SRV_2do' => 'https://2do.directory/helpers/register.php',
            ])
            // Gloebit in one place, the Gloebit.ini of the grid, which the simulators include: the economy it says last there too
            ->and($settings['Gloebit'])
            ->toBe(['Include-Gloebit' => '${Const|EtcDirectory}/Gloebit.ini'])
            ->and($settings)
            ->not->toHaveKey('Economy')
            ->and($gloebit['Economy'])
            ->toBe(['economymodule' => 'Gloebit', 'economy' => '${Const|WebURL}/gloebit', 'SellEnabled' => 'true', 'PriceUpload' => '0', 'PriceGroupCreate' => '0'])
            ->and($gloebit['Gloebit']['GLBEnvironment'])
            ->toBe('production')
            // What follows the closing quote is left out, as OpenSimulator reads it, and said
            ->and($gloebit['Gloebit']['GLBSpecificStorageProvider'])
            ->toBe('OpenSim.Data.MySQL.dll')
            ->and($settings['Groups']['GroupsServerURI'])
            ->toBe('${Const|PrivURL}:${Const|PrivatePort}')
            ->and($settings)
            ->not->toHaveKey('Startup')
            ->and($notes)
            ->toContain('DTL/NSL is not handled', 'DATA_SRV_MISearch', 'GLBSpecificStorageProvider: what follows its closing quote');

        return $machine;
    });

    test('are planned without their secrets, and laid in the files of the grid without replacing what is there, once', function (Machine $machine) {
        $general = $machine->grid . '/OpenSim.ini';
        file_put_contents($general, (is_file($general) ? (string) file_get_contents($general) : '') . "\n[UserProfiles]\n    ProfileServiceURL = \"http://mine.example.org:8002\"\n");
        $plan = GridSettingsImport::prepare($machine->environment, GRID_SETTINGS, 'demo');
        $said = json_encode($plan->document());

        $applied = $plan->apply();
        $again = GridSettingsImport::prepare($machine->environment, GRID_SETTINGS, 'demo')->document();
        $read = ConfigReader::load($general, expand: false);
        $gloebit = ConfigReader::load($machine->grid . '/Gloebit.ini', expand: false);

        expect($said)
            ->not->toContain('made-up-key-of-the-old-grid')
            ->not->toContain('made-up-secret-of-the-old-grid')
            ->not->toContain('made-up-password')
            ->toContain('GLBSecret', 'GLBSpecificStorageProvider')
            ->and($applied)
            ->toBeTrue()
            ->and($read['Search']['Module'] ?? null)
            ->toBe('OpenSimSearch')
            ->and($read['Const']['WebURL'] ?? null)
            ->toBe('https://web.example.org')
            ->and($read)
            ->not->toHaveKey('Economy')
            ->and($gloebit['Gloebit']['GLBKey'] ?? null)
            ->toBe('made-up-key-of-the-old-grid')
            // What the grid said is its own
            ->and($read['UserProfiles']['ProfileServiceURL'] ?? null)
            ->toBe('http://mine.example.org:8002')
            ->and((string) file_get_contents($general) . file_get_contents($machine->grid . '/Gloebit.ini'))
            ->not->toContain('DTLNSLMoneyModule')
            ->not->toContain('MISearch')
            ->and($again['files'])
            ->toBe([]);

        return $machine;
    })->depends('are read as OpenSimulator reads them: its includes, the last word of each, its quotes, its comments; its constants kept');

    test('give the simulators the constants they use: the site of the grid in the master, as the OpenSim.ini of the grid defines it', function (Machine $machine) {
        $master = (new OpenSim\Provisioning\SimulatorMaster($machine->environment))->render($machine->grid, $machine->core . '/bin');

        expect(ConfigReader::parse($master)['Const']['WebURL'] ?? null)->toBe('https://web.example.org');
    })->depends('are planned without their secrets, and laid in the files of the grid without replacing what is there, once');

    test('are then the one source of the simulators: the repair writes none of them in a simulator, the offline messages the V1 of the helpers', function () {
        $machine = new Machine();
        $options = static fn() => new GridOptions(Grid::find($machine->environment, 'demo'));
        $alphaPath = GridConfig::simulatorsDirectory($machine->grid) . '/alpha.ini';
        // A grid of v3 as the setup made it: its master, its options recorded (the offline messages of Robust, the default)
        $master = new OpenSim\Provisioning\SimulatorMaster($machine->environment);
        $master->ensure($machine->grid, $machine->core . '/bin');
        $conf = $machine->grid . '/demo.conf';
        file_put_contents($conf, (string) file_get_contents($conf) . "Options = \"profiles, offline-robust\"\n");
        $before = (string) file_get_contents($alphaPath);

        GridSettingsImport::prepare($machine->environment, GRID_SETTINGS, 'demo')->apply();
        $repair = $options()->repair(true);
        $made = ConfigReader::parse($master->render($machine->grid, $machine->core . '/bin'));

        expect($repair['simulators'])
            ->toBe([])
            // Nothing of the grid copied in a simulator: the master holds the OpenSim.ini of the grid for all of them
            ->and((string) file_get_contents($alphaPath))
            ->toBe($before)
            ->and($made['Search']['SearchURL'] ?? null)
            ->toBe('${Const|WebURL}/helpers/query.php?gk=${Const|BaseURL}:${Const|PublicPort}')
            ->and($made['DataSnapshot']['DATA_SRV_2do'] ?? null)
            ->toBe('https://2do.directory/helpers/register.php')
            ->and($made['Gloebit']['Include-Gloebit'] ?? null)
            ->toBe('${Const|EtcDirectory}/Gloebit.ini')
            // The offline messages as the grid says them, V1 and the helpers, whatever was recorded
            ->and($made['Messaging']['OfflineMessageModule'] ?? null)
            ->toBe('OfflineMessageModule')
            ->and($options()->enabled())
            ->toContain('offline-helpers')
            ->not->toContain('offline-robust')
            ->and($options()->repair(false)['simulators'])
            ->toBe([]);
    });

    test('change only by the choice of an operator: the offline messages of Robust (V2) enabled on the grid, in place of V1', function () {
        $machine = new Machine();
        (new OpenSim\Provisioning\SimulatorMaster($machine->environment))->ensure($machine->grid, $machine->core . '/bin');
        GridSettingsImport::prepare($machine->environment, GRID_SETTINGS, 'demo')->apply();
        $options = static fn() => new GridOptions(Grid::find($machine->environment, 'demo'));
        $kept = $options()->repair(true);
        $v1 = $options()->enabled();

        $changed = $options()->enable('offline-robust');
        $general = ConfigReader::load($machine->grid . '/OpenSim.ini', expand: false)['Messaging'] ?? [];
        $made = ConfigReader::parse((new OpenSim\Provisioning\SimulatorMaster($machine->environment))->render($machine->grid, $machine->core . '/bin'));

        expect($kept['applied'])
            ->not->toContain('offline-robust')
            ->and($v1)
            ->toContain('offline-helpers')
            ->and($changed['error'])
            ->toBeNull()
            ->and($changed['grid'])
            ->toBeTrue()
            ->and($general['OfflineMessageModule'] ?? null)
            ->toBe('Offline Message Module V2')
            ->and($general['OfflineMessageURL'] ?? '')
            ->not->toContain('offline.php')
            ->and($made['Messaging']['OfflineMessageModule'] ?? null)
            ->toBe('Offline Message Module V2')
            ->and($options()->enabled())
            ->toContain('offline-robust')
            ->not->toContain('offline-helpers')
            ->and($options()->repair(false)['simulators'])
            ->toBe([]);
    });

    test('are laid in a simulator only where nothing of the grid gives them: a grid without a master', function () {
        $machine = new Machine();
        GridSettingsImport::prepare($machine->environment, GRID_SETTINGS, 'demo')->apply();
        (new GridOptions(Grid::find($machine->environment, 'demo')))->repair(true);
        $alpha = ConfigReader::load(GridConfig::simulatorsDirectory($machine->grid) . '/alpha.ini', expand: false);

        // Without a master, the file of the simulator is its whole config: the options of the grid are there, Gloebit by its include
        expect($alpha['Search']['SearchURL'] ?? null)
            ->toBe('${Const|WebURL}/helpers/query.php?gk=${Const|BaseURL}:${Const|PublicPort}')
            ->and($alpha['Gloebit']['Include-Gloebit'] ?? null)
            ->toBe('${Const|EtcDirectory}/Gloebit.ini')
            ->and($alpha['Gloebit'] ?? [])
            ->not->toHaveKey('GLBEnvironment')
            ->and($alpha['Messaging']['OfflineMessageModule'] ?? null)
            ->toBe('OfflineMessageModule');
    });

    test('are imported through the API, planned first, the secrets masked', function () {
        $machine = new Machine();
        $router = new OpenSim\Api\Router(new OpenSim\Api\Api($machine->environment));
        $ask = static fn(string $mode) => $router->post('/setups/imports', ['data' => ['type' => 'imports', 'attributes' => ['kind' => 'grid-settings', 'file' => GRID_SETTINGS, 'grid' => 'demo', 'mode' => $mode]]]);

        $plan = $ask('plan');
        $applied = $ask('apply');

        $gloebit = $plan->document['data']['attributes']['files'][$machine->grid . '/Gloebit.ini']['Gloebit'] ?? [];

        expect($gloebit['GLBSecret'] ?? null)
            ->toBe('***')
            ->and($gloebit['GLBSpecificConnectionString'] ?? null)
            ->toBe('***')
            ->and($plan->document['data']['attributes']['applied'])
            ->toBeFalse()
            ->and(is_file($machine->grid . '/OpenSim.ini'))
            ->toBeTrue()
            ->and($applied->document['data']['attributes']['applied'])
            ->toBeTrue()
            ->and(json_encode([$plan->document, $applied->document]))
            ->not->toContain('made-up-password');
    });
});
