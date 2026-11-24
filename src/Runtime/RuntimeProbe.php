<?php

declare(strict_types=1);

namespace W4OS\Engine\Runtime;

/**
 * Whether the machine can run a core: the runtime it needs (Mono, the .NET major it targets: a newer one breaks it), the native
 * libraries it cannot start without. Says what is missing, with the command that installs it.
 */
class RuntimeProbe
{
    /**
     * @param ?\Closure(string):string $capture the output of a command, empty when it fails (default: the shell)
     * @param list<string> $libraryFolders where the libraries are looked for when the loader does not know them (a * is a wildcard)
     * @param string $proc where the processes of the machine are, the runtime each loaded (/proc)
     */
    public function __construct(
        private readonly ?\Closure $capture = null,
        private readonly array $libraryFolders = ['/usr/lib', '/usr/lib64', '/usr/lib/*', '/usr/local/lib'],
        private readonly string $proc = '/proc',
    ) {}

    /**
     * What stops the core from running with the runtime it asks for, null when nothing does.
     *
     * @param string $installer the command that installs .NET, as told in the message
     */
    public function problem(Core $core, string $assembly, string $installer = 'opensim install-dotnet'): ?string
    {
        $runtime = $core->runtime(preg_replace('/\.(dll|exe)$/', '', $assembly) ?? $assembly);
        if ($runtime === 'mono') {
            return $this->exists('mono')
                ? null
                : 'mono is not installed (required for OpenSim < 0.9.3.0): install it with your package manager (e.g. sudo apt install mono-complete)';
        }
        $major = (int) explode('.', $core->dotnetRequired($assembly))[0];
        if (!$this->exists('dotnet')) {
            return "dotnet is not installed. Install the .NET runtime with: sudo $installer $major";
        }
        // The major it targets, never a newer one: .NET 9 removed what its asset cache writes with
        $installed = $this->dotnetMajors();
        if (in_array($major, $installed, true)) {
            return null;
        }

        return "OpenSim needs Microsoft.NETCore.App $major, which is not installed (installed: " .
            (implode(' ', array_map(static fn(int $m): string => (string) $m, $installed)) ?: 'none') .
            "; a newer one breaks its asset cache). Install it (sudo $installer $major) and retry";
    }

    /** The .NET major a process runs on, from the runtime it loaded; null when it is not a .NET process or cannot be read. */
    public function runningMajor(int $pid): ?int
    {
        $maps = @file_get_contents("{$this->proc}/$pid/maps");

        return is_string($maps) && preg_match('#Microsoft\.NETCore\.App/(\d+)\.#', $maps, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * The .NET major an instance runs on when it is not the one its core targets (a newer one breaks its asset cache: no
     * baked texture kept, the skins grey), null when it runs on it, does not run, or cannot be told.
     *
     * @return ?array{running:int,target:int}
     */
    public function wrongRuntime(\W4OS\Engine\Controllers\AbstractController $instance): ?array
    {
        $process = $instance->process();
        $running = $process === null ? null : $this->runningMajor($process->pid);
        $target = $running === null ? null : $instance->core()?->dotnetMajor($instance->executableBase());

        return $target !== null && $running !== $target ? ['running' => (int) $running, 'target' => $target] : null;
    }

    /** Whether a native library is installed (Linux): known to the loader, or in the usual folders. Name without lib and .so. */
    public function hasLibrary(string $name): bool
    {
        if (str_contains($this->run('ldconfig -p 2>/dev/null || /sbin/ldconfig -p 2>/dev/null'), "lib$name.so")) {
            return true;
        }
        foreach ($this->libraryFolders as $folder) {
            // A link that leads nowhere (the library was removed) is not a library
            foreach (glob("$folder/lib$name.so*") ?: [] as $file) {
                if (file_exists($file)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<int> the majors of the .NET runtimes installed, ascending */
    private function dotnetMajors(): array
    {
        preg_match_all('/Microsoft\.NETCore\.App (\d+)\./', $this->run('dotnet --list-runtimes 2>/dev/null'), $m);
        $majors = array_values(array_unique(array_map('intval', $m[1])));
        sort($majors);

        return $majors;
    }

    private function exists(string $command): bool
    {
        return trim($this->run('command -v ' . escapeshellarg($command) . ' 2>/dev/null')) !== '';
    }

    private function run(string $command): string
    {
        return $this->capture !== null ? ($this->capture)($command) : (string) @shell_exec($command);
    }
}
