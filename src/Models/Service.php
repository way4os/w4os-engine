<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Config\Settings;
use OpenSim\Data\Listing;
use OpenSim\Config\GridConfig;
use OpenSim\Controllers\AbstractController;
use OpenSim\Controllers\RobustController;
use OpenSim\Data\Collection;
use OpenSim\Data\Database;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Provisioning\Slug;
use OpenSim\Runtime\Environment;

/**
 * A service of a grid: what its simulators and its users ask for the assets, the inventories, the profiles, the logins. A service
 * belongs to its grid. It is served by a Robust of the grid (a grid has one or several, each serving one or several services), or
 * by something the grid only declares: the address a simulator is given for it (`AssetServerURI`, `ProfileServiceURL`) is the one of
 * a server somewhere else.
 *
 *     $grid->services();                      // the services the grid has, served here or declared
 *     $grid->service('asset')->provider();    // the Robust that serves the assets
 *     $simulator->service('profile')->url();  // the profile service as this simulator reaches it
 *
 * A simulator inherits the services of its grid and answers for them: asked for a service, it gives the address its own config says
 * (a simulator may use a service that is not its grid's), else the one of its grid.
 */
final class Service extends InstalledModel
{
    /** Reached by the simulators of the grid, on its private port. */
    public const PRIVATE = 'private';

    /** Reached by the viewers and by the other grids, on its public port. */
    public const PUBLIC = 'public';

    /** A web service: an address on a web site, not a port of Robust. */
    public const WEB = 'web';

    /** The format of its requests and answers is fixed by OpenSimulator or by the viewers: the address is the grid's to choose. */
    public const FIXED = 'fixed';

    /** The format is the engine's own (see Contract): the old scripts that answered in their own are adapted. */
    public const STANDARD = 'standard';

    /** A page for people (the web search, the status of the grid), no format to keep. */
    public const PAGE = 'page';

    /**
     * The services OpenSimulator has, by key: the connectors of `[ServiceList]` that serve it, the port they are on, what serves it.
     * The settings that give its address, their labels, the key of get_grid_info that tells it are the registry's (Settings).
     */
    private const CATALOG = [
        'asset' => [
            'connectors' => ['AssetServiceConnector'],
            'port' => self::PRIVATE,
            'database' => Database::Assets,
        ],
        'inventory' => [
            'connectors' => ['InventoryInConnector'],
            'port' => self::PRIVATE,
            'database' => Database::Robust,
        ],
        'grid' => [
            'connectors' => ['GridServiceConnector'],
            'port' => self::PRIVATE,
            'database' => Database::Robust,
        ],
        'estate' => [
            'connectors' => ['EstateDataService'],
            'port' => self::PRIVATE,
            'database' => Database::Estate,
        ],
        'authentication' => [
            'connectors' => ['AuthenticationServiceConnector'],
            'port' => self::PRIVATE,
            'database' => Database::Robust,
        ],
        'avatar' => [
            'connectors' => ['AvatarServiceConnector'],
            'port' => self::PRIVATE,
            'database' => Database::Robust,
        ],
        'agent-preferences' => [
            'connectors' => ['AgentPreferencesServiceConnector'],
            'port' => self::PRIVATE,
            'database' => Database::Robust,
        ],
        'presence' => [
            'connectors' => ['PresenceServiceConnector'],
            'port' => self::PRIVATE,
            'database' => Database::Robust,
        ],
        'user-account' => [
            'connectors' => ['UserAccountServiceConnector'],
            'port' => self::PRIVATE,
            'database' => Database::Robust,
        ],
        'grid-user' => [
            'connectors' => ['GridUserServiceConnector'],
            'port' => self::PRIVATE,
            'database' => Database::Robust,
        ],
        'friends' => [
            'connectors' => ['FriendsServiceConnector'],
            'port' => self::PRIVATE,
            'database' => Database::Robust,
        ],
        'map-image' => [
            'connectors' => ['MapAddServiceConnector', 'MapGetServiceConnector'],
            'port' => self::PRIVATE,
            'database' => null,
        ],
        'mute-list' => [
            'connectors' => ['MuteListConnector'],
            'port' => self::PRIVATE,
            'database' => Database::Robust,
        ],
        'bakes' => [
            'connectors' => ['BakedTextureService'],
            'port' => self::PRIVATE,
            'database' => Database::Robust,
        ],
        'groups' => [
            'connectors' => ['GroupsServiceConnector'],
            'port' => self::PRIVATE,
            'database' => Database::Robust,
        ],
        'offline' => [
            'connectors' => ['OfflineIMServiceConnector'],
            'port' => self::PRIVATE,
            'database' => Database::Offline,
            'kind' => self::FIXED,
            'legacy' => 'offline',
            'endpoint' => 'POST /offline-messages/{method}/',
        ],
        'profile' => [
            'connectors' => ['UserProfilesServiceConnector'],
            'port' => self::PUBLIC,
            'database' => Database::Profiles,
        ],
        'login' => [
            'connectors' => ['LLLoginServiceInConnector'],
            'port' => self::PUBLIC,
            'database' => null,
        ],
        // The /get_grid_info of OpenSimulator (Robust answers it): the engine reads it, it is no endpoint of the API
        'get-grid-info' => [
            'connectors' => ['GridInfoServerInConnector'],
            'port' => self::PUBLIC,
            'database' => null,
        ],
        'gatekeeper' => [
            'connectors' => ['GatekeeperServiceInConnector'],
            'port' => self::PUBLIC,
            'database' => null,
        ],
        'user-agent' => [
            'connectors' => ['UserAgentServerConnector'],
            'port' => self::PUBLIC,
            'database' => null,
        ],
        'hg-friends' => [
            'connectors' => ['HGFriendsServerConnector'],
            'port' => self::PUBLIC,
            'database' => null,
        ],
        'hg-asset' => [
            'connectors' => ['HGAssetServiceConnector'],
            'port' => self::PUBLIC,
            'database' => null,
        ],
        'hg-inventory' => [
            'connectors' => ['HGInventoryServiceConnector'],
            'port' => self::PUBLIC,
            'database' => null,
        ],
        'hg-groups' => [
            'connectors' => ['HGGroupsServiceConnector'],
            'port' => self::PUBLIC,
            'database' => null,
        ],
        'map' => [
            'connectors' => ['MapGetServiceConnector'],
            'port' => self::PUBLIC,
            'database' => null,
            'kind' => self::FIXED,
        ],
        'query' => [
            'connectors' => [],
            'port' => self::WEB,
            'database' => Database::Search,
            'default' => 'query',
            'kind' => self::FIXED,
            'legacy' => 'query',
            'endpoint' => 'POST /search/query',
        ],
        'search' => [
            'connectors' => [],
            'port' => self::WEB,
            'default' => 'search',
            'database' => null,
            'kind' => self::PAGE,
        ],
        'welcome' => [
            'connectors' => [],
            'port' => self::WEB,
            'default' => 'welcome',
            'database' => null,
            'kind' => self::PAGE,
        ],
        'web-profile' => [
            'connectors' => [],
            'port' => self::WEB,
            'database' => null,
            'kind' => self::PAGE,
        ],
        'currency' => [
            'connectors' => [],
            'port' => self::WEB,
            'append' => 'currency.php',
            'default' => 'currency',
            'database' => Database::Economy,
            'kind' => self::FIXED,
            'legacy' => 'currency',
            'endpoint' => 'POST /currency.php',
        ],
        'landtool' => [
            'connectors' => [],
            'port' => self::WEB,
            'append' => 'landtool.php',
            'default' => 'landtool',
            'database' => null,
            'kind' => self::FIXED,
            'legacy' => 'landtool',
            'endpoint' => 'POST /landtool.php',
        ],
        'register' => [
            'connectors' => [],
            'port' => self::WEB,
            'default' => 'register',
            'database' => Database::Search,
            'kind' => self::FIXED,
            'legacy' => 'register',
            'endpoint' => 'GET /search/register',
        ],
        'motd' => [
            'connectors' => [],
            'port' => self::WEB,
            'default' => 'motd',
            'database' => null,
            'kind' => self::STANDARD,
            'legacy' => 'motd',
            'standard' => 'GET /motd',
        ],
        'guide' => [
            'connectors' => [],
            'port' => self::WEB,
            'default' => 'guide',
            'database' => null,
            'kind' => self::STANDARD,
            'legacy' => 'guide',
            'standard' => 'GET /destinations',
        ],
        'grid-status' => [
            'connectors' => [],
            'port' => self::WEB,
            'default' => 'grid_status',
            'database' => null,
            'kind' => self::PAGE,
        ],
        'directory' => [
            'connectors' => [],
            'port' => self::WEB,
            'default' => 'directory',
            'database' => null,
            'kind' => self::PAGE,
        ],
        'grid-status-rss' => [
            'connectors' => [],
            'port' => self::WEB,
            'database' => null,
            'kind' => self::FIXED,
        ],
        'parser' => [
            'connectors' => [],
            'port' => self::WEB,
            'default' => 'parser',
            'database' => null,
            'kind' => self::STANDARD,
            'legacy' => 'parser',
            'standard' => 'POST /tasks/search-parse/runs',
        ],
        'events-parser' => [
            'connectors' => [],
            'port' => self::WEB,
            'default' => 'eventsparser',
            'database' => null,
            'kind' => self::STANDARD,
            'legacy' => 'eventsparser',
            'standard' => 'POST /tasks/events-parse/runs',
        ],
        'text-images' => [
            'connectors' => [],
            'port' => self::WEB,
            'default' => 'textgen',
            'database' => null,
            'kind' => self::STANDARD,
            'legacy' => 'textgen',
            'standard' => 'GET /text-images',
        ],
        'assets' => [
            'connectors' => [],
            'port' => self::WEB,
            'default' => 'assets',
            'database' => Database::Assets,
            'kind' => self::STANDARD,
            'standard' => 'GET /assets/{id}.{format}',
        ],
        'instant-message' => [
            'connectors' => ['InstantMessageServerConnector'],
            'port' => self::PUBLIC,
            'database' => null,
        ],
    ];

    /**
     * What a web service is used for, by key (a service of Robust is used by the simulators on its private port, by the viewers and
     * the other grids on its public one): a helper the simulators or the viewer call, a page, a web tool used with a script
     * in-world, a task of the grid, the web assets of the sites.
     */
    private const USAGES = [
        'query' => 'helper-simulators',
        'register' => 'helper-simulators',
        'currency' => 'helper-viewers',
        'landtool' => 'helper-viewers',
        'search' => 'page',
        'welcome' => 'page',
        'web-profile' => 'page',
        'motd' => 'page',
        'guide' => 'page',
        'grid-status' => 'page',
        'grid-status-rss' => 'page',
        'directory' => 'page',
        'text-images' => 'web-tool',
        'parser' => 'task',
        'events-parser' => 'task',
        'assets' => 'web-assets',
    ];


    /** What a standalone serves by itself: the services of the grid, the ones of the hypergrid when it is one. */
    private const STANDALONE = [
        'asset',
        'inventory',
        'grid',
        'estate',
        'authentication',
        'avatar',
        'agent-preferences',
        'presence',
        'user-account',
        'grid-user',
        'friends',
        'map-image',
        'mute-list',
        'profile',
        'login',
        'get-grid-info',
    ];

    private const STANDALONE_HYPERGRID = [
        'gatekeeper',
        'user-agent',
        'hg-friends',
        'hg-asset',
        'hg-inventory',
        'instant-message',
    ];

    /** @var array{0:?string,1:?string}|null the address and its source, worked out once */
    private ?array $resolved = null;

    /** @var array{0:?string}|null the address declared for the simulators of the grid, read once */
    private ?array $declaration = null;

    /**
     * @param string $key one of keys()
     * @param ?Simulator $viewer the simulator that asks, null for the grid itself
     */
    public function __construct(
        Environment $environment,
        private readonly Grid $grid,
        private readonly string $key,
        private readonly ?Simulator $viewer = null,
    ) {
        parent::__construct($environment);
        isset(self::CATALOG[$key]) || throw new \InvalidArgumentException("unknown service '$key'");
    }

    /**
     * The keys of the services OpenSimulator has.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::CATALOG);
    }

    /** What a service is for, by key. */
    public static function labels(): array
    {
        return [
            'asset' => _('Assets'),
            'inventory' => _('Inventories'),
            'grid' => _('Regions of the grid'),
            'estate' => _('Estates'),
            'authentication' => _('Authentication'),
            'avatar' => _('Avatar appearance'),
            'agent-preferences' => _('Agent preferences'),
            'presence' => _('Presence'),
            'user-account' => _('User accounts'),
            'grid-user' => _('Last positions of the users'),
            'friends' => _('Friends'),
            'map-image' => _('Map tiles'),
            'mute-list' => _('Mute lists'),
            'bakes' => _('Baked textures'),
            'groups' => _('Groups'),
            'offline' => _('Offline messages'),
            'profile' => _('Profiles'),
            'login' => _('Login'),
            'get-grid-info' => _('Grid information of Robust (get_grid_info)'),
            'gatekeeper' => _('Gatekeeper (hypergrid arrivals)'),
            'user-agent' => _('User agent (hypergrid departures)'),
            'hg-friends' => _('Hypergrid friends'),
            'hg-asset' => _('Hypergrid assets'),
            'hg-inventory' => _('Hypergrid inventories'),
            'hg-groups' => _('Hypergrid groups'),
            'instant-message' => _('Instant messages between grids'),
            'map' => _('Map tiles for the web'),
            'query' => _('Search API (the simulators query it)'),
            'search' => _('Web search'),
            'welcome' => _('Welcome page'),
            'web-profile' => _('Web page of a profile'),
            'currency' => _('Currency'),
            'landtool' => _('Land purchase'),
            'register' => _('Registration of the simulators in the search'),
            'motd' => _('Message of the day'),
            'guide' => _('Destination guide'),
            'grid-status' => _('Status of the grid'),
            'directory' => _('Directory of the grids in the search'),
            'grid-status-rss' => _('Status of the grid, feed'),
            'parser' => _('Reading of the search data of the hosts'),
            'events-parser' => _('Reading of the events'),
            'text-images' => _('Images of a text'),
            'assets' => _('Assets as images for the web'),
        ];
    }

    /**
     * The services a grid has, served by its Robust instances or declared in the config of its simulators.
     *
     * Options: `search` (words of the key, the name or the address), `sort` ("name", "key", "grid"), `filters` (`served` yes or no,
     * `port` private or public), `page` and `perPage`.
     *
     * @param ?Simulator $viewer the simulator that asks, null for the grid itself
     */
    public static function of(?Grid $grid, ?Simulator $viewer = null, array $options = []): Collection
    {
        $services = [];
        foreach ($grid === null ? [] : self::keys() as $key) {
            $service = new self($grid->environment(), $grid, $key, $viewer);
            $service->exists() && ($services[] = $service);
        }

        return self::collect($services, $options);
    }

    /** The services of all the grids of the machine. */
    public static function all(Environment $environment, array $options = []): Collection
    {
        $services = [];
        foreach (Grid::all($environment) as $grid) {
            foreach (self::of($grid)->items() as $service) {
                $services[] = $service;
            }
        }

        return self::collect($services, $options);
    }

    /** A service by its slug: the grid, a dot, the key (`vonda.asset`). */
    public static function find(Environment $environment, string $id): ?static
    {
        foreach (self::all($environment)->items() as $service) {
            if ($service->id() === $id) {
                return $service;
            }
        }

        return null;
    }

    /** @param list<self> $services */
    private static function collect(array $services, array $options): Collection
    {
        $yesNo = static fn(self $service, string $value): bool => $service->isServed() === ($value === 'yes');

        return self::paged(
            $services,
            $options,
            static fn(
                self $service,
            ): string => "{$service->key} {$service->name()} {$service->url()} {$service->grid->nick()}",
            [
                'name' => static fn(self $service): string => $service->name(),
                'key' => static fn(self $service): string => $service->key,
                'grid' => static fn(self $service): string => $service->grid->nick(),
            ],
            [
                'served' => $yesNo,
                'port' => static fn(self $service, string $value): bool => $service->port() === $value,
            ],
            self::listing(),
        );
    }

    /** What the list of the services shows (Listing). */
    public static function listing(): Listing
    {
        return new Listing(
            [
                Listing::column('name', 'column_service', sort: 'name'),
                Listing::column('grid', 'column_grid', sort: 'grid'),
                Listing::column('url', 'column_url', 'link', more: ['link' => 'url']),
                Listing::column('provider', 'column_provider'),
            ],
            [
                Listing::filter('served', 'filter_served'),
                Listing::filter('port', 'column_port', [
                    self::PRIVATE => 'port_private',
                    self::PUBLIC => 'port_public',
                    self::WEB => 'port_web',
                ]),
            ],
            ['key', 'name', 'url', 'grid'],
            moreSorts: ['key'],
        );
    }

    /** `vonda.asset`: the grid and the service, as the slug of a simulator has its grid. */
    public function id(): string
    {
        return $this->slug();
    }

    public function slug(): string
    {
        return Slug::simulator($this->grid->nick(), $this->key);
    }

    /** What the service is for ("Assets"). */
    public function name(): string
    {
        return self::labels()[$this->key];
    }

    /** The key of the service in the catalog (`asset`, `profile`). */
    public function key(): string
    {
        return $this->key;
    }

    public function grid(): Grid
    {
        return $this->grid;
    }

    /** The simulator that asks, null when it is the grid. */
    public function viewer(): ?Simulator
    {
        return $this->viewer;
    }

    /** Whether it is a web service: an address on a web site (the helpers), not a port of Robust. */
    public function isWeb(): bool
    {
        return $this->port() === self::WEB;
    }

    /** Whether its format is fixed by OpenSimulator or the viewers (self::FIXED), or the engine's own (self::STANDARD); null for a service of Robust. */
    public function kind(): ?string
    {
        return self::CATALOG[$this->key]['kind'] ?? null;
    }

    /** The name of the script that answered it before the engine had a standard for it (`query`, `register`...). */
    public function legacy(): ?string
    {
        return self::CATALOG[$this->key]['legacy'] ?? null;
    }

    /** The standard address of a service whose format is the engine's own (`GET /motd`), null when it has none. */
    public function standard(): ?string
    {
        return self::CATALOG[$this->key]['standard'] ?? null;
    }

    /**
     * Where the engine serves it (`POST /search/query`, `GET /motd`), under the API mount or, on a host that serves one grid, without
     * `/grids/{grid}`: the standard address, or the one of the fixed format; null when the engine does not serve it (Robust does).
     */
    public function endpoint(): ?string
    {
        return self::CATALOG[$this->key]['endpoint'] ?? (self::CATALOG[$this->key]['standard'] ?? null);
    }

    /** The port it is reached on: self::PRIVATE or self::PUBLIC. */
    public function port(): string
    {
        return self::CATALOG[$this->key]['port'];
    }

    /**
     * The connectors of `[ServiceList]` that serve it.
     *
     * @return list<string>
     */
    public function connectors(): array
    {
        return self::CATALOG[$this->key]['connectors'];
    }

    /** The database it stores in, null for a service that stores nothing. */
    public function database(): ?Database
    {
        return self::CATALOG[$this->key]['database'];
    }

    /**
     * Where a simulator is given its address: section and key of its config, null for a service the simulators do not ask for.
     *
     * @return ?array{0:string,1:string}
     */
    public function setting(): ?array
    {
        $entry = Settings::forService($this->key);

        return $entry === null ? null : [$entry['section'], self::keyOf($entry)];
    }

    /** The key of a setting as a section has it; a family of keys (DATA_SRV_*) ends with `*`. */
    private static function keyOf(array $entry): string
    {
        return $entry['key'] . (($entry['prefix'] ?? false) ? '*' : '');
    }

    /**
     * What serves it: a Robust of the grid, or the simulator of a standalone grid. Null when nothing does.
     */
    public function provider(): ?AbstractController
    {
        if ($this->grid->isStandalone()) {
            $serves =
                in_array($this->key, self::STANDALONE, true) ||
                ($this->grid->isHypergrid() && in_array($this->key, self::STANDALONE_HYPERGRID, true));

            return $serves ? ($this->grid->simulators()[0] ?? null)?->controller() : null;
        }
        foreach ($this->grid->robusts() as $robust) {
            foreach ($this->connectors() as $connector) {
                if ($robust->serves($connector)) {
                    return $robust;
                }
            }
        }

        return null;
    }

    /** Whether a Robust of the grid serves it. */
    public function isServed(): bool
    {
        return $this->provider() !== null;
    }

    /** Whether the address a simulator is given is the one of a server the grid does not run. */
    public function isExternal(): bool
    {
        return !$this->isServed() && $this->declared() !== null;
    }

    /** Whether the grid has it: served by a Robust, or declared for its simulators. */
    public function exists(): bool
    {
        // What the grid says, or the helpers answer at their default address
        return $this->isServed() ||
            $this->declared() !== null ||
            $this->live() !== null ||
            ($this->setting() === null && $this->helperAddress() !== null);
    }

    /**
     * The link to the service, where it is reached. A simulator gives the one its config says, else the one of its grid: the Robust that
     * serves it (its base URL and its private or public port), else the address declared for the simulators of the grid.
     */
    public function url(): ?string
    {
        if ($this->viewer !== null && ($own = $this->declaredBy($this->viewer)) !== null) {
            return $own;
        }
        return $this->resolved()[0];
    }

    /**
     * Where the address comes from: `provider` (the Robust or the simulator that serves it), `simulator` (its own config), `live`
     * (the running grid says it at get_grid_info), `config`, `helpers` (a tool of the helpers no setting gives, where the helpers the
     * grid has are: the parser, textgen); null when it has none (an address the grid does not set is not one of the grid).
     */
    public function source(): ?string
    {
        if ($this->viewer !== null && $this->declaredBy($this->viewer) !== null) {
            return 'simulator';
        }

        return $this->resolved()[1];
    }

    /**
     * The address and where it comes from, in the order of trust: what serves it, what the running grid says (a config changed
     * since is not what the viewers are told), what the config says, where the helpers answer it.
     *
     * @return array{0:?string,1:?string}
     */
    private function resolved(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }
        $append = self::CATALOG[$this->key]['append'] ?? '';
        $with = static fn(string $base): string => $append !== '' ? rtrim($base, '/') . '/' . $append : $base;
        $provider = $this->provider();
        if ($provider instanceof RobustController) {
            $port = $this->port() === self::PUBLIC ? $provider->publicPort() : $provider->privatePort();
            $base = $provider->setting('Const', 'BaseURL') ?: 'http://' . ($provider->baseHostname() ?? 'localhost');

            return $this->resolved = [$port !== null ? "$base:$port" : $base, 'provider'];
        }
        if ($provider !== null) {
            // The simulator of a standalone answers for everything on its own port
            return $this->resolved = [
                ($provider->setting('Const', 'BaseURL') ?: 'http://' . $this->grid->baseHostname()) .
                ':' .
                $this->grid->publicPort(),
                'provider',
            ];
        }
        if (($live = $this->live()) !== null) {
            return $this->resolved = [$with($live), 'live'];
        }
        if (($declared = $this->declared()) !== null) {
            return $this->resolved = [$with($declared), 'config'];
        }
        // What the grid does not say it has not: the address the helpers would answer by default is no address of the grid. A tool
        // of the helpers no setting gives (the parser, textgen: a script in-world calls it) is where the helpers the grid has are
        if ($this->setting() === null && ($tool = $this->helperAddress()) !== null) {
            return $this->resolved = [$tool, 'helpers'];
        }

        return $this->resolved = [null, null];
    }

    /** What the running grid says for it at get_grid_info, null when it does not say, or does not answer. */
    private function live(): ?string
    {
        $info = Settings::forService($this->key)['info'] ?? null;
        if ($info === null || !$this->grid->gridInfoIsLive()) {
            return null;
        }
        $value = $this->grid->gridInfo()[$info] ?? '';

        return $value !== '' ? $value : null;
    }

    /** Served by a Robust that is enabled, or declared (a server somewhere else is the business of whoever runs it). */
    public function isEnabled(): bool
    {
        return $this->provider()?->isEnabled() ?? $this->declared() !== null;
    }

    public function enable(): bool
    {
        throw ChannelUnavailable::for(
            'enable the ' . $this->key . ' service',
            "it is served by the Robust of the grid: enable the grid, or the feature that brings it ($this->key)",
        );
    }

    public function disable(): void
    {
        throw ChannelUnavailable::for(
            'disable the ' . $this->key . ' service',
            "it is served by the Robust of the grid: disable the grid, or the feature that brings it ($this->key)",
        );
    }

    public function detail(): array
    {
        $setting = $this->setting();

        return [
            'id' => $this->id(),
            'key' => $this->key,
            'name' => $this->name(),
            'slug' => $this->slug(),
            'grid' => $this->grid->nick(),
            'simulator' => $this->viewer?->id(),
            'url' => $this->url(),
            'port' => $this->port(),
            'provider' => $this->provider()?->name(),
            'served' => $this->isServed(),
            'external' => $this->isExternal(),
            'connectors' => $this->connectors(),
            'section' => $setting[0] ?? null,
            'setting' => $setting[1] ?? null,
            'database' => $this->database()?->value,
            'web' => $this->isWeb(),
            'kind' => $this->kind(),
            'legacy' => $this->legacy(),
            'standard' => $this->standard(),
            'endpoint' => $this->endpoint(),
            'source' => $this->source(),
        ];
    }

    /**
     * What the service is used for: `robust-simulators`, `robust-viewers` (a service of Robust), `helper-simulators`,
     * `helper-viewers`, `page`, `web-tool`, `task`, `web-assets` (USAGES). The offline messages are a service of Robust (V2) or a
     * helper of the simulators (V1), by where their address leads.
     */
    public function usage(): string
    {
        if ($this->key === 'offline') {
            return $this->isServed() ? 'robust-simulators' : 'helper-simulators';
        }

        return self::USAGES[$this->key] ?? ($this->port() === self::PUBLIC ? 'robust-viewers' : 'robust-simulators');
    }

    /**
     * Where its address is set, to show it with its file and its section: the file (`Robust.HG.ini`, `OpenSim.ini` for what the
     * simulators are given, `Helpers.ini`), the section and the key; the connector of `[ServiceList]` of Robust for a service the
     * simulators are not given; the key of get_grid_info that tells it (`info`); its name and description in the catalog.
     *
     * @return array{key:string,url:?string,source:?string,usage:string,file:?string,section:?string,setting:?string,info:?string,label:string,description:string}
     */
    public function address(): array
    {
        $entry = self::CATALOG[$this->key];
        $robust = $this->grid->robustIni();
        $robustFile = $robust !== null ? basename($robust) : 'Robust.ini';
        // Where the registry of the settings says it is set, else the connector of Robust that serves it
        $setting = Settings::forService($this->key);
        [$section, $key] = $setting !== null ? [$setting['section'], self::keyOf($setting)] : [null, null];
        $file = match (true) {
            $setting === null => ($entry['connectors'] ?? []) === [] ? null : $robustFile,
            $setting['instance'] === 'robust' => $robustFile,
            default => Settings::INSTANCES[$setting['instance']]['file'],
        };
        if ($section === null && $file !== null) {
            [$section, $key] = ['ServiceList', $entry['connectors'][0]];
        }
        $name = Settings::serviceLabel($this->key) ?? 'service_' . str_replace('-', '_', $this->key);

        return [
            'key' => $this->key,
            'url' => $this->url(),
            'source' => $this->source(),
            'usage' => $this->usage(),
            'file' => $file,
            'section' => $section,
            'setting' => $key,
            'info' => $setting['info'] ?? null,
            'label' => $name,
            'description' => $name . '_help',
        ];
    }

    /** Where the helpers of the grid answer a web service when the grid says nothing of it, null when it has no helpers. */
    private function helperAddress(): ?string
    {
        $default = self::CATALOG[$this->key]['default'] ?? null;

        return $default !== null && $this->isWeb() ? ($this->grid->helperUrl($default) ?: null) : null;
    }

    /**
     * The settings of the config that give the address, the first that has a value wins: role, section, key.
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    private function settings(): array
    {
        return array_map(
            static fn(array $entry): array => [Settings::INSTANCES[$entry['instance']]['role'], $entry['section'], self::keyOf($entry)],
            Settings::ofService($this->key),
        );
    }

    /** The address the config of the simulators of the grid gives for it, null when it gives none. */
    private function declared(): ?string
    {
        if ($this->viewer !== null) {
            return $this->declaredBy($this->viewer) ?? $this->gridDeclaration();
        }

        return $this->gridDeclaration();
    }

    private function gridDeclaration(): ?string
    {
        if ($this->declaration === null) {
            // What the grid says: the OpenSim.ini of its folder for the simulators, the Robust config for what Robust tells, the
            // Helpers.ini for what has no official place
            $tree = $this->grid->config()?->tree() ?? [];
            $value = null;
            foreach ($this->settings() as [$role, $section, $key]) {
                $in = $tree[$role][$section] ?? [];
                $value = self::valueOf(is_array($in) ? $in : [], $key);
                if ($value !== null) {
                    break;
                }
            }
            $this->declaration = [$value];
        }

        return $this->declaration[0];
    }

    /** The value of a setting in a section; a key ending with `*` is the first key that starts so. */
    private static function valueOf(array $section, string $key): ?string
    {
        if (str_ends_with($key, '*')) {
            foreach ($section as $name => $value) {
                if (str_starts_with((string) $name, rtrim($key, '*')) && is_string($value) && $value !== '') {
                    return $value;
                }
            }

            return null;
        }
        $value = $section[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function declaredBy(Simulator $simulator): ?string
    {
        $setting = $this->setting();
        // What Robust tells is not in the config of a simulator
        if ($setting === null || Settings::forService($this->key)['instance'] !== 'opensim') {
            return null;
        }

        return self::valueOf($simulator->settings()[$setting[0]] ?? [], $setting[1]);
    }
}
