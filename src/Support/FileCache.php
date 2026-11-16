<?php

declare(strict_types=1);

namespace OpenSim\Support;

use OpenSim\Runtime\ClockInterface;

/**
 * A folder of files kept for a while: data, with what is known of it (the data of an asset as the grid gave it or converted, the answer of a grid). A file
 * that is empty or older than the time to live is not kept.
 */
final class FileCache
{
    /** @var array<string,true> the folders already said in the log not to be writable, once per process */
    private static array $warned = [];

    public function __construct(
        private readonly string $directory,
        private readonly int $ttl = 86400,
        private readonly ?ClockInterface $clock = null,
    ) {}

    /**
     * @return ?array{data:string,meta:array<string,mixed>} null when it is not there, or not any more
     */
    public function get(string $name): ?array
    {
        $file = $this->file($name);
        if (!is_file($file)) {
            return null;
        }
        $now = (int) ($this->clock?->time() ?? time());
        if (filesize($file) === 0 || (int) filemtime($file) < $now - $this->ttl) {
            @unlink($file);
            is_file("$file.meta") && @unlink("$file.meta");

            return null;
        }
        $data = file_get_contents($file);
        $meta = is_file("$file.meta") ? json_decode((string) file_get_contents("$file.meta"), true) : [];

        return is_string($data) ? ['data' => $data, 'meta' => is_array($meta) ? $meta : []] : null;
    }

    /** @param array<string,mixed> $meta what is known of the data (its type, its content type) */
    public function put(string $name, string $data, array $meta = []): bool
    {
        if ($data === '') {
            return false;
        }
        if (
            !is_dir($this->directory) &&
            (!$this->writable() || !(@mkdir($this->directory, 0o775, true) || is_dir($this->directory)))
        ) {
            return $this->unwritable();
        }
        $file = $this->file($name);
        if (@file_put_contents($file, $data) === false) {
            return $this->unwritable();
        }
        @touch($file, (int) ($this->clock?->time() ?? time()));
        $meta !== [] && @file_put_contents("$file.meta", json_encode($meta));

        return true;
    }

    /** Whether the folder can be written: it is, or the closest folder that is there lets it be made. */
    public function writable(): bool
    {
        $folder = $this->directory;
        while (!file_exists($folder)) {
            $parent = dirname($folder);
            if ($parent === $folder) {
                return false;
            }
            $folder = $parent;
        }

        // What is there must be a folder: a file in the way keeps it from being made
        return is_dir($folder) && is_writable($folder);
    }

    /** The folder of the files. */
    public function directory(): string
    {
        return $this->directory;
    }

    /** Nothing is kept: said once in the log for a folder, the same data is made again on every request until it is fixed. */
    private function unwritable(): bool
    {
        if (!isset(self::$warned[$this->directory])) {
            self::$warned[$this->directory] = true;
            $user = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? (string) posix_geteuid()) : get_current_user();
            error_log(
                "opensim: warning: the cache folder {$this->directory} cannot be written by $user: nothing is kept, what it holds is made again on every request",
            );
        }

        return false;
    }

    private function file(string $name): string
    {
        return $this->directory . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    }
}
