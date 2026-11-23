<?php

/**
 * w4os-launch-master: the main process of opensim.service (ExecStart of the unit, through opensim service). It listens on
 * /run/opensim/launch.sock (OPENSIM_LAUNCH_SOCKET) and starts the instances it is asked to, as children of the service. It tells
 * systemd it is ready once it listens (Type=notify).
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use W4OS\Engine\Runtime\LaunchMaster;

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

try {
    (new LaunchMaster(LaunchMaster::socket()))->serve(static function (): void {
        getenv('NOTIFY_SOCKET') && exec('systemd-notify --ready');
        fwrite(STDERR, 'w4os-launch-master: listening on ' . LaunchMaster::socket() . "\n");
    });
} catch (\RuntimeException $error) {
    fwrite(STDERR, 'w4os-launch-master: ' . $error->getMessage() . "\n");
    exit(1);
}
