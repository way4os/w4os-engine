<?php

declare(strict_types=1);

/**
 * What the packages and the options do to the modules of the install (EnabledModules of w4os.conf): the defaults of a first install
 * only, a module added or taken out on its own (kickstart --with, an option that needs one), the others kept.
 */

use W4OS\Engine\Config\IniEditor;
use W4OS\Engine\Config\Layout;
use W4OS\Engine\Config\PackagedProfiles;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\GridOptions;
use W4OS\Engine\Testing\Machine;

describe('The modules of the install', function () {
    test('are added and taken out one at a time, the others kept; the defaults of a first install never over a setting', function () {
        $conf = test_tmp() . '/w4os-' . bin2hex(random_bytes(4)) . '.conf';
        file_put_contents($conf, "[Defaults]\n    EnabledModules = opensimsearch\n");
        $profiles = new PackagedProfiles($conf);
        $modules = static fn(): string => (string) IniEditor::load($conf)->get('Defaults', 'EnabledModules');

        $added = [$profiles->enableModule('gloebit'), $modules(), $profiles->enableModule('gloebit')];
        $removed = [$profiles->enableModule('Gloebit', false), $modules()];

        expect($added)->toBe([true, 'opensimsearch gloebit', false])
            ->and($removed)->toBe([true, 'opensimsearch'])
            ->and($profiles->enableModulesOnce('opensimsearch gloebit'))->toBeFalse()
            ->and($modules())->toBe('opensimsearch');
    });

    test('get the one an option of a grid needs where this process can write the config, else say the command', function () {
        $machine = new Machine();
        $conf = test_tmp() . '/w4os-' . bin2hex(random_bytes(4)) . '.conf';
        file_put_contents($conf, "[Defaults]\n    EnabledModules = opensimsearch\n");
        $notes = static function (string $file) use ($machine): array {
            $environment = $machine->environment->withLayout(new Layout(['EnabledModules' => 'opensimsearch'] + $machine->environment->layout->all(), $file));

            return (new GridOptions(Grid::find($environment, 'demo')))->enable('economy-gloebit')['notes'];
        };

        $written = $notes($conf);
        chmod($conf, 0o444);
        $refused = is_writable($conf) ? null : $notes($conf);
        chmod($conf, 0o644);

        expect(implode("\n", $written))->toContain('the module gloebit is enabled for the install')
            ->and((string) IniEditor::load($conf)->get('Defaults', 'EnabledModules'))->toBe('opensimsearch gloebit')
            ->and($refused === null || str_contains(implode("\n", $refused), 'sudo opensim module enable gloebit'))->toBeTrue();
    });
});
