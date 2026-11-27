<?php

declare(strict_types=1);

/**
 * An install of before the kit (/opt/opensim of opensim-debian): found by its library or its cron, its grids and simulators by their
 * configs, its instances by their processes, its start at boot by its cron and units.
 */

use W4OS\Engine\Provisioning\Import\LegacyInstall;

/** An install of before the kit in a folder: a grid W4osDemoGrid, a simulator, its cron, a unit, a process for each config. */
function legacy_install_fixture(): array
{
    $base = sys_get_temp_dir() . '/legacy-' . bin2hex(random_bytes(4));
    $root = "$base/opt/opensim";
    foreach (["$root/lib", "$root/etc/robust.d", "$root/etc/opensim.d", "$base/cron", "$base/systemd", "$base/proc/101", "$base/proc/102"] as $dir) {
        mkdir($dir, 0o755, true);
    }
    file_put_contents("$root/lib/os-helpers", '');
    file_put_contents("$root/etc/robust.d/W4osDemoGrid.ini", "[GridInfoService]\n    gridnick = \"W4osDemoGrid\"\n");
    file_put_contents("$root/etc/opensim.d/w4osdemogrid_welcome.ini", "[Startup]\n");
    file_put_contents("$base/cron/opensim", "#!/bin/sh\n$root/bin/opensim start\n");
    file_put_contents("$base/systemd/opensim.service", "[Service]\nExecStart=$root/bin/opensim start\n");
    file_put_contents("$base/proc/101/cmdline", "mono\0Robust.exe\0-inifile=$root/etc/robust.d/W4osDemoGrid.ini\0");
    file_put_contents("$base/proc/102/cmdline", "mono\0OpenSim.exe\0-inifile\0'$root/etc/opensim.d/w4osdemogrid_welcome.ini'\0");

    return ['base' => $base, 'install' => new LegacyInstall($root, "$base/cron/opensim", ["$base/systemd/opensim.service"], "$base/proc")];
}

describe('An install of before the kit', function () {
    test('is found by its library, its grids and simulators by their configs, its grid by its nick', function () {
        ['install' => $install, 'base' => $base] = legacy_install_fixture();

        expect($install->found())->toBeTrue()
            ->and(array_map('basename', $install->robusts()))->toBe(['W4osDemoGrid.ini'])
            ->and(array_map('basename', $install->simulators()))->toBe(['w4osdemogrid_welcome.ini'])
            ->and($install->nick($install->robusts()[0]))->toBe('w4osdemogrid')
            ->and($install->summary())->toBe("Found an existing install in $base/opt/opensim: grid w4osdemogrid, 1 simulator.");
    });

    test('runs its instances, found by the config each process was given, however it was given', function () {
        ['install' => $install] = legacy_install_fixture();

        expect($install->pids($install->robusts()[0]))->toBe([101])
            ->and($install->pids($install->simulators()[0]))->toBe([102])
            ->and(array_map('basename', $install->running()))->toBe(['w4osdemogrid_welcome.ini', 'W4osDemoGrid.ini']);
    });

    test('starts at boot by its cron and its own unit, never one of the kit', function () {
        ['install' => $install, 'base' => $base] = legacy_install_fixture();
        $kit = new LegacyInstall($install->root, "$base/cron/opensim", ["$base/systemd/kit.service"], "$base/proc");
        file_put_contents("$base/systemd/kit.service", "# opensim service of the kit\n");

        expect($install->starters())->toBe(["$base/cron/opensim", "$base/systemd/opensim.service"])
            ->and($kit->starters())->toBe(["$base/cron/opensim"]);
    });

    test('is not there when neither its library nor a cron naming it is', function () {
        $base = sys_get_temp_dir() . '/legacy-none-' . bin2hex(random_bytes(4));

        expect((new LegacyInstall("$base/opt/opensim", "$base/cron/opensim", [], "$base/proc"))->found())->toBeFalse();
    });
});
