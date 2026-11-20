<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Config\GridFileService;
use OpenSim\Models\Grid;
use OpenSim\Runtime\Environment;

/**
 * What a package that is installed or upgraded adds to the grids of the machine, without the setup and without anybody: the files
 * a grid is missing (the MoneyServer.ini the helpers need), its ini files readable by the web server and nobody else (mode 640), and the options of a grid its simulators miss, laid again in their files
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
}
