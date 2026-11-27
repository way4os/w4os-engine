<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning\Import;

use W4OS\Engine\Config\ConfigReader;

/**
 * The OSSL permissions of a simulator of an install of before the kit, carried into its migrated grid: read where it reads them (its
 * chain of includes, an Include-osslEnable with an absolute path among them), from the [OSSL] section or the old [XEngine] one, whose
 * `${XEngine|macro}` become `${OSSL|macro}`; the ones that differ from what the grid gives kept in its config-include/osslEnable.ini,
 * below the line of the local changes; a function OpenSimulator no longer knows (not in the core's osslDefaultEnable.ini nor
 * osslEnable.ini) left out and named.
 */
final class OsslImport
{
    /** The keys of the OSSL permissions: the functions allowed, the macros of the owners, the threat level. */
    private const KEYS = '/^(Allow_os\w+|Allow_\w+|ossl\w+|OSFunctionThreatLevel|AllowOSFunctions|AllowMODFunctions|AllowLightShareFunctions|PermissionErrorToOwner)$/i';

    /** The line of the grid's osslEnable.ini below which the local changes go. */
    private const MARKER = '/^\s*;\s*add lines .*below this.*$/m';

    /** @var ?array{kept:array<string,string>,dropped:list<string>} */
    private ?array $plan = null;

    public function __construct(
        private readonly string $source,
        private readonly string $gridDir,
        private readonly string $binDir,
    ) {}

    /** @return array<string,string> the permissions kept, by key, as the grid writes them */
    public function kept(): array
    {
        return $this->plan()['kept'];
    }

    /** @return list<string> the functions left out: OpenSimulator no longer has them */
    public function dropped(): array
    {
        return $this->plan()['dropped'];
    }

    /** The lines of the report of the import. */
    public function report(): string
    {
        $kept = $this->kept();
        $dropped = $this->dropped();
        if ($kept === [] && $dropped === []) {
            return '';
        }
        $lines = [sprintf('%d OSSL permission(s) of the old install kept in config-include/osslEnable.ini ([XEngine] read as [OSSL]):', count($kept))];
        foreach ($kept as $key => $value) {
            $lines[] = "  [OSSL] $key = $value";
        }
        $dropped === [] || ($lines[] = 'Left out, OpenSimulator no longer has them: ' . implode(', ', $dropped));

        return implode("\n", $lines) . "\n";
    }

    /** Write the permissions kept below the line of the local changes of the grid's osslEnable.ini, each once. */
    public function apply(): void
    {
        $kept = $this->kept();
        $file = "$this->gridDir/config-include/osslEnable.ini";
        if ($kept === [] || !is_file($file)) {
            return;
        }
        $text = (string) file_get_contents($file);
        $lines = [];
        foreach ($kept as $key => $value) {
            preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/mi', $text) === 1 || ($lines[] = "    $key = $value");
        }
        if ($lines === []) {
            return;
        }
        $block = "    ; the OSSL permissions of the old install (opensim import)\n" . implode("\n", $lines) . "\n";
        // After the whole line of the marker, never inside it (what follows on it would be a line Nini refuses)
        $text = preg_match(self::MARKER, $text, $m, PREG_OFFSET_CAPTURE) === 1
            ? substr($text, 0, $m[0][1] + strlen($m[0][0])) . "\n" . $block . ltrim(substr($text, $m[0][1] + strlen($m[0][0])), "\n")
            : rtrim($text) . "\n" . $block;
        file_put_contents($file, $text);
    }

    /** @return array{kept:array<string,string>,dropped:list<string>} */
    private function plan(): array
    {
        if ($this->plan !== null) {
            return $this->plan;
        }
        // Where the simulator reads them, as written (its macros kept), the old [XEngine] first, [OSSL] over it
        $source = ConfigReader::load($this->source, [], false);
        $old = [];
        foreach (['XEngine', 'OSSL'] as $section) {
            foreach ($source[$section] ?? [] as $key => $value) {
                preg_match(self::KEYS, $key) === 1 && ($old[$key] = self::value((string) $value));
            }
        }
        // What OpenSimulator knows, and what the grid gives already
        $known = [];
        foreach (['osslDefaultEnable.ini', 'osslEnable.ini'] as $name) {
            $known += array_change_key_case(ConfigReader::file("$this->binDir/config-include/$name")['OSSL'] ?? [], CASE_LOWER);
        }
        $grid = [];
        // The grid's files once it is written; the core's before (the plan), which the grid starts from
        $folder = is_file("$this->gridDir/config-include/osslDefaultEnable.ini") ? "$this->gridDir/config-include" : "$this->binDir/config-include";
        foreach (['osslDefaultEnable.ini', 'osslEnable.ini'] as $name) {
            foreach (ConfigReader::file("$folder/$name")['OSSL'] ?? [] as $key => $value) {
                $grid[strtolower($key)] = self::value((string) $value);
            }
        }
        $kept = [];
        $dropped = [];
        foreach ($old as $key => $value) {
            if (str_starts_with(strtolower($key), 'allow_') && !array_key_exists(strtolower($key), $known) && !$this->inCore(substr($key, 6))) {
                $dropped[] = $key;
            } elseif (($grid[strtolower($key)] ?? null) !== $value) {
                $kept[$key] = $value;
            }
        }

        return $this->plan = ['kept' => $kept, 'dropped' => $dropped];
    }

    /** Whether the core has a function, by its name in its script API (one the files of the core do not list). */
    private function inCore(string $function): bool
    {
        $this->api ??= (string) @file_get_contents("$this->binDir/OpenSim.Region.ScriptEngine.Shared.Api.dll");

        return $function !== '' && str_contains($this->api, "\0$function\0");
    }

    private ?string $api = null;

    /** A value as the grid compares and writes it: quotes taken off, the macros of [XEngine] made the ones of [OSSL]. */
    private static function value(string $value): string
    {
        $value = trim($value);
        strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"') && ($value = substr($value, 1, -1));

        return (string) preg_replace('/\$\{XEngine\|/i', '${OSSL|', $value);
    }
}
