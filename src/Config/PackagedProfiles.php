<?php

declare(strict_types=1);

namespace W4OS\Engine\Config;


/**
 * What the packages of the releases (opensim-<version>, in /usr/share/opensim) do to w4os.conf when they come and go: one
 * install profile per release, the first one the default, the profile of a release that was removed forgotten. The file is edited
 * in place, what the administrator wrote in it stays (comments, other settings).
 */
final class PackagedProfiles
{
    /** What a profile of a release takes from [Defaults]: the locations of the install, and who runs the instances. */
    private const INHERITED = ['DirectoryLayout', 'CoreRoot', 'EtcRoot', 'VarRoot', 'LogsRoot', 'CacheRoot', 'DataRoot', 'BackupRoot', 'SystemUser'];

    private readonly string $conf;

    /** @param ?string $conf the config file of the suite (default: the system one, Layout::systemFile()) */
    public function __construct(?string $conf = null, private readonly string $coresRoot = '/usr/share/opensim')
    {
        $this->conf = $conf ?? Layout::systemFile();
    }

    /**
     * Make the profiles follow the releases that are installed.
     *
     * @return list<string> what was changed, one line each
     */
    public function update(): array
    {
        if (!is_file($this->conf)) {
            return [];
        }
        $ini = IniEditor::load($this->conf);
        $before = $ini->toString();
        $notes = [];
        $all = ConfigReader::file($this->conf);
        $defaults = $all['Defaults'] ?? [];
        $cores = $this->cores();

        foreach ($cores as $version => $core) {
            $profile = "opensim-$version";
            if (isset($all[$profile])) {
                continue;
            }
            // Self-contained: the locations of [Defaults], and this release
            foreach (self::INHERITED as $key) {
                isset($defaults[$key]) && $ini->set($profile, $key, $defaults[$key]);
            }
            $ini->set($profile, 'OpensimVersion', $version);
            $ini->set($profile, 'CoreDirectory', $core);
            $ini->set($profile, 'Runtime', 'dotnet');
            $notes[] = "profile $profile added";
        }

        foreach ($all as $profile => $values) {
            $core = $values['CoreDirectory'] ?? '';
            if ($profile === 'Defaults' || !str_starts_with($core, rtrim($this->coresRoot, '/') . '/') || is_dir($core)) {
                continue;
            }
            $ini->removeSection($profile);
            if ($ini->get('Defaults', 'DefaultProfile') === $profile) {
                $ini->remove('Defaults', 'DefaultProfile');
            }
            $notes[] = "profile $profile removed, its release is gone";
        }

        if ($ini->get('Defaults', 'DefaultProfile') === null && $cores !== []) {
            // The latest release, or else another build (the unstable one for instance)
            $releases = array_filter(array_keys($cores), static fn(string $version): bool => preg_match('/^\d+(\.\d+)+$/', $version) === 1);
            $version = (string) (end($releases) ?: array_key_last($cores));
            $ini->set('Defaults', 'DefaultProfile', "opensim-$version");
            $ini->set('Defaults', 'OpensimVersion', $version);
            $ini->set('Defaults', 'CoreDirectory', $cores[$version]);
            $ini->set('Defaults', 'Runtime', 'dotnet');
            $notes[] = "default profile: opensim-$version";
        }

        $ini->toString() !== $before && $ini->save($this->conf);

        return $notes;
    }

    /**
     * Enable modules for the install the first time: when [Defaults] has no EnabledModules at all. An administrator's setting is
     * kept, an empty one too.
     *
     * @return bool whether it was set
     */
    public function enableModulesOnce(string $modules): bool
    {
        if (!is_file($this->conf)) {
            return false;
        }
        $ini = IniEditor::load($this->conf);
        if ($ini->get('Defaults', 'EnabledModules') !== null) {
            return false;
        }
        $ini->set('Defaults', 'EnabledModules', $modules);
        $ini->save($this->conf);

        return true;
    }

    /**
     * Enable a module for the install, or disable it: added to EnabledModules of [Defaults], or taken out of it, the others kept as
     * they are.
     *
     * @return bool whether the setting changed
     */
    public function enableModule(string $module, bool $on = true): bool
    {
        $module = strtolower(trim($module));
        if ($module === '' || !is_file($this->conf)) {
            return false;
        }
        $ini = IniEditor::load($this->conf);
        $now = array_values(array_filter(preg_split('/[\s,]+/', (string) $ini->get('Defaults', 'EnabledModules')) ?: []));
        $then = $on ? array_values(array_unique([...$now, $module])) : array_values(array_filter($now, static fn(string $m): bool => strcasecmp($m, $module) !== 0));
        if ($then === $now) {
            return false;
        }
        $ini->set('Defaults', 'EnabledModules', '"' . implode(' ', $then) . '"');
        $ini->save($this->conf);

        return true;
    }

    /**
     * The releases installed, in the order of sort -V (numbers first, then names), the lowest first: a folder of the root with the OpenSim assembly in its bin.
     *
     * @return array<string,string> version => folder
     */
    private function cores(): array
    {
        $cores = [];
        foreach (glob(rtrim($this->coresRoot, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            // OpenSim.exe in the releases, only OpenSim.dll in the builds made on Linux
            if (is_file("$dir/bin/OpenSim.exe") || is_file("$dir/bin/OpenSim.dll")) {
                $cores[basename($dir)] = $dir;
            }
        }
        uksort($cores, 'strnatcmp');

        return $cores;
    }
}
