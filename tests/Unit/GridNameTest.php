<?php

declare(strict_types=1);

/**
 * The name of a grid: the one its grid info gives (gridname of [GridInfoService]), never another setting of that name.
 */

use W4OS\Engine\Config\InstanceSettings;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Testing\Machine;

describe('The name of a grid', function () {
    test('is the gridname of its grid info, never a constant or a snapshot of the same name before it', function () {
        $machine = new Machine();
        $robust = $machine->grid . '/Robust.HG.ini';
        // A migrated grid: the constants of its old install name it by its old nick, its data snapshot too
        $text = str_replace("[Const]\n", "[Const]\n    GridName = \"kimbered\"\n", (string) file_get_contents($robust));
        file_put_contents($robust, "[DataSnapshot]\n    gridname = \"kimbered\"\n" . $text .
            "\n[GridInfoService]\n    gridname = \"Kimbe Red, pa moli (kimbe-red-charm)\"\n    gridnick = \"kimberedcharm\"\n");

        expect(InstanceSettings::of($robust)['gridName'] ?? null)->toBe('Kimbe Red, pa moli (kimbe-red-charm)')
            ->and(Grid::find($machine->environment, 'demo')?->name())->toBe('Kimbe Red, pa moli (kimbe-red-charm)');
    });

    test('has the host of its BaseURL when its config has no BaseHostname (an install of before the kit)', function () {
        $machine = new Machine();
        $robust = $machine->grid . '/Robust.HG.ini';
        $text = (string) preg_replace('/^\s*BaseHostname\s*=.*$/m', '', (string) file_get_contents($robust));
        file_put_contents($robust, str_replace("[Const]\n", "[Const]\n    BaseURL = \"http://demo.w4os.org\"\n", $text));

        expect(InstanceSettings::of($robust)['baseHostname'] ?? null)->toBe('demo.w4os.org');
    });
});
