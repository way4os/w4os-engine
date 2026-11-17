<?php

/**
 * Run in a container by tests/Environment/SimulatorImportContainerTest.php: the install of opensim-debian at its real place,
 * /opt/opensim (a 0.9.2 Speculoos server: its config in etc/opensim.d, the grid's shared include, its region in
 * etc/grids/<grid>/sims/<sim>/regions), imported into the grid of a test machine, planned then applied. Says what happened in JSON.
 */

use OpenSim\Provisioning\Import\SimulatorImport;
use OpenSim\Provisioning\NullUi;
use OpenSim\Testing\Machine;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$etc = '/opt/opensim/etc';
$files = [
    "$etc/grids/speculoos/OpenSim.ini" => "[DatabaseService]\nStorageProvider = \"OpenSim.Data.MySQL.dll\"\n[XEngine]\nMinThreads = 10\n",
    "$etc/grids/speculoos/sims/redoutable/regions/Redoutable.ini" =>
        "[Redoutable]\nRegionUUID = 11111111-2222-4333-8444-555555555555\nLocation = 1000,1000\nSizeX = 256\nInternalPort = 9251\nExternalHostName = redoutable.speculoos.world\n",
    "$etc/opensim.d/redoutable.ini" =>
        "[Launch]\nSimName = redoutable\nBinDir = /opt/opensim/lib/opensim-0.9.2.2/bin\n" .
        "[Const]\nEtcDirectory = \"$etc\"\nDataDirectory = \"/opt/opensim/var/redoutable\"\n" .
        "[Startup]\nregionload_regionsdir = \"\${Const|EtcDirectory}/grids/speculoos/sims/redoutable/regions\"\nInclude-Grid = \"$etc/grids/speculoos/OpenSim.ini\"\n" .
        "[Network]\nhttp_listener_port = 9250\n" .
        "[DatabaseService]\nConnectionString = \"Data Source=localhost;Database=osredoutable;User ID=opensim;Password=secret;Old Guids=true;\"\n",
];
foreach ($files as $path => $text) {
    is_dir(dirname($path)) || mkdir(dirname($path), 0o755, true);
    file_put_contents($path, $text);
    chmod($path, 0o640);
}

$machine = new Machine();
$import = SimulatorImport::prepare($machine->environment, "$etc/opensim.d/redoutable.ini", 'demo');
$plan = $import->document();
$writtenByPlan = array_values(array_filter($plan['writes'], 'file_exists'));
$import->apply($machine->environment, new NullUi());

echo json_encode([
    'plan' => $plan,
    'writtenByPlan' => $writtenByPlan,
    'mode' => decoct(fileperms($plan['config']) & 0o777),
    'config' => file_get_contents($plan['config']),
    'region' => file_get_contents($plan['regions'][0]['to']),
    'original' => file_get_contents("$etc/grids/speculoos/sims/redoutable/regions/Redoutable.ini"),
], JSON_UNESCAPED_SLASHES);
