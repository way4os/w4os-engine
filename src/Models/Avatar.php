<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Result;
use OpenSim\Text\Uuid;
use OpenSim\Config\GridConfig;
use OpenSim\Data\Collection;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
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
    /** The level of the accounts that are gods (administrators). */
    public const GOD_LEVEL = 200;

    /**
     * The states of an account: it can log in (active), a manager disabled it (disabled), it waits for the confirmation of its
     * email (pending). A state other than active keeps the account out of the grid as Robust reads it: its UserLevel is below 0,
     * which the login of Robust refuses (MinLoginLevel, 0 by default), the level it had being kept as -1 - level; a pending one
     * has PENDING_FLAG in its UserFlags. `active` is 0 for both, as the search of Robust and w4os read it.
     */
    public const STATUSES = ['active', 'disabled', 'pending'];

    /**
     * The roles of the avatars, as w4os names them, by the lowest level of each: a level of 200 and over is a god, 150 a liaison, 100
     * the customer service, 1 god-like, 0 and less a resident.
     */
    public const ROLES = ['resident' => 0, 'god-like' => 1, 'customer-service' => 100, 'liaison' => 150, 'god' => 200];

    /** The level of an account, the one it has back when it is active again (storedLevel()), as the database computes it. */
    private const LEVEL = '(CASE WHEN u.UserLevel < 0 THEN -1 - u.UserLevel ELSE u.UserLevel END)';

    /** The bit of UserFlags of an account that waits for the confirmation of its email (bit 12: OpenSimulator uses 0 to 11). */
    public const PENDING_FLAG = 1 << 12;

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
            ($flags & self::PENDING_FLAG) !== 0 => 'pending',
            $level < 0 || $active === 0 => 'disabled',
            default => 'active',
        };
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

    /** Whether the level of the avatar is the one of its role (0, 1, 100, 150, 200), not one in between. */
    public function hasStandardLevel(): bool
    {
        return $this->level() === self::ROLES[$this->role()];
    }

    public function isGod(): bool
    {
        return $this->level() >= self::GOD_LEVEL;
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

    /** Whether it is an account of the services of the grid, not a person. */
    public function isTechnical(): bool
    {
        return [$this->row['FirstName'], $this->row['LastName']] === self::TECHNICAL;
    }

    /**
     * What it published in its profile as picks (the places it likes).
     *
     * @return list<array<string,mixed>> name, description, simname, posglobal, gatekeeper
     */
    public function picks(): array
    {
        $pdo = Connections::pdo($this->config, Database::Profiles);

        return $pdo === null
            ? []
            : $pdo->get_results(
                "SELECT name, description, simname, posglobal, gatekeeper FROM userpicks WHERE creatoruuid = :id AND enabled = 'true' ORDER BY sortorder, name",
                ['id' => $this->id()],
                \PDO::FETCH_ASSOC,
            );
    }

    /**
     * Its classifieds that have not expired.
     *
     * @return list<array<string,mixed>> name, description, simname, parcelname, creationdate, expirationdate
     */
    public function classifieds(?int $now = null): array
    {
        $pdo = Connections::pdo($this->config, Database::Profiles);
        // An integer in the statement: a bound value would be text, which a number is not compared with in every database
        $now = (int) ($now ?? time());

        return $pdo === null
            ? []
            : $pdo->get_results(
                "SELECT name, description, simname, parcelname, creationdate, expirationdate FROM classifieds WHERE creatoruuid = :id AND expirationdate + 0 > $now ORDER BY creationdate DESC",
                ['id' => $this->id()],
                \PDO::FETCH_ASSOC,
            );
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
            'active' => $this->isEnabled(),
            'status' => $this->status(),
            'online' => $this->isOnline(),
            'lastSeen' => $this->lastSeenTime() ?: null,
            'homeRegion' => $row['HomeRegionID'] ?? null,
            'lastRegion' => $row['LastRegionID'] ?? null,
            'technical' => $this->isTechnical(),
            'profileImage' => $row['profileImage'] ?? null,
            'about' => $row['profileAboutText'] ?? null,
            'profileUrl' => $row['profileURL'] ?? null,
            'partner' => self::uuidOrNull($row['profilePartner'] ?? null),
            'wants' => $row['profileWantToText'] ?? null,
            'wantsList' => Bitmask::labels((int) ($row['profileWantToMask'] ?? 0), self::wantLabels()),
            'skills' => $row['profileSkillsText'] ?? null,
            'skillsList' => Bitmask::labels((int) ($row['profileSkillsMask'] ?? 0), self::skillLabels()),
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
     * Each part of a new name with a capital and the rest in small letters, as w4os makes it: "bart SIMPSON" is "Bart Simpson"; each
     * piece of a hyphenated part too ("jean-PIERRE" is "Jean-Pierre", w4os has no hyphen).
     */
    public static function capitalizeName(string $name): string
    {
        $capital = static fn(string $piece): string => ucfirst(strtolower($piece));

        return implode(
            ' ',
            array_map(
                static fn(string $part): string => implode(self::NAME_PUNCTUATION, array_map($capital, explode(self::NAME_PUNCTUATION, $part))),
                explode(' ', trim($name)),
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
            [$first, $last] = [self::capitalizeName($first), self::capitalizeName($last)];
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
     *        or an account that is moved: its name is kept as it is given, any other gets its capitals (capitalizeName())
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
            $status === 'pending' ? self::PENDING_FLAG : 0,
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
     * Change an avatar. The account: `email`, `title`, `level` or `role` (the level of the role, or the one given with it when it is of
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
        foreach ($values as $name => $value) {
            if (
                !isset(self::ACCOUNT_SETTINGS[$name]) &&
                !isset(self::PROFILE_SETTINGS[$name]) &&
                !in_array($name, ['password', 'home'], true)
            ) {
                throw InvalidValue::because('field-unknown', "an avatar has no changeable '$name'");
            }
        }
        if (isset($values['password']) && (string) $values['password'] === '') {
            throw InvalidValue::because('password-required', 'a password cannot be empty');
        }
        if (isset($values['email']) && self::validateEmail($values['email']) !== null) {
            throw InvalidValue::because('email-invalid', "'{$values['email']}' is not an email");
        }
        // The state is read again: this avatar may have been changed since it was read (enabled, then its level changed)
        $state = ['active' => 1, 'status' => 1, 'level' => 1];
        $current = array_intersect_key($values, $state) !== [] ? static::find($this->config, $this->id()) ?? $this : $this;
        $was = $current->status();
        $status = self::requestedStatus($values, $was);
        $id = $this->id();
        $writes = [];

        $account = array_diff_key(array_intersect_key($values, self::ACCOUNT_SETTINGS), $state);
        if ($account !== [] || array_intersect_key($values, $state) !== []) {
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
            $params['flags'] = $status === 'pending'
                ? $current->flags() | self::PENDING_FLAG
                : $current->flags() & ~self::PENDING_FLAG;
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
        $accounts = self::connect($config, 'UserAccountService', null, 'read the avatars');
        $joined = self::joined($config);
        [$sortName, $direction] = self::sort((string) ($options['sort'] ?? 'name'), $joined);
        [$where, $params, $applied] = self::conditions($options, $joined, AvatarModel::condition($config, 'u.'));

        $from = self::from($joined);
        $total = (int) $accounts->get_var("SELECT COUNT(*) FROM $from$where", $params);
        $sql = 'SELECT ' . self::select($joined) . " FROM $from$where ORDER BY " . self::order($sortName, $direction);
        $perPage = max(0, (int) ($options['perPage'] ?? 0));
        $page = max(1, (int) ($options['page'] ?? 1));
        if ($perPage > 0) {
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . ($page - 1) * $perPage;
        }
        $rows = $accounts->get_results($sql, $params, \PDO::FETCH_ASSOC);
        if (!$joined) {
            $rows = self::withVisits($config, $rows);
        }

        return new Collection(array_map(static fn(array $row): self => new self($config, $row), $rows), [
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'sort' => "$sortName $direction",
            'search' => trim((string) ($options['search'] ?? '')),
            'columns' => self::columns($joined),
            'filters' => self::filters($joined),
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
            'sortable' => array_column(
                array_filter(self::columns($joined), static fn(array $column): bool => $column['sortable']),
                'name',
            ),
            'filters' => array_keys(self::filters($joined)),
        ];
    }

    /**
     * The columns of a list.
     *
     * @return list<array{name:string,label:string,sortable?:bool}>
     */
    public static function columns(bool $joined = true): array
    {
        $columns = [
            ['name' => 'name', 'label' => _('Name')],
            ['name' => 'email', 'label' => _('Email')],
            ['name' => 'created', 'label' => _('Created')],
            ['name' => 'level', 'label' => _('Level')],
            ['name' => 'active', 'label' => _('Active')],
            ['name' => 'online', 'label' => _('Online')],
            ['name' => 'lastSeen', 'label' => _('Last seen')],
        ];

        return array_map(
            static fn(array $column): array => $column + [
                'sortable' =>
                    isset(self::SORTS[$column['name']]) &&
                    ($joined || !in_array($column['name'], self::NEEDS_VISITS, true)),
            ],
            $columns,
        );
    }

    /**
     * The filters of a list.
     *
     * @return array<string,list<string>>
     */
    private static function filters(bool $joined): array
    {
        $yesNo = ['yes', 'no'];

        return [
            'active' => $yesNo,
            'status' => self::STATUSES,
            'role' => array_keys(self::ROLES),
            'god' => $yesNo,
            'technical' => $yesNo,
            'model' => $yesNo,
            'email' => $yesNo,
        ] +
            ($joined ? ['online' => $yesNo] : []);
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

    private static function order(string $name, string $direction): string
    {
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
        // The states as statusOf() tells them
        $pending = '(u.UserFlags & ' . self::PENDING_FLAG . ') <> 0';
        $active = '(u.UserFlags & ' . self::PENDING_FLAG . ') = 0 AND u.active = 1 AND u.UserLevel >= 0';
        $filters =
            [
                'active' => ['yes' => "($active)", 'no' => "NOT ($active)"],
                'status' => [
                    'active' => "($active)",
                    'disabled' => "(NOT ($pending) AND NOT ($active))",
                    'pending' => "($pending)",
                ],
                'role' => self::roleConditions(),
                'god' => [
                    'yes' => '(u.UserLevel >= ' . self::GOD_LEVEL . ' OR u.UserLevel <= ' . (-1 - self::GOD_LEVEL) . ')',
                    'no' => '(u.UserLevel < ' . self::GOD_LEVEL . ' AND u.UserLevel > ' . (-1 - self::GOD_LEVEL) . ')',
                ],
                'technical' => [
                    'yes' => "(u.FirstName = '" . self::TECHNICAL[0] . "' AND u.LastName = '" . self::TECHNICAL[1] . "')",
                    'no' => "NOT (u.FirstName = '" . self::TECHNICAL[0] . "' AND u.LastName = '" . self::TECHNICAL[1] . "')",
                ],
                // The avatar models of the grid, as AvatarModel picks them
                'model' => $model === null ? [] : ['yes' => "($model[0])", 'no' => "NOT ($model[0])"],
                'email' => [
                    'yes' => "(u.Email IS NOT NULL AND u.Email <> '')",
                    'no' => "(u.Email IS NULL OR u.Email = '')",
                ],
            ] + ($joined ? ['online' => ['yes' => "g.Online = 'true'", 'no' => "(g.Online IS NULL OR g.Online <> 'true')"]] : []);
        foreach ((array) ($options['filters'] ?? []) as $name => $value) {
            if (is_string($value) && isset($filters[$name][$value])) {
                $where[] = $filters[$name][$value];
                $applied[$name] = $value;
                $name === 'model' && ($params += $model[1] ?? []);
            }
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params, $applied];
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
