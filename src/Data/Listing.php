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
     * - `boolean`: yes or no;
     * - `picture`, `map`: the UUID of an asset, shown as an image (`/grids/{grid}/assets/{uuid}.jpg`), `map` being the map tile of
     *   a region;
     * - `link`: the text of the column, linked to the address the attribute named by `link` gives;
     * - `hop`: a hop:// address, shown as a teleport link;
     * - `size`: a size in bytes, or width x height in meters;
     * - `status`, `role`: a word of the API, whose text is its key in the catalog (`status_` or `role_` and the word, - as _);
     * - `list`: a list of words.
     */
    public const TYPES = [
        'text',
        'email',
        'number',
        'date',
        'boolean',
        'picture',
        'map',
        'link',
        'hop',
        'size',
        'status',
        'role',
        'list',
    ];

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
     * @param array<string,mixed> $more `picture`: an attribute whose asset is shown beside the value; `tag`: an attribute that,
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
     * A filter: its name in `filter[<name>]`, the key of its title, and its values with the keys of their texts in their order. A
     * filter without values takes any value of the format it says (`uuid`, or what identifies a resource: `simulator`...).
     *
     * @param array<string,string> $values value => key of its text
     */
    public static function filter(string $name, string $label, array $values = self::YES_NO, ?string $format = null): array
    {
        return ['name' => $name, 'label' => $label] +
            ($values === []
                ? ['format' => $format ?? 'string']
                : [
                    'values' => array_map(
                        static fn(string $value, string $key): array => ['value' => $value, 'label' => $key],
                        array_keys($values),
                        $values,
                    ),
                ]);
    }

    /**
     * An action of a row: its name, the key of its text, the operation of the contract it calls (`PATCH /grids/{grid}/avatars/{id}`,
     * `{id}` being the id of the row) with the body it sends (`{id}` there too), and when a row has it.
     *
     * @param array<string,mixed> $body
     * @param array<string,list<mixed>> $when the attributes and the values one of which the row must have
     * @param list<string> $requires the attributes the row must have, not empty
     * @param array<string,mixed> $more `notSelf`: never offered on the avatar of whoever acts
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
            $value = is_scalar($value) ? (string) $value : '';
            $value === '' ||
                $values[$name] === [] ||
                in_array($value, $values[$name], true) ||
                throw InvalidValue::because(
                    'filter-value-unknown',
                    "'$value' is not a value of the filter $name: " . implode(', ', $values[$name]),
                );
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
            'columns' => $this->columns,
            'sort' => ['default' => $this->sort, 'values' => $this->sorts(), 'descending' => '-'],
            'search' => $this->search,
            'filters' => $this->filters,
            'actions' => $this->actions,
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
        foreach ($this->filters as $filter) {
            array_push($keys, $filter['label'], ...array_column($filter['values'] ?? [], 'label'));
        }

        return array_values(array_unique([...$keys, ...array_column($this->actions, 'label')]));
    }

    /** The text of a key in English, the key itself when the catalog does not have it. */
    private static function english(string $key): string
    {
        return Catalog::MESSAGES[$key] ?? $key;
    }
}
