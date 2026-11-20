<?php

declare(strict_types=1);

namespace OpenSim\Provisioning\Import;

use OpenSim\Archives\SourceBackup;
use OpenSim\Config\ConfigReader;
use OpenSim\Config\GridFileService;
use OpenSim\Exceptions\InvalidValue;
use OpenSim\Models\Grid;
use OpenSim\OpenSim;
use OpenSim\Provisioning\GridPlan;
use OpenSim\Provisioning\GridProvisioner;
use OpenSim\Provisioning\UiInterface;
use OpenSim\Runtime\Environment;
use OpenSim\Text\Notice;
use OpenSim\Text\Secrets;

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
     */
    private function __construct(
        public readonly GridPlan $plan,
        public readonly string $source,
        public readonly array $customizations,
        public readonly array $dropped,
        public readonly ?SourceBackup $backup,
        public readonly array $unreadable,
        public readonly ?SimulatorImports $simulators,
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
    public static function prepare(Environment $environment, string $file, ?string $nick = null, ?string $core = null, array $simulators = [], bool $written = false): self
    {
        if (!is_file($file) || !is_readable($file)) {
            throw InvalidValue::because('invalid-request', "cannot read the Robust config: $file");
        }
        $file = (string) realpath($file);
        // The core given, the one of the install, else its latest release
        $core = rtrim($core ?? '', '/') ?: $environment->layout->coreDirectory() ?: (string) \OpenSim\Runtime\Core::latest($environment->layout->coreRoot())?->directory();
        if ($core === '' || !is_dir("$core/bin")) {
            throw InvalidValue::because('invalid-request', "no core to run the grid with (a folder that has bin/): $core");
        }
        $raw = ConfigReader::load($file, [], false, true);
        $plan = RobustImporter::plan($raw, $file, $environment, $nick, $core);
        is_string($plan) && throw InvalidValue::because('invalid-request', $plan);
        // The options this install lays by default (its Robust config, its folders, its database...), as the setup of a grid does
        $plan->options === [] && ($plan->options = (new \OpenSim\Provisioning\GridOptions(Grid::planned($environment, $plan), layered: true, extra: $plan->variables()))->selection([]));
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
        ['kept' => $customizations, 'dropped' => $dropped] = RobustImporter::sorted(
            $raw,
            ConfigReader::parse(RobustImporter::generate($plan, $environment)),
            $defaults,
            dirname($file),
            is_file($example) ? (string) file_get_contents($example) : '',
            $expanded,
            array_values(array_unique(array_filter($roots, static fn(string $root): bool => $root !== '' && $root !== '/'))),
        );

        // A migration keeps the choices of the grid: what this install writes its own way (a text of a preset) is the old one again,
        // but the services the options of this install lay ([ServiceList])
        foreach ($dropped as $i => $setting) {
            if ($setting['reason'] === 'replaced' && $setting['section'] !== 'ServiceList' && !str_contains($setting['value'], '${')) {
                $customizations[] = ['section' => $setting['section'], 'key' => $setting['key'], 'value' => $setting['value'], 'unknown' => false, 'path' => false];
                unset($dropped[$i]);
            }
        }
        $dropped = array_values($dropped);

        // The way back: its database and its files, as they are, before anything is written (the first start may upgrade the tables)
        $backup = SourceBackup::prepare($environment, $file, $plan->gridNick, 'robust');
        $imports = $simulators === [] ? null : SimulatorImports::prepare($environment, $simulators, $plan->gridNick, Grid::planned($environment, $plan));

        return new self($plan, $file, $customizations, $dropped, $backup, SimulatorImport::unreadable($backup->files), $imports);
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
            'dropped' => array_map(SimulatorImport::masked(...), $this->dropped),
            'report' => $this->reportPath(),
            'backup' => $this->backup?->document(),
            'simulators' => $simulators,
            'problems' => $simulators['problems'] ?? [],
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
     * simulators. The old install keeps running until it is stopped.
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
        if ($this->backup !== null) {
            $ui->note(sprintf(_('Wrote %s'), $this->backup->write($dump)));
        }
        $plan = $this->plan;
        $plan->enable = false;
        $plan->start = false;
        (new GridProvisioner($ui))->apply($plan, $environment);
        file_put_contents($plan->robustIni(), RobustImporter::inject((string) file_get_contents($plan->robustIni()), $this->customizations));
        file_put_contents($this->reportPath(), $this->report());
        $ui->note(sprintf(_('Wrote %s'), $this->reportPath()));
        $this->simulators?->apply($environment, $ui, $dump);
        // Passwords are in the config files: 640, as the rest of the grid
        GridFileService::protect($plan->gridDir);
    }

    /**
     * The grid run from here instead of the old install: refused while the old Robust still holds the ports of the grid (its
     * operator stops it, and keeps it from starting again); then the grid and its simulators (the ones of the import) enabled and
     * started.
     *
     * @return list<string> the instances started
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
        $grid->isEnabled() || $grid->enable() || throw new \RuntimeException("could not enable the grid {$plan->gridNick}");
        // The simulators of the grid are the ones of the import: it was made by it
        $instances = [$plan->gridNick];
        foreach (Grid::find($environment, $plan->gridNick)?->simulators() ?? [] as $simulator) {
            $simulator->isEnabled() || $simulator->enable();
            $instances[] = $simulator->name();
        }
        OpenSim::start($instances, $environment);
        $ui->note(sprintf(_('Started %s'), implode(', ', $instances)));

        return $instances;
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
            count($this->customizations) . ' setting(s) of the original kept in the new config:',
        ];
        foreach ($this->customizations as $setting) {
            $lines[] = "  [{$setting['section']}] " . Secrets::mask("{$setting['key']} = {$setting['value']}") . ($setting['unknown'] ? ' (not a setting of the core)' : '');
        }
        $lines[] = '';
        $lines[] = count($this->dropped) . ' setting(s) of the original not kept, with why (same: the new config has it so; replaced: the new config writes its own; default: as the core has it; layout, include: the old install\'s own):';
        foreach ($this->dropped as $setting) {
            $lines[] = "  [{$setting['section']}] " . Secrets::mask("{$setting['key']} = {$setting['value']}") . " ({$setting['reason']}" .
                (($setting['replacement'] ?? null) !== null ? ", now {$setting['replacement']}" : '') . ')';
        }

        return implode("\n", $lines) . "\n";
    }

    private function reportPath(): string
    {
        return "{$this->plan->gridDir}/{$this->plan->gridNick}.import-report.txt";
    }
}
