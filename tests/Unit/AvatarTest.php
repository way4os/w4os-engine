<?php

declare(strict_types=1);

/**
 * The avatars of a grid through the model: the list (search, sort, filters, pages), one avatar, what is changed, the same when the
 * accounts, the visits and the profiles are in other databases. The databases are in memory (SQLite) with the tables of OpenSimulator.
 */

use OpenSim\Data\OSPDO;
use OpenSim\Config\GridConfig;
use OpenSim\Data\Collection;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Models\Avatar;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Exceptions\InvalidValue;

/** A grid folder whose Robust says its web address and its databases. */
function avatar_grid(string $extra = ''): GridConfig
{
    $dir = test_tmp() . '/avatars-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o755, true);
    file_put_contents(
        "$dir/Robust.HG.ini",
        "[Const]\n BaseURL = \"http://play.example.org\"\n PublicPort = \"8002\"\n[Hypergrid]\n GatekeeperURI = \"\${Const|BaseURL}:\${Const|PublicPort}\"\n" .
            "[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=robust;User ID=os;Password=pw;\"\n$extra",
    );

    return GridConfig::fromFolder($dir);
}

/** A database with the tables of the accounts, the visits and the profiles, and three avatars. */
function avatar_database(): OSPDO
{
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec(
        'CREATE TABLE UserAccounts (PrincipalID CHAR(36) PRIMARY KEY, ScopeID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64), Email VARCHAR(64), Created INT, UserLevel INT DEFAULT 0, UserFlags INT DEFAULT 0, UserTitle VARCHAR(64) DEFAULT \'\', active INT DEFAULT 1)',
    );
    $pdo->exec(
        'CREATE TABLE GridUser (UserID VARCHAR(255) PRIMARY KEY, HomeRegionID CHAR(36), LastRegionID CHAR(36), Online CHAR(5) DEFAULT \'false\', Login CHAR(16) DEFAULT \'0\', Logout CHAR(16) DEFAULT \'0\')',
    );
    $pdo->exec(
        'CREATE TABLE userprofile (useruuid CHAR(36) PRIMARY KEY, profileImage CHAR(36), profileAboutText TEXT, profileURL VARCHAR(255), profilePartner CHAR(36), profileWantToText TEXT, profileSkillsText TEXT, profileLanguages TEXT, profileFirstImage CHAR(36), profileFirstText TEXT, profileWantToMask INT DEFAULT 0, profileSkillsMask INT DEFAULT 0)',
    );
    $pdo->exec(
        'CREATE TABLE userpicks (pickuuid CHAR(36), creatoruuid CHAR(36), name VARCHAR(255), description TEXT, simname VARCHAR(255), posglobal VARCHAR(255), sortorder INT, enabled VARCHAR(5), gatekeeper VARCHAR(255))',
    );
    $pdo->exec(
        'CREATE TABLE classifieds (classifieduuid CHAR(36), creatoruuid CHAR(36), creationdate INT, expirationdate INT, name VARCHAR(255), description TEXT, simname VARCHAR(255), parcelname VARCHAR(255))',
    );
    $pdo->exec('CREATE TABLE regions (uuid CHAR(36), regionName VARCHAR(128), locX INT, locY INT, sizeX INT, sizeY INT)');
    $pdo->exec("INSERT INTO UserAccounts (PrincipalID, FirstName, LastName, Email, Created, UserLevel, UserFlags, active) VALUES
        ('11111111-1111-1111-1111-111111111111', 'Ada', 'Love', 'ada@example.org', 1700000000, 0, 0, 1),
        ('22222222-2222-2222-2222-222222222222', 'Bob', 'Root', 'bob@example.org', 1600000000, 250, 3, 1),
        ('33333333-3333-3333-3333-333333333333', 'Cy', 'Gone', 'cy@example.org', 1650000000, 0, 0, 0)");
    $pdo->exec("INSERT INTO GridUser (UserID, HomeRegionID, LastRegionID, Online, Login, Logout) VALUES
        ('11111111-1111-1111-1111-111111111111', 'aaaa', 'bbbb', 'true', '1700005000', '1700001000'),
        ('22222222-2222-2222-2222-222222222222', 'aaaa', 'aaaa', 'false', '1690000000', '1695000000')");
    $pdo->exec(
        "INSERT INTO userprofile (useruuid, profileImage, profileAboutText, profileURL, profilePartner, profileWantToText, profileSkillsText, profileLanguages, profileFirstImage, profileFirstText, profileWantToMask, profileSkillsMask) VALUES ('11111111-1111-1111-1111-111111111111', 'img-ada', 'Hello', 'https://ada.example.org', '22222222-2222-2222-2222-222222222222', 'to build', 'scripting', 'en, fr', '00000000-0000-0000-0000-000000000000', 'a mathematician', 5, 18)",
    );
    $pdo->exec(
        "INSERT INTO userpicks VALUES ('k1', '11111111-1111-1111-1111-111111111111', 'The Garden', 'roses', 'Welcome', '<256010,256020,30>', 2, 'true', ''), ('k2', '11111111-1111-1111-1111-111111111111', 'Hidden', 'x', 'Welcome', '<1,1,1>', 1, 'false', ''), ('k3', '11111111-1111-1111-1111-111111111111', 'Alpha pick', 'y', 'Harbour', '<256500,256020,30>', 1, 'true', 'other.example.org:8002')",
    );
    $pdo->exec(
        "INSERT INTO classifieds VALUES ('c1', '11111111-1111-1111-1111-111111111111', 1700000000, 1900000000, 'Roses for sale', 'fresh', 'Welcome', 'The Garden'), ('c2', '11111111-1111-1111-1111-111111111111', 1600000000, 1600000100, 'Old', 'gone', 'Welcome', 'The Garden')",
    );

    return $pdo;
}

/** The same, with what an avatar is made of besides: the passwords, the homes, the inventories. */
function avatar_creation_database(): OSPDO
{
    $pdo = avatar_database();
    $pdo->exec('ALTER TABLE UserAccounts ADD COLUMN ServiceURLs TEXT');
    $pdo->exec('ALTER TABLE GridUser ADD COLUMN HomePosition VARCHAR(64)');
    $pdo->exec('ALTER TABLE GridUser ADD COLUMN HomeLookAt VARCHAR(64)');
    $pdo->exec(
        'CREATE TABLE auth (UUID CHAR(36) PRIMARY KEY, passwordHash CHAR(32), passwordSalt CHAR(32), webLoginKey VARCHAR(255), accountType VARCHAR(32))',
    );
    $pdo->exec(
        'CREATE TABLE inventoryfolders (folderName VARCHAR(64), type INT, version INT, folderID CHAR(36), agentID CHAR(36), parentFolderID CHAR(36))',
    );
    $pdo->exec(
        'CREATE TABLE inventoryitems (assetID CHAR(36), assetType INT, inventoryName VARCHAR(64), inventoryDescription VARCHAR(128), inventoryNextPermissions INT, inventoryCurrentPermissions INT, invType INT, creatorID VARCHAR(255), inventoryBasePermissions INT, inventoryEveryOnePermissions INT, salePrice INT, saleType INT, creationDate INT, groupID CHAR(36), groupOwned INT, flags INT, inventoryID CHAR(36), avatarID CHAR(36), parentFolderID CHAR(36), inventoryGroupPermissions INT)',
    );
    $pdo->exec("INSERT INTO auth VALUES ('11111111-1111-1111-1111-111111111111', 'old', 'salt', '', 'UserAccount')");

    return $pdo;
}

/** Everything in one database. */
function avatars_in(OSPDO $pdo): void
{
    Connections::using(static fn() => $pdo);
}

afterEach(fn() => Connections::using(null));

describe('avatars', function () {
    test('are listed with their account, their last visit and their profile', function () {
        avatars_in(avatar_database());
        $list = Avatar::all(avatar_grid());

        expect($list)
            ->toBeInstanceOf(Collection::class)
            ->and($list->count())
            ->toBe(3)
            ->and($list->total())
            ->toBe(3)
            ->and(array_column($list->array(), 'name'))
            ->toBe(['Ada Love', 'Bob Root', 'Cy Gone'])
            ->and($list->sort())
            ->toBe('name asc');

        $ada = $list->first()->detail();
        expect($ada['uuid'])
            ->toBe('11111111-1111-1111-1111-111111111111')
            ->and($ada['slug'])
            ->toBe('ada-love')
            ->and($ada['uri'])
            ->toBe('ada.love@play.example.org:8002')
            ->and($ada['email'])
            ->toBe('ada@example.org')
            ->and($ada['created'])
            ->toBe(1700000000)
            ->and($ada['online'])
            ->toBeTrue()
            ->and($ada['lastSeen'])
            ->toBe(1700005000)
            ->and($ada['homeRegion'])
            ->toBe('aaaa')
            ->and($ada['profileImage'])
            ->toBe('img-ada')
            ->and($ada['about'])
            ->toBe('Hello')
            ->and($ada['partner'])
            ->toBe('22222222-2222-2222-2222-222222222222')
            ->and($ada['wants'])
            ->toBe('to build')
            ->and($ada['wantsList'])
            ->toBe(['build', 'meet'])
            ->and($ada['skillsList'])
            ->toBe(['architecture', 'scripting'])
            ->and($ada['languages'])
            ->toBe('en, fr')
            ->and($ada['firstImage'])
            ->toBeNull()
            ->and($ada['firstLife'])
            ->toBe('a mathematician')
            ->and($ada['technical'])
            ->toBeFalse()
            ->and($list->last()->name())
            ->toBe('Cy Gone');
    });

    test('know their level, their flags and whether they are active, a god and in the world', function () {
        avatars_in(avatar_database());
        [$ada, $bob, $cy] = Avatar::all(avatar_grid())->items();

        expect($bob->isGod())
            ->toBeTrue()
            ->and($bob->level())
            ->toBe(250)
            ->and($bob->flags())
            ->toBe(3)
            ->and($ada->isGod())
            ->toBeFalse()
            ->and($ada->isOnline())
            ->toBeTrue()
            ->and($bob->isOnline())
            ->toBeFalse()
            ->and($cy->isEnabled())
            ->toBeFalse()
            ->and($ada->isEnabled())
            ->toBeTrue()
            ->and($bob->lastSeen()->getTimestamp())
            ->toBe(1695000000)
            ->and($cy->lastSeen())
            ->toBeNull();
    });

    test('are searched by words, each in the first name, the last name or the email', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();

        expect(array_column(Avatar::all($config, ['search' => 'ada'])->array(), 'name'))
            ->toBe(['Ada Love'])
            ->and(array_column(Avatar::all($config, ['search' => 'ove ada'])->array(), 'name'))
            ->toBe(['Ada Love'])
            ->and(
                array_column(Avatar::all($config, ['search' => 'example.org', 'searchEmail' => true])->array(), 'name'),
            )
            ->toBe(['Ada Love', 'Bob Root', 'Cy Gone'])
            ->and(Avatar::all($config, ['search' => 'example.org'])->total())
            ->toBe(0)
            ->and(Avatar::all($config, ['search' => 'nobody'])->total())
            ->toBe(0)
            ->and(Avatar::all($config, ['search' => 'ada'])->search())
            ->toBe('ada');
    });

    test('are sorted on a column, in either direction', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();
        $names = static fn(string $sort) => array_column(Avatar::all($config, ['sort' => $sort])->array(), 'name');

        expect($names('name desc'))
            ->toBe(['Cy Gone', 'Bob Root', 'Ada Love'])
            ->and($names('created'))
            ->toBe(['Bob Root', 'Cy Gone', 'Ada Love'])
            ->and($names('lastSeen desc'))
            ->toBe(['Ada Love', 'Bob Root', 'Cy Gone'])
            ->and($names('level desc'))
            ->toBe(['Bob Root', 'Ada Love', 'Cy Gone'])
            ->and($names('online desc'))
            ->toBe(['Ada Love', 'Bob Root', 'Cy Gone'])
            ->and($names('nonsense; DROP TABLE x'))
            ->toBe(['Ada Love', 'Bob Root', 'Cy Gone']);
    });

    test('are filtered by status: active, disabled by a manager, waiting for the confirmation of its email', function () {
        $pdo = avatar_database();
        avatars_in($pdo);
        $config = avatar_grid();
        $names = static fn(string $status) => array_column(
            Avatar::all($config, ['filters' => ['status' => $status]])->array(),
            'name',
        );

        $before = [$names('active'), $names('disabled'), $names('pending')];
        $pdo->exec('UPDATE UserAccounts SET UserFlags = ' . Avatar::PENDING_FLAG . " WHERE FirstName = 'Cy'");

        expect($before)
            ->toBe([['Ada Love', 'Bob Root'], ['Cy Gone'], []])
            ->and([$names('active'), $names('disabled'), $names('pending')])
            ->toBe([['Ada Love', 'Bob Root'], [], ['Cy Gone']])
            ->and(Avatar::find($config, 'Cy Gone')->status())
            ->toBe('pending');
    });

    test('active leaves out an avatar waiting for its email or disabled, whatever column tells it', function () {
        $pdo = avatar_database();
        $pending = Avatar::PENDING_FLAG;
        $pdo->exec("INSERT INTO UserAccounts (PrincipalID, FirstName, LastName, Email, Created, UserLevel, UserFlags, active) VALUES
            ('a1111111-1111-4111-8111-111111111111', 'Pia', 'Pending', 'pia@example.org', 1900000000, -1, $pending, 0),
            ('a2222222-2222-4222-8222-222222222222', 'Pat', 'Flagged', 'pat@example.org', 1900000001, 0, $pending, 1),
            ('a3333333-3333-4333-8333-333333333333', 'Dan', 'Inactive', 'dan@example.org', 1900000002, 0, 0, 0),
            ('a4444444-4444-4444-8444-444444444444', 'Lou', 'Lowered', 'lou@example.org', 1900000003, -1, 0, 1)");
        avatars_in($pdo);
        $config = avatar_grid();
        $listed = static fn(string $status) => array_column(
            Avatar::all($config, ['filters' => ['status' => $status], 'sort' => 'created desc'])->array(),
            'status',
            'name',
        );

        expect($listed('active'))
            ->toBe(['Ada Love' => 'active', 'Bob Root' => 'active'])
            ->and($listed('pending'))
            ->toBe(['Pat Flagged' => 'pending', 'Pia Pending' => 'pending'])
            ->and(array_keys($listed('disabled')))
            ->toBe(['Lou Lowered', 'Dan Inactive', 'Cy Gone']);
    });

    test('are filtered by whether their profile is published, the box "show in search" of the viewer', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();
        $names = static fn(string $published) => array_column(
            Avatar::all($config, ['filters' => ['published' => $published]])->array(),
            'name',
        );

        expect($names('yes'))
            ->toBe(['Bob Root'])
            ->and($names('no'))
            ->toBe(['Ada Love', 'Cy Gone'])
            ->and(Avatar::find($config, 'Bob Root')->isPublished())
            ->toBeTrue()
            ->and(Avatar::find($config, 'Ada Love')->detail()['published'])
            ->toBeFalse();
    });

    test('are filtered by active, online and god', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();
        $names = static fn(array $filters) => array_column(
            Avatar::all($config, ['filters' => $filters])->array(),
            'name',
        );

        expect($names(['active' => 'yes']))
            ->toBe(['Ada Love', 'Bob Root'])
            ->and($names(['active' => 'no']))
            ->toBe(['Cy Gone'])
            ->and($names(['online' => 'yes']))
            ->toBe(['Ada Love'])
            ->and($names(['online' => 'no']))
            ->toBe(['Bob Root', 'Cy Gone'])
            ->and($names(['god' => 'yes']))
            ->toBe(['Bob Root'])
            ->and($names(['god' => 'yes', 'active' => 'no']))
            ->toBe([])
            ->and($names(['unknown' => 'yes', 'god' => 'maybe']))
            ->toBe(['Ada Love', 'Bob Root', 'Cy Gone'])
            ->and(Avatar::all($config, ['filters' => ['online' => 'yes']])->applied())
            ->toBe(['online' => 'yes']);
    });

    test('have the role of their level, as w4os names it, and are filtered and sorted by it', function () {
        $pdo = avatar_database();
        avatars_in($pdo);
        $config = avatar_grid();
        // A level that is not the one of its role, and a god a manager disabled (its level kept below 0)
        $pdo->exec("INSERT INTO UserAccounts (PrincipalID, FirstName, LastName, Email, Created, UserLevel, UserFlags, active) VALUES
            ('44444444-4444-4444-4444-444444444444', 'Dee', 'Like', 'dee@example.org', 1700000001, 1, 0, 1),
            ('55555555-5555-5555-5555-555555555555', 'Eve', 'Help', 'eve@example.org', 1700000002, 120, 0, 1),
            ('66666666-6666-6666-6666-666666666666', 'Fay', 'Link', 'fay@example.org', 1700000003, 150, 0, 1),
            ('77777777-7777-7777-7777-777777777777', 'Gus', 'Off', 'gus@example.org', 1700000004, -201, 0, 0)");
        $roles = array_map(
            static fn(array $avatar): array => [$avatar['role'], $avatar['standardLevel']],
            array_column(Avatar::all($config)->array(), null, 'name'),
        );
        $names = static fn(string $role) => array_column(
            Avatar::all($config, ['filters' => ['role' => $role]])->array(),
            'name',
        );

        expect($roles)
            ->toBe([
                'Ada Love' => ['resident', true],
                'Bob Root' => ['god', false],
                'Cy Gone' => ['resident', true],
                'Dee Like' => ['god-like', true],
                'Eve Help' => ['customer-service', false],
                'Fay Link' => ['liaison', true],
                'Gus Off' => ['god', true],
            ])
            ->and(array_map($names, Avatar::ROLES === [] ? [] : array_combine(array_keys(Avatar::ROLES), array_keys(Avatar::ROLES))))
            ->toBe([
                'resident' => ['Ada Love', 'Cy Gone'],
                'god-like' => ['Dee Like'],
                'customer-service' => ['Eve Help'],
                'liaison' => ['Fay Link'],
                'god' => ['Bob Root', 'Gus Off'],
            ])
            ->and(array_column(Avatar::all($config, ['sort' => 'level desc'])->array(), 'name'))
            ->toBe(['Bob Root', 'Gus Off', 'Fay Link', 'Eve Help', 'Dee Like', 'Ada Love', 'Cy Gone'])
            ->and(Avatar::all($config, ['filters' => ['role' => 'admin']])->applied())
            ->toBe([])
            ->and(Avatar::roleOf(-3))
            ->toBe('resident');
    });

    test('come in pages, the total being the one before the pages', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();
        $first = Avatar::all($config, ['perPage' => 2]);
        $second = Avatar::all($config, ['perPage' => 2, 'page' => 2]);

        expect($first->count())
            ->toBe(2)
            ->and($first->total())
            ->toBe(3)
            ->and($first->pages())
            ->toBe(2)
            ->and($first->page())
            ->toBe(1)
            ->and($second->count())
            ->toBe(1)
            ->and($second->first()->name())
            ->toBe('Cy Gone')
            ->and($second->page())
            ->toBe(2);
    });

    test('say which columns they have and which of them can be sorted', function () {
        avatars_in(avatar_database());
        $list = Avatar::all(avatar_grid());
        $sortable = array_column(
            array_filter($list->columns(), static fn(array $c): bool => $c['sortable'] ?? false),
            'name',
        );

        expect(array_column($list->columns(), 'name'))
            ->toBe(['name', 'email', 'role', 'lastSeen', 'created', 'status'])
            ->and($sortable)
            ->toBe(['name', 'email', 'role', 'lastSeen', 'created', 'status'])
            ->and($list->columns()[0]['label'])
            ->toBe('Avatar Name')
            ->and(array_keys($list->filters()))
            ->toBe(['status', 'role', 'online', 'published', 'email', 'model', 'technical', 'god', 'active']);
    });

    test('are found by their UUID or their name', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();

        expect(Avatar::find($config, '22222222-2222-2222-2222-222222222222')->name())
            ->toBe('Bob Root')
            ->and(Avatar::find($config, 'Ada Love')->uuid())
            ->toBe('11111111-1111-1111-1111-111111111111')
            ->and(Avatar::find($config, 'ada.love'))
            ->toBeNull()
            ->and(Avatar::find($config, 'Ada.Love')->name())
            ->toBe('Ada Love')
            ->and(Avatar::find($config, '99999999-9999-9999-9999-999999999999'))
            ->toBeNull()
            ->and(Avatar::find($config, 'not a name at all'))
            ->toBeNull();
    });

    test('give a list, a JSON and a CSV of themselves', function () {
        avatars_in(avatar_database());
        $list = Avatar::all(avatar_grid(), ['perPage' => 1]);
        $csv = explode("\n", trim($list->csv()));

        expect(json_decode($list->json(), true)[0]['name'])
            ->toBe('Ada Love')
            ->and($csv[0])
            ->toBe('name,email,role,lastSeen,created,status')
            ->and($csv[1])
            ->toBe('"Ada Love",ada@example.org,resident,1700005000,1700000000,active');
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('A search', function () {
    test('looks for the characters it is given: the wildcards of the database are not wildcards', function () {
        $pdo = avatar_database();
        $pdo->exec(
            "INSERT INTO UserAccounts (PrincipalID, FirstName, LastName, Created) VALUES ('77777777-7777-7777-7777-777777777777', 'Under_score', '100%', 1)",
        );
        avatars_in($pdo);
        $config = avatar_grid();
        $names = static fn(string $search) => array_column(
            Avatar::all($config, ['search' => $search])->array(),
            'name',
        );

        expect($names('%'))
            ->toBe(['Under_score 100%'])
            ->and($names('_'))
            ->toBe(['Under_score 100%'])
            ->and($names('r_s'))
            ->toBe(['Under_score 100%'])
            ->and($names('A_a'))
            ->toBe([])
            ->and($names('00%'))
            ->toBe(['Under_score 100%'])
            ->and($names('!'))
            ->toBe([])
            ->and($names('\\'))
            ->toBe([]);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('What a list of avatars offers', function () {
    test('is the visits too when the accounts and the visits are in one database', function () {
        expect(Avatar::capabilities(avatar_grid()))
            ->toBe([
                'sortable' => ['name', 'email', 'level', 'lastSeen', 'created', 'active', 'online'],
                'filters' => ['status', 'role', 'online', 'published', 'email', 'model', 'technical', 'god', 'active'],
            ])
            ->and(
                Avatar::capabilities(
                    avatar_grid(
                        "[GridUserService]\n ConnectionString = \"Data Source=localhost;Database=visits;User ID=os;Password=pw;\"\n",
                    ),
                ),
            )
            ->toBe([
                'sortable' => ['name', 'email', 'level', 'created', 'active'],
                'filters' => ['status', 'role', 'published', 'email', 'model', 'technical', 'god', 'active'],
            ]);
    });
});

describe('account of the services of the grid', function () {
    test('is not a person: the filter leaves it out', function () {
        $pdo = avatar_database();
        $pdo->exec(
            "INSERT INTO UserAccounts (PrincipalID, FirstName, LastName, Created) VALUES ('99999999-9999-9999-9999-999999999999', 'GRID', 'SERVICES', 1500000000)",
        );
        avatars_in($pdo);
        $config = avatar_grid();
        $names = static fn(array $filters) => array_column(
            Avatar::all($config, ['filters' => $filters, 'sort' => 'created'])->array(),
            'name',
        );

        expect($names([]))
            ->toBe(['GRID SERVICES', 'Bob Root', 'Cy Gone', 'Ada Love'])
            ->and($names(['technical' => 'no']))
            ->toBe(['Bob Root', 'Cy Gone', 'Ada Love'])
            ->and($names(['technical' => 'yes']))
            ->toBe(['GRID SERVICES'])
            ->and(Avatar::find($config, 'GRID SERVICES')->isTechnical())
            ->toBeTrue()
            ->and(Avatar::find($config, 'Ada Love')->isTechnical())
            ->toBeFalse();
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

/** The tables of the groups of the grid (the module Groups of the core), Ada in three groups and Bob in one. */
function groups_in(OSPDO $pdo): void
{
    $pdo->exec('CREATE TABLE os_groups_groups (GroupID CHAR(36), Name VARCHAR(255), InsigniaID CHAR(36))');
    $pdo->exec('CREATE TABLE os_groups_membership (GroupID CHAR(36), PrincipalID VARCHAR(255), SelectedRoleID CHAR(36), ListInProfile INT)');
    $pdo->exec('CREATE TABLE os_groups_roles (GroupID CHAR(36), RoleID CHAR(36), Name VARCHAR(255), Title VARCHAR(255))');
    $pdo->exec("INSERT INTO os_groups_groups VALUES
        ('g1', 'Builders', 'aaaaaaaa-0000-4000-8000-000000000002'),
        ('g2', 'Artists', '00000000-0000-0000-0000-000000000000'),
        ('g3', 'Secret Club', ''),
        ('g4', 'Bobs', '')");
    $pdo->exec("INSERT INTO os_groups_roles VALUES
        ('g1', 'r1', 'Owners', 'Chief Builder'),
        ('g2', '00000000-0000-0000-0000-000000000000', 'Everyone', '')");
    $pdo->exec("INSERT INTO os_groups_membership VALUES
        ('g1', '11111111-1111-1111-1111-111111111111', 'r1', 1),
        ('g2', '11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 1),
        ('g3', '11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 0),
        ('g4', '22222222-2222-2222-2222-222222222222', '00000000-0000-0000-0000-000000000000', 1)");
}

describe('groups of an avatar', function () {
    test('are the ones it shows in its profile, by name, with the title it wears and the insignia', function () {
        $pdo = avatar_database();
        groups_in($pdo);
        avatars_in($pdo);

        expect(Avatar::find(avatar_grid(), 'Ada Love')->groups())
            ->toBe([
                ['id' => 'g2', 'name' => 'Artists', 'title' => null, 'picture' => null],
                ['id' => 'g1', 'name' => 'Builders', 'title' => 'Chief Builder', 'picture' => 'aaaaaaaa-0000-4000-8000-000000000002'],
            ])
            ->and(array_column(Avatar::find(avatar_grid(), 'Bob Root')->groups(), 'name'))
            ->toBe(['Bobs']);
    });

    test('are none when the grid has no groups', function () {
        avatars_in(avatar_database());

        expect(Avatar::find(avatar_grid(), 'Ada Love')->groups())->toBe([]);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('picks and the classifieds of an avatar', function () {
    test('are what it published and what has not expired', function () {
        avatars_in(avatar_database());
        $ada = Avatar::find(avatar_grid(), 'Ada Love');

        expect(array_column($ada->picks(), 'name'))
            ->toBe(['Alpha pick', 'The Garden'])
            ->and($ada->picks()[0]['gatekeeper'])
            ->toBe('other.example.org:8002')
            ->and(array_column($ada->classifieds(1_750_000_000), 'name'))
            ->toBe(['Roses for sale'])
            ->and($ada->classifieds(1_500_000_000))
            ->toHaveCount(2)
            ->and(Avatar::find(avatar_grid(), 'Bob Root')->picks())
            ->toBe([]);
    });

    test('tell what the viewer shows: the picture, the parcel, the region, the position in it, the teleport', function () {
        $pdo = avatar_database();
        $pdo->exec('ALTER TABLE userpicks ADD COLUMN snapshotuuid CHAR(36)');
        $pdo->exec('ALTER TABLE userpicks ADD COLUMN user VARCHAR(255)');
        $pdo->exec('ALTER TABLE classifieds ADD COLUMN snapshotuuid CHAR(36)');
        $pdo->exec('ALTER TABLE classifieds ADD COLUMN posglobal VARCHAR(255)');
        $pdo->exec("UPDATE userpicks SET snapshotuuid = 'aaaaaaaa-0000-4000-8000-000000000001', user = 'Rose Garden' WHERE pickuuid = 'k1'");
        $pdo->exec("UPDATE userpicks SET snapshotuuid = '00000000-0000-0000-0000-000000000000', user = '' WHERE pickuuid = 'k3'");
        $pdo->exec("UPDATE userpicks SET gatekeeper = 'http://play.example.org:8002' WHERE pickuuid = 'k1'");
        $pdo->exec("UPDATE classifieds SET posglobal = '<256100.5,256200.2,22.7>'");
        // Welcome is a region of 512 meters: the position in it is not the rest of the division by 256
        $pdo->exec("INSERT INTO regions VALUES ('r1', 'Welcome', 255744, 256000, 512, 512)");
        avatars_in($pdo);
        $ada = Avatar::find(avatar_grid(), 'Ada Love');
        $picks = array_column($ada->picks(), null, 'pickuuid');
        $shown = static fn(array $row): array => array_intersect_key($row, array_flip(['picture', 'parcel', 'region', 'position', 'teleport']));

        expect($shown($picks['k1']))
            ->toBe([
                'picture' => 'aaaaaaaa-0000-4000-8000-000000000001',
                'parcel' => 'Rose Garden',
                'region' => 'Welcome',
                'position' => [266, 20, 30],
                'teleport' => 'hop://play.example.org:8002/Welcome/266/20/30',
            ])
            ->and($shown($picks['k3']))
            ->toBe([
                'picture' => null,
                'parcel' => null,
                'region' => 'Harbour',
                'position' => [244, 20, 30],
                'teleport' => 'hop://other.example.org:8002/Harbour/244/20/30',
            ])
            ->and($picks['k1']['posglobal'])
            ->toBe('<256010,256020,30>')
            ->and($shown($ada->classifieds(1_750_000_000)[0]))
            ->toBe([
                'picture' => null,
                'parcel' => 'The Garden',
                'region' => 'Welcome',
                'position' => [356, 200, 22],
                'teleport' => 'hop://play.example.org:8002/Welcome/356/200/22',
            ]);
    });

    test('are none when the profiles cannot be reached', function () {
        avatars_in(avatar_database());
        $ada = Avatar::find(avatar_grid(), 'Ada Love');
        Connections::using(static fn() => null);

        expect($ada->picks())->toBe([])->and($ada->classifieds())->toBe([]);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('An avatar that is changed', function () {
    test('is changed in the database of the accounts, for the email, the title, the level and active', function () {
        $pdo = avatar_database();
        avatars_in($pdo);
        $config = avatar_grid();
        $cy = Avatar::find($config, 'Cy Gone');

        expect($cy->channels())->toBe(['database']);
        $cy->enable();
        $cy->update(['email' => 'cy@new.example.org', 'title' => 'Ghost', 'level' => 10]);
        $cy = Avatar::find($config, 'Cy Gone');
        expect($cy->isEnabled())
            ->toBeTrue()
            ->and($cy->detail()['email'])
            ->toBe('cy@new.example.org')
            ->and($cy->detail()['title'])
            ->toBe('Ghost')
            ->and($cy->level())
            ->toBe(10);

        $cy->disable();
        expect(Avatar::find($config, 'Cy Gone')->isEnabled())->toBeFalse();
    });

    test('has its wants and its skills changed by the names of their flags, or by the number of the viewer', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();
        $ada = Avatar::find($config, 'Ada Love');
        $refusal = static function (array $values) use ($ada): ?string {
            try {
                $ada->update($values);
            } catch (InvalidValue $e) {
                return $e->reason;
            }

            return null;
        };

        $ada->update(['wantsList' => ['hire', 'build', 'be-hired'], 'skillsList' => 48]);
        $changed = Avatar::find($config, 'Ada Love')->detail();
        $wrong = [$refusal(['wantsList' => ['flying']]), $refusal(['skillsList' => 64]), $refusal(['wantsList' => 'build'])];
        $ada->update(['wantsList' => [], 'skillsList' => '0']);
        $none = Avatar::find($config, 'Ada Love')->detail();

        expect([$changed['wantsList'], $changed['skillsList']])
            ->toBe([['build', 'be-hired', 'hire'], ['scripting', 'custom-characters']])
            ->and($wrong)
            ->toBe(['invalid-request', 'invalid-request', 'invalid-request'])
            ->and([$none['wantsList'], $none['skillsList']])
            ->toBe([[], []])
            ->and(Avatar::profileFlags()['wants'][6])
            ->toBe(['name' => 'be-hired', 'value' => 64, 'key' => 'want_be_hired'])
            ->and(array_column(Avatar::profileFlags()['skills'], 'value', 'name'))
            ->toBe([
                'textures' => 1,
                'architecture' => 2,
                'event-planning' => 4,
                'modeling' => 8,
                'scripting' => 16,
                'custom-characters' => 32,
            ]);
    });

    test('keeps its name: only the listed values can be changed', function () {
        avatars_in(avatar_database());
        $ada = Avatar::find(avatar_grid(), 'Ada Love');

        expect(fn() => $ada->update(['firstName' => 'Eve']))->toThrow(InvalidArgumentException::class);
    });

    test('is not deleted: no channel does it', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();

        expect(fn() => Avatar::find($config, 'Ada Love')->delete())->toThrow(ChannelUnavailable::class);
    });

    test('has its password changed, its home set, and the profile it has changed', function () {
        $pdo = avatar_creation_database();
        avatars_in($pdo);
        $config = avatar_grid();
        $ada = Avatar::find($config, 'Ada Love');
        $ada->update(['password' => 'new password', 'home' => 'cccc', 'about' => 'Changed', 'wants' => 'a boat']);

        $auth = $pdo->get_row(
            'SELECT passwordHash, passwordSalt FROM auth WHERE UUID = :id',
            ['id' => $ada->id()],
            PDO::FETCH_ASSOC,
        );
        expect($auth['passwordHash'])
            ->toBe(md5(md5('new password') . ':' . $auth['passwordSalt']))
            ->and($auth['passwordHash'])
            ->not->toBe(md5(md5('old') . ':salt'))
            ->and($pdo->get_var('SELECT HomeRegionID FROM GridUser WHERE UserID = :id', ['id' => $ada->id()]))
            ->toBe('cccc')
            ->and($pdo->get_var('SELECT HomePosition FROM GridUser WHERE UserID = :id', ['id' => $ada->id()]))
            ->toBe('<128,128,22>');
        $changed = Avatar::find($config, 'Ada Love')->detail();
        expect($changed['about'])
            ->toBe('Changed')
            ->and($changed['wants'])
            ->toBe('a boat')
            ->and($changed['skills'])
            ->toBe('scripting');

        $cy = Avatar::find($config, 'Cy Gone');
        $cy->update(['password' => 'first one']);
        expect($pdo->get_var('SELECT accountType FROM auth WHERE UUID = :id', ['id' => $cy->id()]))->toBe(
            'UserAccount',
        );
    });

    test('has its profile made when the grid has not made it yet, as Robust makes it', function () {
        $pdo = avatar_creation_database();
        // The profiles as Robust makes them: every column required, none with a default
        $pdo->exec('DROP TABLE userprofile');
        $pdo->exec(
            'CREATE TABLE userprofile (useruuid VARCHAR(36) NOT NULL PRIMARY KEY, profilePartner VARCHAR(36) NOT NULL, profileAllowPublish BINARY(1) NOT NULL, profileMaturePublish BINARY(1) NOT NULL, profileURL VARCHAR(255) NOT NULL, profileWantToMask INT NOT NULL, profileWantToText TEXT NOT NULL, profileSkillsMask INT NOT NULL, profileSkillsText TEXT NOT NULL, profileLanguages TEXT NOT NULL, profileImage VARCHAR(36) NOT NULL, profileAboutText TEXT NOT NULL, profileFirstImage VARCHAR(36) NOT NULL, profileFirstText TEXT NOT NULL)',
        );
        avatars_in($pdo);
        $bob = Avatar::find(avatar_grid(), 'Bob Root');

        $bob->update(['about' => 'Hi', 'email' => 'bob@new.example.org']);
        $bob->update(['wants' => 'a boat']);
        $row = $pdo->get_row('SELECT * FROM userprofile WHERE useruuid = :id', ['id' => $bob->id()], PDO::FETCH_ASSOC);
        $detail = Avatar::find(avatar_grid(), 'Bob Root')->detail();

        expect($row)
            ->toMatchArray([
                'profileAboutText' => 'Hi',
                'profileWantToText' => 'a boat',
                'profilePartner' => '00000000-0000-0000-0000-000000000000',
                'profileImage' => '00000000-0000-0000-0000-000000000000',
                'profileURL' => '',
                'profileSkillsText' => '',
            ])
            ->and($detail['email'])
            ->toBe('bob@new.example.org')
            ->and($detail['about'])
            ->toBe('Hi');
    });

    test('is changed all or not at all: nothing changes when a part cannot be written', function () {
        $pdo = avatar_creation_database();
        $pdo->exec("CREATE TRIGGER refuse BEFORE UPDATE ON userprofile BEGIN SELECT RAISE(ABORT, 'refused'); END");
        avatars_in($pdo);
        $config = avatar_grid();
        $ada = Avatar::find($config, 'Ada Love');

        expect(fn() => $ada->update(['email' => 'ada@new.example.org', 'password' => 'brand-new', 'about' => 'x']))
            ->toThrow(ChannelUnavailable::class)
            ->and(Avatar::find($config, 'Ada Love')->detail()['email'])
            ->toBe('ada@example.org')
            ->and($pdo->get_var('SELECT passwordHash FROM auth WHERE UUID = :id', ['id' => $ada->id()]))
            ->toBe('old')
            ->and(fn() => $ada->update(['password' => '']))
            ->toThrow(InvalidArgumentException::class);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('An avatar that is made', function () {
    test('has no part of its name w4os keeps: Default, Test, Admin, the name of the grid, in any case', function () {
        expect(Avatar::validateName('Admin Person'))
            ->toBeNull()
            ->and(Avatar::validateNewName('Admin Person'))
            ->toContain('reserved')
            ->and(Avatar::validateNewName('jane TEST'))
            ->toContain('reserved')
            ->and(Avatar::validateNewName('Jane Mygrid', 'My Grid'))
            ->toContain('reserved')
            ->and(Avatar::validateNewName('Jane Doe', 'My Grid'))
            ->toBeNull()
            ->and(Avatar::validateNewName('Defaulter Doe'))
            ->toBeNull()
            ->and(Avatar::validateNewName('Jane'))
            ->toContain('invalid');
    });

    test('is refused a reserved name, but for whoever says it is one on purpose (a model)', function () {
        avatars_in(avatar_creation_database());
        $config = avatar_grid("[GridInfoService]\n gridname = \"My Grid\"\n");
        $refusal = static function (array $values) use ($config): ?InvalidValue {
            try {
                Avatar::create($config, $values + ['password' => 'secret']);
            } catch (InvalidValue $e) {
                return $e;
            }

            return null;
        };

        expect($refusal(['name' => 'Default Jane'])?->reason)
            ->toBe('name-reserved')
            ->and($refusal(['name' => 'Jane MYGRID'])?->reason)
            ->toBe('name-reserved')
            ->and($refusal(['name' => 'Jane'])?->reason)
            ->toBe('name-invalid')
            ->and($refusal(['name' => 'Ada Love'])?->reason)
            ->toBe('name-taken')
            ->and($refusal(['name' => 'Jane Doe', 'password' => ''])?->reason)
            ->toBe('password-required')
            ->and($refusal(['name' => 'Jane Doe', 'password' => 'no'])?->reason)
            ->toBe('password-invalid')
            ->and($refusal(['name' => 'Jane Doe', 'email' => 'not an email'])?->reason)
            ->toBe('email-invalid')
            ->and($refusal(['name' => 'Jane Doe', 'model' => 'Nobody'])?->reason)
            ->toBe('model-unknown')
            ->and(Avatar::create($config, ['name' => 'Default Jane', 'password' => 'secret', 'reservedName' => true]))
            ->name()
            ->toBe('Default Jane');
    });

    test('has a capital to each part of a name typed all in small letters or all in capitals, before it is checked; a mixed one is kept', function () {
        avatars_in(avatar_creation_database());
        $config = avatar_grid();
        $refusal = static function (string $name) use ($config): ?string {
            try {
                Avatar::create($config, ['name' => $name, 'password' => 'secret']);
            } catch (InvalidValue $e) {
                return $e->reason;
            }

            return null;
        };

        expect(Avatar::create($config, ['name' => 'bart simpson', 'password' => 'secret'])->name())
            ->toBe('Bart Simpson')
            ->and(Avatar::create($config, ['firstName' => 'LISA', 'lastName' => 'sIMPSON', 'password' => 'secret'])->name())
            ->toBe('LISA sIMPSON')
            ->and(Avatar::create($config, ['firstName' => 'jeanpaul', 'lastName' => 'deLuxe', 'password' => 'secret'])->name())
            ->toBe('jeanpaul deLuxe')
            ->and($refusal('ada love'))
            ->toBe('name-taken')
            ->and($refusal('admin person'))
            ->toBe('name-reserved')
            ->and(Avatar::create($config, ['name' => 'jane doe', 'password' => 'secret', 'reservedName' => true])->name())
            ->toBe('jane doe')
            ->and(array_map(Avatar::capitalizeName(...), [
                'jeanpaul deluxe',
                'JeanPaul deLuxe',
                'BART simpson',
                'marty McFly',
                'jean-pierre dupont',
                '2pac shakur',
                'Ada Love',
            ]))
            ->toBe(['Jeanpaul Deluxe', 'JeanPaul deLuxe', 'BART simpson', 'marty McFly', 'Jean-Pierre Dupont', '2pac Shakur', 'Ada Love'])
            ->and(array_map(Avatar::capitalizeName(...), ['BART SIMPSON', 'JEAN-PIERRE DUPONT', 'mcFly marty', '2PAC SHAKUR']))
            ->toBe(['Bart Simpson', 'Jean-Pierre Dupont', 'mcFly marty', '2pac Shakur'])
            ->and(Avatar::normalizeName('élodie dupré'))
            ->toBe('Elodie Dupre')
            ->and(Avatar::find($config, 'Ada Love')->name())
            ->toBe('Ada Love');
    });

    test('is public, its profile shown in search, but for a model or when it is said not to be', function () {
        avatars_in(avatar_creation_database());
        $config = avatar_grid();

        $person = Avatar::create($config, ['name' => 'Bart Simpson', 'password' => 'secret']);
        $model = Avatar::create($config, ['name' => 'Default Jane', 'password' => 'secret', 'reservedName' => true]);
        $private = Avatar::create($config, ['name' => 'Mona Lisa', 'password' => 'secret', 'published' => false]);

        expect([$person->isPublished(), $model->isPublished(), $private->isPublished()])
            ->toBe([true, false, false])
            ->and(Avatar::find($config, 'Ada Love')->isPublished())
            ->toBeFalse();
    });

    test('has its account, its password, its inventory and its outfit, each in its table', function () {
        $pdo = avatar_creation_database();
        avatars_in($pdo);
        $config = avatar_grid();
        $new = Avatar::create($config, ['name' => 'New Person', 'email' => 'new@example.org', 'password' => 'secret']);

        expect($new->name())
            ->toBe('New Person')
            ->and($new->detail()['email'])
            ->toBe('new@example.org')
            ->and($new->isEnabled())
            ->toBeTrue()
            ->and(Avatar::find($config, 'New Person')->id())
            ->toBe($new->id());
        $auth = $pdo->get_row(
            'SELECT passwordHash, passwordSalt, accountType FROM auth WHERE UUID = :id',
            ['id' => $new->id()],
            PDO::FETCH_ASSOC,
        );
        expect($auth['passwordHash'])
            ->toBe(md5(md5('secret') . ':' . $auth['passwordSalt']))
            ->and($auth['accountType'])
            ->toBe('UserAccount')
            ->and(
                $pdo->get_var('SELECT folderName FROM inventoryfolders WHERE agentID = :id AND type = 8', [
                    'id' => $new->id(),
                ]),
            )
            ->toBe('My Inventory')
            ->and(
                (int) $pdo->get_var('SELECT COUNT(*) FROM inventoryfolders WHERE agentID = :id', ['id' => $new->id()]),
            )
            ->toBe(21)
            ->and((int) $pdo->get_var('SELECT COUNT(*) FROM inventoryitems WHERE avatarID = :id', ['id' => $new->id()]))
            ->toBe(12);
    });

    test('can be given its UUID and its home, and keep a password hash it comes with', function () {
        $pdo = avatar_creation_database();
        avatars_in($pdo);
        $new = Avatar::create(avatar_grid(), [
            'firstName' => 'Moved',
            'lastName' => 'Over',
            'uuid' => '44444444-4444-4444-4444-444444444444',
            'home' => 'dddd',
            'passwordHash' => ['hash' => 'abc', 'salt' => 'def'],
        ]);

        expect($new->id())
            ->toBe('44444444-4444-4444-4444-444444444444')
            ->and(
                $pdo->get_var("SELECT passwordHash || '/' || passwordSalt FROM auth WHERE UUID = :id", [
                    'id' => $new->id(),
                ]),
            )
            ->toBe('abc/def')
            ->and($pdo->get_var('SELECT HomeRegionID FROM GridUser WHERE UserID = :id', ['id' => $new->id()]))
            ->toBe('dddd');
    });

    test(
        'is refused for a name that is not one, a name that is taken, no password, a UUID that is not one',
        function () {
            avatars_in(avatar_creation_database());
            $config = avatar_grid();

            expect(fn() => Avatar::create($config, ['name' => 'Single', 'password' => 'secret']))
                ->toThrow(InvalidArgumentException::class, 'not the name')
                ->and(fn() => Avatar::create($config, ['name' => 'Ada Love', 'password' => 'secret']))
                ->toThrow(InvalidArgumentException::class, 'exists already')
                ->and(fn() => Avatar::create($config, ['name' => 'No Password']))
                ->toThrow(InvalidArgumentException::class, 'needs a password')
                ->and(fn() => Avatar::create($config, ['name' => 'Bad Uuid', 'password' => 'secret', 'uuid' => 'nope']))
                ->toThrow(InvalidArgumentException::class, 'not a UUID')
                ->and(Avatar::find($config, 'Bad Uuid'))
                ->toBeNull();
        },
    );

    test('is refused when the database cannot be reached', function () {
        avatars_in(avatar_creation_database());
        Connections::using(static fn() => null);

        expect(fn() => Avatar::create(avatar_grid(), ['name' => 'Lost Soul', 'password' => 'secret']))->toThrow(
            ChannelUnavailable::class,
        );
    });

    test('is refused when the database of the accounts cannot be reached', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();
        $ada = Avatar::find($config, 'Ada Love');
        Connections::using(static fn() => null);

        expect($ada->channels())
            ->toBe([])
            ->and(fn() => $ada->disable())
            ->toThrow(ChannelUnavailable::class)
            ->and(fn() => Avatar::all($config))
            ->toThrow(ChannelUnavailable::class);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('avatars of a grid whose services have their own databases', function () {
    test('are read from each, the visits and the profiles of the page added to the accounts', function () {
        $accounts = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        $accounts->exec(
            'CREATE TABLE UserAccounts (PrincipalID CHAR(36) PRIMARY KEY, ScopeID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64), Email VARCHAR(64), Created INT, UserLevel INT DEFAULT 0, UserFlags INT DEFAULT 0, UserTitle VARCHAR(64) DEFAULT \'\', active INT DEFAULT 1)',
        );
        $accounts->exec(
            "INSERT INTO UserAccounts (PrincipalID, FirstName, LastName, Email, Created) VALUES ('11111111-1111-1111-1111-111111111111', 'Ada', 'Love', 'ada@example.org', 1700000000), ('22222222-2222-2222-2222-222222222222', 'Bob', 'Root', 'bob@example.org', 1600000000)",
        );
        $visits = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        $visits->exec(
            'CREATE TABLE GridUser (UserID VARCHAR(255) PRIMARY KEY, HomeRegionID CHAR(36), LastRegionID CHAR(36), Online CHAR(5), Login CHAR(16), Logout CHAR(16))',
        );
        $visits->exec(
            "INSERT INTO GridUser VALUES ('11111111-1111-1111-1111-111111111111', 'home', 'last', 'true', '100', '200')",
        );
        $profiles = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        $profiles->exec(
            'CREATE TABLE userprofile (useruuid CHAR(36), profileImage CHAR(36), profileAboutText TEXT, profileURL VARCHAR(255), profilePartner CHAR(36), profileWantToText TEXT, profileSkillsText TEXT, profileLanguages TEXT, profileFirstImage CHAR(36), profileFirstText TEXT, profileWantToMask INT DEFAULT 0, profileSkillsMask INT DEFAULT 0)',
        );
        $profiles->exec(
            "INSERT INTO userprofile (useruuid, profileImage, profileAboutText) VALUES ('22222222-2222-2222-2222-222222222222', 'img-bob', 'Root')",
        );
        Connections::using(
            static fn(Database|string $what) => match ($what) {
                'UserAccountService' => $accounts,
                'GridUserService' => $visits,
                Database::Profiles => $profiles,
                default => null,
            },
        );
        $config = avatar_grid(
            "[GridUserService]\n ConnectionString = \"Data Source=localhost;Database=visits;User ID=os;Password=pw;\"\n" .
                "[UserProfilesService]\n ConnectionString = \"Data Source=localhost;Database=profiles;User ID=os;Password=pw;\"\n",
        );

        $list = Avatar::all($config, ['sort' => 'lastSeen desc', 'filters' => ['online' => 'yes']]);
        [$ada, $bob] = $list->items();

        expect($list->sort())
            ->toBe('name asc')
            ->and($list->total())
            ->toBe(2)
            ->and($list->applied())
            ->toBe([])
            ->and(array_keys($list->filters()))
            ->toBe(['status', 'role', 'published', 'email', 'model', 'technical', 'god', 'active'])
            ->and($ada->isOnline())
            ->toBeTrue()
            ->and($ada->lastSeen()->getTimestamp())
            ->toBe(200)
            ->and($ada->detail()['homeRegion'])
            ->toBe('home')
            ->and($bob->isOnline())
            ->toBeFalse()
            ->and($bob->detail()['profileImage'])
            ->toBe('img-bob')
            ->and(array_column(array_filter($list->columns(), static fn(array $c) => $c['sortable']), 'name'))
            ->toBe(['name', 'email', 'role', 'created', 'status']);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('An avatar made in a grid whose services have their own databases', function () {
    test('has each of its rows in the database of its service', function () {
        $make = static function (string $sql): OSPDO {
            $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
            $pdo->exec($sql);

            return $pdo;
        };
        $accounts = $make(
            'CREATE TABLE UserAccounts (PrincipalID CHAR(36) PRIMARY KEY, ScopeID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64), Email VARCHAR(64), ServiceURLs TEXT, Created INT, UserLevel INT DEFAULT 0, UserFlags INT DEFAULT 0, UserTitle VARCHAR(64) DEFAULT \'\', active INT DEFAULT 1)',
        );
        $auth = $make(
            'CREATE TABLE auth (UUID CHAR(36) PRIMARY KEY, passwordHash CHAR(32), passwordSalt CHAR(32), webLoginKey VARCHAR(255), accountType VARCHAR(32))',
        );
        $inventory = $make(
            'CREATE TABLE inventoryfolders (folderName VARCHAR(64), type INT, version INT, folderID CHAR(36), agentID CHAR(36), parentFolderID CHAR(36))',
        );
        $inventory->exec(
            'CREATE TABLE inventoryitems (assetID CHAR(36), assetType INT, inventoryName VARCHAR(64), inventoryDescription VARCHAR(128), inventoryNextPermissions INT, inventoryCurrentPermissions INT, invType INT, creatorID VARCHAR(255), inventoryBasePermissions INT, inventoryEveryOnePermissions INT, salePrice INT, saleType INT, creationDate INT, groupID CHAR(36), groupOwned INT, flags INT, inventoryID CHAR(36), avatarID CHAR(36), parentFolderID CHAR(36), inventoryGroupPermissions INT)',
        );
        $visits = $make(
            'CREATE TABLE GridUser (UserID VARCHAR(255) PRIMARY KEY, HomeRegionID CHAR(36), LastRegionID CHAR(36), Online CHAR(5), Login CHAR(16), Logout CHAR(16))',
        );
        Connections::using(
            static fn(Database|string $what) => match ($what) {
                'UserAccountService' => $accounts,
                'GridUserService' => $visits,
                'AuthenticationService' => $auth,
                'InventoryService' => $inventory,
                default => null,
            },
        );
        $config = avatar_grid(
            "[GridUserService]\n ConnectionString = \"Data Source=localhost;Database=visits;User ID=os;Password=pw;\"\n" .
                "[AuthenticationService]\n ConnectionString = \"Data Source=localhost;Database=auth;User ID=os;Password=pw;\"\n" .
                "[InventoryService]\n ConnectionString = \"Data Source=localhost;Database=inventory;User ID=os;Password=pw;\"\n",
        );

        $new = Avatar::create($config, ['name' => 'Split Rows', 'password' => 'secret']);

        expect((int) $accounts->get_var('SELECT COUNT(*) FROM UserAccounts'))
            ->toBe(1)
            ->and($auth->get_var('SELECT UUID FROM auth'))
            ->toBe($new->id())
            ->and((int) $inventory->get_var('SELECT COUNT(*) FROM inventoryfolders'))
            ->toBe(21);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('A list of a database that cannot be reached', function () {
    test('says why: what the server answered', function () {
        $dir = test_tmp() . '/unreachable-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        file_put_contents(
            "$dir/Robust.HG.ini",
            "[DatabaseService]\n ConnectionString = \"Data Source=127.0.0.1:1;Database=robust;User ID=os;Password=secret;\"\n",
        );
        $config = GridConfig::fromFolder($dir);

        try {
            Avatar::all($config);
            $message = '';
        } catch (ChannelUnavailable $e) {
            $message = $e->getMessage();
        }

        expect($message)
            ->toContain('cannot read the avatars')
            ->toContain('ERROR 2002')
            ->not->toContain('secret')
            ->and(Connections::reason($config, 'UserAccountService'))
            ->toContain('ERROR 2002');
    });

    test('says the config is incomplete when it does not say where the database is', function () {
        $dir = test_tmp() . '/nodb-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        file_put_contents("$dir/Robust.HG.ini", "[Const]\n BaseURL = \"http://play.example.org\"\n");

        expect(fn() => Avatar::all(GridConfig::fromFolder($dir)))->toThrow(
            ChannelUnavailable::class,
            'does not say where',
        );
    });
})->skip(fn() => !extension_loaded('pdo_mysql'), 'pdo_mysql is missing');
