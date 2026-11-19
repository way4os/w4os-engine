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
use OpenSim\Archives\SourceBackup;
use OpenSim\Provisioning\Slug;
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
        public readonly ?SourceBackup $backup = null,
        public readonly array $scripts = [],
        public readonly bool $copyScripts = true,
        public readonly array $missingModules = [],
        public readonly array $unreadable = [],
    ) {}

    /**
     * What the import would do, checked, nothing written.
     *
     * @param string $file the config of the simulator (its OpenSim.ini, or its file of opensim.d), its includes read
     * @param string $grid the nick of the grid of this machine it joins
     * @param ?string $name its name in the grid (default: the one of its config, SimulatorImporter::name())
     * @param ?string $core the folder of the core it will run with (default: the one of the grid)
     * @param bool $scripts copy the state of its scripts (its ScriptEngines folders) into the folder of the new simulator: false loses
     *        it, every script starts again from the beginning
     * @throws NotFound for a grid this machine does not have
     * @throws InvalidValue for what cannot be imported, said
     */
    public static function prepare(
        Environment $environment,
        string $file,
        string $grid,
        ?string $name = null,
        ?string $core = null,
        bool $scripts = true,
    ): self {
        if (!is_file($file) || !is_readable($file)) {
            throw InvalidValue::because('invalid-request', "cannot read the config of the simulator: $file");
        }
        $file = (string) realpath($file);
        $found = Grid::find($environment, $grid) ?? throw NotFound::for("grid called '$grid'", 'grids');
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
        // The folders of the old install (above its etc, and the ones its constants name), and the ones of the new simulator
        $expanded = ConfigReader::expand($raw);
        $roots = [];
        str_contains($file, '/etc/') && ($roots[] = substr($file, 0, (int) strpos($file, '/etc/')));
        foreach ($expanded['Const'] ?? [] as $key => $value) {
            $value = trim((string) $value, " \t\"");
            preg_match('/Directory$/i', (string) $key) === 1 && str_starts_with($value, '/') && ($roots[] = rtrim($value, '/'));
        }
        $master = (new \OpenSim\Provisioning\Presets([], dirname($found->directory(), 2)))->options()['master'] ?? null;
        $layout = $master === null ? [] : ConfigReader::expand(
            ['Const' => ['DataDirectory' => $plan->dataDirectory, 'CacheDirectory' => $plan->cacheDirectory]] + ConfigReader::parse($master->text('model.ini', [])),
        );
        ['kept' => $customizations, 'dropped' => $dropped] = RobustImporter::sorted(
            $raw,
            ConfigReader::parse((new SimulatorConfig())->render($plan)),
            $defaults,
            dirname($file),
            $exampleText,
            $expanded,
            array_values(array_unique(array_filter($roots, static fn(string $root): bool => $root !== '' && $root !== '/'))),
            array_map(static fn(array $values): array => array_map(static fn($v): string => trim((string) $v, '"'), $values), $layout),
        );

        // The way back: its databases and its files, as they are, before anything is written (the first start upgrades them)
        $backup = SourceBackup::prepare(
            $environment,
            $file,
            $plan->gridNick,
            Slug::simulatorOf($plan->slug, $plan->gridNick),
            array_column($regions, 'file'),
        );
        // The state of its scripts: the folders its engines keep it in (ScriptEnginesPath, relative to its bin folder)
        $base = rtrim(trim((string) ($expanded['Launch']['BinDir'] ?? ''), " \t\"") ?: dirname($file), '/');
        $folders = [];
        foreach (['XEngine', 'YEngine'] as $engine) {
            $path = trim((string) ($expanded[$engine]['ScriptEnginesPath'] ?? 'ScriptEngines'), " \t\"");
            $path = str_starts_with($path, '/') ? $path : "$base/$path";
            is_dir($path) && ($folders[(string) realpath($path)] = true);
        }

        // The modules its kept settings name, looked for in the core and in the modules of this core (ModuleLinker: the folder of its
        // name or of its version); for one that is in neither, its package when the kit has one, else the folder a build of it goes
        // to (the module in lowercase, as the packages have it) and the DLL of the older install that has it
        $core = basename($plan->coreDirectory);
        $version = preg_match('/\d+(?:\.\d+)+/', $core, $m) === 1 ? $m[0] : $core;
        $modules = rtrim($environment->layout->modulesRoot(), '/');
        $packaged = array_merge([], ...array_values(array_map(
            static fn(\OpenSim\Provisioning\OptionTemplate $option): array => $option->modules(),
            (new \OpenSim\Provisioning\Presets([], $environment->layout->etcRoot()))->options(),
        )));
        $missing = [];
        foreach (self::missingModules($customizations, $exampleText, [$plan->binDir(), "$modules/$core", "$modules/$version"]) as $module) {
            $name = strtolower($module['module']);
            if (in_array($name, $packaged, true)) {
                $missing[] = $module + ['package' => "opensim-$version-$name", 'name' => $name];

                continue;
            }
            $found = self::dllOf($module['module'], self::dlls(array_unique([...array_map('dirname', $backup->files), $base]), false));
            $missing[] = $module + ['folder' => "$modules/$version/$name", 'name' => $name] + ($found !== null ? ['file' => $found] : []);
        }

        // What the import reads, checked first: an old install keeps its files to its own user (setfacl gives them to the user of the
        // grid), a path this process cannot read is said in the plan, and nothing is written
        $unreadable = self::unreadable([...$backup->files, ...array_column($regions, 'file'), ...($scripts ? array_keys($folders) : [])]);

        return new self($plan, $file, $regions, $customizations, SimulatorImporter::regionsDirectory($raw, $file), $dropped, $backup, array_keys($folders), $scripts, $missing, $unreadable);
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
            // The way back, written before anything else, and the state of the scripts of the original
            'backup' => $this->backup?->document(),
            'scripts' => [
                'from' => $this->scripts,
                'to' => $this->scriptsDirectory(),
                'size' => array_sum(array_map(self::size(...), $this->scripts)),
                'copied' => $this->copyScripts && $this->scripts !== [],
            ],
            'writes' => array_values(
                array_unique([
                    ...($this->backup === null ? [] : [$this->backup->file]),
                    $plan->iniPath(),
                    ...array_column($this->regions, 'to'),
                    $this->reportPath(),
                    $plan->dataDirectory,
                    $plan->cacheDirectory,
                ]),
            ),
            'reminders' => [
                // The files of an older install belong to its user (640 on opensim-debian); the import runs as the user of the grid
                ...($this->unreadable === [] ? [] : [Notice::of('import-read-access', ['paths' => implode(', ', $this->unreadable)])]),
                // A folder of regions that has none is said: the folder its config names may not be the one it reads
                $this->regions === []
                    ? Notice::of('import-no-region', ['simulator' => $plan->simulatorName, 'folder' => $this->regionsDirectory])
                    : Notice::of('import-stop-source', ['regions' => implode(', ', array_column($this->regions, 'name'))]),
                // A module a kept setting names that neither the core nor the modules of the install have: the simulator would not load it
                ...array_map(static fn(array $m): array => Notice::of(isset($m['package']) ? 'import-module-package' : (isset($m['file']) ? 'import-module-found' : 'import-module-missing'), $m), $this->missingModules),
                Notice::of('import-enable-start', ['enable' => "opensim enable {$plan->slug}", 'start' => "opensim start {$plan->slug}"]),
            ],
        ];
    }

    /**
     * Write the simulator, disabled: first the backup of its source, then its config, its regions, the settings it keeps, the state
     * of its scripts, the report of the import.
     *
     * @param ?\Closure(array{host:string,name:string,user:string,pass:string},string):void $dump writes the dump of a database for
     *        the backup (default: DatabaseDump::write())
     */
    public function apply(Environment $environment, UiInterface $ui, ?\Closure $dump = null): void
    {
        $plan = $this->plan;
        // Refused before anything is written when a file of the source cannot be read: an import half done is worse than none
        $this->unreadable === [] || throw InvalidValue::because(
            'source-not-readable',
            'this process cannot read what the import copies: ' . implode(', ', $this->unreadable) . ' (give the user of the grid read access to them)',
        );
        if ($this->backup !== null) {
            $ui->note(sprintf(_('Wrote %s'), $this->backup->write($dump)));
        }
        (new SimulatorProvisioner($ui))->apply($plan, $environment);
        is_dir($plan->regionsDir()) || mkdir($plan->regionsDir(), 0o755, true);
        foreach ($this->regions as $region) {
            if (!copy($region['file'], $region['to'])) {
                throw InvalidValue::because('invalid-request', "cannot copy {$region['file']} to {$region['to']}");
            }
            $ui->note(sprintf(_('Wrote %s'), $region['to']));
        }
        if ($this->copyScripts && $this->scripts !== []) {
            is_dir($this->scriptsDirectory()) || mkdir($this->scriptsDirectory(), 0o750, true);
            foreach ($this->scripts as $from) {
                exec('cp -a ' . escapeshellarg("$from/.") . ' ' . escapeshellarg($this->scriptsDirectory()) . ' 2>&1', $output, $status);
                $status === 0 || throw new \RuntimeException("cannot copy the state of the scripts from $from: " . implode(' ', $output));
            }
            $ui->note(sprintf(_('Copied the state of the scripts to %s'), $this->scriptsDirectory()));
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
            $lines[] = "  [{$setting['section']}] " . Secrets::mask("{$setting['key']} = {$setting['value']}") . " ({$setting['reason']}" .
                (($setting['replacement'] ?? null) !== null ? ", now {$setting['replacement']}" : '') . ')';
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
        // Only the secret is masked: a connection string keeps its host, its database and its user
        $line = "{$setting['key']} = {$setting['value']}";
        $masked = Secrets::mask($line);
        $value = str_starts_with($masked, "{$setting['key']} = ") ? substr($masked, strlen("{$setting['key']} = ")) : Secrets::MASK;

        return ['value' => $masked === $line ? $setting['value'] : $value] + $setting;
    }

    /**
     * The modules the kept settings name (a key that ends in Module: `[Search] Module`, `economymodule`...) that the core does not
     * know: neither in its example, nor in a DLL of its bin folder or of the modules of the install (the name of a region module is a
     * string of its DLL, UTF-16 as .NET keeps it).
     *
     * @param list<array{section:string,key:string,value:string}> $kept
     * @param list<string> $folders where the DLLs are
     * @return list<array{section:string,key:string,module:string}>
     */
    private static function missingModules(array $kept, string $exampleText, array $folders): array
    {
        $named = [];
        foreach ($kept as $setting) {
            $module = trim($setting['value'], " \t\"");
            if (preg_match('/Module$/i', $setting['key']) === 1 && preg_match('/^[A-Za-z][\w.]*$/', $module) === 1
                && preg_match('/\b' . preg_quote($module, '/') . '\b/', $exampleText) !== 1) {
                $named[] = ['section' => $setting['section'], 'key' => $setting['key'], 'module' => $module];
            }
        }
        if ($named === []) {
            return [];
        }
        $dlls = self::dlls($folders, true);

        return array_values(array_filter($named, static fn(array $m): bool => self::dllOf($m['module'], $dlls) === null));
    }

    /**
     * The DLLs of folders, of their subfolders too when $deep.
     *
     * @param list<string> $folders
     * @return list<string>
     */
    private static function dlls(array $folders, bool $deep): array
    {
        $dlls = [];
        foreach (array_filter($folders, static fn(string $folder): bool => is_dir($folder) && is_readable($folder)) as $folder) {
            $files = $deep
                ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS))
                : new \FilesystemIterator($folder);
            foreach ($files as $file) {
                $file->isFile() && str_ends_with(strtolower($file->getFilename()), '.dll') && ($dlls[] = $file->getPathname());
            }
        }

        return $dlls;
    }

    /**
     * The first DLL that has a module: its name as a string of it, UTF-16 as .NET keeps it, or as it is.
     *
     * @param list<string> $dlls
     */
    private static function dllOf(string $module, array $dlls): ?string
    {
        foreach ($dlls as $dll) {
            $bytes = is_readable($dll) ? (string) file_get_contents($dll) : '';
            if (str_contains($bytes, mb_convert_encoding($module, 'UTF-16LE')) || str_contains($bytes, $module)) {
                return $dll;
            }
        }

        return null;
    }

    /**
     * The paths this process cannot read, among files and folders (all of a folder, its subfolders too).
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private static function unreadable(array $paths): array
    {
        $found = [];
        foreach (array_unique($paths) as $path) {
            if (!is_readable($path)) {
                $found[] = $path;

                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
                \RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($iterator as $entry) {
                $entry->isReadable() && (!$entry->isDir() || $entry->isExecutable()) || ($found[] = $entry->getPathname());
            }
        }

        return array_values(array_unique($found));
    }

    /** Where the new simulator keeps the state of its scripts (the ScriptEnginesPath of its layout). */
    private function scriptsDirectory(): string
    {
        return rtrim($this->plan->dataDirectory, '/') . '/ScriptEngines';
    }

    /** The bytes of the files of a folder. */
    private static function size(string $folder): int
    {
        $size = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
        foreach ($files as $file) {
            $file->isFile() && $file->isReadable() && ($size += $file->getSize());
        }

        return $size;
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
