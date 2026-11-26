<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning\Web;

use W4OS\Engine\Config\Layout;

/**
 * The PHP-FPM pools of the suite on this machine: the engine API, as the user of the instances, the helpers and the website, as
 * the web user and its group (Snippets::pool(), helpersPool(), sitePool()), in the pools of the newest PHP-FPM, each written only
 * when it changes.
 */
final class PhpPools
{
    /** The folder of the settings of PHP: W4OS_PHP_ETC for the tests, /etc/php. */
    public static function etc(): string
    {
        return rtrim((string) (getenv('W4OS_PHP_ETC') ?: '/etc/php'), '/');
    }

    /** The folder of the pools of the newest PHP-FPM (/etc/php/8.4/fpm/pool.d), '' without PHP-FPM. */
    public static function folder(?string $etc = null): string
    {
        $folders = glob(($etc ?? self::etc()) . '/*/fpm/pool.d', GLOB_ONLYDIR) ?: [];
        usort($folders, static fn(string $a, string $b): int => version_compare(basename(dirname($a, 2)), basename(dirname($b, 2))));

        return (string) (end($folders) ?: '');
    }

    /** Whether a pool of the suite is installed (w4os-helpers), in the pools of any PHP-FPM. */
    public static function installed(string $name, ?string $etc = null): bool
    {
        return (glob(($etc ?? self::etc()) . "/*/fpm/pool.d/$name.conf") ?: []) !== [];
    }

    /** The group of the web server an installed pool gives its socket to (listen.group), null when it names none. */
    public static function listenGroup(string $file): ?string
    {
        $text = is_file($file) ? (string) file_get_contents($file) : '';

        return preg_match('/^\s*listen\.group\s*=\s*([^\s;]+)/m', $text, $m) === 1 ? $m[1] : null;
    }

    /**
     * The pools of the suite for this install.
     *
     * @param string $listenGroup the group of the web server that reaches their sockets
     * @return array<string,string> the text of each pool, by its name
     */
    public static function texts(Layout $layout, string $listenGroup): array
    {
        return [
            'w4os-api' => Snippets::pool($layout->systemUser() ?: 'opensim', $listenGroup),
            'w4os-helpers' => Snippets::helpersPool($layout->webUser(), $layout->webGroup(), $listenGroup),
            'w4os-site' => Snippets::sitePool($layout->webUser(), $layout->webGroup(), $listenGroup),
        ];
    }

    /**
     * Write the pools whose file is not their text already.
     *
     * @param array<string,string> $texts the text of each pool, by its name (texts())
     * @return list<string> the files written
     * @throws \RuntimeException when a file cannot be written
     */
    public static function write(string $folder, array $texts): array
    {
        $written = [];
        foreach ($texts as $name => $text) {
            $file = "$folder/$name.conf";
            if (is_file($file) && file_get_contents($file) === $text) {
                continue;
            }
            file_put_contents($file, $text) !== false || throw new \RuntimeException("cannot write $file");
            $written[] = $file;
        }

        return $written;
    }
}
