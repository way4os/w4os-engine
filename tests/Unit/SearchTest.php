<?php

declare(strict_types=1);

/**
 * The search: the simulators that register, the snapshots they give, the events of a calendar, and the questions of the viewers
 * (XML-RPC) that read the result.
 */

use W4OS\Engine\Services\EventsParser;
use W4OS\Engine\Services\Failure;
use W4OS\Engine\Services\SearchParser;
use W4OS\Engine\Services\SearchQuery;
use W4OS\Engine\Services\SearchRegistry;
use W4OS\Engine\Services\XmlRpc;
use W4OS\Engine\Testing\ClockFake;
use PhpXmlRpc\Encoder;
use PhpXmlRpc\Request;

const SEARCH_REGION = '11111111-1111-4111-8111-111111111111';
const SEARCH_PARCEL = '22222222-2222-4222-8222-222222222222';
const SEARCH_SALE = '33333333-3333-4333-8333-333333333333';
const SEARCH_INFO = '44444444-4444-4444-8444-444444444444';
const SEARCH_GK = 'http://grid.example.org:8002';

/** The tables of the search, as much as the code reads and writes, in the database the tests use. */
function search_database(): W4OS\Engine\Data\OSPDO
{
    $pdo = test_pdo();
    foreach (
        [
            'CREATE TABLE hostsregister (host VARCHAR(255), port INT, register INT, nextcheck INT, checked INT, failcounter INT, gatekeeperURL VARCHAR(255), PRIMARY KEY (host, port))',
            'CREATE TABLE regionsregister (regionname VARCHAR(255), regionUUID CHAR(36) PRIMARY KEY, regionhandle VARCHAR(255), url VARCHAR(255), owner VARCHAR(255), owneruuid CHAR(36), gatekeeperURL VARCHAR(255))',
            'CREATE TABLE allparcels (regionUUID CHAR(36), parcelname VARCHAR(255), ownerUUID CHAR(36), groupUUID CHAR(36), landingpoint VARCHAR(255), parcelUUID CHAR(36) PRIMARY KEY, infoUUID CHAR(36), parcelarea INT, gatekeeperURL VARCHAR(255))',
            'CREATE TABLE parcels (parcelUUID CHAR(36), regionUUID CHAR(36), parcelname VARCHAR(255), landingpoint VARCHAR(255), description VARCHAR(255), searchcategory VARCHAR(50), build VARCHAR(5), script VARCHAR(5), public VARCHAR(5), dwell FLOAT, infouuid VARCHAR(36), mature VARCHAR(10), gatekeeperURL VARCHAR(255), imageUUID CHAR(36), PRIMARY KEY (regionUUID, parcelUUID))',
            'CREATE TABLE parcelsales (regionUUID CHAR(36), parcelname VARCHAR(255), parcelUUID CHAR(36), area INT, saleprice INT, landingpoint VARCHAR(255), infoUUID CHAR(36), dwell INT, parentestate INT, mature VARCHAR(10), gatekeeperURL VARCHAR(255), PRIMARY KEY (regionUUID, parcelUUID))',
            'CREATE TABLE popularplaces (parcelUUID CHAR(36) PRIMARY KEY, name VARCHAR(255), dwell FLOAT, infoUUID CHAR(36), has_picture INT, mature VARCHAR(10), gatekeeperURL VARCHAR(255))',
            'CREATE TABLE objects (objectuuid CHAR(36), parceluuid CHAR(36), location VARCHAR(255), name VARCHAR(255), description VARCHAR(255), regionuuid CHAR(36), gatekeeperURL VARCHAR(255), PRIMARY KEY (objectuuid, parceluuid))',
            'CREATE TABLE oshelpers_cache (cache_key VARCHAR(255) PRIMARY KEY, cache_value BLOB, cache_expires INT)',
            'CREATE TABLE classifieds (classifieduuid CHAR(36) PRIMARY KEY)',
            'CREATE TABLE events (owneruuid CHAR(36), name VARCHAR(255), eventid INTEGER PRIMARY KEY, creatoruuid CHAR(36), category INT, description TEXT, dateUTC INT, duration INT, covercharge INT, coveramount INT, simname VARCHAR(255), parcelUUID CHAR(36), globalPos VARCHAR(255), eventflags INT, gatekeeperURL VARCHAR(255))',
        ]
        as $sql
    ) {
        $pdo->exec($sql);
    }

    return $pdo;
}

/** The snapshot a simulator gives: one region with a parcel shown in search, a parcel for sale, a parcel hidden, and an object. */
function search_snapshot(string $expire = '3600'): string
{
    $parcel = static fn(
        string $uuid,
        string $name,
        array $attributes,
        string $extra = '',
    ): string => "<parcel category=\"{$attributes['category']}\" forsale=\"{$attributes['forsale']}\" showinsearch=\"{$attributes['showinsearch']}\" build=\"true\" scripts=\"false\" public=\"true\" salesprice=\"{$attributes['price']}\">" .
        "<name>$name</name><uuid>$uuid</uuid><infouuid>" .
        SEARCH_INFO .
        "</infouuid><location>128/128/25</location><description>About $name</description><area>1024</area><dwell>12.5</dwell>$extra" .
        '<owner><uuid>55555555-5555-4555-8555-555555555555</uuid></owner></parcel>';

    return '<?xml version="1.0"?><snapshot><gatekeeperURL>' .
        SEARCH_GK .
        '</gatekeeperURL><regiondata><expire>' .
        $expire .
        '</expire>' .
        '<region category="Mature"><info><uuid>' .
        SEARCH_REGION .
        '</uuid><name>Welcome</name><handle>1099511628032000</handle><url>http://sim.example.org:9000/</url></info>' .
        '<data><estate><id>101</id><name>Ada Love</name><uuid>66666666-6666-4666-8666-666666666666</uuid></estate>' .
        $parcel(
            SEARCH_PARCEL,
            'Rose garden',
            ['category' => 'Residential', 'forsale' => 'false', 'showinsearch' => 'true', 'price' => '0'],
            '<image>77777777-7777-4777-8777-777777777777</image><group><groupuuid>88888888-8888-4888-8888-888888888888</groupuuid></group>',
        ) .
        $parcel(SEARCH_SALE, 'Empty lot', [
            'category' => 'Other',
            'forsale' => 'true',
            'showinsearch' => 'true',
            'price' => '250',
        ]) .
        $parcel('99999999-9999-4999-8999-999999999999', 'Hidden', [
            'category' => 'Other',
            'forsale' => 'false',
            'showinsearch' => 'false',
            'price' => '0',
        ]) .
        '<object><uuid>aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa</uuid><title>Fountain</title><location>10/20/30</location><description>Water</description><parceluuid>' .
        SEARCH_PARCEL .
        '</parceluuid><regionuuid>' .
        SEARCH_REGION .
        '</regionuuid></object>' .
        '</data></region></regiondata></snapshot>';
}

/** A search over the fixture database, with the snapshot of one simulator read into it. */
function searched(): array
{
    $db = search_database();
    $clock = new ClockFake();
    $registry = new SearchRegistry($db, 'regionsregister', $clock);
    $registry->register('sim.example.org', 9000);
    (new SearchParser($registry, $db, 'regionsregister'))->scan('sim.example.org', 9000, search_snapshot());

    return [$db, $registry, $clock];
}

/** What an XML-RPC method gives to a request, decoded. */
function rpc_call(XmlRpc $rpc, string $method, array $request): array
{
    $encoder = new Encoder();
    $answer = $rpc->call((new Request($method, [$encoder->encode($request)]))->serialize());

    return [
        'status' => $answer->status,
        'body' => $answer->body(),
        'data' =>
            XmlRpc::parse(
                str_replace(
                    ['<params>', '</params>'],
                    ['<methodCall><methodName>x</methodName><params>', '</params></methodCall>'],
                    preg_replace('/^<\?xml[^>]*>/', '', $answer->body()) ?? '',
                ),
            )['params'][0] ?? null,
    ];
}

describe('XML-RPC', function () {
    test(
        'gives the struct of a request to a method and writes what it answers like the extension of PHP did',
        function () {
            $rpc = new XmlRpc(['echo' => static fn(array $request): array => XmlRpc::envelope(true, '', [$request])]);

            $answer = rpc_call($rpc, 'echo', ['text' => 'hello']);

            expect($answer['status'])
                ->toBe(200)
                ->and($answer['body'])
                ->toStartWith("<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<params>\n<param>\n")
                ->and($answer['data'])
                ->toBe(['success' => true, 'errorMessage' => '', 'data' => [['text' => 'hello']]]);
        },
    );

    test('tells what it cannot answer: a status with the failure, a fault for a method nobody knows', function () {
        $rpc = new XmlRpc(['no' => static fn(array $request): array => throw new Failure('Forbidden', 403)]);

        $failed = rpc_call($rpc, 'no', []);
        $unknown = rpc_call($rpc, 'nothing', []);
        $junk = $rpc->call('not xml');

        expect($failed['status'])
            ->toBe(403)
            ->and($failed['data'])
            ->toBe(['success' => false, 'errorMessage' => 'Forbidden'])
            ->and($unknown['status'])
            ->toBe(200)
            ->and($unknown['body'])
            ->toContain('<fault>')
            ->and($junk->status)
            ->toBe(400)
            ->and(XmlRpc::parse(''))
            ->toBeNull()
            ->and(XmlRpc::plain(true))
            ->toContain('<boolean>true</boolean>')
            ->and(XmlRpc::plain(false, 'a < b'))
            ->toContain('<error>a &lt; b</error>');
    });
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

describe('The registry of the search', function () {
    test('registers a simulator once, and registers it again at its next start', function () {
        $db = search_database();
        $clock = new ClockFake();
        $registry = new SearchRegistry($db, 'regionsregister', $clock);

        $registry->register('sim.example.org', 9000);
        $registry->schedule('sim.example.org', 9000, 5000, 3);
        $clock->now += 100;
        $registry->register('sim.example.org', 9000);

        expect($db->get_results('SELECT * FROM hostsregister', [], PDO::FETCH_ASSOC))->toBe([
            [
                'host' => 'sim.example.org',
                'port' => 9000,
                'register' => 1100,
                'nextcheck' => 0,
                'checked' => 0,
                'failcounter' => 0,
                'gatekeeperURL' => '',
            ],
        ]);
    });

    test('lists the hosts that are due, and when every host was read, lists them all again', function () {
        $db = search_database();
        $clock = new ClockFake();
        $registry = new SearchRegistry($db, 'regionsregister', $clock);
        $registry->register('a.example.org', 9000);
        $registry->register('b.example.org', 9000);

        expect($registry->due())->toHaveCount(2);
        $registry->schedule('a.example.org', 9000, (int) $clock->now + 600);
        $registry->schedule('b.example.org', 9000, (int) $clock->now - 1);
        expect($registry->due())
            ->toBe([['host' => 'b.example.org', 'port' => 9000]])
            ->and($registry->waiting())
            ->toBeTrue();
        $registry->schedule('b.example.org', 9000, (int) $clock->now + 600);
        // Everyone is read: the next pass starts again with the hosts that are due, whatever they were marked
        expect($registry->due())->toBe([])->and($registry->waiting())->toBeFalse();
        $clock->now += 700;
        expect($registry->due())->toHaveCount(2);
    });

    test('doubles the time between two looks at a host each time it does not answer', function () {
        expect([SearchRegistry::backoff(0), SearchRegistry::backoff(1), SearchRegistry::backoff(3)])->toBe([
            600,
            1200,
            4800,
        ]);
    });

    test('reads a simulator again soon after it registers, unless it failed', function () {
        $db = search_database();
        $clock = new ClockFake();
        $registry = new SearchRegistry($db, 'regionsregister', $clock);
        $registry->register('a.example.org', 9000);
        $registry->register('b.example.org', 9000);
        $registry->schedule('b.example.org', 9000, 7777, 2);

        $registry->again('a.example.org', 9000);
        $registry->again('b.example.org', 9000);

        expect($db->get_var('SELECT nextcheck FROM hostsregister WHERE host = ?', ['a.example.org']))
            ->toBe(1090)
            ->and($db->get_var('SELECT nextcheck FROM hostsregister WHERE host = ?', ['b.example.org']))
            ->toBe(7777);
    });
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

describe('The tasks of the search', function () {
    test('run alone whoever runs them: a lock of the database of the grid where it has one, as they are otherwise', function () {
        $sqlite = new W4OS\Engine\Data\OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);

        expect(W4OS\Engine\Services\SearchTasks::exclusively($sqlite, 'search-parse', static fn(): string => '2 simulator(s) read'))
            ->toBe('2 simulator(s) read')
            ->and(W4OS\Engine\Services\SearchTasks::lockName('search-parse', 'robust'))
            ->toStartWith('opensim-search-parse-')
            ->and(strlen(W4OS\Engine\Services\SearchTasks::lockName('events-parse', str_repeat('x', 200))))
            ->toBeLessThanOrEqual(64);
    });
});

describe('The parser of the search', function () {
    test(
        'reads the snapshot of a simulator into the tables: regions, parcels shown, land for sale, popular places, objects',
        function () {
            [$db, $registry] = searched();

            $region = $db->get_row('SELECT * FROM regionsregister', [], PDO::FETCH_ASSOC);
            $parcel = $db->get_row('SELECT * FROM parcels', [], PDO::FETCH_ASSOC);

            expect($region)
                ->toMatchArray([
                    'regionname' => 'Welcome',
                    'regionUUID' => SEARCH_REGION,
                    'owner' => 'Ada Love',
                    'owneruuid' => '66666666-6666-4666-8666-666666666666',
                    'gatekeeperURL' => SEARCH_GK,
                ])
                ->and($db->get_var('SELECT COUNT(*) FROM allparcels'))
                ->toBe(3)
                ->and($db->get_var('SELECT COUNT(*) FROM parcels'))
                ->toBe(2)
                ->and($parcel)
                ->toMatchArray([
                    'parcelname' => 'Rose garden',
                    'mature' => 'Mature',
                    'searchcategory' => 'Residential',
                    'imageUUID' => '77777777-7777-4777-8777-777777777777',
                    'gatekeeperURL' => SEARCH_GK,
                ])
                ->and($db->get_results('SELECT name, has_picture FROM popularplaces', [], PDO::FETCH_ASSOC))
                ->toBe([['name' => 'Rose garden', 'has_picture' => 1]])
                ->and($db->get_row('SELECT * FROM parcelsales', [], PDO::FETCH_ASSOC))
                ->toMatchArray(['parcelname' => 'Empty lot', 'saleprice' => 250, 'area' => 1024, 'parentestate' => 101])
                ->and($db->get_var('SELECT name FROM objects'))
                ->toBe('10/20/30')
                ->and($db->get_row('SELECT nextcheck, gatekeeperURL FROM hostsregister', [], PDO::FETCH_ASSOC))
                ->toBe(['nextcheck' => 1000 + 3600, 'gatekeeperURL' => SEARCH_GK]);
        },
    );

    test('replaces what it knew of a region by what the simulator says now', function () {
        [$db, $registry] = searched();
        $again = str_replace('Rose garden', 'Rose garden renamed', search_snapshot());

        (new SearchParser($registry, $db, 'regionsregister'))->scan(
            'sim.example.org',
            9000,
            str_replace(
                [SEARCH_SALE, 'forsale="true"'],
                ['33333333-3333-4333-8333-333333333334', 'forsale="false"'],
                $again,
            ),
        );

        expect($db->get_var("SELECT parcelname FROM parcels WHERE parcelUUID = '" . SEARCH_PARCEL . "'"))
            ->toBe('Rose garden renamed')
            ->and($db->get_var('SELECT COUNT(*) FROM regionsregister'))
            ->toBe(1)
            ->and($db->get_var('SELECT COUNT(*) FROM parcelsales'))
            ->toBe(0)
            ->and($db->get_var('SELECT COUNT(*) FROM popularplaces'))
            ->toBe(2);
    });

    test('ignores what is not a snapshot', function () {
        $db = search_database();
        $registry = new SearchRegistry($db, 'regionsregister', new ClockFake());
        $parser = new SearchParser($registry, $db, 'regionsregister');

        $parser->scan('sim.example.org', 9000, '<html>404</html>');
        $parser->scan('sim.example.org', 9000, 'not xml at all');
        $parser->scan('sim.example.org', 9000, '<snapshot><regiondata></regiondata></snapshot>');

        expect($db->get_var('SELECT COUNT(*) FROM regionsregister'))->toBe(0);
    });

    test(
        'reads the hosts that are due, schedules the next look from what the snapshot says, and waits longer for a host that fails',
        function () {
            $db = search_database();
            $clock = new ClockFake();
            $registry = new SearchRegistry($db, 'regionsregister', $clock);
            $registry->register('sim.example.org', 9000);
            $registry->register('down.example.org', 9000);
            $asked = [];
            $http = static function (string $url) use (&$asked): ?string {
                $asked[] = $url;

                return $url === 'http://sim.example.org:9000/?method=collector' ? search_snapshot('1800') : null;
            };
            $parser = new SearchParser($registry, $db, 'regionsregister', $http);

            $looked = $parser->parse();

            expect($looked)
                ->toBe(2)
                ->and($asked)
                ->toContain('http://down.example.org:9000/?method=collector')
                ->and(
                    $db->get_row(
                        "SELECT nextcheck, failcounter, checked FROM hostsregister WHERE host = 'sim.example.org'",
                        [],
                        PDO::FETCH_ASSOC,
                    ),
                )
                ->toBe(['nextcheck' => 1000 + 1800, 'failcounter' => 0, 'checked' => 1])
                ->and(
                    $db->get_row(
                        "SELECT nextcheck, failcounter FROM hostsregister WHERE host = 'down.example.org'",
                        [],
                        PDO::FETCH_ASSOC,
                    ),
                )
                ->toBe(['nextcheck' => 1000 + 1200, 'failcounter' => 1])
                ->and($db->get_var('SELECT COUNT(*) FROM regionsregister'))
                ->toBe(1);
        },
    );

    test('forgets a host that failed too many times, with what it told', function () {
        [$db, $registry] = searched();
        $registry->schedule('sim.example.org', 9000, 0, SearchParser::MAX_FAILURES);
        $parser = new SearchParser($registry, $db, 'regionsregister', static fn(string $url): ?string => null);

        $parser->check('sim.example.org', 9000);

        expect($db->get_var('SELECT COUNT(*) FROM hostsregister'))
            ->toBe(0)
            ->and($db->get_var('SELECT COUNT(*) FROM regionsregister'))
            ->toBe(0)
            ->and($db->get_var('SELECT COUNT(*) FROM parcels'))
            ->toBe(0)
            ->and($db->get_var('SELECT COUNT(*) FROM popularplaces'))
            ->toBe(0)
            ->and($db->get_var('SELECT COUNT(*) FROM objects'))
            ->toBe(0);
    });

    test('forgets a simulator that unregisters', function () {
        [$db, $registry] = searched();

        $registry->unregister('sim.example.org', 9000);

        expect($db->get_var('SELECT COUNT(*) FROM allparcels'))
            ->toBe(0)
            ->and($db->get_var('SELECT COUNT(*) FROM parcelsales'))
            ->toBe(0);
    });
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

describe('The events of the search', function () {
    /** A calendar with an event to come, one that is over, and one in a place that does not answer. */
    function calendar_events(ClockFake $clock): string
    {
        $at = static fn(int $seconds): string => gmdate('Y-m-d\TH:i:s\Z', (int) $clock->now + $seconds);

        return json_encode([
            [
                'title' => 'Concert &amp; party',
                'description' => '<p>Come <b>in</b></p>',
                'start' => $at(7200),
                'end' => $at(10800),
                'categories' => ['Music'],
                'hgurl' => 'grid.example.org:8002:Welcome/10/20/30',
            ],
            [
                'title' => 'Over',
                'description' => '',
                'start' => $at(-7200),
                'end' => $at(-3600),
                'categories' => [],
                'hgurl' => 'grid.example.org:8002:Welcome',
            ],
            [
                'title' => 'Lost',
                'description' => '',
                'start' => $at(3600),
                'end' => $at(3600),
                'categories' => ['whatever'],
                'hgurl' => 'gone.example.org:8002:Nowhere',
            ],
        ]);
    }

    test('are replaced by the ones of the calendar that are not over and whose region answers', function () {
        $db = search_database();
        $clock = new ClockFake();
        $clock->now = 1_700_000_000.0;
        $db->exec("INSERT INTO events (eventid, name, dateUTC) VALUES (99, 'Old', 1)");
        // A table of an older version gets the columns read now (its landing point), as the task does before it runs
        $added = W4OS\Engine\Services\SearchTables::upgrade($db);
        $places = [
            'online' => static fn(string $url): bool => !str_contains($url, 'gone'),
            'parts' => static fn(string $url): array => [
                'gatekeeper' => 'http://grid.example.org:8002',
                'pos' => str_contains($url, '/10/') ? '10/20/30' : '',
            ],
            'located' => static fn(string $url): array => ['x' => 256000, 'y' => 256000],
            'link' => static fn(string $url, int $format): string => "link:$format:$url",
        ];
        $parser = new EventsParser(
            $db,
            'https://calendar.example.org/events',
            static fn(string $url): ?string => $url === 'https://calendar.example.org/events/events.json'
                ? calendar_events($clock)
                : null,
            $places,
            clock: $clock,
        );

        $kept = $parser->parse();
        $event = $db->get_row('SELECT * FROM events', [], PDO::FETCH_ASSOC);

        expect($kept)
            ->toBe(1)
            ->and($db->get_var('SELECT COUNT(*) FROM events'))
            ->toBe(1)
            ->and($event)
            ->toMatchArray([
                'name' => 'Concert & party',
                'category' => 20,
                'duration' => 60,
                'dateUTC' => 1_700_007_200,
                'globalPos' => '256010,256020,30',
                'gatekeeperURL' => 'http://grid.example.org:8002',
                'simname' => 'link:16:grid.example.org:8002:Welcome/10/20/30',
                // The point in its region, as the parcels keep theirs
                'landingpoint' => '10/20/30',
            ])
            ->and($added)
            ->toContain('events.landingpoint', 'events.parcelName')
            ->and(W4OS\Engine\Services\SearchTables::upgrade($db))
            ->not->toContain('events.landingpoint')
            // Its description as the calendar gives it: the links of the viewers are added to its answer only (SearchQuery)
            ->and($event['description'])
            ->toBe('Come in');
    });

    test('of the same name at the same place, back to back (an hour apart at most), are one longer event, as the 2do board has them', function () {
        $db = search_database();
        W4OS\Engine\Services\SearchTables::upgrade($db);
        $clock = new ClockFake();
        $clock->now = 1_700_000_000.0;
        $at = static fn(int $hours): string => gmdate('Y-m-d\TH:i:s\Z', (int) $clock->now + $hours * 3600);
        $event = static fn(string $title, int $start, int $end, string $place = 'grid.example.org:8002:Owls'): array => ['title' => $title, 'description' => '', 'start' => $at($start), 'end' => $at($end), 'categories' => ['Music'], 'hgurl' => $place];
        $calendar = json_encode([
            // Two slots of one show, another show between them in the calendar
            $event('DJ LaRissa＠The Night Owls', 1, 2),
            $event('DJ Isadora - EARLY MORNING BREAKFAST SHOW', 1, 2, 'grid.example.org:8002:Cafe'),
            $event('DJ LaRissa＠The Night Owls', 2, 3),
            $event('DJ Isadora - EARLY MORNING BREAKFAST SHOW', 2, 3, 'grid.example.org:8002:Cafe'),
            // An hour apart at most: still one
            $event('DJ Isadora - EARLY MORNING BREAKFAST SHOW', 4, 5, 'grid.example.org:8002:Cafe'),
            // The same name elsewhere, or later: another event
            $event('DJ LaRissa＠The Night Owls', 3, 4, 'grid.example.org:8002:Elsewhere'),
            $event('DJ LaRissa＠The Night Owls', 6, 7),
        ]);
        $places = [
            'online' => static fn(string $url): bool => true,
            'parts' => static fn(string $url): array => ['gatekeeper' => 'http://grid.example.org:8002', 'pos' => ''],
            'located' => static fn(string $url): array => ['x' => 256000, 'y' => 256000],
            'link' => static fn(string $url, int $format): string => "link:$format:$url",
        ];
        $parser = new EventsParser($db, 'https://calendar.example.org/events', static fn(string $url): ?string => $calendar, $places, clock: $clock);

        $kept = $parser->parse();
        $rows = array_map(static fn(array $r): array => [$r['name'], (int) $r['dateUTC'] - 1_700_000_000, (int) $r['duration']], $db->get_results('SELECT name, dateUTC, duration FROM events ORDER BY dateUTC, name', [], PDO::FETCH_ASSOC));

        expect($kept)->toBe(4)
            // Their names as the calendar gives them, UTF-8
            ->and($rows)->toBe([
                ['DJ Isadora - EARLY MORNING BREAKFAST SHOW', 3600, 240],
                ['DJ LaRissa＠The Night Owls', 3600, 120],
                ['DJ LaRissa＠The Night Owls', 10800, 60],
                ['DJ LaRissa＠The Night Owls', 21600, 60],
            ]);
    });

    test('keep their text as UTF-8, from the calendar to the answer of the viewers: an emoji, a full-width @, accents', function () {
        $db = search_database();
        W4OS\Engine\Services\SearchTables::upgrade($db);
        $clock = new ClockFake();
        $clock->now = 1_700_000_000.0;
        $title = 'Soirée 🎉 DJ LaRissa＠The Night Owls';
        $calendar = json_encode([['title' => $title, 'description' => 'Café 🌙 & dance', 'start' => gmdate('Y-m-d\TH:i:s\Z', 1_700_003_600), 'end' => gmdate('Y-m-d\TH:i:s\Z', 1_700_007_200), 'categories' => ['Music'], 'hgurl' => 'grid.example.org:8002:Owls']]);
        $places = [
            'online' => static fn(string $url): bool => true,
            'parts' => static fn(string $url): array => ['gatekeeper' => 'http://grid.example.org:8002', 'pos' => ''],
            'located' => static fn(string $url): array => ['x' => 256000, 'y' => 256000],
            'link' => static fn(string $url, int $format): string => 'grid.example.org:8002 Owls',
        ];
        (new EventsParser($db, 'https://calendar.example.org/events', static fn(string $url): ?string => $calendar, $places, clock: $clock))->parse();
        $id = (string) $db->get_var('SELECT eventid FROM events');
        $rpc = new XmlRpc((new SearchQuery($db, 'regionsregister', gk: SEARCH_GK, clock: $clock))->methods());
        $info = rpc_call($rpc, 'event_info_query', ['eventID' => $id])['data']['data'][0];

        expect($db->get_var('SELECT name FROM events'))->toBe($title)
            ->and($info['name'])->toBe($title)
            ->and($info['description'])->toEndWith('Café 🌙 & dance');
    });

    test('are left as they are when the calendar does not answer', function () {
        $db = search_database();
        $db->exec("INSERT INTO events (eventid, name, dateUTC) VALUES (99, 'Kept', 1)");
        $parser = new EventsParser($db, 'https://calendar.example.org/events', static fn(string $url): ?string => null);

        expect($parser->parse())
            ->toBe(-1)
            ->and($db->get_var('SELECT name FROM events'))
            ->toBe('Kept');
    });

    test(
        'have the category of the names a calendar gives: miscellaneous for a name nobody knows, none for no name',
        function () {
            expect([
                EventsParser::category(null),
                EventsParser::category([]),
                EventsParser::category(['Sports']),
                EventsParser::category(['unknown', 'LECTURE']),
                EventsParser::category(['unknown']),
                EventsParser::category([22]),
            ])->toBe([0, 0, 19, 27, 29, 22]);
        },
    );
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

describe('The questions of the search', function () {
    test('find the places by their words, rated and ordered, for the grid that asks', function () {
        [$db] = searched();
        $query = new SearchQuery($db, 'regionsregister');
        $rpc = new XmlRpc($query->methods());

        $found = rpc_call($rpc, 'dir_places_query', [
            'text' => 'rose',
            'flags' => (string) (1 << 25),
            'query_start' => '0',
        ])['data'];
        $none = rpc_call($rpc, 'dir_places_query', ['text' => 'rose', 'flags' => (string) (1 << 24)])['data'];
        $other = rpc_call(
            new XmlRpc((new SearchQuery($db, 'regionsregister', gk: 'other.example.org:8002'))->methods()),
            'dir_places_query',
            ['text' => 'rose'],
        )['data'];
        $invalid = rpc_call($rpc, 'dir_places_query', ['text' => ' '])['data'];

        expect($found['success'])
            ->toBeTrue()
            ->and($found['data'][0])
            ->toMatchArray([
                'parcel_id' => SEARCH_INFO,
                'name' => 'Rose garden',
                'for_sale' => 'False',
                'auction' => 'False',
                'regionname' => 'Welcome',
            ])
            // Nothing found is not an error: the viewer says it in its list, once, where an error is an alert for each request (the
            // viewer asks a search several times, and shows the answers of its last one only)
            ->and($none)
            ->toMatchArray(['success' => true, 'data' => []])
            // The places of another grid are not ours: none found
            ->and($other['data'] ?? null)
            ->toBe([])
            ->and($invalid)
            ->toBe(['success' => false, 'errorMessage' => 'Invalid search terms'])
            ->and(
                rpc_call(
                    new XmlRpc((new SearchQuery($db, 'regionsregister', gk: 'grid.example.org:8002'))->methods()),
                    'dir_places_query',
                    ['text' => 'rose'],
                )['data']['success'],
            )
            ->toBeTrue();
    });

    test('list the popular places, without the land for sale and, when asked, without the other grids', function () {
        [$db] = searched();
        $rpc = new XmlRpc((new SearchQuery($db, 'regionsregister'))->methods());

        $all = rpc_call($rpc, 'dir_popular_query', ['flags' => (string) (1 << 12), 'text' => 'rose'])['data'];
        $nothing = rpc_call($rpc, 'dir_popular_query', ['text' => 'lot'])['data'];
        $local = rpc_call($rpc, 'dir_popular_query', [
            'include_hypergrid' => 'false',
            'gatekeeper_url' => 'other.example.org:8002',
        ])['data'];

        expect($all['data'])
            ->toHaveCount(1)
            ->and($all['data'][0])
            ->toMatchArray([
                'parcel_id' => SEARCH_INFO,
                'name' => 'Rose garden',
                'regionname' => 'Welcome',
                'regionUUID' => SEARCH_REGION,
                'imageUUID' => '77777777-7777-4777-8777-777777777777',
            ])
            ->and($nothing['data'] ?? [])
            ->toBe([])
            ->and($local['data'] ?? [])
            ->toBe([]);
    });

    test('list the land for sale, by price per meter, with the limits of the viewer', function () {
        [$db] = searched();
        $rpc = new XmlRpc((new SearchQuery($db, 'regionsregister'))->methods());

        $sales = rpc_call($rpc, 'dir_land_query', [
            'flags' => '0',
            'type' => '4294967295',
            'price' => '0',
            'area' => '0',
        ])['data'];
        $cheap = rpc_call($rpc, 'dir_land_query', [
            'flags' => (string) (1 << 20),
            'type' => '4294967295',
            'price' => '100',
            'area' => '0',
        ])['data'];
        $auctions = rpc_call($rpc, 'dir_land_query', ['flags' => '0', 'type' => '2', 'price' => '0', 'area' => '0'])[
            'data'
        ];

        expect($sales['data'][0])
            ->toBe([
                'parcel_id' => SEARCH_INFO,
                'name' => 'Empty lot',
                'auction' => 'false',
                'for_sale' => 'true',
                'sale_price' => 250,
                'landing_point' => '128/128/25',
                'region_UUID' => SEARCH_REGION,
                'area' => 1024,
            ])
            ->and($cheap['data'] ?? [])
            ->toBe([])
            // No auction is none found, not an error
            ->and($auctions)
            ->toMatchArray(['success' => true, 'data' => []]);
    });

    test('list the events of a day, or the ones to come, by category and words, and tell one in full', function () {
        $db = search_database();
        $clock = new ClockFake();
        $clock->now = 1_700_000_000.0;
        $db->exec(
            "INSERT INTO events VALUES ('o', 'Jazz night', 1, 'c', 20, 'Live jazz', " .
                (1_700_000_000 + 3600) .
                ", 60, 0, 0, 'grid.example.org:8002 Welcome', 'p', '128,128,25', 0, '" .
                SEARCH_GK .
                "'), ('o', 'Old', 2, 'c', 19, 'Gone', 1000, 60, 0, 0, 'Somewhere', 'p', '1,1,1', 0, ''), " .
                "('o', 'Far night', 3, 'c', 20, 'Far away', " . (1_700_000_000 + 3600) .
                ", 60, 0, 0, 'far.example.org:8002 Lonely Lands', 'p', '2583726,2579592,24', 0, 'http://far.example.org:8002')",
        );
        $rpc = new XmlRpc((new SearchQuery($db, 'regionsregister', gk: SEARCH_GK, clock: $clock))->methods());

        $coming = rpc_call($rpc, 'dir_events_query', ['text' => 'u|0|jazz', 'flags' => '0'])['data'];
        $category = rpc_call($rpc, 'dir_events_query', ['text' => 'u|19|', 'flags' => '0'])['data'];
        $invalid = rpc_call($rpc, 'dir_events_query', ['text' => '%%%'])['data'];
        $info = rpc_call($rpc, 'event_info_query', ['eventID' => '1'])['data']['data'][0];
        $far = rpc_call($rpc, 'event_info_query', ['eventID' => '3'])['data']['data'][0];

        expect($coming['data'])
            ->toHaveCount(1)
            ->and($coming['data'][0])
            ->toMatchArray([
                'owner_id' => 'o',
                'name' => 'Jazz night',
                'event_id' => 1,
                'unix_time' => 1_700_003_600,
                'landing_point' => '128,128,25',
            ])
            ->and($category['data'] ?? [])
            ->toBe([])
            ->and($invalid)
            ->toBe(['success' => false, 'errorMessage' => 'Invalid search terms'])
            ->and($info)
            ->toMatchArray([
                'name' => 'Jazz night',
                'category' => 'Live Music',
                'duration' => 60,
                // Of the grid asking: its region by its name
                'simname' => 'Welcome',
            ])
            // Of another grid: host:port:Region, as a viewer names it (the place of the event, its link in the panel)
            ->and([$far['simname'], $far['globalposition']])
            ->toBe([(new W4OS\Engine\Hypergrid\Place('far.example.org:8002/Lonely Lands'))->tpLink(W4OS\Engine\Hypergrid\Destination::LSL), '2583726,2579592,24']);
    });

    test('tell an event to a viewer with the teleport links of its place before its description, once, never stored', function () {
        $db = search_database();
        $links = (string) (new W4OS\Engine\Hypergrid\Place('far.example.org:8002 Lonely Lands/128/128/24'))->tpLink(W4OS\Engine\Hypergrid\Destination::APPTP + W4OS\Engine\Hypergrid\Destination::HOP);
        $db->exec("INSERT INTO events VALUES ('o', 'Far night', 3, 'c', 20, 'Far away', 1700003600, 60, 0, 0, 'far.example.org:8002 Lonely Lands/128/128/24', 'p', '1,1,24', 0, 'http://far.example.org:8002'), " .
            // A row of before, its links stored by the parser of then: never twice
            "('o', 'Old night', 4, 'c', 20, " . $db->quote("$links\n\nStill here") . ", 1700003600, 60, 0, 0, 'far.example.org:8002 Lonely Lands/128/128/24', 'p', '1,1,24', 0, 'http://far.example.org:8002')");
        $rpc = new XmlRpc((new SearchQuery($db, 'regionsregister', gk: SEARCH_GK))->methods());

        $info = rpc_call($rpc, 'event_info_query', ['eventID' => '3'])['data']['data'][0];
        $old = rpc_call($rpc, 'event_info_query', ['eventID' => '4'])['data']['data'][0];

        expect($links)->toContain('hop://', 'secondlife:///app/teleport/')
            ->and($info['description'])->toBe("$links\n\nFar away")
            ->and($old['description'])->toBe("$links\n\nStill here")
            ->and(SearchQuery::withoutViewerLinks("$links\n\nStill here"))->toBe('Still here')
            // Only the block the parser wrote, at the start: never an address in the text
            ->and(SearchQuery::withoutViewerLinks("Come\nhop://x:8002/Y\n\nhere"))->toBe("Come\nhop://x:8002/Y\n\nhere");
    });

    test('list the classifieds of the profiles, and tell one in full, when the grid has them', function () {
        $profiles = test_pdo();
        $profiles->exec(
            'CREATE TABLE classifieds (classifieduuid CHAR(36), creatoruuid CHAR(36), creationdate INT, expirationdate INT, category VARCHAR(20), name VARCHAR(255), description TEXT, parceluuid CHAR(36), parentestate INT, snapshotuuid CHAR(36), simname VARCHAR(255), posglobal VARCHAR(255), parcelname VARCHAR(255), classifiedflags INT, priceforlisting INT)',
        );
        $profiles->exec(
            // One to come, one expired: only the first is listed
            "INSERT INTO classifieds VALUES ('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'creator', 1, 4102444800, '3', 'Shop here', 'Best shop', 'p', 1, 's', 'Welcome', '1,1,1', 'Mall', 4, 50), " .
                "('dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'creator', 1, 2, '3', 'Shop gone', 'Old shop', 'p', 1, 's', 'Welcome', '1,1,1', 'Mall', 4, 60)",
        );
        $rpc = new XmlRpc((new SearchQuery(search_database(), 'regionsregister', profiles: $profiles))->methods());

        $list = rpc_call($rpc, 'dir_classified_query', ['text' => 'shop', 'flags' => '4', 'category' => '3'])['data'];
        $mature = rpc_call($rpc, 'dir_classified_query', ['text' => 'shop', 'flags' => '8', 'category' => '0'])['data'];
        $info = rpc_call($rpc, 'classifieds_info_query', ['classifiedID' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'])[
            'data'
        ];
        $without = new XmlRpc((new SearchQuery(search_database(), 'regionsregister'))->methods());

        expect($list['data'][0])
            ->toBe([
                'classifiedid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                'name' => 'Shop here',
                'classifiedflags' => 4,
                'creation_date' => 1,
                'expiration_date' => 4102444800,
                'priceforlisting' => 50,
            ])
            ->and($mature['data'] ?? [])
            ->toBe([])
            ->and($info['data'][0])
            ->toMatchArray(['name' => 'Shop here', 'parcelname' => 'Mall', 'priceforlisting' => 50])
            ->and($without->call(rpc_xml('dir_classified_query', ['text' => 'x']))->body())
            ->toContain('<error>');
    });

    test(
        'publish a profile in the search for the simulator that holds the secure session of the avatar, and for no one else',
        function () {
            $accounts = test_pdo();
            $accounts->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36), UserFlags INT)');
            $accounts->exec('CREATE TABLE Presence (UserID CHAR(36), SecureSessionID CHAR(36))');
            $avatar = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
            $session = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
            $accounts->exec("INSERT INTO UserAccounts VALUES ('$avatar', 6)");
            $accounts->exec("INSERT INTO Presence VALUES ('$avatar', '$session')");
            $rpc = new XmlRpc(
                (new SearchQuery(
                    search_database(),
                    'regionsregister',
                    accounts: $accounts,
                    presence: $accounts,
                ))->methods(),
            );

            $shown = rpc_call($rpc, 'avatar_publish_update', [
                'avatar_id' => $avatar,
                'secure_session_id' => $session,
                'allow_publish' => 'true',
            ]);
            $flags = $accounts->get_var('SELECT UserFlags FROM UserAccounts');
            $hidden = rpc_call($rpc, 'avatar_publish_update', [
                'avatar_id' => $avatar,
                'secure_session_id' => $session,
                'allow_publish' => 'false',
            ]);
            $forged = rpc_call($rpc, 'avatar_publish_update', [
                'avatar_id' => $avatar,
                'secure_session_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
                'allow_publish' => 'true',
            ]);
            $invalid = rpc_call($rpc, 'avatar_publish_update', ['avatar_id' => 'nobody']);

            expect($shown['data']['success'])
                ->toBeTrue()
                ->and($flags)
                ->toBe(7)
                ->and($accounts->get_var('SELECT UserFlags FROM UserAccounts'))
                ->toBe(6)
                ->and($hidden['data']['success'])
                ->toBeTrue()
                ->and($forged['status'])
                ->toBe(403)
                ->and($invalid['status'])
                ->toBe(400);
        },
    );
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

/** The XML of a request to a method. */
function rpc_xml(string $method, array $request): string
{
    return (new Request($method, [(new Encoder())->encode($request)]))->serialize();
}

describe('The guide of a grid that has no list', function () {
    /** The places of two grids in a search shared by both. */
    function guide_database(): W4OS\Engine\Data\OSPDO
    {
        $db = search_database();
        $db->exec(
            "INSERT INTO regionsregister VALUES ('Welcome', 'r1', '1', 'http://sim.example.org:9000/', 'Ada', 'o', 'http://grid.example.org:8002'), " .
                "('Far', 'r2', '2', 'http://other.example.org:9000/', 'Bob', 'o', 'http://other.example.org:8002')",
        );
        $parcel = static fn(
            string $uuid,
            string $name,
            string $region,
            string $point,
            int $dwell,
            string $gk,
        ): string => "('$uuid', '$region', '$name', '$point', '', '', 'true', 'true', 'true', $dwell, 'info', 'PG', '$gk', NULL)";
        $db->exec(
            'INSERT INTO parcels VALUES ' .
                implode(', ', [
                    $parcel('p1', 'Rose garden', 'r1', '128/128/25', 30, 'http://grid.example.org:8002'),
                    $parcel('p2', 'Library', 'r1', '64,64,22', 5, 'http://grid.example.org:8002'),
                    $parcel('p3', 'Market', 'r1', '10/10/30', 10, 'http://grid.example.org:8002'),
                    $parcel('p4', 'Elsewhere', 'r2', '', 50, 'http://other.example.org:8002'),
                ]),
        );
        $db->exec(
            "INSERT INTO popularplaces VALUES ('p1', 'Rose garden', 30, 'info', 0, 'PG', 'http://grid.example.org:8002'), " .
                "('p3', 'Market', 10, 'info', 0, 'PG', 'http://grid.example.org:8002'), " .
                "('p4', 'Elsewhere', 50, 'info', 0, 'PG', 'http://other.example.org:8002')",
        );

        return $db;
    }

    test(
        'lists the popular places and the places shown in search, the first ten of each, of the grid asked',
        function () {
            $db = guide_database();

            $ours = (new W4OS\Engine\Services\DefaultDestinations(
                $db,
                'regionsregister',
                'grid.example.org:8002',
                'http://grid.example.org:8002',
            ))->lists();
            $shared = (new W4OS\Engine\Services\DefaultDestinations(
                $db,
                'regionsregister',
                'grid.example.org:8002',
            ))->lists();

            expect($ours)
                ->toBe([
                    'Popular places' => [
                        ['name' => 'Rose garden', 'url' => 'grid.example.org:8002/Welcome/128/128/25'],
                        ['name' => 'Market', 'url' => 'grid.example.org:8002/Welcome/10/10/30'],
                    ],
                    'Places in the search' => [
                        ['name' => 'Library', 'url' => 'grid.example.org:8002/Welcome/64/64/22'],
                    ],
                ])
                ->and($shared['Popular places'][0])
                ->toBe(['name' => 'Elsewhere', 'url' => 'other.example.org:8002/Far'])
                ->and(count($shared['Popular places']))
                ->toBe(3);
        },
    );

    test('gives the popular places for their cards: the most visited first, of every grid of the search, with their picture', function () {
        $db = guide_database();
        $db->exec("UPDATE parcels SET imageUUID = 'aaaaaaaa-0000-4000-8000-000000000001' WHERE parcelUUID = 'p1'");

        $popular = (new W4OS\Engine\Services\DefaultDestinations($db, 'regionsregister', 'grid.example.org:8002'))->popular(2);

        expect($popular)->toBe([
            // With what its model is made of (Grid::popularModels())
            ['name' => 'Elsewhere', 'url' => 'other.example.org:8002/Far', 'picture' => null, 'dwell' => 50, 'parcel' => 'p4', 'region' => 'Far', 'landing' => '', 'gatekeeper' => 'http://other.example.org:8002'],
            ['name' => 'Rose garden', 'url' => 'grid.example.org:8002/Welcome/128/128/25', 'picture' => 'aaaaaaaa-0000-4000-8000-000000000001', 'dwell' => 30, 'parcel' => 'p1', 'region' => 'Welcome', 'landing' => '128/128/25', 'gatekeeper' => 'http://grid.example.org:8002'],
        ]);
    });

    test('lists a place not visited yet after the others, as the search of the viewers and w4os-wp do', function () {
        $db = guide_database();
        $db->exec("INSERT INTO popularplaces VALUES ('p2', 'Library', 0, 'info', 0, 'PG', 'http://grid.example.org:8002')");

        $names = array_column((new W4OS\Engine\Services\DefaultDestinations($db, 'regionsregister', 'grid.example.org:8002', 'http://grid.example.org:8002'))->popular(10), 'dwell', 'name');

        expect($names)->toBe(['Rose garden' => 30, 'Market' => 10, 'Library' => 0]);
    });

    test('leave out the places of the other grids and the land for sale unless asked, as w4os-wp 2.x does by default', function () {
        $db = guide_database();
        // The market is for sale
        $db->exec("INSERT INTO parcelsales VALUES ('r1', 'Market', 'p3', 512, 100, '10/10/30', 'info', 10, 1, 'PG', 'http://grid.example.org:8002')");
        $names = static fn(W4OS\Engine\Services\DefaultDestinations $guide, ?bool $sales = null): array => array_column($sales === null ? $guide->popular(10) : $guide->popular(10, $sales), 'name');

        expect($names(new W4OS\Engine\Services\DefaultDestinations($db, 'regionsregister', 'grid.example.org:8002')))
            ->toBe(['Elsewhere', 'Rose garden'])
            ->and($names(new W4OS\Engine\Services\DefaultDestinations($db, 'regionsregister', 'grid.example.org:8002'), true))
            ->toBe(['Elsewhere', 'Rose garden', 'Market'])
            ->and($names(new W4OS\Engine\Services\DefaultDestinations($db, 'regionsregister', 'grid.example.org:8002', 'http://grid.example.org:8002/')))
            ->toBe(['Rose garden']);

        $machine = new W4OS\Engine\Testing\Machine();
        W4OS\Engine\Data\Connections::using(static fn() => $db);
        $machine->write($machine->grid . '/Robust.HG.ini', (string) file_get_contents($machine->grid . '/Robust.HG.ini') . "\n[GridInfoService]\n    login = \"http://grid.example.org:8002/\"\n");
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'demo');
        $ours = array_column($grid->popularPlaces(10), 'name');
        $all = array_column($grid->popularPlaces(10, hypergrid: true, landSales: true), 'name');
        W4OS\Engine\Data\Connections::using(null);

        expect([$ours, $all])->toBe([['Rose garden'], ['Elsewhere', 'Rose garden', 'Market']]);
    });

    test('of a grid: the popular places with the label of their address, as a card shows it', function () {
        $machine = new W4OS\Engine\Testing\Machine();
        $db = guide_database();
        W4OS\Engine\Data\Connections::using(static fn() => $db);
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'demo');

        $popular = $grid->popularPlaces(3, hypergrid: true);
        W4OS\Engine\Data\Connections::using(null);

        // The grid of the search is another one than demo: its places are hypergrid, titled by their region
        expect(array_map(static fn(array $place): array => [$place['title'], $place['label'], $place['gridName']], $popular))->toBe([
            ['Far', 'other.example.org:8002', null],
            ['Welcome', 'grid.example.org:8002', null],
            ['Welcome', 'grid.example.org:8002', null],
        ])
            ->and(array_column($popular, 'name'))
            ->toBe(['Elsewhere', 'Rose garden', 'Market']);
    });

    test('keep ten of each section', function () {
        $db = search_database();
        $db->exec("INSERT INTO regionsregister VALUES ('Welcome', 'r1', '1', 'http://sim:9000/', 'Ada', 'o', '')");
        for ($i = 1; $i <= 25; $i++) {
            $db->exec(
                "INSERT INTO parcels VALUES ('p$i', 'r1', 'Place " .
                    str_pad((string) $i, 2, '0', STR_PAD_LEFT) .
                    "', '', '', '', 'true', 'true', 'true', $i, 'info', 'PG', '', NULL)",
            );
            $i <= 12 && $db->exec("INSERT INTO popularplaces VALUES ('p$i', 'Place $i', $i, 'info', 0, 'PG', '')");
        }

        $lists = (new W4OS\Engine\Services\DefaultDestinations($db, 'regionsregister', 'grid.example.org:8002'))->lists();

        expect(count($lists['Popular places']))
            ->toBe(10)
            ->and(count($lists['Places in the search']))
            ->toBe(10)
            ->and($lists['Popular places'][0]['name'])
            ->toBe('Place 12')
            ->and($lists['Places in the search'][0]['name'])
            ->toBe('Place 01');
    });

    test('is nothing when the search knows nothing, or has no tables', function () {
        $empty = (new W4OS\Engine\Services\DefaultDestinations(
            search_database(),
            'regionsregister',
            'grid.example.org:8002',
        ))->lists();
        $none = (new W4OS\Engine\Services\DefaultDestinations(
            test_pdo(),
            'regionsregister',
            'grid.example.org:8002',
        ))->lists();

        expect($empty)->toBe([])->and($none)->toBe([]);
    });
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

describe('The duration of an event', function () {
    test('is a duration in minutes, shown in minutes under an hour and in hours from 60, by the units of the catalog', function () {
        $fields = array_column(W4OS\Engine\Models\Event::itemForm()->fields, null, 'name');

        expect($fields['duration']['type'] ?? null)->toBe('duration')
            ->and(array_intersect_key(W4OS\Engine\I18n\Catalog::MESSAGES, array_flip(['unit_minutes', 'unit_hours', 'unit_hours_minutes'])))
            ->toBe(['unit_minutes' => '%s min', 'unit_hours' => '%s h', 'unit_hours_minutes' => '%1$s h %2$s']);
    });
});

describe('The page of a result of the search', function () {
    test('puts its teleport link on the field that names its place: never a second line of its name', function () {
        $teleport = static fn(string $model): ?string => array_values(array_filter($model::itemForm()->fields, static fn(array $field): bool => !empty($field['teleport'])))[0]['name'] ?? null;

        expect(array_map($teleport, [W4OS\Engine\Models\Event::class, W4OS\Engine\Models\Classified::class, W4OS\Engine\Models\SearchPlace::class, W4OS\Engine\Models\LandSale::class]))
            ->toBe(['location', 'location', 'region', 'region']);
    });
});

describe('The details of a result of the search', function () {
    test('show its picture, an event the one of its place, and its description as a text of its own, without its label', function () {
        $models = [W4OS\Engine\Models\Event::class, W4OS\Engine\Models\Classified::class, W4OS\Engine\Models\SearchPlace::class, W4OS\Engine\Models\LandSale::class];
        $description = static fn(string $model): array => array_values(array_filter($model::itemForm()->fields, static fn(array $f): bool => $f['name'] === 'description'))[0] ?? [];

        expect(array_map(static fn(string $model): bool => ($description($model)['bare'] ?? false) === true, $models))->toBe([true, true, true, true])
            ->and(W4OS\Engine\Models\Event::listing()->document()['columns'][0]['picture'] ?? null)->toBe('picture');
    });
});

describe('The category of a result', function () {
    test('is called Category in its details as in its filter, an event too (the viewer says Type)', function () {
        $label = static fn(string $model, string $name): ?string => array_column($model::itemForm()->fields, 'label', 'name')[$name] ?? null;

        expect([$label(W4OS\Engine\Models\Event::class, 'category'), $label(W4OS\Engine\Models\Classified::class, 'category')])
            ->toBe(['web_search_category', 'web_search_category']);
    });
});

describe('The text of the search', function () {
    test('is UTF-8 everywhere: utf8mb4 on the connection, the tables made so, the ones of before converted with their keys', function () {
        $statements = implode("\n", (new ReflectionMethod(W4OS\Engine\Services\SearchTables::class, 'statements'))->invoke(null, 'regionsregister'));

        expect(W4OS\Engine\Data\OSPDO::dsn('mysql', 'db.example.org', 'search'))->toBe('mysql:host=db.example.org;dbname=search;charset=utf8mb4')
            ->and(W4OS\Engine\Data\OSPDO::dsn('sqlite', '', '/tmp/x.db'))->toBe('sqlite:/tmp/x.db')
            ->and($statements)->toContain('CHARSET=utf8mb4')->not->toContain('CHARSET=utf8 ')->not->toContain('MyISAM')
            // A table of before (latin1 or utf8, MyISAM): InnoDB for the length of its keys in utf8mb4, its text converted
            ->and(W4OS\Engine\Services\SearchTables::conversion('parcels'))->toBe('ALTER TABLE `parcels` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    });
});

