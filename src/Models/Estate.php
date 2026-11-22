<?php

declare(strict_types=1);

namespace W4OS\Engine\Models;

use W4OS\Engine\Data\Listing;
use W4OS\Engine\Text\Uuid;
use W4OS\Engine\Config\ConnectionString;
use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Collection;
use W4OS\Engine\Data\Connections;
use W4OS\Engine\Data\Database;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Exceptions\ChannelUnavailable;
use W4OS\Engine\Exceptions\InvalidValue;
use W4OS\Engine\Provisioning\Slug;

/**
 * An estate: what a group of regions share, its owner, its managers, its rules, the list of those who may enter and the list of
 * those who may not. A region is in one estate.
 *
 * The estates are the grid's when its Robust serves them (EstateDataService), else each simulator keeps its own, in its own database
 * (or one shared by several simulators): an estate is then known by its simulator and its number, which only means something there
 * (`vonda.welcome:101`: its slug, which has the grid in it). `simulator()` tells which, null for the estates of the grid.
 *
 * Its ban list is the bans of its regions, whoever is banned (an avatar of the grid or a visitor of another one, see Avatar): there
 * is no ban list of the whole grid in the core, banning on the whole grid is banning in every estate.
 */
final class Estate extends Model
{
    /** The tables that hold a list for an estate: what they are called here, their table and their column. */
    private const LISTS = [
        'regions' => ['estate_map', 'RegionID'],
        'managers' => ['estate_managers', 'uuid'],
        'users' => ['estate_users', 'uuid'],
        'groups' => ['estate_groups', 'uuid'],
    ];

    private const NO_ONE = '00000000-0000-0000-0000-000000000000';

    /** The columns a list can be sorted on. */
    private const SORTS = [
        'name' => 'EstateName',
        'id' => 'EstateID',
        'owner' => 'ownerName',
        'regions' => 'regionCount',
    ];

    public function id(): string
    {
        return ($this->simulator() !== null ? $this->simulator() . ':' : '') . $this->number();
    }

    /** A new estate, as OpenSimulator makes it (EstateSettings): its settings by default, public. */
    private const NEW = [
        'AbuseEmailToEstateOwner' => 0, 'DenyAnonymous' => 0, 'ResetHomeOnTeleport' => 0, 'FixedSun' => 0, 'DenyTransacted' => 0,
        'BlockDwell' => 0, 'DenyIdentified' => 0, 'AllowVoice' => 1, 'UseGlobalTime' => 0, 'PricePerMeter' => 1, 'TaxFree' => 0,
        'AllowDirectTeleport' => 1, 'RedirectGridX' => 0, 'RedirectGridY' => 0, 'ParentEstateID' => 1, 'SunPosition' => 0,
        'EstateSkipScripts' => 0, 'BillableFactor' => 0, 'PublicAccess' => 1, 'AbuseEmail' => '', 'DenyMinors' => 0,
        'AllowLandmark' => 1, 'AllowParcelChanges' => 1, 'AllowSetHome' => 1,
    ];

    /**
     * Make an estate: `name` (64 characters at most), `owner` (an avatar of the grid), and `simulator`, the one whose database of
     * estates holds it when the simulators keep their own. By the console of the simulator when it runs (`estate create`), else in
     * the database, with the settings OpenSimulator gives a new one, its number after the others (101 the first, as its table starts).
     *
     * @param array<string,mixed> $values
     * @throws InvalidValue
     * @throws ChannelUnavailable
     */
    public static function create(GridConfig $config, array $values): static
    {
        $name = trim((string) ($values['name'] ?? ''));
        ($name !== '' && mb_strlen($name) <= 64) || throw InvalidValue::because('invalid-request', 'the name of an estate has 1 to 64 characters');
        $owner = strtolower(trim((string) ($values['owner'] ?? '')));
        Uuid::isValid($owner) && Avatar::find($config, $owner) !== null || throw InvalidValue::because('owner-unknown', "the grid has no avatar '$owner'");
        $simulator = trim((string) ($values['simulator'] ?? ''));
        $central = Database::Estate->scopeIn($config) === Database::GRID;
        $central || $simulator !== '' || throw InvalidValue::because('invalid-request', 'the simulators keep their estates: say the one that holds it (simulator)');
        $action = "make the estate $name";
        $pdo = self::connect($config, Database::Estate, $central ? null : $simulator, $action);
        if ($simulator === '' || !\W4OS\Engine\Console\InstanceConsoleService::send($simulator, "estate create $owner $name")) {
            $simulator !== '' && \W4OS\Engine\Console\InstanceConsoleService::running((string) $config->file("simulators/$simulator")) &&
                throw ChannelUnavailable::for($action, "the simulator $simulator runs and its console cannot be reached");
            $number = max(100, (int) $pdo->get_var('SELECT MAX(EstateID) FROM estate_settings')) + 1;
            $pdo->insert('estate_settings', ['EstateID' => $number, 'EstateName' => $name, 'EstateOwner' => $owner] + self::NEW);
        }
        $number = (int) $pdo->get_var('SELECT MAX(EstateID) FROM estate_settings WHERE EstateName = :name', ['name' => $name]);

        return static::find($config, ($central ? '' : "$simulator:") . $number) ?? throw ChannelUnavailable::for($action, 'it is not in the database of the estates');
    }

    /** Its number in its database. */
    public function number(): int
    {
        return (int) ($this->row['EstateID'] ?? 0);
    }

    /** The simulator whose database holds it, null for an estate of the grid. */
    public function simulator(): ?string
    {
        $simulator = (string) ($this->row['simulator'] ?? '');

        return $simulator !== '' ? $simulator : null;
    }

    public function name(): string
    {
        return (string) ($this->row['EstateName'] ?? '');
    }

    public function slug(): string
    {
        return Slug::slug($this->name());
    }

    /** The user who owns it. */
    public function owner(): ?string
    {
        $owner = strtolower((string) ($this->row['EstateOwner'] ?? ''));

        return $owner !== '' && $owner !== self::NO_ONE ? $owner : null;
    }

    public function ownerName(): ?string
    {
        return $this->row['ownerName'] ?? null;
    }

    /** The estate it belongs to (0: none). */
    public function parent(): int
    {
        return (int) ($this->row['ParentEstateID'] ?? 0);
    }

    /**
     * The regions in it.
     *
     * @return list<string> UUIDs, lower case
     */
    public function regions(): array
    {
        return $this->list('regions');
    }

    public function hasRegion(string $uuid): bool
    {
        return in_array(strtolower($uuid), $this->regions(), true);
    }

    /**
     * Those who manage it, with its owner.
     *
     * @return list<string> UUIDs
     */
    public function managers(): array
    {
        return $this->list('managers');
    }

    /**
     * Those who may enter, when it is not open to all.
     *
     * @return list<string> UUIDs
     */
    public function users(): array
    {
        return $this->list('users');
    }

    /**
     * The groups that may enter, when it is not open to all.
     *
     * @return list<string> UUIDs
     */
    public function groups(): array
    {
        return $this->list('groups');
    }

    /**
     * Those who may not enter: a user (`uuid`), an address (`ip`), a host (`hostMask`) or a name (`nameMask`).
     *
     * @return list<array{uuid:string,ip:string,hostMask:string,nameMask:string}>
     */
    public function bans(): array
    {
        return array_values((array) ($this->row['bans'] ?? []));
    }

    /** Whether a user is banned from it. */
    public function isBanned(string $uuid): bool
    {
        $uuid = strtolower($uuid);
        foreach ($this->bans() as $ban) {
            if ($ban['uuid'] === $uuid) {
                return true;
            }
        }

        return false;
    }

    public function isPublic(): bool
    {
        return (int) ($this->row['PublicAccess'] ?? 0) !== 0;
    }

    /** The database it is in, null when the grid does not say it. */
    public function connection(): ?ConnectionString
    {
        return Database::Estate->connection($this->config, $this->simulator());
    }

    /**
     * The statement that bans a user from it, for whoever reviews it before the database is changed; null when the user is banned
     * already.
     *
     * @throws \InvalidArgumentException when it is not a UUID
     */
    public function banSql(string $uuid): ?string
    {
        $uuid = self::uuidOf($uuid);
        $database = $this->connection()?->database;
        if ($this->isBanned($uuid)) {
            return null;
        }

        return sprintf(
            "INSERT INTO %sestateban (EstateID, bannedUUID, bannedIp, bannedIpHostMask) VALUES (%d, '%s', '', '');",
            $database !== null ? '`' . str_replace('`', '``', $database) . '`.' : '',
            $this->number(),
            $uuid,
        );
    }

    /**
     * Ban a user from it, in its database. A simulator that runs has the list in memory: it reads it again when it starts.
     *
     * @throws ChannelUnavailable
     * @throws \InvalidArgumentException when it is not a UUID
     */
    public function ban(string $uuid): void
    {
        $uuid = self::uuidOf($uuid);
        $pdo = self::connect($this->config, Database::Estate, $this->simulator(), 'ban in ' . $this->name());
        if ($this->isBanned($uuid)) {
            return;
        }
        $pdo->insert('estateban', [
            'EstateID' => $this->number(),
            'bannedUUID' => $uuid,
            'bannedIp' => '',
            'bannedIpHostMask' => '',
        ]);
    }

    /**
     * Let a user in again, in its database.
     *
     * @throws ChannelUnavailable
     * @throws \InvalidArgumentException when it is not a UUID
     */
    public function unban(string $uuid): void
    {
        $uuid = self::uuidOf($uuid);
        $pdo = self::connect($this->config, Database::Estate, $this->simulator(), 'unban in ' . $this->name());
        $pdo->prepareAndExecute('DELETE FROM estateban WHERE EstateID = :id AND LOWER(bannedUUID) = :uuid', [
            'id' => $this->number(),
            'uuid' => $uuid,
        ]);
    }

    public function channels(): array
    {
        return Connections::pdo($this->config, Database::Estate, $this->simulator()) !== null ? ['database'] : [];
    }

    public function detail(): array
    {
        $row = $this->row;
        $flag = static fn(string $key): bool => (int) ($row[$key] ?? 0) !== 0;

        return [
            'id' => $this->id(),
            'number' => $this->number(),
            'simulator' => $this->simulator(),
            'name' => $this->name(),
            'slug' => $this->slug(),
            'owner' => $this->owner(),
            'ownerName' => $this->ownerName(),
            'parent' => $this->parent(),
            'public' => $this->isPublic(),
            'denyAnonymous' => $flag('DenyAnonymous'),
            'denyIdentified' => $flag('DenyIdentified'),
            'denyTransacted' => $flag('DenyTransacted'),
            'denyMinors' => $flag('DenyMinors'),
            'allowVoice' => $flag('AllowVoice'),
            'allowDirectTeleport' => $flag('AllowDirectTeleport'),
            'allowEnvironmentOverride' => $flag('AllowEnviromentOverride'),
            'resetHomeOnTeleport' => $flag('ResetHomeOnTeleport'),
            'skipScripts' => $flag('EstateSkipScripts'),
            'taxFree' => $flag('TaxFree'),
            'pricePerMeter' => (int) ($row['PricePerMeter'] ?? 0),
            'billableFactor' => (float) ($row['BillableFactor'] ?? 0),
            'fixedSun' => $flag('FixedSun'),
            'useGlobalTime' => $flag('UseGlobalTime'),
            'sunPosition' => (float) ($row['SunPosition'] ?? 0),
            'abuseEmail' => ($row['AbuseEmail'] ?? '') !== '' ? $row['AbuseEmail'] : null,
            'abuseEmailToOwner' => $flag('AbuseEmailToEstateOwner'),
            'regions' => $this->regions(),
            'regionCount' => count($this->regions()),
            'managers' => $this->managers(),
            'users' => $this->users(),
            'groups' => $this->groups(),
            'bans' => $this->bans(),
        ];
    }

    /**
     * The estates of the grid, from its database or from the database of each simulator.
     *
     * Options: `search` (words of the name), `sort` ("name", "id desc", "owner", "regions"), `filters` (`simulator`: only the estates
     * of this simulator; `owner`: a UUID; `region`: a UUID, the estate that holds it), `page` and `perPage`.
     *
     * @throws ChannelUnavailable when no database of estates can be reached
     */
    public static function all(GridConfig $config, array $options = []): Collection
    {
        self::listing()->check($options);
        [$sortName, $direction] = array_pad(
            preg_split('/\s+/', trim((string) ($options['sort'] ?? 'name'))) ?: [],
            2,
            'asc',
        );
        if (!isset(self::SORTS[$sortName])) {
            [$sortName, $direction] = ['name', 'asc'];
        }
        $direction = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';
        $filters = (array) ($options['filters'] ?? []);
        $applied = [];

        $rows = [];
        $reached = 0;
        foreach (self::databases($config) as [$simulator, $pdo]) {
            $reached++;
            if (isset($filters['simulator']) && $filters['simulator'] !== '' && $filters['simulator'] !== $simulator) {
                continue;
            }
            array_push($rows, ...self::rowsIn($pdo, $simulator));
        }
        if ($reached === 0) {
            throw ChannelUnavailable::for('read the estates', 'no database of estates can be reached');
        }

        foreach (
            preg_split('/\s+/', trim((string) ($options['search'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []
            as $word
        ) {
            $rows = array_filter(
                $rows,
                static fn(array $row): bool => stripos((string) $row['EstateName'], $word) !== false,
            );
        }
        foreach (['owner', 'region'] as $name) {
            if (isset($filters[$name]) && Uuid::isValid((string) $filters[$name])) {
                $value = strtolower((string) $filters[$name]);
                $applied[$name] = $value;
                $rows = array_filter(
                    $rows,
                    static fn(array $row): bool => $name === 'owner'
                        ? strtolower((string) $row['EstateOwner']) === $value
                        : in_array($value, $row['regions'], true),
                );
            }
        }
        if (isset($filters['simulator']) && $filters['simulator'] !== '') {
            $applied['simulator'] = (string) $filters['simulator'];
        }

        $rows = self::withOwners($config, array_values($rows));
        foreach ($rows as &$row) {
            $row['regionCount'] = count($row['regions']);
        }
        unset($row);
        $key = self::SORTS[$sortName];
        $numeric = in_array($sortName, ['id', 'regions'], true);
        usort($rows, static function (array $a, array $b) use ($key, $numeric, $direction): int {
            $order = $numeric
                ? (int) ($a[$key] ?? 0) <=> (int) ($b[$key] ?? 0)
                : strcasecmp((string) ($a[$key] ?? ''), (string) ($b[$key] ?? ''));

            $order = $direction === 'desc' ? -$order : $order;

            return $order ?:
                strcmp(
                    (string) $a['simulator'] . ':' . $a['EstateID'],
                    (string) $b['simulator'] . ':' . $b['EstateID'],
                );
        });
        $total = count($rows);
        $perPage = max(0, (int) ($options['perPage'] ?? 0));
        $page = max(1, (int) ($options['page'] ?? 1));
        if ($perPage > 0) {
            $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
        }

        return new Collection(array_map(static fn(array $row): self => new self($config, $row), $rows), [
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'sort' => "$sortName $direction",
            'search' => trim((string) ($options['search'] ?? '')),
            'columns' => self::columns(),
            'filters' => self::listing()->filterValues(),
            'applied' => $applied,
        ]);
    }

    /**
     * An estate by its id: its number for the estates of the grid, its simulator and its number (`alpha:101`) for those of a
     * simulator. A number alone is looked for in every database.
     */
    public static function find(GridConfig $config, string $id): ?static
    {
        $simulator = null;
        if (str_contains($id, ':')) {
            [$simulator, $id] = explode(':', $id, 2);
        }
        if (!ctype_digit($id)) {
            return null;
        }
        foreach (self::databases($config) as [$owner, $pdo]) {
            if ($simulator !== null && $simulator !== $owner) {
                continue;
            }
            $rows = self::rowsIn($pdo, $owner, ' WHERE EstateID = :id', ['id' => (int) $id]);
            if ($rows !== []) {
                return new self($config, self::withOwners($config, $rows)[0]);
            }
        }

        return null;
    }

    /**
     * The columns of a list.
     *
     * @return list<array{name:string,label:string,sortable?:bool}>
     */
    public static function columns(): array
    {
        return self::listing()->columns();
    }

    /** What the list of the estates shows (Listing). */
    public static function listing(): Listing
    {
        return new Listing(
            [
                Listing::column('name', 'column_name', sort: 'name'),
                Listing::column('ownerName', 'column_owner', sort: 'owner'),
                Listing::column('simulator', 'filter_simulator'),
                Listing::column('regionCount', 'column_regions', 'number', 'regions'),
            ],
            [
                Listing::filter('simulator', 'filter_simulator', [], 'simulator'),
                Listing::filter('owner', 'column_owner', [], 'uuid'),
                Listing::filter('region', 'column_region', [], 'uuid'),
            ],
            ['name'],
            moreSorts: ['id'],
            priorities: ['ownerName' => 2, 'regionCount' => 2, 'simulator' => 3],
        );
    }

    /**
     * The databases of estates: the one of the grid when its Robust serves them, else one for each simulator (a database shared by
     * simulators once, for the first of them).
     *
     * @return \Generator<int,array{0:?string,1:OSPDO}> the simulator, null for the grid's
     */
    /**
     * How many estates each avatar owns, in the estate databases of the grid; null when none can be read.
     *
     * @return ?array<string,int> by owner, in lower case
     */
    public static function countsByOwner(GridConfig $config): ?array
    {
        $counts = null;
        foreach (self::databases($config) as [, $pdo]) {
            if (!$pdo->table_exists('estate_settings')) {
                continue;
            }
            $counts ??= [];
            $statement = $pdo->prepareAndExecute('SELECT EstateOwner, COUNT(*) FROM estate_settings GROUP BY EstateOwner', []);
            foreach ($statement ? $statement->fetchAll(\PDO::FETCH_NUM) : [] as [$owner, $n]) {
                $counts[strtolower((string) $owner)] = ($counts[strtolower((string) $owner)] ?? 0) + (int) $n;
            }
        }

        return $counts;
    }

    /**
     * The owner of the grid: the owner of its first estate (the lowest EstateID of its estate databases), the one OpenSimulator makes
     * the god of its regions (region_owner_is_god); null when none can be read.
     */
    public static function firstOwner(GridConfig $config): ?string
    {
        $first = null;
        foreach (self::databases($config) as [, $pdo]) {
            if (!$pdo->table_exists('estate_settings')) {
                continue;
            }
            $statement = $pdo->prepareAndExecute('SELECT EstateID, EstateOwner FROM estate_settings ORDER BY EstateID LIMIT 1', []);
            $row = $statement ? $statement->fetch(\PDO::FETCH_NUM) : false;
            if ($row !== false && ($first === null || (int) $row[0] < $first[0])) {
                $first = [(int) $row[0], strtolower((string) $row[1])];
            }
        }

        return $first === null || !Uuid::isValid($first[1]) || Uuid::isNullKey($first[1]) ? null : $first[1];
    }

    private static function databases(GridConfig $config): \Generator
    {
        if (Database::Estate->scopeIn($config) === Database::GRID) {
            $pdo = Connections::pdo($config, Database::Estate);
            if ($pdo !== null) {
                yield [null, $pdo];
            }

            return;
        }
        $seen = [];
        foreach (array_keys($config->tree()['simulators'] ?? []) as $simulator) {
            $key = Connections::key($config, Database::Estate, (string) $simulator);
            if ($key === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pdo = Connections::pdo($config, Database::Estate, (string) $simulator);
            if ($pdo !== null) {
                yield [(string) $simulator, $pdo];
            }
        }
    }

    /**
     * The estates of a database with their lists, read once for all of them.
     *
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private static function rowsIn(OSPDO $pdo, ?string $simulator, string $where = '', array $params = []): array
    {
        $rows = $pdo->get_results("SELECT * FROM estate_settings$where", $params, \PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn(array $row): int => (int) $row['EstateID'], $rows);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $lists = [];
        foreach (self::LISTS as $name => [$table, $column]) {
            foreach (
                self::select($pdo, "SELECT EstateID, $column FROM $table WHERE EstateID IN ($marks)", $ids)
                as $row
            ) {
                $lists[(int) $row['EstateID']][$name][] = strtolower((string) $row[$column]);
            }
        }
        $bans = [];
        $banned =
            self::select(
                $pdo,
                "SELECT EstateID, bannedUUID, bannedIp, bannedIpHostMask, bannedNameMask FROM estateban WHERE EstateID IN ($marks)",
                $ids,
            ) ?:
            self::select(
                $pdo,
                "SELECT EstateID, bannedUUID, bannedIp, bannedIpHostMask, '' AS bannedNameMask FROM estateban WHERE EstateID IN ($marks)",
                $ids,
            );
        foreach ($banned as $row) {
            $uuid = strtolower((string) $row['bannedUUID']);
            $bans[(int) $row['EstateID']][] = [
                'uuid' => $uuid === self::NO_ONE ? '' : $uuid,
                'ip' => (string) $row['bannedIp'],
                'hostMask' => (string) $row['bannedIpHostMask'],
                'nameMask' => (string) $row['bannedNameMask'],
            ];
        }

        $estates = [];
        foreach ($rows as $row) {
            $id = (int) $row['EstateID'];
            $estate = $row + ['simulator' => $simulator ?? '', 'bans' => $bans[$id] ?? []];
            foreach (array_keys(self::LISTS) as $name) {
                $estate[$name] = $lists[$id][$name] ?? [];
            }
            $estates[] = $estate;
        }

        return $estates;
    }

    /**
     * The rows a query gives; none when the table is not there (an old database).
     *
     * @param list<int> $params
     * @return list<array<string,mixed>>
     */
    private static function select(OSPDO $pdo, string $sql, array $params): array
    {
        try {
            return $pdo->get_results($sql, $params, \PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The name of the owner of each estate, from the user account service.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function withOwners(GridConfig $config, array $rows): array
    {
        $ids = array_values(
            array_unique(
                array_filter(
                    array_map(static fn(array $row): string => strtolower((string) $row['EstateOwner']), $rows),
                    static fn(string $id): bool => $id !== '' && $id !== self::NO_ONE,
                ),
            ),
        );
        $names = [];
        if ($ids !== [] && ($pdo = Connections::pdo($config, 'UserAccountService'))) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            foreach (
                $pdo->get_results(
                    "SELECT PrincipalID, FirstName, LastName FROM UserAccounts WHERE PrincipalID IN ($marks)",
                    $ids,
                    \PDO::FETCH_ASSOC,
                )
                as $row
            ) {
                $names[strtolower((string) $row['PrincipalID'])] = trim($row['FirstName'] . ' ' . $row['LastName']);
            }
        }
        foreach ($rows as &$row) {
            $row['ownerName'] = $names[strtolower((string) $row['EstateOwner'])] ?? null;
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function list(string $name): array
    {
        return array_values((array) ($this->row[$name] ?? []));
    }

    private static function uuidOf(string $text): string
    {
        return Uuid::isValid($text) ? strtolower($text) : throw new \InvalidArgumentException("'$text' is not a UUID");
    }
}
