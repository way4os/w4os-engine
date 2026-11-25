<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning;

use W4OS\Engine\Runtime\InstanceWrites;

/**
 * How the simulators of a core draw their map tiles. Warp3DImageModule renders the regions in 3D, and saves the tile of each one as
 * `MAP-<region uuid>.png`: in `[Map] MapTilesDirectory` when the core reads it (the releases after 0.9.3.0, or 0.9.3.0 with its
 * package opensim-0.9.3.0-maptiles), else in the working folder of the simulator, the bin of its core. A simulator that cannot
 * write it does not start ("A generic error occurred in GDI+"): MapImageModule, the default of OpenSimulator, then.
 *
 *     MapRenderer::whyNotWarp3D('/usr/share/opensim/0.9.3.0', '/var/cache/opensim/demo', 'opensim');   // null, or why not
 */
final class MapRenderer
{
    public const WARP3D = 'Warp3DImageModule';

    public const SIMPLE = 'MapImageModule';

    /** Where a simulator keeps its tile when its core lets it say: its cache. */
    public const FOLDER = '${Const|CacheDirectory}/maptiles';

    /** The assembly of the core that has the map modules. */
    private const MODULES = 'OpenSim.Region.CoreModules.dll';

    /** @var array<string,bool> whether an assembly (by its path and its time) reads MapTilesDirectory */
    private static array $read = [];

    /** Whether a core lets a simulator say where its tile is written (`[Map] MapTilesDirectory`). */
    public static function hasFolderSetting(string $core): bool
    {
        $file = rtrim($core, '/') . '/bin/' . self::MODULES;
        if (!is_file($file)) {
            return false;
        }
        $key = $file . '@' . (int) @filemtime($file) . ':' . (int) @filesize($file);
        // A setting of the code is a text of its assembly, in UTF-16
        $word = implode('', array_map(static fn(string $c): string => "$c\0", str_split('MapTilesDirectory')));

        return self::$read[$key] ??= str_contains((string) file_get_contents($file), $word);
    }

    /**
     * Why the simulators of a core cannot draw their map with Warp3DImageModule, null when they can: their folder of the tiles
     * (when the core reads one) written by the account they run as, else the bin of the core (an install that runs from the archive
     * of a release, its own). A core that is not on this machine is told by its version: before 0.9.3 they write where they run
     * from, 0.9.3 and after in a bin the packages keep read-only; a core that says nothing keeps MapImageModule.
     *
     * @param string $folder where they keep their tiles (MapTilesDirectory, expanded), empty when it is not set
     * @param string $account the account they run as (SystemUser), empty for the one running this code
     */
    public static function whyNotWarp3D(string $core, string $folder = '', string $account = ''): ?string
    {
        $bin = rtrim($core, '/') . '/bin';
        if (!is_file("$bin/" . self::MODULES)) {
            $version = self::version($core);

            return match (true) {
                $version === null => self::WARP3D . ' writes the map tile of a region where its core is, and the core is not known here: ' . self::SIMPLE,
                version_compare($version, '0.9.3', '>=') => self::WARP3D . ' writes the map tile of a region in the bin folder of OpenSimulator ' . $version . ', read-only: ' . self::SIMPLE,
                default => null,
            };
        }
        $folder = trim($folder, " \t\"");
        if (self::hasFolderSetting($core) && $folder !== '') {
            // A folder of the constants of each simulator (its cache): the setup makes it theirs
            if (str_contains($folder, '${')) {
                return null;
            }
            $refused = InstanceWrites::unwritable([$folder], $account);

            return $refused === [] ? null : self::WARP3D . ' writes the map tile of a region in ' . array_key_first($refused) . ', which ' . ($account !== '' ? $account : 'the simulator') . ' cannot write: ' . self::SIMPLE;
        }
        if (InstanceWrites::unwritable([$bin], $account) === []) {
            return null;
        }

        return self::hasFolderSetting($core)
            ? self::WARP3D . " writes the map tile of a region in the bin folder of its core ($bin), read-only, without [Map] MapTilesDirectory: " . self::SIMPLE
            : self::WARP3D . " writes the map tile of a region in the bin folder of its core ($bin), read-only, and this core has no MapTilesDirectory: " . self::SIMPLE . ', until ' . self::remedy($core);
    }

    /** What gives a core MapTilesDirectory: the package of its release, or a newer one. */
    public static function remedy(string $core): string
    {
        $package = self::package($core);

        return $package !== null ? "the package $package is installed" : 'a newer OpenSimulator is installed';
    }

    /** The package that gives the core of a release MapTilesDirectory (opensim-0.9.3.0-maptiles), null for the others. */
    public static function package(string $core): ?string
    {
        $version = self::version($core);

        return $version === '0.9.3.0' ? "opensim-$version-maptiles" : null;
    }

    /** The version of a core by its folder (/usr/share/opensim/0.9.3.0), null when it does not say one. */
    private static function version(string $core): ?string
    {
        return preg_match('/(\d+\.\d+\.\d+(?:\.\d+)?)/', basename(rtrim($core, '/')), $m) === 1 ? $m[1] : null;
    }
}
