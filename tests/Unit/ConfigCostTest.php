<?php

declare(strict_types=1);

/**
 * What a page of the helpers costs in reading the configs of its grid: each file parsed once, however many services ask for it.
 */

use W4OS\Engine\Config\ConfigReader;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Testing\Machine;

test('the paths of the helpers of a grid read each file of its chain once', function () {
    $machine = new Machine();
    $grid = Grid::find($machine->environment, 'demo');
    $files = count(ConfigReader::files($grid->robustIni()));
    ConfigReader::$parsed = 0;

    $grid->helperPaths();
    $grid->helperPaths();

    expect(ConfigReader::$parsed)->toBeLessThanOrEqual($files + 4);
});

test('a file changed since it was read is read again', function () {
    $file = sys_get_temp_dir() . '/cost-' . bin2hex(random_bytes(4)) . '.ini';
    file_put_contents($file, "[A]\nkey = one\n");
    $first = ConfigReader::file($file)['A']['key'];
    file_put_contents($file, "[A]\nkey = two, longer\n");
    $second = ConfigReader::file($file)['A']['key'];
    unlink($file);

    expect([$first, $second])->toBe(['one', 'two, longer']);
});
