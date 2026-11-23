<?php

declare(strict_types=1);

namespace W4OS\Engine\Network;

use W4OS\Engine\Config\ConfigReader;
use W4OS\Engine\Config\IniEditor;
use W4OS\Engine\Runtime\Environment;

/**
 * The port the engine API of a grid answers at on this machine, on 127.0.0.1 only, for its sites (opensim web GRID api): a port of
 * the grid, kept in `[Grid] ApiPort` of `<nick>.conf` (in its section of w4os.conf for a grid in place: never in a file of the install).
 * One that a web server of this machine serves already is the one kept; else the first free one of the block of the grid after its
 * own (x3 to x9, x4 being its console): 8005 for a grid on 8002.
 */
final class ApiPort
{
    /** Its key in `[Grid]` of `<nick>.conf`. */
    public const KEY = 'ApiPort';

    /** The web servers whose configs may serve it, by their folder under /etc. */
    private const SERVERS = ['apache2', 'nginx', 'caddy'];

    /**
     * Its port: the one it has, else the one a web server of this machine gives it, else the first free one of its block; written
     * in its file when it had none there (and the file can be written).
     *
     * @param string $conf the file that keeps it, $section its section there
     * @param string $etc where the configs of the web servers are (/etc)
     */
    public static function of(Environment $environment, string $conf, string $section, string $nick, int $publicPort, int $privatePort, string $etc = '/etc'): int
    {
        $port = (int) trim((string) (ConfigReader::file($conf)[$section][self::KEY] ?? ''), " \t\"");
        if ($port > 0) {
            return $port;
        }
        $port = self::served($nick, $etc) ?? self::free($environment, $publicPort, $privatePort);
        if (is_file($conf) ? is_writable($conf) : is_writable(dirname($conf))) {
            $ini = is_file($conf) ? IniEditor::load($conf) : new IniEditor('');
            $ini->set($section, self::KEY, (string) $port);
            $ini->save($conf);
        }

        return $port;
    }

    /** Its port as it is known, nothing allocated nor written: its own, else the one a web server of this machine gives it, null for none. */
    public static function known(string $conf, string $section, string $nick, string $etc = '/etc'): ?int
    {
        $port = (int) trim((string) (ConfigReader::file($conf)[$section][self::KEY] ?? ''), " \t\"");

        return $port > 0 ? $port : self::served($nick, $etc);
    }

    /** The port a web server of this machine serves the API of this grid at (its config names the grid, OPENSIM_GRID), null for none. */
    public static function served(string $nick, string $etc = '/etc'): ?int
    {
        foreach (self::SERVERS as $server) {
            if (!is_dir("$etc/$server")) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator("$etc/$server", \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($files as $file) {
                if (!$file->isFile() || !$file->isReadable() || $file->getSize() > 1_000_000) {
                    continue;
                }
                $text = (string) file_get_contents($file->getPathname());
                if (preg_match('/OPENSIM_GRID\s+"?' . preg_quote($nick, '/') . '"?[\s;]/', $text) === 1 && preg_match('/127\.0\.0\.1:(\d+)/', $text, $m) === 1) {
                    return (int) $m[1];
                }
            }
        }

        return null;
    }

    /** The first free port of the block of a grid after its own, its console (x4) left to it; past the block, the next free one. */
    public static function free(Environment $environment, int $publicPort, int $privatePort): int
    {
        $ports = new Ports($environment);
        $block = intdiv($publicPort, 10) * 10;
        for ($port = max($publicPort, $privatePort) + 1; $port < $block + 10; $port++) {
            if ($port % 10 !== 4 && $ports->isFree($port)) {
                return $port;
            }
        }

        return $ports->next($block + 10);
    }
}
