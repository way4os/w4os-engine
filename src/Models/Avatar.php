<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Forms\ItemForm;
use OpenSim\Result;
use OpenSim\Text\Uuid;
use OpenSim\Config\GridConfig;
use OpenSim\Data\Collection;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Data\Listing;
use OpenSim\I18n\Catalog;
use OpenSim\Data\OSPDO;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Exceptions\InvalidValue;
use OpenSim\Provisioning\AccountWriter;
use OpenSim\Provisioning\GridAccounts;
use OpenSim\Hypergrid\Destination;
use OpenSim\Network\GridInfoCache;
use OpenSim\Provisioning\Slug;
use OpenSim\Runtime\SystemClock;
use OpenSim\SignIn\AvatarTokens;
use OpenSim\Support\Bitmask;

/**
 * An avatar of the grid: its account (UserAccounts of the user account service), where it was (GridUser of the grid user service)
 * and its profile (userprofile of the profiles service). The three are read in one query when they are in the same database, which is
 * the usual case, else one query for each.
 *
 * It is local, or it is a visitor of another grid (the grid keeps the visits in GridUser), or someone known by their UUID alone:
 * whoever is banned or whose data is deleted is any of them. What it left in the databases of the grid is told by leftovers().
 */
final class Avatar extends Model
{
    /**
     * The levels of the accounts, as OpenSimulator names them (opensimulator.org/wiki/Userlevel): a level has every right of the
     * ones below it, never one above.
     */
    public const GOD_NOT = 0;
    public const GOD_LIKE = 1;
    public const GOD_CUSTOMER_SERVICE = 100;
    public const GOD_LIAISON = 150;
    public const GOD_FULL = 200;
    public const GOD_MAINTENANCE = 250;

    /** Every level by its value, named as OpenSimulator names it. */
    public const LEVELS = [
        self::GOD_NOT => 'GOD_NOT',
        self::GOD_LIKE => 'GOD_LIKE',
        self::GOD_CUSTOMER_SERVICE => 'GOD_CUSTOMER_SERVICE',
        self::GOD_LIAISON => 'GOD_LIAISON',
        self::GOD_FULL => 'GOD_FULL',
        self::GOD_MAINTENANCE => 'GOD_MAINTENANCE',
    ];

    /** The level of the accounts that are gods (administrators): GOD_FULL. Ask isGodLevel() rather than compare with it. */
    public const GOD_LEVEL = self::GOD_FULL;

    /**
     * The states of an account: it can log in (active), a manager disabled it (disabled), it waits for the confirmation of its
     * email (pending). A state other than active keeps the account out of the grid as Robust reads it: its UserLevel is below 0,
     * which the login of Robust refuses (MinLoginLevel, 0 by default), the level it had being kept as -1 - level; a pending one
     * has PENDING_FLAG in its UserFlags. `active` is 0 for both, as the search of Robust and w4os read it. A banned one (a hard ban)
     * is disabled with BANNED_FLAG besides: only an unban lets it in again, never an enable; its account stays, its name taken, its
     * UUID kept (what it made or owns can be found by it). Being in the ban list of an estate is another thing (estateBans()).
     */
    public const STATUSES = ['active', 'disabled', 'pending', 'banned'];

    /** What an avatar is: a person's (user), a model of the grid (an outfit to copy, [Models]), the account of its services. */
    public const TYPES = ['user', 'model', 'service'];

    /**
     * What an avatar wants to do, the check boxes of its profile in the viewer (profileWantToMask), as OpenSimulator and w4os have
     * them: the name of each flag and its bit.
     */
    public const WANTS = [
        'build' => 1,
        'explore' => 2,
        'meet' => 4,
        'group' => 8,
        'buy' => 16,
        'sell' => 32,
        'be-hired' => 64,
        'hire' => 128,
    ];

    /** What an avatar is good at, the check boxes of its profile in the viewer (profileSkillsMask): the name of each flag and its bit. */
    public const SKILLS = [
        'textures' => 1,
        'architecture' => 2,
        'event-planning' => 4,
        'modeling' => 8,
        'scripting' => 16,
        'custom-characters' => 32,
    ];

    /**
     * The roles of the avatars, as w4os names them, by the lowest level of each (LEVELS): 250 and over the maintenance, 200 a god, 150
     * a liaison, 100 the customer service, 1 god-like, 0 and less a resident.
     */
    public const ROLES = [
        'resident' => self::GOD_NOT,
        'god-like' => self::GOD_LIKE,
        'customer-service' => self::GOD_CUSTOMER_SERVICE,
        'liaison' => self::GOD_LIAISON,
        'god' => self::GOD_FULL,
        'maintenance' => self::GOD_MAINTENANCE,
    ];

    /** The level of an account, the one it has back when it is active again (storedLevel()), as the database computes it. */
    private const LEVEL = '(CASE WHEN u.UserLevel < 0 THEN -1 - u.UserLevel ELSE u.UserLevel END)';

    /**
     * The bit of UserFlags of a profile that is published (the box "show in search" of the viewer, told by the search module:
     * SearchQuery::publish()): its web profile and the search of people show it.
     */
    public const PUBLISHED_FLAG = 1;

    /** The bit of UserFlags of an account that waits for the confirmation of its email (bit 12: OpenSimulator uses 0 to 11). */
    public const PENDING_FLAG = 1 << 12;

    /** The bit of UserFlags of an account banned for good (bit 13): a convention of the engine, as PENDING_FLAG; OpenSimulator has none. */
    public const BANNED_FLAG = 1 << 13;

    /** @var ?array<string,list<string>> the estates whose ban lists have each avatar, read once for a list (all()); null: not read */
    private ?array $estateBanned = null;

    /** What the name of an account may have besides letters and digits. */
    private const NAME_PUNCTUATION = '-';

    /** What is read of an account, a visit and a profile. */
    private const ACCOUNT_COLUMNS = 'u.PrincipalID, u.FirstName, u.LastName, u.Email, u.Created, u.UserLevel, u.UserFlags, u.UserTitle, u.active';
    private const VISIT_COLUMNS = 'g.HomeRegionID, g.LastRegionID, g.Online, g.Login, g.Logout';
    private const PROFILE_COLUMNS = 'p.profileImage, p.profileAboutText, p.profileURL, p.profilePartner, p.profileWantToMask, p.profileWantToText, p.profileSkillsMask, p.profileSkillsText, p.profileLanguages, p.profileFirstImage, p.profileFirstText';

    /** The account the services of the grid have, which is not a person: Robust makes it for itself. */
    private const TECHNICAL = ['GRID', 'SERVICES'];

    /** What a person leaves in the databases, by table: the condition that finds it, {id} being their UUID and {like} the start of the ids of GridUser. */
    private const LEFTOVERS = [
        'UserAccounts' => 'PrincipalID = {id}',
        'GridUser' => 'UserID = {id} OR UserID LIKE {like}',
        'userprofile' => 'useruuid = {id}',
        'tokens' => 'UUID = {id}',
        'auth' => 'UUID = {id}',
        'Avatars' => 'PrincipalID = {id}',
        'Presence' => 'UserID = {id}',
        'im_offline' => 'PrincipalID = {id}',
        'inventoryfolders' => 'agentID = {id}',
        'inventoryitems' => 'avatarID = {id}',
        'assets' => 'CreatorID = {id}',
    ];

    /** What of an account can be changed, by name: the column of UserAccounts. */
    private const ACCOUNT_SETTINGS = [
        'email' => 'Email',
        'title' => 'UserTitle',
        'level' => 'UserLevel',
        'active' => 'active',
        'status' => 'active',
        // The box "show in search": the first bit of UserFlags (PUBLISHED_FLAG), written with the state
        'published' => 'UserFlags',
    ];

    /** A profile as the grid makes it when an avatar opens it the first time (MySQLUserProfilesData). */
    private const NEW_PROFILE = [
        'profilePartner' => '00000000-0000-0000-0000-000000000000',
        'profileAllowPublish' => '0',
        'profileMaturePublish' => '0',
        'profileURL' => '',
        'profileWantToMask' => 0,
        'profileWantToText' => '',
        'profileSkillsMask' => 0,
        'profileSkillsText' => '',
        'profileLanguages' => '',
        'profileImage' => '00000000-0000-0000-0000-000000000000',
        'profileAboutText' => '',
        'profileFirstImage' => '00000000-0000-0000-0000-000000000000',
        'profileFirstText' => '',
    ];

    /** The parts of a name a new avatar cannot have, as w4os keeps them (beside the name of the grid). */
    public const RESERVED_NAMES = ['Default', 'Test', 'Admin'];

    /** What of a profile can be changed, by name: the column of userprofile. */
    private const PROFILE_SETTINGS = [
        'about' => 'profileAboutText',
        'profileUrl' => 'profileURL',
        'wants' => 'profileWantToText',
        'skills' => 'profileSkillsText',
        'languages' => 'profileLanguages',
        'firstLife' => 'profileFirstText',
        'wantsList' => 'profileWantToMask',
        'skillsList' => 'profileSkillsMask',
    ];

    /** The moment of the last visit as the database computes it: the later of Login and Logout, stored as texts. */
    private const LAST_SEEN = 'CASE WHEN COALESCE(g.Login + 0, 0) > COALESCE(g.Logout + 0, 0) THEN COALESCE(g.Login + 0, 0) ELSE COALESCE(g.Logout + 0, 0) END';

    /** The columns a list can be sorted on, by their name in the collection: the expressions they are sorted by. */
    private const SORTS = [
        'name' => ['u.FirstName', 'u.LastName'],
        'email' => ['u.Email'],
        'created' => ['u.Created'],
        'level' => [self::LEVEL],
        'active' => ['u.active'],
        'lastSeen' => [self::LAST_SEEN],
        'online' => ["CASE WHEN g.Online = 'true' THEN 1 ELSE 0 END"],
        // What it is: the condition of the models is the grid's (order())
        'type' => [],
    ];

    /** The sorts and filters that need the visits in the same query. */
    private const NEEDS_VISITS = ['lastSeen', 'online'];

    public function id(): string
    {
        return (string) $this->row['PrincipalID'];
    }

    public function uuid(): string
    {
        return $this->id();
    }

    public function name(): string
    {
        return trim($this->row['FirstName'] . ' ' . $this->row['LastName']);
    }

    public function slug(): string
    {
        return Slug::slug($this->name());
    }

    public function firstName(): string
    {
        return (string) ($this->row['FirstName'] ?? '');
    }

    public function lastName(): string
    {
        return (string) ($this->row['LastName'] ?? '');
    }

    /** The grid a visitor comes from (host:port), empty for an avatar of this grid. */
    public function grid(): string
    {
        return (string) ($this->row['grid'] ?? '');
    }

    /** Whether it is an avatar of this grid, not a visitor from another. */
    public function isLocal(): bool
    {
        return $this->grid() === '';
    }

    /** First.Last@grid as a viewer shows it; the UUID for someone whose name is not known. */
    public function label(): string
    {
        if ($this->firstName() === '' && $this->lastName() === '') {
            return $this->id();
        }

        return $this->firstName() . '.' . $this->lastName() . ($this->isLocal() ? '' : '@' . $this->grid());
    }

    /** The name of the avatar for the other grids: first.last@host:port. */
    public function uri(): ?string
    {
        $grid = $this->isLocal() ? $this->config->gridUri() : $this->grid();

        return $grid === null || $grid === ''
            ? null
            : strtolower($this->firstName() . '.' . $this->lastName()) . '@' . $grid;
    }

    /** The user flags of the account. */
    public function flags(): int
    {
        return (int) ($this->row['UserFlags'] ?? 0);
    }

    /** The user level, the one it has back once it is active when it is not. */
    public function level(): int
    {
        return self::levelOf((int) ($this->row['UserLevel'] ?? 0));
    }

    /** active, disabled or pending (STATUSES). */
    public function status(): string
    {
        return self::statusOf(
            (int) ($this->row['UserLevel'] ?? 0),
            (int) ($this->row['UserFlags'] ?? 0),
            (int) ($this->row['active'] ?? 1),
        );
    }

    /** The state of an account from its columns: its level, its flags and `active` (1 when it is not known, as Robust gives it). */
    public static function statusOf(int $level, int $flags, int $active = 1): string
    {
        return match (true) {
            ($flags & self::BANNED_FLAG) !== 0 => 'banned',
            ($flags & self::PENDING_FLAG) !== 0 => 'pending',
            $level < 0 || $active === 0 => 'disabled',
            default => 'active',
        };
    }

    /**
     * The estates whose ban lists have it, by their ids (Estate::id()): the bans of a region or a parcel are not counted. None when
     * no database of estates can be read.
     *
     * @return list<string>
     */
    public function estateBans(): array
    {
        $this->estateBanned ??= self::estateBanLists($this->config);

        return $this->estateBanned[strtolower((string) $this->uuid())] ?? [];
    }

    /**
     * The avatars in the ban lists of the estates of the grid, read once: each with the ids of the estates that ban it.
     *
     * @return array<string,list<string>>
     */
    private static function estateBanLists(GridConfig $config): array
    {
        try {
            $estates = Estate::all($config)->items();
        } catch (\OpenSim\Exceptions\ChannelUnavailable) {
            return [];
        }
        $banned = [];
        foreach ($estates as $estate) {
            foreach ($estate->bans() as $ban) {
                $ban['uuid'] === '' || ($banned[$ban['uuid']][] = $estate->id());
            }
        }

        return $banned;
    }

    /**
     * Put it in the ban lists of these estates (their ids) and take it out of the others.
     *
     * @param list<string>|string|bool $estates the ids; true: every estate, false: none
     * @throws InvalidValue for an estate the grid does not have
     */
    private function banInEstates(array|string|bool $estates): void
    {
        $all = Estate::all($this->config)->items();
        $ids = array_map(static fn(Estate $estate): string => $estate->id(), $all);
        $wanted = $estates === true ? $ids : ($estates === false ? [] : array_map('strval', (array) $estates));
        foreach (array_diff($wanted, $ids) as $unknown) {
            throw InvalidValue::because('invalid-request', "the grid has no estate '$unknown': " . implode(', ', $ids));
        }
        $uuid = (string) $this->uuid();
        foreach ($all as $estate) {
            in_array($estate->id(), $wanted, true) ? $estate->ban($uuid) : ($estate->isBanned($uuid) && $estate->unban($uuid));
        }
        $this->estateBanned = null;
    }

    /** The level an account has when it is active, from its UserLevel. */
    public static function levelOf(int $stored): int
    {
        return $stored < 0 ? -1 - $stored : $stored;
    }

    /** The UserLevel of an account of this level, active or not. */
    public static function storedLevel(int $level, bool $active): int
    {
        return $active ? $level : -1 - max(0, $level);
    }

    /** The role of the avatar, by its level (ROLES). */
    public function role(): string
    {
        return self::roleOf($this->level());
    }

    /** The role of a level (ROLES): the one of the highest lowest level it reaches. */
    public static function roleOf(int $level): string
    {
        $role = 'resident';
        foreach (self::ROLES as $name => $lowest) {
            if ($level >= $lowest) {
                $role = $name;
            }
        }

        return $role;
    }

    /** Whether the level of the avatar is the one of its role (0, 1, 100, 150, 200, 250), not one in between. */
    public function hasStandardLevel(): bool
    {
        return $this->level() === self::ROLES[$this->role()];
    }

    /** Whether its profile is published: the box "show in search" of the viewer (PUBLISHED_FLAG). */
    public function isPublished(): bool
    {
        return ($this->flags() & self::PUBLISHED_FLAG) !== 0;
    }

    public function isGod(): bool
    {
        return self::isGodLevel($this->level());
    }

    /** Whether a level is the one of a god (GOD_FULL, and GOD_MAINTENANCE above it): what a bridge asks, never a constant. */
    public static function isGodLevel(int $level): bool
    {
        return $level >= self::GOD_FULL;
    }

    /** Whether the grid says the avatar is in the world now. */
    public function isOnline(): bool
    {
        return ($this->row['Online'] ?? 'false') === 'true';
    }

    public function isEnabled(): bool
    {
        return $this->status() === 'active';
    }

    /** What it is (TYPES): the account of the services of the grid, a model of the grid ([Models]), else a user. */
    public function type(): string
    {
        return match (true) {
            $this->isTechnical() => 'service',
            AvatarModel::matches($this->config, (string) ($this->row['PrincipalID'] ?? ''), (string) ($this->row['FirstName'] ?? ''), (string) ($this->row['LastName'] ?? ''), (int) ($this->row['active'] ?? 1) === 1) => 'model',
            default => 'user',
        };
    }

    /** Whether it is an account of the services of the grid, not a person. */
    public function isTechnical(): bool
    {
        return [$this->row['FirstName'], $this->row['LastName']] === self::TECHNICAL;
    }

    /**
     * What it published in its profile as picks (the places it likes), with what the viewer shows of each (Avatar::place()).
     *
     * @return list<array<string,mixed>> the rows of userpicks (name, description, simname, posglobal, gatekeeper, pickuuid,
     *     snapshotuuid, user...) and picture, parcel, region, position, teleport
     */
    public function picks(): array
    {
        $pdo = Connections::pdo($this->config, Database::Profiles);
        $rows = $pdo === null
            ? []
            : $pdo->get_results(
                "SELECT * FROM userpicks WHERE creatoruuid = :id AND enabled = 'true' ORDER BY sortorder, name",
                ['id' => $this->id()],
                \PDO::FETCH_ASSOC,
            );

        // The parcel of a pick is in the column `user`, as the core keeps it
        return array_map(fn(array $row): array => $this->place($row, (string) ($row['user'] ?? '')), $rows);
    }

    /**
     * Its classifieds that have not expired, with what the viewer shows of each (Avatar::place()).
     *
     * @return list<array<string,mixed>> the rows of classifieds (name, description, simname, parcelname, posglobal, creationdate,
     *     expirationdate, classifieduuid, snapshotuuid...) and picture, parcel, region, position, teleport
     */
    public function classifieds(?int $now = null): array
    {
        $pdo = Connections::pdo($this->config, Database::Profiles);
        // An integer in the statement: a bound value would be text, which a number is not compared with in every database
        $now = (int) ($now ?? time());

        $rows = $pdo === null
            ? []
            : $pdo->get_results(
                "SELECT * FROM classifieds WHERE creatoruuid = :id AND expirationdate + 0 > $now ORDER BY creationdate DESC",
                ['id' => $this->id()],
                \PDO::FETCH_ASSOC,
            );

        return array_map(fn(array $row): array => $this->place($row, (string) ($row['parcelname'] ?? '')), $rows);
    }

    /**
     * The groups it shows in its profile (the box of each group in the viewer, ListInProfile), by name: each with the title it
     * wears in the group (the one of its role, null without one) and the insignia of the group (`picture`, null without one).
     * None when the grid has no groups.
     *
     * @return list<array{id:string,name:string,title:?string,picture:?string}>
     */
    public function groups(): array
    {
        $pdo = Connections::pdo($this->config, Database::forTable('os_groups_membership') ?? Database::Robust);
        if ($pdo === null || !$pdo->tables_exist(['os_groups_groups', 'os_groups_membership', 'os_groups_roles'])) {
            return [];
        }
        $rows = $pdo->get_results(
            'SELECT g.GroupID, g.Name, g.InsigniaID, r.Title FROM os_groups_membership m'
                . ' JOIN os_groups_groups g ON g.GroupID = m.GroupID'
                . ' LEFT JOIN os_groups_roles r ON r.GroupID = m.GroupID AND r.RoleID = m.SelectedRoleID'
                . ' WHERE m.PrincipalID = :id AND m.ListInProfile = 1 ORDER BY g.Name',
            ['id' => $this->id()],
            \PDO::FETCH_ASSOC,
        );

        return array_map(static fn(array $row): array => [
            'id' => (string) $row['GroupID'],
            'name' => (string) $row['Name'],
            'title' => trim((string) ($row['Title'] ?? '')) === '' ? null : (string) $row['Title'],
            'picture' => trim((string) ($row['InsigniaID'] ?? '')) === '' || Uuid::isNullKey($row['InsigniaID'])
                ? null
                : (string) $row['InsigniaID'],
        ], is_array($rows) ? $rows : []);
    }

    /**
     * What the viewer shows of a pick or a classified, added to its row: `picture` (the UUID of its snapshot, null without one),
     * `parcel`, `region`, `position` (x, y, z in the region: its global position less the corner of the region, the rest of the
     * division by 256 for a region the grid does not have) and `teleport` (hop://, to the grid of its gatekeeper).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function place(array $row, string $parcel): array
    {
        $text = static fn(mixed $value): ?string => trim((string) $value) === '' ? null : trim((string) $value);
        $picture = $text($row['snapshotuuid'] ?? null);
        $region = $text($row['simname'] ?? null);
        $here = $this->config->gridUri();
        $host = strtolower(rtrim((string) preg_replace('#^https?://#i', '', (string) ($row['gatekeeper'] ?? '')), '/'));
        $host = $host === '' ? $here : $host;
        $position = null;
        if (preg_match('/^<?\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)/', (string) ($row['posglobal'] ?? ''), $m) === 1) {
            [$x, $y] = $host === $here && $region !== null ? $this->regionCorner($region) ?? [null, null] : [null, null];
            $position = [
                (int) ($x === null ? fmod((float) $m[1], 256) : (float) $m[1] - $x),
                (int) ($y === null ? fmod((float) $m[2], 256) : (float) $m[2] - $y),
                (int) Destination::height($m[3]),
            ];
        }

        return $row + [
            'picture' => Uuid::isNullKey($picture) ? null : $picture,
            'parcel' => $text($parcel),
            'region' => $region,
            'position' => $position,
            'teleport' => $host === null || $region === null
                ? null
                : (string) Destination::format("$host:$region" . ($position === null ? '' : '/' . implode('/', $position)), 8),
        ];
    }

    /**
     * Where a region of the grid begins, in meters; null when the grid does not have it or its regions cannot be read.
     *
     * @return array{0:int,1:int}|null
     */
    private function regionCorner(string $region): ?array
    {
        $row = Connections::pdo($this->config, Database::forTable('regions') ?? Database::Robust)?->get_row(
            'SELECT locX, locY FROM regions WHERE regionName = :name',
            ['name' => $region],
            \PDO::FETCH_ASSOC,
        );

        return is_array($row) ? [(int) $row['locX'], (int) $row['locY']] : null;
    }

    public function lastSeen(): ?\DateTimeImmutable
    {
        return self::date($this->lastSeenTime());
    }

    /**
     * What a profile can say its avatar wants, the bit 0 first (`profileWantToMask`).
     *
     * @return list<string>
     */
    /**
     * The flags of the wants and the skills of a profile, for a site to show them as check boxes: the name of each, its bit, and the
     * key of its text in the catalog of the sites (want_build, skill_event_planning).
     *
     * @return array{wants:list<array{name:string,value:int,key:string}>,skills:list<array{name:string,value:int,key:string}>}
     */
    public static function profileFlags(): array
    {
        $list = static fn(array $flags, string $prefix): array => array_map(
            static fn(string $name, int $value): array => [
                'name' => $name,
                'value' => $value,
                'key' => $prefix . str_replace('-', '_', $name),
            ],
            array_keys($flags),
            array_values($flags),
        );

        return ['wants' => $list(self::WANTS, 'want_'), 'skills' => $list(self::SKILLS, 'skill_')];
    }

    /**
     * The number of the flags a profile is given: their names, or the number itself (what the viewer keeps).
     *
     * @param array<string,int> $flags WANTS or SKILLS
     * @throws InvalidValue for a name that is not a flag, a number with a bit that is not one, anything else
     */
    private static function profileMask(mixed $value, array $flags, string $what): int
    {
        $all = array_sum($flags);
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $mask = (int) $value;
            if (($mask & ~$all) !== 0) {
                throw InvalidValue::because('invalid-request', "$mask is not a number of the $what (0 to $all)");
            }

            return $mask;
        }
        if (!is_array($value)) {
            throw InvalidValue::because('invalid-request', "the $what are a list of " . implode(', ', array_keys($flags)) . ', or their number');
        }
        $mask = 0;
        foreach ($value as $name) {
            $mask |= $flags[(string) $name] ?? throw InvalidValue::because(
                'invalid-request',
                "'$name' is not one of the $what: " . implode(', ', array_keys($flags)),
            );
        }

        return $mask;
    }

    public static function wantLabels(): array
    {
        return [_('Build'), _('Explore'), _('Meet'), _('Group'), _('Buy'), _('Sell'), _('Be Hired'), _('Hire')];
    }

    /**
     * What a profile can say its avatar is good at, the bit 0 first (`profileSkillsMask`).
     *
     * @return list<string>
     */
    public static function skillLabels(): array
    {
        return [
            _('Textures'),
            _('Architecture'),
            _('Event Planning'),
            _('Modeling'),
            _('Scripting'),
            _('Custom Characters'),
        ];
    }

    public function detail(): array
    {
        $row = $this->row;

        return [
            'id' => $this->id(),
            'uuid' => $this->uuid(),
            'name' => $this->name(),
            'slug' => $this->slug(),
            'uri' => $this->uri(),
            'firstName' => $this->firstName(),
            'lastName' => $this->lastName(),
            'grid' => $this->grid() ?: null,
            'local' => $this->isLocal(),
            'email' => $row['Email'] ?? null,
            'title' => $row['UserTitle'] ?? '',
            'created' => isset($row['Created']) ? (int) $row['Created'] : null,
            'level' => $this->level(),
            'isGod' => $this->isGod(),
            'role' => $this->role(),
            'standardLevel' => $this->hasStandardLevel(),
            'flags' => $this->flags(),
            'published' => $this->isPublished(),
            'active' => $this->isEnabled(),
            'status' => $this->status(),
            'banned' => $this->status() === 'banned',
            // The estates whose ban lists have it (their ids), whether any does: a hard ban is the account's, these are the estates'
            'estateBans' => $this->estateBans(),
            'estateBanned' => $this->estateBans() !== [],
            // The ban it has, the hard one first: hard, estates, or none
            'ban' => $this->status() === 'banned' ? 'hard' : ($this->estateBans() !== [] ? 'estates' : null),
            'online' => $this->isOnline(),
            // Its status, online for an active avatar in the world: what the list shows in one icon
            'accountState' => $this->status() === 'active' && $this->isOnline() ? 'online' : $this->status(),
            'lastSeen' => $this->lastSeenTime() ?: null,
            'homeRegion' => $row['HomeRegionID'] ?? null,
            'lastRegion' => $row['LastRegionID'] ?? null,
            'technical' => $this->isTechnical(),
            'type' => $this->type(),
            // Its web profile: the address the grid gives with First.Last (profilePage()), null when it gives none
            'profilePage' => $this->isLocal() ? self::profilePage($this->config, $this->name()) : null,
            'profileImage' => $row['profileImage'] ?? null,
            'about' => $row['profileAboutText'] ?? null,
            // Its web address, only when the grid allows it, as the viewers show it
            'profileUrl' => self::profileWebUrls($this->config) ? $row['profileURL'] ?? null : null,
            'partner' => self::uuidOrNull($row['profilePartner'] ?? null),
            'wants' => $row['profileWantToText'] ?? null,
            'wantsList' => Bitmask::names((int) ($row['profileWantToMask'] ?? 0), array_flip(self::WANTS)),
            'skills' => $row['profileSkillsText'] ?? null,
            'skillsList' => Bitmask::names((int) ($row['profileSkillsMask'] ?? 0), array_flip(self::SKILLS)),
            'languages' => $row['profileLanguages'] ?? null,
            'firstImage' => self::uuidOrNull($row['profileFirstImage'] ?? null),
            'firstLife' => $row['profileFirstText'] ?? null,
        ];
    }

    public function channels(): array
    {
        return Connections::pdo($this->config, 'UserAccountService') === null ? [] : ['database'];
    }

    /**
     * The name of an account: a first and a last name of letters, digits and hyphens, 63 characters at most each (the login box of
     * the viewers takes 128 for both), whatever it is for (to make an account, to pick or import one). The dot and the at sign are
     * the hypergrid's (First.Last@grid), the underscore is taken for a space in many places of OpenSimulator, and the viewers take
     * the space, the dot and the underscore for what separates the first name from the last.
     */
    public static function validateName(mixed $value): ?string
    {
        $word = '[A-Za-z0-9' . preg_quote(self::NAME_PUNCTUATION, '/') . ']+';
        $name = trim((string) $value);
        if (preg_match("/^$word $word$/", $name) !== 1) {
            return _('invalid (First Last)');
        }

        return max(array_map('strlen', explode(' ', $name))) > 63 ? _('too long (63 max)') : null;
    }

    /**
     * The name of a new avatar of a grid, as w4os takes it: a name (validateName) that has no part w4os keeps, in any case: Default
     * (the avatar models), Test, Admin, and the name of the grid without its spaces.
     *
     * @param string $grid the name of the grid, '' when it has none
     */
    public static function validateNewName(mixed $value, string $grid = ''): ?string
    {
        $wrong = self::validateName($value);
        if ($wrong !== null) {
            return $wrong;
        }
        $reserved = array_filter(array_map('strtolower', [...self::RESERVED_NAMES, str_replace(' ', '', $grid)]));

        return array_intersect(explode(' ', strtolower(trim((string) $value))), $reserved) !== []
            ? sprintf(_('reserved (%s and the name of the grid)'), implode(', ', self::RESERVED_NAMES))
            : null;
    }

    /**
     * A new name in Latin letters without accents (the viewers lose the accents), every alphabet transliterated, a capital to each
     * part (capitalizeName()).
     */
    public static function normalizeName(string $name): string
    {
        return self::capitalizeName(Slug::latin($name, self::NAME_PUNCTUATION));
    }

    /**
     * A new name the person did not mind the case of, written as w4os writes it. All in small letters or all in capitals, each part
     * gets a capital and the rest small letters, each piece of a hyphenated part too: "bart simpson" and "BART SIMPSON" are "Bart
     * Simpson", "JEAN-PIERRE dupont" is "Jean-Pierre Dupont". Capitals and small letters mixed are the person's choice, kept as typed
     * ("McFly", "mcFly", "JeanPaul deLuxe", "BART simpson"). Digits do not count.
     */
    public static function capitalizeName(string $name): string
    {
        $name = trim($name);
        if (preg_match('/[A-Z]/', $name) === 1 && preg_match('/[a-z]/', $name) === 1) {
            return $name;
        }
        $piece = static fn(string $piece): string => ucfirst(strtolower($piece));

        return implode(
            ' ',
            array_map(
                static fn(string $part): string => implode(self::NAME_PUNCTUATION, array_map($piece, explode(self::NAME_PUNCTUATION, $part))),
                explode(' ', $name),
            ),
        );
    }

    /**
     * The password of an account that is made. The double quote is out: the implementations and the console do not agree on it.
     * 16 characters at most: the password box of the login panel of the viewers does not take more.
     */
    public static function validatePassword(mixed $value): ?string
    {
        return match (true) {
            preg_match('/["\r\n]/', (string) $value) === 1 => _('invalid (no double quote)'),
            mb_strlen((string) $value) < 6 => _('too short (6 min)'),
            mb_strlen((string) $value) > 16 => _('too long (16 max)'),
            default => null,
        };
    }

    /** Whether an email is an address: no space, quote or backslash around the at sign. */
    public static function validEmail(string $email): bool
    {
        return preg_match('/^[^\s"\'\\\\]+@[^\s"\'\\\\]+$/', $email) === 1;
    }

    /** The email of an account that is made, or nothing. */
    public static function validateEmail(mixed $value): ?string
    {
        return trim((string) $value) === '' || self::validEmail(trim((string) $value)) ? null : _('invalid');
    }

    /** Whether a hash and a salt are the ones Robust keeps for a password: 32 hexadecimal characters, and a salt of letters and digits. */
    public static function validPasswordHash(string $hash, string $salt): bool
    {
        return preg_match('/^[0-9a-f]{32}$/i', $hash) === 1 && preg_match('/^[0-9a-zA-Z]{1,64}$/', $salt) === 1;
    }

    /**
     * What `create()` checks before it writes anything, the name being free: the name, the password, the email, the status, the UUID,
     * the model. A registration checks it all the same before it keeps the request (AvatarAccess::requestRegistration).
     *
     * @param array<string,mixed> $values as `create()` takes them
     * @return array{first:string,last:string,email:string,password:string,hash:?array,uuid:?string,status:string,model:?AvatarModel}
     * @throws InvalidValue for a value that is wrong; its reason says which
     * @throws ChannelUnavailable when the database of the accounts cannot be reached
     */
    public static function check(GridConfig $config, array $values): array
    {
        [$first, $last] = isset($values['name'])
            ? array_pad(explode(' ', trim((string) $values['name']), 2), 2, '')
            : [(string) ($values['firstName'] ?? ''), (string) ($values['lastName'] ?? '')];
        // A name a person chooses gets its capitals; a reserved one (a model, an account that is moved) is kept as it is given
        if (!Result::isTrue($values['reservedName'] ?? false)) {
            // The whole name decides: capitals and small letters mixed in it keep both parts as they are typed
            [$first, $last] = array_pad(explode(' ', self::capitalizeName(trim($first) . ' ' . trim($last)), 2), 2, '');
        }
        if (self::validateName("$first $last") !== null) {
            throw InvalidValue::because(
                'name-invalid',
                "'$first $last' is not the name of an avatar: a first and a last name, letters, digits and -",
            );
        }
        if (
            !Result::isTrue($values['reservedName'] ?? false) &&
            ($wrong = self::validateNewName("$first $last", (string) $config->find('GridInfoService', 'gridname'))) !==
                null
        ) {
            throw InvalidValue::because('name-reserved', "the name '$first $last' is $wrong");
        }
        $hash = isset($values['passwordHash']) ? (array) $values['passwordHash'] : null;
        $password = (string) ($values['password'] ?? '');
        if ($hash === null && $password === '') {
            throw InvalidValue::because('password-required', "the avatar '$first $last' needs a password");
        }
        if ($hash === null && ($wrong = self::validatePassword($password)) !== null) {
            throw InvalidValue::because('password-invalid', "the password is $wrong");
        }
        if (self::validateEmail($values['email'] ?? '') !== null) {
            throw InvalidValue::because('email-invalid', "'{$values['email']}' is not an email");
        }
        $status = self::requestedStatus($values, 'active');
        $uuid = isset($values['uuid']) ? strtolower((string) $values['uuid']) : null;
        if ($uuid !== null && !Uuid::isValid($uuid)) {
            throw new \InvalidArgumentException("'$uuid' is not a UUID");
        }

        $accounts = self::connect($config, 'UserAccountService', null, "create the avatar $first $last");
        if (
            (int) $accounts->get_var('SELECT COUNT(*) FROM UserAccounts WHERE FirstName = :f AND LastName = :l', [
                'f' => $first,
                'l' => $last,
            ]) > 0
        ) {
            throw InvalidValue::because('name-taken', "an avatar called '$first $last' exists already");
        }
        $model = null;
        if (trim((string) ($values['model'] ?? '')) !== '') {
            $model =
                AvatarModel::find($config, trim((string) $values['model'])) ??
                throw InvalidValue::because('model-unknown', "'{$values['model']}' is not an avatar model of the grid");
        }

        return [
            'first' => $first,
            'last' => $last,
            'email' => trim((string) ($values['email'] ?? '')),
            'password' => $password,
            'hash' => $hash,
            'uuid' => $uuid,
            'status' => $status,
            'model' => $model,
        ];
    }

    /**
     * Make an avatar: its account, its password, its inventory with the default outfit (or the one of a model), and its home when it
     * is given. Each is written in the database of its service (the accounts, the passwords, the inventories may be in different
     * ones), the account last so that nothing shows of an avatar that could not be made.
     *
     * @param array<string,mixed> $values `name` ("First Last") or `firstName` and `lastName`, `email`, and `password` or `passwordHash`
     *        (`['hash' => ..., 'salt' => ...]`, an account that is moved); `uuid` and `home` (the id of a region) when they are known;
     *        `model`, the UUID or the name of an avatar model (AvatarModel) whose outfit it wears instead of the default one;
     *        `status` (STATUSES), or `active` false for a disabled one; `reservedName` true for a name w4os keeps (a model: Default...)
     *        or an account that is moved: its name is kept as it is given, any other all in small letters or all in capitals gets
     *        its capitals (capitalizeName()); `published` false for a profile that is not public, which a new one is (PUBLISHED_FLAG),
     *        but for a reserved name
     * @throws InvalidValue for a name that is not one or is reserved or taken, a missing or wrong password, a wrong email, a model
     *         that is not one; its reason says which
     * @throws ChannelUnavailable when a database cannot be reached or refuses
     */
    public static function create(GridConfig $config, array $values): static
    {
        $new = self::check($config, $values);
        ['first' => $first, 'last' => $last, 'password' => $password, 'hash' => $hash, 'uuid' => $uuid] = $new;
        $status = $new['status'];
        $outfit = $new['model']?->outfit();
        // A new profile is public ("show in search"), but for a reserved name (a model, an account that is moved)
        $published = isset($values['published'])
            ? Result::isTrue($values['published'])
            : !Result::isTrue($values['reservedName'] ?? false);

        $made = AccountWriter::standard()->tables(
            $first,
            $last,
            $new['email'],
            $password,
            $hash,
            $uuid,
            $outfit,
            $status === 'active',
            self::storedLevel(0, $status === 'active'),
            ($status === 'pending' ? self::PENDING_FLAG : 0) | ($published ? self::PUBLISHED_FLAG : 0),
        );
        // Each database gets its rows in one transaction: the tables of the same database go together (the usual case: one)
        $groups = [];
        foreach ($made['tables'] as $table => $statements) {
            $what = Database::forTable($table) ?? 'UserAccountService';
            $key = Connections::key($config, $what) ?? $table;
            $groups[$key]['what'] ??= $what;
            $groups[$key]['statements'] = [...$groups[$key]['statements'] ?? [], ...$statements];
            $groups[$key]['tables'][] = $table;
        }
        $written = [];
        try {
            foreach ($groups as $group) {
                $pdo = self::connect($config, $group['what'], null, "create the avatar $first $last");
                $own = !$pdo->inTransaction() && $pdo->beginTransaction();
                try {
                    foreach ($group['statements'] as $sql) {
                        $pdo->exec($sql);
                    }
                    $own && $pdo->commit();
                } catch (\PDOException $e) {
                    $own && $pdo->inTransaction() && $pdo->rollBack();

                    throw $e;
                }
                array_push($written, ...$group['tables']);
            }
            isset($values['home']) && self::setHome($config, $made['id'], (string) $values['home']);
        } catch (\PDOException $e) {
            throw ChannelUnavailable::for(
                "create the avatar $first $last",
                $e->getMessage() . ($written === [] ? '' : ' (written before: ' . implode(', ', $written) . ')'),
            );
        }

        return static::find($config, $made['id']) ??
            throw ChannelUnavailable::for(
                "create the avatar $first $last",
                'the account was written but cannot be read back',
            );
    }

    /**
     * Change an avatar. The account: `email`, `title`, `published` ("show in search"), `level` or `role` (the level of the role, or the one given with it when it is of
     * that role), `active`; the password: `password`; the home: `home` (the id of a
     * region); the profile: `about`, `profileUrl`, `wants`, `skills`, `languages`, `firstLife`, the profile being made as the grid
     * makes it when the avatar has none yet. The name does not change. All is changed or nothing: the values are checked first, and
     * each database is written in a transaction.
     *
     * @throws ChannelUnavailable when a database cannot be reached or refuses: nothing is changed
     * @throws InvalidValue for a value that is not one of these, an empty password, an email that is not one
     */
    public function update(array $values): void
    {
        // A role is the level of its own (ROLES); a level given with it must be of that role, and is the one kept
        if (array_key_exists('role', $values)) {
            $role = (string) $values['role'];
            if (!isset(self::ROLES[$role])) {
                throw InvalidValue::because(
                    'role-unknown',
                    "'$role' is not a role: " . implode(', ', array_keys(self::ROLES)),
                );
            }
            if (array_key_exists('level', $values) && self::roleOf((int) $values['level']) !== $role) {
                throw InvalidValue::because(
                    'role-level-conflict',
                    "the level {$values['level']} is not one of the role $role (" .
                        self::roleOf((int) $values['level']) .
                        ')',
                );
            }
            $values['level'] = (int) ($values['level'] ?? self::ROLES[$role]);
            unset($values['role']);
        }
        // A hard ban is a state (status banned), lifted by `banned` false only; the bans of the estates are their lists
        if (array_key_exists('banned', $values)) {
            $banned = Result::isTrue($values['banned']);
            unset($values['banned']);
            $banned ? ($values['status'] = 'banned') : ($values['unban'] = true);
        }
        $estateBans = array_key_exists('estateBans', $values) ? (array) $values['estateBans'] : null;
        array_key_exists('estateBanned', $values) && ($estateBans = Result::isTrue($values['estateBanned']));
        unset($values['estateBans'], $values['estateBanned']);
        // A web address the grid does not allow is ignored, as the viewers do
        if (!self::profileWebUrls($this->config)) {
            unset($values['profileUrl']);
        }
        // The check boxes of the profile: their names, or the number the viewer keeps
        isset($values['wantsList']) && ($values['wantsList'] = self::profileMask($values['wantsList'], self::WANTS, 'wants'));
        isset($values['skillsList']) && ($values['skillsList'] = self::profileMask($values['skillsList'], self::SKILLS, 'skills'));
        foreach ($values as $name => $value) {
            if (
                !isset(self::ACCOUNT_SETTINGS[$name]) &&
                !isset(self::PROFILE_SETTINGS[$name]) &&
                !in_array($name, ['password', 'home', 'unban'], true)
            ) {
                throw InvalidValue::because('field-unknown', "an avatar has no changeable '$name'");
            }
        }
        if (isset($values['password']) && (string) $values['password'] === '') {
            throw InvalidValue::because('password-required', 'a password cannot be empty');
        }
        // The same rules as at the registration
        if (isset($values['password']) && ($wrong = self::validatePassword($values['password'])) !== null) {
            throw InvalidValue::because('password-invalid', "the password is $wrong");
        }
        if (isset($values['email']) && self::validateEmail($values['email']) !== null) {
            throw InvalidValue::because('email-invalid', "'{$values['email']}' is not an email");
        }
        // The state is read again: this avatar may have been changed since it was read (enabled, then its level changed)
        $state = ['active' => 1, 'status' => 1, 'level' => 1, 'published' => 1, 'unban' => 1];
        $current = array_intersect_key($values, $state) !== [] ? static::find($this->config, $this->id()) ?? $this : $this;
        $was = $current->status();
        $unban = (bool) ($values['unban'] ?? false);
        unset($values['unban']);
        $status = $unban && $was === 'banned' && !isset($values['status']) && !isset($values['active']) ? 'active' : self::requestedStatus($values, $was);
        // A ban is lifted by an unban only: an enable, a change of state, does not
        $was === 'banned' && $status !== 'banned' && !$unban && throw InvalidValue::because(
            'account-banned',
            'this avatar is banned: only an unban (banned false) lets it in again',
        );
        $id = $this->id();
        $writes = [];

        $account = array_diff_key(array_intersect_key($values, self::ACCOUNT_SETTINGS), $state);
        if ($account !== [] || array_intersect_key($values, $state) !== [] || $status !== $was) {
            $set = [];
            $params = ['id' => $id];
            foreach ($account as $name => $value) {
                $set[] = self::ACCOUNT_SETTINGS[$name] . " = :$name";
                $params[$name] = is_string($value) ? trim($value) : $value;
            }
            // The state is told by three columns together (STATUSES)
            $set[] = 'active = :active, UserLevel = :level, UserFlags = :flags';
            $params['active'] = $status === 'active' ? 1 : 0;
            $params['level'] = self::storedLevel((int) ($values['level'] ?? $current->level()), $status === 'active');
            $params['flags'] = ($current->flags() & ~(self::PENDING_FLAG | self::BANNED_FLAG))
                | ($status === 'pending' ? self::PENDING_FLAG : 0)
                | ($status === 'banned' ? self::BANNED_FLAG : 0);
            if (array_key_exists('published', $values)) {
                $params['flags'] = Result::isTrue($values['published'])
                    ? $params['flags'] | self::PUBLISHED_FLAG
                    : $params['flags'] & ~self::PUBLISHED_FLAG;
            }
            $writes[] = [
                'UserAccountService',
                static fn(OSPDO $pdo) => self::run(
                    $pdo,
                    'UPDATE UserAccounts SET ' . implode(', ', $set) . ' WHERE PrincipalID = :id',
                    $params,
                ),
            ];
        }
        if (isset($values['password'])) {
            $writes[] = ['AuthenticationService', self::passwordWrite($id, (string) $values['password'])];
        }
        if (isset($values['home'])) {
            $writes[] = ['GridUserService', self::homeWrite($id, (string) $values['home'])];
        }
        $profile = array_intersect_key($values, self::PROFILE_SETTINGS);
        if ($profile !== []) {
            $writes[] = [Database::Profiles, self::profileWrite($id, $profile)];
        }

        self::writeAll($this->config, $writes, 'change ' . $this->name());
        $estateBans === null || $this->banInEstates($estateBans);
        if ($was === 'pending' && $status !== 'pending') {
            self::forgetConfirmation($this->config, $id);
        }
    }

    /**
     * The state asked in values, or the current one: `status`, or `active` (true: active, false: disabled).
     *
     * @throws InvalidValue for a status that is not one of STATUSES
     */
    private static function requestedStatus(array $values, string $current): string
    {
        if (array_key_exists('status', $values)) {
            $status = strtolower(trim((string) $values['status']));
            in_array($status, self::STATUSES, true) ||
                throw InvalidValue::because('invalid-request', "'$status' is not a status: " . implode(', ', self::STATUSES));

            return $status;
        }

        return array_key_exists('active', $values) ? (Result::isTrue($values['active']) ? 'active' : 'disabled') : $current;
    }

    /** Forget the token of the confirmation of the email of an avatar that does not wait for it any more: it would do nothing. */
    private static function forgetConfirmation(GridConfig $config, string $id): void
    {
        try {
            AvatarTokens::for($config, new SystemClock())->forget($id, AvatarTokens::EMAIL_CONFIRMATION);
        } catch (\Throwable) {
            // A token left is refused anyway: the avatar is not pending
        }
    }

    /**
     * Write in the databases of their services, all or nothing: a transaction in each database, committed once every write is made.
     *
     * @param list<array{0:string,1:\Closure(OSPDO):mixed}> $writes the service (or Database::...) and what is written in its database
     * @throws ChannelUnavailable when a database cannot be reached or refuses: what was written is rolled back
     */
    private static function writeAll(GridConfig $config, array $writes, string $action): void
    {
        $open = [];
        try {
            foreach ($writes as [$what, $write]) {
                $pdo = self::connect($config, $what, null, $action);
                if (!isset($open[spl_object_id($pdo)]) && !$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $open[spl_object_id($pdo)] = $pdo;
                }
                $write($pdo);
            }
            foreach ($open as $pdo) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            foreach ($open as $pdo) {
                $pdo->inTransaction() && $pdo->rollBack();
            }

            throw $e instanceof \PDOException ? ChannelUnavailable::for($action, $e->getMessage()) : $e;
        }
    }

    /** A statement that throws when the database refuses it (OSPDO's helpers only log it). */
    private static function run(OSPDO $pdo, string $sql, array $params): \PDOStatement
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /** Whether a row is there, in a statement that counts it. */
    private static function exists(OSPDO $pdo, string $sql, string $id): bool
    {
        return (int) self::run($pdo, $sql, ['id' => $id])->fetchColumn() > 0;
    }

    /** The password, as Robust keeps it: a new salt and the hash of the password with it. */
    private static function passwordWrite(string $id, string $password): \Closure
    {
        return static function (OSPDO $pdo) use ($id, $password): void {
            $salt = bin2hex(random_bytes(16));
            $params = ['id' => $id, 'h' => AccountWriter::passwordHash($password, $salt), 's' => $salt];
            if (self::exists($pdo, 'SELECT COUNT(*) FROM auth WHERE UUID = :id', $id)) {
                self::run($pdo, 'UPDATE auth SET passwordHash = :h, passwordSalt = :s WHERE UUID = :id', $params);

                return;
            }
            self::run(
                $pdo,
                'INSERT INTO auth (UUID, passwordHash, passwordSalt, webLoginKey, accountType) VALUES (:id, :h, :s, :k, :t)',
                $params + ['k' => '00000000-0000-0000-0000-000000000000', 't' => 'UserAccount'],
            );
        };
    }

    /** The home of an avatar: the region and the position Robust gives to a home. */
    private static function homeWrite(string $id, string $regionId): \Closure
    {
        return static function (OSPDO $pdo) use ($id, $regionId): void {
            $params = ['id' => $id, 'r' => $regionId, 'p' => GridAccounts::HOME_POSITION, 'l' => '<0,1,0>'];
            self::run(
                $pdo,
                self::exists($pdo, 'SELECT COUNT(*) FROM GridUser WHERE UserID = :id', $id)
                    ? 'UPDATE GridUser SET HomeRegionID = :r, HomePosition = :p, HomeLookAt = :l WHERE UserID = :id'
                    : 'INSERT INTO GridUser (UserID, HomeRegionID, HomePosition, HomeLookAt) VALUES (:id, :r, :p, :l)',
                $params,
            );
        };
    }

    /**
     * The profile of an avatar, made first when the grid has not made it yet (the avatar never opened it): with the values the grid
     * gives a new one, every column of userprofile being required.
     *
     * @param array<string,mixed> $profile values by the names of PROFILE_SETTINGS
     */
    private static function profileWrite(string $id, array $profile): \Closure
    {
        return static function (OSPDO $pdo) use ($id, $profile): void {
            if (!self::exists($pdo, 'SELECT COUNT(*) FROM userprofile WHERE useruuid = :id', $id)) {
                $new = ['useruuid' => $id] + self::NEW_PROFILE;
                self::run(
                    $pdo,
                    'INSERT INTO userprofile (' .
                        implode(', ', array_keys($new)) .
                        ') VALUES (:' .
                        implode(', :', array_keys($new)) .
                        ')',
                    $new,
                );
            }
            $set = [];
            $params = ['id' => $id];
            foreach ($profile as $name => $value) {
                $set[] = self::PROFILE_SETTINGS[$name] . " = :$name";
                $params[$name] = (string) $value;
            }
            self::run($pdo, 'UPDATE userprofile SET ' . implode(', ', $set) . ' WHERE useruuid = :id', $params);
        };
    }

    /** The home of an avatar that is made. */
    private static function setHome(GridConfig $config, string $id, string $regionId): void
    {
        self::writeAll($config, [['GridUserService', self::homeWrite($id, $regionId)]], 'set the home of ' . $id);
    }

    public function enable(): bool
    {
        $this->update(['active' => true]);

        return true;
    }

    public function disable(): void
    {
        $this->update(['active' => false]);
    }

    /** A visitor of another grid, from its id in GridUser: <uuid>;<home of the grid>;<First Last>. */
    public static function visitor(GridConfig $config, string $userId, ?GridInfoCache $grids = null): static
    {
        $parts = explode(';', $userId);
        $name = preg_split('/\s+/', trim($parts[2] ?? ''), 2) ?: [];
        $grid = preg_replace('#^[a-z]+://|/+$#i', '', trim($parts[1] ?? '')) ?? '';
        // The grid of a visitor is what its get_grid_info says, not the address OpenSimulator kept when they came (it never refreshes
        // the visitors it knows): when the grid does not answer, what was kept is all there is
        if (
            $grids !== null &&
            ($login = Destination::loginParts((string) $grids->login(trim($parts[1] ?? '')))) !== null
        ) {
            $grid = $login['host'] . ($login['port'] !== null ? ':' . $login['port'] : '');
        }

        return new static($config, [
            'PrincipalID' => strtolower($parts[0]),
            'FirstName' => $name[0] ?? '',
            'LastName' => $name[1] ?? '',
            'grid' => $grid,
        ]);
    }

    /** Someone known by their UUID alone: who never came here can be banned all the same. */
    public static function unknown(GridConfig $config, string $uuid): static
    {
        return new static($config, ['PrincipalID' => strtolower($uuid), 'FirstName' => '', 'LastName' => '']);
    }

    /**
     * The people a name or a UUID stands for: the account of the grid, and the visitors of other grids that have come ("First Last",
     * "First.Last", "First.Last@grid"). A UUID nobody here has is someone all the same. None when no one has the name.
     *
     * @return list<static>
     * @throws \InvalidArgumentException when it is neither a name nor a UUID
     */
    public static function lookup(GridConfig $config, string $target, ?GridInfoCache $grids = null): array
    {
        $target = trim($target);
        $people = [];
        if (Uuid::isValid($target)) {
            $uuid = strtolower($target);
            $local = self::find($config, $uuid);
            $local !== null && ($people[] = $local);
            foreach (self::visits($config, "$uuid;%") as $userId) {
                $people[] = static::visitor($config, $userId, $grids);
            }

            // A UUID is enough to ban someone who never came here
            return $people !== [] ? $people : [static::unknown($config, $uuid)];
        }
        if (preg_match('/^([A-Za-z0-9_-]+)[. ]([A-Za-z0-9_-]+)(?:@(\S+))?$/', $target, $m) !== 1) {
            throw new \InvalidArgumentException(
                sprintf("'%s' is neither a name (First Last, First.Last@grid) nor a UUID", $target),
            );
        }
        [, $first, $last] = $m;
        $grid = $m[3] ?? '';
        if ($grid === '') {
            $local = self::find($config, "$first $last");
            $local !== null && ($people[] = $local);
        }
        // The visitors of other grids: GridUser keeps them as <uuid>;<home of the grid>;<First Last>
        foreach (
            self::visits(
                $config,
                '%;' . ($grid !== '' ? '%' . self::like($grid) . '%;' : '') . self::like("$first $last"),
            )
            as $userId
        ) {
            $people[] = static::visitor($config, $userId, $grids);
        }

        return $people;
    }

    /**
     * What this person left in the databases of the grid, each thing in the database of its service: the account, the visits, the
     * profile, the inventory, the assets they made, their objects in the regions.
     *
     * @return list<array{table:string,database:?\OpenSim\Config\ConnectionString,rows:int,condition:string}> database null when the
     *         grid does not say where the table is
     */
    public function leftovers(): array
    {
        $id = "'" . $this->id() . "'";
        $found = [];
        foreach (self::LEFTOVERS as $table => $condition) {
            $which = Database::forTable($table);
            $condition = strtr($condition, ['{id}' => $id, '{like}' => "'" . $this->id() . ";%'"]);
            $found[] = $this->leftover($table, $which, null, $condition);
        }
        $seen = [];
        foreach (array_keys($this->config->tree()['simulators'] ?? []) as $simulator) {
            $key = Connections::key($this->config, Database::Simulator, (string) $simulator);
            if ($key === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $found[] = $this->leftover('prims', Database::Simulator, (string) $simulator, "OwnerID = $id");
        }

        return $found;
    }

    /**
     * The statements that delete what this person left, for whoever reviews them: nothing is run. Only where there is something.
     *
     * @param ?list<array{table:string,database:?\OpenSim\Config\ConnectionString,rows:int,condition:string}> $leftovers what leftovers()
     *        said, when it was asked already
     * @return list<string> the comments and the statements
     */
    public function purgeSql(?array $leftovers = null): array
    {
        $lines = [];
        foreach ($leftovers ?? $this->leftovers() as $left) {
            if ($left['database'] === null || $left['rows'] === 0) {
                continue;
            }
            $lines[] = sprintf(
                '-- Delete %d row%s from %s.%s',
                $left['rows'],
                $left['rows'] > 1 ? 's' : '',
                $left['database']->database,
                $left['table'],
            );
            $lines[] = sprintf(
                'DELETE FROM %s.%s WHERE %s;',
                self::quoteName($left['database']->database),
                self::quoteName($left['table']),
                $left['condition'],
            );
        }

        return $lines;
    }

    /**
     * The hypergrid data of this person, for information: the rows of hg_traveling_data.
     *
     * @return list<array<string,mixed>>
     */
    public function travelingData(): array
    {
        $pdo = Connections::pdo($this->config, Database::forTable('hg_traveling_data') ?? 'UserAgentService');
        if ($pdo === null) {
            return [];
        }
        try {
            return $pdo->get_results(
                'SELECT * FROM hg_traveling_data WHERE UserID = :id',
                ['id' => $this->id()],
                \PDO::FETCH_ASSOC,
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param Database|string|null $which
     * @return array{table:string,database:?\OpenSim\Config\ConnectionString,rows:int,condition:string}
     */
    private function leftover(string $table, Database|string|null $which, ?string $simulator, string $condition): array
    {
        $database =
            $which === null
                ? null
                : ($which instanceof Database
                    ? $which->connection($this->config, $simulator)
                    : Database::service($this->config, $which));
        $pdo = $which === null ? null : Connections::pdo($this->config, $which, $simulator);
        $rows = 0;
        if ($pdo !== null) {
            try {
                $rows = (int) $pdo->get_var('SELECT COUNT(*) FROM ' . self::quoteName($table) . " WHERE $condition");
            } catch (\Throwable) {
                $rows = 0;
            }
        }

        return ['table' => $table, 'database' => $database, 'rows' => $rows, 'condition' => $condition];
    }

    /**
     * The ids of the visits that match a pattern of GridUser.
     *
     * @return list<string>
     */
    private static function visits(GridConfig $config, string $pattern): array
    {
        $pdo = Connections::pdo($config, 'GridUserService');
        if ($pdo === null) {
            return [];
        }
        try {
            return array_map(
                'strval',
                $pdo->get_col("SELECT UserID FROM GridUser WHERE UserID LIKE :p ESCAPE '!'", ['p' => $pattern]),
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /** A text for a LIKE written with ESCAPE '!': its wildcards are characters. */
    private static function like(string $text): string
    {
        return strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    private static function quoteName(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * The avatars of the grid.
     *
     * Options: `search` (words, each of them in the first name or the last name, and in the email with `searchEmail`: the emails are
     * not found by whoever asks, unless the caller says so), `sort` ("name", "lastSeen desc"...), `filters` (`active`, `status`, `online`, `god`,
     * `technical`: yes or no), `page` and `perPage`.
     *
     * @throws ChannelUnavailable when the database of the accounts cannot be reached
     */
    public static function all(GridConfig $config, array $options = []): Collection
    {
        $joined = self::joined($config);
        self::listing($joined)->check($options);
        $accounts = self::connect($config, 'UserAccountService', null, 'read the avatars');
        [$sortName, $direction] = self::sort((string) ($options['sort'] ?? 'name'), $joined);
        $model = AvatarModel::condition($config, 'u.');
        [$where, $params, $applied] = self::conditions($options, $joined, $model);

        $from = self::from($joined);
        $total = (int) $accounts->get_var("SELECT COUNT(*) FROM $from$where", $params);
        $order = self::order($sortName, $direction, $model);
        $sortParams = $sortName === 'type' ? $model[1] : [];
        $sql = 'SELECT ' . self::select($joined) . " FROM $from$where ORDER BY " . $order;
        $params += $sortParams;
        $perPage = max(0, (int) ($options['perPage'] ?? 0));
        $page = max(1, (int) ($options['page'] ?? 1));
        if ($perPage > 0) {
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . ($page - 1) * $perPage;
        }
        $rows = $accounts->get_results($sql, $params, \PDO::FETCH_ASSOC);
        $counts = self::counts($accounts, $from, $options, $joined, $model);
        if (!$joined) {
            $rows = self::withVisits($config, $rows);
        }

        // The ban lists of the estates, read once for the list
        $lists = $rows === [] ? [] : self::estateBanLists($config);
        $avatars = array_map(static function (array $row) use ($config, $lists): self {
            $avatar = new self($config, $row);
            $avatar->estateBanned = $lists;

            return $avatar;
        }, $rows);

        return new Collection($avatars, [
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'sort' => "$sortName $direction",
            'search' => trim((string) ($options['search'] ?? '')),
            'columns' => self::columns($joined),
            'filters' => self::filters($joined),
            'counts' => $counts,
            'applied' => $applied,
        ]);
    }

    /**
     * An avatar by its UUID or its name (First Last, First.Last).
     */
    public static function find(GridConfig $config, string $id): ?static
    {
        $accounts = Connections::pdo($config, 'UserAccountService');
        if ($accounts === null) {
            return null;
        }
        if (Uuid::isValid($id)) {
            $where = ' WHERE u.PrincipalID = :a';
            $params = ['a' => strtolower($id)];
        } elseif (preg_match('/^([^\s.@]+)[\s.]([^\s.@]+)$/', trim($id), $m)) {
            $where = ' WHERE u.FirstName = :a AND u.LastName = :b';
            $params = ['a' => $m[1], 'b' => $m[2]];
        } else {
            return null;
        }
        $joined = self::joined($config);
        $rows = $accounts->get_results(
            'SELECT ' . self::select($joined) . ' FROM ' . self::from($joined) . $where,
            $params,
            \PDO::FETCH_ASSOC,
        );
        $rows = $joined ? $rows : self::withVisits($config, $rows);

        return isset($rows[0]) ? new self($config, $rows[0]) : null;
    }

    /**
     * What the list of this grid can sort on and filter by: the visits (last seen, online) need the accounts and the visits in one
     * database. A consumer shows only these.
     *
     * @return array{sortable:list<string>,filters:list<string>}
     */
    public static function capabilities(GridConfig $config): array
    {
        $joined = self::joined($config);

        return [
            'sortable' => self::listing($joined)->sorts(),
            'filters' => array_keys(self::filters($joined)),
        ];
    }

    /**
     * What the list of the avatars shows (Listing): the visits (last seen, online) need the accounts and the visits in one database.
     */
    public static function listing(bool $joined = true): Listing
    {
        $columns = [
            Listing::column('name', 'avatar_name', sort: 'name', more: [
                'picture' => 'profileImage',
                'default' => 'person',
            ]),
            Listing::column('email', 'email', 'email', 'email'),
            Listing::column('role', 'role', 'role', 'level'),
            // A person's, a model of the grid, the account of its services
            Listing::column('type', 'column_type', 'list', 'type', ['values' => Listing::values(array_combine(self::TYPES, array_map(static fn(string $type): string => "avatar_type_$type", self::TYPES)))]),
            // Born, as the viewers say it, then last seen
            Listing::column('created', 'born', 'datetime', 'created'),
            Listing::column('lastSeen', 'last_seen', 'datetime', 'lastSeen'),
            // The account, the button that enables or disables it, and the presence in the world
            Listing::column('status', 'column_status', 'indicators', 'active', ['indicators' => [
                // Its account and its presence in one icon (accountState): a user with a check while it is in the world; a banned account
                // has an icon of its own, a difference that matters is never the color alone
                Listing::indicator('accountState', 'account', 'status', [
                    ['online', 'online', 'ok', 'account-online'],
                    ['active', 'status_active', 'ok'],
                    ['pending', 'status_pending', 'off'],
                    ['disabled', 'status_disabled', 'off'],
                    ['banned', 'status_banned', 'failed', 'account-banned'],
                ], ['enable', 'disable']),
                // The sign of a ban, for both: a hard one (red), else the ban lists of some estates (orange)
                Listing::indicator('ban', 'ban', 'status_banned', [['hard', 'state_grid_banned', 'failed'], ['estates', 'state_estate_banned', 'warning']]),
            ]]),
        ];
        $statuses = array_combine(self::STATUSES, array_map(Catalog::statusKey(...), self::STATUSES));
        $roles = array_combine(array_keys(self::ROLES), array_map(Catalog::roleKey(...), array_keys(self::ROLES)));
        $filters = [
            Listing::filter('status', 'status', $statuses),
            // In the world now or not: what the visits say, apart from the status of the account
            ...($joined ? [Listing::filter('online', 'presence', ['online' => 'online', 'offline' => 'offline'])] : []),
            Listing::filter('role', 'role', $roles),
            Listing::filter('type', 'column_type', array_combine(self::TYPES, array_map(static fn(string $type): string => "avatar_type_$type", self::TYPES))),

            Listing::filter('published', 'show_in_search'),
            Listing::filter('email', 'filter_email'),
            Listing::filter('model', 'filter_model'),
            Listing::filter('technical', 'filter_technical'),
            Listing::filter('god', 'god'),
            Listing::filter('active', 'filter_active'),
        ];
        [$method, $path] = ['PATCH', '/grids/{grid}/avatars/{id}'];
        $actions = [
            Listing::action('enable', 'action_enable', $method, $path, ['status' => 'active'], ['status' => ['disabled', 'pending']]),
            Listing::action(
                'disable',
                'action_disable',
                $method,
                $path,
                ['status' => 'disabled'],
                ['status' => ['active']],
                more: ['notSelf' => true],
            ),
            Listing::action(
                'reset',
                'action_reset',
                'POST',
                '/grids/{grid}/password-resets',
                ['name' => '{name}'],
                ['status' => ['active']],
                ['email'],
                ['link' => 'reset'],
            ),
            // The bans, a god's: hard (the account), or in the lists of every estate; asked first
            Listing::action('ban', 'action_ban', $method, $path, ['banned' => true], ['status' => ['active', 'disabled', 'pending']], more: ['notSelf' => true, 'level' => self::GOD_FULL, 'ask' => 'ask_ban']),
            Listing::action('unban', 'action_unban', $method, $path, ['banned' => false], ['status' => ['banned']], more: ['level' => self::GOD_FULL]),
            Listing::action('estateBan', 'action_estate_ban', $method, $path, ['estateBanned' => true], ['estateBanned' => [false]], more: ['notSelf' => true, 'level' => self::GOD_FULL, 'ask' => 'ask_estate_ban']),
            Listing::action('estateUnban', 'action_estate_unban', $method, $path, ['estateBanned' => false], ['estateBanned' => [true]], more: ['level' => self::GOD_FULL]),
        ];
        if (!$joined) {
            $columns = array_map(
                static fn(array $column): array => in_array($column['sort'] ?? '', self::NEEDS_VISITS, true)
                    ? array_diff_key($column, ['sort' => true])
                    : $column,
                $columns,
            );
        }

        return new Listing($columns, $filters, ['name', 'email'], $actions, '-created', $joined ? ['online'] : []);
    }

    /**
     * The settings of the grid its form needs (the fields that say `setting`), for a site: `allowUserProfileWebUrls`.
     *
     * @return array{allowUserProfileWebUrls:bool}
     */
    public function formSettings(): array
    {
        return ['allowUserProfileWebUrls' => self::profileWebUrls($this->config)];
    }

    /**
     * Whether the grid lets its avatars give a web address in their profile (the Web tab of the viewers, `profileUrl`):
     * AllowUserProfileWebURLs of `[UserProfiles]`, read as each of its simulators reads it (its OpenSim.ini, then the file of the
     * simulator, each with the files it includes and its constants), true when it says nothing or nothing it knows; false when one
     * of them says false, so that a site never shows what a viewer hides.
     */
    public static function profileWebUrls(GridConfig $config): bool
    {
        $said = static fn(?array $ini): ?string => isset($ini['UserProfiles']['AllowUserProfileWebURLs']) && trim((string) $ini['UserProfiles']['AllowUserProfileWebURLs']) !== ''
            ? strtolower(trim((string) $ini['UserProfiles']['AllowUserProfileWebURLs']))
            : null;
        $grid = $said($config->tree()['OpenSim'] ?? null);
        $simulators = $config->tree()['simulators'] ?? [];
        $values = $simulators === [] ? [$grid] : array_map(static fn(array $ini): ?string => $said($ini) ?? $grid, $simulators);

        return array_intersect($values, ['false', 'no', 'off']) === [];
    }

    /**
     * The web page of the profile of an avatar of the grid, by its name: `web_profile_url` of `[GridInfoService]` with First.Last for
     * `[AGENT_NAME]`, as the viewers open it. Null when the grid has none, or one that is not for an avatar.
     */
    public static function profilePage(GridConfig $config, string $name): ?string
    {
        return self::profileAt((string) $config->find('GridInfoService', 'web_profile_url'), $name);
    }

    /**
     * The web page of the profile of an avatar from the address a grid gives them (`web_profile_url`, live or configured): First.Last
     * for `[AGENT_NAME]`. Null for an address that is not one for an avatar, or a name that is not First Last (or First.Last).
     */
    public static function profileAt(string $page, string $name): ?string
    {
        $parts = preg_split('/[\s.]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!str_contains($page, '[AGENT_NAME]') || count($parts) !== 2) {
            return null;
        }

        return str_replace('[AGENT_NAME]', rawurlencode($parts[0]) . '.' . rawurlencode($parts[1]), $page);
    }

    /**
     * What the page of an avatar shows and lets change (ItemForm), in the tabs of its profile and the one of its account: the avatar
     * changes its profile, its email (confirmed, see confirmEmail) and its password; the managers change everything, its role and
     * its status too. The banner of its profile, over every tab: its picture, its name, its birth, its role (`banner`).
     */
    public static function itemForm(): ItemForm
    {
        $both = ['access' => ['edit' => ['self', 'manager']]];
        $flags = static fn(array $list): array => array_column($list, 'key', 'name');
        $roles = array_combine(array_keys(self::ROLES), array_map(Catalog::roleKey(...), array_keys(self::ROLES)));
        $statuses = array_combine(self::STATUSES, array_map(Catalog::statusKey(...), self::STATUSES));
        $profile = self::profileFlags();

        return new ItemForm(
            [
                ItemForm::shown('profileImage', 'profile_picture', 'picture', 'inworld', ['default' => 'person', 'banner' => true]),
                ItemForm::shown('name', 'avatar_name', 'text', 'inworld', ['banner' => true]),
                ItemForm::shown('uuid', 'profile_key', 'text', 'inworld'),
                ItemForm::shown('created', 'born', 'datetime', 'inworld', ['banner' => true]),
                ItemForm::shown('partner', 'partner', 'avatar', 'inworld'),
                ItemForm::field('about', 'about', 'text', 'inworld', more: $both),
                ItemForm::field('published', 'show_in_search', 'boolean', 'inworld', more: $both),
                ItemForm::field('profileUrl', 'profile_url', 'url', 'web', more: $both + ['setting' => 'allowUserProfileWebUrls']),
                ItemForm::field('wantsList', 'wants', 'checkboxes', 'interests', $flags($profile['wants']), $both),
                ItemForm::field('wants', 'profile_other', 'string', 'interests', more: $both),
                ItemForm::field('skillsList', 'skills', 'checkboxes', 'interests', $flags($profile['skills']), $both),
                ItemForm::field('skills', 'profile_other', 'string', 'interests', more: $both),
                ItemForm::field('languages', 'languages', 'string', 'interests', more: $both),
                ItemForm::shown('firstImage', 'profile_picture', 'picture', 'real_life', ['default' => 'person']),
                ItemForm::field('firstLife', 'real_life', 'text', 'real_life', more: $both),
                ItemForm::field('email', 'email', 'email', 'account', more: $both),
                ItemForm::field('role', 'role', 'select', 'account', $roles, ['banner' => true, 'bare' => true]),
                ItemForm::field('status', 'status', 'select', 'account', $statuses),
                ItemForm::field(
                    'password',
                    'password',
                    'secret',
                    'account',
                    more: $both + ['description' => 'password_help', 'writeOnly' => true],
                ),
                ItemForm::shown('lastSeen', 'last_seen', 'datetime', 'account'),
            ],
            [
                ItemForm::group('inworld', 'profile_tab_inworld'),
                ItemForm::group('web', 'profile_tab_web'),
                ItemForm::group('interests', 'profile_tab_interests'),
                ItemForm::group('real_life', 'profile_tab_real_life'),
                ItemForm::group('account', 'profile_tab_account'),
            ],
            self::listing()->actions,
            // The sections of a profile, in the order of the tabs of the viewers, its stream first; the account is not one of them
            [
                ItemForm::section('feed', 'profile_tab_feed', items: 'posts'),
                ItemForm::section('about', 'profile_tab_about', 'inworld'),
                ItemForm::section('web', 'profile_tab_web', 'web'),
                ItemForm::section('interests', 'profile_tab_interests', 'interests'),
                ItemForm::section('picks', 'profile_tab_picks', items: 'picks'),
                ItemForm::section('classifieds', 'profile_tab_classifieds', items: 'classifieds'),
                ItemForm::section('real-life', 'profile_tab_real_life', 'real_life'),
            ],
        );
    }

    /** What its page shows and lets change as its grid has it (itemForm() without what the grid does not allow, formSettings()). */
    public function form(): ItemForm
    {
        return self::itemForm()->for($this->formSettings());
    }

    /**
     * The columns of a list.
     *
     * @return list<array{name:string,label:string,sortable:bool}>
     */
    public static function columns(bool $joined = true): array
    {
        return self::listing($joined)->columns();
    }

    /**
     * The filters of a list.
     *
     * @return array<string,list<string>>
     */
    private static function filters(bool $joined): array
    {
        return self::listing($joined)->filterValues();
    }

    /** Whether the accounts, the visits and the profiles are in one database. */
    private static function joined(GridConfig $config): bool
    {
        $keys = [
            Connections::key($config, 'UserAccountService'),
            Connections::key($config, 'GridUserService'),
            Connections::key($config, Database::Profiles),
        ];

        return $keys[0] !== null && count(array_unique($keys)) === 1;
    }

    private static function from(bool $joined): string
    {
        return 'UserAccounts u' .
            ($joined
                ? ' LEFT JOIN GridUser g ON g.UserID = u.PrincipalID LEFT JOIN userprofile p ON p.useruuid = u.PrincipalID'
                : '');
    }

    private static function select(bool $joined): string
    {
        return self::ACCOUNT_COLUMNS . ($joined ? ', ' . self::VISIT_COLUMNS . ', ' . self::PROFILE_COLUMNS : '');
    }

    /** @return array{0:string,1:string} the sort and its direction; by name, ascending, when the sort asked is not available */
    private static function sort(string $sort, bool $joined): array
    {
        [$name, $direction] = array_pad(preg_split('/\s+/', trim($sort)) ?: [], 2, 'asc');
        if (!isset(self::SORTS[$name]) || (!$joined && in_array($name, self::NEEDS_VISITS, true))) {
            return ['name', 'asc'];
        }

        return [$name, strtolower((string) $direction) === 'desc' ? 'desc' : 'asc'];
    }

    private static function order(string $name, string $direction, ?array $model = null): string
    {
        // By what it is, the names of each kind in their order
        if ($name === 'type') {
            $type = self::filterConditions(true, $model)['type'];

            return "CASE WHEN {$type['service']} THEN 2 WHEN {$type['model']} THEN 1 ELSE 0 END " . strtoupper($direction) . ', u.FirstName ASC, u.LastName ASC, u.PrincipalID';
        }
        $parts = array_map(
            static fn(string $expression): string => $expression . ' ' . strtoupper($direction),
            self::SORTS[$name],
        );

        return implode(', ', $parts) . ', u.PrincipalID';
    }

    /**
     * @param array<string,mixed> $options
     * @return array{0:string,1:array<string,string>,2:array<string,string>} the WHERE, its parameters, the filters applied
     */
    private static function conditions(array $options, bool $joined, ?array $model = null): array
    {
        $where = [];
        $params = [];
        $applied = [];
        $words = preg_split('/\s+/', trim((string) ($options['search'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $i => $word) {
            $where[] =
                "(u.FirstName LIKE :w$i ESCAPE '!' OR u.LastName LIKE :w$i ESCAPE '!'" .
                ($options['searchEmail'] ?? false ? " OR u.Email LIKE :w$i ESCAPE '!'" : '') .
                ')';
            $params["w$i"] = self::contains($word);
        }
        $filters = self::filterConditions($joined, $model);
        foreach ((array) ($options['filters'] ?? []) as $name => $value) {
            if (is_string($value) && isset($filters[$name][$value])) {
                $where[] = $filters[$name][$value];
                $applied[$name] = $value;
                in_array($name, ['model', 'type'], true) && ($params += $model[1] ?? []);
            }
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params, $applied];
    }

    /**
     * The condition of each value of each filter of the list on the query of the avatars (the states as statusOf() tells them).
     *
     * @param ?array{0:string,1:array<string,string>} $model the condition of the models of the grid (AvatarModel::condition())
     * @return array<string,array<string,string>>
     */
    private static function filterConditions(bool $joined, ?array $model): array
    {
        // The states as statusOf() tells them
        $banned = '(u.UserFlags & ' . self::BANNED_FLAG . ') <> 0';
        $pending = "(u.UserFlags & " . self::PENDING_FLAG . ") <> 0 AND NOT ($banned)";
        $active = '(u.UserFlags & ' . self::PENDING_FLAG . ') = 0 AND u.active = 1 AND u.UserLevel >= 0';
        $technical = "(u.FirstName = '" . self::TECHNICAL[0] . "' AND u.LastName = '" . self::TECHNICAL[1] . "')";
        $isModel = $model[0] ?? '1 = 0';

        return
            [
                'active' => ['yes' => "($active)", 'no' => "NOT ($active)"],
                'status' => [
                    'active' => "($active)",
                    'disabled' => "(NOT ($pending) AND NOT ($active) AND NOT ($banned))",
                    'pending' => "($pending)",
                    'banned' => "($banned)",
                ],
                'role' => self::roleConditions(),
                'god' => [
                    'yes' => '(u.UserLevel >= ' . self::GOD_FULL . ' OR u.UserLevel <= ' . (-1 - self::GOD_FULL) . ')',
                    'no' => '(u.UserLevel < ' . self::GOD_FULL . ' AND u.UserLevel > ' . (-1 - self::GOD_FULL) . ')',
                ],
                'technical' => [
                    'yes' => "(u.FirstName = '" . self::TECHNICAL[0] . "' AND u.LastName = '" . self::TECHNICAL[1] . "')",
                    'no' => "NOT (u.FirstName = '" . self::TECHNICAL[0] . "' AND u.LastName = '" . self::TECHNICAL[1] . "')",
                ],
                // The avatar models of the grid, as AvatarModel picks them
                'model' => $model === null ? [] : ['yes' => "($model[0])", 'no' => "NOT ($model[0])"],
                // What it is (type()): the account of the services first, then a model
                'type' => [
                    'user' => "(NOT $technical AND NOT ($isModel))",
                    'model' => "(NOT $technical AND ($isModel))",
                    'service' => $technical,
                ],
                'email' => [
                    'yes' => "(u.Email IS NOT NULL AND u.Email <> '')",
                    'no' => "(u.Email IS NULL OR u.Email = '')",
                ],
                'published' => [
                    'yes' => '(u.UserFlags & ' . self::PUBLISHED_FLAG . ') <> 0',
                    'no' => '(u.UserFlags & ' . self::PUBLISHED_FLAG . ') = 0',
                ],
            ] +
                ($joined
                    ? ['online' => ['online' => "g.Online = 'true'", 'offline' => "(g.Online IS NULL OR g.Online <> 'true')"]]
                    : []);
    }

    /**
     * How many avatars each value of each filter of the list gives, in one query: each counted with the search and the other
     * filters asked, not its own (as the views of a list of WordPress: All (n), Active (n)).
     *
     * @param array<string,mixed> $options
     * @param array{0:string,1:array<string,string>} $model
     * @return array<string,array<string,int>>
     */
    private static function counts(OSPDO $accounts, string $from, array $options, bool $joined, array $model): array
    {
        $filters = self::filterConditions($joined, $model);
        $asked = array_filter((array) ($options['filters'] ?? []), static fn($value, $name): bool => is_string($value) && isset($filters[$name][$value]), ARRAY_FILTER_USE_BOTH);
        [$search, $params] = self::conditions(['search' => $options['search'] ?? '', 'searchEmail' => $options['searchEmail'] ?? false], $joined, $model);
        $sums = [];
        $keys = [];
        foreach (self::listing($joined)->filterValues() as $name => $values) {
            foreach ($values as $value) {
                if (!isset($filters[$name][$value])) {
                    continue;
                }
                $others = array_map(static fn(string $other, string $was): string => $filters[$other][$was], array_keys(array_diff_key($asked, [$name => 1])), array_diff_key($asked, [$name => 1]));
                $sums[] = 'SUM(CASE WHEN ' . implode(' AND ', [$filters[$name][$value], ...$others]) . ' THEN 1 ELSE 0 END) AS c' . count($keys);
                $keys[] = [$name, $value];
            }
        }
        if ($sums === []) {
            return [];
        }
        $sql = 'SELECT ' . implode(', ', $sums) . " FROM $from$search";
        // The condition of the models has its parameters, wherever it is
        str_contains($sql, ':model') && ($params += $model[1]);
        $row = $accounts->get_results($sql, $params, \PDO::FETCH_NUM)[0] ?? [];
        $counts = [];
        foreach ($keys as $i => [$name, $value]) {
            $counts[$name][$value] = (int) ($row[$i] ?? 0);
        }

        return $counts;
    }

    /** @return array<string,string> the condition of each role on the level of an account (ROLES) */
    private static function roleConditions(): array
    {
        $conditions = [];
        $bounds = array_values(self::ROLES);
        foreach (array_keys(self::ROLES) as $i => $role) {
            $above = $i === 0 ? '' : self::LEVEL . ' >= ' . $bounds[$i];
            $below = isset($bounds[$i + 1]) ? self::LEVEL . ' < ' . $bounds[$i + 1] : '';
            $conditions[$role] = '(' . implode(' AND ', array_filter([$above, $below])) . ')';
        }

        return $conditions;
    }

    /**
     * The visits and the profiles of the accounts of a page, when they are in other databases.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function withVisits(GridConfig $config, array $rows): array
    {
        $ids = array_column($rows, 'PrincipalID');
        if ($ids === []) {
            return $rows;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $visits = [];
        $profiles = [];
        if ($pdo = Connections::pdo($config, 'GridUserService')) {
            foreach (
                $pdo->get_results(
                    "SELECT UserID, HomeRegionID, LastRegionID, Online, Login, Logout FROM GridUser WHERE UserID IN ($marks)",
                    $ids,
                    \PDO::FETCH_ASSOC,
                )
                as $row
            ) {
                $visits[$row['UserID']] = $row;
            }
        }
        if ($pdo = Connections::pdo($config, Database::Profiles)) {
            foreach (
                $pdo->get_results(
                    "SELECT useruuid, profileImage, profileAboutText, profileURL, profilePartner, profileWantToMask, profileWantToText, profileSkillsMask, profileSkillsText, profileLanguages, profileFirstImage, profileFirstText FROM userprofile WHERE useruuid IN ($marks)",
                    $ids,
                    \PDO::FETCH_ASSOC,
                )
                as $row
            ) {
                $profiles[$row['useruuid']] = $row;
            }
        }
        foreach ($rows as &$row) {
            $row +=
                array_diff_key($visits[$row['PrincipalID']] ?? [], ['UserID' => 1]) +
                array_diff_key($profiles[$row['PrincipalID']] ?? [], ['useruuid' => 1]);
        }

        return $rows;
    }

    /** A UUID, null for none or the null key. */
    private static function uuidOrNull(mixed $uuid): ?string
    {
        $uuid = (string) $uuid;

        return $uuid !== '' && $uuid !== '00000000-0000-0000-0000-000000000000' ? $uuid : null;
    }

    /** The time of the last visit: the later of the login and the logout, 0 when there is none. */
    private function lastSeenTime(): int
    {
        return max((int) ($this->row['Login'] ?? 0), (int) ($this->row['Logout'] ?? 0));
    }
}
