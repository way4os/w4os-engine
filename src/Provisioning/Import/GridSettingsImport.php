<?php

declare(strict_types=1);

namespace OpenSim\Provisioning\Import;

use OpenSim\Config\ConfigReader;
use OpenSim\Config\IniEditor;
use OpenSim\Exceptions\InvalidValue;
use OpenSim\Exceptions\NotFound;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\GridOptions;
use OpenSim\Runtime\Environment;

/**
 * The settings of a grid of another install brought into the OpenSim.ini of a grid of this machine: the ones of the sections of
 * its options (its search and its data services, its economy and Gloebit, its offline messages, its profiles, its groups...), as
 * OpenSimulator reads the old config: with the files it includes, the last of them saying the last word (a key whatever its case),
 * its constants replaced (by the old install's, else the grid's of this machine: never left to resolve), a quoted value without
 * what follows its closing quote. Left out: what is a simulator's own (a folder, a cache), what is not used any more (the money
 * of DTL/NSL), and what the grid of this machine already says, which is never replaced. The repair of the options then lays them in
 * the simulators of the grid, nobody editing a file.
 *
 *     $import = GridSettingsImport::prepare($environment, '/opt/opensim/etc/grids/Old/OpenSim.ini', 'speculoos');
 *     $import->document();   // the plan, the secrets masked
 *     $import->apply();      // laid in /etc/opensim/grids/speculoos/OpenSim.ini
 */
final class GridSettingsImport
{
    /** What the money of DTL/NSL needed, not handled: Section/key in lower case. */
    private const RETIRED = ['economy/currencyserver', 'economy/userserver'];

    /** The money modules not handled (old code: a user installs it by hand). */
    private const RETIRED_MODULES = ['dtlnslmoneymodule'];

    /** The old names of the data service of the helpers of the grid, in [DataSnapshot] (a service that is not there any more). */
    private const RETIRED_SERVICES = ['data_srv_misearch'];

    /** A setting whose value is a secret: never said. */
    private const SECRET = '/(key|secret|password|passwd|connectionstring|token)$/i';

    /**
     * @param array<string,array<string,array<string,string>>> $files what is laid, by file, then section, then key, as the old config
     *        writes them (its constants as references)
     * @param list<string> $notes what is left out and why, never a value
     */
    private function __construct(
        private readonly Grid $grid,
        private readonly string $source,
        private readonly string $target,
        private readonly array $files,
        private readonly array $notes,
    ) {}

    /** The settings of an old config to bring into a grid of this machine, planned. */
    public static function prepare(Environment $environment, string $file, string $nick): self
    {
        $grid = Grid::find($environment, $nick) ?? throw NotFound::for("grid called '$nick'");
        is_file($file) && is_readable($file) || throw InvalidValue::because('invalid-request', "$file cannot be read");
        $target = rtrim($grid->directory(), '/') . '/OpenSim.ini';
        $sections = self::optionSections(new GridOptions($grid));

        // The old config as OpenSimulator reads it: each file, then the files it includes, each saying the last word
        $values = [];
        $constants = [];
        $notes = [];
        foreach (ConfigReader::files($file) as $path) {
            $text = (string) @file_get_contents($path);
            foreach (ConfigReader::parse($text) as $section => $keys) {
                $lower = strtolower($section);
                foreach ($keys as $key => $value) {
                    if ($lower === 'const') {
                        $constants[strtolower($key)] = $value;
                    } elseif (isset($sections[$lower]) && stripos($key, 'Include-') !== 0) {
                        $values[$lower][strtolower($key)] = [$sections[$lower], $key, $value];
                    }
                }
            }
            foreach (self::stray($text) as $setting) {
                $notes[] = "$setting: what follows its closing quote is left out, as OpenSimulator reads it";
            }
        }
        $known = self::constantsKnown($grid, $target);
        $gloebit = strtolower(trim($values['economy']['economymodule'][2] ?? '')) === 'gloebit';
        $gloebitFile = rtrim($grid->directory(), '/') . '/Gloebit.ini';
        $there = [$target => self::lower($target), $gloebitFile => self::lower($gloebitFile)];

        $files = [];
        $define = [];
        foreach ($values as $lower => $keys) {
            foreach ($keys as $key => [$section, $name, $value]) {
                $reason = match (true) {
                    preg_match('/(Directory|Path|Dir|Location)$/i', $name) === 1 => null, // a folder of the old simulators
                    in_array("$lower/$key", self::RETIRED, true),
                    $key === 'economymodule' && in_array(strtolower(trim($value)), self::RETIRED_MODULES, true) => "$section/$name: the money of DTL/NSL is not handled (old code, to install by hand), left out",
                    default => false,
                };
                if ($reason !== false) {
                    $reason === null || ($notes[] = $reason);

                    continue;
                }
                // The data service of the helpers of the grid by its old name: DATA_SRV_<nick of the grid> now
                if ($lower === 'datasnapshot' && in_array($key, self::RETIRED_SERVICES, true)) {
                    $notes[] = "$section/$name: an old name of the data service of the helpers of the grid, now DATA_SRV_{$grid->nick()}";
                    $name = 'DATA_SRV_' . $grid->nick();
                    $key = strtolower($name);
                }
                // The constants it uses: the ones of this machine as they are, the others the old install's, defined for the grid
                $missing = [];
                preg_match_all('/\$\{Const\|([^}]+)\}/i', $value, $used);
                foreach ($used[1] as $constant) {
                    $c = strtolower($constant);
                    if (isset($known[$c])) {
                        continue;
                    }
                    isset($constants[$c]) ? ($define[$c] = [$constant, $constants[$c]]) : ($missing[] = $constant);
                }
                if ($missing !== [] || preg_match('/\$\{(?!Const\|)/i', $value) === 1) {
                    $notes[] = "$section/$name: " . (self::secret($name) ? 'its value' : $value) . ' uses what this grid does not know, left out';

                    continue;
                }
                // Gloebit has its settings in one place, the Gloebit.ini of the grid; the rest in its OpenSim.ini
                $into = $gloebit && in_array($lower, ['economy', 'gloebit'], true) ? $gloebitFile : $target;
                if (array_key_exists($key, $there[$into][$lower] ?? [])) {
                    continue;
                }
                $files[$into][$section][$name] = $value;
            }
        }
        // The constants the settings use that this machine does not have (the web site of the grid, WebURL)
        foreach ($define as $c => [$constant, $value]) {
            array_key_exists($c, $there[$target]['const'] ?? []) || ($files[$target]['Const'][$constant] = $value);
        }
        // The simulators include the Gloebit.ini of the grid
        if (isset($files[$gloebitFile]) && !self::includes($there[$target], 'gloebit')) {
            $files[$target]['Gloebit']['Include-Gloebit'] = '${Const|EtcDirectory}/Gloebit.ini';
        }

        return new self($grid, realpath($file) ?: $file, $target, $files, array_values(array_unique($notes)));
    }

    /**
     * What is laid in a file of the grid (its OpenSim.ini by default, Gloebit.ini), by section then key.
     *
     * @return array<string,array<string,string>>
     */
    public function settings(string $file = 'OpenSim.ini'): array
    {
        return $this->files[rtrim($this->grid->directory(), '/') . "/$file"] ?? [];
    }

    /**
     * The plan, as it is said: the settings with their values, a secret masked, and what is left out.
     *
     * @return array{grid:string,source:string,target:string,settings:array<string,array<string,string>>,notes:list<string>}
     */
    public function document(): array
    {
        $said = [];
        foreach ($this->files as $path => $sections) {
            foreach ($sections as $section => $keys) {
                foreach ($keys as $key => $value) {
                    $said[$path][$section][$key] = self::secret($key) || stripos($value, 'password=') !== false ? '***' : $value;
                }
            }
        }

        return [
            'grid' => $this->grid->nick(),
            'source' => $this->source,
            'target' => $this->target,
            // The OpenSim.ini of the grid, then every file laid (the Gloebit.ini of the grid too)
            'settings' => $said[$this->target] ?? [],
            'files' => $said,
            'notes' => $this->notes,
        ];
    }

    /** Lay the settings in the OpenSim.ini of the grid, made when it has none: whether something was written. */
    public function apply(): bool
    {
        $written = false;
        foreach ($this->files as $path => $sections) {
            $ini = IniEditor::load($path);
            foreach ($sections as $section => $keys) {
                foreach ($keys as $key => $value) {
                    $ini->set($section, $key, self::quoted($value));
                }
            }
            $written = @file_put_contents($path, $ini->toString()) !== false || $written;
        }

        return $written;
    }

    /**
     * The sections of the options of the grid in OpenSim.ini, by their name in lower case.
     *
     * @return array<string,string>
     */
    private static function optionSections(GridOptions $options): array
    {
        $sections = [];
        foreach ($options->templates() as $option) {
            if (!in_array('OpenSim.ini', $option->files(), true)) {
                continue;
            }
            foreach ($option->settings('OpenSim.ini', [], false) as [$section]) {
                $sections[strtolower($section)] ??= $section;
            }
        }

        return $sections;
    }

    /**
     * The constants the simulators of the grid have on this machine, by their name in lower case: the ones its master gives every
     * simulator (but the address of the site of a standalone, WebURL), the folders of each, and those the OpenSim.ini of the grid
     * defines.
     *
     * @return array<string,true>
     */
    private static function constantsKnown(Grid $grid, string $target): array
    {
        $known = ['datadirectory' => true, 'cachedirectory' => true, 'logsdirectory' => true];
        $master = (new \OpenSim\Provisioning\Presets([], dirname($grid->directory(), 2)))->options()['master'] ?? null;
        foreach ($master?->settings('grid.ini', [], false) ?? [] as [$section, $key]) {
            strtolower($section) === 'const' && strtolower($key) !== 'weburl' && ($known[strtolower($key)] = true);
        }
        foreach (array_keys(self::lower($target)['const'] ?? []) as $key) {
            $known[$key] = true;
        }

        return $known;
    }

    /**
     * A file as written, its sections and keys in lower case; empty when it is not there.
     *
     * @return array<string,array<string,string>>
     */
    private static function lower(string $path): array
    {
        return array_map(static fn($keys): array => is_array($keys) ? array_change_key_case($keys) : [], array_change_key_case(ConfigReader::file($path)));
    }

    /**
     * Whether a file includes a file of a name (an `Include-<name>` key).
     *
     * @param array<string,array<string,string>> $ini lower()
     */
    private static function includes(array $ini, string $name): bool
    {
        foreach ($ini as $keys) {
            if (array_key_exists('include-' . strtolower($name), $keys)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The settings of a text whose quoted value is followed by something else than a comment: `Section/key`.
     *
     * @return list<string>
     */
    private static function stray(string $text): array
    {
        $found = [];
        $section = '';
        foreach (explode("\n", str_replace("\r", '', $text)) as $line) {
            if (preg_match('/^\s*\[([^\]]+)\]/', $line, $m)) {
                $section = trim($m[1]);
            } elseif (preg_match('/^\s*([^;#=\s][^=]*?)\s*=\s*"[^"]*"\s*([^\s;#].*)$/', $line, $m)) {
                $found[] = "$section/" . trim($m[1]);
            }
        }

        return $found;
    }

    private static function secret(string $key): bool
    {
        return preg_match(self::SECRET, $key) === 1;
    }

    /** A value as an ini of OpenSimulator writes it: quoted unless it is a plain word or number. */
    private static function quoted(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_.+-]*$/', $value) === 1 ? $value : '"' . str_replace('"', '', $value) . '"';
    }
}
