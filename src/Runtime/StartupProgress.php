<?php

declare(strict_types=1);

namespace W4OS\Engine\Runtime;

/**
 * How a simulator loads, from its log: the plugins of modules it loads with a count, the database it brings up to date, then
 * each region configured, in the grid and ready. One line per step, from the start of what is read.
 */
final class StartupProgress
{
    /** What a process says first, before its log has anything. */
    private const FIRST_SIGNS = 'configured log4net using|system locale|environment is supported by opensimulator|reading configuration settings|starting opensim|logging started to file';

    /** The plugins of the framework and of the application: they are not modules of the regions. */
    private const FRAMEWORK = '/^(OpenSim|Robust|OpenSim\.Region\.Framework|OpenSim\.Data|OpenSim\.ApplicationPlugins\..*)$/';

    /**
     * The steps of a text.
     *
     * @param string $text the log, or the output of the process at its very start when $processOutput
     * @param int $regions how many regions the simulator has, for the "1/2" of the region steps
     * @param bool $processOutput only the first signs of life are steps
     * @return list<string>
     */
    public static function steps(string $text, int $regions = 1, bool $processOutput = false): array
    {
        $regions = max(1, $regions);
        $steps = [];
        $plugins = $shown = $configured = $registered = $ready = 0;
        $second = false;
        $latest = [];

        // The line of the simulator itself comes first: the region is in the grid only when its connector says nothing after it
        $pending = null;
        foreach (explode("\n", $text) as $line) {
            if ($pending !== null && trim($line) !== '') {
                if (str_contains($line, '[GRID CONNECTOR]: Registration failed')) {
                    $steps[] = sprintf('  %s: refused by the grid', $pending);
                } else {
                    $registered++;
                    $steps[] = sprintf('  %s: registered in the grid (%d/%d)', $pending, $registered, $regions);
                }
                $pending = null;
            }
            if (preg_match('/' . self::FIRST_SIGNS . '/i', $line)) {
                $line = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line);
                $line = str_replace("\r", '', $line);
                $line = (string) preg_replace('/^[0-9-]+ [0-9:,.]+ +[A-Z]+ +(\[[^\]]*\]: *)?/', '', $line);
                $line = trim($line);
                if ($line !== '') {
                    $steps[] = "  $line";
                }

                continue;
            }
            if ($processOutput) {
                continue;
            }
            if (preg_match('/\[PLUGINS\]: Plugin Loaded: (.*)$/', $line, $m)) {
                if (!preg_match(self::FRAMEWORK, rtrim($m[1]))) {
                    $plugins++;
                }
            } elseif (str_contains($line, "[REGIONMODULES]: Loading Region's modules")) {
                $second = true;
            } elseif (preg_match('/\[REGIONMODULES\]: From plugin (.*?), \(version /', $line, $m)) {
                if (!$second) {
                    $shown++;
                    $steps[] = sprintf('  %s %d/%d', $m[1], $shown, max($plugins, $shown));
                }
            } elseif (preg_match('/\[MIGRATIONS\]: Upgrading (.*?) to latest revision (\d+)/', $line, $m)) {
                $latest[$m[1]] = (int) $m[2];
            } elseif (preg_match('/\[MIGRATIONS\]: Updating (.*?) to version (\d+)/', $line, $m)) {
                $steps[] = sprintf('  Database %s %d/%d', $m[1], (int) $m[2], max($latest[$m[1]] ?? 0, (int) $m[2]));
            } elseif (preg_match('/\[REGION LOADER FILE SYSTEM\]: Loaded config for region (.*)$/', $line, $m)) {
                $configured++;
                $steps[] = sprintf('  %s: configured (%d/%d)', rtrim($m[1]), $configured, $regions);
            } elseif (
                preg_match('/\[GRID SERVICE\]: Region (.*?) \([0-9a-fA-F-]+, /', $line, $m) &&
                str_contains($line, 'registered at')
            ) {
                $pending = $m[1];
            } elseif (preg_match('/\[RegionReady\]: Region "(.*?)".* is ready/', $line, $m)) {
                $ready++;
                $steps[] = sprintf('  %s: ready (%d/%d)', $m[1], $ready, $regions);
            }
        }

        return $steps;
    }

    /** What the grid answered when it refused the registration of a region (the connector of the simulator says it), null when none. */
    public static function refusal(string $log): ?string
    {
        return preg_match('/\[GRID CONNECTOR\]: (Registration failed):?\s*(.*?)\s*$/m', $log, $m) === 1
            ? trim($m[1] . ' ' . preg_replace('/\s+/', ' ', $m[2]))
            : null;
    }
}
