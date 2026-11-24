<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning;

/**
 * The settings of an older config that stop a simulator of a newer core, left to the core's default: on an import (the setting is
 * not kept) and at an upgrade (the line is commented, with why).
 *
 *     RetiredSettings::why('Map', 'MapImageModule', 'Warp3DImageModule', '/usr/share/opensim/0.9.3.0');   // the reason, else null
 *     RetiredSettings::repair('/etc/opensim/grids/demo/sims/demo.welcome.ini', '/usr/share/opensim/0.9.3.0');   // lines commented
 */
final class RetiredSettings
{
    /**
     * Section, key, the values that stop the core, the first version of the core they stop, why.
     *
     * @var list<array{0:string,1:string,2:string,3:string,4:string}>
     */
    public const RULES = [
        ['Map', 'MapImageModule', '/^warp3dimagemodule$/i', '0.9.3', 'Warp3DImageModule stops a simulator of OpenSimulator 0.9.3 (its map tiles): its default, MapImageModule'],
        ['Modules', 'AssetServices', '/^hgassetbroker$/i', '0.9.3', 'OpenSimulator 0.9.3 has no HGAssetBroker: the one of its architecture, RegionAssetConnector'],
        ['Modules', 'GridServices', '/^remotegridservicesconnector$/i', '0.9.3', 'OpenSimulator 0.9.3 has no RemoteGridServicesConnector: the one of its architecture, RegionGridServicesConnector'],
    ];

    /** Why a setting stops a simulator of this core, null when it does not. */
    public static function why(string $section, string $key, string $value, string $core): ?string
    {
        $version = self::version($core);
        foreach (self::RULES as [$inSection, $named, $values, $from, $reason]) {
            if (strcasecmp($section, $inSection) === 0 && strcasecmp($key, $named) === 0
                && preg_match($values, trim($value, " \t\"")) === 1 && $version !== null && version_compare($version, $from, '>=')) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * The lines of a config that stop a simulator of this core, commented with why; the owner and the mode of the file kept.
     *
     * @return int how many lines were commented
     */
    public static function repair(string $file, string $core): int
    {
        $text = (string) @file_get_contents($file);
        $section = '';
        $count = 0;
        $lines = preg_split('/(?<=\n)/', $text) ?: [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*\[([^\]]+)\]/', $line, $m) === 1) {
                $section = trim($m[1]);
                continue;
            }
            if (preg_match('/^(\s*)([A-Za-z_]+)\s*=\s*(.*?)\s*$/', $line, $m) !== 1) {
                continue;
            }
            $why = self::why($section, $m[2], $m[3], $core);
            if ($why !== null) {
                $lines[$i] = "{$m[1]}; " . trim($line) . " ; $why\n";
                $count++;
            }
        }
        if ($count > 0 && ($handle = @fopen($file, 'c')) !== false) {
            ftruncate($handle, 0);
            fwrite($handle, implode('', $lines));
            fclose($handle);
        }

        return $count;
    }

    /** The version of a core by its folder (/usr/share/opensim/0.9.3.0), null when it does not say one. */
    private static function version(string $core): ?string
    {
        return preg_match('/(\d+\.\d+\.\d+(?:\.\d+)?)/', basename(rtrim($core, '/')), $m) === 1 ? $m[1] : null;
    }
}
