<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning;

use W4OS\Engine\Config\GridFileService;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Runtime\Environment;

/**
 * What a package that is installed or upgraded adds to the grids of the machine, without the setup and without anybody: the files
 * a grid is missing (the MoneyServer.ini the helpers need), its ini files readable by the web server and nobody else (mode 640),
 * `SslMode=None;` in the connection strings to a database of this machine (ConnectionString::withLocalTls()), and the options of a
 * grid its simulators miss, laid again in their files
 * (GridOptions::repair(), planned then applied: a simulator made before an option became standard, or by an older engine). What is
 * there is left as it is, a second run does nothing; the simulators take what was laid at their next start.
 *
 *     GridCompletion::run($environment, $layout->etcRoot());   // the postinst of the packages (opensim-complete-grids)
 */
final class GridCompletion
{
    /**
     * @return list<string> what was done, one line each; nothing when there was nothing to do
     */
    public static function run(Environment $environment, string $etcRoot): array
    {
        $done = [];
        foreach (glob(rtrim($etcRoot, '/') . '/grids/*', GLOB_ONLYDIR) ?: [] as $dir) {
            // A grid with helpers has a Helpers.ini, and its helpers need the key
            if (is_file("$dir/Helpers.ini") && ($made = MoneyServerConfig::ensure($dir)) !== null) {
                $done[] = "wrote $made";
            }
            // A database of this machine takes no TLS: without SslMode=None, a Robust on Mono starts without it
            foreach (self::localTls($dir) as $file => $count) {
                $done[] = basename($dir) . ": SslMode=None added to $count connection string" . ($count > 1 ? 's' : '') . ' of a local database in ' . substr($file, strlen($dir) + 1) . ' (restart what reads it: opensim restart)';
            }
            // What stops a simulator of its core (an older config: Warp3DImageModule on 0.9.3), commented with why
            foreach (self::retired($environment, $dir) as $file => $count) {
                $done[] = basename($dir) . ": $count setting" . ($count > 1 ? 's' : '') . ' that stop a simulator of its core commented in ' . substr($file, strlen($dir) + 1) . ' (restart what reads it: opensim restart)';
            }
            // The tables of the search, with the columns the helpers read now (a grid made or moved from an older version)
            foreach (self::searchColumns($environment, $dir) as $line) {
                $done[] = basename($dir) . ": $line";
            }
            // Every ini may hold a password and the web server reads them (the helpers): theirs, and nobody else's
            if (!GridFileService::protect($dir, GridFileService::canGiveWebGroup())) {
                $done[] = basename($dir) . ': some ini files could not be given to the group of the web server';
            }
            try {
                $repair = (new GridOptions(Grid::at($environment, $dir)))->repair(true);
            } catch (\Throwable $e) {
                $done[] = basename($dir) . ': the options could not be repaired: ' . $e->getMessage();

                continue;
            }
            if ($repair['applied'] === []) {
                continue;
            }
            $simulators = implode(', ', array_keys($repair['simulators']));
            $done[] = basename($dir) . ': options laid again in its simulators ' . $simulators . ': ' . implode(', ', $repair['applied'])
                . ' (a simulator takes them at its next start: opensim restart)';
            array_push($done, ...array_map(static fn(string $note): string => basename($dir) . ": $note", $repair['notes']));
        }

        return $done;
    }

    /**
     * The settings of the ini files of a grid that stop a simulator of its core, commented (RetiredSettings).
     *
     * @return array<string,int> the files changed, with how many lines
     */
    private static function retired(Environment $environment, string $dir): array
    {
        try {
            $core = Grid::at($environment, $dir)->coreDirectory() ?: $environment->layout->coreDirectory();
        } catch (\Throwable) {
            return [];
        }
        $changed = [];
        foreach ([...(glob("$dir/*.ini") ?: []), ...(glob("$dir/*/*.ini") ?: [])] as $file) {
            ($count = RetiredSettings::repair($file, $core)) > 0 && ($changed[$file] = $count);
        }

        return $changed;
    }

    /**
     * The columns later versions read, added to the tables of the search of a grid (SearchTables::upgrade()); nothing when its
     * search database is not reachable from here.
     *
     * @return list<string>
     */
    private static function searchColumns(Environment $environment, string $dir): array
    {
        try {
            $config = Grid::at($environment, $dir)->config();
            $db = $config === null ? null : \W4OS\Engine\Data\Connections::pdo($config, \W4OS\Engine\Data\Database::Search);
            // Never the search another install keeps (MaintainSearch = false)
            $added = $db !== null && $db->connected && \W4OS\Engine\Services\SearchTasks::maintains($config) ? \W4OS\Engine\Services\SearchTables::upgrade($db) : [];
        } catch (\Throwable) {
            return [];
        }

        return $added === [] ? [] : ['search tables: added ' . implode(', ', $added)];
    }

    /**
     * The connection strings of the ini files of a grid (its folder, its simulators, its includes) to a database of this machine,
     * `SslMode=None;` added where they say no SslMode; the commented lines, the other hosts and the form of each line left as they
     * are, the owner and the mode of a file too.
     *
     * @return array<string,int> the files changed, with how many lines
     */
    private static function localTls(string $dir): array
    {
        $changed = [];
        $files = [...(glob("$dir/*.ini") ?: []), ...(glob("$dir/*/*.ini") ?: []), ...(glob("$dir/*/*/*.ini") ?: [])];
        foreach ($files as $file) {
            $text = (string) @file_get_contents($file);
            $count = 0;
            $new = (string) preg_replace_callback(
                '/^([ \t]*[A-Za-z_]*ConnectionString[ \t]*=[ \t]*)("?)([^"\r\n]*)("?)([ \t]*)$/mi',
                static function (array $m) use (&$count): string {
                    $fixed = \W4OS\Engine\Config\ConnectionString::withLocalTls($m[3]);
                    if ($fixed === null) {
                        return $m[0];
                    }
                    $count++;

                    return $m[1] . $m[2] . $fixed . $m[4] . $m[5];
                },
                $text,
            );
            if ($count > 0 && $new !== $text && ($handle = @fopen($file, 'c')) !== false) {
                ftruncate($handle, 0);
                fwrite($handle, $new);
                fclose($handle);
                $changed[$file] = $count;
            }
        }

        return $changed;
    }
}
