<?php

declare(strict_types=1);

namespace OpenSim\Data;

use OpenSim\Exceptions\InvalidValue;
use OpenSim\I18n\Catalog;

/**
 * What the list of a model shows, as the model tells it: its columns in their order, the filters and their values, what its search
 * looks in, its default sort and the actions of a row. The labels are keys of the catalog (Catalog::MESSAGES), so what describes a
 * list stays the same in every language; whoever shows it translates them.
 *
 * The contract gives it as `x-list` on the list operation (document()); the CSV of a list takes its columns (columns()).
 *
 *     new Listing(
 *         [Listing::column('name', 'avatar_name', sort: 'name'), Listing::column('created', 'created', 'date', 'created')],
 *         [Listing::filter('online', 'online')],
 *         search: ['name'],
 *     );
 */
final class Listing
{
    /**
     * How a column shows its value:
     * - `text`, `email`, `number`: as it is;
     * - `date`: a Unix time, shown as a day;
     * - `datetime`: a Unix time, shown with its time (when it was created, last seen);
     * - `boolean`: yes or no;
     * - `picture`, `map`: the UUID of an asset, shown as an image (`/grids/{grid}/assets/{uuid}.jpg`), `map` being the map tile of
     *   a region;
     * - `link`: the text of the column, linked to the address the attribute named by `link` gives;
     * - `hop`: a hop:// address, shown as a teleport link;
     * - `size`: a size in bytes, or width x height in meters;
     * - `status`, `role`: a word of the API, whose text is its key in the catalog (`status_` or `role_` and the word, - as _);
     * - `list`: a list of words;
     * - `indicators`: icons in one column, one for each of its `indicators` (indicator()), each showing the state of an attribute
     *   of the row and, when it carries actions, the button that changes it.
     */
    public const TYPES = [
        'text',
        'email',
        'number',
        'date',
        'datetime',
        'boolean',
        'picture',
        'map',
        'link',
        'hop',
        'size',
        'status',
        'role',
        'list',
        'indicators',
    ];

    /**
     * The icons of the indicators, by what they mean: a site gives each the icon of its own library. `power` enabled or not, `run`
     * running or not, `online` in the world (a globe with a check), `offline` out of it (a crossed globe), `console` a remote console, `screen` a screen session, `file` a config file,
     * `files` config files, `database` a database, `server` a server or its config, `account` an account, `account-online` one in the world (a user with a check), `account-banned` a banned one,
     * `ban` the sign of a ban, `play` and `pause` running or not (a state may have its own icon).
     *
     * A difference between states that matters changes the icon or the text, never the tone alone.
     */
    public const ICONS = ['power', 'run', 'play', 'pause', 'online', 'offline', 'console', 'screen', 'file', 'files', 'database', 'server', 'account', 'account-online', 'account-banned', 'ban'];

    /**
     * The tones of the states of an indicator: `ok` (green: all is well), `off` (grey: off as it normally is), `warning` (orange: a
     * part of it is kept out, a ban of some estates), `failed` (red: an error, a hard ban).
     */
    public const TONES = ['ok', 'off', 'warning', 'failed'];

    /**
     * The types of a column whose value reads without its label (an email, a role, a picture...): the label of the others comes
     * before their value where a list folds them under the name (label_value). A link to a teleport reads alone too.
     */
    public const BARE = ['email', 'role', 'status', 'size', 'picture', 'map', 'boolean', 'hop', 'indicators'];

    /** The values of a yes or no filter, with the keys of their texts. */
    public const YES_NO = ['yes' => 'yes', 'no' => 'no'];

    /**
     * @param list<array<string,mixed>> $columns made by column()
     * @param list<array<string,mixed>> $filters made by filter()
     * @param list<string> $search the attributes the search looks in
     * @param list<array<string,mixed>> $actions made by action()
     * @param string $sort the sort of a list that asks for none, `-` before it for the descending order
     * @param list<string> $moreSorts the sorts it takes that no column shows
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $filters = [],
        public readonly array $search = [],
        public readonly array $actions = [],
        public readonly string $sort = 'name',
        public readonly array $moreSorts = [],
    ) {}

    /**
     * A column: the attribute it shows, the key of its title, how it shows it (TYPES), and the sort that orders the list by it (none:
     * it does not sort).
     *
     * @param array<string,mixed> $more `picture`: an attribute whose asset is shown beside the value; `subtitle`: the attributes shown
     *        under the value, the first that has one (its address, else its slug), `subtitleLink` the attribute that gives the address
     *        it links to; `tag`: an attribute that,
     *        when true, adds the text of `tagLabel` beside it; `link`: the attribute that gives the address of a `link`
     */
    public static function column(
        string $name,
        string $label,
        string $type = 'text',
        ?string $sort = null,
        array $more = [],
    ): array {
        in_array($type, self::TYPES, true) || throw new \InvalidArgumentException("'$type' is not a type of column");

        return ['name' => $name, 'label' => $label, 'type' => $type] + ($sort === null ? [] : ['sort' => $sort]) + $more;
    }

    /**
     * An indicator of an `indicators` column: the attribute it shows (a dotted path for a value in an object: `connections.console`),
     * its icon (ICONS), the key of its title, its states, and the actions of the list that change it: the one whose `when` the row
     * meets is the button of the icon, not one of the buttons of the row. A value none of its states has shows no icon.
     *
     * @param list<array{0:mixed,1:string,2:string,3?:string}> $states the value, the key of its text, its tone (TONES), and its own
     *        icon when it is not the one of the indicator
     * @param list<string> $actions names of actions of the list
     * @return array{name:string,icon:string,label:string,states:list<array{value:mixed,label:string,tone:string,icon?:string}>,actions?:list<string>}
     */
    public static function indicator(string $name, string $icon, string $label, array $states, array $actions = []): array
    {
        foreach ([$icon, ...array_filter(array_column($states, 3))] as $one) {
            in_array($one, self::ICONS, true) || throw new \InvalidArgumentException("'$one' is not an icon of an indicator");
        }
        foreach ($states as [, , $tone]) {
            in_array($tone, self::TONES, true) || throw new \InvalidArgumentException("'$tone' is not a tone of an indicator");
        }

        return [
            'name' => $name,
            'icon' => $icon,
            'label' => $label,
            'states' => array_map(
                static fn(array $state): array => ['value' => $state[0], 'label' => $state[1], 'tone' => $state[2]] + (isset($state[3]) ? ['icon' => $state[3]] : []),
                $states,
            ),
        ] + ($actions === [] ? [] : ['actions' => $actions]);
    }

    /**
     * A filter: its name in `filter[<name>]`, the key of its title, and its values with the keys of their texts in their order. A
     * filter without values takes any value of the format it says (`uuid`, or what identifies a resource: `simulator`...).
     *
     * @param array<string,string|array{0:string,1:string}> $values value => key of its text, or the keys of its text and of what
     *        explains it (`description`, a tooltip)
     * @param array<string,mixed> $more `multiple`: it takes several of its values at once, separated by commas, and keeps what has
     *        any of them; `dynamic`: the values the data has now are in `meta.available.<name>` of the list, the others give nothing
     */
    public static function filter(
        string $name,
        string $label,
        array $values = self::YES_NO,
        ?string $format = null,
        array $more = [],
    ): array {
        return ['name' => $name, 'label' => $label] +
            ($values === []
                ? ['format' => $format ?? 'string']
                : ['values' => self::values($values)]) +
            $more;
    }

    /**
     * Words with the keys of their texts, as a filter and a column of a `list` give them: `[{value, label, description?}]`.
     *
     * @param array<string,string|array{0:string,1:string}> $values value => key of its text, or the keys of its text and of what
     *        explains it
     * @return list<array{value:string,label:string,description?:string}>
     */
    public static function values(array $values): array
    {
        return array_map(
            static fn(string $value, string|array $key): array => ['value' => $value] +
                (is_array($key) ? ['label' => $key[0], 'description' => $key[1]] : ['label' => $key]),
            array_map('strval', array_keys($values)),
            array_values($values),
        );
    }

    /** Whether a filter takes several values at once. */
    public function multiple(string $name): bool
    {
        foreach ($this->filters as $filter) {
            if ($filter['name'] === $name) {
                return (bool) ($filter['multiple'] ?? false);
            }
        }

        return false;
    }

    /**
     * The values a filter asks for: the value, or each of the values of a filter that takes several.
     *
     * @return list<string>
     */
    public function asked(string $name, mixed $value): array
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '') {
            return [];
        }

        return $this->multiple($name)
            ? array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $one): bool => $one !== ''))
            : [$value];
    }

    /**
     * An action of a row: its name, the key of its text, the operation of the contract it calls (`PATCH /grids/{grid}/avatars/{id}`,
     * `{id}` being the id of the row) with the body it sends (`{id}` there too), and when a row has it.
     *
     * @param array<string,mixed> $body
     * @param array<string,list<mixed>> $when the attributes and the values one of which the row must have
     * @param list<string> $requires the attributes the row must have, not empty
     * @param array<string,mixed> $more `notSelf`: never offered on the avatar of whoever acts; `link`: the page of the site the mail
     *        of the operation links to (`reset` for a new password, `confirm` for the confirmation of an email): whoever calls adds
     *        to the body `link`, the address of its own page with `{token}` in it, and may add `send` false to get the token back
     *        instead of mailing it; `ask`: the key of the question a site asks before (stopping a simulator sends avatars away);
     *        `level`: the lowest user level of a manager who does it, when it is above the one of the operation (a ban: GOD_FULL)
     */
    public static function action(
        string $name,
        string $label,
        string $method,
        string $path,
        array $body = [],
        array $when = [],
        array $requires = [],
        array $more = [],
    ): array {
        return ['name' => $name, 'label' => $label, 'method' => $method, 'path' => $path] +
            ($body === [] ? [] : ['body' => $body]) +
            ($when === [] ? [] : ['when' => $when]) +
            ($requires === [] ? [] : ['requires' => $requires]) +
            $more;
    }

    /**
     * Refuse what the list does not take, rather than answer as if it was not asked: a filter it does not have (`filter-unknown`), a
     * value a filter does not take (`filter-value-unknown`), a sort it does not have (`sort-unknown`). An empty value asks nothing.
     *
     * @param array<string,mixed> $options `filters` and `sort` ("name", "lastSeen desc"), as a model's all() takes them
     * @throws InvalidValue
     */
    public function check(array $options): void
    {
        $values = $this->filterValues();
        foreach ((array) ($options['filters'] ?? []) as $name => $value) {
            $name = (string) $name;
            isset($values[$name]) ||
                throw InvalidValue::because(
                    'filter-unknown',
                    "'$name' is not a filter of this list: " . implode(', ', array_keys($values)),
                );
            foreach ($values[$name] === [] ? [] : $this->asked($name, $value) as $one) {
                in_array($one, $values[$name], true) ||
                    throw InvalidValue::because(
                        'filter-value-unknown',
                        "'$one' is not a value of the filter $name: " . implode(', ', $values[$name]),
                    );
            }
        }
        $sort = trim((string) ($options['sort'] ?? ''));
        if ($sort === '') {
            return;
        }
        [$name, $direction] = array_pad(preg_split('/\s+/', $sort) ?: [], 2, 'asc');
        in_array($name, $this->sorts(), true) && in_array(strtolower((string) $direction), ['asc', 'desc'], true) ||
            throw InvalidValue::because(
                'sort-unknown',
                "'$sort' is not a sort of this list: " . implode(', ', $this->sorts()) . ', each asc or desc',
            );
    }

    /**
     * The columns as a list gives them (the CSV, `meta.columns`): the attribute, its title in English, whether it sorts.
     *
     * @return list<array{name:string,label:string,sortable:bool}>
     */
    public function columns(): array
    {
        return array_map(
            static fn(array $column): array => [
                'name' => $column['name'],
                'label' => self::english($column['label']),
                'sortable' => isset($column['sort']),
            ],
            $this->columns,
        );
    }

    /**
     * The filters and their values, none for a filter that takes any value.
     *
     * @return array<string,list<string>>
     */
    public function filterValues(): array
    {
        $values = [];
        foreach ($this->filters as $filter) {
            $values[$filter['name']] = array_column($filter['values'] ?? [], 'value');
        }

        return $values;
    }

    /** The sorts a list takes, `-` before one for the descending order. */
    public function sorts(): array
    {
        return array_values(array_unique([...array_column($this->columns, 'sort'), ...$this->moreSorts]));
    }

    /**
     * What the contract says of the list (`x-list`).
     *
     * @return array<string,mixed>
     */
    public function document(): array
    {
        return [
            'columns' => array_map(self::shown(...), $this->columns, array_keys($this->columns)),
            'sort' => ['default' => $this->sort, 'values' => $this->sorts(), 'descending' => '-'],
            'search' => $this->search,
            'filters' => $this->filters,
            'actions' => $this->actions,
        ];
    }

    /**
     * A column as a site shows it: `bare` whether its value reads without its label, `priority` primary for the first column and
     * the status (shown at every width), secondary for the others (folded under the name on a narrow screen); a column may say
     * its own.
     *
     * @param array<string,mixed> $column
     * @return array<string,mixed>
     */
    private static function shown(array $column, int $position): array
    {
        return $column + [
            'bare' => in_array($column['type'], self::BARE, true) || ($column['type'] === 'link' && ($column['link'] ?? '') === 'teleport'),
            'priority' => $position === 0 || $column['type'] === 'indicators' ? 'primary' : 'secondary',
        ];
    }

    /**
     * The keys of the catalog it uses.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [...array_column($this->columns, 'label'), ...array_column($this->columns, 'tagLabel')];
        foreach ($this->columns as $column) {
            $values = $column['values'] ?? [];
            array_push($keys, ...array_column($values, 'label'), ...array_column($values, 'description'));
            foreach ($column['indicators'] ?? [] as $indicator) {
                array_push($keys, $indicator['label'], ...array_column($indicator['states'], 'label'));
            }
        }
        foreach ($this->filters as $filter) {
            array_push(
                $keys,
                $filter['label'],
                ...array_column($filter['values'] ?? [], 'label'),
                ...array_column($filter['values'] ?? [], 'description'),
            );
        }

        return array_values(array_unique([...$keys, ...array_column($this->actions, 'label')]));
    }

    /** The text of a key in English, the key itself when the catalog does not have it. */
    private static function english(string $key): string
    {
        return Catalog::MESSAGES[$key] ?? $key;
    }
}
