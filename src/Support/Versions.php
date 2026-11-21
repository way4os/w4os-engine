<?php

declare(strict_types=1);

namespace W4OS\Engine\Support;

use W4OS\Engine\Runtime\Core;
use W4OS\Engine\Runtime\Environment;

/**
 * The versions of what matters on a machine, for `--version`: the projects of the family (the version of the package that installed
 * them, else their git version in a clone, else the version being worked on), the cores of OpenSimulator, the runtimes.
 *
 *     Versions::engine();                                      // "3.0.0~beta.14+12.g1a2b3c4"
 *     Versions::all($environment, ['w4os-cli' => $cli]);    // name => version, what was given first
 */
final class Versions
{
    /** The version of a project from its folder, `unknown` when nothing tells it. */
    public static function of(string $root, string $package): string
    {
        $root = rtrim((string) (realpath($root) ?: $root), '/');
        // Installed by a package: its folder is the package's
        if (str_starts_with($root, "/usr/share/$package") && ($version = self::package($package)) !== null) {
            return $version;
        }
        if (file_exists("$root/.git")) {
            $described = trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' describe --tags --always --dirty 2>/dev/null'));
            if ($described !== '') {
                return $described;
            }
        }
        if (is_file("$root/.version")) {
            return trim((string) file_get_contents("$root/.version")) . ' (development)';
        }

        return 'unknown';
    }

    /** The version of the engine. */
    public static function engine(): string
    {
        return self::of(dirname(__DIR__, 2), 'w4os-engine');
    }

    /**
     * Everything that matters: what is given first (the command line that asks), the engine, rest-php, the helpers, the site and the
     * kit when they are here, each core of the install, .NET and PHP.
     *
     * @param array<string,string> $first
     * @return array<string,string> name => version
     */
    public static function all(Environment $environment, array $first = []): array
    {
        $versions = $first + ['w4os-engine' => self::engine()];
        if (class_exists(\OpenSim_Rest::class)) {
            $versions['opensim-rest-php'] = self::of(dirname((string) (new \ReflectionClass(\OpenSim_Rest::class))->getFileName()), 'opensim-rest-php');
        }
        is_dir('/usr/share/w4os-helpers') && ($versions['w4os-helpers'] = self::of('/usr/share/w4os-helpers', 'w4os-helpers'));
        foreach (['w4os-site', 'opensim-kit'] as $package) {
            ($version = self::package($package)) !== null && ($versions[$package] = $version);
        }
        foreach (Core::all($environment->layout->coreRoot()) as $core) {
            $versions['OpenSimulator ' . $core->name()] = self::package('opensim-' . $core->name()) ?? ($core->version() !== '' ? $core->version() : $core->name());
        }
        $runtimes = (string) @shell_exec('dotnet --list-runtimes 2>/dev/null');
        if (preg_match_all('/^Microsoft\.NETCore\.App (\S+)/m', $runtimes, $m) > 0) {
            $versions['.NET'] = implode(', ', $m[1]);
        }
        $versions['PHP'] = PHP_VERSION;

        return $versions;
    }

    /** The version of an installed package, null when dpkg does not have it installed. */
    private static function package(string $package): ?string
    {
        if (!is_dir('/var/lib/dpkg')) {
            return null;
        }
        $version = trim((string) @shell_exec('dpkg-query -W -f=\'${db:Status-Abbrev} ${Version}\' ' . escapeshellarg($package) . ' 2>/dev/null'));

        return preg_match('/^ii\s+(\S+)$/', $version, $m) === 1 ? $m[1] : null;
    }
}
