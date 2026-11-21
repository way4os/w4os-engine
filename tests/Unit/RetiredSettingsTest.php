<?php

declare(strict_types=1);

/**
 * The settings of an older config that stop a simulator of a newer core (Warp3DImageModule on 0.9.3): not kept by an import,
 * commented at an upgrade, left to the core's default.
 */

use W4OS\Engine\Provisioning\GridCompletion;
use W4OS\Engine\Provisioning\Import\SimulatorImport;
use W4OS\Engine\Provisioning\RetiredSettings;
use W4OS\Engine\Testing\Machine;

describe('A setting that stops a simulator of its core', function () {
    test('is told by the version of the core', function () {
        expect(RetiredSettings::why('Map', 'MapImageModule', '"Warp3DImageModule"', '/usr/share/opensim/0.9.3.0'))->toContain('MapImageModule')
            ->and(RetiredSettings::why('Map', 'MapImageModule', 'Warp3DImageModule', '/opt/opensim/lib/opensim-0.9.2.2'))->toBeNull()
            ->and(RetiredSettings::why('Map', 'MapImageModule', 'MapImageModule', '/usr/share/opensim/0.9.3.0'))->toBeNull()
            ->and(RetiredSettings::why('Startup', 'MapImageModule', 'Warp3DImageModule', '/usr/share/opensim/0.9.3.0'))->toBeNull();
    });

    test('is not kept by the import of a simulator', function () {
        $machine = new Machine();
        $file = old_install($machine);
        $machine->write($file, (string) file_get_contents($file) . "[Map]\nMapImageModule = \"Warp3DImageModule\"\n");

        $plan = SimulatorImport::prepare($machine->environment, $file, 'demo')->document();
        $retired = array_filter($plan['dropped'], static fn(array $d): bool => $d['reason'] === 'retired');

        expect(array_column($plan['settings'], 'key'))->not->toContain('MapImageModule')
            ->and(array_column($retired, 'key'))->toBe(['MapImageModule']);
    });

    test('is commented at an upgrade, with why, the other lines and the comments left as they are', function () {
        $machine = new Machine();
        $sim = glob($machine->grid . '/sims/*.ini')[0];
        $machine->write($sim, (string) file_get_contents($sim) . "\n[Map]\n    ; MapImageModule = \"Warp3DImageModule\"\n    MapImageModule = \"Warp3DImageModule\"\n    GenerateMaptiles = true\n");

        $done = implode("\n", GridCompletion::run($machine->environment, $machine->etc));
        $again = implode("\n", GridCompletion::run($machine->environment, $machine->etc));
        $text = (string) file_get_contents($sim);

        expect($done)->toContain('1 setting that stop a simulator of its core commented in sims/')
            ->and($again)->not->toContain('stop a simulator')
            ->and($text)->toContain('    ; MapImageModule = "Warp3DImageModule" ; Warp3DImageModule stops a simulator of OpenSimulator 0.9.3')
            ->toContain("    ; MapImageModule = \"Warp3DImageModule\"\n")
            ->toContain('    GenerateMaptiles = true');
    });
});
