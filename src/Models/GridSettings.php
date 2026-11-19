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
use OpenSim\Text\Uuid;

/**
 * The settings of a grid a site shows and changes, by page, each a form (`x-form` of the contract): the settings of the registry
 * (Settings) whose `admin` is that page, in their order, in the standard field format, by their clean name. A setting is written in
 * the file of the grid that says it already (Robust, OpenSim.ini, Helpers.ini, MoneyServer.ini, in the order the grid reads them),
 * else in the file of its instance; what a site sends is checked against its field. What the options of the grid set is shown, not
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
        'economy' => ['title' => 'settings_economy', 'menu' => 'settings_menu_economy'],
        'search' => ['title' => 'settings_search', 'menu' => 'settings_menu_search'],
        'guide' => ['title' => 'settings_guide', 'menu' => 'settings_menu_guide'],
        'offline' => ['title' => 'settings_offline', 'menu' => 'settings_menu_offline'],
        'assets' => ['title' => 'settings_assets', 'menu' => 'settings_menu_assets'],
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
    private const SHOWN = ['label', 'options', 'widget', 'placeholder', 'condition', 'multiple', 'description', 'readOnly'];

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

        return ['title' => self::PAGES[$page]['title'], 'menu' => self::PAGES[$page]['menu'], 'fields' => $fields, 'submit' => 'action_save_changes', 'saved' => 'settings_saved']
            + array_intersect_key(self::PAGES[$page], ['description' => 1, 'preview' => 1]);
    }

    /**
     * The settings of a page, as the grid says them, else their defaults; a secret masked.
     *
     * @return array<string,mixed>
     */
    public function read(string $page): array
    {
        $config = $this->config();
        $values = [];
        foreach (self::entries($page) as $entry) {
            $values[$entry['name']] = self::value($entry, $config);
        }

        return $values;
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
        in_array('files', $this->grid->access(), true) || throw \OpenSim\Exceptions\ChannelUnavailable::for(
            "change the settings of {$this->grid->nick()}",
            'the grid has no files on this machine: its settings are changed where its config is kept',
        );
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
            $writes[] = [$entry, $value];
        }
        foreach ($writes as [$entry, $value]) {
            $file = $this->fileOf($entry);
            $ini = is_file($file) ? IniEditor::load($file) : new IniEditor('');
            $ini->set($entry['section'], $entry['key'], self::checked($entry, $value, $ini->get($entry['section'], $entry['key'])));
            $ini->save($file);
        }

        return $this->read($page);
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
