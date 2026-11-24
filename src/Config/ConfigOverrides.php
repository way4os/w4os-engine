<?php

declare(strict_types=1);

namespace W4OS\Engine\Config;

/**
 * The files a simulator reads after the rest of its config, OpenSimulator's `-inidirectory`: the `*.ini` of the `config` folder
 * beside its own file (`sims/<slug>/config`). A setting there wins over the master of the grid, the simulator's file and every file
 * they include. An import writes there the settings of the original that a file of the kit, read later, sets otherwise.
 *
 *     ConfigOverrides::files('/etc/opensim/grids/demo/sims/demo.welcome.ini');   // [.../sims/demo.welcome/config/imported.ini]
 */
final class ConfigOverrides
{
    /** The file an import writes in the folder. */
    public const IMPORTED = 'imported.ini';

    /** The folder, for the file of a simulator (its real place: opensim.d links to the grid's). */
    public static function directory(string $ini): string
    {
        $real = realpath($ini) ?: $ini;

        return dirname($real) . '/' . basename($real, '.ini') . '/config';
    }

    /**
     * The `*.ini` of the folder, sorted; none when it is not there.
     *
     * @return list<string>
     */
    public static function files(string $ini): array
    {
        $found = glob(self::directory($ini) . '/*.ini') ?: [];
        sort($found);

        return $found;
    }

    /**
     * The settings a file read after the simulator's own sets otherwise: in its own file they would lose. Each with the file that
     * would win and what it says.
     *
     * @param list<array{section:string,key:string,value:string}> $settings
     * @param list<string> $chain the files of its config in the order OpenSimulator reads them (ConfigReader::files([master, ini]))
     * @return list<array{section:string,key:string,value:string,file:string,kit:string}>
     */
    public static function lost(array $settings, string $ini, array $chain): array
    {
        $real = realpath($ini) ?: $ini;
        $at = array_search($real, array_map(static fn(string $file): string => realpath($file) ?: $file, $chain), true);
        $after = $at === false ? [] : array_slice($chain, $at + 1);
        // Who sets what last, keys and sections whatever their case
        $last = [];
        foreach ($after as $file) {
            foreach (ConfigReader::file($file) as $section => $values) {
                foreach ($values as $key => $value) {
                    $last[strtolower($section)][strtolower((string) $key)] = ['value' => trim((string) $value, " \t\""), 'file' => $file];
                }
            }
        }
        $lost = [];
        foreach ($settings as $setting) {
            $wins = $last[strtolower($setting['section'])][strtolower($setting['key'])] ?? null;
            if ($wins !== null && $wins['value'] !== trim($setting['value'], " \t\"")) {
                $lost[] = $setting + ['file' => $wins['file'], 'kit' => $wins['value']];
            }
        }

        return $lost;
    }

    /**
     * Why the kit keeps its value over the original's, null when the original's wins: what the kit needs to work, the architecture
     * of its grid (its modules and services), the addresses of its grid, its folders. The settings of the grid and of the simulator
     * (OSSL, cache...) are the original's.
     *
     * @param array{section:string,key:string,value:string} $setting
     */
    public static function kept(array $setting): ?string
    {
        return match (true) {
            strcasecmp($setting['section'], 'Modules') === 0 => 'the architecture of the grid',
            stripos($setting['value'], '.dll:') !== false => 'a service of the architecture of the grid',
            preg_match('/(URI|URL|Gatekeeper)$/i', $setting['key']) === 1 => 'an address of the grid',
            preg_match('/(Directory|Path|Dir|Location)$/i', $setting['key']) === 1 => 'a folder of the install',
            default => null,
        };
    }
}
