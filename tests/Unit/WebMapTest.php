<?php

declare(strict_types=1);

/**
 * The web map of a grid: its tiles as Robust's MapImageService makes them (map-<zoom>-<x>-<y>-objects.jpg), read from its folder
 * when the grid's files are here, else asked once of Robust and kept; the water tile where the grid has no region, Robust never
 * asked; its regions for the browser, the links to other grids left out.
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Connections;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Models\Region;
use W4OS\Engine\Testing\Machine;

/**
 * A grid of this machine whose Robust serves the map: three regions side by side (a varregion of 512 m), a link to another grid far
 * away, the default region given or not; its tiles in its folder when asked.
 *
 * @return array{0:Machine,1:Grid,2:string} the machine, the grid, the folder of its tiles
 */
function map_grid(bool $map = true, bool $tilesFolder = false, bool $default = true): array
{
    $machine = new Machine();
    $robust = $machine->grid . '/Robust.HG.ini';
    $ini = (string) file_get_contents($robust);
    $map && ($ini = str_replace("[ServiceList]\n", "[ServiceList]\n    MapGetServiceConnector = \"\${Const|PublicPort}/OpenSim.Server.Handlers.dll:MapGetServiceConnector\"\n", $ini)
        . "\n[MapImageService]\n    LocalServiceModule = \"OpenSim.Services.MapImageService.dll:MapImageService\"\n    TilesStoragePath = \"\${Const|DataDirectory}/maptiles\"\n");
    $machine->write($robust, $ini . "\n[Hypergrid]\n    GatekeeperURI = \"http://grid.example.org:8002\"\n");
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec('CREATE TABLE regions (uuid VARCHAR(36) PRIMARY KEY, regionHandle BIGINT, regionName VARCHAR(128), locX INT, locY INT, sizeX INT DEFAULT 0, sizeY INT DEFAULT 0, serverIP VARCHAR(64), serverPort INT, serverURI VARCHAR(255), flags INT DEFAULT 0, last_seen INT DEFAULT 0, owner_uuid CHAR(36), access INT DEFAULT 21, ScopeID CHAR(36), regionMapTexture VARCHAR(36))');
    $insert = $pdo->prepare('INSERT INTO regions (uuid, regionName, locX, locY, sizeX, sizeY, flags) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ([
        ['11111111-0000-0000-0000-000000000001', 'Welcome', 1000, 1000, 256, 256, ($default ? Region::DEFAULT_REGION : 0) | Region::REGION_ONLINE],
        ['11111111-0000-0000-0000-000000000002', 'Plaza', 1001, 1000, 0, 0, Region::REGION_ONLINE],
        ['11111111-0000-0000-0000-000000000003', 'Big Island', 1002, 1000, 512, 512, 0],
        ['11111111-0000-0000-0000-000000000004', 'Elsewhere', 5000, 5000, 256, 256, Region::HYPERLINK],
    ] as [$uuid, $name, $x, $y, $sizeX, $sizeY, $flags]) {
        $insert->execute([$uuid, $name, $x * 256, $y * 256, $sizeX, $sizeY, $flags]);
    }
    Connections::using(static fn() => $pdo);
    $grid = Grid::find($machine->environment, 'demo');
    $folder = $grid->dataDirectory() . '/maptiles/00000000-0000-0000-0000-000000000000';
    $tilesFolder && mkdir($folder, 0o755, true);

    return [$machine, $grid, $folder];
}

/** The water tile of the kit, the one Robust gives where there is no region. */
function map_water(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/share/images/map-water.jpg');
}

afterEach(fn() => Connections::using(null));

describe('A tile of the map', function () {
    test('is read from the folder of the tiles when the grid is here, Robust never asked; a tile not made yet is water', function () {
        [$machine, $grid, $folder] = map_grid(tilesFolder: true);
        file_put_contents("$folder/map-1-1000-1000-objects.jpg", 'JPEG of Welcome');
        $machine->http['http://localhost:8002/map-1-1000-1000-objects.jpg'] = 'JPEG from Robust';

        expect($grid->hasMap())->toBeTrue()
            ->and($grid->mapTile(1, 1000, 1000))->toBe('JPEG of Welcome')
            ->and($grid->webMap()->tile(1, 1001, 1000))->toMatchArray(['data' => map_water(), 'water' => true]);
    });

    test('is asked of Robust once without the folder, then kept a day', function () {
        [$machine, $grid] = map_grid();
        $url = 'http://localhost:8002/map-2-1000-1000-objects.jpg';
        $machine->http[$url] = 'JPEG from Robust';
        $first = $grid->webMap()->tile(2, 1000, 1000);
        $machine->http[$url] = 'JPEG changed since';
        $kept = $grid->mapTile(2, 1000, 1000);
        $machine->clock->now += 86401;
        $later = Grid::find($machine->environment, 'demo')->mapTile(2, 1000, 1000);

        expect($first)->toMatchArray(['data' => 'JPEG from Robust', 'water' => false, 'maxAge' => 86400])
            ->and($kept)->toBe('JPEG from Robust')
            ->and($later)->toBe('JPEG changed since');
    });

    test('is water where the grid has no region, Robust never asked; water for a while when Robust does not answer', function () {
        [$machine, $grid] = map_grid();
        $machine->http['http://localhost:8002/map-1-3000-3000-objects.jpg'] = 'JPEG from Robust';
        // Zoom 3: the 4 by 4 regions from 1000,1000, Big Island in them; from 1004 none (the link to another grid is no region)
        $machine->http['http://localhost:8002/map-3-1000-1000-objects.jpg'] = 'JPEG of the block';
        $machine->http['http://localhost:8002/map-1-1003-1001-objects.jpg'] = 'JPEG of a corner of Big Island';

        $tile = static fn(int $z, int $x, int $y): ?array => $grid->webMap()->tile($z, $x, $y);

        expect($tile(1, 3000, 3000))->toMatchArray(['data' => map_water(), 'water' => true, 'maxAge' => 86400])
            ->and($grid->mapTile(1, 3000, 3000))->toBe(map_water())
            ->and($tile(1, 5000, 5000)['water'])->toBeTrue()
            ->and($grid->mapTile(3, 1000, 1000))->toBe('JPEG of the block')
            ->and($grid->mapTile(1, 1003, 1001))->toBe('JPEG of a corner of Big Island')
            ->and($tile(1, 1004, 1000)['water'])->toBeTrue()
            // Robust busy (its map handler answers one at a time): water, kept a minute only
            ->and($tile(1, 1001, 1000))->toMatchArray(['data' => map_water(), 'water' => true, 'maxAge' => 60]);
    });

    test('is none on a grid that serves no map, or out of the zooms of Robust', function () {
        [, $grid] = map_grid(map: false);
        [, $mapped] = map_grid();

        expect($grid->hasMap())->toBeFalse()
            ->and($grid->mapTile(1, 1000, 1000))->toBeNull()
            ->and($grid->mapRegions())->toBeNull()
            ->and($mapped->mapTile(0, 1000, 1000))->toBeNull()
            ->and($mapped->mapTile(9, 1000, 1000))->toBeNull()
            ->and($mapped->mapTile(1, -1, 1000))->toBeNull();
    });
});

describe('The regions of the map', function () {
    test('are the ones of the grid, in regions of 256 m, their size and their links, never a link to another grid', function () {
        [, $grid] = map_grid();
        $map = $grid->mapRegions();
        $regions = array_column($map['regions'], null, 'name');

        expect(array_keys($regions))->toBe(['Big Island', 'Plaza', 'Welcome'])
            ->and($regions['Big Island'])->toMatchArray(['x' => 1002, 'y' => 1000, 'sizeX' => 512, 'sizeY' => 512, 'online' => false])
            ->and($regions['Plaza'])->toMatchArray(['x' => 1001, 'y' => 1000, 'sizeX' => 256, 'sizeY' => 256, 'online' => true])
            // By client, as the results of the search give them
            ->and(array_keys($regions['Welcome']['tpLinks']))->toBe(['viewer', 'browser'])
            ->and($regions['Welcome']['tpLinks']['browser'])->not->toBe([])
            ->and(reset($regions['Welcome']['tpLinks']['browser']))->toMatchArray(['link' => 'hop://grid.example.org:8002/Welcome', 'action' => 'teleport'])
            ->and($map['bounds'])->toBe(['minX' => 1000, 'minY' => 1000, 'maxX' => 1004, 'maxY' => 1002])
            // The middle of the default region
            ->and($map['center'])->toBe(['x' => 1000.5, 'y' => 1000.5]);
    });

    test('are centred on the middle of the grid without a default region', function () {
        [, $grid] = map_grid(default: false);

        expect($grid->mapRegions()['center'])->toBe(['x' => 1002.0, 'y' => 1001.0]);
    });
});

describe('The map in the API', function () {
    test('gives a tile as a JPEG the browsers keep, open to everyone', function () {
        [, $grid, $folder] = map_grid(tilesFolder: true);
        file_put_contents("$folder/map-1-1000-1000-objects.jpg", 'JPEG of Welcome');
        $router = new Router(new Api($grid->environment()));
        $tile = $router->get('/grids/demo/map/1/1000/1000.jpg');

        expect($tile->status)->toBe(200)
            ->and($tile->body())->toBe('JPEG of Welcome')
            ->and($tile->headers)->toMatchArray(['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=86400', 'ETag' => '"' . md5('JPEG of Welcome') . '"'])
            ->and($router->get('/grids/demo/map/9/1000/1000.jpg')->status)->toBe(404)
            ->and($router->get('/grids/nowhere/map/1/1000/1000.jpg')->status)->toBe(404)
            ->and(Router::PUBLIC)->toContain('GET /grids/{grid}/map/{z}/{x}/{y}.jpg', 'GET /grids/{grid}/map');
    });

    test('gives the regions of the map, their bounds and their centre; a grid without a map says so', function () {
        [, $grid] = map_grid();
        $map = (new Router(new Api($grid->environment())))->get('/grids/demo/map');
        [, $none] = map_grid(map: false);
        $nothing = (new Router(new Api($none->environment())))->get('/grids/demo/map');

        expect($map->status)->toBe(200)
            ->and(array_column($map->document['data'], 'type'))->toBe(['map-region', 'map-region', 'map-region'])
            ->and(array_column(array_column($map->document['data'], 'attributes'), 'name'))->toBe(['Big Island', 'Plaza', 'Welcome'])
            ->and($map->document['meta'])->toMatchArray(['hasMap' => true, 'bounds' => ['minX' => 1000, 'minY' => 1000, 'maxX' => 1004, 'maxY' => 1002], 'center' => ['x' => 1000.5, 'y' => 1000.5]])
            ->and($nothing->document['meta']['hasMap'])->toBeFalse();
    });
});

describe('The address of the tiles', function () {
    test('of a grid only described here is where it logs in, the public port of its Robust; its MapTileURL wins', function () {
        $machine = new Machine();
        // It says its login, never the address of its tiles (MapTileURL is given at login only)
        $machine->http['http://dev-demo.w4os.org:3405/get_grid_info'] = '<gridinfo><gridname>W4OS Demo</gridname><login>http://dev-demo.w4os.org:3405/</login></gridinfo>';
        $described = static fn(?GridConfig $config = null): Grid => Grid::described($machine->environment, ['nick' => 'w4osdemogrid', 'name' => 'W4OS Demo', 'remote' => true, 'baseHostname' => 'dev-demo.w4os.org', 'publicPort' => 3405], $config);
        $given = $described(new GridConfig(['Robust' => ['Const' => ['BaseURL' => 'http://tiles.example.org', 'PublicPort' => '8080'], 'LoginService' => ['MapTileURL' => '${Const|BaseURL}:${Const|PublicPort}/']]]));

        expect($described()->mapUrl())->toBe('http://dev-demo.w4os.org:3405')
            ->and($described()->hasMap())->toBeTrue()
            ->and($given->mapUrl())->toBe('http://tiles.example.org:8080/')
            // One that does not answer has no login: no map
            ->and(Grid::described($machine->environment, ['nick' => 'gone', 'name' => 'Gone', 'remote' => true, 'baseHostname' => 'gone.example.org', 'publicPort' => 8002])->hasMap())->toBeFalse()
            // A grid of this machine says it: its Robust serves no map, it has none
            ->and(map_grid(map: false)[1]->mapUrl())->toBeNull();
    });
});
