<?php

declare(strict_types=1);

/**
 * The pictures of the hypergrid destinations of a guide: asked to their grid by the scheduler (its gatekeeper, the land of the
 * simulator of the region, its asset service), kept, and read by the guide without asking anything.
 */

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Cache;
use W4OS\Engine\Data\FileCacheStore;
use W4OS\Engine\Hypergrid\Gatekeeper;
use W4OS\Engine\Services\DestinationPictures;
use W4OS\Engine\Services\RemotePictures;
use W4OS\Engine\Support\FileCache;
use W4OS\Engine\Testing\Machine;
use W4OS\Engine\Testing\ClockFake;

const REMOTE_REGION = 'cccccccc-0000-4000-8000-000000000001';
const REMOTE_BEACH = 'dddddddd-0000-4000-8000-000000000001';
const REMOTE_HILL = 'dddddddd-0000-4000-8000-000000000002';

/** The answer of a gatekeeper to an XML-RPC call. */
function remote_rpc(array $value): string
{
    return (new PhpXmlRpc\Response((new PhpXmlRpc\Encoder())->encode($value)))->serialize();
}

/** The snapshot of the simulator of the region Far: two parcels shown in search, with their pictures. */
function remote_snapshot(): string
{
    $parcel = static fn(string $name, string $at, string $image): string => "<parcel showinsearch=\"true\"><name>$name</name><location>$at</location><image>$image</image></parcel>";

    return '<?xml version="1.0"?><snapshot><gatekeeperURL>http://far.example.net:8002</gatekeeperURL><regiondata><expire>3600</expire>' .
        '<region category="PG"><info><uuid>' . REMOTE_REGION . '</uuid><name>Far</name></info><data>' .
        $parcel('Beach', '10/20/30', REMOTE_BEACH) . $parcel('Hill', '128/128/25', REMOTE_HILL) .
        '</data></region><region><info><uuid>cccccccc-0000-4000-8000-000000000002</uuid><name>Other</name></info><data>' .
        $parcel('Alone', '1/1/1', REMOTE_HILL) . '</data></region></regiondata></snapshot>';
}

/**
 * A remote grid far.example.net:8002 (its gatekeeper, the simulator of its region Far, its asset service) and the stores of the
 * pictures; what is asked is counted, and the grid stops answering when `$down` is set.
 */
function remote_grid(): object
{
    $world = new class {
        public array $asked = [];

        public bool $down = false;

        public ClockFake $clock;

        public Cache $cache;

        public RemotePictures $pictures;
    };
    $world->clock = new ClockFake();
    $folder = test_tmp() . '/remote-pictures-' . bin2hex(random_bytes(4));
    $world->cache = new Cache(new FileCacheStore("$folder/cache"), $world->clock);
    Gatekeeper::using(static function (string $url, string $body) use ($world): ?string {
        preg_match('#<methodName>(.*?)</methodName>#', $body, $method);
        $world->asked[] = "$url {$method[1]}";
        if ($world->down) {
            return null;
        }

        return match ($method[1]) {
            'link_region' => remote_rpc(['uuid' => REMOTE_REGION, 'result' => 'True']),
            'get_region' => remote_rpc(['uuid' => REMOTE_REGION, 'region_name' => 'Far', 'server_uri' => 'http://sim.far.example.net:9000/', 'x' => '256000', 'y' => '256000', 'result' => 'True']),
            // The land of the simulator: the parcel at a point of the region, by its handle (x << 32 | y)
            'land_data' => remote_rpc(
                str_contains($body, (string) ((256000 << 32) | 256000)) && str_contains($body, '<string>10</string>') && str_contains($body, '<string>20</string>')
                    ? ['Name' => 'Beach', 'SnapshotID' => REMOTE_BEACH, 'UserLocation' => '<10, 20, 30>']
                    : ['Name' => 'Hill', 'SnapshotID' => '00000000-0000-0000-0000-000000000000'],
            ),
            default => null,
        };
    });
    $http = static function (string $url) use ($world): ?string {
        $world->asked[] = $url;

        return $world->down ? null : match ($url) {
            'http://sim.far.example.net:9000/?method=collector' => remote_snapshot(),
            'http://far.example.net:8002/assets/' . REMOTE_BEACH => '<AssetBase><Data>' . base64_encode('JP2-BYTES') . '</Data><ID>' . REMOTE_BEACH . '</ID><Name>Beach</Name><Type>0</Type></AssetBase>',
            default => null,
        };
    };
    $world->pictures = new RemotePictures($world->cache, $http, new GridConfig(), 'http://grid.example.org:8002', $world->clock);

    return $world;
}

afterEach(fn() => Gatekeeper::using(null));

describe('A grid alone', function () {
    test('lands on the region its gatekeeper gives: a page asks it once, within a second or two, then reads what was kept', function () {
        $world = remote_grid();
        $machine = new Machine();
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'demo');
        $cache = Cache::for($machine->environment, $grid->config());

        $place = $grid->place('far.example.net:8002');
        $asked = $world->asked;
        $world->asked = [];
        $again = $grid->place('far.example.net:8002');

        expect($asked)
            ->toBe(['http://far.example.net:8002 link_region', 'http://far.example.net:8002 get_region'])
            ->and(RemotePictures::landing($cache, 'far.example.net:8002'))
            ->toBe('Far')
            ->and($place->tpUri())
            ->toBe('far.example.net:8002/Far')
            ->and($place->tpLink(W4OS\Engine\Hypergrid\Destination::HOP))
            ->toBe('hop://far.example.net:8002/Far')
            ->and($again->tpUri())
            ->toBe('far.example.net:8002/Far')
            // A login URI still adds its grid first and copies its exact address, its teleports landing on the region
            ->and(array_key_first($place->tpLinks('browser')))
            ->toBe(W4OS\Engine\Hypergrid\Destination::ADDGRID)
            ->and($place->tpLinks('browser')[W4OS\Engine\Hypergrid\Destination::LSL]['link'])
            ->toBe('far.example.net:8002')
            ->and(array_key_first($grid->place('http://far.example.net:8002/')->tpLinks('viewer')))
            ->toBe(W4OS\Engine\Hypergrid\Destination::ADDGRID)
            ->and($grid->place('http://far.example.net:8002/')->tpLinks('viewer')[W4OS\Engine\Hypergrid\Destination::LSL]['link'])
            ->toBe('far.example.net:8002')
            ->and($world->asked)
            ->toBe([])
            ->and(W4OS\Engine\Hypergrid\Gatekeeper::$timeout)
            ->toBe(Gatekeeper::TIMEOUT);
    });

    test('this grid itself too: its own gatekeeper gives the region a visitor lands on', function () {
        $world = remote_grid();
        $grid = W4OS\Engine\Models\Grid::find((new Machine())->environment, 'demo');
        $login = (string) $grid->loginUri();

        expect($grid->place($login)->tpUri())
            ->toBe("$login/Far")
            // Its login URI adds it and copies its exact address, whatever region it lands on
            ->and(array_key_first($grid->place($login)->tpLinks('browser')))
            ->toBe(W4OS\Engine\Hypergrid\Destination::ADDGRID)
            ->and($grid->place("http://$login/")->tpLinks('browser')[W4OS\Engine\Hypergrid\Destination::LSL]['link'])
            ->toBe($login)
            ->and($world->asked)
            ->toBe(['http://' . $login . ' link_region', 'http://' . $login . ' get_region'])
            // A region of this grid named alone is not asked
            ->and($grid->place('Welcome')->tpUri())
            ->toBe("$login/Welcome")
            ->and(count($world->asked))
            ->toBe(2);
    });

    test('whose gatekeeper does not answer has no link that needs a region, only adding it and its address to copy, asked again half an hour later', function () {
        $world = remote_grid();
        $world->down = true;
        $machine = new Machine();
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'demo');

        $place = $grid->place('far.example.net:8002');
        $asked = count($world->asked);
        $grid->place('far.example.net:8002');
        $soon = count($world->asked);
        // Answering again, asked again once the half hour is over (a gatekeeper slow once)
        $world->down = false;
        Gatekeeper::forget();
        $machine->clock->now += RemotePictures::LANDING_RETRY + 1;
        $later = $grid->place('far.example.net:8002');

        expect($place->tpLink(W4OS\Engine\Hypergrid\Destination::HOP))
            ->toBe('')
            // Adding the grid to the viewer, then its address to copy
            ->and(array_column($place->tpLinks('browser'), 'label'))
            ->toBe(['link_menu_addgrid', 'link_menu_copy'])
            ->and($soon)
            ->toBe($asked)
            ->and($later->tpUri())
            ->toBe('far.example.net:8002/Far');
    });

    test('of the grid itself: its links land on its region, its address stays its login', function () {
        $world = remote_grid();
        $grid = W4OS\Engine\Models\Grid::find((new Machine())->environment, 'demo');
        $login = (string) $grid->loginUri();
        $place = $grid->place($login);

        expect($grid->tpUri())
            ->toBe($login)
            ->and($grid->tpLinks('browser'))
            ->toBe($place->tpLinks('browser'))
            ->and($grid->tpLink(W4OS\Engine\Hypergrid\Destination::HOP))
            ->toBe("hop://$login/Far")
            ->and(count($world->asked))
            ->toBe(2);
    });

    test('of the guide is asked by the task too, and kept', function () {
        $world = remote_grid();
        $machine = new Machine();
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'demo');
        $cache = Cache::for($machine->environment, $grid->config());
        $pictures = new RemotePictures($cache, static fn(string $url): ?string => null, new GridConfig(), (string) $grid->loginUrl(), $world->clock);

        $pictures->refresh((new DestinationPictures(null))->of(['Visit' => [['name' => 'Far grid', 'url' => 'far.example.net:8002']]]));

        expect($world->asked)
            ->toBe(['http://far.example.net:8002 link_region', 'http://far.example.net:8002 get_region'])
            ->and(RemotePictures::landing($cache, 'far.example.net:8002'))
            ->toBe('Far');
    });
});

describe('The pictures of the hypergrid destinations', function () {
    test('are asked to their grid: its gatekeeper, the land of the simulator of the region at the landing point, its picture', function () {
        $world = remote_grid();
        $guide = (new DestinationPictures(null))->of(['Visit' => [['name' => 'Far beach', 'url' => 'far.example.net:8002/Far/10/20/30']]]);

        $found = $world->pictures->refresh($guide);

        expect($found)
            ->toBe(1)
            ->and($world->asked)
            ->toBe([
                'http://far.example.net:8002 link_region',
                'http://far.example.net:8002 get_region',
                'http://sim.far.example.net:9000/ land_data',
                'http://far.example.net:8002/assets/' . REMOTE_BEACH,
            ])
            // The picture is kept for the pages of the grid (WebAssets), with what the asset service said of it, in the cache every
            // process of the grid shares (the task runs as the web server, the pages as the account of the instances)
            ->and(RemotePictures::image($world->cache, REMOTE_BEACH))
            ->toBe(['data' => 'JP2-BYTES', 'type' => 0, 'name' => 'Beach'])
            ->and(RemotePictures::lookup($world->cache, 'far.example.net:8002/Far/10/20/30', 'Far beach'))
            ->toBe(REMOTE_BEACH);

        return $world;
    });

    test('are what the guide shows, read from what was kept, without asking anything', function (object $world) {
        $world->asked = [];
        $guide = (new DestinationPictures(null, '', $world->cache))->of(['Visit' => [['name' => 'Far beach', 'url' => 'far.example.net:8002/Far/10/20/30'], ['name' => 'Unknown', 'url' => 'far.example.net:8002/Nowhere']]]);

        expect(array_column($guide['Visit']['destinations'], 'picture', 'name'))
            ->toBe(['Far beach' => REMOTE_BEACH, 'Unknown' => null])
            ->and($guide['Visit']['picture'])
            ->toBe(REMOTE_BEACH)
            ->and($world->asked)
            ->toBe([]);
    })->depends('are asked to their grid: its gatekeeper, the land of the simulator of the region at the landing point, its picture');

    test('are asked again after a day, a failure after a few hours; a grid that stops answering keeps its picture', function () {
        $world = remote_grid();
        $guide = (new DestinationPictures(null))->of(['Visit' => [
            ['name' => 'Far beach', 'url' => 'far.example.net:8002/Far/10/20/30'],
            // A parcel without a picture at the point: not found
            ['name' => 'Hill', 'url' => 'far.example.net:8002/Far'],
        ]]);
        $world->pictures->refresh($guide);
        $world->asked = [];

        $world->pictures->refresh($guide);
        $soon = $world->asked;
        $world->clock->now += RemotePictures::RETRY + 1;
        $world->pictures->refresh($guide);
        $retried = count($world->asked);
        $world->asked = [];
        $world->down = true;
        $world->clock->now += RemotePictures::CHECK;
        $world->pictures->refresh($guide);

        expect($soon)
            ->toBe([])
            // The failure only: its gatekeeper and the land of its simulator, asked again
            ->and($retried)
            ->toBe(3)
            ->and(RemotePictures::lookup($world->cache, 'far.example.net:8002/Far/10/20/30', 'Far beach'))
            ->toBe(REMOTE_BEACH)
            ->and(RemotePictures::lookup($world->cache, 'far.example.net:8002/Far', 'Hill'))
            ->toBeNull();
    });

    test('are not taken from the snapshot of the simulator: the land of the simulator only', function () {
        $world = remote_grid();
        $guide = (new DestinationPictures(null))->of(['Visit' => [['name' => 'Hill', 'url' => 'far.example.net:8002/Far/128/128/25']]]);

        $found = $world->pictures->refresh($guide);

        expect($found)
            ->toBe(0)
            ->and($world->asked)
            ->toBe(['http://far.example.net:8002 link_region', 'http://far.example.net:8002 get_region', 'http://sim.far.example.net:9000/ land_data']);
    });

    test('ask the grids their info too, once a day, so that their cards can say the names they give themselves', function () {
        $world = remote_grid();
        $answers = ['http://far.example.net:8002/get_grid_info' => '<gridinfo><gridname>Far World</gridname></gridinfo>'];
        $asked = [];
        $grids = new W4OS\Engine\Network\GridInfoCache(grid_info_world($answers, $asked)->environment, cache: $world->cache);
        $pictures = new RemotePictures($world->cache, static fn(string $url): ?string => null, new GridConfig(), 'http://grid.example.org:8002', $world->clock, $grids);
        $guide = ['Visit' => ['picture' => null, 'destinations' => [
            ['name' => 'Known', 'url' => 'far.example.net:8002/Far', 'picture' => REMOTE_HILL],
            ['name' => 'Home', 'url' => 'grid.example.org:8002/Welcome', 'picture' => null],
        ]]];

        $pictures->refresh($guide);
        $pictures->refresh($guide);

        expect($asked)
            ->toBe(['http://far.example.net:8002/get_grid_info'])
            ->and($grids->saved('http://far.example.net:8002')['gridname'] ?? null)
            ->toBe('Far World');
    });

    test('are not asked for the places of the grid, nor for the ones whose picture of the search is kept here', function () {
        $world = remote_grid();
        // The picture the search names, its grid gave it already
        RemotePictures::keep($world->cache, REMOTE_HILL, 'JP2-BYTES', 0, 'Hill');

        $found = $world->pictures->refresh(['Visit' => ['picture' => null, 'destinations' => [
            ['name' => 'Home', 'url' => 'grid.example.org:8002/Welcome', 'picture' => null],
            ['name' => 'Known', 'url' => 'far.example.net:8002/Far', 'picture' => REMOTE_HILL],
        ]]]);

        expect($found)->toBe(0)->and($world->asked)->toBe([]);
    });

    test('are given by the pages of the grid as its own pictures, from the cache of the grid, without asking the other grid', function () {
        $machine = new Machine();
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'demo');
        $converter = new class implements W4OS\Engine\Assets\ConverterInterface {
            public function convert(string $data, string $format, ?int $width = null): ?string
            {
                return "$format:$data";
            }
        };
        RemotePictures::keep(W4OS\Engine\Data\Cache::for($machine->environment, W4OS\Engine\Config\GridConfig::fromFolder($machine->grid)), REMOTE_BEACH, 'JP2-BYTES', 0, 'Beach');
        $asked = [];
        $web = new W4OS\Engine\Assets\WebAssets(
            $grid,
            new FileCache(test_tmp() . '/remote-web-' . bin2hex(random_bytes(4)) . '/images'),
            new FileCache(test_tmp() . '/remote-web-' . bin2hex(random_bytes(4)) . '/sources'),
            $converter,
            static function (string $url) use (&$asked): ?string {
                $asked[] = $url;

                return null;
            },
        );

        expect($web->get(REMOTE_BEACH, 'png'))
            ->toBe(['data' => 'png:JP2-BYTES', 'mime' => 'image/png', 'found' => true])
            ->and($asked)
            ->toBe([]);
    });

    test('are asked again when the picture of a destination is known but not kept any more', function () {
        $world = remote_grid();
        $guide = (new DestinationPictures(null))->of(['Visit' => [['name' => 'Far beach', 'url' => 'far.example.net:8002/Far/10/20/30']]]);
        $world->pictures->refresh($guide);
        $world->cache->forget('guide.image.' . REMOTE_BEACH);
        $world->asked = [];

        $world->pictures->refresh($guide);

        expect($world->asked)
            ->toContain('http://far.example.net:8002/assets/' . REMOTE_BEACH)
            ->and(RemotePictures::image($world->cache, REMOTE_BEACH))
            ->not->toBeNull();
    });

    test('are fetched from the asset service the user agents of their grid give, when its gatekeeper has none', function () {
        $world = remote_grid();
        $owner = 'eeeeeeee-0000-4000-8000-000000000001';
        // A grid whose gatekeeper serves no asset: its user agents say the one of its users (as OSGrid)
        Gatekeeper::using(static function (string $url, string $body) use ($world, $owner): ?string {
            preg_match('#<methodName>(.*?)</methodName>#', $body, $method);
            $world->asked[] = "$url {$method[1]}";

            return match ($method[1]) {
                'link_region' => remote_rpc(['uuid' => REMOTE_REGION, 'result' => 'True']),
                'get_region' => remote_rpc(['uuid' => REMOTE_REGION, 'server_uri' => 'http://sim.far.example.net:9000/', 'x' => '256000', 'y' => '256000', 'result' => 'True']),
                'land_data' => remote_rpc(['Name' => 'Beach', 'SnapshotID' => REMOTE_BEACH, 'OwnerID' => $owner]),
                'get_server_urls' => str_contains($body, $owner) ? remote_rpc(['SRV_AssetServerURI' => 'http://assets.far.example.net/', 'SRV_HomeURI' => 'http://far.example.net:8002']) : null,
                default => null,
            };
        });
        $http = static function (string $url) use ($world): ?string {
            $world->asked[] = $url;

            return $url === 'http://assets.far.example.net/assets/' . REMOTE_BEACH
                ? '<AssetBase><Data>' . base64_encode('JP2-BYTES') . '</Data><ID>' . REMOTE_BEACH . '</ID><Name>Beach</Name><Type>0</Type></AssetBase>'
                : null;
        };
        $pictures = new RemotePictures($world->cache, $http, new GridConfig(), 'http://grid.example.org:8002', $world->clock);

        $found = $pictures->refresh((new DestinationPictures(null))->of(['Visit' => [['name' => 'Far beach', 'url' => 'far.example.net:8002/Far/10/20/30']]]));

        expect($found)
            ->toBe(1)
            ->and($world->asked)
            ->toContain('http://far.example.net:8002/assets/' . REMOTE_BEACH, 'http://far.example.net:8002 get_server_urls', 'http://assets.far.example.net/assets/' . REMOTE_BEACH);
    });
});

describe('The pictures of the guide of a grid', function () {
    test('are asked for each destination that has none, never taken for found by the image of its title', function () {
        $world = remote_grid();
        $machine = new Machine();
        $machine->http['http://far.example.net:8002/assets/' . REMOTE_BEACH] = '<AssetBase><Data>' . base64_encode('JP2-BYTES') . '</Data><ID>' . REMOTE_BEACH . '</ID><Name>Beach</Name><Type>0</Type></AssetBase>';
        $machine->write($machine->grid . '/Helpers.ini', "[Helpers]\nGuideSource = \"destinations.txt\"\n");
        $machine->write($machine->grid . '/destinations.txt', "Visit\nFar beach|far.example.net:8002/Far/10/20/30\nFar hill|far.example.net:8002/Far/128/128/25\n");
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'demo');

        $run = $grid->refreshGuidePictures();
        $guide = W4OS\Engine\Models\Grid::find($machine->environment, 'demo')->guide();

        expect($run['found'])->toBe(1)
            ->and($guide['Visit']['destinations'][0]['picture'] ?? null)->toBe(REMOTE_BEACH)
            // The step that gave nothing, for each destination without a picture: here its parcel has none
            ->and($run['missed'])->toBe(['Far hill' => 'the parcel has no picture']);
    });

    test('say the step that failed for each destination: the gatekeeper, the simulator, the asset service', function () {
        $world = remote_grid();
        $guide = (new DestinationPictures(null))->of(['Visit' => [['name' => 'Far beach', 'url' => 'far.example.net:8002/Far/10/20/30']]]);
        $world->down = true;

        $world->pictures->refresh($guide);

        expect($world->pictures->missed())->toBe(['Far beach' => 'the gatekeeper gives no region']);
    });

    test('of the popular places of another grid are asked too, and shown: a host without a grid has no asset of its own', function () {
        $world = remote_grid();
        $machine = new Machine();
        $machine->http['http://far.example.net:8002/assets/' . REMOTE_BEACH] = '<AssetBase><Data>' . base64_encode('JP2-BYTES') . '</Data><ID>' . REMOTE_BEACH . '</ID><Name>Beach</Name><Type>0</Type></AssetBase>';
        $db = search_database();
        $db->exec("INSERT INTO regionsregister VALUES ('Far', 'r9', '9', 'http://sim.far.example.net:9000/', 'Bob', 'o', 'http://far.example.net:8002')");
        $db->exec("INSERT INTO parcels VALUES ('p9', 'r9', 'Far beach', '10/20/30', '', '', 'true', 'true', 'true', 40, 'info', 'PG', 'http://far.example.net:8002', NULL)");
        $db->exec("INSERT INTO popularplaces VALUES ('p9', 'Far beach', 40, 'info', 0, 'PG', 'http://far.example.net:8002')");
        W4OS\Engine\Data\Connections::using(static fn() => $db);
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'demo');

        $run = $grid->refreshGuidePictures();
        $places = array_column($grid->popularPlaces(10, hypergrid: true), 'picture', 'name');
        $kept = RemotePictures::image(Cache::for($machine->environment, $grid->config()), REMOTE_BEACH);
        W4OS\Engine\Data\Connections::using(null);

        expect($run['found'])->toBe(1)
            ->and($places['Far beach'] ?? null)->toBe(REMOTE_BEACH)
            // Kept where the pages find it (WebAssets)
            ->and($kept)->not->toBeNull();
    });

    test('of another grid: never a picture this host cannot show, the image of its title then, on a card of the popular places and of the guide', function () {
        $world = remote_grid();
        $machine = new Machine();
        $machine->http['http://far.example.net:8002/assets/' . REMOTE_BEACH] = '<AssetBase><Data>' . base64_encode('JP2-BYTES') . '</Data><ID>' . REMOTE_BEACH . '</ID><Name>Beach</Name><Type>0</Type></AssetBase>';
        $db = search_database();
        $db->exec("INSERT INTO regionsregister VALUES ('Far', 'r9', '9', 'http://sim.far.example.net:9000/', 'Bob', 'o', 'http://far.example.net:8002')");
        // Both parcels have a picture in the search; the grid gives the one of the beach, the hill has none on its land
        $db->exec("INSERT INTO parcels VALUES ('p9', 'r9', 'Far beach', '10/20/30', '', '', 'true', 'true', 'true', 40, 'info', 'PG', 'http://far.example.net:8002', '" . REMOTE_BEACH . "'), " .
            "('p8', 'r9', 'Far hill', '128/128/25', '', '', 'true', 'true', 'true', 30, 'info', 'PG', 'http://far.example.net:8002', '" . REMOTE_HILL . "')");
        $db->exec("INSERT INTO popularplaces VALUES ('p9', 'Far beach', 40, 'info', 0, 'PG', 'http://far.example.net:8002'), ('p8', 'Far hill', 30, 'info', 0, 'PG', 'http://far.example.net:8002')");
        $machine->write($machine->grid . '/Helpers.ini', "[Helpers]\nGuideSource = \"destinations.txt\"\n");
        $machine->write($machine->grid . '/destinations.txt', "Visit\nFar beach|far.example.net:8002/Far/10/20/30\nFar hill|far.example.net:8002/Far/128/128/25\n");
        W4OS\Engine\Data\Connections::using(static fn() => $db);
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'demo');

        // Before the task: nothing kept, the images of their titles
        $before = array_column($grid->popularPlaces(10, hypergrid: true), 'picture', 'name');
        $grid->refreshGuidePictures();
        $places = array_column($grid->popularPlaces(10, hypergrid: true), 'picture', 'name');
        $guide = array_column($grid->guide()['Visit']['destinations'] ?? [], 'picture', 'name');
        W4OS\Engine\Data\Connections::using(null);
        $title = static fn(?string $picture): bool => str_starts_with((string) $picture, 'title-');

        expect($title($before['Far beach'] ?? null))->toBeTrue()
            // Asked though the search had a picture of it: shown once its grid gave it
            ->and($places['Far beach'] ?? null)->toBe(REMOTE_BEACH)
            ->and($guide['Far beach'] ?? null)->toBe(REMOTE_BEACH)
            // Never given: the image of its title, never the asset the search names
            ->and($title($places['Far hill'] ?? null))->toBeTrue()
            ->and($title($guide['Far hill'] ?? null))->toBeTrue();
    });

    test('of the grid, a region named alone included, are the search\'s: shown as it gives them', function () {
        $cache = new Cache(new FileCacheStore(test_tmp() . '/remote-shown-' . bin2hex(random_bytes(4))), new ClockFake());

        expect(RemotePictures::shown($cache, 'http://grid.example.org:8002', 'Welcome/128/128/25', REMOTE_HILL))->toBe(REMOTE_HILL)
            ->and(RemotePictures::shown($cache, 'http://grid.example.org:8002', 'grid.example.org:8002/Welcome', REMOTE_HILL))->toBe(REMOTE_HILL)
            // Another grid's, not kept here: none
            ->and(RemotePictures::shown($cache, 'http://grid.example.org:8002', 'far.example.net:8002/Far', REMOTE_HILL))->toBeNull();
    });
});

