<?php

/**
 * w4os-api: the API of the engine on the command line (run by the script w4os-api), the same addresses and answers as the web (see the contract, docs/api/).
 *
 *   w4os-api [GET|POST|PATCH|DELETE] PATH [key=value...] [--data=JSON|@FILE|-] [--grid=NICK]
 *
 *   w4os-api GET /forms/grid
 *   w4os-api --grid=demo GET /monitor format=text
 *   w4os-api --version [-v]          the version of the engine; with -v, of everything that matters here
 *
 * The install is the one of w4os.conf (or the OPENSIM_* variables). The status is 0 for a success, 1 for a problem, 2 for a command
 * that is not understood.
 */

declare(strict_types=1);

// What PHP and the libraries say about themselves goes to the error output, never in the answer; the deprecations of a library are not
// for whoever reads the answer
ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Cli;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Config\Layout;
use W4OS\Engine\Runtime\Environment;
use W4OS\Engine\Scheduling\Scheduler;

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;

        break;
    }
}
if (!class_exists(Router::class)) {
    fwrite(STDERR, "w4os-api: the autoload of the engine is not found (composer install)\n");
    exit(2);
}

$args = array_slice($_SERVER['argv'], 1);
// The version of the engine; with -v, of everything that matters on this machine
if (in_array('--version', $args, true) || in_array('-V', $args, true)) {
    if (array_intersect(['-v', '--verbose'], $args) === []) {
        echo 'w4os-engine ' . \W4OS\Engine\Support\Versions::engine() . "\n";
        exit(0);
    }
    try {
        $environment = Environment::system(Layout::resolve());
    } catch (\RuntimeException $e) {
        fwrite(STDERR, 'w4os-api: ' . $e->getMessage() . "\n");
        exit(2);
    }
    foreach (\W4OS\Engine\Support\Versions::all($environment) as $name => $version) {
        printf("%-24s %s\n", $name, $version);
    }
    exit(0);
}
$grid = null;
foreach ($args as $index => $arg) {
    if (str_starts_with($arg, '--grid=')) {
        $grid = substr($arg, 7);
        unset($args[$index]);
    }
}

try {
    $environment = Environment::system(Layout::resolve());
} catch (\RuntimeException $e) {
    fwrite(STDERR, 'w4os-api: ' . $e->getMessage() . "\n");
    exit(2);
}
$router = new Router(new Api($environment, Scheduler::for($environment)), grid: $grid);
[$status, $output, $error] = (new Cli($router))->run(
    array_values($args),
    static fn(): string => (string) stream_get_contents(STDIN),
);
fwrite(STDOUT, $output);
fwrite(STDERR, $error);
$router->terminate();

exit($status);
