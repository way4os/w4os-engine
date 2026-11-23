<?php

declare(strict_types=1);

namespace W4OS\Engine\Runtime;

/**
 * Where an instance writes, by its config, and what of it the account running this code cannot write: an instance that cannot
 * write its PID file, its map tiles or its log hangs or dies without a word.
 */
final class InstanceWrites
{
    /** The settings that name where it writes: the folder of a file, or a folder (a relative one is in the bin folder of its core). */
    private const WRITTEN = [
        'Startup' => ['PIDFile' => 'file', 'ConsoleHistoryFile' => 'file'],
        'Map' => ['MapTileDirectory' => 'folder'],
        'AssetCache' => ['CacheDirectory' => 'folder'],
        'XEngine' => ['ScriptEnginesPath' => 'folder'],
        'YEngine' => ['ScriptEnginesPath' => 'folder'],
        'DataSnapshot' => ['data_snapshot_path' => 'folder'],
    ];

    /**
     * The folders a config writes in.
     *
     * @param array<string,array<string,mixed>> $config its sections, expanded
     * @return list<string>
     */
    public static function folders(array $config, string $bin): array
    {
        $folders = [];
        foreach (self::WRITTEN as $section => $keys) {
            foreach ($keys as $key => $kind) {
                $value = trim((string) ($config[$section][$key] ?? ''), '"');
                if ($value === '') {
                    continue;
                }
                $path = str_starts_with($value, '/') ? $value : ($bin !== '' ? rtrim($bin, '/') . "/$value" : '');
                $path === '' || $folders[] = $kind === 'file' ? dirname($path) : $path;
            }
        }

        return array_values(array_unique($folders));
    }

    /**
     * The paths of these an account cannot write (the one running this code when none is named), each the path itself or, when it
     * is not there yet, the first of its parents that is (where it would be made): path => its owner.
     *
     * @param list<string> $paths
     * @return array<string,string>
     */
    public static function unwritable(array $paths, string $account = ''): array
    {
        $refused = [];
        foreach (array_unique($paths) as $path) {
            for ($there = $path; !file_exists($there) && $there !== dirname($there); $there = dirname($there));
            if (!self::writes($there, $account)) {
                $refused[$there] = self::name((int) fileowner($there));
            }
        }

        return $refused;
    }

    /** Whether an account writes in a path, by its owner, group and mode (root writes everywhere). */
    private static function writes(string $path, string $account): bool
    {
        $entry = $account !== '' && function_exists('posix_getpwnam') ? posix_getpwnam($account) : false;
        if ($entry === false || (function_exists('posix_geteuid') && $entry['uid'] === posix_geteuid())) {
            return is_writable($path);
        }
        if ($entry['uid'] === 0) {
            return true;
        }
        $mode = (int) fileperms($path);
        if ((int) fileowner($path) === $entry['uid']) {
            return ($mode & 0o200) !== 0;
        }
        $group = (int) filegroup($path);
        $member = $group === $entry['gid'] || in_array($account, (posix_getgrgid($group) ?: [])['members'] ?? [], true);

        return ($mode & ($member ? 0o020 : 0o002)) !== 0;
    }

    /** The name of an account by its number, the number when it has none. */
    private static function name(int $uid): string
    {
        return function_exists('posix_getpwuid') ? (posix_getpwuid($uid)['name'] ?? (string) $uid) : (string) $uid;
    }
}
