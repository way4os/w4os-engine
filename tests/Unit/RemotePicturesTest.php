<?php

declare(strict_types=1);

/**
 * The pictures of the hypergrid destinations of a guide: asked to their grid by the scheduler (its gatekeeper, the snapshot of
 * the simulator of the region, its asset service), kept, and read by the guide without asking anything.
 */

use OpenSim\Config\GridConfig;
use OpenSim\Data\Cache;
use OpenSim\Data\FileCacheStore;
use OpenSim\Hypergrid\Gatekeeper;
use OpenSim\Services\DestinationPictures;
use OpenSim\Services\RemotePictures;
use OpenSim\Support\FileCache;
use OpenSim\Testing\ClockFake;

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

        public FileCache $images;

        public RemotePictures $pictures;
    };
    $world->clock = new ClockFake();
    $folder = test_tmp() . '/remote-pictures-' . bin2hex(random_bytes(4));
    $world->cache = new Cache(new FileCacheStore("$folder/cache"), $world->clock);
    $world->images = new FileCache("$folder/images", RemotePictures::KEEP, $world->clock);
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
    $world->pictures = new RemotePictures($world->cache, $world->images, $http, new GridConfig(), 'http://grid.example.org:8002', $world->clock);

    return $world;
}

afterEach(fn() => Gatekeeper::using(null));

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
            // The picture is kept for the pages of the grid (WebAssets), with what the asset service said of it
            ->and($world->images->get(REMOTE_BEACH))
            ->toBe(['data' => 'JP2-BYTES', 'meta' => ['type' => 0, 'name' => 'Beach']])
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
            // A picture its asset service does not give: not found
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
            // The failure only: its gatekeeper, the land of its simulator, its snapshot, its asset service, asked again
            ->and($retried)
            ->toBe(5)
            ->and(RemotePictures::lookup($world->cache, 'far.example.net:8002/Far/10/20/30', 'Far beach'))
            ->toBe(REMOTE_BEACH)
            ->and(RemotePictures::lookup($world->cache, 'far.example.net:8002/Far', 'Hill'))
            ->toBeNull();
    });

    test('take the snapshot of the simulator when its land has no picture at the point', function () {
        $world = remote_grid();
        $guide = (new DestinationPictures(null))->of(['Visit' => [['name' => 'Hill', 'url' => 'far.example.net:8002/Far/128/128/25']]]);

        $world->pictures->refresh($guide);

        expect(array_slice($world->asked, 2, 3))->toBe([
            'http://sim.far.example.net:9000/ land_data',
            'http://sim.far.example.net:9000/?method=collector',
            'http://far.example.net:8002/assets/' . REMOTE_HILL,
        ]);
    });

    test('ask the grids their info too, once a day, so that their cards can say the names they give themselves', function () {
        $world = remote_grid();
        $answers = ['http://far.example.net:8002/get_grid_info' => '<gridinfo><gridname>Far World</gridname></gridinfo>'];
        $asked = [];
        $grids = new OpenSim\Network\GridInfoCache(grid_info_world($answers, $asked)->environment, cache: $world->cache);
        $pictures = new RemotePictures($world->cache, $world->images, static fn(string $url): ?string => null, new GridConfig(), 'http://grid.example.org:8002', $world->clock, $grids);
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

    test('are not asked for the places of the grid, nor for the ones the search has a picture of', function () {
        $world = remote_grid();

        $found = $world->pictures->refresh(['Visit' => ['picture' => null, 'destinations' => [
            ['name' => 'Home', 'url' => 'grid.example.org:8002/Welcome', 'picture' => null],
            ['name' => 'Known', 'url' => 'far.example.net:8002/Far', 'picture' => REMOTE_HILL],
        ]]]);

        expect($found)->toBe(0)->and($world->asked)->toBe([]);
    });

    test('are given by the pages of the grid as its own pictures, without asking the other grid', function () {
        $machine = new OpenSim\Testing\Machine();
        $grid = OpenSim\Models\Grid::find($machine->environment, 'demo');
        $converter = new class implements OpenSim\Assets\ConverterInterface {
            public function convert(string $data, string $format, ?int $width = null): ?string
            {
                return "$format:$data";
            }
        };
        OpenSim\Assets\WebAssets::remote($grid)->put(REMOTE_BEACH, 'JP2-BYTES', ['type' => 0, 'name' => 'Beach']);
        $asked = [];
        $web = new OpenSim\Assets\WebAssets(
            $grid,
            new FileCache(test_tmp() . '/remote-web-' . bin2hex(random_bytes(4)) . '/images'),
            new FileCache(test_tmp() . '/remote-web-' . bin2hex(random_bytes(4)) . '/sources'),
            $converter,
            static function (string $url) use (&$asked): ?string {
                $asked[] = $url;

                return null;
            },
            remote: OpenSim\Assets\WebAssets::remote($grid),
        );

        expect($web->get(REMOTE_BEACH, 'png'))
            ->toBe(['data' => 'png:JP2-BYTES', 'mime' => 'image/png', 'found' => true])
            ->and($asked)
            ->toBe([]);
    });
});
