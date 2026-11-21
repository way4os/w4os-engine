<?php

declare(strict_types=1);

namespace W4OS\Engine\Config;

use W4OS\Engine\I18n\Catalog;

/**
 * The registry of the settings of a grid: a table of conversion between a setting as the files of OpenSimulator say it (instance >
 * section > key, in the words of the developer of each module) and its clean name in the engine and the API (`name`, camelCase), with
 * what it is. The values stay in their own files (Robust.ini or Robust.HG.ini, OpenSim.ini, MoneyServer.ini, Helpers.ini): the
 * registry says where each is.
 *
 *     Settings::entry('opensim', 'Search', 'SearchURL');   // ['name' => 'searchUrl', 'type' => 'url', 'service' => 'query', ...]
 *     Settings::named('searchUrl');                         // the same
 *     Settings::where('assetsUrl', $config);                // ['file' => 'Helpers.ini', 'section' => 'Helpers', 'key' => 'AssetsURL']
 *
 * An entry: `name` its clean name; `type` (TYPES); `label` the key of its text in the catalog (`<label>_help` its description, when
 * the catalog has one); `default` what it is when the files say nothing; `options` value => key of the text of a choice; `service`
 * the keys of the services whose address it gives (Service), the first setting of a service the one it is set in, the others read
 * after it; `labels` the label of the address of a service when it is not the label of the setting; `names` the services it names
 * without giving their address (the login: Robust serves it); `info` the key of get_grid_info that tells it; `hypergrid` only in
 * Robust.HG.ini; `secret` never shown, masked; `multiple` several values; `prefix` a family of keys that begin with the key (`*`);
 * `admin` the settings page a site edits it on (GridSettings), `readOnly` shown there, changed elsewhere (an option of the grid);
 * `readers` who reads it: `grid` (Robust, the simulators, the money server: its files only), `helpers` (the helpers and the site: the
 * settings the application keeps do, when its files cannot be written; the money key, the helpers tie the quotes with it);
 * `example` a value shown as one in an empty field, never taken (the default is shown as it is); `placeholder` the key of a text
 * shown in an empty field that is neither; `condition` (clean names => the values it is shown for), `widget` as in the standard
 * field format; `health` how
 * the health of the grid asks it (`address` a page, `login-search` the web search of the login, `xmlrpc` the search of the
 * simulators, `register` the data services, `offline` the offline messages, `secret` set or not).
 *
 * What belongs to a site (its pages, its users, its theme) is the site's, never here.
 */
final class Settings
{
    /** What replaces a secret that is set, when it is shown; given back, it keeps the secret as it is. */
    public const MASK = '********';

    /** The types of the settings. */
    public const TYPES = ['text', 'url', 'login-uri', 'hostname', 'integer', 'float', 'bool', 'select', 'checkboxes', 'email', 'password', 'avatar', 'connection-string'];

    /** The instances, the role of their file in the config of a grid (GridConfig) and its name when the grid has none. */
    public const INSTANCES = [
        'robust' => ['role' => 'Robust', 'file' => 'Robust.ini'],
        'opensim' => ['role' => 'OpenSim', 'file' => 'OpenSim.ini'],
        'moneyserver' => ['role' => 'MoneyServer', 'file' => 'MoneyServer.ini'],
        'helpers' => ['role' => 'Helpers', 'file' => 'Helpers.ini'],
    ];

    /** The storage providers of OpenSimulator. */
    private const PROVIDERS = ['OpenSim.Data.MySQL.dll' => 'MySQL', 'OpenSim.Data.PGSQL.dll' => 'PostgreSQL'];

    /** @var array<string,array<string,array<string,array<string,mixed>>>> instance > section > key > entry */
    public const REGISTRY = [
        'robust' => [
            'Const' => [
                'BaseURL' => ['name' => 'baseUrl', 'type' => 'url', 'label' => 'setting_base_url'],
                'BaseHostname' => ['name' => 'baseHostname', 'type' => 'hostname', 'label' => 'setting_base_hostname'],
                'PublicPort' => ['name' => 'publicPort', 'type' => 'integer', 'label' => 'setting_public_port', 'default' => 8002],
                'PrivatePort' => ['name' => 'privatePort', 'type' => 'integer', 'label' => 'setting_private_port', 'default' => 8003],
            ],
            // The remote console of Robust, what drives it
            'Network' => [
                'ConsoleUser' => ['name' => 'consoleUser', 'type' => 'text', 'label' => 'setting_console_user', 'admin' => 'grid'],
                'ConsolePass' => ['name' => 'consolePass', 'type' => 'password', 'label' => 'setting_console_pass', 'secret' => true, 'admin' => 'grid'],
                'ConsolePort' => ['name' => 'consolePort', 'type' => 'integer', 'label' => 'setting_console_port', 'admin' => 'grid'],
            ],
            // The offline messages the helpers keep, in the database of Robust by default
            'Messaging' => [
                'ConnectionString' => ['name' => 'offlineDatabase', 'type' => 'connection-string', 'label' => 'offline_database', 'secret' => true, 'readers' => 'helpers', 'admin' => 'offline', 'fallback' => 'db_use_default'],
            ],
            'DatabaseService' => [
                'StorageProvider' => ['name' => 'storageProvider', 'type' => 'select', 'label' => 'setting_storage_provider', 'options' => self::PROVIDERS, 'default' => 'OpenSim.Data.MySQL.dll'],
                'ConnectionString' => ['name' => 'database', 'type' => 'connection-string', 'label' => 'setting_database', 'secret' => true, 'admin' => 'grid'],
            ],
            'GridInfoService' => [
                'login' => ['name' => 'loginUri', 'type' => 'login-uri', 'label' => 'setting_login', 'info' => 'login', 'names' => ['login'], 'health' => 'address', 'admin' => 'grid'],
                'gatekeeper' => ['name' => 'gatekeeper', 'type' => 'url', 'label' => 'setting_gatekeeper', 'info' => 'gatekeeper', 'hypergrid' => true, 'names' => ['gatekeeper'], 'health' => 'address'],
                'uas' => ['name' => 'userAgentService', 'type' => 'url', 'label' => 'setting_uas', 'info' => 'uas', 'hypergrid' => true, 'names' => ['user-agent']],
                'gridname' => ['name' => 'gridName', 'type' => 'text', 'label' => 'setting_grid_name', 'info' => 'gridname', 'admin' => 'grid'],
                'gridnick' => ['name' => 'gridNick', 'type' => 'text', 'label' => 'setting_grid_nick', 'info' => 'gridnick', 'admin' => 'grid'],
                'welcome' => ['name' => 'welcomePage', 'type' => 'url', 'label' => 'setting_welcome', 'info' => 'welcome', 'service' => 'welcome', 'health' => 'address'],
                'economy' => ['name' => 'economyUrl', 'type' => 'url', 'label' => 'setting_economy', 'info' => 'economy', 'service' => ['currency', 'landtool'], 'labels' => ['currency' => 'service_currency', 'landtool' => 'service_landtool'], 'admin' => 'economy', 'health' => 'address'],
                'about' => ['name' => 'aboutPage', 'type' => 'url', 'label' => 'setting_about', 'info' => 'about', 'health' => 'address'],
                'register' => ['name' => 'registerPage', 'type' => 'url', 'label' => 'setting_register', 'info' => 'register', 'health' => 'address'],
                'help' => ['name' => 'helpPage', 'type' => 'url', 'label' => 'setting_help', 'info' => 'help', 'health' => 'address'],
                'password' => ['name' => 'passwordPage', 'type' => 'url', 'label' => 'setting_password', 'info' => 'password', 'health' => 'address'],
                'GridStatus' => ['name' => 'gridStatusPage', 'type' => 'url', 'label' => 'setting_grid_status', 'info' => 'GridStatus', 'service' => 'grid-status', 'health' => 'address'],
                'GridStatusRSS' => ['name' => 'gridStatusRss', 'type' => 'url', 'label' => 'setting_grid_status_rss', 'info' => 'GridStatusRSS', 'service' => 'grid-status-rss', 'health' => 'address'],
                'web_profile_url' => ['name' => 'webProfileUrl', 'type' => 'url', 'label' => 'setting_web_profile', 'info' => 'web_profile_url', 'service' => 'web-profile', 'health' => 'address', 'admin' => 'grid'],
                'search' => ['name' => 'webSearchUrl', 'type' => 'url', 'label' => 'setting_web_search', 'info' => 'search', 'service' => 'search', 'health' => 'address', 'admin' => 'search'],
            ],
            // Where an old config gave the economy
            'Economy' => [
                'economy' => ['name' => 'robustEconomyUrl', 'type' => 'url', 'label' => 'setting_economy', 'service' => ['currency', 'landtool'], 'labels' => ['currency' => 'service_currency', 'landtool' => 'service_landtool']],
            ],
            'LoginService' => [
                'DestinationGuide' => ['name' => 'destinationGuide', 'type' => 'url', 'label' => 'setting_destination_guide', 'service' => 'guide', 'admin' => 'guide', 'health' => 'address'],
                'MessageUrl' => ['name' => 'messageUrl', 'type' => 'url', 'label' => 'setting_message_url', 'service' => 'motd', 'health' => 'address'],
                'SearchURL' => ['name' => 'loginSearchUrl', 'type' => 'url', 'label' => 'setting_web_search', 'service' => 'search', 'health' => 'login-search'],
                'WelcomeMessage' => ['name' => 'welcomeMessage', 'type' => 'text', 'label' => 'setting_welcome_message'],
                'Currency' => ['name' => 'currencySymbol', 'type' => 'text', 'label' => 'setting_currency_symbol', 'example' => 'YC$', 'admin' => 'economy'],
            ],
        ],
        'opensim' => [
            // The services of Robust the simulators call
            'AssetService' => ['AssetServerURI' => ['name' => 'assetServerUri', 'type' => 'url', 'label' => 'service_asset', 'service' => 'asset', 'admin' => 'grid', 'fallback' => 'db_use_default']],
            'InventoryService' => ['InventoryServerURI' => ['name' => 'inventoryServerUri', 'type' => 'url', 'label' => 'service_inventory', 'service' => 'inventory']],
            'GridService' => ['GridServerURI' => ['name' => 'gridServerUri', 'type' => 'url', 'label' => 'service_grid', 'service' => 'grid', 'admin' => 'grid']],
            'EstateService' => ['EstateServerURI' => ['name' => 'estateServerUri', 'type' => 'url', 'label' => 'service_estate', 'service' => 'estate']],
            'AuthenticationService' => ['AuthenticationServerURI' => ['name' => 'authenticationServerUri', 'type' => 'url', 'label' => 'service_authentication', 'service' => 'authentication']],
            'AvatarService' => ['AvatarServerURI' => ['name' => 'avatarServerUri', 'type' => 'url', 'label' => 'service_avatar', 'service' => 'avatar']],
            'AgentPreferencesService' => ['AgentPreferencesServerURI' => ['name' => 'agentPreferencesServerUri', 'type' => 'url', 'label' => 'service_agent_preferences', 'service' => 'agent-preferences']],
            'PresenceService' => ['PresenceServerURI' => ['name' => 'presenceServerUri', 'type' => 'url', 'label' => 'service_presence', 'service' => 'presence']],
            'UserAccountService' => ['UserAccountServerURI' => ['name' => 'userAccountServerUri', 'type' => 'url', 'label' => 'service_user_account', 'service' => 'user-account']],
            'GridUserService' => ['GridUserServerURI' => ['name' => 'gridUserServerUri', 'type' => 'url', 'label' => 'service_grid_user', 'service' => 'grid-user']],
            'FriendsService' => ['FriendsServerURI' => ['name' => 'friendsServerUri', 'type' => 'url', 'label' => 'service_friends', 'service' => 'friends']],
            'MapImageService' => ['MapImageServerURI' => ['name' => 'mapImageServerUri', 'type' => 'url', 'label' => 'service_map_image', 'service' => 'map-image']],
            'MuteListService' => ['MuteListServerURI' => ['name' => 'muteListServerUri', 'type' => 'url', 'label' => 'service_mute_list', 'service' => 'mute-list']],
            'XBakes' => ['URL' => ['name' => 'bakesUrl', 'type' => 'url', 'label' => 'service_bakes', 'service' => 'bakes']],
            'Groups' => ['GroupsServerURI' => ['name' => 'groupsServerUri', 'type' => 'url', 'label' => 'service_groups', 'service' => 'groups']],
            'UserProfiles' => ['ProfileServiceURL' => ['name' => 'profileServiceUrl', 'type' => 'url', 'label' => 'service_profile', 'service' => 'profile', 'admin' => 'grid', 'fallback' => 'db_use_default']],
            'Search' => [
                'Module' => ['name' => 'searchModule', 'type' => 'text', 'label' => 'setting_search_module', 'default' => 'OpenSimSearch'],
                'SearchURL' => ['name' => 'searchUrl', 'type' => 'url', 'label' => 'setting_search_service', 'service' => 'query', 'admin' => 'search', 'health' => 'xmlrpc'],
            ],
            'DataSnapshot' => [
                'index_sims' => ['name' => 'indexSims', 'type' => 'bool', 'label' => 'setting_index_sims'],
                'gridname' => ['name' => 'snapshotGridName', 'type' => 'text', 'label' => 'setting_grid_name'],
                'DATA_SRV_' => ['name' => 'dataServices', 'type' => 'url', 'label' => 'setting_data_service', 'service' => 'register', 'prefix' => true, 'multiple' => true, 'readOnly' => true, 'health' => 'register'],
            ],
            'Messaging' => [
                'OfflineMessageModule' => ['name' => 'offlineModule', 'type' => 'select', 'label' => 'setting_offline_module', 'options' => ['OfflineMessageModule' => 'offline_module_v1', 'Offline Message Module V2' => 'offline_module_v2'], 'admin' => 'offline'],
                'OfflineMessageURL' => ['name' => 'offlineMessageUrl', 'type' => 'url', 'label' => 'setting_offline_messages', 'service' => 'offline', 'admin' => 'offline', 'health' => 'offline'],
            ],
            'Economy' => [
                'economymodule' => ['name' => 'economyModule', 'type' => 'select', 'label' => 'setting_economy_module', 'options' => ['BetaGridLikeMoneyModule' => 'economy_module_free', 'DTLNSLMoneyModule' => 'economy_module_moneyserver', 'Gloebit' => 'economy_module_gloebit', 'Podex' => 'economy_module_podex'], 'default' => 'BetaGridLikeMoneyModule', 'admin' => 'economy'],
                'SellEnabled' => ['name' => 'sellEnabled', 'type' => 'bool', 'label' => 'setting_sell_enabled', 'default' => true],
                'PriceUpload' => ['name' => 'priceUpload', 'type' => 'integer', 'label' => 'setting_price_upload', 'default' => 0],
                'PriceGroupCreate' => ['name' => 'priceGroupCreate', 'type' => 'integer', 'label' => 'setting_price_group_create', 'default' => 0],
            ],
            'Gloebit' => [
                'Enabled' => ['name' => 'gloebitEnabled', 'type' => 'bool', 'label' => 'setting_gloebit_enabled', 'default' => false],
                'GLBSpecificConnectionString' => ['name' => 'gloebitDatabase', 'type' => 'connection-string', 'label' => 'economy_database', 'secret' => true, 'admin' => 'economy', 'fallback' => 'db_use_default', 'condition' => ['economyModule' => ['Gloebit']]],
                'GLBEnvironment' => ['name' => 'gloebitEnvironment', 'type' => 'select', 'label' => 'setting_gloebit_environment', 'options' => ['sandbox' => 'gloebit_sandbox', 'production' => 'gloebit_production'], 'admin' => 'economy', 'condition' => ['economyModule' => ['Gloebit']]],
                'GLBKey' => ['name' => 'gloebitKey', 'type' => 'password', 'label' => 'setting_gloebit_key', 'secret' => true, 'admin' => 'economy', 'condition' => ['economyModule' => ['Gloebit']]],
                'GLBSecret' => ['name' => 'gloebitSecret', 'type' => 'password', 'label' => 'setting_gloebit_secret', 'secret' => true, 'admin' => 'economy', 'condition' => ['economyModule' => ['Gloebit']]],
                'GLBOwnerName' => ['name' => 'gloebitOwnerName', 'type' => 'text', 'label' => 'setting_gloebit_owner_name', 'example' => 'Banker Name', 'admin' => 'economy', 'condition' => ['economyModule' => ['Gloebit']]],
                'GLBOwnerEmail' => ['name' => 'gloebitOwnerEmail', 'type' => 'email', 'label' => 'setting_gloebit_owner_email', 'example' => 'banker@example.org', 'admin' => 'economy', 'condition' => ['economyModule' => ['Gloebit']]],
            ],
        ],
        'moneyserver' => [
            // Its database in parts of their own, the keys of [MySql] by part
            'MySql' => [
                'database' => ['name' => 'moneyDatabase', 'type' => 'connection-string', 'label' => 'economy_database', 'secret' => true, 'admin' => 'economy', 'parts' => ['host' => 'hostname', 'port' => 'port', 'database' => 'database', 'user' => 'username', 'password' => 'password'], 'fallback' => 'db_use_default', 'condition' => ['economyModule' => ['DTLNSLMoneyModule', 'Podex']]],
            ],
            'MoneyServer' => [
                // The avatar that can create money: it buys it from the system at no cost (zeros: every avatar, empty: nobody)
                'BankerAvatar' => ['name' => 'moneyBanker', 'type' => 'text', 'label' => 'setting_money_banker', 'example' => '00000000-0000-0000-0000-000000000000', 'admin' => 'economy', 'condition' => ['economyModule' => ['DTLNSLMoneyModule', 'Podex']]],
                'MoneyScriptAccessKey' => ['name' => 'moneyScriptKey', 'type' => 'password', 'label' => 'setting_money_script_key', 'secret' => true, 'readers' => 'helpers', 'admin' => 'economy', 'condition' => ['economyModule' => ['DTLNSLMoneyModule', 'Podex']], 'health' => 'secret'],
            ],
        ],
        'helpers' => [
            'Helpers' => [
                'HelpersURL' => ['name' => 'helpersUrl', 'type' => 'url', 'label' => 'setting_helpers'],
                'AssetsURL' => ['name' => 'assetsUrl', 'type' => 'url', 'label' => 'setting_assets_url', 'service' => 'assets', 'labels' => ['assets' => 'service_assets'], 'admin' => 'assets', 'example' => 'https://example.org/assets'],
                'DirectoryURL' => ['name' => 'directoryUrl', 'type' => 'url', 'label' => 'setting_directory', 'service' => 'directory'],
                'GridStatusURL' => ['name' => 'gridStatusUrl', 'type' => 'url', 'label' => 'setting_grid_status', 'service' => 'grid-status'],
                'GuideSource' => ['name' => 'guideSource', 'type' => 'text', 'label' => 'setting_guide_source', 'admin' => 'guide', 'default' => 'https://2do.directory/destinations/guide'],
                'SearchConnectionString' => ['name' => 'searchDatabase', 'type' => 'connection-string', 'label' => 'setting_search_database', 'secret' => true, 'admin' => 'search', 'fallback' => 'db_use_default'],
                'HypEventsURL' => ['name' => 'eventsUrl', 'type' => 'url', 'label' => 'setting_events_url', 'admin' => 'search', 'default' => \W4OS\Engine\Services\SearchTasks::EVENTS_SOURCE],
                // The search engine of another server, kept for when the helpers do not provide the search
                'ExternalSearchURL' => ['name' => 'searchExternalUrl', 'type' => 'url', 'label' => 'search_engine_url', 'default' => 'http://2do.directory/helpers/query.php', 'admin' => 'search'],
                'LogoURL' => ['name' => 'logoUrl', 'type' => 'url', 'label' => 'setting_logo_url'],
                'MailSender' => ['name' => 'mailSender', 'type' => 'email', 'label' => 'setting_mail_sender', 'admin' => 'offline', 'example' => 'no-reply@example.org'],
                'CurrencyRate' => ['name' => 'currencyRate', 'type' => 'float', 'label' => 'setting_currency_rate', 'default' => 10.0, 'admin' => 'economy', 'condition' => ['economyModule' => ['Gloebit', 'DTLNSLMoneyModule']]],
                // The currency provider the helpers serve with the generic money server: podex, else none
                'CurrencyProvider' => ['name' => 'currencyProvider', 'type' => 'text', 'label' => 'setting_currency_provider'],
                'CurrencyRatePer' => ['name' => 'currencyRatePer', 'type' => 'float', 'label' => 'setting_currency_rate_per', 'default' => 1000.0],
                'GloebitConversionThreshold' => ['name' => 'gloebitThreshold', 'type' => 'float', 'label' => 'setting_gloebit_threshold', 'default' => 1.2],
                'PodexMessage' => ['name' => 'podexMessage', 'type' => 'text', 'label' => 'setting_podex_message', 'default' => \W4OS\Engine\Economy\CurrencySettings::PODEX_MESSAGE, 'admin' => 'economy', 'condition' => ['economyModule' => ['Podex']]],
                'PodexUrl' => ['name' => 'podexUrl', 'type' => 'text', 'label' => 'setting_podex_url', 'example' => 'secondlife://Podex Exchange/128/128/21', 'required' => true, 'admin' => 'economy', 'condition' => ['economyModule' => ['Podex']]],
            ],
            // What the numbers of the grid leave out (Stats::of())
            'Stats' => [
                'Exclude' => ['name' => 'excludeFromStats', 'type' => 'checkboxes', 'label' => 'setting_exclude_from_stats', 'multiple' => true, 'options' => ['models' => 'exclude_models', 'nomail' => 'exclude_nomail', 'hypergrid' => 'exclude_hypergrid'], 'default' => \W4OS\Engine\Data\Stats::EXCLUDED, 'admin' => 'grid'],
            ],
            // Checked when an avatar is made (Avatar::check())
            'Avatars' => [
                'AllowMultipleAvatars' => ['name' => 'allowMultipleAvatars', 'type' => 'bool', 'label' => 'setting_allow_multiple_avatars', 'default' => true, 'admin' => 'avatars'],
                'ReservedNames' => ['name' => 'reservedNames', 'type' => 'text', 'label' => 'setting_reserved_names', 'multiple' => true, 'default' => \W4OS\Engine\Models\Avatar::RESERVED_NAMES, 'admin' => 'avatars'],
            ],
            'Models' => [
                'Match' => ['name' => 'match', 'type' => 'select', 'label' => 'models_match', 'widget' => 'radio', 'options' => ['first' => 'models_match_first', 'any' => 'models_match_any', 'last' => 'models_match_last', 'uuid' => 'models_match_uuid'], 'default' => 'any', 'admin' => 'models'],
                'MatchPattern' => ['name' => 'pattern', 'type' => 'text', 'label' => 'models_pattern', 'default' => 'Default', 'condition' => ['match' => ['first', 'any', 'last']], 'admin' => 'models'],
                'List' => ['name' => 'list', 'type' => 'avatar', 'label' => 'models_list', 'multiple' => true, 'placeholder' => 'models_list_placeholder', 'default' => [], 'condition' => ['match' => ['uuid']], 'admin' => 'models'],
            ],
        ],
    ];

    /** @var ?list<array<string,mixed>> */
    private static ?array $entries = null;

    /**
     * Every setting, in the order of the registry: its entry with its `instance`, `section`, `key` and `description`.
     *
     * @return list<array<string,mixed>>
     */
    public static function entries(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }
        $entries = [];
        foreach (self::REGISTRY as $instance => $sections) {
            foreach ($sections as $section => $keys) {
                foreach ($keys as $key => $entry) {
                    $description = $entry['label'] . '_help';
                    $entries[] = ['instance' => $instance, 'section' => $section, 'key' => $key] + $entry
                        + ['readers' => $instance === 'helpers' ? 'helpers' : 'grid']
                        + (isset(Catalog::MESSAGES[$description]) ? ['description' => $description] : []);
                }
            }
        }

        return self::$entries = $entries;
    }

    /** The setting a file says at that section and key, whatever their case (a key of a family by its prefix), null for none. */
    public static function entry(string $instance, string $section, string $key): ?array
    {
        foreach (self::entries() as $entry) {
            if ($entry['instance'] !== $instance || strcasecmp($entry['section'], $section) !== 0) {
                continue;
            }
            $same = ($entry['prefix'] ?? false)
                ? strncasecmp($key, $entry['key'], strlen($entry['key'])) === 0
                : strcasecmp($entry['key'], $key) === 0;
            if ($same) {
                return $entry;
            }
        }

        return null;
    }

    /** The setting of a clean name, null for none. */
    public static function named(string $name): ?array
    {
        foreach (self::entries() as $entry) {
            if ($entry['name'] === $name) {
                return $entry;
            }
        }

        return null;
    }

    /** The setting that gives the address of a service (a key of Service), null when no setting does. */
    public static function forService(string $service): ?array
    {
        return self::ofService($service)[0] ?? null;
    }

    /**
     * The settings that give the address of a service, in the order they are read: the one it is set in, then the others.
     *
     * @return list<array<string,mixed>>
     */
    public static function ofService(string $service): array
    {
        return array_values(array_filter(self::entries(), static fn(array $entry): bool => in_array($service, (array) ($entry['service'] ?? []), true)));
    }

    /** The label of the address of a service: the one of its setting, or of the setting that names it; null for none. */
    public static function serviceLabel(string $service): ?string
    {
        $entry = self::forService($service);
        if ($entry !== null) {
            return $entry['labels'][$service] ?? $entry['label'];
        }
        foreach (self::entries() as $entry) {
            if (in_array($service, $entry['names'] ?? [], true)) {
                return $entry['label'];
            }
        }

        return null;
    }

    /**
     * The settings a settings page edits, in their order.
     *
     * @return list<array<string,mixed>>
     */
    public static function page(string $page): array
    {
        return array_values(array_filter(self::entries(), static fn(array $entry): bool => ($entry['admin'] ?? null) === $page));
    }

    /** The name of the file of an instance for a grid: the one its config has (Robust.HG.ini), else the usual one. */
    public static function file(string $instance, ?GridConfig $config = null): string
    {
        $file = $config?->file(self::INSTANCES[$instance]['role']);

        return $file !== null && $file !== '' ? basename($file) : self::INSTANCES[$instance]['file'];
    }

    /**
     * Where a setting is, for a grid: its file, its section, its key.
     *
     * @return ?array{file:string,section:string,key:string}
     */
    public static function where(string $name, ?GridConfig $config = null): ?array
    {
        $entry = self::named($name);

        return $entry === null ? null : ['file' => self::file($entry['instance'], $config), 'section' => $entry['section'], 'key' => $entry['key']];
    }

    /** The codec of a type that is more than a text, null for the others. */
    public static function type(string $type): ?SettingType
    {
        return match ($type) {
            'connection-string' => new ConnectionStringType(),
            default => null,
        };
    }
}
