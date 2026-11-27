<?php

/**
 * opensim-layout [SETTING=FOLDER...]: makes the system layout of the machine (the account the instances run as, the folders,
 * w4os.conf) when it is not there, as root. Run by the package after its installation; nothing is done twice. With folders given
 * (EtcRoot=/srv/opensim/etc), they are written in w4os.conf first and made.
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use W4OS\Engine\Config\Layout;
use W4OS\Engine\Provisioning\NullUi;
use W4OS\Engine\Provisioning\SystemLayout;

// Its own libraries, the ones of the project it is a dependency of, or the ones that project names (a clone linked to it)
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php', (string) getenv('W4OS_AUTOLOAD')] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;

        break;
    }
}
if (!class_exists(SystemLayout::class)) {
    fwrite(STDERR, "opensim-layout: the autoload of the engine is not found\n");
    exit(2);
}

$ui = new NullUi(live: true);
// The folders a person placed (EtcRoot=/srv/opensim/etc ...): written in w4os.conf first, or given to a file not made yet
$roots = [];
foreach (array_slice($argv, 1) as $argument) {
    [$key, $path] = explode('=', $argument, 2) + ['', ''];
    $roots[$key] = $path;
}
try {
    SystemLayout::setRoots(Layout::systemFile(), $roots);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, 'opensim-layout: ' . $error->getMessage() . "\n");
    exit(2);
}
if (!is_file(Layout::systemFile())) {
    foreach ($roots as $key => $path) {
        putenv(Layout::environmentName($key) . '=' . $path);
    }
}
$made = (new SystemLayout($ui))->prepare(Layout::resolve());
foreach ($ui->said as [$level, $message]) {
    $level === 'error' && fwrite(STDERR, "opensim-layout: $message\n");
}
exit($made ? 0 : 1);
