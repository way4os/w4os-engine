<?php

declare(strict_types=1);

namespace OpenSim\Assets;

use OpenSim\Provisioning\ResourceRegistry;

/**
 * The logos the engine ships for every bridge (the Grav plugin, w4os, the helpers): the ones of w4os and of OpenSimulator, in
 * `share/images/logos`, by their file names (see the README there). The logo of a grid is its own setting (Grid::logoUrl()).
 *
 *     Logos::file(Logos::W4OS_LONG);                 // the path of the file, null when it is not one of them
 *     Logos::file('w4os-logo-v1-long-300x75.png');   // any of FILES
 *     Logos::all();                                  // file name => path
 */
final class Logos
{
    /** The folder of the logos, in the share folder of the kit. */
    public const FOLDER = 'share/images/logos';

    /** The long logo of w4os, for a footer (1244x312; 300x75 in `w4os-logo-v1-long-300x75.png`). */
    public const W4OS_LONG = 'w4os-logo-v1-long.png';

    /** The square logo of w4os (960x960). */
    public const W4OS_SQUARE = 'w4os-logo-v1-square.png';

    /** The icon of w4os (256x256); its favicons are the `cropped-w4os-logo-v1-square-*` ones. */
    public const W4OS_ICON = 'w4os-icon-v1.png';

    /** The logo of OpenSimulator (453x273; 24x14 in `opensimulator-logo-24x14.png`). */
    public const OPENSIMULATOR = 'opensimulator-logo.png';

    /** The logos, by their file names. */
    public const FILES = [
        'w4os-logo-v1-long.png',
        'w4os-logo-v1-long-300x75.png',
        'w4os-logo-v1-square.png',
        'w4os-icon-v1.png',
        'cropped-w4os-logo-v1-square-32x32.png',
        'cropped-w4os-logo-v1-square-180x180.png',
        'cropped-w4os-logo-v1-square-192x192.png',
        'opensimulator-logo.png',
        'opensimulator-logo-24x14.png',
    ];

    /** The folder the logos are in, null when the kit has none. */
    public static function directory(): ?string
    {
        foreach (ResourceRegistry::roots() as $root) {
            if (is_dir($root . '/' . self::FOLDER)) {
                return $root . '/' . self::FOLDER;
            }
        }

        return null;
    }

    /** The path of a logo, null for a name that is not one of FILES or a kit without it. */
    public static function file(string $name): ?string
    {
        $directory = self::directory();

        return $directory !== null && in_array($name, self::FILES, true) && is_file("$directory/$name")
            ? "$directory/$name"
            : null;
    }

    /** @return array<string,string> the path of each logo the kit has, by its file name */
    public static function all(): array
    {
        $all = [];
        foreach (self::FILES as $name) {
            $file = self::file($name);
            $file !== null && ($all[$name] = $file);
        }

        return $all;
    }
}
