<?php

declare(strict_types=1);

namespace OpenSim\Provisioning\Import;

use OpenSim\Config\ConfigReader;
use OpenSim\Config\GridConfig;
use OpenSim\Config\GridFileService;
use OpenSim\Exceptions\InvalidValue;
use OpenSim\Exceptions\NotFound;
use OpenSim\Models\Grid;
use OpenSim\Models\Region;
use OpenSim\Provisioning\SimulatorConfig;
use OpenSim\Provisioning\SimulatorPlan;
use OpenSim\Provisioning\SimulatorProvisioner;
use OpenSim\Provisioning\UiInterface;
use OpenSim\Runtime\Environment;
use OpenSim\Text\Notice;
use OpenSim\Text\Secrets;

/**
 * A simulator that runs with another install (its OpenSim.ini and its regions: the layout of opensim-debian, or of a release) moved
 * into a grid of this machine: what would be written and where (prepare(), document()), then written (apply()).
 *
 *     $import = SimulatorImport::prepare($environment, '/opt/opensim/etc/opensim.d/redoutable.ini', 'speculoos');
 *     $import->document();   // the plan: nothing is written
 *     $import->apply($environment, $ui);
 *
 * What makes the simulator stays as it is: its database (the new config uses the same one, nothing is copied), its regions (their
 * files are copied with their UUID and their place), its ports, the settings it has that differ from the defaults of the core. The
 * simulator is written disabled: the old one is stopped by its operator, then the new one enabled and started.
 */
final class SimulatorImport
{
    /**
     * @param list<array{file:string,to:string,name:string,uuid:string,location:string,port:int,size:int}> $regions
     * @param list<array{section:string,key:string,value:string,unknown:bool}> $customizations
     */
    private function __construct(
        public readonly SimulatorPlan $plan,
        public readonly string $source,
        public readonly array $regions,
        public readonly array $customizations,
        public readonly string $regionsDirectory = '',
        public readonly array $dropped = [],
    ) {}

    /**
     * What the import would do, checked, nothing written.
     *
     * @param string $file the config of the simulator (its OpenSim.ini, or its file of opensim.d), its includes read
     * @param string $grid the nick of the grid of this machine it joins
     * @param ?string $name its name in the grid (default: the one of its config, SimulatorImporter::name())
     * @param ?string $core the folder of the core it will run with (default: the one of the grid)
     * @throws NotFound for a grid this machine does not have
     * @throws InvalidValue for what cannot be imported, said
     */
    public static function prepare(
        Environment $environment,
        string $file,
        string $grid,
        ?string $name = null,
        ?string $core = null,
    ): self {
        if (!is_file($file) || !is_readable($file)) {
            throw InvalidValue::because('invalid-request', "cannot read the config of the simulator: $file");
        }
        $file = (string) realpath($file);
        $found = Grid::find($environment, $grid) ?? throw NotFound::for("grid called '$grid'");
        if ($found->isRemote()) {
            throw InvalidValue::because('invalid-request', "the grid '$grid' is described here, its Robust runs elsewhere: import into a grid of this machine");
        }
        $core = rtrim($core ?? '', '/') ?: $found->coreDirectory() ?: $environment->layout->coreDirectory();
        if ($core === '' || !is_dir("$core/bin")) {
            throw InvalidValue::because('invalid-request', "no core to run the simulator with (a folder that has bin/): $core");
        }

        $raw = ConfigReader::load($file, [], false, true);
        $plan = SimulatorImporter::plan($raw, $file, $found, $name, $core);
        if (is_string($plan)) {
            throw InvalidValue::because('invalid-request', $plan);
        }
        if (is_file($plan->iniPath()) || in_array($plan->slug, array_map(static fn($s): string => $s->name(), $found->simulators()), true)) {
            throw InvalidValue::because('invalid-request', "the grid already has the simulator {$plan->slug} ({$plan->iniPath()}): give another name");
        }

        $files = SimulatorImporter::regionFiles($raw, $file);
        $plan->externalHost = SimulatorImporter::externalHost($files);
        $regions = self::regions($files, $plan->regionsDir(), GridConfig::fromFolder($found->directory()));

        // The settings of the original that differ from the defaults of the core: kept in the new config
        $known = array_values(array_filter(
            ["{$plan->binDir()}/OpenSimDefaults.ini", "{$plan->binDir()}/OpenSim.ini.example"],
            'is_file',
        ));
        $defaults = $known === [] ? [] : ConfigReader::load($known, [], false, true);
        $exampleText = implode("\n", array_map(static fn(string $path): string => (string) file_get_contents($path), $known));
        ['kept' => $customizations, 'dropped' => $dropped] = RobustImporter::sorted(
            $raw,
            ConfigReader::parse((new SimulatorConfig())->render($plan)),
            $defaults,
            dirname($file),
            $exampleText,
            ConfigReader::expand($raw),
        );

        return new self($plan, $file, $regions, $customizations, SimulatorImporter::regionsDirectory($raw, $file), $dropped);
    }

    /**
     * What the import writes, where, and what is left to do: the plan, as the API gives it.
     *
     * @return array<string,mixed>
     */
    public function document(): array
    {
        $plan = $this->plan;
        $consolePort = $plan->consoleMode === 'rest' ? $plan->consolePort : null;

        return [
            'source' => $this->source,
            'grid' => $plan->gridNick,
            'simulator' => $plan->simulatorName,
            'slug' => $plan->slug,
            'core' => $plan->coreDirectory,
            'config' => $plan->iniPath(),
            'database' => ['host' => $plan->dbHost, 'name' => $plan->dbName, 'user' => $plan->dbUser, 'kept' => true],
            'ports' => ['http' => $plan->httpPort, 'console' => $consolePort],
            'regions' => $this->regions,
            // What is kept and what is left (with why), their secrets masked: they are in the configs
            'settings' => array_map(self::masked(...), $this->customizations),
            'dropped' => array_map(self::masked(...), $this->dropped),
            'report' => $this->reportPath(),
            'writes' => array_values(
                array_unique([
                    $plan->iniPath(),
                    ...array_column($this->regions, 'to'),
                    $this->reportPath(),
                    $plan->dataDirectory,
                    $plan->cacheDirectory,
                ]),
            ),
            'reminders' => [
                // The files of an older install belong to its user (640 on opensim-debian); the import runs as the user of the grid
                Notice::of('import-read-access'),
                // A folder of regions that has none is said: the folder its config names may not be the one it reads
                $this->regions === []
                    ? Notice::of('import-no-region', ['simulator' => $plan->simulatorName, 'folder' => $this->regionsDirectory])
                    : Notice::of('import-stop-source', ['regions' => implode(', ', array_column($this->regions, 'name'))]),
                Notice::of('import-enable-start', ['enable' => "opensim enable {$plan->slug}", 'start' => "opensim start {$plan->slug}"]),
            ],
        ];
    }

    /** Write the simulator, disabled: its config, its regions, the settings it keeps, the report of the import. */
    public function apply(Environment $environment, UiInterface $ui): void
    {
        $plan = $this->plan;
        (new SimulatorProvisioner($ui))->apply($plan, $environment);
        is_dir($plan->regionsDir()) || mkdir($plan->regionsDir(), 0o755, true);
        foreach ($this->regions as $region) {
            if (!copy($region['file'], $region['to'])) {
                throw InvalidValue::because('invalid-request', "cannot copy {$region['file']} to {$region['to']}");
            }
            $ui->note(sprintf(_('Wrote %s'), $region['to']));
        }
        $text = RobustImporter::inject((string) file_get_contents($plan->iniPath()), $this->customizations);
        file_put_contents($plan->iniPath(), $text);
        file_put_contents($this->reportPath(), $this->report());
        $ui->note(sprintf(_('Wrote %s'), $this->reportPath()));
        // Passwords are in the config files: 640, as the rest of the grid
        GridFileService::protect($plan->gridDir);
    }

    /** What was found and kept, for whoever compares the new config with the original. */
    public function report(): string
    {
        $plan = $this->plan;
        $lines = [
            "Simulator {$plan->simulatorName} ({$plan->slug}) of the grid {$plan->gridNick}, imported from {$this->source}",
            "Core: {$plan->coreDirectory}",
            "Database: {$plan->dbName} @ {$plan->dbHost} (user {$plan->dbUser}), kept as it is",
            "HTTP port: {$plan->httpPort}" . ($plan->consoleMode === 'rest' ? ", console {$plan->consolePort}" : ''),
            'Regions: ' . count($this->regions) . ' file(s) copied with their UUID',
            '',
            count($this->customizations) . ' setting(s) of the original kept in the new config:',
        ];
        foreach ($this->customizations as $setting) {
            $lines[] = "  [{$setting['section']}] " . Secrets::mask("{$setting['key']} = {$setting['value']}") . ($setting['unknown'] ? ' (not a setting of the core)' : '');
        }
        $lines[] = '';
        $lines[] = count($this->dropped) . ' setting(s) of the original not kept, with why (same: the new config has it so; replaced: the new config writes its own; default: as the core has it; layout, include: the old install\'s own):';
        foreach ($this->dropped as $setting) {
            $lines[] = "  [{$setting['section']}] " . Secrets::mask("{$setting['key']} = {$setting['value']}") . " ({$setting['reason']})";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * A setting with its value masked when it is a secret (Secrets::mask() of its line).
     *
     * @param array{key:string,value:string} $setting
     * @return array<string,mixed>
     */
    private static function masked(array $setting): array
    {
        $line = "{$setting['key']} = {$setting['value']}";

        return ['value' => Secrets::mask($line) === $line ? $setting['value'] : Secrets::MASK] + $setting;
    }

    private function reportPath(): string
    {
        return GridConfig::simulatorsDirectory($this->plan->gridDir) . "/{$this->plan->slug}.import-report.txt";
    }

    /**
     * The regions of the files, where they are copied, refused when a region of the grid on this machine has the name or the UUID of
     * one of them, or when two of them have the same.
     *
     * @param list<string> $files
     * @return list<array{file:string,to:string,name:string,uuid:string,location:string,port:int,size:int}>
     */
    private static function regions(array $files, string $to, GridConfig $config): array
    {
        $taken = [];
        foreach (Region::configured($config) as $region) {
            $taken[strtolower($region->name())] = $region->file();
            $region->uuid() === null || ($taken[$region->uuid()] = $region->file());
        }
        $regions = [];
        foreach ($files as $file) {
            foreach (ConfigReader::load($file, [], false) as $name => $settings) {
                $uuid = strtolower(trim((string) ($settings['RegionUUID'] ?? ''), " \t\""));
                foreach ([strtolower($name), $uuid] as $key) {
                    if ($key !== '' && isset($taken[$key])) {
                        throw InvalidValue::because(
                            'invalid-request',
                            "the region $name ($uuid, $file) is already a region of the grid on this machine ({$taken[$key]}): a grid has one region of a name",
                        );
                    }
                }
                $taken[strtolower($name)] = $file;
                $uuid === '' || ($taken[$uuid] = $file);
                $regions[] = [
                    'file' => $file,
                    'to' => "$to/" . basename($file),
                    'name' => (string) $name,
                    'uuid' => $uuid,
                    'location' => trim((string) ($settings['Location'] ?? ''), " \t\""),
                    'port' => (int) trim((string) ($settings['InternalPort'] ?? '0'), " \t\""),
                    'size' => (int) trim((string) ($settings['SizeX'] ?? '0'), " \t\"") ?: Region::SIZE,
                ];
            }
        }

        return $regions;
    }
}
