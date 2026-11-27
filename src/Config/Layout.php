<?php

declare(strict_types=1);

namespace W4OS\Engine\Config;

use W4OS\Engine\Runtime\Core;

/**
 * Where an install keeps its files: the settings of `w4os.conf`, [Defaults] first and the profile it points to over them
 * (or the one asked for). The folders of the install (etc, var, logs, cache, data, cores), the user the instances run as,
 * the modules enabled for the regions.
 *
 *     $layout = Layout::load();
 *     $layout->etcRoot();           // /etc/opensim
 *     $layout->core();              // the core the instances run from, unless their grid says another
 *
 * Nothing is created, nothing is written: reading only.
 */
final class Layout
{
    /** The name of the config file of the suite: the one place it is written, a rename is this one change */
    public const FILE = 'w4os.conf';

    /** The folder of the system config of the suite */
    public const SYSTEM_DIR = '/etc/w4os';

    /** The name the config of the suite had before w4os, in the folder of the grids (EtcRoot) */
    public const LEGACY_FILE = 'opensim.conf';

    /**
     * @var list<string> where the config file is looked for, in this order ($HOME is the one of the caller); the config of a
     *      user from before w4os is read where it is, nothing moves it (the system one is moved by the package)
     */
    private const FILES = [
        '%HOME%/.config/w4os/' . self::FILE,
        '%HOME%/.' . self::FILE,
        self::SYSTEM_DIR . '/' . self::FILE,
        '%HOME%/.config/opensim/' . self::LEGACY_FILE,
        '%HOME%/.' . self::LEGACY_FILE,
    ];

    /** The config file of the suite on the system: /etc/w4os/w4os.conf */
    public static function systemFile(): string
    {
        return self::SYSTEM_DIR . '/' . self::FILE;
    }

    private ?Core $core = null;
    private bool $coreFound = false;

    /**
     * @param array<string,string> $values the settings, [Defaults] and the profile resolved
     */
    public function __construct(
        private readonly array $values = [],
        private readonly ?string $file = null,
        private readonly string $profile = '',
        private readonly array $grids = [],
    ) {}

    /** The prefix of the sections of the grids of an existing install that the suite runs where they are: `[Grid <nick>]`. */
    public const GRID_SECTION = 'Grid ';

    /**
     * The grids of an existing install the suite runs where they are, each its `[Grid <nick>]` section: where its files are
     * (RobustIni, CoreDirectory, Simulators...), and the settings of the helpers that have no place of their own in OpenSimulator.
     *
     * @return array<string,array<string,string>> nick => its settings
     */
    public function grids(): array
    {
        return $this->grids;
    }

    /**
     * The layout of the install: `w4os.conf` found in the usual places (or given), then the profile.
     *
     * @param ?string $file the config file, null to look for it
     * @param array<string,string> $defaults the values of an install by the book, for what the file does not say (EtcRoot, CoreRoot)
     * @param ?string $profile the profile, null for the default one
     */
    public static function load(?string $file = null, array $defaults = [], ?string $profile = null): self
    {
        $file ??= self::locate();
        $ini = $file !== null && is_file($file) ? ConfigReader::load($file) : [];
        $values = $ini['Defaults'] ?? [];
        $profile ??= $values['DefaultProfile'] ?? '';
        if ($profile !== '' && isset($ini[$profile])) {
            $values = $ini[$profile] + $values;
        }
        unset($values['DefaultProfile']);
        $grids = [];
        foreach ($ini as $section => $settings) {
            if (str_starts_with((string) $section, self::GRID_SECTION) && is_array($settings) && ($nick = trim(substr((string) $section, strlen(self::GRID_SECTION)))) !== '') {
                $grids[$nick] = array_map(static fn($value): string => (string) $value, $settings);
            }
        }

        return new self($values + $defaults, $file !== null && is_file($file) ? $file : null, $profile, $grids);
    }

    /**
     * The settings of w4os.conf an environment may give as `OPENSIM_<NAME_IN_CAPITALS>` (`OPENSIM_ETC_ROOT` for EtcRoot).
     */
    /** The account and group of the web server of a new install. */
    public const WEB_ACCOUNT = 'www-data';

    public const ENVIRONMENT_KEYS = [
        'CoreRoot',
        'CoreDirectory',
        'EtcRoot',
        'VarRoot',
        'LogsRoot',
        'CacheRoot',
        'TasksRoot',
        'DataRoot',
        'BackupRoot',
        'SystemUser',
        'WebUser',
        'WebGroup',
        'ModulesRoot',
        'EnabledModules',
    ];

    /**
     * The layout of a front-end: the OPENSIM_* variables of its environment, then w4os.conf, then the defaults of the system layout.
     *
     * @param ?array<string,mixed> $environment the variables of the environment, null for the ones of the process
     * @param bool $defaults false for the environment and the file alone: what neither says stays unsaid
     * @throws \RuntimeException when OPENSIM_CONF names a file that does not exist
     */
    public static function resolve(?array $environment = null, ?string $profile = null, bool $defaults = true): self
    {
        $environment ??= getenv();
        $file = (string) ($environment['OPENSIM_CONF'] ?? '');
        if ($file !== '' && !is_file($file)) {
            throw new \RuntimeException("OPENSIM_CONF names $file, which does not exist");
        }
        $layout = self::load($file !== '' ? $file : null, $defaults ? DirectoryLayout::roots() : [], $profile);
        $given = [];
        foreach (self::ENVIRONMENT_KEYS as $key) {
            $value = (string) ($environment[self::environmentName($key)] ?? '');
            if ($value !== '') {
                $given[$key] = $value;
            }
        }

        // A core root the environment gives is kept as it is given
        return new self($given + (isset($given['CoreRoot']) ? $layout->all() : self::coresFound($layout->all())), $layout->file(), $layout->profile(), $layout->grids());
    }

    /**
     * A core root that does not exist on this machine (a config written for another kind of install) gives way to the usual root
     * that has a core, the core it names with it: the settings are left as they are in their file.
     *
     * @param array<string,string> $values
     * @return array<string,string>
     */
    private static function coresFound(array $values): array
    {
        $root = rtrim((string) ($values['CoreRoot'] ?? ''), '/');
        if ($root === '' || is_dir($root)) {
            return $values;
        }
        foreach (['/usr/share/opensim', DirectoryLayout::roots()['CoreRoot']] as $usual) {
            $latest = $usual === $root ? null : \W4OS\Engine\Runtime\Core::latest($usual);
            if ($latest !== null) {
                $values['CoreRoot'] = $usual;
                $named = $usual . '/' . basename((string) ($values['CoreDirectory'] ?? ''));
                isset($values['CoreDirectory']) && ($values['CoreDirectory'] = \W4OS\Engine\Runtime\Core::at($named)->exists() ? $named : $latest->directory());

                return $values;
            }
        }

        return $values;
    }

    /** The name a setting of w4os.conf has in an environment: EtcRoot is OPENSIM_ETC_ROOT. */
    public static function environmentName(string $key): string
    {
        return 'OPENSIM_' . strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
    }

    /** The first `w4os.conf` of the usual places, null when there is none. */
    public static function locate(): ?string
    {
        $home = (string) getenv('HOME');
        foreach (self::FILES as $candidate) {
            $path = str_replace('%HOME%', $home, $candidate);
            if (($home !== '' || !str_contains($candidate, '%HOME%')) && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** The file the settings come from, null when there is none (the defaults only). */
    public function file(): ?string
    {
        return $this->file;
    }

    /** The profile in use, empty when the file has none. */
    public function profile(): string
    {
        return $this->profile;
    }

    /** @return array<string,string> every setting, the profile over [Defaults] */
    public function all(): array
    {
        return $this->values;
    }

    /** One setting, the given default when the install does not say it. */
    public function get(string $key, string $default = ''): string
    {
        return trim((string) ($this->values[$key] ?? $default));
    }

    public function etcRoot(): string
    {
        return $this->get('EtcRoot');
    }

    public function coreRoot(): string
    {
        return $this->get('CoreRoot');
    }

    /** The core the install points to (CoreDirectory), empty when it says none. */
    public function coreDirectory(): string
    {
        return $this->get('CoreDirectory');
    }

    /** The host the databases of the install are on (DataSource), empty when it says none. */
    public function dataSource(): string
    {
        return $this->get('DataSource');
    }

    public function varRoot(): string
    {
        return $this->get('VarRoot');
    }

    public function logsRoot(): string
    {
        return $this->get('LogsRoot');
    }

    public function cacheRoot(): string
    {
        return $this->get('CacheRoot');
    }

    public function dataRoot(): string
    {
        return $this->get('DataRoot');
    }

    /**
     * Where the backups of the administrator go, never inside the data: BackupRoot, else /var/backups/opensim for the system
     * layout, else a `backups` folder beside the data.
     */
    public function backupRoot(): string
    {
        if (($root = $this->get('BackupRoot')) !== '') {
            return rtrim($root, '/');
        }

        return $this->etcRoot() === '/etc/opensim'
            ? '/var/backups/opensim'
            : dirname(rtrim($this->dataRoot() !== '' ? $this->dataRoot() : $this->stateRoot(), '/')) . '/backups';
    }

    /** The folder for what the engine writes while running (state, caches): the var root, else the data one. */
    public function stateRoot(): string
    {
        return $this->varRoot() !== ''
            ? $this->varRoot()
            : ($this->dataRoot() !== ''
                ? $this->dataRoot()
                : sys_get_temp_dir());
    }

    /**
     * The folder of the state of the tasks (Scheduler: their last runs, their locks, the pseudo cron): `TasksRoot`, given by whoever
     * runs them when it cannot write the one of the install (a site run by the web server), else `cron` in the state folder.
     */
    public function tasksRoot(): string
    {
        $tasks = rtrim($this->get('TasksRoot'), '/');

        return $tasks !== '' ? $tasks : $this->stateRoot() . '/cron';
    }

    /** The user the instances run as, empty when the install has none (they run as whoever starts them). */
    public function systemUser(): string
    {
        return $this->get('SystemUser');
    }

    /**
     * The account the commands run as: SystemUser when this machine has it, else none (whoever calls them): a config may name
     * an account the machine never had.
     */
    public function runAs(): string
    {
        $user = $this->systemUser();

        return $user !== '' && function_exists('posix_getpwnam') && posix_getpwnam($user) === false ? '' : $user;
    }

    /** The account of the web server that serves the sites of the grids: `WebUser`, www-data when the install names none. */
    public function webUser(): string
    {
        return $this->get('WebUser') ?: self::WEB_ACCOUNT;
    }

    /** The group of the web server, that reads the settings of the grids: `WebGroup`, www-data when the install names none. */
    public function webGroup(): string
    {
        return $this->get('WebGroup') ?: self::WEB_ACCOUNT;
    }

    /** The resolved paths and Unix identities of this install. @return array<string,string|int> */
    public function systemInfo(): array
    {
        if (!function_exists('posix_geteuid')) {
            throw new \RuntimeException('The PHP POSIX extension is required to read system identities');
        }

        $currentUid = posix_geteuid();
        $currentGid = posix_getegid();
        $current = posix_getpwuid($currentUid);
        $currentGroup = posix_getgrgid($currentGid);
        $systemUser = $this->systemUser();
        $system = $systemUser !== '' ? posix_getpwnam($systemUser) : $current;
        if ($system === false) {
            throw new \RuntimeException("SystemUser '$systemUser' does not exist");
        }
        $systemGroup = posix_getgrgid((int) $system['gid']);

        return [
            'systemUser' => (string) ($system['name'] ?? ''),
            'systemUid' => (int) $system['uid'],
            'systemGroup' => (string) ($systemGroup['name'] ?? ''),
            'systemGid' => (int) $system['gid'],
            'currentUser' => (string) ($current['name'] ?? ''),
            'currentUid' => $currentUid,
            'currentGroup' => (string) ($currentGroup['name'] ?? ''),
            'currentGid' => $currentGid,
            'etcRoot' => $this->etcRoot(),
            'varRoot' => $this->varRoot(),
            'logsRoot' => $this->logsRoot(),
        ];
    }

    /** @return list<string> the modules enabled for the regions (EnabledModules, comma or space separated) */
    public function enabledModules(): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', $this->get('EnabledModules')) ?: []));
    }

    /** Where the modules of the install are, by core. */
    public function modulesRoot(): string
    {
        return $this->get('ModulesRoot', '/usr/share/opensim-modules');
    }

    /**
     * The core the instances run from when their grid chooses none: the CoreDirectory of the install when it holds a core, else
     * the latest one under the core root (the releases by version first, then the builds). Null when there is none.
     */
    public function core(): ?Core
    {
        if (!$this->coreFound) {
            $this->coreFound = true;
            $directory = $this->coreDirectory();
            $this->core =
                $directory !== '' && Core::at($directory)->exists()
                    ? Core::at($directory)
                    : Core::latest($this->coreRoot());
        }

        return $this->core;
    }
}
