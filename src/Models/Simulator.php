<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Data\Listing;
use OpenSim\Provisioning\Slug;
use OpenSim\Config\ConnectionString;
use OpenSim\Console\ConsoleInterface;
use OpenSim\Controllers\OpenSimController;
use OpenSim\Data\Collection;
use OpenSim\Runtime\Core;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\LogFile;
use OpenSim\Runtime\Process;
use OpenSim\Runtime\Outcome;

/**
 * A simulator: what runs one or several regions of a grid, with the config it is made of (its `<name>.ini`). Its identity, its
 * regions, its estate and its database are here; the program that runs it, started, stopped and talked to, is its controller
 * (OpenSimController), which it starts and stops for whoever asks.
 *
 * Its services are the ones of its grid, as its config says them (see Service).
 */
final class Simulator extends InstalledModel
{
    use HasName;

    /** The icon of each way to it in the list (Listing::ICONS). */
    private const ICONS = ['console' => 'console', 'screen' => 'screen', 'config' => 'file', 'regions' => 'files', 'mysql' => 'database', 'sqlite' => 'database'];

    /** The rule of the name of a simulator: letters, digits, space and hyphen, no dot. */
    private const NAME = ['punctuation' => '-'];

    /** The ways the engine reaches a simulator, in the order a site shows them (connections()). */
    public const CONNECTIONS = ['console', 'screen', 'config', 'regions', 'mysql', 'sqlite'];

    /** The states of a way: none (the engine has no such way to it), configured (named, not tried), ok, failed (tried, not reached). */
    public const CONNECTION_STATES = ['none', 'configured', 'ok', 'failed'];

    private readonly OpenSimController $controller;

    /** @var ?array{uri:string,grid:Grid,online:bool} a simulator of another machine, known through the regions of its grid */
    private ?array $elsewhere = null;

    /** Whether connections() tries each way rather than saying it is configured (probe()). */
    private bool $probed = false;

    /** @var ?list<int> the ports bound on the machine, read once for a list (all()); null: not read */
    private ?array $bound = null;

    /** Its process runs and answers. */
    public const RUNNING = 'running';

    /** It does not run. */
    public const STOPPED = 'stopped';

    /** Its process runs and does not answer: its HTTP port not bound (a list), or no answer at /simstatus/ (alone). */
    public const UNRESPONSIVE = 'unresponsive';

    /** Its screen session is there without its process: the program ended in it. */
    public const CRASHED = 'crashed';

    public function __construct(Environment $environment, string $ini, ?string $name = null)
    {
        parent::__construct($environment);
        $this->controller = new OpenSimController($environment, $ini, $name);
    }

    /**
     * A simulator of another machine that runs regions of a grid of this one (registered with its Robust): known by its address
     * only, the engine has no way to it.
     */
    public static function elsewhere(Environment $environment, Grid $grid, string $uri, bool $online): self
    {
        $simulator = new self($environment, '', $uri);
        $simulator->elsewhere = ['uri' => $uri, 'grid' => $grid, 'online' => $online];

        return $simulator;
    }

    /** Whether it runs on another machine (elsewhere()). */
    public function isElsewhere(): bool
    {
        return $this->elsewhere !== null;
    }

    /** Try each way to it when it is asked alone (connections()): a list only says what is configured, trying is slow. */
    public function probe(): void
    {
        $this->probed = true;
    }

    /**
     * The ways the engine reaches it (CONNECTIONS), each in a state of CONNECTION_STATES: `console` its REST console, `screen` the
     * screen session it runs in, `config` its ini, `regions` the files of its regions, `mysql` or `sqlite` its database (the config
     * of the Robust is the grid's). Configured unless probe() asked to try them.
     *
     * @return array<string,string>
     */
    public function connections(): array
    {
        $states = array_fill_keys(self::CONNECTIONS, 'none');
        if ($this->elsewhere !== null) {
            return $states;
        }
        $state = fn(bool $configured, \Closure $reached): string => !$configured
            ? 'none'
            : (!$this->probed ? 'configured' : ($reached() ? 'ok' : 'failed'));
        $console = $this->remoteConsole();
        $regions = $this->regionsDirectory();
        $database = $this->database();
        $sqlite = $database?->driver === 'sqlite';

        // What costs nothing is checked in a list too: its files, the ports bound; a console or a screen is only there while it runs
        $running = $this->process() !== null;
        $host = (string) ($this->consoleHost() ?: '127.0.0.1');
        $checked = fn(bool $configured, \Closure $reached): string => !$configured ? 'none' : ($reached() ? 'ok' : 'failed');

        return [
            'console' => match (true) {
                $console === null => 'none',
                !$running => 'configured',
                $this->probed => $state(true, fn(): bool => self::listens($host, (int) $console['port'])),
                $this->bound !== null => in_array((int) $console['port'], $this->bound, true) ? 'ok' : 'failed',
                default => 'configured',
            },
            'screen' => $console !== null ? 'none' : ($running ? 'ok' : ($this->environment->consoles->sessionExists($this->name()) ? 'failed' : 'configured')),
            'config' => $checked(is_file($this->ini()), fn(): bool => is_readable($this->ini())),
            'regions' => $checked($regions !== '' && is_dir($regions), fn(): bool => is_readable($regions) && $this->hasRegion()),
            'mysql' => $state($database !== null && !$sqlite, fn(): bool => self::reaches($database)),
            // A file: read, never made by trying it
            'sqlite' => $checked($sqlite, fn(): bool => is_readable((string) $database?->database)),
        ];
    }

    /**
     * Whether it runs and answers (RUNNING, STOPPED, UNRESPONSIVE, CRASHED), from what costs nothing in a list (its process, its
     * screen session, the ports bound on the machine), its /simstatus/ asked when it is alone (probe()). A simulator of another
     * machine runs while one of its regions is online.
     */
    public function state(): string
    {
        if ($this->elsewhere !== null) {
            return $this->elsewhere['online'] ? self::RUNNING : self::STOPPED;
        }
        $process = $this->process();
        if ($process === null) {
            return $this->environment->consoles->sessionExists($this->name()) ? self::CRASHED : self::STOPPED;
        }
        $port = (int) $this->httpPort();
        $answers = match (true) {
            $port <= 0 => true,
            $this->probed => trim((string) ($this->environment->http ?? self::fetch(...))("http://127.0.0.1:$port/simstatus/")) === 'OK',
            $this->bound !== null => in_array($port, $this->bound, true),
            default => true,
        };

        return $answers ? self::RUNNING : self::UNRESPONSIVE;
    }

    /** The body of a local address, null when it does not answer within two seconds. */
    private static function fetch(string $url): ?string
    {
        $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        set_error_handler(static fn(): bool => true);
        try {
            $body = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        return is_string($body) ? $body : null;
    }

    /** Whether something listens at an address (a TCP connection opens within a second). */
    private static function listens(string $host, int $port): bool
    {
        // A refused connection is an answer, not an error: what the stream would warn is kept out
        set_error_handler(static fn(): bool => true);
        try {
            $socket = $port > 0 ? fsockopen($host, $port, $code, $message, 1.0) : false;
        } finally {
            restore_error_handler();
        }
        $socket === false || fclose($socket);

        return $socket !== false;
    }

    /** Whether a MySQL database answers with the credentials of a connection string. */
    private static function reaches(?\OpenSim\Config\ConnectionString $connection): bool
    {
        if ($connection === null) {
            return false;
        }
        $pdo = new \OpenSim\Data\OSPDO(['host' => $connection->source, 'name' => $connection->database, 'user' => $connection->user, 'pass' => $connection->password, 'log' => false]);

        return $pdo->connected;
    }

    /** What runs it. */
    public function controller(): OpenSimController
    {
        return $this->controller;
    }

    /**
     * The fields of a simulator, in the standard format of the forms (see Forms\FieldSet): what the setup asks to make or to change
     * one, and what any other form about a simulator shows. The groups are the screens of the setup.
     *
     * @param array{cores?:array<string,string>,modify?:bool,accounts?:?list<string>} $options the cores to run it (folder => name; with
     *        one there is no question), whether the simulator exists (its name stays), and the accounts of its grid that can own its
     *        estate (null: they are not known here, the owner is typed and has to exist; none: the owner is the first to create)
     * @return list<array<string,mixed>>
     */
    public static function fields(array $options = []): array
    {
        $cores = $options['cores'] ?? [];
        $accounts = $options['accounts'] ?? null;
        $fresh = static fn(array $field): array => $field + ['required' => true, 'type' => 'string'];
        // The simulator of a standalone is the grid: it has its port and its database, which are not asked again
        $own = ($options['standalone'] ?? false) ? ['enable' => false] : [];

        $fields = [
            $fresh([
                'name' => 'name',
                'group' => 'simulator',
                'label' => _('Simulator name'),
                'callback' => [self::class, 'validateName'],
                'normalize' => [self::class, 'normalizeName'],
                'enable' => !($options['modify'] ?? false),
            ]),
            [
                'name' => 'core',
                'group' => 'simulator',
                'type' => 'select',
                'label' => _('OpenSim core to run this simulator'),
                'options' => $cores,
                'enable' => count($cores) > 1,
            ],
            $fresh([
                'name' => 'http_port',
                'group' => 'simulator',
                'type' => 'integer',
                'label' => _('Simulator HTTP port'),
                'callback' => [Grid::class, 'validatePort'],
            ] + $own),
            $fresh([
                'name' => 'external_host',
                'group' => 'simulator',
                'label' => _('Public address of this machine'),
                'description' => _('What the regions announce to the viewers (SYSTEMIP: the first interface)'),
            ]),
            [
                'name' => 'console',
                'group' => 'simulator',
                'type' => 'select',
                'label' => _('Console of the simulator'),
                'options' => [
                    'rest' => _('Remote REST console (recommended)'),
                    'screen' => _('Screen session (on this machine)'),
                ],
            ],
            $fresh([
                'name' => 'console_port',
                'follows' => ['http_port'],
                'group' => 'simulator',
                'type' => 'integer',
                'label' => _('Console port'),
                'callback' => [Grid::class, 'validatePort'],
                'condition' => ['console' => 'rest'],
            ]),
            [
                'name' => 'database_driver',
                'group' => 'database',
                'type' => 'select',
                'label' => _('Simulator database driver'),
                'options' => ['mysql' => _('MySQL or MariaDB'), 'sqlite' => _('SQLite')],
                'required' => true,
                'enable' => !($options['standalone'] ?? false),
            ],
            $fresh(['name' => 'database_host', 'group' => 'database', 'label' => _('Database host'), 'condition' => ['database_driver' => 'mysql']] + $own),
            $fresh(['name' => 'database_name', 'group' => 'database', 'label' => _('Database name'), 'condition' => ['database_driver' => 'mysql']] + $own),
            $fresh(['name' => 'database_user', 'group' => 'database', 'label' => _('Database user'), 'condition' => ['database_driver' => 'mysql']] + $own),
            $fresh(['name' => 'database_password', 'group' => 'database', 'label' => _('Database password'), 'condition' => ['database_driver' => 'mysql']] + $own),
            $fresh(['name' => 'estate_name', 'group' => 'estate', 'label' => _('Estate name')]),
        ];

        // The owner of the estate is an account of the grid, which OpenSimulator cannot make: it is chosen among the grid's, typed
        // when they are not known here, or made first
        if ($accounts === null) {
            $fields[] = $fresh([
                'name' => 'estate_owner',
                'group' => 'estate',
                'label' => _('Estate owner (an account of the grid)'),
                'callback' => [Avatar::class, 'validateName'],
            ]);
        } elseif ($accounts !== []) {
            $fields[] = [
                'name' => 'estate_owner',
                'group' => 'estate',
                'type' => 'select',
                'label' => _('Estate owner'),
                'options' => array_combine($accounts, $accounts) + ['+' => _('Create a new account in the grid')],
            ];
        }
        $new =
            $accounts === null
                ? ['enable' => false]
                : ($accounts === []
                    ? []
                    : ['condition' => ['estate_owner' => '+']]);
        $fields[] = $fresh(
            [
                'name' => 'new_name',
                'group' => 'estate',
                'label' => _('Name of the new account (First Last)'),
                'callback' => [Avatar::class, 'validateName'],
                'normalize' => [Avatar::class, 'normalizeName'],
            ] + $new,
        );
        $fields[] = $fresh(
            [
                'name' => 'new_password',
                'group' => 'estate',
                'type' => 'secret',
                'label' => _('Password of the new account'),
                'callback' => [Avatar::class, 'validatePassword'],
            ] + $new,
        );
        $fields[] =
            [
                // OpenSimulator does not need one, no more than the setup
                'name' => 'new_email',
                'group' => 'estate',
                'type' => 'string',
                'required' => false,
                'label' => _('Email of the new account (optional)'),
                'callback' => [Avatar::class, 'validateEmail'],
            ] + $new;

        return $fields;
    }

    /**
     * Where it is reached, `host:port`: what identifies it. The port is the one it listens on, the host the one its regions announce
     * (this machine's own when they say SYSTEMIP or when it has no region yet). Null when its config does not say its port.
     */
    public function uri(): ?string
    {
        if ($this->elsewhere !== null) {
            return $this->elsewhere['uri'];
        }
        $port = $this->httpPort();
        if ($port === null) {
            return null;
        }
        $host = '';
        foreach ($this->regionsDirectory() !== '' ? Region::inDirectory($this->regionsDirectory()) : [] as $region) {
            $host = (string) $region->externalHost();
            if ($host !== '') {
                break;
            }
        }
        if ($host === '' || strcasecmp($host, 'SYSTEMIP') === 0) {
            $host = (string) gethostname();
        }

        return $host !== '' ? "$host:$port" : null;
    }

    /**
     * The simulators of the machine, those of every grid.
     *
     * Options: `search` (words of the slug, the address or the grid), `sort` ("name", "port", "grid"), `filters` (`grid`: a nick;
     * `enabled`, `running`: yes or no), `page` and `perPage`.
     */
    public static function all(Environment $environment, array $options = []): Collection
    {
        $simulators = [];
        // The ports bound, read once for the list: whether each simulator answers, and its console
        $bound = $environment->occupiedPorts ?? $environment->processes->listening();
        foreach (Grid::all($environment)->items() as $grid) {
            $here = $grid->simulators();
            foreach ($here as $simulator) {
                $simulator->bound = $bound;
            }
            array_push($simulators, ...$here, ...self::ofOthers($environment, $grid, $here));
        }
        $yesNo = static fn(\Closure $is): \Closure => static fn(self $simulator, string $value): bool => $is($simulator) ===
            ($value === 'yes');

        return self::paged(
            $simulators,
            $options,
            static fn(
                self $simulator,
            ): string => "{$simulator->slug()} {$simulator->uri()} {$simulator->grid()?->name()}",
            [
                'name' => static fn(self $simulator): string => $simulator->slug() ?: (string) $simulator->uri(),
                'port' => static fn(self $simulator): int => (int) $simulator->httpPort(),
                'grid' => static fn(self $simulator): string => (string) $simulator->grid()?->nick(),
            ],
            [
                'grid' => static fn(self $simulator, string $nick): bool => $simulator->grid()?->nick() === $nick,
                'enabled' => $yesNo(static fn(self $simulator): bool => $simulator->isEnabled()),
                'running' => $yesNo(static fn(self $simulator): bool => $simulator->isRunning()),
            ],
            self::listing(),
        );
    }

    /**
     * The simulators of other machines that run regions of a grid, the ones of this machine left out; none when the database of its
     * regions cannot be read.
     *
     * @param list<self> $here
     * @return list<self>
     */
    private static function ofOthers(Environment $environment, Grid $grid, array $here): array
    {
        $directory = $grid->directory();
        if ($directory === '' || !is_dir($directory)) {
            return [];
        }
        $local = array_map('strtolower', array_filter(array_map(static fn(self $simulator): ?string => $simulator->uri(), $here)));
        try {
            $servers = Region::elsewhere(\OpenSim\Config\GridConfig::fromFolder($directory, ['Helpers' => 'Helpers.ini']));
        } catch (\OpenSim\Exceptions\ChannelUnavailable) {
            return [];
        }

        return array_values(array_map(
            static fn(array $server): self => self::elsewhere($environment, $grid, $server['id'], $server['online']),
            array_filter($servers, static fn(array $server): bool => !in_array(strtolower($server['id']), $local, true)),
        ));
    }

    /** What the list of the simulators shows (Listing). */
    public static function listing(): Listing
    {
        [$method, $path] = ['PATCH', '/simulators/{id}'];

        return new Listing(
            [
                Listing::column('name', 'column_name', sort: 'name'),
                Listing::column('slug', 'column_slug'),
                Listing::column('grid', 'column_grid', sort: 'grid'),
                Listing::column('uri', 'column_uri'),
                Listing::column('httpPort', 'column_port', 'number', 'port'),
                // Enabled and running, each the button that changes it, then the ways the engine reaches it
                Listing::column('status', 'column_status', 'indicators', more: ['indicators' => [
                    Listing::indicator('enabled', 'power', 'column_enabled', [[true, 'state_enabled', 'ok'], [false, 'state_disabled', 'off']], ['enable', 'disable']),
                    // Play while it runs, pause while it does not, red when it should answer and does not
                    Listing::indicator('state', 'play', 'column_running', [
                        [self::RUNNING, 'state_running', 'ok', 'play'],
                        [self::STOPPED, 'state_stopped', 'off', 'pause'],
                        [self::UNRESPONSIVE, 'state_unresponsive', 'failed', 'play'],
                        [self::CRASHED, 'state_crashed', 'failed', 'pause'],
                    ], ['start', 'stop']),
                    ...array_map(
                        // Each state says it all: the thing alone while it is there (configured, or tried and reached), with what is wrong
                        // when it was tried and not reached; none shows no icon
                        static fn(string $way): array => Listing::indicator("connections.$way", self::ICONS[$way], "connection_$way", [
                            ['configured', "connection_$way", 'off'],
                            ['ok', "connection_$way", 'ok'],
                            ['failed', "connection_{$way}_failed", 'failed'],
                        ]),
                        self::CONNECTIONS,
                    ),
                ]]),
            ],
            [
                Listing::filter('grid', 'column_grid', [], 'grid'),
                Listing::filter('enabled', 'column_enabled'),
                Listing::filter('running', 'column_running'),
            ],
            ['slug', 'uri', 'grid'],
            [
                Listing::action('enable', 'action_enable', $method, $path, ['enabled' => true], ['enabled' => [false]]),
                Listing::action('disable', 'action_disable', $method, $path, ['enabled' => false], ['enabled' => [true]]),
                // At its instance (its slug, the name opensim start takes); stopping sends the avatars of its regions away: asked first
                Listing::action('start', 'action_start', 'POST', '/instances/{slug}/start', when: ['running' => [false], 'enabled' => [true]], requires: ['slug']),
                Listing::action('stop', 'action_stop', 'POST', '/instances/{slug}/stop', when: ['running' => [true]], requires: ['slug'], more: ['ask' => 'ask_stop_instance']),
                Listing::action('restart', 'action_restart', 'POST', '/instances/{slug}/restart', when: ['running' => [true]], requires: ['slug'], more: ['ask' => 'ask_restart_instance']),
            ],
        );
    }

    /** A simulator by its address (`host:port`) or its slug (`vonda.welcome`). */
    public static function find(Environment $environment, string $id): ?static
    {
        foreach (self::all($environment)->items() as $simulator) {
            if (strcasecmp((string) $simulator->uri(), $id) === 0 || strcasecmp($simulator->slug(), $id) === 0) {
                return $simulator;
            }
        }

        return null;
    }

    /** What identifies it: its address, else its name. */
    public function id(): string
    {
        return $this->uri() ?? $this->name();
    }

    /**
     * Every fact about it, the password of its database and of its console left out.
     *
     * @return array<string,mixed>
     */
    public function detail(): array
    {
        if ($this->elsewhere !== null) {
            return [
                'id' => $this->id(),
                'uri' => $this->uri(),
                'name' => $this->displayName(),
                'slug' => null,
                'grid' => $this->grid()?->nick(),
                'ini' => null,
                'link' => null,
                'enabled' => null,
                'running' => $this->isRunning(),
                'state' => $this->state(),
                'local' => false,
                'connections' => $this->connections(),
                'httpPort' => $this->httpPort(),
            ] + array_fill_keys(['consolePort', 'console', 'databaseHost', 'databaseName', 'databaseUser', 'estateDatabaseName', 'estateName', 'estateOwner', 'regionsDirectory', 'core'], null);
        }

        return [
            'id' => $this->id(),
            'uri' => $this->uri(),
            'name' => $this->displayName(),
            'slug' => $this->slug(),
            'grid' => $this->grid()?->nick(),
            'ini' => $this->ini(),
            'link' => $this->link(),
            'enabled' => $this->isEnabled(),
            'running' => $this->isRunning(),
            'state' => $this->state(),
            'local' => true,
            'connections' => $this->connections(),
            'httpPort' => $this->httpPort(),
            'consolePort' => $this->consolePort(),
            'console' => $this->remoteConsole() !== null ? 'rest' : 'screen',
            'databaseHost' => $this->databaseHost(),
            'databaseName' => $this->databaseName(),
            'databaseUser' => $this->databaseUser(),
            'estateDatabaseName' => $this->estateDatabase()?->database,
            'estateName' => $this->estateName(),
            'estateOwner' => $this->estateOwner(),
            'regionsDirectory' => $this->regionsDirectory() ?: null,
            'core' => $this->core()?->directory(),
        ];
    }

    public function name(): string
    {
        return $this->controller->name();
    }

    /** The name to show: the one given to it, without its grid ("Test North" for friendly_fowl.test_north). */
    public function displayName(): string
    {
        if ($this->elsewhere !== null) {
            return $this->elsewhere['uri'];
        }

        return self::displayNameOf($this->slug(), $this->grid()?->nick());
    }

    /** The name to show of the instance of a simulator (grid.simulator): its own part, its words in capitals. */
    public static function displayNameOf(string $instance, ?string $nick = null): string
    {
        $own = $nick !== null && $nick !== ''
            ? Slug::simulatorOf($instance, $nick)
            : (str_contains($instance, Slug::SEPARATOR) ? substr($instance, strpos($instance, Slug::SEPARATOR) + 1) : $instance);

        return Slug::nameOf($own);
    }

    /** The name for a path or a command: its own, which has its grid in it (`vonda.welcome`, not `welcome`: every grid has one). */
    public function slug(): string
    {
        return $this->elsewhere !== null ? '' : $this->controller->name();
    }

    /** The config file it runs with: its link when it is enabled. */
    public function ini(): string
    {
        return $this->controller->ini();
    }

    public function link(): string
    {
        return $this->controller->link();
    }

    /** Whether its config is there. */
    public function exists(): bool
    {
        return $this->controller->exists();
    }

    public function isEnabled(): bool
    {
        return $this->elsewhere === null && $this->controller->isEnabled();
    }

    public function enable(): bool
    {
        return $this->here('enable')->enable();
    }

    public function disable(): void
    {
        $this->here('disable')->disable();
    }

    public function channels(): array
    {
        return $this->elsewhere === null && $this->controller->canBeEnabled() ? ['ini'] : [];
    }

    public function start(): Outcome
    {
        return $this->here('start')->start();
    }

    public function stop(bool $now = false): Outcome
    {
        return $this->here('stop')->stop($now);
    }

    public function restart(bool $now = false): Outcome
    {
        return $this->here('restart')->restart($now);
    }

    /** A simulator of another machine is running while one of its regions is online. */
    public function isRunning(): bool
    {
        return $this->elsewhere !== null ? $this->elsewhere['online'] : $this->controller->isRunning();
    }

    /** Its controller, for what only a simulator of this machine does. */
    private function here(string $what): OpenSimController
    {
        $this->elsewhere === null || throw \OpenSim\Exceptions\ChannelUnavailable::for("$what the simulator {$this->elsewhere['uri']}, which runs on another machine");

        return $this->controller;
    }

    public function process(): ?Process
    {
        return $this->controller->process();
    }

    public function console(): ConsoleInterface
    {
        return $this->controller->console();
    }

    public function logFile(): LogFile
    {
        return $this->controller->logFile();
    }

    /** The ports it listens on. */
    public function ports(): array
    {
        return $this->controller->ports();
    }

    /** The core it runs from. */
    public function core(): ?Core
    {
        return $this->controller->core();
    }

    /** @return ?array{port:int,user:string,password:string} its remote console, null when it runs in a screen session */
    public function remoteConsole(): ?array
    {
        return $this->controller->remoteConsole();
    }

    /** One setting of its config, as written, empty when it does not say it. */
    public function setting(string $section, string $key): string
    {
        return $this->controller->setting($section, $key);
    }

    /**
     * The settings of its config, the files it includes read the way OpenSimulator reads them.
     *
     * @return array<string,array<string,string>>
     */
    public function settings(): array
    {
        return $this->controller->settings();
    }

    public function launchSetting(string ...$names): string
    {
        return $this->controller->launchSetting(...$names);
    }

    /** The grid it belongs to, null for a config that is not in the folder of one. */
    public function grid(): ?Grid
    {
        return $this->elsewhere !== null ? $this->elsewhere['grid'] : $this->controller->grid();
    }

    /**
     * The services the simulator uses, as it reaches them: its grid's, each at the address its own config says when it says one
     * (see Service::of() for the options).
     */
    public function services(array $options = []): Collection
    {
        return Service::of($this->grid(), $this, $options);
    }

    /** A service as the simulator reaches it (`$simulator->service('profile')->uri()`), null when it has none. */
    public function service(string $key): ?Service
    {
        $grid = $this->grid();
        if ($grid === null || !in_array($key, Service::keys(), true)) {
            return null;
        }
        $service = new Service($this->environment, $grid, $key, $this);

        return $service->exists() ? $service : null;
    }

    public function gridDirectory(): ?string
    {
        return $this->controller->gridDirectory();
    }

    /** The master of its grid, made again when the core or the grid changed. */
    public function master(string $binDir): ?string
    {
        return $this->controller->master($binDir);
    }

    /** A database it connects to, as OpenSimulator chooses it from its config (see Controller::connection()). */
    public function connection(\OpenSim\Data\Database|string $which): ?ConnectionString
    {
        return $this->controller->connection($which);
    }

    /** The database of its regions: [SimulationDataStore], else [DatabaseService]. */
    public function database(): ?ConnectionString
    {
        return $this->controller->database();
    }

    /** The database of its estates: [EstateDataStore], else EstateConnectionString of [DatabaseService], else the database of its regions. */
    public function estateDatabase(): ?ConnectionString
    {
        return $this->controller->estateDatabase();
    }

    public function databaseHost(): ?string
    {
        return $this->controller->databaseHost();
    }

    public function databaseName(): ?string
    {
        return $this->controller->databaseName();
    }

    public function databaseUser(): ?string
    {
        return $this->controller->databaseUser();
    }

    public function databasePassword(): ?string
    {
        return $this->controller->databasePassword();
    }

    public function consolePort(): ?int
    {
        return $this->controller->consolePort();
    }

    public function consoleHost(): ?string
    {
        return $this->controller->consoleHost();
    }

    public function consoleUser(): ?string
    {
        return $this->controller->consoleUser();
    }

    public function consolePassword(): ?string
    {
        return $this->controller->consolePassword();
    }

    /** The port it listens on, null when its config does not say it. */
    public function httpPort(): ?int
    {
        if ($this->elsewhere !== null) {
            return (int) substr($this->elsewhere['uri'], strrpos($this->elsewhere['uri'], ':') + 1);
        }

        return $this->controller->httpPort();
    }

    public function estateName(): ?string
    {
        return $this->controller->estateName();
    }

    public function estateOwner(): ?string
    {
        return $this->controller->estateOwner();
    }

    /** The folder of the descriptions of its regions, empty when its config does not say where they are. */
    public function regionsDirectory(): string
    {
        return $this->controller->regionsDirectory();
    }

    /** Whether it has a region to load: a region file in its regions folder. One without waits for the answers of a region to create. */
    public function hasRegion(): bool
    {
        return $this->controller->hasRegion();
    }
}
