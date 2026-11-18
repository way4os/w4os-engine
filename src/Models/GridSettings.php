<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Config\ConfigReader;
use OpenSim\Config\GridConfig;
use OpenSim\Config\IniEditor;
use OpenSim\Exceptions\InvalidValue;
use OpenSim\Exceptions\NotFound;
use OpenSim\Text\Uuid;

/**
 * The settings of a grid a site shows and changes, by group, each a form (`x-form` of the contract): its fields in the standard
 * field format, each the key of a section of the files of the grid it is kept in. A setting is written in the file of the grid that
 * says it already (Robust, OpenSim.ini, Helpers.ini, in the order the grid reads them), else in its Helpers.ini; what a site sends is
 * checked against the choices of its field.
 *
 *     $settings = new GridSettings($grid);
 *     $settings->read('models');                        // ['match' => 'any', 'pattern' => 'Default', 'list' => []]
 *     $settings->write('models', ['match' => 'uuid', 'list' => ['<uuid>']]);
 *
 * Groups: `models`, the rule of the avatar models (AvatarModel, the keys of w4os).
 */
final class GridSettings
{
    /**
     * The groups, their title and their fields: `name`, `label`, `type`, `options` (value => label), `condition` (the values of
     * other fields it is shown for), `multiple` (several values, kept as a list), `description`; `section` and `key` where the
     * grid keeps it, `default` what it is when the grid says nothing.
     */
    private const GROUPS = [
        'models' => [
            'title' => 'settings_models',
            'fields' => [
                [
                    'name' => 'match',
                    'label' => 'models_match',
                    'type' => 'select',
                    'options' => ['first' => 'models_match_first', 'last' => 'models_match_last', 'any' => 'models_match_any', 'uuid' => 'models_match_uuid'],
                    'description' => 'models_match_help',
                    'section' => 'Models',
                    'key' => 'Match',
                    'default' => 'any',
                ],
                [
                    'name' => 'pattern',
                    'label' => 'models_pattern',
                    'type' => 'string',
                    'condition' => ['match' => ['first', 'last', 'any']],
                    'description' => 'models_pattern_help',
                    'section' => 'Models',
                    'key' => 'MatchPattern',
                    'default' => 'Default',
                ],
                [
                    'name' => 'list',
                    'label' => 'models_list',
                    'type' => 'avatar',
                    'multiple' => true,
                    'condition' => ['match' => ['uuid']],
                    'description' => 'models_list_help',
                    'section' => 'Models',
                    'key' => 'List',
                    'default' => [],
                ],
            ],
        ],
    ];

    /** The roles of the files of the grid, in the order it reads a setting from them (GridConfig::find()). */
    private const FILES = ['Robust', 'OpenSim', 'Helpers'];

    public function __construct(private readonly Grid $grid) {}

    /** What a group of settings is, for the contract. */
    public static function description(string $group): string
    {
        return match ($group) {
            'models' => 'The rule the avatar models are picked by (`[Models]`, the keys of w4os): `match` first (the first name is the pattern), last (the last name), any (either), uuid (the list); `pattern`, the name; `list`, the UUIDs of the models for uuid. The models it picks are the list of `/grids/{grid}/avatar-models`.',
            default => '',
        };
    }

    /** @return list<string> the groups of settings */
    public static function groups(): array
    {
        return array_keys(self::GROUPS);
    }

    /**
     * The form of a group, as the contract gives it (`x-form`): its title, its fields without where they are kept, its submit.
     *
     * @return array{title:string,fields:list<array<string,mixed>>,submit:string}
     */
    public static function form(string $group): array
    {
        $fields = array_map(
            static fn(array $field): array => array_diff_key($field, ['section' => 1, 'key' => 1, 'default' => 1]),
            self::fields($group),
        );

        return ['title' => self::GROUPS[$group]['title'], 'fields' => $fields, 'submit' => 'action_save'];
    }

    /**
     * The settings of a group, as the grid says them, else their defaults.
     *
     * @return array<string,mixed>
     */
    public function read(string $group): array
    {
        $config = $this->config();
        $values = [];
        foreach (self::fields($group) as $field) {
            $said = $config->find($field['section'], $field['key']);
            $values[$field['name']] = ($field['multiple'] ?? false)
                ? self::list((string) ($said ?? ''))
                : ($said === null || trim($said) === '' ? $field['default'] : trim($said));
        }

        return $values;
    }

    /**
     * Change settings of a group: the ones given, checked; each written in the file of the grid that says it, else its Helpers.ini.
     *
     * @param array<string,mixed> $values by name
     * @return array<string,mixed> the settings of the group now
     * @throws InvalidValue for a value its field does not take (`invalid-request`)
     */
    public function write(string $group, array $values): array
    {
        $fields = array_column(self::fields($group), null, 'name');
        $writes = [];
        foreach ($values as $name => $value) {
            $field = $fields[$name] ?? throw InvalidValue::because('invalid-request', "'$name' is not a setting of $group (" . implode(', ', array_keys($fields)) . ')');
            $writes[] = [$field, self::checked($field, $value)];
        }
        foreach ($writes as [$field, $value]) {
            $file = $this->fileOf($field['section'], $field['key']);
            $ini = IniEditor::load($file);
            $ini->set($field['section'], $field['key'], $value);
            file_put_contents($file, $ini->toString());
        }

        return $this->read($group);
    }

    /**
     * The fields of a group.
     *
     * @return list<array<string,mixed>>
     * @throws NotFound for a group there is not
     */
    private static function fields(string $group): array
    {
        return self::GROUPS[$group]['fields'] ?? throw NotFound::for("settings called '$group'");
    }

    /**
     * A value as the file keeps it, checked against its field.
     *
     * @param array<string,mixed> $field
     */
    private static function checked(array $field, mixed $value): string
    {
        if ($field['multiple'] ?? false) {
            $list = is_array($value) ? $value : self::list((string) $value);
            $list = array_values(array_unique(array_map(static fn($v): string => strtolower(trim((string) $v)), $list)));
            foreach ($list as $item) {
                $field['type'] === 'avatar' && !Uuid::isValid($item) && throw InvalidValue::because('invalid-request', "'$item' is not the UUID of an avatar ({$field['name']})");
            }

            return '"' . implode(', ', $list) . '"';
        }
        $value = trim((string) (is_scalar($value) ? $value : ''));
        isset($field['options']) && !isset($field['options'][$value]) && throw InvalidValue::because(
            'invalid-request',
            "'$value' is not a choice of {$field['name']} (" . implode(', ', array_keys($field['options'])) . ')',
        );

        return preg_match('/^[A-Za-z0-9_.+-]*$/', $value) === 1 ? $value : '"' . str_replace('"', '', $value) . '"';
    }

    /** @return list<string> the items of a list as the files keep it, separated by commas, spaces or semicolons */
    private static function list(string $text): array
    {
        return array_values(array_map('strtolower', preg_split('/[\s,;]+/', trim($text, " \t\""), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    /** The file of the grid a setting is written in: the one that says it already, else its Helpers.ini. */
    private function fileOf(string $section, string $key): string
    {
        $config = $this->config();
        foreach (self::FILES as $role) {
            $file = $config->file($role);
            if ($file !== null && $file !== '' && is_file($file)) {
                $ini = array_change_key_case(ConfigReader::file($file));
                if (array_key_exists(strtolower($key), array_change_key_case($ini[strtolower($section)] ?? []))) {
                    return $file;
                }
            }
        }

        return rtrim($this->grid->directory(), '/') . '/Helpers.ini';
    }

    private function config(): GridConfig
    {
        return GridConfig::fromFolder($this->grid->directory(), ['Helpers' => 'Helpers.ini']);
    }
}
