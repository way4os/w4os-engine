<?php

/**
 * opensim-complete-grids: what a package that is installed or upgraded adds to the grids it finds, without the setup and without
 * anybody: the files of the grid that are missing (the MoneyServer.ini the helpers need), and the options of a grid its simulators
 * miss, laid again (GridCompletion). What exists is not replaced, a second run does nothing.
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use W4OS\Engine\Config\Layout;
use W4OS\Engine\Provisioning\GridCompletion;
use W4OS\Engine\Runtime\Environment;

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;

        break;
    }
}
if (!class_exists(GridCompletion::class)) {
    fwrite(STDERR, "opensim-complete-grids: the autoload of the engine is not found\n");
    exit(2);
}

$layout = Layout::resolve();
foreach (GridCompletion::run(Environment::system($layout), $layout->etcRoot()) as $line) {
    echo "opensim: $line\n";
}
