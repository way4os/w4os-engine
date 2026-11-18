<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

/**
 * Enable the modules of the install for an instance: their files linked in the addins folder of its registry, where OpenSim
 * loads add-ins from, so that a core kept read-only needs nothing written in its bin folder. The modules to enable are the
 * ones the install names, looked for under the name of the core folder or its version. What was linked for a module no longer
 * enabled is removed.
 *
 * A module that has a config of its own (`<Name>.ini.example` beside its files, as Gloebit) copies it at its start into the
 * `ConfigDirectory` of `[Startup]` when it is not there (Util.MergeConfigurationFile), and stops to ask for it to be edited: the
 * config of the instance has a `<Name>.ini` there, without a setting (the ones of the example would replace those of the grid,
 * which are in its OpenSim.ini), and one that is there is left as it is.
 */
final class ModuleLinker
{
    /**
     * @param list<string> $modules the modules enabled
     * @param string $root where the modules are, by core
     * @param ?string $configs the ConfigDirectory of the instance, where the modules find their own config
     * @return list<string> what was done, one line each (for the debug)
     */
    public static function sync(string $addins, Core $core, array $modules, string $root, ?string $configs = null): array
    {
        $notes = [];
        $wanted = [];
        $version = preg_match('/\d+(?:\.\d+)+/', $core->name(), $m) ? $m[0] : '';

        foreach ($modules as $module) {
            $directory = '';
            foreach (array_filter([$core->name(), $version]) as $name) {
                if (is_dir("$root/$name/$module")) {
                    $directory = "$root/$name/$module";

                    break;
                }
            }
            if ($directory === '') {
                $notes[] = "module $module: not installed for {$core->name()}, skipped";

                continue;
            }
            if (!is_dir($addins) && !mkdir($addins, 0o755, true) && !is_dir($addins)) {
                return $notes;
            }
            foreach (glob("$directory/*") ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $configs === null || !str_ends_with($file, '.ini.example') || array_push($notes, ...self::config($configs, basename($file, '.example'), $module));
                $link = "$addins/" . basename($file);
                if (is_link($link) || is_file($link)) {
                    unlink($link);
                }
                symlink($file, $link);
                $wanted[$link] = true;
            }
            $notes[] = "module $module enabled from $directory";
        }

        // What was linked for a module that is not enabled any more
        foreach (is_dir($addins) ? (glob("$addins/*") ?: []) : [] as $file) {
            if (is_link($file) && str_starts_with((string) readlink($file), "$root/") && !isset($wanted[$file])) {
                unlink($file);
                $notes[] = 'module file ' . basename($file) . ' removed';
            }
        }

        return $notes;
    }

    /**
     * The config of a module in the folder of the instance, without a setting, when it is not there.
     *
     * @return list<string>
     */
    private static function config(string $configs, string $name, string $module): array
    {
        if (is_file("$configs/$name")) {
            return [];
        }
        if (!is_dir($configs) && !@mkdir($configs, 0o755, true) && !is_dir($configs)) {
            return ["module $module: $configs cannot be made, $name not written"];
        }
        $text = "; The config of the module $module is in the OpenSim.ini of the grid or of the simulator (opensim enable): a setting\n"
            . "; written here replaces it. Made so that the module does not copy its example here, nor in the core.\n";

        return @file_put_contents("$configs/$name", $text) === false ? ["module $module: $configs/$name not written"] : ["module $module: $configs/$name made"];
    }
}
