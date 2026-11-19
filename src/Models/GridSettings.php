<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Config\ConfigReader;
use OpenSim\Config\GridConfig;
use OpenSim\Config\IniEditor;
use OpenSim\Config\Settings;
use OpenSim\Exceptions\InvalidValue;
use OpenSim\Exceptions\NotFound;
use OpenSim\Provisioning\Web\HelpersConfig;
use OpenSim\Text\Notice;
use OpenSim\Text\Uuid;

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
        'models' => [
            'title' => 'settings_models',
            'menu' => 'settings_models',
            'description' => ['models_help_choice', 'models_help_create', 'models_help_not_users', 'models_help_outfit', 'models_help_rule'],
            'preview' => ['label' => 'models_preview', 'empty' => 'models_none', 'path' => '/grids/{grid}/avatar-models', 'filters' => ['match', 'pattern', 'list']],
        ],
        'economy' => ['title' => 'settings_economy', 'menu' => 'settings_menu_economy', 'description' => ['economy_help_helpers', 'economy_help_scripts', 'economy_help_module', 'economy_help_modules', 'economy_help_binaries']],
        'search' => ['title' => 'settings_search', 'menu' => 'settings_menu_search', 'description' => ['search_help_local', 'search_help_external', 'search_help_module', 'search_help_binary']],
        'guide' => ['title' => 'settings_guide', 'menu' => 'settings_menu_guide', 'description' => ['guide_help_feature', 'guide_help_places']],
        'offline' => ['title' => 'settings_offline', 'menu' => 'settings_menu_offline'],
        'assets' => ['title' => 'settings_assets', 'menu' => 'settings_menu_assets'],
    ];

    /**
     * The pages of the services of the helpers, as w4os-wp has them: what Provide is the address of (the setting, the service of the
     * helpers it is), its label and help, the block a page of the site shows when the site handles it, and whether the address is
     * the base of the helpers (the economy: currency.php and landtool.php under it).
     */
    private const PROVIDE = [
        'economy' => ['setting' => 'economyUrl', 'service' => 'currency', 'label' => 'provide_economy', 'base' => true, 'url' => 'economy_helper_uri', 'help' => ['economy_helper_uri_help']],
        'search' => ['setting' => 'searchUrl', 'service' => 'query', 'label' => 'provide_search', 'after' => 'searchDatabase', 'url' => 'search_engine_url', 'help' => ['search_url_help_viewer', 'search_url_help_one', 'search_url_help_web']],
        'guide' => ['setting' => 'destinationGuide', 'service' => 'guide', 'label' => 'provide_guide', 'block' => 'destination_guide', 'after' => 'guideSource', 'url' => 'guide_url'],
        'offline' => ['setting' => 'offlineMessageUrl', 'service' => 'offline', 'label' => 'provide_offline', 'description' => ['provide_offline_help', 'provide_offline_help_core'], 'after' => 'mailSender', 'url' => 'offline_helper', 'help' => ['offline_helper_help']],
        'assets' => ['setting' => 'assetsUrl', 'service' => 'assets', 'label' => 'provide_assets', 'description' => ['provide_assets_help'], 'url' => 'assets_url', 'help' => ['provide_path_help'], 'urlHelp' => ['assets_external_help'], 'placeholder' => 'https://example.org/assets', 'required' => true],
    ];

    /** The fields of a page in the order w4os-wp shows them, the address of the service and Provide aside. */
    private const ORDER = [
        'economy' => ['economyModule', 'currencyRate', 'moneyScriptKey', 'podexMessage', 'podexUrl'],
        'search' => ['searchDatabase', 'dataServices', 'eventsUrl'],
        'offline' => ['mailSender', 'offlineModule'],
    ];

    /**
     * What w4os-wp shows of a field on a page that its setting does not say (its label there, its help in points, its placeholder,
     * what it requires, when it is shown), and the notes it shows (`note`: a text, no value), by page and field.
     */
    private const SHOWN_AS = [
        'economy' => [
            'currencyRate' => ['condition' => ['economyModule' => ['BetaGridLikeMoneyModule', 'DTLNSLMoneyModule']]],
            'gloebitConfiguration' => ['note' => true, 'after' => 'currencyRate', 'label' => 'gloebit_configuration', 'description' => ['gloebit_help_crash', 'gloebit_help_linux', 'gloebit_help_register', 'gloebit_help_config', 'gloebit_help_dll'], 'condition' => ['economyModule' => ['Gloebit']]],
            'moneyScriptKey' => ['label' => 'money_script_access_key', 'description' => ['money_script_access_key_help']],
        ],
        'search' => [
            'dataServices' => ['label' => 'search_register_url', 'description' => ['search_register_help_data', 'search_register_help_several', 'search_register_help_prefix']],
            'eventsUrl' => ['description' => ['events_help_fetch', 'events_help_blank'], 'placeholder' => 'http://2do.directory/events/'],
        ],
        'guide' => [
            'guideSource' => ['required' => true, 'description' => ['guide_source_help_file', 'guide_source_help_where', 'guide_source_help_pipe', 'guide_source_help_categories', 'guide_source_help_comments', 'guide_source_help_board']],
        ],
        'offline' => [
            'mailSender' => ['required' => true],
        ],
    ];
    /** The type of a field of the standard format, by type of setting. */
    private const FIELD_TYPES = [
        'text' => 'string',
        'hostname' => 'string',
        'float' => 'number',
        'bool' => 'boolean',
        'password' => 'secret',
    ];

    /** What a field says of its setting, beside its name and its type. */
    private const SHOWN = ['label', 'options', 'widget', 'placeholder', 'condition', 'multiple', 'description', 'readOnly', 'required'];

    /** The roles of the files of the grid, in the order it reads a setting from them (GridConfig::find()). */
    private const FILES = ['Robust', 'OpenSim', 'Helpers', 'MoneyServer'];

    public function __construct(private readonly Grid $grid) {}

    /** What a page of settings is, for the contract. */
    public static function description(string $page): string
    {
        return match ($page) {
            'models' => 'The rule the avatar models are picked by (`[Models]`, the keys of w4os): `match` first (the first name is the pattern), last (the last name), any (either), uuid (the list); `pattern`, the name; `list`, the UUIDs of the models for uuid. The models it picks are the list of `/grids/{grid}/avatar-models`.',
            'economy' => 'The economy of the grid, as w4os-wp has it: the address of its helpers (`economy` of `[GridInfoService]`), its module (set by the options of the grid), the key of the money server, the rate and the settings of Podex the helpers use.',
            'search' => 'The search of the grid, as w4os-wp has it: what the simulators call and where they register (set by the options of the grid), the database of the search, the calendar of the events.',
            'guide' => 'The destinations guide of the grid, as w4os-wp has it: its address (`DestinationGuide` of `[LoginService]`) and its source (`GuideSource` of `[Helpers]`).',
            'offline' => 'The offline messages of the grid, as w4os-wp has them: the module and its address (set by the options of the grid), the sender of the messages forwarded by mail.',
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
                + array_intersect_key($entry, array_flip(self::SHOWN))
                + (($codec = Settings::type($entry['type'])) !== null ? ['fields' => $codec->fields()] : []),
            self::entries($page),
        );
        if (isset(self::PROVIDE[$page])) {
            $fields = self::provided($page, $fields);
        }

        return ['title' => self::PAGES[$page]['title'], 'menu' => self::PAGES[$page]['menu'], 'fields' => $fields, 'submit' => 'action_save_changes', 'saved' => 'settings_saved']
            + array_intersect_key(self::PAGES[$page], ['description' => 1, 'preview' => 1]);
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
        if (!$provide || !isset(self::PROVIDE[$page])) {
            return $values;
        }
        // Provided: no address of its own, or one on the site of the grid
        $url = $values[self::PROVIDE[$page]['setting']];
        $site = $this->grid->site();
        $on = !is_string($url) || $url === '' || ($site !== null && \OpenSim\Provisioning\Web\Services::isUnder($url, $site));
        $path = $on && is_string($url) && $url !== '' ? self::pathAndQuery($url) : $this->defaultPath($page);

        return ['provide' => $on, 'path' => $path] + $values;
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
        isset(self::PROVIDE[$page]) && ($values = $this->addressOf($page, $values));
        $now = $this->read($page);
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
                $value == ($now[$name] ?? null) || throw \OpenSim\Exceptions\ChannelUnavailable::because("change $name of {$this->grid->nick()}", $where['reason']['code'], $where['reason']['params']);
                continue;
            }
            $writes[] = [$entry, $value, $where];
        }
        foreach ($writes as [$entry, $value, $where]) {
            if ($where['store'] ?? false) {
                $store = $this->grid->environment()->settings;
                $current = $store->all()[$entry['section']][$entry['key']] ?? null;
                $store->set($entry['section'], $entry['key'], trim(self::checked($entry, $value, $current), '"'));
                continue;
            }
            $file = $where['file'];
            $ini = is_file($file) ? IniEditor::load($file) : new IniEditor('');
            $ini->set($entry['section'], $entry['key'], self::checked($entry, $value, $ini->get($entry['section'], $entry['key'])));
            $ini->save($file);
        }

        return $this->read($page);
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
            $value = ($entry['secret'] ?? false) ? '(your key)' : (is_array($value) ? implode(', ', array_map('strval', $value)) : (string) ($value ?? ''));
            $line = [
                'reason' => $where['reason'],
                'file' => $where['file'] !== null ? basename($where['file']) : Settings::file($entry['instance'], $config),
                'text' => self::ini([$entry['section'] => [$entry['key'] => $value]]),
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
        $setting = self::PROVIDE[$page]['setting'];
        $given = array_key_exists('provide', $values) || array_key_exists('path', $values);
        $on = !array_key_exists('provide', $values) || filter_var($values['provide'], FILTER_VALIDATE_BOOLEAN);
        unset($values['provide']);
        $path = $values['path'] ?? null;
        unset($values['path']);
        if (!$given) {
            return $values;
        }
        if ($on) {
            $site = $this->grid->site() ?? throw InvalidValue::because(
                'invalid-request',
                "the grid has no web site (WebURL of [Const]) to provide $page on: give the whole address of the service, Provide off",
            );
            $path = trim((string) ($path ?? $this->read($page)['path']));

            return [$setting => $site . '/' . ltrim($path, '/')] + $values;
        }
        trim((string) ($values[$setting] ?? '')) !== '' || throw InvalidValue::because(
            'invalid-request',
            "Provide off needs the whole address of the service ($setting)",
        );

        return $values;
    }

    /**
     * The fields of a page of a service of the helpers: Provide first (on by default), the path under the site when on, the whole
     * address when off, then the others, asked when the helpers provide it (but what the options set, always shown).
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
            ['name' => 'path', 'type' => 'path', 'base' => 'site'] + $words + ['condition' => $on],
        ];
        $others = [];
        foreach ($fields as $field) {
            $more = array_diff_key($shownAs[$field['name']] ?? [], ['condition' => 1]);
            $condition = ($shownAs[$field['name']]['condition'] ?? []) + ($field['condition'] ?? []);
            if ($field['name'] === $provide['setting']) {
                $address[] = ['condition' => ['provide' => [false]], 'label' => $provide['url'], 'description' => $provide['urlHelp'] ?? $provide['help'] ?? []]
                    + array_intersect_key($provide, ['placeholder' => 1, 'required' => 1]) + $field;
                $address[1]['description'] === [] && ($address[1] = array_diff_key($address[1], ['description' => 1]));
                continue;
            }
            $others[] = ($field['readOnly'] ?? false) ? $more + $field : ['condition' => $on + $condition] + $more + $field;
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
        // The address where w4os-wp shows it: after Provide, or after the field it follows
        $at = isset($provide['after']) ? array_search($provide['after'], array_column($others, 'name'), true) : false;
        array_splice($others, $at === false ? 0 : $at + 1, 0, $address);

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
        $under = static fn(?string $base): bool => $url !== null && $base !== null && \OpenSim\Provisioning\Web\Services::isUnder($url, $base);
        $service = new Service($this->grid->environment(), $this->grid, $provide['service']);

        return ($fixed === [] ? [] : ['fixed' => $fixed]) + ['snippets' => $this->snippets($page), 'service' => [
            'key' => $provide['service'],
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
     * The lines of the files of the grid each field of a page shows, as w4os-wp shows them, for the values of this grid: by field, a
     * list of `file` (null for a sample) and `text`. Never a secret: a key, a password are named, not given.
     *
     * @return array<string,list<array{file:?string,text:string}>>
     */
    private function snippets(string $page): array
    {
        $config = $this->config();
        $robust = Settings::file('robust', $config);
        $database = \OpenSim\Data\Database::Robust->connection($config);
        [$host, $name, $user] = $database === null ? ['localhost', 'robust', 'opensim'] : [$database->source, $database->database, $database->user];
        $url = $this->address($page) ?? '';
        $line = static fn(?string $file, array $sections): array => ['file' => $file, 'text' => self::ini($sections)];

        return match ($page) {
            'economy' => [
                'path' => [$line($robust, ['GridInfoService' => ['economy' => $url], 'LoginService' => ['; Currency' => 'YC$ ;; Your Currency symbol, optional']])],
                'gloebitConfiguration' => [
                    $line('OpenSim.ini', [
                        'Economy' => ['economymodule' => 'Gloebit', 'economy' => $url, 'SellEnabled' => 'true', '; PriceUpload' => '0', '; PriceGroupCreate' => '0'],
                        'Gloebit' => [
                            'Enabled' => 'true', 'GLBEnvironment' => 'production', 'GLBKey' => '(your Gloebit app key)', 'GLBSecret' => '(your Gloebit app secret)',
                            'GLBOwnerName' => 'Banker Name', 'GLBOwnerEmail' => 'banker@example.org',
                            'GLBSpecificConnectionString' => "\"Data Source=$host;Database=$name;User ID=$user;Password=your_password;Old Guids=true;\"",
                        ],
                    ]),
                    $line("$robust (optional, for grid-wide support)", ['GridInfoService' => ['economy' => $url], 'LoginService' => ['Currency' => 'G$']]),
                ],
                'moneyScriptKey' => [$line('MoneyServer.ini', [
                    'MoneyServer' => ['EnableScriptSendMoney' => 'true', 'MoneyScriptAccessKey' => '(your key)'],
                    'MySql' => ['hostname' => $host, 'database' => $name, 'username' => $user, 'password' => '(your password)'],
                ])],
            ],
            'search' => $this->searchSnippets($url, $robust, $line),
            'guide' => [
                'guideSource' => [['file' => null, 'text' => implode("\n", [
                    'Section 1', 'Display Name|yourgrid.org:8002/Region Name', 'Display Name|yourgrid.org:8002/Region Name/128/128/22',
                    'Section 2', 'Display Name|othergrid.org:8002', 'Display Name|othergrid.org:8002/Region Name/128/128/22',
                ])]],
                'path' => [$line($robust, ['LoginService' => ['DestinationGuide' => $url]])],
            ],
            'offline' => ['path' => [
                $line('OpenSim.ini', ['Messaging' => ['OfflineMessageModule' => 'OfflineMessageModule', 'OfflineMessageURL' => $url]]),
                $line($robust, ['GridInfoService' => [
                    ';; Optional. To allow different grid to communicate their offline messages service' => null,
                    ';; In previous versions, we recommended the "message" variable to add' => null,
                    ';; the URL in Robust.HG.ini GridInfoService section, but this value seems' => null,
                    ';; to be intended for other purposes, although not enforced (yet?).' => null,
                    'OfflineMessageURL' => $url,
                ]]),
            ]],
            default => [],
        };
    }

    /**
     * The lines of the search: what the simulators call, the web search (another setting of a similar name), the registration.
     *
     * @param \Closure(?string,array):array{file:?string,text:string} $line
     * @return array<string,list<array{file:?string,text:string}>>
     */
    private function searchSnippets(string $url, string $robust, \Closure $line): array
    {
        $web = $this->grid->searchUrl() ?? 'https://example.org/search/';
        $provided = (bool) $this->read('search')['provide'];
        $register = $this->grid->helperUrl('register') ?: 'http://yourgrid.org/helpers/register.php';
        $own = 'DATA_SRV_' . preg_replace('/[^A-Za-z0-9]/', '', ucwords($this->grid->name() ?: 'Your Grid'));

        return [
            'path' => [
                $line('OpenSim.ini', ['Search' => [';; Save your settings before copying the values below' => null, 'Module' => 'OpenSimSearch', 'SearchURL' => "\"$url\""]]),
                $line($robust, ['LoginService' => ['SearchURL' => $web], 'GridInfoService' => ['search' => $web]]),
            ],
            'dataServices' => [$line('OpenSim.ini', ['DataSnapshot' => [
                'index_sims' => 'true',
                'gridname' => '"' . $this->grid->name() . '"',
                ($provided ? '' : '; ') . $own => "\"$register\"",
                ($provided ? '; ' : '') . 'DATA_SRV_2do' => '"http://2do.directory/helpers/register.php"',
                '; DATA_SRV_OtherEngine' => '"http://example.org/register.php"',
            ]])],
        ];
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
        $site = $this->grid->site();

        return $site === null ? null : $site . $this->defaultPath($page);
    }

    /** Where the helpers answer the service of a page, under the site: their base, and the page of the service. */
    private function defaultPath(string $page): string
    {
        $base = rtrim(\OpenSim\Provisioning\Web\Services::pathOf((string) ($this->grid->helpersUrl() ?? '/helpers')), '/');
        if (self::PROVIDE[$page]['base'] ?? false) {
            return "$base/";
        }
        $default = (string) (Service::defaultPage(self::PROVIDE[$page]['service']) ?? self::PROVIDE[$page]['service']);
        $url = $this->grid->helperUrl($default);

        return $url !== '' ? \OpenSim\Provisioning\Web\Services::pathOf($url) : "$base/$default";
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
        $said = $config->find($entry['section'], $entry['key']);
        $said = $said === null || trim($said) === '' ? null : trim($said, " \t\"");
        if (($codec = Settings::type($entry['type'])) !== null) {
            $value = $said === null ? null : $codec->decode($said);

            return ($entry['secret'] ?? false) ? $codec->mask($value) : $value;
        }
        if ($entry['secret'] ?? false) {
            return $said === null ? '' : Settings::MASK;
        }
        if ($entry['multiple'] ?? false) {
            return $said === null ? ($entry['default'] ?? []) : self::list($said);
        }
        if ($said === null) {
            return $entry['default'] ?? null;
        }

        return match ($entry['type']) {
            'integer' => is_numeric($said) ? (int) $said : $said,
            'float' => is_numeric($said) ? (float) $said : $said,
            'bool' => in_array(strtolower($said), ['true', '1', 'yes', 'on'], true),
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
            $list = array_values(array_unique(array_map(static fn($v): string => strtolower(trim((string) $v)), $list)));
            foreach ($list as $item) {
                $entry['type'] === 'avatar' && !Uuid::isValid($item) && $refuse("'$item' is not the UUID of an avatar");
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
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false || $refuse("'$value' is not an email address"),
                'integer' => preg_match('/^-?\d+$/', $value) === 1 || $refuse("'$value' is not a whole number"),
                'float' => is_numeric($value) || $refuse("'$value' is not a number"),
                default => true,
            };
        }
        isset($entry['options']) && !isset($entry['options'][$value]) && $refuse(
            "'$value' is not a choice (" . implode(', ', array_keys($entry['options'])) . ')',
        );

        return self::quoted($value);
    }

    /** A value as an ini file keeps it: as it is when it is a word, else between quotes. */
    private static function quoted(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_.+-]*$/', $value) === 1 ? $value : '"' . str_replace('"', '', $value) . '"';
    }

    /** @return list<string> the items of a list as the files keep it, separated by commas, spaces or semicolons */
    private static function list(string $text): array
    {
        return array_values(array_map('strtolower', preg_split('/[\s,;]+/', trim($text, " \t\""), -1, PREG_SPLIT_NO_EMPTY) ?: []));
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
