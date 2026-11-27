<?php

declare(strict_types=1);

namespace W4OS\Engine\Runtime;

/**
 * The releases of OpenSimulator as its site publishes them (the binary archives of opensimulator.org/dist), and the install of
 * one under a core root: the archive downloaded once, unpacked in `<root>/<version>`. For a machine that has no package of a core.
 */
final class CoreReleases
{
    public const URL = 'http://opensimulator.org/dist';

    /** How long the list is kept: it changes once a year or two. */
    private const TTL = 604800;

    /**
     * @param string $cache the folder the list and the archives are kept in
     * @param ?\Closure(string):string $fetch the page of the releases, '' when it cannot be had (default: the site)
     * @param ?\Closure(string,string):bool $download an archive to a file (default: curl)
     */
    public function __construct(
        private readonly string $cache,
        private readonly ?\Closure $fetch = null,
        private readonly ?\Closure $download = null,
    ) {}

    /**
     * The releases, the newest first: the binary archives, never the sources.
     *
     * @return list<array{version:string,archive:string}>
     */
    public function available(): array
    {
        preg_match_all('/href="(opensim-([0-9][0-9.]*[0-9])\.tar\.gz)"/', $this->page(), $found, PREG_SET_ORDER);
        $releases = [];
        foreach ($found as [, $file, $version]) {
            $releases[$version] = ['version' => $version, 'archive' => self::URL . '/' . $file];
        }
        uksort($releases, static fn(string $a, string $b): int => version_compare($b, $a));

        return array_values($releases);
    }

    /**
     * A release unpacked under a core root, its folder; one that is there already is kept as it is.
     *
     * @param ?\Closure(string):void $said what is being done, for a live line
     * @throws \RuntimeException when it is not a release, or cannot be downloaded or unpacked
     */
    public function install(string $version, string $root, ?\Closure $said = null): string
    {
        $directory = rtrim($root, '/') . '/' . $version;
        if (Core::at($directory)->exists()) {
            return $directory;
        }
        $release = array_column($this->available(), 'archive', 'version')[$version] ?? null;
        $release !== null || throw new \RuntimeException(sprintf(_('Not a release of OpenSimulator: %s'), $version));
        $archive = rtrim($this->cache, '/') . '/' . basename($release);
        if ((!is_dir($this->cache) && !@mkdir($this->cache, 0o755, true)) || (!is_dir($directory) && !@mkdir($directory, 0o755, true))) {
            throw new \RuntimeException(sprintf(_('Cannot write in %s.'), is_dir($this->cache) ? dirname($directory) : $this->cache));
        }
        if (!is_file($archive)) {
            $said !== null && $said(sprintf(_('downloading %s'), basename($release)));
            $done = ($this->download ?? static fn(string $url, string $file): bool => ShellService::run('curl -fsSL ' . ShellService::arg($url) . ' -o ' . ShellService::arg("$file.part")) === 0 && rename("$file.part", $file))($release, $archive);
            if (!$done || !is_file($archive)) {
                @unlink("$archive.part");
                @rmdir($directory);

                throw new \RuntimeException(sprintf(_('Could not download %s.'), $release));
            }
        }
        $said !== null && $said(sprintf(_('unpacking %s'), basename($release)));
        // The archive holds one folder, opensim-<version>: what it holds goes in the folder of the core. Never the owners it names.
        $code = ShellService::run('tar xzf ' . ShellService::arg($archive) . ' --no-same-owner --strip-components=1 -C ' . ShellService::arg($directory));
        if ($code !== 0 || !Core::at($directory)->exists()) {
            throw new \RuntimeException(sprintf(_('Could not unpack %s in %s.'), basename($release), $directory));
        }
        // OpenSim puts its System.Drawing.Common.dll for Linux and macOS in place at its start: done now, for a core its
        // instances cannot write in
        $library = "$directory/bin/System.Drawing.Common.dll";
        is_file("$library.linux") && @copy("$library.linux", $library);

        return $directory;
    }

    /** The page of the releases: the one kept while it is fresh, the site's, else the one kept however old. */
    private function page(): string
    {
        $kept = rtrim($this->cache, '/') . '/releases.html';
        if (is_file($kept) && time() - (int) filemtime($kept) < self::TTL) {
            return (string) file_get_contents($kept);
        }
        $page = ($this->fetch ?? static fn(string $url): string => (string) @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60]])))(self::URL . '/');
        if ($page === '') {
            return is_file($kept) ? (string) file_get_contents($kept) : '';
        }
        (is_dir($this->cache) || @mkdir($this->cache, 0o755, true)) && @file_put_contents($kept, $page);

        return $page;
    }
}
