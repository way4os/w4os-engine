<?php

declare(strict_types=1);

namespace W4OS\Engine\Models;

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Collection;
use W4OS\Engine\Data\Database;
use W4OS\Engine\Data\Listing;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Exceptions\ChannelUnavailable;
use W4OS\Engine\Hypergrid\Destination;
use W4OS\Engine\Hypergrid\Place;
use W4OS\Engine\Hypergrid\Reachable;
use W4OS\Engine\Hypergrid\TeleportLinks;
use W4OS\Engine\Services\SearchFilters;
use W4OS\Engine\Text\Uuid;

/**
 * A result of the search of the grid, as the viewers find it in its search tables (the ones the simulators register: parcels,
 * land for sale, events, classifieds): its row, the place it sends to (its region and its point, on its grid: the one of its
 * gatekeeper), its links asked of it as of any Reachable.
 *
 * Each kind says its table, its query (the terms of SearchFilters, the ones of query.php), its sorts, its list and its page. Its
 * criteria are the filters of its list: the words are its `search`; `rating` the ratings asked (general, moderate, adult, several
 * at once), none for every one.
 */
abstract class SearchResult extends Model implements Reachable
{
    use TeleportLinks;

    /** The ratings a search asks when it asks none: the ones of a viewer that has not changed them, as its search window opens. */
    public const RATINGS = ['general', 'moderate', 'adult'];

    /** The type of the kind in the API, under `/grids/{grid}/` (`search/places`). */
    abstract public static function type(): string;

    /** What its query reads: the SELECT of its rows and their FROM, its joins with them (`p.*, r.regionname AS region FROM ...`). */
    abstract protected static function from(OSPDO $db): string;

    /**
     * The terms of its WHERE (to join with AND) and their values, for the criteria of all() (SearchFilters).
     *
     * @param array<string,string> $filters the criteria asked, as the filters of its list
     * @return array{0:list<string>,1:array<string,mixed>}
     */
    abstract protected static function terms(string $search, array $filters): array;

    /** @return array<string,string> its sorts, the SQL of each, the first being the default (its direction: listing()->sort) */
    abstract protected static function sorts(): array;

    /** The id of its row (`parcelUUID`, `eventid`...), qualified as in its query. */
    abstract protected static function key(): string;

    /** The attribute that names it, after the sort asked: the last order of a list, so that equal rows keep their order. */
    abstract protected static function tie(): string;

    /** Its region, as its grid names it (the row may keep it with its grid). */
    abstract protected function region(): string;

    /** Its point in its region (`x/y/z`, `x,y,z`, `<x, y, z>`), '' when it has none. */
    abstract protected function landing(): string;

    public function uuid(): ?string
    {
        return $this->id();
    }

    public function slug(): string
    {
        return $this->id();
    }

    public function name(): string
    {
        return (string) ($this->row['name'] ?? '');
    }

    /** The gatekeeper of the grid it is on, as the row keeps it (`http://host:port`), '' for the grid itself. */
    public function gatekeeper(): string
    {
        return trim((string) ($this->row['gatekeeperURL'] ?? ''));
    }

    public function tpUri(): ?string
    {
        return Place::at($this->gatekeeper(), $this->region(), $this->landing(), $this->tpLogin(), $this->tpFormats())?->tpUri();
    }

    protected function tpLogin(): ?string
    {
        return $this->config->gridUri();
    }

    protected function tpFormats(): array
    {
        return Destination::linkFormats($this->config);
    }

    /**
     * What every result says of where it sends to: its address (`uri`) and its links for both clients (`links`, by client, as
     * tpLinks() gives them), none without a region.
     *
     * @return array{uri:?string,links:array<string,array<int,array{link:string,label:string}>>}
     */
    protected function place(): array
    {
        return [
            'uri' => $this->tpUri(),
            'links' => array_combine(Destination::CLIENTS, array_map(fn(string $client): array => $this->tpLinks($client), Destination::CLIENTS)),
        ];
    }

    /**
     * Its rows the search of the viewers finds. Options: `search` (its words), `filters` (its criteria, as its list gives them),
     * `sort` ("traffic desc"; none: its own), `page` and `perPage`.
     *
     * @throws ChannelUnavailable when the search the grid sets cannot be reached
     */
    public static function all(GridConfig $config, array $options = []): Collection
    {
        static::listing()->check($options);
        $sorts = static::sorts();
        $default = ltrim(static::listing()->sort, '-');
        [$sort, $direction] = array_pad(preg_split('/\s+/', trim((string) ($options['sort'] ?? ''))) ?: [], 2, '');
        if (!isset($sorts[$sort])) {
            [$sort, $direction] = [$default, str_starts_with(static::listing()->sort, '-') ? 'desc' : 'asc'];
        }
        $direction = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';
        $perPage = max(0, (int) ($options['perPage'] ?? 0));
        $page = max(1, (int) ($options['page'] ?? 1));
        $search = trim((string) ($options['search'] ?? ''));
        $filters = array_map('strval', (array) ($options['filters'] ?? []));
        $db = self::search($config);
        [$rows, $total] = [[], 0];
        if ($db !== null) {
            $from = static::from($db);
            [$terms, $values] = static::terms($search, $filters);
            $where = $terms === [] ? '' : ' WHERE ' . implode(' AND ', $terms);
            $total = (int) self::read($db, 'SELECT COUNT(*) AS n ' . substr($from, (int) stripos($from, ' FROM ')) . $where, $values)[0]['n'];
            $sql = "SELECT $from$where ORDER BY {$sorts[$sort]} $direction, " . static::tie();
            $perPage > 0 && ($sql .= " LIMIT $perPage OFFSET " . ($page - 1) * $perPage);
            $rows = self::read($db, $sql, $values);
        }

        return new Collection(array_map(static fn(array $row): static => new static($config, $row), $rows), [
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'sort' => "$sort $direction",
            'search' => $search,
            'filters' => static::listing()->filterValues(),
            'applied' => $filters,
        ]);
    }

    /** @throws ChannelUnavailable when the search the grid sets cannot be reached */
    public static function find(GridConfig $config, string $id): ?static
    {
        $db = self::search($config);
        if ($db === null) {
            return null;
        }
        $rows = self::read($db, 'SELECT ' . static::from($db) . ' WHERE ' . static::key() . ' = :id', ['id' => $id]);

        return $rows === [] ? null : new static($config, $rows[0]);
    }

    /** The ratings asked in its filters, none for every one. */
    protected static function ratings(array $filters): array
    {
        return array_values(array_intersect(self::RATINGS, array_filter(explode(',', (string) ($filters['rating'] ?? '')))));
    }

    /** The filter of the ratings, for the list of each kind. */
    protected static function ratingFilter(): array
    {
        return Listing::filter('rating', 'web_search_ratings', self::ratingOptions(), more: ['multiple' => true]);
    }

    /** The ratings as the options of a field: each its text. */
    protected static function ratingOptions(): array
    {
        return ['general' => 'web_search_rating_general', 'moderate' => 'web_search_rating_moderate', 'adult' => 'web_search_rating_adult'];
    }

    /** A filter of categories: any (0), or one of them, by its number. */
    protected static function categoryFilter(array $categories, string $prefix): array
    {
        return Listing::filter('category', 'web_search_category', ['0' => 'web_search_any_category'] + array_combine(
            array_map('strval', array_keys($categories)),
            array_map(static fn(string $key): string => "{$prefix}_$key", $categories),
        ));
    }

    /** The rating a `mature` column keeps, as a word of the API: general, moderate, adult ('' unknown). */
    protected static function rating(mixed $mature): string
    {
        return match (strtolower(trim((string) $mature))) {
            'pg' => 'general', 'mature' => 'moderate', 'adult' => 'adult', default => '',
        };
    }

    /** A picture of a row, null when it has none (empty, the null UUID). */
    protected static function picture(mixed $uuid): ?string
    {
        $uuid = trim((string) $uuid);

        return $uuid === '' || Uuid::isNullKey($uuid) ? null : $uuid;
    }

    /** The table of the regions of the search (regionsregister, or regions for an older one). */
    protected static function regions(OSPDO $db): string
    {
        return Region::searchTable($db);
    }

    /** The search database of the grid, null when it sets none. @throws ChannelUnavailable when it cannot be reached */
    private static function search(GridConfig $config): ?OSPDO
    {
        return self::optional($config, Database::Search, null, 'read the search');
    }

    /** @return list<array<string,mixed>> @throws ChannelUnavailable when the query fails */
    private static function read(OSPDO $db, string $sql, array $values): array
    {
        $statement = $db->prepareAndExecute($sql, $values);
        $statement || throw ChannelUnavailable::for('read the search', 'the query failed');

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
