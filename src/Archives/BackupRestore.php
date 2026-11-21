<?php

declare(strict_types=1);

namespace W4OS\Engine\Archives;

use W4OS\Engine\Config\ConnectionString;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Exceptions\InvalidValue;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\DatabaseService;
use W4OS\Engine\Provisioning\GridPlan;
use W4OS\Engine\Provisioning\NullUi;
use W4OS\Engine\Runtime\Environment;
use W4OS\Engine\Text\Notice;

/**
 * A backup (BackupService: a grid, or one of its simulators) put back on this machine: what would be written and where (prepare(),
 * document()), then written (apply()). Its configuration goes to the etc root of this install, the links that enable its instances
 * pointing there; its data to the data folder of its grid here; each database is made again from its dump, in the database its
 * config names (made when it is missing, refused when it has tables). The logs it may hold are left in it.
 *
 *     $restore = BackupRestore::prepare($environment, '/srv/backups/alpha/alpha-20261003-101500.tar.gz');
 *     $restore->document();   // the plan: nothing is written
 *     $restore->apply();
 *
 * Nothing that is here is overwritten: the configuration of what it restores must not be here (a grid, a simulator), the files of
 * the grid a simulator shares are kept as they are.
 */
final class BackupRestore
{
    /**
     * @param array<string,mixed> $manifest what the backup says of itself
     * @param list<string> $entries the paths of the archive
     */
    private function __construct(
        private readonly Environment $environment,
        public readonly string $file,
        public readonly array $manifest,
        private readonly array $entries,
        private readonly array $shared = [],
        private readonly array $regionUuids = [],
    ) {}

    /**
     * What the restore would do, checked, nothing written.
     *
     * @throws InvalidValue `invalid-request` for a file that is not a backup, `restore-conflict` for what is here already
     */
    public static function prepare(Environment $environment, string $file): self
    {
        if (!is_file($file) || !is_readable($file)) {
            throw InvalidValue::because('invalid-request', "cannot read the backup: $file");
        }
        $manifest = json_decode((string) self::tar(['-xzOf', $file, 'manifest.json']), true);
        if (!is_array($manifest) || !is_string($manifest['grid'] ?? null) || $manifest['grid'] === '') {
            throw InvalidValue::because('invalid-request', "$file is not a backup of a grid (it has no manifest.json)");
        }
        $entries = array_values(array_filter(array_map(
            static fn(string $line): string => rtrim($line, '/'),
            explode("\n", (string) self::tar(['-tzf', $file])),
        )));
        // The databases of the grid a simulator shares (its estates, when Robust serves them), and the UUIDs of its regions: read
        // from the configuration it holds
        $shared = [];
        $uuids = [];
        $simulator = is_string($manifest['simulator'] ?? null) && $manifest['simulator'] !== '' ? $manifest['simulator'] : null;
        if ($simulator !== null) {
            $stage = sys_get_temp_dir() . '/opensim-restore-' . bin2hex(random_bytes(6));
            mkdir($stage, 0o700, true);
            try {
                self::tar(['-xzf', $file, '-C', $stage, 'etc']);
                $held = Grid::at($environment, "$stage/etc/grids/{$manifest['grid']}");
                $grid = array_map(static fn(ConnectionString $c): string => $c->database, $held->ownConnections());
                foreach ($held->connections($simulator) as $connection) {
                    in_array($connection->database, $grid, true) && ($shared[] = $connection->database);
                }
                foreach (glob("$stage/etc/grids/{$manifest['grid']}/" . \W4OS\Engine\Config\GridConfig::SIMULATORS . "/$simulator/regions/*.ini") ?: [] as $region) {
                    foreach (\W4OS\Engine\Config\ConfigReader::load($region, [], false) as $name => $settings) {
                        $uuid = strtolower(trim((string) ($settings['RegionUUID'] ?? ''), " \t\""));
                        $uuid === '' || ($uuids[$uuid] = (string) $name);
                    }
                }
            } finally {
                exec('rm -rf ' . escapeshellarg($stage));
            }
        }
        $restore = new self($environment, (string) realpath($file), $manifest, $entries, array_values(array_unique($shared)), $uuids);
        $conflicts = $restore->conflicts();
        if ($conflicts !== []) {
            throw InvalidValue::because('restore-conflict', 'this machine has already what the backup holds: ' . implode(', ', $conflicts));
        }

        return $restore;
    }

    /** The grid it holds. */
    public function grid(): string
    {
        return (string) $this->manifest['grid'];
    }

    /** The simulator it holds alone, null for a whole grid. */
    public function simulator(): ?string
    {
        $simulator = $this->manifest['simulator'] ?? null;

        return is_string($simulator) && $simulator !== '' ? $simulator : null;
    }

    /**
     * What the restore writes, where, and what is left to do: the plan, as the API gives it.
     *
     * @return array<string,mixed>
     */
    public function document(): array
    {
        $writes = [];
        $kept = [];
        foreach ($this->files() as [$entry, $to]) {
            $this->isShared($entry) && file_exists($to) ? ($kept[] = $to) : ($writes[] = $to);
        }
        $instances = $this->instances();
        $keptDatabases = $this->keptDatabases();
        $missing = $keptDatabases === [] ? [] : $this->missingEstates();

        return [
            'file' => $this->file,
            'grid' => $this->grid(),
            'simulator' => $this->simulator(),
            'made' => $this->manifest['date'] ?? null,
            'host' => $this->manifest['host'] ?? null,
            'databases' => array_map(
                static fn(array $db): array => [
                    'host' => (string) ($db['host'] ?? ''),
                    'name' => (string) ($db['name'] ?? ''),
                    'user' => (string) ($db['user'] ?? ''),
                    // A database of the grid a simulator shares, kept as it is here
                    'kept' => in_array((string) ($db['name'] ?? ''), $keptDatabases, true),
                ],
                (array) ($this->manifest['databases'] ?? []),
            ),
            'writes' => $writes,
            'kept' => $kept,
            'reminders' => [
                // Its regions have no estate in the database of the grid here: they would start with none
                ...($missing === null || $missing === [] ? [] : [Notice::of('restore-estate-missing', [
                    'regions' => implode(', ', $missing),
                    'database' => implode(', ', $keptDatabases),
                ])]),
                ...($missing === null ? [Notice::of('restore-estate-unchecked', ['database' => implode(', ', $keptDatabases)])] : []),
                ...array_map(
                    static fn(string $instance): array => Notice::of('restore-start', ['instance' => $instance, 'command' => "opensim start $instance"]),
                    $instances,
                ),
            ],
        ];
    }

    /**
     * Write it: each database from its dump first (a database refused leaves no file written), then the configuration and the data.
     *
     * @param ?\Closure(ConnectionString, string):void $load makes a database again from a dump (default: made when missing, refused
     *        when it has tables, DatabaseDump::load())
     * @throws \RuntimeException when a file cannot be written or a database made
     */
    public function apply(?\Closure $load = null): void
    {
        $load ??= self::loader(...);
        $stage = sys_get_temp_dir() . '/opensim-restore-' . bin2hex(random_bytes(6));
        mkdir($stage, 0o700, true);
        try {
            self::tar(['-xzf', $this->file, '-C', $stage]) ?? throw new \RuntimeException("cannot read {$this->file}");
            // The databases first, with the accounts of the configuration it holds: a database refused leaves no file written
            $connections = [];
            $held = "$stage/etc/grids/{$this->grid()}";
            foreach (Grid::at($this->environment, $held)->connections($this->simulator()) as $connection) {
                $connections[$connection->database] = $connection;
            }
            $keptDatabases = $this->keptDatabases();
            foreach ((array) ($this->manifest['databases'] ?? []) as $db) {
                $name = (string) ($db['name'] ?? '');
                $dump = "$stage/db/$name.sql.gz";
                // A database of the grid a simulator shares is the one of the grid here: kept as it is
                if ($name === '' || !is_file($dump) || in_array($name, $keptDatabases, true)) {
                    continue;
                }
                $connection = $connections[$name] ?? throw new \RuntimeException("no config of the backup names the database $name");
                $load($connection, $dump);
            }
            foreach ($this->files() as [$entry, $to]) {
                $from = "$stage/$entry";
                if ($this->isShared($entry) && file_exists($to)) {
                    continue;
                }
                is_dir(dirname($to)) || mkdir(dirname($to), 0o750, true);
                if (is_link($from)) {
                    // A link that enabled an instance points where its config is restored
                    $target = (string) readlink($from);
                    // An archive the tar of macOS made before 3.0.0~beta.13 renamed the targets too (etc//path)
                    str_starts_with($target, 'etc//') && ($target = substr($target, 4));
                    $old = rtrim((string) ($this->manifest['etc'] ?? ''), '/');
                    $old !== '' && str_starts_with($target, "$old/") && ($target = $this->etc() . substr($target, strlen($old)));
                    @symlink($target, $to) || throw new \RuntimeException("cannot link $to");
                } elseif (is_dir($from)) {
                    is_dir($to) || mkdir($to, fileperms($from) & 0o777, true);
                } else {
                    copy($from, $to) || throw new \RuntimeException("cannot write $to");
                    chmod($to, fileperms($from) & 0o777);
                }
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($stage));
        }
    }

    /**
     * The paths of the archive that are written, and where: its configuration under the etc root, its data under the data folder of
     * its grid.
     *
     * @return list<array{0:string,1:string}> entry => path on this machine
     */
    private function files(): array
    {
        $data = rtrim((string) ($this->manifest['data'] ?? ''), '/');
        $files = [];
        foreach ($this->entries as $entry) {
            if (str_starts_with($entry, 'etc/')) {
                $files[] = [$entry, $this->etc() . '/' . substr($entry, 4)];
            } elseif (str_starts_with($entry, 'var/')) {
                // var/<the folder of its data>/...: the data folder of the grid here
                $rest = explode('/', substr($entry, 4), 2);
                $data !== '' && isset($rest[1]) && ($files[] = [$entry, $this->dataDirectory() . '/' . $rest[1]]);
            }
        }

        return $files;
    }

    /** Whether a path of the archive is a file of the grid a simulator shares with the others: kept when it is here. */
    private function isShared(string $entry): bool
    {
        $simulator = $this->simulator();
        if ($simulator === null) {
            return false;
        }
        $sims = "etc/grids/{$this->grid()}/" . \W4OS\Engine\Config\GridConfig::SIMULATORS;

        return str_starts_with($entry, 'etc/grids/') &&
            $entry !== "$sims/$simulator.ini" &&
            !str_starts_with($entry, "$sims/$simulator/");
    }

    /**
     * The databases of the grid a simulator of the backup shares (its estates, when Robust serves them), kept as they are when its
     * grid is here: the ones of the grid that runs here, which a restore of the grid only replaces.
     *
     * @return list<string>
     */
    private function keptDatabases(): array
    {
        return $this->simulator() !== null && is_dir($this->etc() . "/grids/{$this->grid()}") ? $this->shared : [];
    }

    /**
     * The regions of the simulator that have no estate in the database of the estates of the grid here (estate_map and
     * estate_settings), null when it cannot be read.
     *
     * @return ?list<string> their names
     */
    private function missingEstates(): ?array
    {
        if ($this->regionUuids === []) {
            return [];
        }
        $config = \W4OS\Engine\Config\GridConfig::fromFolder($this->etc() . "/grids/{$this->grid()}");
        $pdo = \W4OS\Engine\Data\Connections::pdo($config, \W4OS\Engine\Data\Database::Estate);
        if ($pdo === null || !$pdo->connected) {
            return null;
        }
        try {
            $marks = implode(',', array_fill(0, count($this->regionUuids), '?'));
            $found = $pdo->get_results(
                "SELECT LOWER(m.RegionID) FROM estate_map m INNER JOIN estate_settings s ON s.EstateID = m.EstateID WHERE LOWER(m.RegionID) IN ($marks)",
                array_keys($this->regionUuids),
                \PDO::FETCH_COLUMN,
            );
        } catch (\Throwable) {
            return null;
        }

        return array_values(array_diff_key($this->regionUuids, array_flip(array_map('strval', $found))));
    }

    /** What is here already that the restore would write: the config of the grid, or of the simulator. @return list<string> */
    private function conflicts(): array
    {
        $grid = $this->etc() . "/grids/{$this->grid()}";
        if ($this->simulator() === null) {
            return is_dir($grid) ? [$grid] : [];
        }
        $found = [];
        foreach ($this->files() as [$entry, $to]) {
            $this->isShared($entry) || !file_exists($to) || is_dir($to) || ($found[] = $to);
        }

        return $found;
    }

    /** @return list<string> the instances it enables (its Robust, its simulators), by the links it holds */
    private function instances(): array
    {
        $instances = [];
        foreach ($this->entries as $entry) {
            if (preg_match('#^etc/(robust|opensim)\.d/([^/]+)\.ini$#', $entry, $m) === 1) {
                $instances[] = $m[2];
            }
        }

        return $instances;
    }

    private function etc(): string
    {
        return rtrim($this->environment->layout->etcRoot(), '/');
    }

    /** The data folder of its grid on this machine. */
    private function dataDirectory(): string
    {
        return rtrim($this->environment->layout->dataRoot(), '/') . '/' . $this->grid();
    }

    /**
     * A database made again from its dump: made when it is missing (DatabaseService, the administrator of the session), refused when
     * it has tables, nothing of it replaced.
     *
     * @throws \RuntimeException
     */
    private static function loader(ConnectionString $db, string $dump): void
    {
        $plan = new GridPlan();
        $plan->dbHost = $db->source;
        $plan->dbName = $db->database;
        $plan->dbUser = $db->user;
        $plan->dbPass = $db->password;
        DatabaseService::remember($db->source, $db->user, $db->password);
        (new DatabaseService(new NullUi()))->ensure($plan) === DatabaseService::OK ||
            throw new \RuntimeException("the database {$db->database} @ {$db->source} cannot be had: an administrator of the server makes it (CREATE DATABASE, GRANT)");
        $pdo = new OSPDO(['host' => $db->source, 'name' => $db->database, 'user' => $db->user, 'pass' => $db->password, 'log' => false]);
        $pdo->connected || throw new \RuntimeException("cannot connect to {$db->database} @ {$db->source}");
        $pdo->get_results('SHOW TABLES', [], \PDO::FETCH_COLUMN) === [] ||
            throw InvalidValue::because('restore-conflict', "the database {$db->database} @ {$db->source} has tables: it is not replaced");
        DatabaseDump::load($pdo, $dump);
    }

    /**
     * What tar says, null when it fails.
     *
     * @param list<string> $arguments
     */
    private static function tar(array $arguments): ?string
    {
        $command = 'tar ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>/dev/null';
        exec($command, $lines, $status);

        return $status === 0 ? implode("\n", $lines) : null;
    }
}
