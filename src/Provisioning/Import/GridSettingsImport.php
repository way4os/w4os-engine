<?php

declare(strict_types=1);

namespace OpenSim\Provisioning\Import;

use OpenSim\Config\ConfigReader;
use OpenSim\Config\IniEditor;
use OpenSim\Exceptions\InvalidValue;
use OpenSim\Exceptions\NotFound;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\GridOptions;
use OpenSim\Provisioning\SimulatorMaster;
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
    /** What the money of DTL/NSL needed, not used any more: Section/key in lower case. */
    private const RETIRED = ['economy/currencyserver', 'economy/userserver'];

    /** The money modules not used any more. */
    private const RETIRED_MODULES = ['dtlnslmoneymodule'];

    /** A setting whose value is a secret: never said. */
    private const SECRET = '/(key|secret|password|passwd|connectionstring|token)$/i';

    /**
     * @param array<string,array<string,string>> $settings what is laid, by section then key, as the old config writes them
     * @param list<string> $notes what is left out and why, never a value
     */
    private function __construct(
        private readonly Grid $grid,
        private readonly string $source,
        private readonly string $target,
        private readonly array $settings,
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
        $here = self::constantsHere($grid);
        $there = ConfigReader::load($target, expand: false);
        $there = array_map(static fn($keys): array => is_array($keys) ? array_change_key_case($keys) : [], array_change_key_case($there));

        $settings = [];
        foreach ($values as $lower => $keys) {
            foreach ($keys as $key => [$section, $name, $value]) {
                $reason = match (true) {
                    preg_match('/(Directory|Path|Dir|Location)$/i', $name) === 1 => null, // a folder of the old simulators
                    in_array("$lower/$key", self::RETIRED, true) => "$section/$name: the money of DTL/NSL is not used any more",
                    $key === 'economymodule' && in_array(strtolower(trim($value)), self::RETIRED_MODULES, true) => "$section/$name: the money of DTL/NSL is not used any more",
                    default => false,
                };
                if ($reason !== false) {
                    $reason === null || ($notes[] = $reason);

                    continue;
                }
                $resolved = self::resolve($value, $constants, $here);
                if (str_contains($resolved, '${')) {
                    $notes[] = "$section/$name: " . (self::secret($name) ? 'its value' : $value) . ' uses what this grid does not know, left out';

                    continue;
                }
                // What the grid of this machine says is its own
                if (array_key_exists($key, $there[$lower] ?? [])) {
                    continue;
                }
                $settings[$section][$name] = $resolved;
            }
        }

        return new self($grid, realpath($file) ?: $file, $target, $settings, array_values(array_unique($notes)));
    }

    /**
     * What is laid, by section then key.
     *
     * @return array<string,array<string,string>>
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * The plan, as it is said: the settings with their values, a secret masked, and what is left out.
     *
     * @return array{grid:string,source:string,target:string,settings:array<string,array<string,string>>,notes:list<string>}
     */
    public function document(): array
    {
        $said = [];
        foreach ($this->settings as $section => $keys) {
            foreach ($keys as $key => $value) {
                $said[$section][$key] = self::secret($key) || stripos($value, 'password=') !== false ? '***' : $value;
            }
        }

        return [
            'grid' => $this->grid->nick(),
            'source' => $this->source,
            'target' => $this->target,
            'settings' => $said,
            'notes' => $this->notes,
        ];
    }

    /** Lay the settings in the OpenSim.ini of the grid, made when it has none: whether something was written. */
    public function apply(): bool
    {
        if ($this->settings === []) {
            return false;
        }
        $ini = IniEditor::load($this->target);
        foreach ($this->settings as $section => $keys) {
            foreach ($keys as $key => $value) {
                $ini->set($section, $key, self::quoted($value));
            }
        }

        return @file_put_contents($this->target, $ini->toString()) !== false;
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
     * The constants of the grid of this machine, by their name in lower case: those its simulators share (its master).
     *
     * @return array<string,string>
     */
    private static function constantsHere(Grid $grid): array
    {
        $master = SimulatorMaster::path($grid->directory());
        $const = is_file($master) ? (ConfigReader::file($master)['Const'] ?? []) : [];

        return array_change_key_case($const);
    }

    /**
     * A value with its constants replaced: the old install's, else the ones of this grid.
     *
     * @param array<string,string> $old
     * @param array<string,string> $here
     */
    private static function resolve(string $value, array $old, array $here): string
    {
        for ($i = 0; $i < 5 && str_contains($value, '${Const|'); $i++) {
            $value = (string) preg_replace_callback(
                '/\$\{Const\|([^}]+)\}/i',
                static fn(array $m): string => $old[strtolower($m[1])] ?? $here[strtolower($m[1])] ?? $m[0],
                $value,
            );
        }

        return $value;
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
