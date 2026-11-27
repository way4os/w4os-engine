<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning\Import;

/**
 * An install of before the kit (/opt/opensim of opensim-debian): there when its library is, or a cron that names it; its grids and its
 * simulators by their configs (etc/robust.d, etc/opensim.d), its instances by the config each process was given (-inifile), its start
 * at boot by its cron and its own units (never the kit's, which says "opensim service").
 */
final class LegacyInstall
{
    /** The units an install of before the kit may have. */
    public const UNITS = ['/etc/systemd/system/opensim.service', '/etc/init.d/opensim'];

    /**
     * @param list<string> $units the units it may start by
     */
    public function __construct(
        public readonly string $root = '/opt/opensim',
        private readonly string $cron = '/etc/cron.hourly/opensim',
        private readonly array $units = self::UNITS,
        private readonly string $proc = '/proc',
    ) {}

    /** The one of this machine: W4OS_LEGACY_ROOT, W4OS_LEGACY_CRON and W4OS_LEGACY_UNITS for the tests. */
    public static function here(): self
    {
        $units = getenv('W4OS_LEGACY_UNITS');

        return new self(
            (string) (getenv('W4OS_LEGACY_ROOT') ?: '/opt/opensim'),
            (string) (getenv('W4OS_LEGACY_CRON') ?: '/etc/cron.hourly/opensim'),
            $units !== false ? array_values(array_filter(explode(' ', $units))) : self::UNITS,
            (string) (getenv('W4OS_LEGACY_PROC') ?: '/proc'),
        );
    }

    /** Whether there is one: its library, or a cron that names it. */
    public function found(): bool
    {
        return is_file("$this->root/lib/os-helpers") || (is_file($this->cron) && str_contains((string) file_get_contents($this->cron), $this->root));
    }

    /** @return list<string> the configs of its Robusts */
    public function robusts(): array
    {
        return $this->configs('robust.d');
    }

    /** @return list<string> the configs of its simulators */
    public function simulators(): array
    {
        return $this->configs('opensim.d');
    }

    /** The nick of a grid of it, its gridnick in lower case, the characters of a nick only. */
    public function nick(string $robust): string
    {
        $text = is_readable($robust) ? (string) file_get_contents($robust) : '';

        return preg_match('/^\s*gridnick\s*=\s*"?([^";\r\n]*)"?/mi', $text, $m) === 1 ? (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($m[1])) : '';
    }

    /** It in one line: its folder, its grid, its simulators. */
    public function summary(): string
    {
        $robust = $this->robusts()[0] ?? null;
        $count = count($this->simulators());

        return sprintf(
            'Found an existing install in %s%s.',
            $this->root,
            $robust === null ? '' : sprintf(': grid %s, %d simulator%s', $this->nick($robust), $count, $count === 1 ? '' : 's'),
        );
    }

    /**
     * The processes that run a config: the -inifile each was given (quotes a shell left taken off, relative to its folder), the same
     * file as the config.
     *
     * @return list<int>
     */
    public function pids(string $config): array
    {
        $target = realpath($config);
        $pids = [];
        foreach ($target === false ? [] : (glob("$this->proc/[0-9]*", GLOB_ONLYDIR) ?: []) as $process) {
            $args = explode("\0", (string) @file_get_contents("$process/cmdline"));
            $value = null;
            foreach ($args as $i => $arg) {
                if (str_starts_with($arg, '-inifile=')) {
                    $value = substr($arg, 9);
                } elseif ($arg === '-inifile') {
                    $value = $args[$i + 1] ?? null;
                }
            }
            if ($value === null || $value === '') {
                continue;
            }
            $value = trim($value, "'\"");
            if (!str_starts_with($value, '/')) {
                $cwd = @readlink("$process/cwd");
                if ($cwd === false) {
                    continue;
                }
                $value = "$cwd/$value";
            }
            realpath($value) === $target && ($pids[] = (int) basename($process));
        }

        return $pids;
    }

    /** @return list<string> the configs whose instances run, the simulators first (they are stopped before their Robust) */
    public function running(): array
    {
        return array_values(array_filter([...$this->simulators(), ...$this->robusts()], fn(string $config): bool => $this->pids($config) !== []));
    }

    /** @return list<string> what starts it at boot: its cron, its own units */
    public function starters(): array
    {
        $starters = is_file($this->cron) ? [$this->cron] : [];
        foreach ($this->units as $unit) {
            is_file($unit) && !is_link($unit) && !str_contains((string) file_get_contents($unit), 'opensim service') && ($starters[] = $unit);
        }

        return $starters;
    }

    /** @return list<string> the configs of one folder of its etc, sorted */
    private function configs(string $folder): array
    {
        $files = glob("$this->root/etc/$folder/*.ini") ?: [];
        sort($files);

        return $files;
    }
}
