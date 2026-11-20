<?php

declare(strict_types=1);

/**
 * What a package that is installed or upgraded does to the grids of the machine, without anybody: the files a grid is missing, and
 * the options of a grid its simulators miss laid again (the repair of the options), so that an upgrade needs nothing by hand.
 */

use OpenSim\Config\GridConfig;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\GridCompletion;
use OpenSim\Provisioning\GridOptions;
use OpenSim\Testing\Machine;

describe('The completion of the grids at an upgrade', function () {
    test('lays again the options their simulators miss, once: a second run has nothing to do', function () {
        $machine = new Machine();
        $before = (new GridOptions(Grid::find($machine->environment, 'demo')))->repair(false)['simulators'];

        $first = GridCompletion::run($machine->environment, $machine->etc);
        $second = GridCompletion::run($machine->environment, $machine->etc);

        expect($before)
            ->toHaveKey('alpha')
            ->and(implode("\n", $first))
            ->toContain('demo: options laid again', 'profiles', 'restart')
            ->and($second)
            ->toBe([])
            ->and((string) file_get_contents(GridConfig::simulatorsDirectory($machine->grid) . '/alpha.ini'))
            ->toContain('[UserProfiles]');
    });

    test('repairs, never changes a setting: a valid choice of a grid that is not the default stays, with nothing of the default beside it', function () {
        $machine = new Machine();
        $alpha = GridConfig::simulatorsDirectory($machine->grid) . '/alpha.ini';
        // The offline messages V1, kept by another site: not the default (V2), a choice
        file_put_contents($alpha, (string) file_get_contents($alpha) . "\n[Messaging]\n    OfflineMessageModule = OfflineMessageModule\n    OfflineMessageURL = \"https://elsewhere.example.org/offline.php\"\n");

        GridCompletion::run($machine->environment, $machine->etc);
        $messaging = OpenSim\Config\ConfigReader::load($alpha, expand: false)['Messaging'] ?? [];

        expect($messaging)
            ->toBe(['OfflineMessageModule' => 'OfflineMessageModule', 'OfflineMessageURL' => 'https://elsewhere.example.org/offline.php'])
            ->and((new GridOptions(Grid::find($machine->environment, 'demo')))->repair(false)['simulators']['alpha'] ?? [])
            ->not->toContain('offline-robust');
    });

    test('gives every ini of a grid, the MoneyServer.ini it adds too, mode 640 and the group of the web server when the machine has one', function () {
        $machine = new Machine();
        $alpha = GridConfig::simulatorsDirectory($machine->grid) . '/alpha.ini';
        chmod($alpha, 0o644);
        file_put_contents($machine->grid . '/Helpers.ini', "[Helpers]\n");
        chmod($machine->grid . '/Helpers.ini', 0o664);
        // The MoneyServer.ini made from an original, as the completion does when a grid with helpers has none
        $original = $machine->root . '/MoneyServer.ini.original';
        file_put_contents($original, "[MoneyServer]\n");

        GridCompletion::run($machine->environment, $machine->etc);
        OpenSim\Provisioning\MoneyServerConfig::write($machine->grid, $original);
        $files = [$alpha, $machine->grid . '/Helpers.ini', $machine->grid . '/MoneyServer.ini'];
        clearstatcache();

        expect(array_map(static fn(string $file): int => fileperms($file) & 0o777, $files))->toBe([0o640, 0o640, 0o640]);
        // The group is given when this user may give it (a member of the group of the web server, as the account of the instances is)
        $gid = OpenSim\Config\GridFileService::webGroup();
        if ($gid !== null && in_array($gid, posix_getgroups(), true)) {
            expect(array_map(static fn(string $file): int => filegroup($file), $files))->toBe([$gid, $gid, $gid]);
        }
    });
});
