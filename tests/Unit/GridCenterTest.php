<?php

declare(strict_types=1);

/**
 * The centre of a grid, where its new regions are placed around: the one it was given; else its default region's place; else its
 * first region's; else one drawn and kept. Never its port on both axes.
 */

use W4OS\Engine\Models\Grid;
use W4OS\Engine\Testing\Machine;

/** The region files of the simulator alpha of the test machine: Plaza at a place, and another one. */
function centre_regions(Machine $machine, array $places): void
{
    $folder = glob("{$machine->grid}/sims/*alpha*/regions", GLOB_ONLYDIR)[0] ?? "{$machine->grid}/sims/alpha/regions";
    foreach (glob("$folder/*.ini") ?: [] as $file) {
        unlink($file);
    }
    $n = 0;
    foreach ($places as $name => $location) {
        file_put_contents("$folder/$name.ini", "[$name]\nRegionUUID = 11111111-1111-4111-8111-" . str_pad((string) ++$n, 12, '0', STR_PAD_LEFT) . "\nLocation = $location\nInternalPort = 900$n\n");
    }
}

test('is the place of its default region when it has none given', function () {
    $machine = new Machine();
    centre_regions($machine, ['First Garden' => '3000,3000', 'Welcome' => '5100,6200']);
    file_put_contents("{$machine->grid}/Robust.HG.ini", "\n[GridService]\n    Region_Welcome = \"DefaultRegion, FallbackRegion\"\n", FILE_APPEND);

    expect(Grid::find($machine->environment, 'demo')->centerPlace())->toBe([5100, 6200]);
});

test('is the place of its first region when none is default', function () {
    $machine = new Machine();
    centre_regions($machine, ['First Garden' => '3000,3000', 'Welcome' => '5100,6200']);

    expect(Grid::find($machine->environment, 'demo')->centerPlace())->toBe([3000, 3000]);
});

test('of a grid without region is drawn once and kept, never its port on both axes', function () {
    $machine = new Machine();
    centre_regions($machine, []);
    $first = Grid::find($machine->environment, 'demo')->centerPlace();
    $again = Grid::find($machine->environment, 'demo')->centerPlace();

    expect($first)->toBe($again)
        ->and($first)->not->toBe([8002, 8002])
        ->and([$first[0] % 100, $first[1] % 100])->toBe([0, 0])
        ->and(Grid::find($machine->environment, 'demo')->center())->toBe(implode(',', $first));
});
