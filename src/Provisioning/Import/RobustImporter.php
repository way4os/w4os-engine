<?php

namespace W4OS\Engine\Provisioning\Import;

use W4OS\Engine\Config\IniEditor;
use W4OS\Engine\Config\ConfigReader;
use W4OS\Engine\Config\ConnectionString;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\GridOptions;
use W4OS\Engine\Provisioning\GridPlan;
use W4OS\Engine\Provisioning\Slug;
use W4OS\Engine\Runtime\Environment;

/**
 * Ports the configuration of a Robust that was not made by the kit: what the setup asks is detected in its config
 * and goes through the generators of the kit (a standard config, valid for the core chosen), then the other values
 * of the original that differ from the defaults of the core are injected into it, so the settings of the user
 * are kept. The original is never changed.
 */
final class RobustImporter
{
    /** The sections the kit lays out itself: its folders, its pid file, its registry. */
    private const KIT_SECTIONS = ['Const', 'Startup', 'Launch'];

    /**
     * The nick a grid gets here when none is given: the one of its config in the small letters of a nick, without what a nick cannot
     * have (W4osDemoGrid: w4osdemogrid), else one made of its name.
     *
     * @param array<string,array<string,string>> $raw its Robust config, as written
     */
    public static function defaultNick(array $raw): string
    {
        $info = $raw['GridInfoService'] ?? [];

        return (string) preg_replace('/[^a-z0-9_-]+/', '', strtolower(trim((string) ($info['gridnick'] ?? ''), '"')))
            ?: Slug::nick(trim((string) ($info['gridname'] ?? ''), '"'));
    }

    /**
     * What the setup asks, read from the original config: a plan for NewGrid.
     *
     * @param array<string,array<string,string>> $raw the original, as written (see ConfigReader::load)
     * @return GridPlan|string the plan, or what makes it impossible
     */
    public static function plan(
        array $raw,
        string $path,
        Environment $environment,
        ?string $nick,
        string $core,
    ): GridPlan|string {
        $ini = ConfigReader::expand($raw);
        $name = $ini['GridInfoService']['gridname'] ?? '';
        // The nick given, else the one of the grid made a nick here; refused when it is not one (Grid::validateNick())
        $nick = trim((string) ($nick ?: self::defaultNick($ini)));
        if ($nick === '') {
            return 'the grid has no nick (gridnick of [GridInfoService]): give one with --nick';
        }
        if (($wrong = Grid::validateNick($nick)) !== null) {
            return "the nick '$nick' is $wrong: give one with --nick";
        }
        if (($db = ConnectionString::parse($ini['DatabaseService']['ConnectionString'] ?? '')) === null) {
            return 'no database in [DatabaseService] ConnectionString';
        }

        $plan = new GridPlan();
        // What its grid info sends: ASCII only (GridInfoText)
        $plan->gridName = $name !== '' ? \W4OS\Engine\Config\GridInfoText::ascii(trim((string) $name, " \t\"")) : Slug::nameOf($nick);
        $plan->gridNick = $nick;
        $plan->gridSlug = Slug::slug($plan->gridName);
        $plan->enableHypergrid = str_contains(basename($path), '.HG.') || isset($ini['Hypergrid']['GatekeeperURI']);
        $plan->coreDirectory = $core;
        $plan->binDir = "$core/bin";

        $host = $ini['Const']['BaseHostname'] ?? '';
        if ($host === '' && preg_match('#^https?://([^:/]+)#', $ini['Const']['BaseURL'] ?? '', $m)) {
            $host = $m[1];
        }
        $plan->baseHostname = $host !== '' ? $host : 'localhost';
        $plan->publicPort = (int) ($ini['Const']['PublicPort'] ?? 8002);
        $plan->privatePort = (int) ($ini['Const']['PrivatePort'] ?? $plan->publicPort + 1);
        $plan->webUrl = $ini['Const']['WebURL'] ?? "https://{$plan->baseHostname}";
        [$plan->dbHost, $plan->dbName, $plan->dbUser, $plan->dbPass] = [
            $db->source,
            $db->database,
            $db->user,
            $db->password,
        ];

        $network = $ini['Network'] ?? [];
        if (($network['ConsoleUser'] ?? '') !== '' && (int) ($network['ConsolePort'] ?? 0) > 0) {
            $plan->consoleMode = 'rest';
            $plan->consolePort = (int) $network['ConsolePort'];
            $plan->consoleHost = $network['ConsoleHost'] ?? $plan->baseHostname;
            $plan->consoleUser = $network['ConsoleUser'];
            $plan->consolePass = $network['ConsolePass'] ?? '';
        } else {
            $plan->consoleMode = 'screen';
        }

        $plan->gridDir = $environment->layout->etcRoot() . "/grids/$nick";
        $plan->etcDirectory = $plan->gridDir;
        $plan->dataDirectory = $environment->layout->dataRoot() . "/$nick";
        $plan->cacheDirectory = $environment->layout->cacheRoot() . "/$nick";
        $plan->logsDirectory = $environment->layout->logsRoot();
        $plan->enable = false;
        $plan->start = false;

        return $plan;
    }

    /**
     * What the original has that the kit's config does not say by itself, and that is not the default of the core:
     * the settings of the user. A value the setup writes (the values it asked) is not one, nor is a value the
     * example of the core has as it is. The exceptions are the places of the data (the assets, the map tiles...):
     * they stay where they are, as the original has them (made absolute), default or not, as the data is not
     * moved by porting the config.
     *
     * @param array<string,array<string,string>> $original   as written
     * @param array<string,array<string,string>> $generated  the standard config of the kit, as written
     * @param array<string,array<string,string>> $example    the example of the core, as written
     * @param string                             $dir        the folder of the original, for relative paths
     * @param array<string,array<string,string>> $expanded   the original with its references replaced (for the places)
     * @return list<array{section:string,key:string,value:string,unknown:bool,path:bool}>
     */
    public static function customizations(
        array $original,
        array $generated,
        array $example,
        string $dir,
        string $exampleText = '',
        array $expanded = [],
    ): array {
        return self::sorted($original, $generated, $example, $dir, $exampleText, $expanded)['kept'];
    }

    /**
     * The settings of the original sorted: the ones kept (customizations()), and the ones left with why: `layout` (a section of the
     * layout of the install, [Const], [Startup], [Launch]), `include` (a file of its own layout), `same` (the new config writes it,
     * with the same value), `replaced` (the new config writes it its own way), `default` (as the core has it), `empty`.
     *
     * @param list<string> $roots the folders of the old install (a folder under one is its own, `replaced`)
     * @param array<string,array<string,string>> $layout the folders the new one has instead, by section and key
     * @return array{kept:list<array{section:string,key:string,value:string,unknown:bool,path:bool}>,dropped:list<array{section:string,key:string,value:string,reason:string,replacement?:?string,place?:bool}>} a place of the old install
     *         it replaces is `place` (its data brought by DataMoves)
     */
    public static function sorted(
        array $original,
        array $generated,
        array $example,
        string $dir,
        string $exampleText = '',
        array $expanded = [],
        array $roots = [],
        array $layout = [],
    ): array {
        $found = [];
        $dropped = [];
        $drop = static function (string $section, string $key, string $value, string $reason, ?string $replacement = null, bool $place = false) use (&$dropped): void {
            $dropped[] = ['section' => $section, 'key' => $key, 'value' => $value, 'reason' => $reason] + ($replacement === null ? [] : ['replacement' => $replacement]) + ($place ? ['place' => true] : []);
        };
        foreach ($original as $section => $values) {
            foreach ($values as $key => $value) {
                $key = (string) $key;
                $value = (string) $value;
                // The layout of the kit (its folders, its pid, its registry) is its own
                if (in_array($section, self::KIT_SECTIONS, true)) {
                    $drop($section, $key, $value, 'layout');
                    continue;
                }
                // The files the original includes are those of its own layout, the kit has its own
                if (stripos($key, 'Include-') === 0) {
                    $drop($section, $key, $value, 'include');
                    continue;
                }
                $standard = $generated[$section][$key] ?? null;
                $default = $example[$section][$key] ?? null;
                $unknown =
                    $exampleText !== '' &&
                    preg_match('/^\s*;*\s*' . preg_quote($key, '/') . '\s*=/mi', $exampleText) !== 1;

                if ($value !== '' && preg_match('/(Directory|Path|Dir|Location)$/i', $key) === 1) {
                    $place = self::absolute($key, $expanded[$section][$key] ?? $value, $dir);
                    // A folder of the old install is its own: the new one has the folders of its layout
                    foreach ($roots as $root) {
                        if ($place === $root || str_starts_with($place, rtrim($root, '/') . '/')) {
                            $drop($section, $key, $place, 'replaced', $layout[$section][$key] ?? $standard, true);

                            continue 2;
                        }
                    }
                    if ($place !== $standard) {
                        $found[] = [
                            'section' => $section,
                            'key' => $key,
                            'value' => $place,
                            'unknown' => $unknown,
                            'path' => true,
                        ];
                    } else {
                        $drop($section, $key, $value, 'same');
                    }
                    continue;
                }
                // What the setup wrote is its own; what is as in the core is no choice of the user
                $reason = match (true) {
                    $value === '' => 'empty',
                    $value === $standard || self::sameDatabase($value, $standard) => 'same',
                    $standard !== null && $standard !== $default => 'replaced',
                    $value === $default => 'default',
                    default => null,
                };
                if ($reason !== null) {
                    $drop($section, $key, $value, $reason);
                    continue;
                }
                $found[] = [
                    'section' => $section,
                    'key' => $key,
                    'value' => $value,
                    'unknown' => $unknown,
                    'path' => false,
                ];
            }
        }

        return ['kept' => $found, 'dropped' => $dropped];
    }

    /**
     * Put the customizations into the config the kit wrote, in place, with the constants they use that it does not define: each as
     * the original defines it ($constants, its [Const] as written), and the ones that definition uses in turn. A constant the rest of
     * the config defines ($defined: the master of a simulator) is left to it. What the original gets from its launcher ([Launch]: the
     * folder of its core, its name) is the one of this install ($launch); a constant that needs another is not brought.
     *
     * @param list<array{section:string,key:string,value:string,unknown:bool,path?:bool}> $customizations
     * @param array<string,string> $constants the [Const] of the original, as written
     * @param list<string> $defined the constants the files read with this one define already
     * @param array<string,string> $launch what [Launch] is here: BinDir (the bin of the core it runs on), SimName, MachineName
     */
    public static function inject(string $text, array $customizations, array $constants = [], array $defined = [], array $launch = []): string
    {
        $ini = new IniEditor($text);
        $quoted = static fn(string $value): string => preg_match('/^(-?\d+(\.\d+)?|true|false)$/i', $value) === 1 ? $value : '"' . $value . '"';
        foreach ($customizations as $c) {
            $ini->set($c['section'], $c['key'], $quoted($c['value']));
        }
        $pending = array_column($customizations, 'value');
        while ($pending !== []) {
            preg_match_all('/\$\{Const\|([^}]+)\}/', (string) array_shift($pending), $used);
            foreach ($used[1] as $name) {
                if ($ini->get('Const', $name) === null && isset($constants[$name]) && !in_array($name, $defined, true)) {
                    $value = (string) preg_replace_callback('/\$\{Launch\|([^}]+)\}/', static fn(array $m): string => $launch[$m[1]] ?? $m[0], trim($constants[$name], " \t\""));
                    if (str_contains($value, '${Launch|')) {
                        continue;
                    }
                    $ini->set('Const', $name, $quoted($value));
                    $pending[] = $value;
                }
            }
        }

        return rtrim($ini->toString()) . "\n";
    }

    /**
     * The references of a config its files do not resolve, `${Section|Key}` that no file of it defines: Nini stops the instance on the
     * first ("Expand key not found"). `[Launch]` is the launcher's, left out.
     *
     * @param list<string> $files the files of the instance, in the order it reads them, with what they include
     * @param bool $directory the `*.ini` of its ConfigDirectory too, as Robust reads them
     * @return list<string> each as "[Section] Key: ${Ref}"
     */
    public static function unresolved(array $files, bool $directory = false): array
    {
        $merged = \W4OS\Engine\Config\ConfigReader::load($files, [], false, $directory);
        $found = [];
        foreach ($merged as $section => $values) {
            foreach ($values as $key => $value) {
                preg_match_all('/\$\{([^|}]+)\|([^}]+)\}/', (string) $value, $refs, PREG_SET_ORDER);
                foreach ($refs as [$ref, $from, $name]) {
                    $from === 'Launch' || isset($merged[$from][$name]) || $found[] = "[$section] $key: $ref";
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** A path that was relative to the original config is made absolute: the user's data stays where it is. */
    private static function absolute(string $key, string $value, string $dir): string
    {
        if (
            !preg_match('/(Directory|Path|Dir|Location)$/i', $key) ||
            $value === '' ||
            str_starts_with($value, '/') ||
            str_contains($value, '${') ||
            str_contains($value, '://')
        ) {
            return $value;
        }
        $parts = [];
        foreach (explode('/', $dir . '/' . $value) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return '/' . implode('/', $parts);
    }

    /** The standard config of the kit for a plan: the example of the core with the presets of the grid laid over it. */
    public static function generate(GridPlan $plan, Environment $environment): string
    {
        $folder = sys_get_temp_dir() . '/robust-standard-' . bin2hex(random_bytes(4));
        is_dir($folder) || mkdir($folder, 0o755, true);
        $standard = GridPlan::fromArray(['gridDir' => $folder, 'etcDirectory' => $folder] + $plan->toArray());
        IniEditor::load($standard->binDir . '/' . ($standard->enableHypergrid ? 'Robust.HG.ini.example' : 'Robust.ini.example'))->save(
            $standard->robustIni(),
        );
        $options = new GridOptions(Grid::planned($environment, $standard), layered: true, extra: $plan->variables());
        foreach ($options->selection([]) as $name) {
            $options->enable($name);
        }
        $text = (string) file_get_contents($standard->robustIni());
        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            )
            as $item
        ) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($folder);

        return $text;
    }

    /**
     * Whether two connection strings name the same database (its server, its name, its user), whatever their other pairs: the
     * new config writes `SslMode=None;` for a local one (ConnectionString), the database is the same.
     */
    private static function sameDatabase(string $value, ?string $standard): bool
    {
        if ($standard === null || stripos($value, 'Data Source') === false) {
            return false;
        }
        $old = \W4OS\Engine\Config\ConnectionString::parse($value);
        $new = \W4OS\Engine\Config\ConnectionString::parse($standard);

        return $old !== null && $new !== null && $old->key() === $new->key() && $old->password === $new->password;
    }
}
