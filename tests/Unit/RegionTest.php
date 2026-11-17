<?php

declare(strict_types=1);

/**
 * The regions of a grid through the model: the flags with the values of OpenSimulator, the list (search, sort, filters, pages),
 * one region, and the names of the flags in the config of Robust. The database is in memory (SQLite) with the tables of OpenSimulator.
 */

use OpenSim\Data\OSPDO;
use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Models\Region;

/** A grid folder whose Robust says its web address. */
function region_config(): GridConfig
{
    $dir = test_tmp() . '/regions-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o755, true);
    file_put_contents(
        "$dir/Robust.HG.ini",
        "[Const]\n BaseURL = \"http://play.example.org\"\n PublicPort = \"8002\"\n[Hypergrid]\n GatekeeperURI = \"\${Const|BaseURL}:\${Const|PublicPort}\"\n[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=robust;User ID=os;Password=pw;\"\n",
    );

    return GridConfig::fromFolder($dir);
}

/** Three regions of the grid and a link to a region of another grid, and their owner. */
function region_database(): OSPDO
{
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec(
        'CREATE TABLE regions (uuid VARCHAR(36) PRIMARY KEY, regionHandle BIGINT, regionName VARCHAR(128), locX INT, locY INT, sizeX INT DEFAULT 0, sizeY INT DEFAULT 0, serverIP VARCHAR(64), serverPort INT, serverURI VARCHAR(255), flags INT DEFAULT 0, last_seen INT DEFAULT 0, owner_uuid CHAR(36), access INT DEFAULT 21, ScopeID CHAR(36), regionMapTexture VARCHAR(36))',
    );
    $pdo->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64))');
    $pdo->exec("INSERT INTO UserAccounts VALUES ('11111111-1111-1111-1111-111111111111', 'Ada', 'Love')");
    $pdo->exec("INSERT INTO regions (uuid, regionHandle, regionName, locX, locY, sizeX, sizeY, serverIP, serverPort, serverURI, flags, last_seen, owner_uuid) VALUES
        ('aaaaaaaa-0000-0000-0000-000000000001', 1, 'Welcome', 256000, 256000, 256, 256, '10.0.0.1', 9001, 'http://10.0.0.1:9000/', 1029, 1700000000, '11111111-1111-1111-1111-111111111111'),
        ('aaaaaaaa-0000-0000-0000-000000000002', 2, 'Garden', 256256, 256000, 512, 512, '10.0.0.1', 9002, 'http://10.0.0.1:9000/', 22, 1600000000, '11111111-1111-1111-1111-111111111111'),
        ('aaaaaaaa-0000-0000-0000-000000000003', 3, 'Quiet Harbour', 255744, 256000, 0, 0, '10.0.0.2', 9011, 'http://10.0.0.2:9010/', 1040, 0, '00000000-0000-0000-0000-000000000000'),
        ('aaaaaaaa-0000-0000-0000-000000000004', 4, 'Far Away', 0, 0, 256, 256, '', 0, 'http://other.example.org:8002/', 512, 5, '00000000-0000-0000-0000-000000000000')");

    return $pdo;
}

afterEach(fn() => Connections::using(null));

describe('flags of a region', function () {
    test('have the values of OpenSimulator', function () {
        expect([
            Region::DEFAULT_REGION,
            Region::FALLBACK_REGION,
            Region::REGION_ONLINE,
            Region::NO_DIRECT_LOGIN,
            Region::PERSISTENT,
            Region::LOCKED_OUT,
            Region::NO_MOVE,
            Region::RESERVATION,
            Region::AUTHENTICATE,
            Region::HYPERLINK,
            Region::DEFAULT_HGREGION,
        ])->toBe([1, 2, 4, 8, 16, 32, 64, 128, 256, 512, 1024]);
    });

    test('are named as OpenSimulator names them (OpenSim.Framework.RegionFlags, the regions table), not as LSL does', function () {
        expect(Region::NAMES)->toBe([
            1 => 'DefaultRegion',
            2 => 'FallbackRegion',
            4 => 'RegionOnline',
            8 => 'NoDirectLogin',
            16 => 'Persistent',
            32 => 'LockedOut',
            64 => 'NoMove',
            128 => 'Reservation',
            256 => 'Authenticate',
            512 => 'Hyperlink',
            1024 => 'DefaultHGRegion',
        ]);
    });

    test('have the name the config of Robust gives them, and a label to show', function () {
        expect(Region::flagName(Region::DEFAULT_HGREGION))
            ->toBe('DefaultHGRegion')
            ->and(Region::flagName(Region::NO_DIRECT_LOGIN))
            ->toBe('NoDirectLogin')
            ->and(Region::flagValue('FallbackRegion'))
            ->toBe(2)
            ->and(Region::flagValue('Nonsense'))
            ->toBe(0)
            ->and(Region::label(Region::DEFAULT_REGION))
            ->toBe('Default')
            ->and(Region::flagLabels(1029))
            ->toBe([1 => 'Default', 4 => 'Online', 1024 => 'Default HG'])
            ->and(fn() => Region::flagName(3))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn() => Region::flagName(Region::REGION_ONLINE))
            ->toThrow(InvalidArgumentException::class);
    });

    test('have names in the config of Robust for the ones a region can be given', function () {
        expect(Region::flagNames(0))
            ->toBe([])
            ->and(Region::flagNames(Region::FALLBACK_REGION | Region::PERSISTENT))
            ->toBe(['FallbackRegion', 'Persistent'])
            ->and(Region::flagNames(1029))
            ->toBe(['DefaultRegion', 'DefaultHGRegion'])
            ->and(Region::flagNames(Region::REGION_ONLINE | Region::HYPERLINK))
            ->toBe([])
            ->and(Region::flagNames(2047))
            ->toBe([
                'DefaultRegion',
                'FallbackRegion',
                'NoDirectLogin',
                'Persistent',
                'LockedOut',
                'NoMove',
                'Reservation',
                'Authenticate',
                'DefaultHGRegion',
            ]);
    });
});

describe('Regions', function () {
    test('are listed without the links to other grids, with their place, their size and their owner', function () {
        Connections::using(static fn() => region_database());
        $list = Region::all(region_config());

        expect(array_column($list->array(), 'name'))
            ->toBe(['Garden', 'Quiet Harbour', 'Welcome'])
            ->and($list->total())
            ->toBe(3)
            ->and($list->applied())
            ->toBe([]);

        $welcome = $list->last()->detail();
        expect($welcome['uuid'])
            ->toBe('aaaaaaaa-0000-0000-0000-000000000001')
            ->and($welcome['slug'])
            ->toBe('welcome')
            ->and($welcome['uri'])
            ->toBe('play.example.org:8002/Welcome')
            ->and([$welcome['x'], $welcome['y']])
            ->toBe([1000, 1000])
            ->and($welcome['location'])
            ->toBe('1000,1000')
            ->and($welcome['size'])
            ->toBe('256x256')
            ->and([$welcome['width'], $welcome['height']])
            ->toBe([256, 256])
            ->and($welcome['port'])
            ->toBe(9001)
            ->and($welcome['owner'])
            ->toBe('11111111-1111-1111-1111-111111111111')
            ->and($welcome['ownerName'])
            ->toBe('Ada Love')
            ->and($welcome['isDefault'])
            ->toBeTrue()
            ->and($welcome['online'])
            ->toBeTrue()
            ->and($welcome['roles'])
            ->toBe(['DefaultRegion', 'DefaultHGRegion'])
            ->and($welcome['flagNames'])
            ->toBe(['DefaultRegion', 'DefaultHGRegion'])
            ->and($welcome['flagLabels'])
            ->toBe(['Default', 'Online', 'Default HG'])
            ->and($welcome['lastSeen'])
            ->toBe(1700000000);
    });

    test('know their flags, their size when the table does not say it, and nobody as an owner', function () {
        Connections::using(static fn() => region_database());
        [$garden, $harbour, $welcome] = Region::all(region_config())->items();

        expect($garden->roles())
            ->toBe(['FallbackRegion', 'Persistent'])
            ->and($welcome->roles())
            ->toBe(['DefaultRegion', 'DefaultHGRegion'])
            ->and($garden->width())
            ->toBe(512)
            ->and($garden->isFallback())
            ->toBeTrue()
            ->and($garden->isDefault())
            ->toBeFalse()
            ->and($garden->isOnline())
            ->toBeTrue()
            ->and($garden->has(Region::PERSISTENT))
            ->toBeTrue()
            ->and($harbour->width())
            ->toBe(256)
            ->and($harbour->owner())
            ->toBeNull()
            ->and($harbour->isOnline())
            ->toBeFalse()
            ->and($harbour->isDefault())
            ->toBeTrue()
            ->and($harbour->has(Region::PERSISTENT | Region::DEFAULT_HGREGION))
            ->toBeTrue()
            ->and($harbour->lastSeen())
            ->toBeNull()
            ->and($welcome->lastSeen()->getTimestamp())
            ->toBe(1700000000);
    });

    test('are filtered by their flags, any of the ones asked, a link to another grid only when asked', function () {
        Connections::using(static fn() => region_database());
        $config = region_config();
        $names = static fn(array $filters) => array_column(
            Region::all($config, ['filters' => $filters])->array(),
            'name',
        );
        $list = Region::all($config, ['filters' => ['flags' => 'DefaultRegion, DefaultHGRegion']]);

        expect($names([]))
            ->toBe(['Garden', 'Quiet Harbour', 'Welcome'])
            // Online or offline, the flag RegionOnline: a filter of its own
            ->and($names(['status' => 'online']))
            ->toBe(['Garden', 'Welcome'])
            ->and($names(['status' => 'offline']))
            ->toBe(['Quiet Harbour'])
            ->and(fn() => $names(['flags' => 'RegionOnline']))
            ->toThrow(fn(\OpenSim\Exceptions\InvalidValue $e) => expect($e->reason)->toBe('filter-value-unknown'))
            ->and(fn() => $names(['status' => 'yes']))
            ->toThrow(fn(\OpenSim\Exceptions\InvalidValue $e) => expect($e->reason)->toBe('filter-value-unknown'))
            ->and(array_column($list->array(), 'name'))
            ->toBe(['Quiet Harbour', 'Welcome'])
            ->and($list->applied())
            ->toBe(['flags' => 'DefaultRegion,DefaultHGRegion'])
            ->and($names(['flags' => 'FallbackRegion']))
            ->toBe(['Garden'])
            ->and($names(['flags' => 'Hyperlink']))
            ->toBe(['Far Away'])
            ->and($names(['flags' => 'Persistent,Hyperlink']))
            ->toBe(['Far Away', 'Garden', 'Quiet Harbour'])
            ->and($names(['flags' => 'NoMove']))
            ->toBe([])
            // The flags the regions have now, in the order of the table: the values of the filter that give something
            ->and($list->available()['flags'])
            ->toBe(['DefaultRegion', 'FallbackRegion', 'Persistent', 'Hyperlink', 'DefaultHGRegion'])
            ->and(fn() => $names(['flags' => 'DefaultRegion,Bogus']))
            ->toThrow(fn(\OpenSim\Exceptions\InvalidValue $e) => expect($e->reason)->toBe('filter-value-unknown'))
            ->and(fn() => $names(['online' => 'yes']))
            ->toThrow(fn(\OpenSim\Exceptions\InvalidValue $e) => expect($e->reason)->toBe('filter-unknown'));
    });

    test('are filtered by their owner, and give their hop address on the grid', function () {
        Connections::using(static fn() => region_database());
        $config = region_config();
        $names = static fn(array $filters) => array_column(
            Region::all($config, ['filters' => $filters])->array(),
            'name',
        );
        $teleports = array_column(Region::all($config, ['filters' => ['flags' => 'Hyperlink']])->array(), 'teleport');

        expect($names(['owner' => '11111111-1111-1111-1111-111111111111']))
            ->toBe(['Garden', 'Welcome'])
            ->and($names(['owner' => '11111111-1111-1111-1111-111111111111', 'flags' => 'DefaultHGRegion']))
            ->toBe(['Welcome'])
            ->and(Region::all($config, ['filters' => ['owner' => '11111111-1111-1111-1111-111111111111']])->applied())
            ->toBe(['owner' => '11111111-1111-1111-1111-111111111111'])
            ->and(Region::find($config, 'Quiet Harbour')->detail()['teleport'])
            ->toBe('hop://play.example.org:8002/Quiet+Harbour')
            ->and($teleports)
            ->toBe([null]);
    });

    test('link their owner to the web profile the grid gives, none when it gives none', function () {
        Connections::using(static fn() => region_database());
        $config = region_config();
        $dir = test_tmp() . '/regions-profile-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        file_put_contents(
            "$dir/Robust.HG.ini",
            "[Const]\n BaseURL = \"http://play.example.org\"\n[GridInfoService]\n web_profile_url = \"\${Const|BaseURL}/helpers/profile/[AGENT_NAME]\"\n[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=robust;User ID=os;Password=pw;\"\n",
        );
        $profiled = GridConfig::fromFolder($dir);
        $owners = static fn(GridConfig $grid): array => array_column(Region::all($grid)->array(), 'ownerProfile', 'name');

        expect($owners($profiled))
            ->toBe([
                'Garden' => 'http://play.example.org/helpers/profile/Ada.Love',
                'Quiet Harbour' => null,
                'Welcome' => 'http://play.example.org/helpers/profile/Ada.Love',
            ])
            ->and($owners($config))
            ->toBe(['Garden' => null, 'Quiet Harbour' => null, 'Welcome' => null])
            ->and(\OpenSim\Models\Avatar::profilePage($profiled, 'Jean Dé'))
            ->toBe('http://play.example.org/helpers/profile/Jean.D%C3%A9')
            ->and(\OpenSim\Models\Avatar::profilePage($profiled, 'Nobody'))
            ->toBeNull();
    });

    test('describe their list: columns, sorts, filters, search and actions, with the keys of the catalog', function () {
        $listing = Region::listing();

        expect(array_column($listing->columns, 'type', 'name'))
            ->toBe([
                'name' => 'text',
                'location' => 'text',
                'size' => 'size',
                'ownerName' => 'link',
                'serverName' => 'text',
                'flagNames' => 'list',
                'lastSeen' => 'datetime',
                'status' => 'indicators',
            ])
            ->and($listing->sorts())
            ->toBe(['name', 'location', 'size', 'flags', 'lastSeen'])
            ->and($listing->filterValues())
            ->toBe([
                'status' => ['online', 'offline'],
                'flags' => array_values(array_diff(Region::NAMES, ['RegionOnline'])),
                'owner' => [],
                'simulator' => [],
            ])
            ->and($listing->filters[0])
            ->toBe([
                'name' => 'status',
                'label' => 'status',
                'values' => [
                    ['value' => 'online', 'label' => 'online'],
                    ['value' => 'offline', 'label' => 'offline'],
                ],
            ])
            ->and($listing->filters[1]['label'])
            ->toBe('column_flags')
            ->and($listing->filters[1]['values'][0])
            ->toBe([
                'value' => 'DefaultRegion',
                'label' => 'region_flag_default_region',
                'description' => 'region_flag_default_region_help',
            ])
            ->and(array_intersect_key($listing->filters[1], ['multiple' => 1, 'dynamic' => 1]))
            ->toBe(['multiple' => true, 'dynamic' => true])
            // The column of the flags has every flag but the status, with the texts of the filter
            ->and($listing->columns[5]['values'])
            ->toBe($listing->filters[1]['values'])
            ->and(array_column($listing->columns[5]['values'], 'value'))
            ->toBe(array_values(array_diff(Region::NAMES, ['RegionOnline'])))
            // The map tile of the region beside its name, as the picture of an avatar
            ->and(array_intersect_key($listing->columns[0], ['picture' => 1, 'default' => 1]))
            ->toBe(['picture' => 'mapImage', 'default' => 'place'])
            ->and($listing->filters[2])
            ->toBe(['name' => 'owner', 'label' => 'column_owner', 'format' => 'uuid'])
            ->and(array_column($listing->actions, 'body', 'name'))
            ->toBe(['enable' => ['enabled' => true], 'disable' => ['enabled' => false]])
            // Enabled, the button that changes it where its file is, then online, in one column
            ->and(array_map(
                static fn(array $indicator): array => [$indicator['name'], $indicator['icon'], array_column($indicator['states'], 'tone', 'label'), $indicator['actions'] ?? []],
                $listing->columns[7]['indicators'],
            ))
            ->toBe([
                ['enabled', 'power', ['state_enabled' => 'ok', 'state_disabled' => 'off'], ['enable', 'disable']],
                ['online', 'online', ['online' => 'ok', 'offline' => 'off'], []],
            ])
            ->and(array_column($listing->columns, 'name'))
            ->not->toContain('online')
            ->and($listing->keys())
            ->toContain('state_enabled', 'offline')
            ->and(array_diff($listing->keys(), array_keys(\OpenSim\I18n\Catalog::MESSAGES)))
            ->toBe([])
            ->and(array_column(Region::columns(), 'label', 'name')['name'])
            ->toBe('Region');
    });

    test('are searched, sorted and paged', function () {
        Connections::using(static fn() => region_database());
        $config = region_config();
        $names = static fn(array $options) => array_column(Region::all($config, $options)->array(), 'name');

        expect($names(['search' => 'har']))
            ->toBe(['Quiet Harbour'])
            ->and($names(['search' => 'quiet har']))
            ->toBe(['Quiet Harbour'])
            ->and($names(['sort' => 'name desc']))
            ->toBe(['Welcome', 'Quiet Harbour', 'Garden'])
            ->and($names(['sort' => 'location']))
            ->toBe(['Quiet Harbour', 'Welcome', 'Garden'])
            ->and($names(['sort' => 'lastSeen desc']))
            ->toBe(['Welcome', 'Garden', 'Quiet Harbour'])
            ->and($names(['sort' => 'size desc']))
            ->toBe(['Garden', 'Welcome', 'Quiet Harbour'])
            ->and(fn() => $names(['sort' => '1; DROP TABLE regions']))
            ->toThrow(fn(\OpenSim\Exceptions\InvalidValue $e) => expect($e->reason)->toBe('sort-unknown'))
            ->and($names(['perPage' => 2, 'page' => 2]))
            ->toBe(['Welcome'])
            ->and(Region::all($config, ['perPage' => 2])->total())
            ->toBe(3)
            ->and(Region::all($config, ['perPage' => 2])->pages())
            ->toBe(2);
    });

    test('can be asked by their UUIDs, and the grid says which of some it knows', function () {
        Connections::using(static fn() => region_database());
        $config = region_config();
        $names = static fn(array $options) => array_column(Region::all($config, $options)->array(), 'name');

        expect(
            $names([
                'uuids' => [
                    'AAAAAAAA-0000-0000-0000-000000000002',
                    'aaaaaaaa-0000-0000-0000-000000000001',
                    'cccccccc-0000-0000-0000-000000000000',
                ],
            ]),
        )
            ->toBe(['Garden', 'Welcome'])
            ->and($names(['uuids' => []]))
            ->toBe([])
            ->and(Region::all($config, ['uuids' => ['aaaaaaaa-0000-0000-0000-000000000002'], 'perPage' => 5])->total())
            ->toBe(1)
            ->and(
                array_values(
                    array_diff(
                        Region::known($config, [
                            'aaaaaaaa-0000-0000-0000-000000000004',
                            'cccccccc-0000-0000-0000-000000000000',
                            'AAAAAAAA-0000-0000-0000-000000000001',
                        ]),
                        ['aaaaaaaa-0000-0000-0000-000000000001'],
                    ),
                ),
            )
            ->toBe(['aaaaaaaa-0000-0000-0000-000000000004'])
            ->and(Region::known($config, ['cccccccc-0000-0000-0000-000000000000']))
            ->toBe([]);
    });

    test('are found by their UUID or their name', function () {
        Connections::using(static fn() => region_database());
        $config = region_config();

        expect(Region::find($config, 'aaaaaaaa-0000-0000-0000-000000000002')->name())
            ->toBe('Garden')
            ->and(Region::find($config, 'Quiet Harbour')->uuid())
            ->toBe('aaaaaaaa-0000-0000-0000-000000000003')
            ->and(Region::find($config, 'Far Away')->isHyperlink())
            ->toBeTrue()
            ->and(Region::find($config, 'Nowhere'))
            ->toBeNull();
    });

    test('have their name in their file: neither renamed nor disabled without it', function () {
        Connections::using(static fn() => region_database());
        $welcome = Region::find(region_config(), 'Welcome');

        expect($welcome->channels())
            ->toBe(['database'])
            ->and(fn() => $welcome->disable())
            ->toThrow(ChannelUnavailable::class)
            ->and(fn() => $welcome->update(['name' => 'Home']))
            ->toThrow(fn(\OpenSim\Exceptions\InvalidValue $e) => expect($e->reason)->toBe('field-read-only'));
    });

    test('cannot be listed when the database cannot be reached', function () {
        Connections::using(static fn() => null);

        expect(fn() => Region::all(region_config()))
            ->toThrow(ChannelUnavailable::class)
            ->and(Region::find(region_config(), 'Welcome'))
            ->toBeNull();
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('region as the search engine knows it', function () {
    /** A search database of its own, and the grid's database beside it. */
    function region_search_databases(bool $shared): array
    {
        $grid = region_database();
        $search = $shared ? $grid : new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        $table = $shared ? 'regionsregister' : 'regions';
        $search->exec(
            "CREATE TABLE $table (regionname VARCHAR(255), regionUUID CHAR(36) PRIMARY KEY, regionhandle VARCHAR(255), url VARCHAR(255), owner VARCHAR(255), owneruuid CHAR(36), gatekeeperURL VARCHAR(255))",
        );
        $search->exec(
            "INSERT INTO $table VALUES ('Welcome', 'AAAAAAAA-0000-0000-0000-000000000001', '1', 'http://play.example.org:9000/', 'Ada Love', '11111111-1111-1111-1111-111111111111', 'http://play.example.org:8002/')",
        );

        return [$grid, $search];
    }

    test('is read in the database of the search, never in the one of the grid', function () {
        [$grid, $search] = region_search_databases(false);
        $asked = [];
        Connections::using(static function ($what) use ($grid, $search, &$asked): OSPDO {
            $asked[] = $what instanceof OpenSim\Data\Database ? $what->value : $what;

            return $what === OpenSim\Data\Database::Search ? $search : $grid;
        });

        $region = Region::inSearch(region_config(), 'aaaaaaaa-0000-0000-0000-000000000001');

        expect($region->sources())
            ->toBe(['search'])
            ->and([$region->name(), $region->uuid()])
            ->toBe(['Welcome', 'aaaaaaaa-0000-0000-0000-000000000001'])
            ->and($asked)
            ->toBe(['search'])
            ->and(Region::inSearch(region_config(), 'aaaaaaaa-0000-0000-0000-0000000000ff'))
            ->toBeNull();
    });

    test(
        'is in a table of its own when the search shares the database of Robust, whose regions are another table',
        function () {
            [$shared] = region_search_databases(true);

            expect(Region::searchTable($shared))->toBe('regionsregister');
            Connections::using(static fn(): OSPDO => $shared);

            expect(Region::inSearch(region_config(), 'aaaaaaaa-0000-0000-0000-000000000001')?->name())->toBe('Welcome');
            [, $apart] = region_search_databases(false);
            expect(Region::searchTable($apart))->toBe('regions');
        },
    );
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');
