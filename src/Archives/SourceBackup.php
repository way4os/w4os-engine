<?php

declare(strict_types=1);

namespace OpenSim\Archives;

use OpenSim\Config\ConfigReader;
use OpenSim\Config\ConnectionString;
use OpenSim\Runtime\Environment;

/**
 * The backup of a simulator of another install (opensim-debian, a release), as it is before an import changes anything: a dump of
 * each database its config names, and a copy of the files it is read from (its ini, the files it includes, its regions), in the
 * backup root of this install (`<grid>/<simulator>/<grid>-<simulator>-<stamp>-source.tar.gz`, mode 640: it holds passwords).
 *
 * The first start of an imported simulator on a newer core upgrades its databases: this is the way back. A restore of a grid
 * (BackupRestore) refuses it, it is not one.
 *
 *     $backup = SourceBackup::prepare($environment, '/opt/opensim/etc/opensim.d/redoutable.ini', 'speculoos', 'redoutable');
 *     $backup->document();   // where it goes, its databases, its files
 *     $backup->write();
 */
final class SourceBackup
{
    /**
     * @param list<string> $files the files it copies
     * @param list<array{host:string,name:string,user:string,pass:string}> $databases the ones it dumps
     */
    private function __construct(
        public readonly string $config,
        public readonly string $file,
        public readonly array $files,
        public readonly array $databases,
    ) {}

    /**
     * What the backup of a simulator holds, and where it goes.
     *
     * @param string $config the config of the simulator (its OpenSim.ini, or its file of opensim.d)
     * @param string $grid the nick its archive is named after
     * @param string $simulator the name of the simulator in it (Archives::part())
     * @param list<string> $extra other files to keep with its config (its regions)
     */
    public static function prepare(Environment $environment, string $config, string $grid, string $simulator, array $extra = []): self
    {
        $config = (string) (realpath($config) ?: $config);
        $files = array_values(array_unique([...ConfigReader::files($config, [], true), ...array_filter(array_map('realpath', $extra))]));
        // Every database the config names (ConnectionString, EstateConnectionString...), once each
        $databases = [];
        foreach (ConfigReader::load($config, [], true, true) as $settings) {
            foreach ($settings as $key => $value) {
                $connection = preg_match('/ConnectionString$/i', (string) $key) === 1 ? ConnectionString::parse(trim((string) $value, " \t\"")) : null;
                if ($connection !== null && $connection->driver !== 'sqlite' && $connection->database !== '') {
                    $databases[$connection->key()] = ['host' => $connection->source, 'name' => $connection->database, 'user' => $connection->user, 'pass' => $connection->password];
                }
            }
        }
        $part = Archives::part($simulator);
        $file = sprintf('%s/%s/%s/%s-%s-%s-source.tar.gz', $environment->layout->backupRoot(), $grid, $part, $grid, $part, Archives::stamp());

        return new self($config, $file, $files, array_values($databases));
    }

    /**
     * Where it goes, the databases it dumps, the files it copies and their size (the dumps are counted when they are made).
     *
     * @return array{file:string,databases:list<string>,files:list<string>,size:int}
     */
    public function document(): array
    {
        return [
            'file' => $this->file,
            'databases' => array_column($this->databases, 'name'),
            'files' => $this->files,
            'size' => array_sum(array_map(static fn(string $file): int => (int) filesize($file), $this->files)),
        ];
    }

    /**
     * Write it: each database dumped, the files copied by their path, a manifest; the archive is its owner's (640).
     *
     * @param ?\Closure(array{host:string,name:string,user:string,pass:string},string):void $dump writes the dump of a database
     *        (default: DatabaseDump::write())
     * @return string the archive
     * @throws \RuntimeException when a database cannot be dumped or the archive written
     */
    public function write(?\Closure $dump = null): string
    {
        $dump ??= DatabaseDump::write(...);
        $folder = dirname($this->file);
        is_dir($folder) || @mkdir($folder, 0o750, true) || is_dir($folder) || throw new \RuntimeException("cannot make the folder $folder");
        is_file($this->file) && throw new \RuntimeException("{$this->file} exists already");
        $stage = sys_get_temp_dir() . '/opensim-source-' . bin2hex(random_bytes(6));
        mkdir("$stage/db", 0o700, true);
        try {
            foreach ($this->databases as $db) {
                $dump($db, "$stage/db/{$db['name']}.sql.gz");
            }
            foreach ($this->files as $file) {
                $to = "$stage/files$file";
                is_dir(dirname($to)) || mkdir(dirname($to), 0o700, true);
                copy($file, $to) || throw new \RuntimeException("cannot copy $file");
            }
            file_put_contents("$stage/manifest.json", json_encode([
                'kind' => 'source',
                'config' => $this->config,
                'date' => date('c'),
                'host' => gethostname(),
                'databases' => array_map(static fn(array $db): array => ['host' => $db['host'], 'name' => $db['name'], 'user' => $db['user']], $this->databases),
                'files' => $this->files,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            exec('tar -czf ' . escapeshellarg($this->file) . ' -C ' . escapeshellarg($stage) . ' . 2>&1', $output, $status);
            $status === 0 || throw new \RuntimeException("cannot write {$this->file}: " . implode(' ', $output));
            chmod($this->file, 0o640);
        } catch (\Throwable $error) {
            is_file($this->file) && unlink($this->file);
            throw $error instanceof \RuntimeException ? $error : new \RuntimeException($error->getMessage(), 0, $error);
        } finally {
            exec('rm -rf ' . escapeshellarg($stage));
        }

        return $this->file;
    }
}
