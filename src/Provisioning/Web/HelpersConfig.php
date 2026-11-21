<?php

namespace W4OS\Engine\Provisioning\Web;

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Config\GridFileService;
use W4OS\Engine\Config\IniEditor;

/**
 * The web side of a grid. w4os-helpers read the official settings of the grid (Robust.ini, OpenSim.ini, the ini of its
 * simulators) in their own format, one source of truth: the setup writes nothing of them in another file.
 *
 * `Helpers.ini`, in the folder of the grid, only holds what has no official place, in a section [Helpers] (the source of the
 * guide, the search database, the theme...): the setup copies the commented template of the preset `helpers` once, the rest is
 * the operator's.
 */
final class HelpersConfig
{
    public const FILE = 'Helpers.ini';

    /** Where the helpers are, when Helpers.ini and the economy of the grid say nothing. */
    public const DEFAULT_PATH = '/helpers';

    /** The setting of [Helpers] in Helpers.ini that says where the helpers are: the base of every page they serve. */
    public const URL_KEY = 'HelpersURL';

    public static function path(string $gridDir): string
    {
        return rtrim($gridDir, '/') . '/' . self::FILE;
    }

    /**
     * The files of a grid folder named like the file of the helpers in another case (`helpers.ini` of the first setups): a
     * system that does not tell the cases apart (macOS, Windows) cannot hold both. The only one is renamed, the second one is
     * kept aside as `<name>~`.
     *
     * @return list<array{0:string,1:string}> the names it had and the names it has now
     */
    public static function unifyCase(string $gridDir): array
    {
        $gridDir = rtrim($gridDir, '/');
        $variants = array_values(
            array_filter(
                scandir($gridDir) ?: [],
                static fn(string $name): bool => $name !== self::FILE && strcasecmp($name, self::FILE) === 0,
            ),
        );
        $moved = [];
        foreach ($variants as $name) {
            $to = in_array(self::FILE, scandir($gridDir) ?: [], true) ? "$name~" : self::FILE;
            if (@rename("$gridDir/$name", "$gridDir/$to")) {
                $moved[] = [$name, $to];
            }
        }

        return $moved;
    }

    /**
     * The config of the services of a grid folder, empty when it has none: its Robust config, or for a standalone the master of its
     * simulator (the constants) and its StandaloneCommon.ini (what the viewers are told).
     */
    private static function robust(string $gridDir): string
    {
        foreach (['Robust.HG.ini', 'Robust.ini'] as $file) {
            if (is_file("$gridDir/$file")) {
                return GridFileService::read("$gridDir/$file");
            }
        }
        $text = '';
        foreach (['OpenSimGridDefaults.ini', 'config-include/StandaloneCommon.ini'] as $file) {
            is_file("$gridDir/$file") && ($text .= GridFileService::read("$gridDir/$file") . "\n");
        }

        return $text;
    }

    /** The setting of the Robust config of a grid (`WebURL`, `economy`...), empty when it has not; of the config given for one. */
    private static function robustValue(string|GridConfig $grid, string $key): string
    {
        if ($grid instanceof GridConfig) {
            foreach (['Const', 'GridInfoService'] as $section) {
                $value = trim((string) ($grid->get("Robust.$section.$key") ?? $grid->find($section, $key) ?? ''), " \t\"");
                if ($value !== '') {
                    return $value;
                }
            }

            return '';
        }
        $gridDir = $grid;
        $value = preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*"?([^"\r\n]*?)"?\s*$/m', self::robust($gridDir), $m)
            ? trim($m[1])
            : '';

        // A constant of the files (${Const|BaseURL}:8830): the value the executables read
        return str_contains($value, '${Const|') ? self::robustValue(GridConfig::fromFolder($gridDir), $key) : $value;
    }

    /** The address of the web site of the grid: the `WebURL` of its Robust config. */
    public static function webUrl(string|GridConfig $grid): string
    {
        return self::robustValue($grid, 'WebURL');
    }

    /** A setting of [Helpers] in Helpers.ini (of the config given for a grid), empty when it has none. */
    public static function value(string|GridConfig $grid, string $key): string
    {
        if ($grid instanceof GridConfig) {
            return trim((string) ($grid->get("Helpers.Helpers.$key") ?? ''), " \t\"");
        }
        $file = self::path($grid);

        return is_file($file) ? trim((string) IniEditor::load($file)->get('Helpers', $key)) : '';
    }

    /** The address of the helpers Helpers.ini gives (`HelpersURL`), without its last slash; empty when it gives none. */
    public static function configuredUrl(string|GridConfig $grid): string
    {
        return rtrim(self::value($grid, self::URL_KEY), '/');
    }

    /** Where the helpers are: the path of `HelpersURL` of Helpers.ini, else of the economy the grid gives to its viewers. */
    public static function basePath(string|GridConfig $grid): string
    {
        $configured = self::value($grid, self::URL_KEY);
        if ($configured !== '') {
            return Services::pathOf($configured);
        }
        $economy = self::robustValue($grid, 'economy');

        return $economy === '' ? self::DEFAULT_PATH : Services::pathOf($economy);
    }

    /** The services as the grid has them. */
    public static function services(string|GridConfig $grid): Services
    {
        return new Services(self::basePath($grid));
    }
}
