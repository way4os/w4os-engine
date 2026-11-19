<?php

declare(strict_types=1);

/**
 * The state of a grid at a glance: its Robust, its simulators here and elsewhere, its regions, as data, as the lines of `opensim
 * status`, and as a picture for a prim; and the addresses and the info of a grid, as the API gives them.
 */

use Nyholm\Psr7\Factory\Psr17Factory;
use OpenSim\Api\Api;
use OpenSim\Api\Handler;
use OpenSim\Data\Connections;
use OpenSim\Models\Grid;
use OpenSim\Runtime\GridMonitor;
use OpenSim\Testing\Machine;

/** The test machine with its Robust and its simulator alpha running, beta down. */
function monitor_machine(bool $robust = true): Machine
{
    $machine = new Machine();
    $machine->processes->add('dotnet OpenSim.dll -inifile=' . $machine->etc . '/opensim.d/alpha.ini');
    $robust &&
        $machine->processes->add(
            'dotnet Robust.dll -inifile=' . $machine->etc . '/robust.d/demo.ini',
            'opensim',
            0.3,
            1.2,
            50000,
        );

    return $machine;
}

/** The regions of the grid, and the tables its numbers are counted in. */
function monitor_database(): OpenSim\Data\OSPDO
{
    $db = region_database();
    $db->exec('ALTER TABLE UserAccounts ADD COLUMN active INT DEFAULT 1');
    $db->exec('CREATE TABLE GridUser (UserID CHAR(36), Login VARCHAR(16) DEFAULT 0)');
    $db->exec('CREATE TABLE Presence (UserID CHAR(36), RegionID CHAR(36))');
    $db->exec(
        "INSERT INTO Presence VALUES ('11111111-1111-1111-1111-111111111111', 'aaaaaaaa-0000-0000-0000-000000000001')",
    );

    return $db;
}

afterEach(fn() => Connections::using(null));

describe('The monitor of a grid', function () {
    test(
        'tells the instances of the machine, the simulators with their regions, and the level of the grid: ok with one down',
        function () {
            $machine = monitor_machine();
            $db = monitor_database();
            Connections::using(static fn() => $db);

            $monitor = GridMonitor::of(Grid::find($machine->environment, 'demo'), region_config());

            // A simulator stopped and its regions offline happen, the grid itself works: its level is its services'
            expect($monitor['level'])
                ->toBe('ok')
                ->and(array_column($monitor['instances'], 'running', 'name'))
                ->toBe(['demo' => true, 'alpha' => true, 'beta' => false])
                ->and($monitor['regions'])
                ->toBe(['total' => 3, 'online' => 2])
                ->and(array_column($monitor['simulators'], 'online', 'slug'))
                ->toBe(['alpha' => 2, 'beta' => 0])
                ->and(array_column($monitor['simulators'], 'regions', 'slug'))
                ->toBe(['alpha' => 2, 'beta' => 1])
                // The name to show, as the page of a region gives it, the instance apart
                ->and(array_column($monitor['simulators'], 'name', 'slug'))
                ->toBe(['alpha' => 'Alpha', 'beta' => 'Beta'])
                ->and([$monitor['up'], $monitor['down']])
                ->toBe([2, 1])
                ->and($monitor['summary'])
                ->toStartWith('2 up, 1 down - regions 2/3');
        },
    );

    test(
        'is down when the Robust does not run, ok when everything runs, and shows a simulator that runs elsewhere',
        function () {
            $down = monitor_machine(robust: false);
            $db = monitor_database();
            Connections::using(static fn() => $db);
            $off = GridMonitor::of(Grid::find($down->environment, 'demo'), region_config());

            $up = monitor_machine();
            $up->processes->add('dotnet OpenSim.dll -inifile=' . $up->etc . '/opensim.d/beta.ini');
            $db = monitor_database();
            $db->exec("UPDATE regions SET flags = 4 WHERE uuid = 'aaaaaaaa-0000-0000-0000-000000000003'");
            $db->exec(
                "INSERT INTO regions (uuid, regionHandle, regionName, locX, locY, serverIP, serverPort, serverURI, flags) VALUES ('aaaaaaaa-0000-0000-0000-000000000009', 9, 'Remote', 1, 1, '10.9.9.9', 9100, 'http://remote.example.org:9100/', 4)",
            );
            Connections::using(static fn() => $db);
            $ok = GridMonitor::of(Grid::find($up->environment, 'demo'), region_config());

            expect($off['level'])
                ->toBe('down')
                ->and($ok['level'])
                ->toBe('ok')
                ->and($ok['regions'])
                ->toBe(['total' => 4, 'online' => 4])
                ->and(array_values(array_filter($ok['simulators'], static fn(array $s): bool => !$s['local'])))
                ->toBe([
                    [
                        'name' => 'remote.example.org:9100',
                        'slug' => null,
                        'uri' => 'http://remote.example.org:9100/',
                        'local' => false,
                        'running' => true,
                        'regions' => 1,
                        'online' => 1,
                    ],
                ]);
        },
    );

    test('is degraded when the Robust runs and the database of its grid service does not answer', function () {
        $machine = monitor_machine();
        Connections::using(static fn() => null);

        $monitor = GridMonitor::of(Grid::find($machine->environment, 'demo'), region_config());

        expect($monitor['level'])->toBe('degraded');
    });

    test('says its level by a text of the catalog, in every language, never the keyword of the viewers', function () {
        $keys = array_map(GridMonitor::labelKey(...), [GridMonitor::OK, GridMonitor::DEGRADED, GridMonitor::DOWN, GridMonitor::UNKNOWN]);
        $french = OpenSim\I18n\Catalog::messages('fr_FR');
        $missing = [];
        foreach (glob(OpenSim\I18n\Catalog::directory() . '/*.po') ?: [] as $file) {
            preg_match('/-([a-z]{2}_[A-Z]{2})\.po$/', $file, $m);
            $texts = OpenSim\I18n\Catalog::messages($m[1], false);
            foreach (array_filter($keys) as $key) {
                ($texts[$key] ?? '') === '' && ($missing[] = "$m[1] $key");
            }
        }

        expect($keys)
            ->toBe(['online', 'degraded', 'offline', null])
            ->and(array_intersect_key(OpenSim\I18n\Catalog::MESSAGES, array_flip(array_filter($keys))))
            ->toBe(['online' => 'Online', 'offline' => 'Offline', 'degraded' => 'Degraded'])
            ->and([$french['online'], $french['degraded'], $french['offline']])
            ->toBe(['En ligne', 'Dégradé', 'Hors ligne'])
            ->and($missing)
            ->toBe([]);
    });

    test('is written as the table of opensim status, with a title and a summary', function () {
        $machine = monitor_machine();
        $db = monitor_database();
        Connections::using(static fn() => $db);

        $text = GridMonitor::text(GridMonitor::of(Grid::find($machine->environment, 'demo'), region_config()));

        expect($text)
            ->toStartWith("OK - Demo\nNAME")
            ->toMatch('/^demo\s+robust\s+up\s+-\s+0\.3\s+1\.2$/m')
            ->toMatch('/^alpha\s+simulator\s+up\s+2\/2\s/m')
            ->toMatch('/^beta\s+simulator\s+down\s+0\/1\s/m')
            ->toEndWith("\n");
    });
});

describe('The monitor, the addresses and the info of a grid, in the API', function () {
    function monitor_handler(Machine $machine, array &$drawn): Handler
    {
        $factory = new Psr17Factory();

        return new Handler(
            new Api($machine->environment, config: static fn() => region_config(), texts: text_renderer($drawn)),
            $factory,
            $factory,
            '/api',
            authenticator: new OpenSim\Security\OpenAccess(),
        );
    }

    test('give the monitor as data, as text, and as a picture colored by its level', function () {
        $machine = monitor_machine();
        $db = monitor_database();
        Connections::using(static fn() => $db);
        $drawn = [];
        $handler = monitor_handler($machine, $drawn);

        $json = json_decode((string) api_ask($handler, 'GET', '/api/grids/demo/monitor')->getBody(), true);
        $text = api_ask($handler, 'GET', '/api/grids/demo/monitor?format=text');
        $png = api_ask($handler, 'GET', '/api/grids/demo/monitor?format=png');

        expect($json['data'])
            ->toMatchArray(['type' => 'monitor', 'id' => 'demo'])
            ->and($json['data']['attributes']['level'])
            ->toBe('ok')
            ->and($text->getHeaderLine('Content-Type'))
            ->toBe('text/plain; charset=utf-8')
            ->and((string) $text->getBody())
            ->toStartWith('OK - Demo')
            ->and($png->getHeaderLine('Content-Type'))
            ->toBe('image/png')
            ->and($drawn[0][1])
            ->toBe('DejaVu-Sans-Mono')
            // Green: the grid is ok
            ->and($drawn[0][3])
            ->toBe('#88ff88')
            ->and(api_ask($handler, 'GET', '/api/grids/nowhere/monitor')->getStatusCode())
            ->toBe(404);
    });

    test(
        'give the addresses a grid has, each with where it comes from, and every element it says of itself',
        function () {
            $machine = monitor_machine();
            $machine->write(
                $machine->grid . '/Robust.HG.ini',
                file_get_contents($machine->grid . '/Robust.HG.ini') .
                    "\n[Const]\n    WebURL = \"https://play.example.org\"\n[GridInfoService]\n    economy = \"https://play.example.org/helpers\"\n    gridname = \"Demo World\"\n    freeform = \"kept\"\n",
            );
            $drawn = [];
            $handler = monitor_handler($machine, $drawn);

            $addresses = json_decode((string) api_ask($handler, 'GET', '/api/grids/demo/addresses')->getBody(), true)[
                'data'
            ];
            $info = json_decode((string) api_ask($handler, 'GET', '/api/grids/demo/info')->getBody(), true)['data'];

            expect($addresses)
                ->toMatchArray(['type' => 'addresses', 'id' => 'demo'])
                ->and($addresses['attributes']['economy'])
                ->toBe('https://play.example.org/helpers')
                ->and($addresses['attributes']['addresses']['currency'])
                ->toBe('https://play.example.org/helpers/currency.php')
                ->and($addresses['attributes']['sources']['currency'])
                ->toBe('config')
                ->and($info)
                ->toMatchArray(['type' => 'info', 'id' => 'demo'])
                ->and($info['attributes']['live'])
                ->toBeFalse()
                ->and($info['attributes']['elements'])
                ->toMatchArray(['gridname' => 'Demo World', 'freeform' => 'kept'])
                ->and(api_ask($handler, 'GET', '/api/grids/nowhere/addresses')->getStatusCode())
                ->toBe(404)
                ->and(api_ask($handler, 'GET', '/api/grids/nowhere/info')->getStatusCode())
                ->toBe(404);
        },
    );
});

describe('The addresses of a grid, each what it is', function () {
    test('say their use, the file, the section and the key they are set in, and their name and description in the catalog, file by file', function () {
        $machine = new Machine();
        $router = new OpenSim\Api\Router(new Api($machine->environment));
        $services = $router->get('/grids/demo/addresses')->document['data']['attributes']['services'];
        // Each service as the grid would have it, whether it has it or not
        $grid = OpenSim\Models\Grid::find($machine->environment, 'demo');
        $by = [];
        foreach (OpenSim\Models\Service::keys() as $key) {
            $by[$key] = (new OpenSim\Models\Service($machine->environment, $grid, $key))->address();
        }
        $texts = OpenSim\I18n\Catalog::MESSAGES;
        $order = array_map(static fn(array $s): string => "{$s['file']} {$s['section']}", $services);
        $sorted = $order;
        usort($sorted, static fn(string $a, string $b): int => strcmp($a, $b));

        expect($by['asset'])
            ->toMatchArray(['usage' => 'robust-simulators', 'file' => 'OpenSim.ini', 'section' => 'AssetService', 'setting' => 'AssetServerURI', 'label' => 'service_asset'])
            ->and($by['gatekeeper'])
            ->toMatchArray(['usage' => 'robust-viewers', 'file' => 'Robust.HG.ini', 'section' => 'ServiceList', 'setting' => 'GatekeeperServiceInConnector'])
            ->and($by['currency'])
            ->toMatchArray(['usage' => 'helper-viewers', 'file' => 'Robust.HG.ini', 'section' => 'GridInfoService', 'setting' => 'economy', 'info' => 'economy'])
            ->and($by['query'])
            ->toMatchArray(['usage' => 'helper-simulators', 'file' => 'OpenSim.ini', 'section' => 'Search', 'setting' => 'SearchURL', 'label' => 'setting_search_service'])
            ->and($by['text-images'])
            ->toMatchArray(['usage' => 'web-tool', 'label' => 'service_text_images'])
            ->and($by['assets'])
            ->toMatchArray(['usage' => 'web-assets', 'file' => 'Helpers.ini', 'section' => 'Helpers', 'setting' => 'AssetsURL'])
            ->and($by['welcome']['usage'])
            ->toBe('page')
            // Every name, description and use in the catalog
            ->and(array_column($services, 'key'))
            ->toBe(['asset', 'grid'])
            ->and(array_diff([...array_column($by, 'label'), ...array_column($by, 'description'), ...array_map(static fn(string $u): string => 'usage_' . str_replace('-', '_', $u), array_unique(array_column($by, 'usage')))], array_keys($texts)))
            ->toBe([])
            ->and($texts['service_asset'])
            ->toBe('Assets service')
            // Sorted file by file, then section by section
            ->and($order)
            ->toBe($sorted);
    });
});
