<?php

/**
 * opensim-pools: writes the PHP-FPM pools of the suite (the engine API, the helpers) into the pools of the newest PHP-FPM, as root,
 * each only when it changes, and names the files written, one a line. The group of the web server that reaches their sockets is
 * --web-group, else the one the installed pool of the API gives, else www-data. --if-installed writes them only on a machine whose
 * pool of the API is there already (the upgrade of the package). Nothing without PHP-FPM.
 *
 *     php opensim-pools.php [--web-group GROUP] [--if-installed]
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use W4OS\Engine\Config\Layout;
use W4OS\Engine\Provisioning\Web\PhpPools;

// The autoload of the project the engine is a dependency of first (the command line), else its own
foreach ([__DIR__ . '/../../../autoload.php', __DIR__ . '/../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;

        break;
    }
}
if (!class_exists(PhpPools::class)) {
    fwrite(STDERR, "opensim-pools: the autoload of the engine is not found\n");
    exit(2);
}

$options = getopt('', ['web-group:', 'if-installed']);
$folder = PhpPools::folder();
if ($folder === '' || (isset($options['if-installed']) && !PhpPools::installed('w4os-api'))) {
    exit(0);
}
$group = (string) ($options['web-group'] ?? '') ?: (PhpPools::listenGroup("$folder/w4os-api.conf") ?? 'www-data');
try {
    foreach (PhpPools::write($folder, PhpPools::texts(Layout::resolve(), $group)) as $file) {
        echo $file, "\n";
    }
} catch (\RuntimeException $error) {
    fwrite(STDERR, 'opensim-pools: ' . $error->getMessage() . "\n");
    exit(1);
}
