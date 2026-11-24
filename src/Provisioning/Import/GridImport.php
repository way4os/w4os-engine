<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning\Import;

use W4OS\Engine\Archives\SourceBackup;
use W4OS\Engine\Config\ConfigOverrides;
use W4OS\Engine\Config\ConfigReader;
use W4OS\Engine\Config\GridInfoText;
use W4OS\Engine\Config\GridFileService;
use W4OS\Engine\Exceptions\InvalidValue;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\OpenSim;
use W4OS\Engine\Provisioning\GridPlan;
use W4OS\Engine\Provisioning\GridProvisioner;
use W4OS\Engine\Provisioning\UiInterface;
use W4OS\Engine\Runtime\Environment;
use W4OS\Engine\Text\Notice;
use W4OS\Engine\Text\Secrets;

/**
 * The grid of another install brought into this one, migrated: the same grid (its database, so its accounts and its regions, kept as
 * it is), run from the config of this install instead of the old one. Its Robust config is made the way this install makes one, with
 * the settings of the old one that are not the defaults of the core put back (RobustImporter), the places of its data kept where they
 * are; its simulators come with it (SimulatorImports). The old install is never changed.
 *
 *     $import = GridImport::prepare($environment, '/opt/opensim/bin/Robust.HG.ini', simulators: [['file' => '/opt/opensim/bin/OpenSim.ini']]);
 *     $import->document();          // the plan: nothing written
 *     $import->apply($environment, $ui);   // written, disabled: the old one still runs
 *     $import->switch($environment, $ui);  // the old one stopped by its operator: this one enabled and started
 */
final class GridImport
{
    /**
     * @param list<array{section:string,key:string,value:string,unknown:bool,path:bool}> $customizations
     * @param list<array<string,mixed>> $dropped
     * @param list<string> $unreadable
     * @param list<string> $gridInfo the values of its grid info made ASCII, said (GridInfoText::said())
     */
    private function __construct(
        public readonly GridPlan $plan,
        public readonly string $source,
        public readonly array $customizations,
        public readonly array $dropped,
        public readonly ?SourceBackup $backup,
        public readonly array $unreadable,
        public readonly ?SimulatorImports $simulators,
        public readonly DataMoves $data = new DataMoves([]),
        public readonly array $constants = [],
        public readonly array $gridInfo = [],
    ) {}

    /**
     * What the import would do: nothing written.
     *
     * @param string $file the Robust config of the other install (Robust.HG.ini, Robust.ini)
     * @param ?string $nick its nick here, when it is not the one of its config
     * @param ?string $core the core it runs with here (default: the one of the install)
     * @param list<array{file:string,name?:?string,core?:?string,scripts?:bool}> $simulators the configs of its simulators
     * @param bool $written the import was applied already: the grid is here (to switch it)
     * @throws InvalidValue for what cannot be imported, said
     */
    public static function prepare(Environment $environment, string $file, ?string $nick = null, ?string $core = null, array $simulators = [], bool $written = false, bool $copyData = false): self
    {
        if (!is_file($file) || !is_readable($file)) {
            throw InvalidValue::because('invalid-request', "cannot read the Robust config: $file");
        }
        $file = (string) realpath($file);
        // The core given, the one of the install, else its latest release
        $core = rtrim($core ?? '', '/') ?: $environment->layout->coreDirectory() ?: (string) \W4OS\Engine\Runtime\Core::latest($environment->layout->coreRoot())?->directory();
        if ($core === '' || !is_dir("$core/bin")) {
            throw InvalidValue::because('invalid-request', "no core to run the grid with (a folder that has bin/): $core");
        }
        $raw = ConfigReader::load($file, [], false, true);
        $plan = RobustImporter::plan($raw, $file, $environment, $nick, $core);
        is_string($plan) && throw InvalidValue::because('invalid-request', $plan);
        // The options this install lays by default (its Robust config, its folders, its database...), as the setup of a grid does
        $plan->options === [] && ($plan->options = (new \W4OS\Engine\Provisioning\GridOptions(Grid::planned($environment, $plan), layered: true, extra: $plan->variables()))->selection([]));
        $written || Grid::find($environment, $plan->gridNick) === null ||
            throw InvalidValue::because('invalid-request', "this install has the grid {$plan->gridNick} already: give another nick (nick)");

        // The settings of the original that differ from what this install writes and from the defaults of the core
        $example = "{$plan->binDir}/" . ($plan->enableHypergrid ? 'Robust.HG.ini.example' : 'Robust.ini.example');
        $defaults = is_file($example) ? ConfigReader::load($example, [], false, true) : [];
        $expanded = ConfigReader::expand($raw);
        // The folders of the old install: above its bin, and the ones its constants name
        $roots = [basename(dirname($file)) === 'bin' ? dirname($file, 2) : dirname($file)];
        foreach ($expanded['Const'] ?? [] as $key => $value) {
            $value = trim((string) $value, " \t\"");
            preg_match('/Directory$/i', (string) $key) === 1 && str_starts_with($value, '/') && ($roots[] = rtrim($value, '/'));
        }
        $generated = ConfigReader::parse(RobustImporter::generate($plan, $environment));
        ['kept' => $customizations, 'dropped' => $dropped] = RobustImporter::sorted(
            $raw,
            $generated,
            $defaults,
            dirname($file),
            is_file($example) ? (string) file_get_contents($example) : '',
            $expanded,
            array_values(array_unique(array_filter($roots, static fn(string $root): bool => $root !== '' && $root !== '/'))),
        );

        // A migration keeps the choices of the grid: what this install writes its own way (a text of a preset) is the old one again,
        // but the services the options of this install lay ([ServiceList]), and a value referring to what the new config does not define
        $defined = $generated;
        foreach ($customizations as $setting) {
            $defined[$setting['section']][$setting['key']] = $setting['value'];
        }
        foreach ($dropped as $i => $setting) {
            if ($setting['reason'] !== 'replaced' || ($setting['place'] ?? false)) {
                continue;
            }
            // A path of the install is the kit's (ConfigOverrides::path()): the old one is not in this install
            if (ConfigOverrides::path($setting['value'])) {
                $dropped[$i] = ['reason' => 'corrected', 'replacement' => trim((string) ($generated[$setting['section']][$setting['key']] ?? ''), " \t\""), 'why' => ConfigOverrides::PATH_WHY] + $setting;
                continue;
            }
            if ($setting['section'] !== 'ServiceList' && self::resolves($setting['value'], $defined)) {
                $customizations[] = ['section' => $setting['section'], 'key' => $setting['key'], 'value' => $setting['value'], 'unknown' => false, 'path' => false];
                unset($dropped[$i]);
            }
        }
        $dropped = array_values($dropped);

        // Its grid info sends ASCII only: what the new config keeps of it is transliterated, said
        $gridInfo = [];
        $original = trim((string) (ConfigReader::expand($raw)['GridInfoService']['gridname'] ?? ''), " \t\"");
        $original !== '' && $original !== $plan->gridName && $gridInfo[] = GridInfoText::said(['section' => 'GridInfoService', 'key' => 'gridname', 'from' => $original, 'to' => $plan->gridName]);
        $referred = [];
        foreach ([...array_values($generated['GridInfoService'] ?? []), ...array_column(array_filter($customizations, static fn(array $s): bool => $s['section'] === 'GridInfoService'), 'value')] as $value) {
            preg_match_all('/\$\{Const\|([^}]+)\}/i', (string) $value, $m);
            array_push($referred, ...array_map('strtolower', $m[1]));
        }
        foreach ($customizations as $i => $setting) {
            $sent = $setting['section'] === 'GridInfoService' || ($setting['section'] === 'Const' && in_array(strtolower($setting['key']), $referred, true));
            if ($sent && !GridInfoText::sendable($setting['value'])) {
                $customizations[$i]['value'] = GridInfoText::ascii($setting['value']);
                $said = GridInfoText::said(['section' => $setting['section'], 'key' => $setting['key'], 'from' => $setting['value'], 'to' => $customizations[$i]['value']]);
                in_array($said, $gridInfo, true) || $gridInfo[] = $said;
            }
        }

        // The way back: its database and its files, as they are, before anything is written (the first start may upgrade the tables)
        $backup = SourceBackup::prepare($environment, $file, $plan->gridNick, 'robust');
        $imports = $simulators === [] ? null : SimulatorImports::prepare($environment, $simulators, $plan->gridNick, Grid::planned($environment, $plan));
        // The folders of its data in the old install come into the layout of this one, where its new config has them
        $configs = trim((string) ($expanded['Startup']['ConfigDirectory'] ?? ''), " \t\"");
        $names = [$configs !== '' && !str_contains($configs, '${') ? basename($configs) : '', trim((string) ($expanded['Launch']['MachineName'] ?? ''), '"'), basename($file, '.ini')];
        // Its assets of FSAssets come, or the import does not go
        $assets = $expanded['AssetService'] ?? [];
        $store = trim((string) ($assets['BaseDirectory'] ?? ''), " \t\"");
        $required = str_contains((string) ($assets['LocalServiceModule'] ?? ''), 'FSAsset') && $store !== '' ? ['[AssetService] BaseDirectory' => $store] : [];
        $written = $generated;
        foreach ($customizations as $setting) {
            $written[$setting['section']][$setting['key']] = $setting['value'];
        }
        $bin = trim((string) ($expanded['Launch']['BinDir'] ?? ''), " \t\"");
        $data = DataMoves::plan($dropped, ConfigReader::expand($written), $names, $copyData, str_starts_with($bin, '/') ? $bin : dirname($file), $required);

        // The constants of the old config its grid info refers to, written with the kept settings: ASCII too
        $constants = $raw['Const'] ?? [];
        foreach ($constants as $key => $value) {
            if (in_array(strtolower((string) $key), $referred, true) && !GridInfoText::sendable((string) $value)) {
                $constants[$key] = GridInfoText::ascii((string) $value);
                $said = GridInfoText::said(['section' => 'Const', 'key' => (string) $key, 'from' => (string) $value, 'to' => $constants[$key]]);
                in_array($said, $gridInfo, true) || $gridInfo[] = $said;
            }
        }

        return new self($plan, $file, $customizations, $dropped, $backup, SimulatorImport::unreadable($backup->files), $imports, $data, $constants, $gridInfo);
    }

    /**
     * What the import writes, where, and what is left to do: the plan, as the API gives it.
     *
     * @return array<string,mixed>
     */
    public function document(): array
    {
        $plan = $this->plan;
        $simulators = $this->simulators?->document();

        return [
            'source' => $this->source,
            'grid' => $plan->gridNick,
            'name' => $plan->gridName,
            'hypergrid' => $plan->enableHypergrid,
            'core' => $plan->coreDirectory,
            'config' => $plan->robustIni(),
            'database' => ['host' => $plan->dbHost, 'name' => $plan->dbName, 'user' => $plan->dbUser, 'kept' => true],
            'ports' => ['public' => $plan->publicPort, 'private' => $plan->privatePort, 'console' => $plan->consoleMode === 'rest' ? $plan->consolePort : null],
            'settings' => array_map(SimulatorImport::masked(...), $this->customizations),
            // The values of its grid info made ASCII: OpenSim's grid info cannot send other characters
            'gridInfo' => $this->gridInfo,
            'dropped' => array_map(SimulatorImport::masked(...), $this->dropped),
            'report' => $this->reportPath(),
            'backup' => $this->backup?->document(),
            'simulators' => $simulators,
            // The folders of its data brought into this install, never measured first
            'data' => $this->data->document(),
            'problems' => ($simulators['problems'] ?? []) + ($this->data->problems() === [] ? [] : ['data' => implode('; ', $this->data->problems())]),
            'writes' => array_values(array_unique([
                ...($this->backup === null ? [] : [$this->backup->file]),
                $plan->robustIni(),
                $this->reportPath(),
                $plan->dataDirectory,
                $plan->cacheDirectory,
                ...($simulators['writes'] ?? []),
            ])),
            'reminders' => [
                ...($this->unreadable === [] ? [] : [Notice::of('import-read-access', ['paths' => implode(', ', $this->unreadable)])]),
                Notice::of('import-stop-grid', ['grid' => $plan->gridNick, 'ports' => "{$plan->publicPort}, {$plan->privatePort}"]),
                Notice::of('import-switch', ['command' => "opensim import --robust {$this->source} --switch"]),
            ],
        ];
    }

    /**
     * Write the grid, disabled: first the backup of its source, then its config with the settings it keeps, the report, its
     * simulators. The folders of its data are moved (or copied) where its new config has them, as the last of its own writings.
     * The old install keeps running until it is stopped (it runs without the data moved). When it fails, its data is moved back and
     * the folders it made are taken back (its config, its data and cache folders): it can be applied again; the backup of the
     * source is kept.
     *
     * @param ?\Closure $dump writes the dump of a database for the backup (default: DatabaseDump::write())
     */
    public function apply(Environment $environment, UiInterface $ui, ?\Closure $dump = null): void
    {
        $this->unreadable === [] || throw InvalidValue::because(
            'source-not-readable',
            'this process cannot read what the import copies: ' . implode(', ', $this->unreadable) . ' (give the user of the grid read access to them)',
        );
        ($this->simulators?->document()['problems'] ?? []) === [] ||
            throw InvalidValue::because('invalid-request', 'the simulators cannot be imported: ' . implode('; ', $this->simulators->document()['problems']));
        $this->data->problems() === [] || throw InvalidValue::because('invalid-request', 'its data cannot be brought: ' . implode('; ', $this->data->problems()));
        $plan = $this->plan;
        // What this import makes, taken back when it fails
        $made = array_values(array_filter(
            array_unique([$plan->gridDir, $plan->dataDirectory, $plan->cacheDirectory]),
            static fn(string $dir): bool => $dir !== '' && !file_exists($dir),
        ));
        $brought = [];
        try {
            if ($this->backup !== null) {
                $ui->note(sprintf(_('Wrote %s'), $this->backup->write($dump)));
                foreach ($this->backup->document()['remote'] as $database) {
                    $ui->warn(sprintf(_('Not in the backup: %s, a database of another server (its own backups keep it)'), $database));
                }
            }
            $plan->enable = false;
            $plan->start = false;
            (new GridProvisioner($ui))->apply($plan, $environment);
            $launch = ['BinDir' => $plan->binDir, 'MachineName' => $plan->gridNick];
            file_put_contents($plan->robustIni(), RobustImporter::inject((string) file_get_contents($plan->robustIni()), $this->customizations, $this->constants, [], $launch));
            file_put_contents($this->reportPath(), $this->report());
            $ui->note(sprintf(_('Wrote %s'), $this->reportPath()));
            $this->data->apply($ui, $brought);
            $this->simulators?->apply($environment, $ui, $dump);
            // Passwords are in the config files: 640, as the rest of the grid
            GridFileService::protect($plan->gridDir);
            // Each instance reads what it is written: a reference none of its files resolves would stop it at its start
            ($unresolved = self::unresolved($environment, $plan->gridNick)) === [] ||
                throw InvalidValue::because('config-unresolved', 'the configs written refer to what they do not define: ' . implode('; ', $unresolved));
        } catch (\Throwable $error) {
            $this->data->undo($brought, $ui);
            foreach ($made as $dir) {
                self::remove($dir);
            }
            $made === [] || $ui->warn(sprintf(_('The import stopped: what it made is taken back (%s); apply it again once the cause is fixed.'), implode(', ', $made)));

            throw $error;
        }
    }

    /**
     * What the configs of the instances of a grid refer to and none of their files define, by instance.
     *
     * @return list<string>
     */
    public static function unresolved(Environment $environment, string $nick): array
    {
        $grid = Grid::find($environment, $nick);
        $found = [];
        foreach ([$grid?->robust(), ...array_map(static fn($simulator) => $simulator->controller(), $grid?->simulators() ?? [])] as $instance) {
            if ($instance === null) {
                continue;
            }
            foreach (RobustImporter::unresolved($instance->files(), $instance instanceof \W4OS\Engine\Controllers\RobustController, $instance->lastFiles()) as $reference) {
                $found[] = $instance->name() . " $reference";
            }
        }

        return $found;
    }

    /**
     * Whether each reference of a value (`${Section|Key}`) is defined in a config.
     *
     * @param array<string,array<string,string>> $config
     */
    private static function resolves(string $value, array $config): bool
    {
        preg_match_all('/\$\{([^|}]+)\|([^}]+)\}/', $value, $references, PREG_SET_ORDER);
        foreach ($references as [, $section, $key]) {
            if (!isset($config[$section][$key])) {
                return false;
            }
        }

        return true;
    }

    /** A folder this import made, and all it holds. */
    private static function remove(string $dir): void
    {
        if (is_link($dir) || !is_dir($dir)) {
            is_file($dir) || is_link($dir) ? @unlink($dir) : null;

            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * The grid run from here instead of the old install: refused while the old Robust still holds the ports of the grid (its
     * operator stops it, and keeps it from starting again), or while a config of it refers to what it does not define; then the grid
     * and its simulators (the ones of the import) enabled and started, as `opensim start` starts them (up only once ready, else the
     * error of its log). Its simulators are not started when its Robust did not.
     *
     * @return array<string,\W4OS\Engine\Runtime\Outcome|\W4OS\Engine\Exceptions\InstanceFailed> what became of each instance
     */
    public function switch(Environment $environment, UiInterface $ui): array
    {
        $plan = $this->plan;
        $grid = Grid::find($environment, $plan->gridNick) ?? throw InvalidValue::because('invalid-request', "the grid {$plan->gridNick} is not written yet: apply the import first");
        // The ports bound on this machine (the configs name them too, the one of the grid here first)
        $bound = $environment->processes->listening();
        foreach ([$plan->publicPort, $plan->privatePort] as $port) {
            !in_array($port, $bound, true) || throw InvalidValue::because(
                'source-running',
                "port $port of the grid is taken: the old Robust still runs, stop it (and keep it from starting again), then switch",
            );
        }
        ($unresolved = self::unresolved($environment, $plan->gridNick)) === [] ||
            throw InvalidValue::because('config-unresolved', 'the configs of the grid refer to what they do not define: ' . implode('; ', $unresolved));
        $grid->isEnabled() || $grid->enable() || throw new \RuntimeException("could not enable the grid {$plan->gridNick}");
        // The simulators of the grid are the ones of the import: it was made by it
        $simulators = [];
        foreach (Grid::find($environment, $plan->gridNick)?->simulators() ?? [] as $simulator) {
            $simulator->isEnabled() || $simulator->enable();
            $simulators[] = $simulator->name();
        }
        $results = OpenSim::start([$plan->gridNick], $environment);
        $robust = $results[$plan->gridNick] ?? null;
        if ($robust instanceof \W4OS\Engine\Exceptions\InstanceFailed) {
            foreach ($simulators as $name) {
                $results[$name] = new \W4OS\Engine\Exceptions\InstanceFailed($name, "not started: the Robust of {$plan->gridNick} did not start", 'robust-not-started');
            }
        } elseif ($simulators !== []) {
            $results += OpenSim::start($simulators, $environment);
        }
        foreach ($results as $name => $result) {
            $result instanceof \W4OS\Engine\Exceptions\InstanceFailed
                ? $ui->error("$name: " . $result->getMessage())
                : $ui->note(sprintf(_('Started %s'), $name));
        }

        return $results;
    }

    /** What was found and kept, for whoever compares the new config with the original. */
    public function report(): string
    {
        $plan = $this->plan;
        $lines = [
            "Grid {$plan->gridName} ({$plan->gridNick}), imported from {$this->source}",
            "Core: {$plan->coreDirectory}",
            "Database: {$plan->dbName} @ {$plan->dbHost} (user {$plan->dbUser}), kept as it is",
            "Ports: public {$plan->publicPort}, private {$plan->privatePort}" . ($plan->consoleMode === 'rest' ? ", console {$plan->consolePort}" : ''),
            '',
            ...($this->gridInfo === [] ? [] : ['Grid info made ASCII:', ...array_map(static fn(string $said): string => "  $said", $this->gridInfo), '']),
            count($this->customizations) . ' setting(s) of the original kept in the new config:',
        ];
        foreach ($this->customizations as $setting) {
            $lines[] = "  [{$setting['section']}] " . Secrets::mask("{$setting['key']} = {$setting['value']}") . ($setting['unknown'] ? ' (not a setting of the core)' : '');
        }
        $lines[] = '';
        $lines[] = count($this->dropped) . ' setting(s) of the original not kept, with why (same: the new config has it so; replaced: the new config writes its own; corrected: the value of the kit stays, the one it needs to work; default: as the core has it; layout, include: the old install\'s own):';
        foreach ($this->dropped as $setting) {
            $lines[] = "  [{$setting['section']}] " . Secrets::mask("{$setting['key']} = {$setting['value']}") . " ({$setting['reason']}" .
                (($setting['replacement'] ?? null) !== null ? ", now {$setting['replacement']}" : '') . (isset($setting['why']) ? ": {$setting['why']}" : '') . ')';
        }

        return implode("\n", $lines) . "\n";
    }

    private function reportPath(): string
    {
        return "{$this->plan->gridDir}/{$this->plan->gridNick}.import-report.txt";
    }
}
