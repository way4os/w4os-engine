<?php

declare(strict_types=1);

namespace W4OS\Engine\Models;

use W4OS\Engine\Config\ConfigReader;
use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Config\IniEditor;
use W4OS\Engine\Config\Settings;
use W4OS\Engine\Exceptions\InvalidValue;
use W4OS\Engine\Exceptions\NotFound;
use W4OS\Engine\Hypergrid\Destination;
use W4OS\Engine\Provisioning\Web\HelpersConfig;
use W4OS\Engine\Text\Notice;
use W4OS\Engine\Text\Uuid;

/**
 * The settings of a grid a site shows and changes, by page, each a form (`x-form` of the contract): the settings of the registry
 * (Settings) whose `admin` is that page, in their order, in the standard field format, by their clean name. A setting is written in
 * the file of the grid that says it already (Robust, OpenSim.ini, Helpers.ini, MoneyServer.ini, in the order the grid reads them),
 * else in the file of its instance; for a grid without files here, in the settings the application keeps (SettingsStore); what a site sends is checked against its field. What the options of the grid set is shown, not
 * changed here (`readOnly`); a secret is never given back (Settings::MASK when it is set), and the mask sent back keeps it.
 *
 *     $settings = new GridSettings($grid);
 *     $settings->read('models');                        // ['match' => 'any', 'pattern' => 'Default', 'list' => []]
 *     $settings->write('models', ['match' => 'uuid', 'list' => ['<uuid>']]);
 *
 * Pages: `models` (the rule of the avatar models, the keys of w4os), `economy`, `search`, `guide`, `offline`, `assets` (the pages
 * of the services of w4os-wp).
 */
final class GridSettings
{
    /**
     * The pages, their title, their short label in a menu (`menu`), their help (`description`: the keys of its points, in order) and the list a form shows of what its
     * settings pick (`preview`: its title, what it says when empty, the list, the fields given as its filters). The words are those
     * of w4os-wp, with its translations.
     */
    private const PAGES = [
        // The ways to reach its Robust in one place: its address, its database, its console
        'grid' => [
            'title' => 'settings_grid',
            'menu' => 'settings_menu_grid',
            'groups' => [['name' => 'grid', 'label' => 'group_grid_info'], ['name' => 'robust', 'label' => 'group_robust'], ['name' => 'services', 'label' => 'group_services'], ['name' => 'avatars', 'label' => 'group_avatars']],
        ],
        'models' => [
            'title' => 'settings_models',
            'menu' => 'settings_models',
            'description' => ['models_help_choice', 'models_help_create', 'models_help_not_users', 'models_help_outfit', 'models_help_rule'],
            'preview' => ['label' => 'models_preview', 'empty' => 'models_none', 'path' => '/grids/{grid}/avatar-models', 'filters' => ['match', 'pattern', 'list']],
        ],
        'avatars' => ['title' => 'settings_avatars', 'menu' => 'settings_avatars'],
        'economy' => ['title' => 'settings_economy', 'menu' => 'settings_menu_economy', 'description' => ['economy_help_helpers', 'economy_help_scripts', 'economy_help_module', 'economy_help_modules', 'economy_help_binaries']],
        'search' => ['title' => 'settings_search', 'menu' => 'settings_menu_search', 'description' => ['search_help_local', 'search_help_external', 'search_help_module', 'search_help_binary']],
        'guide' => ['title' => 'settings_guide', 'menu' => 'settings_menu_guide', 'description' => ['guide_help_feature', 'guide_help_places', 'guide_source_help_file', 'guide_source_help_pipe', 'guide_source_help_categories', 'guide_source_help_comments', 'guide_source_help_board']],
        'offline' => ['title' => 'settings_offline', 'menu' => 'settings_menu_offline', 'description' => ['provide_offline_help', 'provide_offline_help_core']],
        'assets' => ['title' => 'settings_assets', 'menu' => 'settings_menu_assets', 'description' => ['provide_assets_help']],
    ];

    /**
     * The pages of the services of the helpers, as w4os-wp has them: what Provide is the address of (the setting, the service of the
     * helpers it is), its label and help, the block a page of the site shows when the site handles it, and whether the address is
     * a prefix (`prefix`: the economy, currency.php and landtool.php fixed under it), what the path is under (`base`: the site, or
     * the helpers for what the viewers and the simulators call) and the setting that keeps the address of another server
     * (`external`), so that it is not lost when Provide is switched.
     */
    private const PROVIDE = [
        'economy' => ['setting' => 'economyUrl', 'service' => 'currency', 'label' => 'provide_economy', 'prefix' => true, 'url' => 'economy_helper_uri', 'help' => ['economy_helper_uri_help']],
        'search' => ['setting' => 'searchUrl', 'service' => 'query', 'base' => 'helpers', 'external' => 'searchExternalUrl', 'label' => 'provide_search', 'after' => 'searchDatabase', 'url' => 'search_engine_url', 'help' => ['search_url_help_viewer', 'search_url_help_one']],
        'guide' => ['setting' => 'destinationGuide', 'service' => 'guide', 'label' => 'provide_guide', 'block' => 'destination_guide', 'after' => 'guideSource', 'url' => 'guide_url'],
        'offline' => ['setting' => 'offlineMessageUrl', 'service' => 'offline', 'base' => 'helpers', 'label' => 'provide_offline', 'after' => 'offlineModule', 'url' => 'offline_helper', 'help' => ['offline_helper_help']],
        // A tool of its own, not a page of the site: anywhere under the site, routed apart to the engine
        'assets' => ['setting' => 'assetsUrl', 'service' => 'assets', 'label' => 'provide_assets', 'url' => 'assets_url', 'help' => ['assets_path_help'], 'urlHelp' => ['assets_external_help'], 'example' => 'https://example.org/assets', 'required' => true],
    ];

    /** The tip of a field, under its label where the label is not enough: a few words, the details being its description. */
    private const TIPS = [
        'models' => ['match' => 'tip_models_match', 'pattern' => 'tip_models_pattern'],
        'economy' => [
            'path' => 'tip_economy_url', 'economyUrl' => 'tip_economy_url',
            'gloebitEnvironment' => 'tip_gloebit_environment', 'gloebitKey' => 'tip_gloebit_keys',
            'gloebitSecret' => 'tip_gloebit_keys', 'currencyRate' => 'tip_currency_rate', 'moneyScriptKey' => 'tip_money_script_key',
            'podexMessage' => 'tip_podex_message', 'podexUrl' => 'tip_podex_url',
        ],
        'search' => [
            'path' => 'tip_search_url', 'searchExternalUrl' => 'tip_search_url',
            'webSearchUrl' => 'tip_web_search',
        ],
        'guide' => ['guideSource' => 'tip_guide_source', 'path' => 'tip_guide_url', 'destinationGuide' => 'tip_guide_url'],
        'offline' => [
            'offlineModule' => 'tip_offline_module',
            'path' => 'tip_offline_url', 'offlineMessageUrl' => 'tip_offline_url',
        ],
        'assets' => ['path' => 'tip_assets_url', 'assetsUrl' => 'tip_assets_url'],
    ];

    /** The fields of a page in the order w4os-wp shows them, the address of the service and Provide aside. */
    private const ORDER = [
        'economy' => ['economyModule', 'currencySymbol', 'moneyDatabase', 'moneyBanker', 'gloebitEnvironment', 'gloebitKey', 'gloebitSecret', 'gloebitOwnerName', 'gloebitOwnerEmail', 'gloebitDatabase', 'currencyRate', 'moneyScriptKey', 'podexMessage', 'podexUrl'],
        'search' => ['searchDatabase', 'webSearchUrl', 'eventsUrl'],
        'offline' => ['mailSender', 'offlineDatabase', 'offlineModule'],
        'grid' => ['loginUri', 'gridName', 'gridNick', 'gridServerUri', 'database', 'consoleUser', 'consolePass', 'consolePort', 'assetServerUri', 'profileServiceUrl', 'webProfileUrl', 'excludeFromStats'],
    ];

    /**
     * What w4os-wp shows of a field on a page that its setting does not say (its label there, its help in points,
     * what it requires, when it is shown), and the notes it shows (`note`: a text, no value), by page and field.
     */
    private const SHOWN_AS = [
        'grid' => [
            'loginUri' => ['group' => 'grid'],
            'gridName' => ['group' => 'grid'],
            'gridNick' => ['group' => 'grid'],
            'gridServerUri' => ['group' => 'robust', 'label' => 'setting_robust_uri', 'description' => 'setting_robust_uri_help'],
            'database' => ['group' => 'robust', 'label' => 'setting_robust_database'],
            'consoleUser' => ['group' => 'robust'],
            'consolePass' => ['group' => 'robust'],
            'consolePort' => ['group' => 'robust'],
            // The services that may be in Robust instances of their own
            'assetServerUri' => ['group' => 'services', 'label' => 'setting_assets_service_uri', 'description' => ['uri_default_robust']],
            'profileServiceUrl' => ['group' => 'services', 'label' => 'setting_profile_service_uri', 'description' => ['uri_default_login']],
            'webProfileUrl' => ['group' => 'avatars', 'label' => 'setting_profile_page'],
            'excludeFromStats' => ['group' => 'avatars'],
        ],
        'economy' => [
            'currencyRate' => ['condition' => ['economyModule' => ['BetaGridLikeMoneyModule', 'DTLNSLMoneyModule', 'Podex']]],
            'gloebitConfiguration' => ['note' => true, 'after' => 'currencyRate', 'label' => 'gloebit_configuration', 'description' => ['gloebit_help_crash', 'gloebit_help_linux', 'gloebit_help_register', 'gloebit_help_config', 'gloebit_help_dll'], 'condition' => ['economyModule' => ['Gloebit']]],
            'moneyScriptKey' => ['label' => 'money_script_access_key', 'description' => ['money_script_access_key_help']],
            // A money server can create money: offered as a convenience, not as a recommendation
            'moneyServerWarning' => ['note' => true, 'after' => 'economyModule', 'label' => 'money_server_warning_title', 'description' => ['money_server_warning'], 'condition' => ['economyModule' => ['DTLNSLMoneyModule', 'Podex']]],
            'moneyBanker' => ['description' => ['money_banker_help', 'money_banker_podex_help']],
            'gloebitOwnerName' => ['description' => ['gloebit_banker_help']],
            'currencySymbol' => ['description' => ['currency_symbol_help']],
            'moneyDatabase' => ['description' => ['db_default_main']],
            'gloebitDatabase' => ['description' => ['db_default_simulator']],
        ],
        'search' => [
            'webSearchUrl' => ['label' => 'web_search_url', 'description' => ['web_search_help']],
            'searchDatabase' => ['description' => ['db_default_main']],
            'eventsUrl' => ['description' => ['events_help_fetch']],
        ],
        'guide' => [
            'guideSource' => ['description' => ['guide_source_help_where', 'guide_source_help_name']],
        ],
        'offline' => [
            'mailSender' => ['required' => true],
            'offlineDatabase' => ['description' => ['db_default_main']],
            // Not provided, the module is chosen: V1 with the address of another service, V2 Robust's
            'offlineModule' => ['condition' => ['provide' => [false]]],
            'offlineMessageUrl' => ['condition' => ['offlineModule' => ['OfflineMessageModule']]],
        ],
    ];
    /** The type of a field of the standard format, by type of setting. */
    private const FIELD_TYPES = [
        'text' => 'string',
        'hostname' => 'string',
        // host:port, as a viewer takes it
        'login-uri' => 'string',
        'float' => 'number',
        'bool' => 'boolean',
        'password' => 'secret',
    ];

    /** What a field says of its setting, beside its name and its type. */
    private const SHOWN = ['label', 'options', 'widget', 'default', 'example', 'placeholder', 'condition', 'multiple', 'description', 'readOnly', 'required', 'fallback'];

    /** The roles of the files of the grid, in the order it reads a setting from them (GridConfig::find()). */
    private const FILES = ['Robust', 'OpenSim', 'Helpers', 'MoneyServer'];

    public function __construct(private readonly Grid $grid) {}

    /** What a page of settings is, for the contract. */
    public static function description(string $page): string
    {
        return match ($page) {
            'models' => 'The rule the avatar models are picked by (`[Models]`, the keys of w4os): `match` first (the first name is the pattern), last (the last name), any (either), uuid (the list); `pattern`, the name; `list`, the UUIDs of the models for uuid. The models it picks are the list of `/grids/{grid}/avatar-models`.',
            'grid' => 'What the grid is (its login URI, its name, its nick), the ways to reach its Robust in one place (its address, the private one of the services, its database, its remote console: `[Network]` of Robust), the services that may be in Robust instances of their own (the assets, the profiles: null, Use default, the address of Robust, the login URI), the web page of a profile, and what the numbers of the grid leave out (`excludeFromStats`: models, nomail, the accounts without email, hypergrid, the visitors of other grids; the models and nomail by default, as w4os-wp).',
            'avatars' => 'What is checked when an avatar is made (`[Avatars]` of Helpers.ini): `allowMultipleAvatars`, more than one avatar for an email (true by default; false refuses a new avatar whose email an avatar has, `email-taken`); `reservedNames`, the names no new avatar may have as its first or last name (Default, Test, Admin by default; the name of the grid always, `name-reserved`).',
            'economy' => 'The economy of the grid, as w4os-wp has it: the address of its helpers (`economy` of `[GridInfoService]`), its module chosen as w4os-wp chooses its provider (the free economy, the generic money server, Gloebit and its settings, or Podex: the generic money server, `[Helpers] CurrencyProvider = podex`), its database, the key of the money server, the rate and the settings of Podex the helpers use.',
            'search' => 'The search of the grid, as w4os-wp has it: what the simulators call and where they register (set by the options of the grid), the database of the search, the calendar of the events.',
            'guide' => 'The destinations guide of the grid, as w4os-wp has it: its address (`DestinationGuide` of `[LoginService]`) and its source (`GuideSource` of `[Helpers]`).',
            'offline' => 'The offline messages of the grid, as w4os-wp has them: provided, the helpers serve the module V1 and keep the messages in their database; not provided, the module V1 with the address of another service, or V2, Robust\'s, without one; the sender of the messages forwarded by mail.',
            'assets' => 'The web assets server of the grid (`AssetsURL` of `[Helpers]`): set when it is not the one of the helpers, as when this machine does not read JPEG 2000.',
            default => '',
        };
    }

    /** @return list<string> the pages of settings */
    public static function groups(): array
    {
        return array_keys(self::PAGES);
    }

    /**
     * The form of a page, as the contract gives it (`x-form`): its title, its label in a menu, its fields without where they are kept,
     * its submit, the message once it is saved.
     *
     * @return array{title:string,fields:list<array<string,mixed>>,submit:string,description?:list<string>,preview?:array<string,mixed>}
     */
    public static function form(string $page): array
    {
        $fields = array_map(
            static fn(array $entry): array => ['name' => $entry['name'], 'type' => self::fieldType($entry)]
                + array_intersect_key(array_filter($entry, static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== []), array_flip(self::SHOWN))
                + (($codec = Settings::type($entry['type'])) !== null ? ['fields' => $codec->fields()] : []),
            self::entries($page),
        );
        if (isset(self::PROVIDE[$page])) {
            $fields = self::provided($page, $fields);
        } else {
            // What the page shows of them, in its order
            $fields = array_map(static fn(array $field): array => (self::SHOWN_AS[$page][$field['name']] ?? []) + $field, $fields);
            $order = array_flip(self::ORDER[$page] ?? []);
            usort($fields, static fn(array $one, array $two): int => ($order[$one['name']] ?? PHP_INT_MAX) <=> ($order[$two['name']] ?? PHP_INT_MAX));
        }
        // The tip under the label, where the label is not enough
        $fields = array_map(static fn(array $field): array => isset(self::TIPS[$page][$field['name']]) ? ['tip' => self::TIPS[$page][$field['name']]] + $field : $field, $fields);

        return ['title' => self::PAGES[$page]['title'], 'menu' => self::PAGES[$page]['menu'], 'fields' => $fields, 'submit' => 'action_save_changes', 'saved' => 'settings_saved']
            + array_intersect_key(self::PAGES[$page], ['description' => 1, 'preview' => 1, 'groups' => 1]);
    }

    /**
     * The settings of a page, as the grid says them, else their defaults; a secret masked.
     *
     * @return array<string,mixed>
     */
    public function read(string $page, bool $provide = true): array
    {
        $config = $this->config();
        $values = [];
        foreach (self::entries($page) as $entry) {
            $values[$entry['name']] = self::value($entry, $config);
        }
        // Podex: the generic money server, the helpers told it is Podex
        if (($values['economyModule'] ?? null) === 'DTLNSLMoneyModule' && strtolower(trim((string) $config->find('Helpers', 'CurrencyProvider'))) === 'podex') {
            $values['economyModule'] = 'Podex';
        }
        if (!$provide || !isset(self::PROVIDE[$page])) {
            return $values;
        }
        // Provided: no address of its own, or one on the site of the grid
        $provide = self::PROVIDE[$page];
        $url = $values[$provide['setting']];
        $site = $this->grid->site();
        $on = !is_string($url) || $url === '' || ($site !== null && \W4OS\Engine\Provisioning\Web\Services::isUnder($url, $site));
        // The module V2 is Robust's: the helpers do not provide it
        $page === 'offline' && stripos((string) $values['offlineModule'], 'V2') !== false && ($on = false);
        // The path under its base, which ends with the slash: it never starts with one
        $path = ltrim($on && is_string($url) && $url !== '' ? $this->pathOf($page, $url) : $this->defaultPath($page), '/');
        // The address of another server: the one kept, else the one the grid uses when it is elsewhere
        if (isset($provide['external']) && !$on && $config->find('Helpers', Settings::named($provide['external'])['key']) === null) {
            $values[$provide['external']] = $url;
        }
        $page === 'search' && ($values = $this->register($config, $on) + $values);
        unset($values[$provide['setting']]);
        isset($provide['external']) || ($values[$provide['setting']] = $url);

        return ['provide' => $on, 'path' => $path] + $values;
    }

    /** The address the path of a page is under: the helpers for what the viewers and the simulators call, else the site. */
    private function baseOf(string $page): ?string
    {
        if ((self::PROVIDE[$page]['base'] ?? 'site') === 'helpers') {
            $helpers = $this->grid->helpersUrl() ?? ($this->grid->site() === null ? null : $this->grid->site() . '/helpers');

            return $helpers === null ? null : rtrim($helpers, '/');
        }

        return $this->grid->site();
    }

    /** The path of an address under the base of its page (with its query), as the page shows it. */
    private function pathOf(string $page, string $url): string
    {
        $base = $this->baseOf($page);
        if ((self::PROVIDE[$page]['base'] ?? 'site') === 'helpers' && $base !== null && str_starts_with($url, $base . '/')) {
            return substr($url, strlen($base) + 1);
        }

        return self::pathAndQuery($url);
    }

    /** The key the grid registers on its own search with (`DATA_SRV_` and its name). */
    private function ownRegister(): string
    {
        return 'DATA_SRV_' . preg_replace('/[^A-Za-z0-9]/', '', ucwords($this->grid->name() ?: 'Your Grid'));
    }

    /**
     * Where the simulators register for the search: their own register under the helpers (`registerPath`), and the registers of
     * other search engines (`registers`, the other DATA_SRV_ of [DataSnapshot]; 2do.directory's when the grid has none).
     *
     * @return array{registerPath:string,include2do:bool,registers:list<string>}
     */
    private function register(GridConfig $config, bool $on): array
    {
        $base = $this->baseOf('search');
        // A register under the helpers of the grid is its own, whatever its key (the simulators may name it by the nick)
        $mine = static fn(string $url): bool => $base !== null && str_starts_with($url, $base . '/');
        $own = trim((string) $config->find('DataSnapshot', $this->ownRegister()), " \t\"");
        $others = [];
        $twoDo = null;
        $keys = $config->keys('DataSnapshot', '/^DATA_SRV_/i');
        foreach ($keys as $key => $url) {
            $url = trim((string) $url, " \t\"");
            if ($url === '' || strcasecmp((string) $key, $this->ownRegister()) === 0) {
                continue;
            }
            if (self::is2do($url)) {
                $twoDo = true;
                continue;
            }
            $mine($url) ? ($own !== '' || ($own = $url)) : ($others[] = $url);
        }

        return [
            'registerPath' => $own !== '' && $base !== null && str_starts_with($own, $base . '/') ? substr($own, strlen($base) + 1) : 'register.php',
            // 2do.directory, on for a grid that registers nowhere yet
            'include2do' => $twoDo ?? array_filter(array_map(static fn($url): string => trim((string) $url, " \t\""), $keys)) === [],
            'registers' => array_values(array_unique($others)),
        ];
    }

    /** The register of 2do.directory, the one recommended. */
    private const REGISTER_2DO = 'http://2do.directory/helpers/register.php';

    /** The address of 2do.directory the grid registers on now (http or https), null when none. */
    private function twoDoRegister(): ?string
    {
        foreach ($this->config()->keys('DataSnapshot', '/^DATA_SRV_/i') as $url) {
            $url = trim((string) $url, " \t\"");
            if ($url !== '' && self::is2do($url)) {
                return $url;
            }
        }

        return null;
    }

    /** Whether an address is the one of 2do.directory. */
    private static function is2do(string $url): bool
    {
        return str_contains((string) parse_url($url, PHP_URL_HOST), '2do.directory');
    }

    /** The key of a register in [DataSnapshot]: DATA_SRV_2do for 2do.directory, else DATA_SRV_ and its host. */
    private static function registerKey(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return str_contains($host, '2do.directory') ? 'DATA_SRV_2do' : 'DATA_SRV_' . preg_replace('/[^A-Za-z0-9]/', '', ucwords(str_replace(['.', '-'], ' ', $host)));
    }

    /** The path of an address with what follows it (its query), as a site shows it under its own address. */
    private static function pathAndQuery(string $url): string
    {
        $parts = parse_url($url) ?: [];

        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * Change settings of a page: the ones given, checked; each written in the file of the grid that says it, else the file of its
     * instance.
     *
     * @param array<string,mixed> $values by name
     * @return array<string,mixed> the settings of the page now
     * @throws InvalidValue for a value its field does not take, a setting the options set (`invalid-request`)
     */
    public function write(string $page, array $values): array
    {
        $entries = array_column(self::entries($page), null, 'name');
        // Provide switched on, before it becomes the address
        $switchedOn = filter_var($values['provide'] ?? false, FILTER_VALIDATE_BOOLEAN);
        isset(self::PROVIDE[$page]) && ($values = $this->addressOf($page, $values));
        $now = $this->read($page);
        $before = $now;
        // Podex is written as the generic money server, and the provider the helpers serve
        if ($page === 'economy' && isset($values['economyModule']) && $values['economyModule'] !== $now['economyModule']) {
            $values['currencyProvider'] = $values['economyModule'] === 'Podex' ? 'podex' : '';
            $values['economyModule'] === 'Podex' && ($values['economyModule'] = 'DTLNSLMoneyModule');
            $entries['currencyProvider'] = Settings::named('currencyProvider');
        }
        // The web search: given to the viewers at login and by get_grid_info
        if ($page === 'search' && array_key_exists('webSearchUrl', $values)) {
            $values['loginSearchUrl'] = $values['webSearchUrl'];
            $entries['loginSearchUrl'] = Settings::named('loginSearchUrl');
        }
        // The guide saved without a source, or switched on without one, has the one of 2do.directory
        if ($page === 'guide' && ((array_key_exists('guideSource', $values) && trim((string) $values['guideSource']) === '')
            || ($switchedOn && ($this->config()->find('Helpers', 'GuideSource') ?? '') === '' && !isset($values['guideSource'])))) {
            $values['guideSource'] = (string) Settings::named('guideSource')['default'];
        }
        // Gloebit is a global currency: its symbol is G$
        $page === 'economy' && ($values['economyModule'] ?? $now['economyModule']) === 'Gloebit' && ($values['currencySymbol'] = 'G$');
        // The registers of the search, a key of the family DATA_SRV_ each
        foreach (array_filter(array_keys($values), static fn(string $name): bool => str_starts_with($name, 'DATA_SRV_')) as $key) {
            $entries[$key] = ['instance' => 'opensim', 'section' => 'DataSnapshot', 'key' => $key, 'name' => $key, 'type' => 'url', 'readers' => 'grid'];
        }
        $writes = [];
        foreach ($values as $name => $value) {
            $entry = $entries[$name] ?? throw InvalidValue::because('invalid-request', "'$name' is not a setting of $page (" . implode(', ', array_keys($entries)) . ')');
            if ($entry['readOnly'] ?? false) {
                // A form sends back what it shows: the same value is no change
                $value == $now[$name] || throw InvalidValue::because('invalid-request', "'$name' is set by the options of the grid, not here");
                continue;
            }
            // Nothing given, or the mask of a secret given back: no change
            if ($value === null || (($entry['secret'] ?? false) && $value === Settings::MASK)) {
                continue;
            }
            $where = $this->destination($entry);
            if (isset($where['reason'])) {
                // Where it cannot be written: the same value is no change, another one is refused with its reason
                $value == ($now[$name] ?? null) || throw \W4OS\Engine\Exceptions\ChannelUnavailable::because("change $name of {$this->grid->nick()}", $where['reason']['code'], $where['reason']['params']);
                continue;
            }
            $writes[] = [$entry, $value, $where];
        }
        foreach ($writes as [$entry, $value, $where]) {
            // Its default: what the grid uses when the setting says nothing (the database of Robust for the search); an empty address
            // sent is the default too, never an empty key
            $default = isset($entry['fallback']) && ((is_array($value) && filter_var($value['useDefault'] ?? false, FILTER_VALIDATE_BOOLEAN))
                || (is_string($value) && trim($value) === ''));
            // A database in keys of its own (the money server) reads no other: the main database of the grid is written in them
            if ($default && isset($entry['parts'])) {
                $main = \W4OS\Engine\Data\Database::Robust->connection($this->config()) ?? throw InvalidValue::because('invalid-request', "the grid says no main database to use for {$entry['name']}");
                $value = ['host' => $main->host ?: $main->source, 'port' => $main->port, 'database' => $main->database, 'user' => $main->user, 'password' => $main->password];
                $default = false;
            }
            // The instances that read the file restart to take a value that changed (the helpers and the site read theirs each time)
            ($value != ($before[$entry['name']] ?? null)) && isset($where['file']) && $this->restartFor((string) $where['file']);
            // false: the key commented out, as w4os-wp leaves the register the grid does not use
            if ($value === false) {
                ($where['store'] ?? false) && $this->grid->environment()->settings->set($entry['section'], $entry['key'], '');
                if (isset($where['file']) && is_file($where['file'])) {
                    $ini = IniEditor::load($where['file']);
                    $ini->comment($entry['section'], $entry['key']) && $ini->save($where['file']);
                }
                continue;
            }
            if ($where['store'] ?? false) {
                $store = $this->grid->environment()->settings;
                $now = $store->all()[$entry['section']] ?? [];
                $keys = $default ? [$entry['key'] => ''] : (isset($entry['parts'])
                    ? self::partsOf($entry, self::partsValue($entry, $value), static fn(string $key): ?string => $now[$key] ?? null)
                    : [$entry['key'] => self::checked($entry, $value, $now[$entry['key']] ?? null)]);
                foreach ($keys as $key => $text) {
                    $store->set($entry['section'], $key, trim($text, '"'));
                }
                continue;
            }
            $file = $where['file'];
            $ini = is_file($file) ? IniEditor::load($file) : new IniEditor('');
            if ($default) {
                $ini->remove($entry['section'], $entry['key']);
            } elseif (isset($entry['parts'])) {
                foreach (self::partsOf($entry, self::partsValue($entry, $value), static fn(string $key): ?string => $ini->get($entry['section'], $key)) as $key => $text) {
                    $ini->set($entry['section'], $key, $text);
                }
            } else {
                $current = $ini->get($entry['section'], $entry['key']);
                $ini->set($entry['section'], $entry['key'], $this->withConstants($entry, self::checked($entry, $value, $current), $current));
            }
            $ini->save($file);
        }

        return $this->read($page);
    }

    /** @var array<string,true> what the changes written leave to restart: `grid` (Robust), `simulators` */
    private array $restarts = [];

    /** Who restarts for a file written: Robust for its config and the money server's, the simulators for theirs, nobody for the helpers'. */
    private function restartFor(string $file): void
    {
        $robust = (string) $this->grid->robustIni();
        if (basename($file) === Settings::INSTANCES['helpers']['file']) {
            return;
        }
        $grid = !$this->grid->isStandalone() && ($file === $robust || basename($file) === Settings::INSTANCES['moneyserver']['file']);
        $this->restarts[$grid ? 'grid' : 'simulators'] = true;
    }

    /**
     * What the changes written leave to do: the notices of the restarts they need (`restart-grid`, `restart-simulators`, with the
     * command), none when nothing that an instance reads changed.
     *
     * @return list<array{code:string,params:array<string,string>,detail:string}>
     */
    public function reminders(): array
    {
        $reminders = [];
        isset($this->restarts['grid']) && ($reminders[] = Notice::of('restart-grid', ['restart' => 'opensim restart ' . $this->grid->nick()]));
        if (isset($this->restarts['simulators'])) {
            $names = array_map(static fn($simulator): string => $simulator->name(), $this->grid->simulators());
            $reminders[] = Notice::of('restart-simulators', ['restart' => 'opensim restart ' . ($names === [] ? $this->grid->nick() . ' --simulators' : implode(' ', $names))]);
        }

        return $reminders;
    }

    /**
     * Where a setting is written, at the best of what this process can do: the file of the grid that says it (or of its instance)
     * when this process can write it; else the settings the application keeps, for what the helpers and the site read only
     * (`readers` helpers); else nowhere here, with the reason (`reason`, a notice, and the file the grid reads it in, `file`).
     *
     * @param array<string,mixed> $entry
     * @return array{file?:?string,store?:bool,reason?:array{code:string,params:array<string,string|int>,detail:string}}
     */
    private function destination(array $entry): array
    {
        $store = $this->grid->environment()->settings;
        $kept = $store !== null && $store->writable() && ($entry['readers'] ?? 'grid') === 'helpers';
        // A grid in place: what only the helpers read goes to its section of the config of the suite, never in a file of the install
        if ($kept && $this->grid->in(Grid::IN_PLACE)) {
            return ['store' => true];
        }
        if (in_array('files', $this->grid->access(), true)) {
            $file = $this->fileOf($entry);
            if (self::canWrite($file)) {
                return ['file' => $file];
            }
            if ($kept) {
                return ['store' => true];
            }
            $name = basename($file);

            return ['file' => $file, 'reason' => Notice::of(($entry['readers'] ?? 'grid') === 'grid' ? 'setting-file-unwritable' : 'setting-file-unwritable-unkept', ['file' => $name])];
        }
        if ($kept) {
            return ['store' => true];
        }

        return ['file' => null, 'reason' => Notice::of(($entry['readers'] ?? 'grid') === 'grid' ? 'setting-no-files' : 'setting-no-files-unkept')];
    }

    /**
     * An address as a file of Robust or the simulators keeps it: under a constant of the grid (WebURL, BaseURL...), `${Const|Name}`
     * and the rest, the constant the line already used first; any other value as it is.
     *
     * @param array<string,mixed> $entry
     * @param ?string $current what the line says now, as it is written
     */
    private function withConstants(array $entry, string $text, ?string $current): string
    {
        $value = trim($text, '"');
        if ($entry['type'] !== 'url' || !in_array($entry['instance'], ['robust', 'opensim'], true) || $value === '') {
            return $text;
        }
        $constants = array_filter(
            // The constants of the instance that reads the file: Robust's for Robust, the simulators' for OpenSim.ini
            array_map(static fn($constant): string => trim((string) $constant, " \t\""), $this->config()->tree()[Settings::INSTANCES[$entry['instance']]['role']]['Const'] ?? []),
            static fn(string $constant): bool => preg_match('#^https?://#i', $constant) === 1,
        );
        // The constant the line uses, then the longest one the address is under
        $used = preg_match('/^"?\$\{Const\|([^}]+)\}/', (string) $current, $m) === 1 ? $m[1] : null;
        uksort($constants, static fn(string $one, string $two): int => [$two === $used, strlen($constants[$two] ?? '')] <=> [$one === $used, strlen($constants[$one] ?? '')]);
        foreach ($constants as $name => $constant) {
            $rest = substr($value, strlen(rtrim($constant, '/')));
            if (str_starts_with($value, rtrim($constant, '/')) && ($rest === '' || in_array($rest[0], ['/', ':', '?'], true))) {
                return '"${Const|' . $name . '}' . $rest . '"';
            }
        }

        return $text;
    }

    /** Whether this process can write a file: the file itself, or the folder it would be made in. Checked, never assumed. */
    private static function canWrite(string $file): bool
    {
        return is_file($file) ? is_writable($file) : is_dir(dirname($file)) && is_writable(dirname($file));
    }

    /**
     * The fields of a page this process cannot change for the grid: why, the file the grid reads it in, the lines to write there by
     * hand (`file`, `text`, never a secret), by field.
     *
     * @return array<string,array{reason:array{code:string,params:array<string,string|int>,detail:string},file:string,text:string}>
     */
    private function fixed(string $page): array
    {
        $config = $this->config();
        $values = $this->read($page, false);
        $fixed = [];
        foreach (self::entries($page) as $entry) {
            if ($entry['readOnly'] ?? false) {
                continue;
            }
            $where = $this->destination($entry);
            if (!isset($where['reason'])) {
                continue;
            }
            $value = $values[$entry['name']] ?? null;
            // Podex is the generic money server in the files of the grid
            $value === 'Podex' && $entry['name'] === 'economyModule' && ($value = 'DTLNSLMoneyModule');
            $line = [
                'reason' => $where['reason'],
                'file' => $where['file'] !== null ? basename($where['file']) : Settings::file($entry['instance'], $config),
                'text' => self::ini([$entry['section'] => self::lines($entry, $value)]),
            ];
            // The address of a service: its path as well as the whole address
            ($entry['name'] === (self::PROVIDE[$page]['setting'] ?? null)) && ($fixed['path'] = $line);
            $fixed[$entry['name']] = $line;
        }

        return $fixed;
    }

    /**
     * The values of a page of a service with its address in place of Provide and the path: on, the site and the path; off, the
     * whole address given.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     * @throws InvalidValue for a service provided by a grid that has no site, or not provided without its address
     */
    private function addressOf(string $page, array $values): array
    {
        $provide = self::PROVIDE[$page];
        $setting = $provide['setting'];
        $external = $provide['external'] ?? null;
        $register = $page === 'search' ? array_intersect_key($values, ['registerPath' => 1, 'registers' => 1, 'include2do' => 1]) : [];
        $given = array_key_exists('provide', $values) || array_key_exists('path', $values) || ($external !== null && array_key_exists($external, $values)) || $register !== [];
        $now = $given ? $this->read($page) : [];
        $on = array_key_exists('provide', $values) ? filter_var($values['provide'], FILTER_VALIDATE_BOOLEAN) : (bool) ($now['provide'] ?? true);
        $path = $values['path'] ?? null;
        unset($values['provide'], $values['path'], $values['registerPath'], $values['registers'], $values['include2do']);
        if (!$given) {
            return $values;
        }
        if ($on) {
            $base = $this->baseOf($page) ?? throw InvalidValue::because(
                'invalid-request',
                "the grid has no web site (WebURL of [Const]) to provide $page on: give the whole address of the service, Provide off",
            );
            $values = [$setting => $base . '/' . ltrim(trim((string) ($path ?? $now['path'])), '/')] + $values;
        } elseif ($page === 'offline' && stripos((string) ($values['offlineModule'] ?? $now['offlineModule']), 'V2') !== false) {
            // V2: Robust serves them, no address of the helpers
            unset($values[$setting]);
        } else {
            $address = $external !== null ? ($values[$external] ?? $now[$external]) : ($values[$setting] ?? null);
            // Left empty, the address of another server is its default (the search of 2do.directory)
            $external !== null && trim((string) $address) === '' && ($address = Settings::named($external)['default'] ?? $address);
            // Not provided: another service, never the helpers
            $helpers = $this->baseOf($page);
            $page === 'offline' && $helpers !== null && is_string($address) && str_starts_with($address, $helpers . '/') && throw InvalidValue::because(
                'invalid-request',
                "with Provide off, the offline messages are another service's: $address is the helpers' (Provide on)",
            );
            trim((string) $address) !== '' || throw InvalidValue::because(
                'invalid-request',
                'Provide off needs the whole address of the service (' . ($external ?? $setting) . ')',
            );
            $values = [$setting => $address] + $values;
        }
        // Provided, the helpers serve the module V1
        $page === 'offline' && $on && ($values['offlineModule'] = 'OfflineMessageModule');
        if ($page === 'search') {
            // The simulators register on the search the grid uses: its own under the helpers, else the other one
            $own = $this->ownRegister();
            $values[$own] = $on ? $this->baseOf('search') . '/' . ltrim((string) ($register['registerPath'] ?? $now['registerPath']), '/') : false;
            // The registers of other search engines, the ones not in the list left commented
            $registers = array_values(array_filter(array_map('trim', (array) ($register['registers'] ?? $now['registers'])), static fn(string $url): bool => $url !== ''));
            foreach ($this->config()->keys('DataSnapshot', '/^DATA_SRV_/i') as $key => $url) {
                strcasecmp((string) $key, $own) === 0 || ($values[(string) $key] = false);
            }
            // 2do.directory by its box; given in the list, it is included all the same
            $twoDo = array_values(array_filter($registers, self::is2do(...)));
            $registers = array_diff($registers, $twoDo);
            $include = $twoDo !== [] || filter_var($register['include2do'] ?? $now['include2do'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $include && ($values['DATA_SRV_2do'] = $twoDo[0] ?? $this->twoDoRegister() ?? self::REGISTER_2DO);
            foreach ($registers as $url) {
                $values[self::registerKey($url)] = $url;
            }
        }

        return $values;
    }

    /**
     * The fields of a page of a service of the helpers: Provide first (on by default), the path under the site when on, the whole
     * address when off, then the others, shown when the helpers provide it (what the options set too).
     *
     * @param list<array<string,mixed>> $fields
     * @return list<array<string,mixed>>
     */
    private static function provided(string $page, array $fields): array
    {
        $provide = self::PROVIDE[$page];
        $on = ['provide' => [true]];
        $shownAs = self::SHOWN_AS[$page] ?? [];
        $words = ['label' => $provide['url']] + (isset($provide['help']) ? ['description' => $provide['help']] : []);
        $address = [
            ['name' => 'path', 'type' => 'path', 'base' => $provide['base'] ?? 'site'] + $words + ['condition' => $on],
        ];
        $others = [];
        $register = [];
        foreach ($fields as $field) {
            $more = array_diff_key($shownAs[$field['name']] ?? [], ['condition' => 1]);
            $condition = ($shownAs[$field['name']]['condition'] ?? []) + ($field['condition'] ?? []);
            // The address of the grid is kept apart from the one of another server: only the latter is a field
            if ($field['name'] === $provide['setting'] && isset($provide['external'])) {
                continue;
            }

            if ($field['name'] === ($provide['external'] ?? $provide['setting'])) {
                $address[] = ['condition' => ['provide' => [false]] + ($shownAs[$field['name']]['condition'] ?? []), 'label' => $provide['url'], 'description' => $provide['urlHelp'] ?? $provide['help'] ?? []]
                    + array_intersect_key($provide, ['example' => 1, 'required' => 1]) + $field;
                $address[1]['description'] === [] && ($address[1] = array_diff_key($address[1], ['description' => 1]));
                continue;
            }
            $others[] = ['condition' => isset($condition['provide']) ? $condition : $on + $condition] + $more + $field;
        }
        $order = array_flip(self::ORDER[$page] ?? []);
        usort($others, static fn(array $one, array $two): int => ($order[$one['name']] ?? PHP_INT_MAX) <=> ($order[$two['name']] ?? PHP_INT_MAX));
        // The notes of the page, after the field w4os-wp shows them after
        foreach ($shownAs as $name => $note) {
            if (!($note['note'] ?? false)) {
                continue;
            }
            $at = array_search($note['after'], array_column($others, 'name'), true);
            array_splice($others, $at === false ? count($others) : $at + 1, 0, [
                ['name' => $name, 'type' => 'note', 'label' => $note['label'], 'description' => $note['description'], 'condition' => $on + $note['condition']],
            ]);
        }
        // The registers of the search: its own under the helpers, and the ones of other search engines, whatever Provide
        $page === 'search' && ($register = [
            ['name' => 'registerPath', 'type' => 'path', 'base' => 'helpers', 'label' => 'search_register_url', 'description' => ['search_register_help_data'], 'condition' => $on],
            ['name' => 'include2do', 'type' => 'boolean', 'label' => 'search_include_2do', 'description' => ['search_register_help_2do'], 'default' => true],
            ['name' => 'registers', 'type' => 'url', 'multiple' => true, 'label' => 'search_registers', 'description' => ['search_register_help_several']],
        ]);
        // The address where w4os-wp shows it: after Provide, or after the field it follows
        $at = isset($provide['after']) ? array_search($provide['after'], array_column($others, 'name'), true) : false;
        array_splice($others, $at === false ? 0 : $at + 1, 0, [...$address, ...$register]);

        return [
            ['name' => 'provide', 'label' => $provide['label'], 'type' => 'boolean', 'widget' => 'switch']
                + (isset($provide['description']) ? ['description' => $provide['description']] : []),
            ...$others,
        ];
    }

    /**
     * Who handles the requests of the service of a page, for a site to route them: `base` the site (its web address), `helpers` the
     * base of the helpers, `handledBy` the helpers (its address under their base), the site (elsewhere on it: a page of the site
     * that shows `block`, or the operation of the engine `endpoint`), elsewhere (another server, Provide off), null when it has none.
     *
     * @return array<string,mixed> empty for a page that is not of a service
     */
    public function meta(string $page): array
    {
        $fixed = $this->fixed($page);
        if (!isset(self::PROVIDE[$page])) {
            return $fixed === [] ? [] : ['fixed' => $fixed];
        }
        $provide = self::PROVIDE[$page];
        $url = $this->address($page);
        $site = $this->grid->site();
        $helpers = $this->grid->helpersUrl();
        $under = static fn(?string $base): bool => $url !== null && $base !== null && \W4OS\Engine\Provisioning\Web\Services::isUnder($url, $base);
        $service = new Service($this->grid->environment(), $this->grid, $provide['service']);

        return ($fixed === [] ? [] : ['fixed' => $fixed]) + ['snippets' => $this->snippets($page)] + self::examples($page) + ['service' => [
            'key' => $provide['service'],
            // What the path is under, read-only, ending with the slash the path never starts with
            'prefix' => ($prefix = $this->baseOf($page)) === null ? null : rtrim($prefix, '/') . '/',
            'base' => $site,
            'helpers' => $helpers,
            'handledBy' => match (true) {
                $url === null => null,
                $under($helpers) => 'helpers',
                $under($site) => 'site',
                default => 'elsewhere',
            },
            'block' => $provide['block'] ?? null,
            'endpoint' => $service->endpoint() ?? $service->standard(),
        ]];
    }

    /**
     * The lines of the files of the grid a page needs, for the values chosen now: one block for each file, in the order the page
     * needs them (`file`, `text`: its sections and their lines). Never a secret: a key, a password are named, not given.
     *
     * @return list<array{file:string,text:string}>
     */
    private function snippets(string $page): array
    {
        $config = $this->config();
        $robust = Settings::file('robust', $config);
        $url = $this->address($page) ?? '';
        $values = $this->read($page);
        $blocks = [];
        // The sections of a file, the ones given after the first merged in them
        $add = static function (string $file, array $sections) use (&$blocks): void {
            foreach ($sections as $section => $keys) {
                $blocks[$file][$section] = ($blocks[$file][$section] ?? []) + $keys;
            }
        };
        match ($page) {
            'economy' => $this->economyLines($add, $values, $url, $robust),
            'search' => $this->searchLines($add, $values, $url),
            'guide' => [
                $add($robust, ['LoginService' => ['DestinationGuide' => $url]]),
                $add('Helpers.ini', ['Helpers' => ['GuideSource' => (string) $values['guideSource']]]),
            ],
            'offline' => $this->offlineLines($add, $values, $url, $robust),
            'assets' => $values['provide'] ? null : $add('Helpers.ini', ['Helpers' => ['AssetsURL' => (string) ($values['assetsUrl'] ?? '')]]),
            default => null,
        };

        return array_map(static fn(string $file, array $sections): array => ['file' => $file, 'text' => self::ini($sections)], array_keys($blocks), array_values($blocks));
    }

    /** The lines of the economy: its address, its module and the settings of the module, the settings of the helpers. */
    private function economyLines(\Closure $add, array $values, string $url, string $robust): void
    {
        $module = (string) $values['economyModule'];
        $money = in_array($module, ['DTLNSLMoneyModule', 'Podex'], true);
        $add($robust, ['GridInfoService' => ['economy' => $url]]);
        ($values['currencySymbol'] ?? '') !== '' && $add($robust, ['LoginService' => ['Currency' => (string) $values['currencySymbol']]]);
        $add('OpenSim.ini', ['Economy' => ['economymodule' => $money ? 'DTLNSLMoneyModule' : $module, 'economy' => $url]]);
        if ($module === 'Gloebit') {
            $add('OpenSim.ini', [
                'Economy' => ['SellEnabled' => 'true'],
                'Gloebit' => ['Enabled' => 'true'] + self::lines(Settings::named('gloebitEnvironment'), $values['gloebitEnvironment'] ?? 'production')
                    + ['GLBKey' => '(your Gloebit app key)', 'GLBSecret' => '(your Gloebit app secret)']
                    + self::lines(Settings::named('gloebitOwnerName'), $values['gloebitOwnerName'] ?? '')
                    + self::lines(Settings::named('gloebitOwnerEmail'), $values['gloebitOwnerEmail'] ?? '')
                    + ($values['gloebitDatabase'] === null ? [] : self::lines(Settings::named('gloebitDatabase'), $values['gloebitDatabase'])),
            ]);
        }
        if ($money) {
            $database = $values['moneyDatabase'] ?? null;
            $add('MoneyServer.ini', [
                'MoneyServer' => ['EnableScriptSendMoney' => 'true', 'MoneyScriptAccessKey' => '(your key)'] + (($values['moneyBanker'] ?? '') !== '' ? ['BankerAvatar' => (string) $values['moneyBanker']] : []),
                'MySql' => self::lines(Settings::named('moneyDatabase'), $database ?? ['host' => 'localhost', 'database' => 'money', 'user' => 'opensim']),
            ]);
        }
        $helpers = in_array($module, ['BetaGridLikeMoneyModule', 'DTLNSLMoneyModule', 'Podex'], true) ? ['CurrencyRate' => (string) $values['currencyRate']] : [];
        $module === 'Podex' && ($helpers += ['CurrencyProvider' => 'podex', 'PodexMessage' => '"' . $values['podexMessage'] . '"', 'PodexUrl' => '"' . $values['podexUrl'] . '"']);
        $helpers === [] || $add('Helpers.ini', ['Helpers' => $helpers]);
    }

    /** The lines of the search: what the simulators call and where they register, the database and the events of the helpers. */
    private function searchLines(\Closure $add, array $values, string $url): void
    {
        $register = $values['provide'] ? [$this->ownRegister() => '"' . $this->baseOf('search') . '/' . ltrim($values['registerPath'], '/') . '"'] : [];
        ($values['include2do'] ?? true) && ($register['DATA_SRV_2do'] = '"' . ($this->twoDoRegister() ?? self::REGISTER_2DO) . '"');
        foreach ($values['registers'] as $url) {
            $register[self::registerKey($url)] = '"' . $url . '"';
        }
        ($values['webSearchUrl'] ?? '') !== '' && $add(Settings::file('robust', $this->config()), ['LoginService' => ['SearchURL' => (string) $values['webSearchUrl']], 'GridInfoService' => ['search' => (string) $values['webSearchUrl']]]);
        $add('OpenSim.ini', [
            'Search' => ['Module' => 'OpenSimSearch', 'SearchURL' => "\"$url\""],
            'DataSnapshot' => ['index_sims' => 'true', 'gridname' => '"' . $this->grid->name() . '"'] + $register,
        ]);
        $values['provide'] && $add('Helpers.ini', ['Helpers' => ['HypEventsURL' => (string) $values['eventsUrl']]]);
    }

    /** The lines of the offline messages: the module chosen, V1 and its address or V2 and the connector of Robust; the sender of the mails. */
    private function offlineLines(\Closure $add, array $values, string $url, string $robust): void
    {
        $v2 = stripos((string) $values['offlineModule'], 'V2') !== false;
        $add('OpenSim.ini', ['Messaging' => $v2
            ? ['OfflineMessageModule' => '"Offline Message Module V2"', 'OfflineMessageURL' => '${Const|PrivateURL}:${Const|PrivatePort}']
            : ['OfflineMessageModule' => 'OfflineMessageModule', 'OfflineMessageURL' => $url]]);
        // V2: the connector of Robust that keeps them, commented in the distribution; V1: the address of the helpers, which relay
        // the messages between the grids that use them too (not a standard key of Robust)
        $v2 ? $add($robust, ['ServiceList' => ['OfflineIMServiceConnector' => '"${Const|PrivatePort}/OpenSim.Addons.OfflineIM.dll:OfflineIMServiceRobustConnector"']])
            : $add($robust, ['GridInfoService' => ['OfflineMessageURL' => $url]]);
        $values['provide'] && $add('Helpers.ini', ['Helpers' => ['MailSender' => (string) $values['mailSender']]]);
        // The database the helpers keep the messages in, when it is not the one of Robust
        $values['provide'] && ($values['offlineDatabase'] ?? null) !== null
            && $add($robust, ['Messaging' => self::lines(Settings::named('offlineDatabase'), $values['offlineDatabase'])]);
    }

    /**
     * The examples a page shows at its bottom, apart from its settings: an example of a list of destinations for the guide.
     *
     * @return array{examples?:list<array{label:string,text:string}>}
     */
    private static function examples(string $page): array
    {
        return $page !== 'guide' ? [] : ['examples' => [['label' => 'guide_example', 'text' => implode("\n", [
            'Section 1', 'Display Name|yourgrid.org:8002/Region Name', 'Display Name|yourgrid.org:8002/Region Name/128/128/22',
            'Section 2', 'Display Name|othergrid.org:8002', 'Display Name|othergrid.org:8002/Region Name/128/128/22',
        ])]]];
    }

    /** @param array<string,array<string,?string>> $sections section => key => value, a key with no value a line of its own */
    private static function ini(array $sections): string
    {
        $text = [];
        foreach ($sections as $section => $keys) {
            $text[] = "[$section]";
            foreach ($keys as $key => $value) {
                $text[] = '    ' . ($value === null ? $key : "$key = $value");
            }
        }

        return implode("\n", $text);
    }

    /** The address of the service of a page: the one the grid sets, else where its helpers answer it on its site; null for none. */
    private function address(string $page): ?string
    {
        $said = $this->read($page, false)[self::PROVIDE[$page]['setting']] ?? null;
        if (is_string($said) && $said !== '') {
            return $said;
        }
        $base = $this->baseOf($page);

        return $base === null ? null : rtrim($base, '/') . '/' . ltrim($this->defaultPath($page), '/');
    }

    /** Where the helpers answer the service of a page, under the site: their base, and the page of the service. */
    private function defaultPath(string $page): string
    {
        $base = rtrim(\W4OS\Engine\Provisioning\Web\Services::pathOf((string) ($this->grid->helpersUrl() ?? '/helpers')), '/');
        if (self::PROVIDE[$page]['prefix'] ?? false) {
            return "$base/";
        }
        $default = (string) (Service::defaultPage(self::PROVIDE[$page]['service']) ?? self::PROVIDE[$page]['service']);
        $url = $this->grid->helperUrl($default);
        if ((self::PROVIDE[$page]['base'] ?? 'site') === 'helpers') {
            return $url !== '' ? $this->pathOf($page, $url) : $default;
        }

        return $url !== '' ? \W4OS\Engine\Provisioning\Web\Services::pathOf($url) : "$base/$default";
    }

    /**
     * The settings of a page.
     *
     * @return list<array<string,mixed>>
     * @throws NotFound for a page there is not
     */
    private static function entries(string $page): array
    {
        isset(self::PAGES[$page]) || throw NotFound::for("settings called '$page'");

        return Settings::page($page);
    }

    /** @param array<string,mixed> $entry */
    private static function fieldType(array $entry): string
    {
        return self::FIELD_TYPES[$entry['type']] ?? $entry['type'];
    }

    /**
     * The value of a setting as the grid says it, else its default, as its type reads; a secret masked.
     *
     * @param array<string,mixed> $entry
     */
    private static function value(array $entry, GridConfig $config): mixed
    {
        if ($entry['prefix'] ?? false) {
            return array_values($config->keys($entry['section'], '/^' . preg_quote($entry['key'], '/') . '/i'));
        }
        if (isset($entry['parts'])) {
            return self::parts($entry, $config);
        }
        $said = $config->find($entry['section'], $entry['key']);
        // Empty, or its quotes only: the file says nothing
        $said = $said === null || trim($said, " \t\"") === '' ? null : trim($said, " \t\"");
        if (($codec = Settings::type($entry['type'])) !== null) {
            $value = $said === null ? null : $codec->decode($said);

            return ($entry['secret'] ?? false) ? $codec->mask($value) : $value;
        }
        if ($entry['secret'] ?? false) {
            return $said === null ? '' : Settings::MASK;
        }
        if ($entry['multiple'] ?? false) {
            // Boxes all unchecked are a choice: none
            if ($entry['type'] === 'checkboxes') {
                $raw = $config->find($entry['section'], $entry['key']);

                return $raw === null ? ($entry['default'] ?? []) : self::list($raw);
            }

            return $said === null ? ($entry['default'] ?? []) : ($entry['type'] === 'avatar' ? array_map('strtolower', self::list($said)) : self::list($said));
        }
        if ($said === null) {
            return $entry['default'] ?? null;
        }

        return match ($entry['type']) {
            'integer' => is_numeric($said) ? (int) $said : $said,
            'float' => is_numeric($said) ? (float) $said : $said,
            'bool' => in_array(strtolower($said), ['true', '1', 'yes', 'on'], true),
            // What OpenSimulator keeps as a URL is shown as the URI people give a viewer
            'login-uri' => Destination::loginUri($said) ?? $said,
            default => $said,
        };
    }

    /**
     * A value as the file keeps it, checked against its setting.
     *
     * @param array<string,mixed> $entry
     * @param ?string $current what the file says now
     */
    private static function checked(array $entry, mixed $value, ?string $current): string
    {
        $name = $entry['name'];
        $refuse = static fn(string $why): never => throw InvalidValue::because('invalid-request', "$why ($name)");
        if (($codec = Settings::type($entry['type'])) !== null) {
            is_array($value) || $refuse('a value of parts is expected');

            return self::quoted($codec->encode($value, $current === null ? null : trim($current, " \t\"")));
        }
        if ($entry['multiple'] ?? false) {
            $list = is_array($value) ? $value : self::list((string) $value);
            // The UUIDs in small letters, the names as they are typed
            $list = array_values(array_unique(array_filter(array_map(static fn($v): string => $entry['type'] === 'avatar' ? strtolower(trim((string) $v)) : trim((string) $v), $list), 'strlen')));
            foreach ($list as $item) {
                $entry['type'] === 'avatar' && !Uuid::isValid($item) && $refuse("'$item' is not the UUID of an avatar");
                isset($entry['options']) && !isset($entry['options'][$item]) && $refuse("'$item' is not a choice (" . implode(', ', array_keys($entry['options'])) . ')');
            }

            return '"' . implode(', ', $list) . '"';
        }
        if ($entry['type'] === 'bool') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        }
        $value = trim((string) (is_scalar($value) ? $value : ''));
        if ($value !== '') {
            match ($entry['type']) {
                'url' => preg_match('#^https?://[^\s"<>/]+(/[^\s"<>]*)?$#i', $value) === 1 || $refuse("'$value' is not an address (https://...)"),
                'login-uri' => preg_match('/^[a-z0-9.-]+(:\d+)?$/i', (string) Destination::loginUri($value)) === 1 || $refuse("'$value' is not the login URI of a grid (host:port)"),
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false || $refuse("'$value' is not an email address"),
                'integer' => preg_match('/^-?\d+$/', $value) === 1 || $refuse("'$value' is not a whole number"),
                'float' => is_numeric($value) || $refuse("'$value' is not a number"),
                default => true,
            };
        }
        isset($entry['options']) && !isset($entry['options'][$value]) && $refuse(
            "'$value' is not a choice (" . implode(', ', array_keys($entry['options'])) . ')',
        );

        return self::quoted(self::kept($entry, $value));
    }

    /**
     * A database a section says in keys of their own (`parts`: the [MySql] of the money server), null when it names none; the
     * password masked.
     *
     * @param array<string,mixed> $entry
     * @return ?array<string,mixed>
     */
    private static function parts(array $entry, GridConfig $config): ?array
    {
        $said = [];
        foreach ($entry['parts'] as $part => $key) {
            $said[$part] = trim((string) $config->find($entry['section'], $key), " \t\"");
        }
        if ($said['database'] === '') {
            return null;
        }
        // The main database of the grid, its keys written for the money server: its default
        $main = \W4OS\Engine\Data\Database::Robust->connection($config);
        if ($main !== null && $main->database === $said['database'] && $main->user === $said['user']
            && strcasecmp($main->host ?: $main->source, $said['host'] ?: 'localhost') === 0 && (int) ($main->port ?? 3306) === (int) ($said['port'] ?: 3306)) {
            return null;
        }

        return [
            'host' => $said['host'] ?: 'localhost',
            'port' => ctype_digit($said['port']) ? (int) $said['port'] : null,
            'database' => $said['database'],
            'user' => $said['user'],
            'password' => $said['password'] !== '' ? Settings::MASK : '',
            'options' => [],
        ];
    }

    /**
     * A value as the file keeps it: the login URI given in any form (host:port, http://, hop://) as the URL OpenSimulator says at
     * get_grid_info and the viewers log in at (`http://host:port/`).
     *
     * @param array<string,mixed> $entry
     */
    private static function kept(array $entry, string $value): string
    {
        return $entry['type'] === 'login-uri' && $value !== '' && ($url = Destination::loginUrl($value)) !== null ? "$url/" : $value;
    }

    /**
     * The lines of a setting to write by hand, by key: its value as the file keeps it, a key or a password named, never given.
     *
     * @param array<string,mixed> $entry
     * @return array<string,string>
     */
    private static function lines(array $entry, mixed $value): array
    {
        $database = is_array($value) ? ['password' => 'your_password'] + $value : ['host' => 'localhost', 'database' => '', 'user' => '', 'password' => 'your_password'];
        if (isset($entry['parts'])) {
            return array_combine(array_values($entry['parts']), array_map(
                static fn(string $part): string => $part === 'password' ? '(your password)' : (string) ($database[$part] ?? ''),
                array_keys($entry['parts']),
            ));
        }
        if (($codec = Settings::type($entry['type'])) !== null) {
            return [$entry['key'] => '"' . $codec->encode(['password' => 'your_password'] + $database) . '"'];
        }
        if ($entry['secret'] ?? false) {
            return [$entry['key'] => '(your key)'];
        }

        return [$entry['key'] => is_array($value) ? implode(', ', array_map('strval', $value)) : self::kept($entry, (string) ($value ?? ''))];
    }

    /**
     * A value of parts as a page sends it, checked.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private static function partsValue(array $entry, mixed $value): array
    {
        is_array($value) || throw InvalidValue::because('invalid-request', "a value of parts is expected ({$entry['name']})");
        isset($value['port']) && $value['port'] !== '' && !ctype_digit((string) $value['port'])
            && throw InvalidValue::because('invalid-request', "'{$value['port']}' is not a port ({$entry['name']})");

        return $value;
    }

    /**
     * The keys a database in parts is written in, from a value of parts: a part not given, or the mask of the password, as it is now.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $value
     * @param \Closure(string):?string $current what a key says now
     * @return array<string,string> by key
     */
    private static function partsOf(array $entry, array $value, \Closure $current): array
    {
        $keys = [];
        foreach ($entry['parts'] as $part => $key) {
            $given = $value[$part] ?? null;
            $keep = $given === null || ($part === 'password' && $given === Settings::MASK);
            $text = $keep ? trim((string) $current($key), " \t\"") : trim((string) $given);
            ($text !== '' || $part === 'password') && ($keys[$key] = self::quoted($text));
        }

        return $keys;
    }

    /** A value as an ini file keeps it: as it is when it is a word, else between quotes. */
    private static function quoted(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_.+-]*$/', $value) === 1 ? $value : '"' . str_replace('"', '', $value) . '"';
    }

    /** @return list<string> the items of a list as the files keep it, separated by commas, spaces or semicolons */
    private static function list(string $text): array
    {
        return array_values(preg_split('/[\s,;]+/', trim($text, " \t\""), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * The file of the grid a setting is written in: the one that says it already, else the file of its instance (the Robust config,
     * the one of a standalone; the OpenSim.ini, the MoneyServer.ini, the Helpers.ini of the grid).
     *
     * @param array<string,mixed> $entry
     */
    private function fileOf(array $entry): string
    {
        $config = $this->config();
        foreach (self::FILES as $role) {
            $file = $config->file($role);
            if ($file !== null && $file !== '' && is_file($file)) {
                $ini = array_change_key_case(ConfigReader::file($file));
                if (array_key_exists(strtolower($entry['key']), array_change_key_case($ini[strtolower($entry['section'])] ?? []))) {
                    return $file;
                }
            }
        }
        $dir = rtrim($this->grid->directory(), '/');

        return match ($entry['instance']) {
            'robust' => ($this->grid->isStandalone() ? "$dir/config-include/StandaloneCommon.ini" : $this->grid->robustIni())
                ?? throw InvalidValue::because('invalid-request', "the grid {$this->grid->nick()} has no Robust config on this machine"),
            'helpers' => HelpersConfig::path($dir),
            default => $dir . '/' . Settings::INSTANCES[$entry['instance']]['file'],
        };
    }

    /** The config of the grid as it reads it: its files on this machine, or the one an application gives (Grid::config()). */
    private function config(): GridConfig
    {
        return $this->grid->config() ?? new GridConfig();
    }
}
