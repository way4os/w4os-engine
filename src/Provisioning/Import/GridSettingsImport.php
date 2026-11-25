<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning\Import;

use W4OS\Engine\Provisioning\MapRenderer;
use W4OS\Engine\Config\ConfigReader;
use W4OS\Engine\Config\IniEditor;
use W4OS\Engine\Exceptions\InvalidValue;
use W4OS\Engine\Exceptions\NotFound;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\GridOptions;
use W4OS\Engine\Provisioning\SimulatorMaster;
use W4OS\Engine\Runtime\Environment;

/**
 * The settings of a grid of another install brought into the OpenSim.ini of a grid of this machine: the ones of the sections of
 * its options (its search and its data services, its economy and Gloebit, its offline messages, its profiles, its groups...), as
 * OpenSimulator reads the old config: with the files it includes, the last of them saying the last word (a key whatever its case),
 * its constants replaced (by the old install's, else the grid's of this machine: never left to resolve), a quoted value without
 * what follows its closing quote. Left out: what is a simulator's own (a folder, a cache), what is not used any more (the money
 * of DTL/NSL), and what the grid of this machine already says, which is never replaced. They go in one place, where the
 * simulators read them: the OpenSim.ini of a grid that has a master (it holds it for every simulator), else each simulator, whose
 * config is whole (a grid that runs elsewhere, its simulators made without a master), Gloebit by the path of its file. The repair
 * of the options copies none of them again, and the option of a group they tell is the one the grid has (the offline messages V1).
 *
 *     $import = GridSettingsImport::prepare($environment, '/opt/opensim/etc/grids/Old/OpenSim.ini', 'speculoos');
 *     $import->document();   // the plan, the secrets masked
 *     $import->apply();      // laid in /etc/opensim/grids/speculoos/OpenSim.ini, or in its simulators without a master
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
     * @param list<string> $targets the files the settings of the simulators go in: the OpenSim.ini of the grid, or each simulator
     */
    private function __construct(
        private readonly Grid $grid,
        private readonly string $source,
        private readonly array $targets,
        private readonly array $files,
        private readonly array $notes,
    ) {}

    /** The settings of an old config to bring into a grid of this machine, planned. */
    /**
     * @param bool $overKit a grid a migration just made: a value its options wrote (a pre-setting, the sandbox of Gloebit) is the old
     *        config's again, but what the kit needs to work (ConfigOverrides::kept(): its addresses, its paths, its services)
     */
    public static function prepare(Environment $environment, string $file, string $nick, bool $overKit = false): self
    {
        $grid = Grid::find($environment, $nick) ?? throw NotFound::for("grid called '$nick'", 'grids');
        is_file($file) && is_readable($file) || throw InvalidValue::because('invalid-request', "$file cannot be read");
        $target = rtrim($grid->directory(), '/') . '/OpenSim.ini';
        // The host of the site of the grid: an address there is the grid's own (onSite())
        $site = (string) parse_url((string) $grid->site(), PHP_URL_HOST);
        $sections = self::optionSections(new GridOptions($grid));

        // The old config as OpenSimulator reads it: each file, then the files it includes, each saying the last word
        $values = [];
        $constants = [];
        $notes = [];
        foreach (ConfigReader::files($file) as $path) {
            $text = (string) @file_get_contents($path);
            foreach (\W4OS\Engine\Config\MovedSettings::normalize(ConfigReader::parse($text)) as $section => $keys) {
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
        // The search in the world calls the script of the helpers (query.php): another address is wrong, theirs in its place, said
        if (isset($values['search']['searchurl'])) {
            $address = ConfigReader::replaceConstants(trim($values['search']['searchurl'][2], " \t\""), array_map(static fn($v): string => trim((string) $v, " \t\""), $constants));
            $why = \W4OS\Engine\Provisioning\Web\PageAddress::invalid('Search', 'SearchURL', $address);
            $query = $grid->helpersUrl() === null ? '' : $grid->helperUrl('query');
            if ($why !== null && $query !== '') {
                $values['search']['searchurl'][2] = $query;
                $notes[] = "Search/SearchURL: $address is wrong, $why ($query)";
            }
        }
        $gloebit = strtolower(trim($values['economy']['economymodule'][2] ?? '', " \t\"")) === 'gloebit';
        // Gloebit set up in the old config (loaded, with its keys) comes; else its settings stay there, said
        $unanswered = $gloebit ? (new GridOptions($grid))->unanswered('economy-gloebit', [$file]) : [];
        if (isset($values['gloebit']) && (!$gloebit || $unanswered !== [])) {
            $notes[] = $gloebit
                ? 'Gloebit: the old simulators load it without ' . implode(', ', $unanswered) . ', not turned on (its settings left out)'
                : 'Gloebit: the old simulators do not load it ([Economy] economymodule), its settings left out';
        }
        if ($unanswered !== []) {
            unset($values['economy']['economymodule']);
            $gloebit = false;
        }
        $gloebit || ($values = array_diff_key($values, ['gloebit' => true]));
        // The 3D map kept where its simulators can draw it: on a core that reads MapTilesDirectory, in their cache (MapRenderer)
        $core = $grid->coreDirectory();
        $map = ['folder' => MapRenderer::hasFolderSetting($core) ? MapRenderer::FOLDER : '', 'account' => $environment->layout->systemUser()];
        if ($map['folder'] !== '' && strcasecmp(trim((string) ($values['map']['mapimagemodule'][2] ?? ''), " \t\""), MapRenderer::WARP3D) === 0) {
            $values['map']['maptilesdirectory'] = ['Map', 'MapTilesDirectory', '"' . MapRenderer::FOLDER . '"'];
        }
        $gloebitFile = rtrim($grid->directory(), '/') . '/Gloebit.ini';
        // Where the settings of the simulators go: the OpenSim.ini of the grid, which its master gives them all; a grid without a
        // master has simulators whose config is whole, each its own place (SimulatorConfig)
        $layered = SimulatorMaster::exists($grid->directory());
        $simulators = array_values((new GridOptions($grid))->simulators());
        $targets = $layered || $simulators === [] ? [$target] : $simulators;
        $include = $layered ? '${Const|EtcDirectory}/Gloebit.ini' : $gloebitFile;
        $gloebitThere = self::lower($gloebitFile);

        $files = [];
        foreach ($targets as $into) {
            $known = self::constantsKnown($grid, $into, $layered);
            $there = self::lower($into);
            $define = [];
            foreach ($values as $lower => $keys) {
                foreach ($keys as $key => [$section, $name, $value]) {
                    $retired = \W4OS\Engine\Provisioning\RetiredSettings::why($section, $name, $value, $core, $map);
                    $ours = strcasecmp($name, 'MapTilesDirectory') === 0 && trim($value, " \t\"") === MapRenderer::FOLDER;
                    $reason = match (true) {
                        !$ours && preg_match('/(Directory|Path|Dir|Location)$/i', $name) === 1 => null, // a folder of the old simulators
                        $retired !== null => "$section/$name: $retired, left out",
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
                    // The constants it uses: the ones the simulators have as they are, the others the old install's, defined
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
                    // Gloebit has its settings in one place, the Gloebit.ini of the grid; the rest where the simulators read them
                    $gloebitHere = $gloebit && in_array($lower, ['economy', 'gloebit'], true);
                    $has = ($gloebitHere ? $gloebitThere : $there)[$lower] ?? [];
                    if (array_key_exists($key, $has) && (
                        !$overKit ||
                        trim((string) $has[$key], " \t\"") === trim($value, " \t\"") ||
                        // What the kit needs to work is its own, but an address of the site of the grid, which its helpers serve where
                        // it is (Grid::helperPaths()): the old one's, never rewritten
                        (\W4OS\Engine\Config\ConfigOverrides::kept(['section' => $section, 'key' => $name, 'value' => $value, 'kit' => (string) $has[$key]]) !== null
                            && !self::onSite($value, $constants, $site))
                    )) {
                        continue;
                    }
                    $files[$gloebitHere ? $gloebitFile : $into][$section][$name] = $value;
                }
            }
            // The constants the settings use that the simulators do not have (the web site of the grid, WebURL)
            foreach ($define as $c => [$constant, $value]) {
                array_key_exists($c, $there['const'] ?? []) || ($files[$into]['Const'][$constant] = $value);
            }
            // The simulators include the Gloebit.ini of the grid
            if (isset($files[$gloebitFile]) && !self::includes($there, 'gloebit')) {
                $files[$into]['Gloebit']['Include-Gloebit'] = $include;
            }
        }
        $files = array_filter($files);

        return new self($grid, realpath($file) ?: $file, $targets, $files, array_values(array_unique($notes)));
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
            'target' => $this->targets[0],
            // Where the settings of the simulators go: the OpenSim.ini of the grid, or each simulator of a grid without a master
            'targets' => $this->targets,
            // The first of them, then every file laid (the Gloebit.ini of the grid too)
            'settings' => $said[$this->targets[0]] ?? [],
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
     * Whether an address of the old config is on the site of the grid: written from its web site (`${Const|WebURL}`), or its host.
     *
     * @param array<string,string> $constants the [Const] of the old config, by their name in lower case
     */
    private static function onSite(string $value, array $constants, string $site): bool
    {
        $value = trim($value, " \t\"");
        if (preg_match('/^\$\{Const\|WebURL\}/i', $value) === 1) {
            return true;
        }
        $value = (string) preg_replace_callback('/\$\{Const\|([^}]+)\}/i', static fn(array $m): string => trim((string) ($constants[strtolower($m[1])] ?? $m[0]), " \t\""), $value);

        return $site !== '' && strcasecmp((string) parse_url($value, PHP_URL_HOST), $site) === 0;
    }

    /**
     * The sections of the options of a grid in OpenSim.ini (its settings pages), by their name in lower case.
     *
     * @return array<string,string>
     */
    public static function sections(Grid $grid): array
    {
        return self::optionSections(new GridOptions($grid));
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
     * simulator (but the address of the site of a standalone, WebURL), the folders of each, and those the file the settings go in
     * defines (the OpenSim.ini of the grid, or the simulator itself, whose config is whole without a master).
     *
     * @return array<string,true>
     */
    private static function constantsKnown(Grid $grid, string $target, bool $layered = true): array
    {
        $known = ['datadirectory' => true, 'cachedirectory' => true, 'logsdirectory' => true];
        $master = $layered ? (new \W4OS\Engine\Provisioning\Presets([], dirname($grid->directory(), 2)))->options()['master'] ?? null : null;
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
