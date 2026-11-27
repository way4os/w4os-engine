<?php

/**
 * opensim-profiles: what the packages do to w4os.conf when they are installed, upgraded or removed (the postinst scripts run it):
 *
 *   opensim-profiles.php [--conf FILE] [--cores FOLDER] [--default-modules "a b"] [--enable-module NAME] [--disable-module NAME]
 *
 * One profile per release found in the cores folder (/usr/share/opensim), the one of a release that is gone forgotten, the latest
 * release the default when there is none. With --default-modules, the modules are enabled for the install if it has no setting for
 * them yet. --enable-module and --disable-module add a module to EnabledModules or take it out (kickstart --with, an option that
 * needs one), the others kept.
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use W4OS\Engine\Config\PackagedProfiles;

// Its own libraries, the ones of the project it is a dependency of, or the ones that project names (a clone linked to it)
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php', (string) getenv('W4OS_AUTOLOAD')] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;

        break;
    }
}
if (!class_exists(PackagedProfiles::class)) {
    fwrite(STDERR, "opensim-profiles: the autoload of the engine is not found\n");
    exit(2);
}

$options = getopt('', ['conf:', 'cores:', 'default-modules:', 'enable-module:', 'disable-module:']);
$profiles = new PackagedProfiles($options['conf'] ?? \W4OS\Engine\Config\Layout::systemFile(), $options['cores'] ?? '/usr/share/opensim');
foreach ($profiles->update() as $note) {
    echo "opensim: $note\n";
}
if (isset($options['default-modules']) && $profiles->enableModulesOnce((string) $options['default-modules'])) {
    echo "opensim: modules enabled: {$options['default-modules']}\n";
}
foreach ([true => 'enable-module', false => 'disable-module'] as $on => $option) {
    foreach (isset($options[$option]) ? (array) $options[$option] : [] as $module) {
        $profiles->enableModule((string) $module, (bool) $on) && print('opensim: module ' . ($on ? 'enabled' : 'disabled') . ": $module\n");
    }
}
