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

/**
 * A grid that runs elsewhere, with two simulators on this machine made whole, as a simulator of such a grid is (no master): each its
 * own constants, its groups of the hypergrid; the options of the grid recorded as the setup made them.
 */
function remote_machine(): Machine
{
    $machine = new Machine();
    unlink($machine->grid . '/Robust.HG.ini');
    @unlink($machine->etc . '/robust.d/demo.ini');
    file_put_contents($machine->grid . '/demo.conf', "[Grid]\nRemote = true\nGridName = Demo\nGridNick = demo\nBaseHostname = grid.example.org\nPublicPort = 8002\nPrivatePort = 8003\nHypergrid = true\nCoreDirectory = {$machine->core}\nOptions = \"hypergrid, profiles, groups-hypergrid, offline-robust\"\n");
    foreach (['alpha', 'beta'] as $slug) {
        $path = GridConfig::simulatorsDirectory($machine->grid) . "/$slug.ini";
        file_put_contents($path, (string) file_get_contents($path) . "[Const]\n    BaseURL = \"http://grid.example.org\"\n    PublicPort = \"8002\"\n    PrivURL = \"http://grid.example.org\"\n    PrivatePort = \"8003\"\n"
            . "[Groups]\n    Enabled = true\n    Module = \"Groups Module V2\"\n    ServicesConnectorModule = \"Groups HG Service Connector\"\n    LocalService = remote\n    GroupsServerURI = \"\${Const|PrivURL}:\${Const|PrivatePort}\"\n"
            . "[UserProfiles]\n    ProfileServiceURL = \"\${Const|BaseURL}:\${Const|PublicPort}\"\n");
    }

    return $machine;
}

describe('The settings of an old grid', function () {
    test('are read as OpenSimulator reads them: its includes, the last word of each, its quotes, its comments; its constants kept', function () {
        $machine = new Machine();
        // A grid of v3: the master of its simulators holds its OpenSim.ini
        (new OpenSim\Provisioning\SimulatorMaster($machine->environment))->ensure($machine->grid, $machine->core . '/bin');
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

    test('are laid in each simulator of a grid without a master, its config whole, by the import alone', function () {
        $machine = new Machine();
        GridSettingsImport::prepare($machine->environment, GRID_SETTINGS, 'demo')->apply();
        $imported = (string) file_get_contents(GridConfig::simulatorsDirectory($machine->grid) . '/alpha.ini');
        (new GridOptions(Grid::find($machine->environment, 'demo')))->repair(true);
        // The file of the simulator itself, not the files it includes
        $alpha = ConfigReader::file(GridConfig::simulatorsDirectory($machine->grid) . '/alpha.ini');

        // Without a master, the file of the simulator is its whole config: the settings are there, Gloebit by the path of its file;
        // the repair has the options of the grid to add (the profiles...), never these again
        expect(is_file($machine->grid . '/OpenSim.ini'))
            ->toBeFalse()
            ->and(substr_count((string) file_get_contents(GridConfig::simulatorsDirectory($machine->grid) . '/alpha.ini'), 'SearchURL'))
            ->toBe(substr_count($imported, 'SearchURL'))
            ->and($alpha['Search']['SearchURL'] ?? null)
            ->toBe('${Const|WebURL}/helpers/query.php?gk=${Const|BaseURL}:${Const|PublicPort}')
            ->and($alpha['Gloebit']['Include-Gloebit'] ?? null)
            ->toBe($machine->grid . '/Gloebit.ini')
            ->and($alpha['Gloebit'] ?? [])
            ->not->toHaveKey('GLBEnvironment')
            ->and($alpha['Messaging']['OfflineMessageModule'] ?? null)
            ->toBe('OfflineMessageModule');
    });

    test('of a grid that runs elsewhere, its simulators whole: laid in each simulator once, the repair adding nothing and changing nothing', function () {
        $machine = remote_machine();
        $alphaPath = GridConfig::simulatorsDirectory($machine->grid) . '/alpha.ini';
        $grid = static fn() => Grid::find($machine->environment, 'demo');

        $plan = GridSettingsImport::prepare($machine->environment, GRID_SETTINGS, 'demo');
        $plan->apply();
        $imported = (string) file_get_contents($alphaPath);
        $repair = (new GridOptions($grid()))->repair(true);
        $alpha = ConfigReader::file($alphaPath);
        // Every value the simulator had after the import, as it is after the repair
        $kept = [];
        foreach (ConfigReader::parse($imported) as $section => $keys) {
            $kept[$section] = array_intersect_key($alpha[$section] ?? [], $keys);
        }

        expect($grid()->isRemote())
            ->toBeTrue()
            // One place: the simulators, whose config is whole; no OpenSim.ini of the grid besides
            ->and(is_file($machine->grid . '/OpenSim.ini'))
            ->toBeFalse()
            ->and($plan->document()['targets'])
            ->toBe([GridConfig::simulatorsDirectory($machine->grid) . '/alpha.ini', GridConfig::simulatorsDirectory($machine->grid) . '/beta.ini'])
            ->and($alpha['Search']['SearchURL'] ?? null)
            ->toBe('${Const|WebURL}/helpers/query.php?gk=${Const|BaseURL}:${Const|PublicPort}')
            ->and($alpha['Const']['WebURL'] ?? null)
            ->toBe('https://web.example.org')
            ->and($alpha['Messaging']['OfflineMessageModule'] ?? null)
            ->toBe('OfflineMessageModule')
            // Gloebit in one place, its file, which each simulator includes by its path
            ->and($alpha['Gloebit']['Include-Gloebit'] ?? null)
            ->toBe($machine->grid . '/Gloebit.ini')
            // The repair lays again none of what the import laid, and changes no value: it only adds a key an option of the grid
            // misses (MessagingEnabled of the groups of the hypergrid, which the old config did not say)
            ->and($repair['applied'])
            ->not->toContain('search', 'offline-robust', 'offline-helpers', 'economy-gloebit', 'groups-remote')
            ->and($kept)
            ->toBe(ConfigReader::parse($imported))
            ->and(substr_count((string) file_get_contents($alphaPath), 'SearchURL'))
            ->toBe(1)
            ->and($alpha['Groups']['ServicesConnectorModule'] ?? null)
            ->toBe('Groups HG Service Connector')
            ->and((new GridOptions($grid()))->enabled())
            ->toContain('groups-hypergrid')
            ->not->toContain('groups-remote');
    });

    test('never change a valid choice of the grid: its groups of the hypergrid stay, whatever other option of their group shares a setting', function () {
        $machine = remote_machine();
        // The groups of the hypergrid said by an OpenSim.ini of the grid too (an older import): Module and GroupsServerURI are the
        // ones of every option of the group, the connector of the hypergrid tells this one
        file_put_contents($machine->grid . '/OpenSim.ini', "[Groups]\n    Enabled = true\n    Module = \"Groups Module V2\"\n    ServicesConnectorModule = \"Groups HG Service Connector\"\n    LocalService = remote\n    GroupsServerURI = \"\${Const|PrivURL}:\${Const|PrivatePort}\"\n    MessagingModule = \"Groups Messaging Module V2\"\n");
        $alphaPath = GridConfig::simulatorsDirectory($machine->grid) . '/alpha.ini';
        $options = static fn() => new GridOptions(Grid::find($machine->environment, 'demo'));

        $enabled = $options()->enabled();
        $options()->repair(true);

        expect($enabled)
            ->toContain('groups-hypergrid')
            ->not->toContain('groups-remote')
            ->and(ConfigReader::file($alphaPath)['Groups']['ServicesConnectorModule'] ?? null)
            ->toBe('Groups HG Service Connector');
    });

    test('are imported through the API, planned first, the secrets masked', function () {
        $machine = new Machine();
        (new OpenSim\Provisioning\SimulatorMaster($machine->environment))->ensure($machine->grid, $machine->core . '/bin');
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
