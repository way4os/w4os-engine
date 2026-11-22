<?php

declare(strict_types=1);

namespace W4OS\Engine\Models;

/**
 * The addresses a grid advertised before (get_grid_info: login, about, register, help, password, web_profile_url, search...), for a
 * site to answer them still: the viewers keep the ones they read until they read the info of the grid again. Each pair of a service,
 * the old address and the new one, and when it changed:
 *
 *  - a change of the engine records the ones it makes (around(): the address of the grid, its settings, the repair of its options);
 *  - what it advertised before it came to this install is read from the report of its import (`<nick>.import-report.txt`, what
 *    the import kept or replaced of the original), to what it advertises now; when that changed is not known.
 *
 * Only addresses (a value with a scheme), only the ones that changed, never one a report masked. Kept in the folder of the grid,
 * beside its config: `previous-addresses.json`.
 *
 *     AddressHistory::around($grid, fn() => $settings->write($page, $values));
 *     AddressHistory::pairs($grid);   // [['service' => 'register', 'old' => ..., 'new' => ..., 'date' => ..., 'source' => 'change'], ...]
 */
final class AddressHistory
{
    public const FILE = 'previous-addresses.json';

    /** What a report writes after a setting, the reason it was kept or not. */
    private const REASONS = '/ \((?:same|replaced|default|layout|include|not a setting of the core)\)$/';

    /**
     * Do a change of the grid and record the addresses it changed.
     *
     * @template T
     * @param \Closure():T $change
     * @return T
     */
    public static function around(Grid $grid, \Closure $change): mixed
    {
        $before = self::advertised($grid);
        $result = $change();
        $before === null || self::record($grid, $before);

        return $result;
    }

    /**
     * The pairs of the grid: the ones its import says, then the ones its changes recorded; an old address once.
     *
     * @return list<array{service:string,old:string,new:string,date:?string,source:string}>
     */
    public static function pairs(Grid $grid): array
    {
        $directory = $grid->directory();
        if ($directory === '' || !is_dir($directory)) {
            return [];
        }
        $recorded = array_map(static fn(array $pair): array => $pair + ['source' => 'change'], self::kept("$directory/" . self::FILE));
        $known = array_flip(array_map(static fn(array $pair): string => "{$pair['service']} {$pair['old']}", $recorded));
        $now = self::advertised($grid) ?? [];
        $imported = [];
        foreach (self::imported($grid) as $service => $old) {
            $new = $now[$service] ?? null;
            $new === null || $new === $old || isset($known["$service $old"])
                || ($imported[] = ['service' => $service, 'old' => $old, 'new' => $new, 'date' => null, 'source' => 'import']);
        }

        return array_merge($imported, $recorded);
    }

    /**
     * What the grid advertises now, from its config: service => address; null for a grid without its config here.
     *
     * @return ?array<string,string>
     */
    public static function advertised(Grid $grid): ?array
    {
        $directory = $grid->directory();
        if ($directory === '' || !is_dir($directory)) {
            return null;
        }

        return self::addresses(Grid::at($grid->environment(), $directory)->configuredInfo());
    }

    /**
     * What the grid advertised before its import, as its report says it: service => address, its constants the original's.
     *
     * @return array<string,string>
     */
    public static function imported(Grid $grid): array
    {
        $report = $grid->directory() . '/' . $grid->nick() . '.import-report.txt';
        $lines = is_file($report) ? (file($report, FILE_IGNORE_NEW_LINES) ?: []) : [];
        [$constants, $info] = [[], []];
        foreach ($lines as $line) {
            if (preg_match('/^\s+\[(Const|GridInfoService)\] ([^=]+?) = (.*)$/', $line, $m) !== 1) {
                continue;
            }
            $value = trim((string) preg_replace(self::REASONS, '', $m[3]));
            $m[1] === 'Const' ? ($constants[trim($m[2])] = $value) : ($info[trim($m[2])] = $value);
        }
        $resolved = [];
        foreach ($info as $key => $value) {
            for ($depth = 0; $depth < 5 && str_contains($value, '${'); ++$depth) {
                $value = (string) preg_replace_callback('/\$\{Const\|([^}]+)\}/', static fn(array $m): string => $constants[$m[1]] ?? $m[0], $value);
            }
            // A value the report masked, or with a constant it does not say, is not known
            str_contains($value, '${') || str_contains($value, '***') || ($resolved[$key] = $value);
        }

        return self::addresses($resolved);
    }

    /** Record the addresses that changed since what the grid advertised before. */
    private static function record(Grid $grid, array $before): void
    {
        $after = self::advertised($grid) ?? [];
        $date = gmdate('Y-m-d\TH:i:s\Z', (int) $grid->environment()->clock->time());
        $pairs = [];
        foreach ($before as $service => $old) {
            $new = $after[$service] ?? null;
            $new === null || $new === $old || ($pairs[] = ['service' => $service, 'old' => $old, 'new' => $new, 'date' => $date]);
        }
        if ($pairs === []) {
            return;
        }
        $file = $grid->directory() . '/' . self::FILE;
        $text = json_encode(array_merge(self::kept($file), $pairs), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        // The change is made: one that cannot be kept is told, never undone
        @file_put_contents($file, $text) === false && error_log("opensim: the previous addresses of {$grid->nick()} cannot be kept in $file");
    }

    /** @return list<array{service:string,old:string,new:string,date:?string}> the pairs a file keeps */
    private static function kept(string $file): array
    {
        $kept = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($kept) ? array_values(array_filter($kept, static fn($pair): bool => is_array($pair) && isset($pair['service'], $pair['old'], $pair['new']))) : [];
    }

    /**
     * The values that are addresses (a scheme), trimmed of their quotes.
     *
     * @param array<string,string> $info
     * @return array<string,string>
     */
    private static function addresses(array $info): array
    {
        $info = array_map(static fn($value): string => trim((string) $value, " \t\""), $info);

        return array_filter($info, static fn(string $value): bool => preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1);
    }
}
