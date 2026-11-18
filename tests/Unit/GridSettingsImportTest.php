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
    test('are read as OpenSimulator reads them: its includes, the last word of each, its constants, its quotes', function () {
        $machine = new Machine();
        $plan = GridSettingsImport::prepare($machine->environment, GRID_SETTINGS, 'demo');
        $settings = $plan->settings();

        expect($settings['Search'])
            ->toBe(['Module' => 'OpenSimSearch', 'SearchURL' => 'https://web.example.org/helpers/query.php?gk=http://grid.example.org:8002'])
            // Both data services of the grid, as they are; the cache of a snapshot is the simulator's own
            ->and($settings['DataSnapshot'])
            ->toBe([
                'index_sims' => 'true',
                'gridname' => 'Example',
                'default_snapshot_period' => '3600',
                'DATA_SRV_MISearch' => 'https://web.example.org/helpers/register.php',
                'DATA_SRV_2do' => 'https://2do.directory/helpers/register.php',
            ])
            // The included Gloebit.ini says the economy last: DTL/NSL is not used any more
            ->and($settings['Economy'])
            ->toBe(['economymodule' => 'Gloebit', 'economy' => 'https://web.example.org/gloebit', 'SellEnabled' => 'true', 'PriceUpload' => '0', 'PriceGroupCreate' => '0'])
            ->and($settings['Gloebit']['GLBEnvironment'])
            ->toBe('production')
            // What follows the closing quote is left out, as OpenSimulator reads it
            ->and($settings['Gloebit']['GLBSpecificStorageProvider'])
            ->toBe('OpenSim.Data.MySQL.dll')
            ->and($settings['Groups']['GroupsServerURI'])
            ->toBe('http://grid.example.org:8003')
            // What is not of an option of the grid stays in the old install
            ->and($settings)
            ->not->toHaveKey('Startup')
            ->and(json_encode($settings))
            ->not->toContain('${');

        return $machine;
    });

    test('are planned without their secrets, and laid in the OpenSim.ini of the grid without replacing what is there, once', function (Machine $machine) {
        $general = $machine->grid . '/OpenSim.ini';
        file_put_contents($general, (is_file($general) ? (string) file_get_contents($general) : '') . "\n[UserProfiles]\n    ProfileServiceURL = \"http://mine.example.org:8002\"\n");
        $plan = GridSettingsImport::prepare($machine->environment, GRID_SETTINGS, 'demo');
        $said = json_encode($plan->document());

        $applied = $plan->apply();
        $again = GridSettingsImport::prepare($machine->environment, GRID_SETTINGS, 'demo')->document();
        $file = (string) file_get_contents($general);
        $read = ConfigReader::load($general, expand: false);

        expect($said)
            ->not->toContain('made-up-key-of-the-old-grid')
            ->not->toContain('made-up-secret-of-the-old-grid')
            ->not->toContain('made-up-password')
            ->toContain('GLBSecret', 'GLBSpecificStorageProvider')
            ->and($applied)
            ->toBeTrue()
            ->and($read['Search']['Module'] ?? null)
            ->toBe('OpenSimSearch')
            ->and($read['Gloebit']['GLBKey'] ?? null)
            ->toBe('made-up-key-of-the-old-grid')
            // What the grid said is its own
            ->and($read['UserProfiles']['ProfileServiceURL'] ?? null)
            ->toBe('http://mine.example.org:8002')
            ->and($file)
            ->not->toContain('DTLNSLMoneyModule')
            ->and($again['settings'])
            ->toBe([]);

        return $machine;
    })->depends('are read as OpenSimulator reads them: its includes, the last word of each, its constants, its quotes');

    test('are then laid in the simulators of the grid by the repair of the options, once', function (Machine $machine) {
        $options = static fn() => new GridOptions(Grid::find($machine->environment, 'demo'));

        $options()->repair(true);
        $alpha = ConfigReader::load(GridConfig::simulatorsDirectory($machine->grid) . '/alpha.ini', expand: false);

        expect($alpha['Search']['SearchURL'] ?? null)
            ->toBe('https://web.example.org/helpers/query.php?gk=http://grid.example.org:8002')
            ->and($alpha['DataSnapshot']['DATA_SRV_2do'] ?? null)
            ->toBe('https://2do.directory/helpers/register.php')
            ->and($alpha['Gloebit']['GLBEnvironment'] ?? null)
            ->toBe('production')
            ->and($alpha['Economy']['economymodule'] ?? null)
            ->toBe('Gloebit')
            ->and($options()->repair(false)['simulators'])
            ->toBe([]);
    })->depends('are planned without their secrets, and laid in the OpenSim.ini of the grid without replacing what is there, once');

    test('are imported through the API, planned first, the secrets masked', function () {
        $machine = new Machine();
        $router = new OpenSim\Api\Router(new OpenSim\Api\Api($machine->environment));
        $ask = static fn(string $mode) => $router->post('/setups/imports', ['data' => ['type' => 'imports', 'attributes' => ['kind' => 'grid-settings', 'file' => GRID_SETTINGS, 'grid' => 'demo', 'mode' => $mode]]]);

        $plan = $ask('plan');
        $applied = $ask('apply');

        expect($plan->document['data']['attributes']['settings']['Gloebit']['GLBSecret'] ?? null)
            ->toBe('***')
            ->and($plan->document['data']['attributes']['settings']['Gloebit']['GLBSpecificConnectionString'] ?? null)
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
