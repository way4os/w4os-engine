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

    /** A value that is an address: a URL, or the constant of one (${Const|BaseURL}:${Const|PublicPort}). */
    private const ADDRESS = '~^(https?://|\$\{Const\|\w*(URL|URI|Host)\w*\})~i';

    /** Why the kit keeps a path over the original's. */
    public const PATH_WHY = 'a path of the install';

    /** A value that is a path: absolute, relative to a folder, from the constant of one, or with a folder in it (assets/AssetSets.xml). */
    private const PATH = '~^(/|\.\.?/|\$\{Const\|\w*(Dir|Path)\w*\}|[\w.-]+/[^\s:,;]*$)~i';

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

    /** Whether a value is a path: absolute, relative to a folder, from the constant of one, or with a folder in it. */
    public static function path(string $value): bool
    {
        return preg_match(self::PATH, trim($value, " \t\"")) === 1;
    }

    /**
     * Why the kit keeps its value over the original's, null when the original's wins: what the kit needs to work, the architecture
     * of its grid (its modules and services), the addresses of its grid, its paths. Told by the values the kit and the original
     * give, never by the name of the key: the permissions of OSSL and of the script engines are the original's, whatever they name.
     *
     * @param array{section:string,key:string,value:string,kit?:string} $setting
     */
    public static function kept(array $setting): ?string
    {
        $values = array_map(static fn(string $value): string => trim($value, " \t\""), [$setting['value'], $setting['kit'] ?? '']);
        $any = static fn(string $pattern): bool => preg_grep($pattern, $values) !== [];

        return match (true) {
            strcasecmp($setting['section'], 'OSSL') === 0 || stripos($setting['key'], 'Allow') === 0 => null,
            strcasecmp($setting['section'], 'Modules') === 0 => 'the architecture of the grid',
            $any('/\.dll:/i') => 'a service of the architecture of the grid',
            $any(self::ADDRESS) => 'an address of the grid',
            self::path($values[0]) || self::path($values[1]) => self::PATH_WHY,
            default => null,
        };
    }
}
