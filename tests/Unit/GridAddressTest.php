<?php

declare(strict_types=1);

/**
 * The address of a grid changed at once in every file of it on this machine (POST /grids/{grid}/address, GridAddress): its host, its
 * public port and its site, planned first, then applied.
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Testing\Machine;

/** A grid at old.example.org:8002, its site at http://10.0.0.5:8830, with its addresses in every file the change goes through. */
function address_grid(): array
{
    $machine = new Machine();
    $grid = $machine->grid;
    $machine->write("$grid/Robust.HG.ini", str_replace("[Const]\n", "[Const]\n    BaseHostname = \"old.example.org\"\n    BaseURL = \"http://old.example.org\"\n    WebURL = \"http://10.0.0.5:8830\"\n", file_get_contents("$grid/Robust.HG.ini"))
        . "\n    ConsoleHost = \"old.example.org\"\n[DatabaseService]\n    ConnectionString = \"Data Source=old.example.org;Database=demo;User ID=demo;Password=secret;\"\n"
        . "[GridInfoService]\n    ; the grid was at http://old.example.org:8002 before\n    login = \${Const|BaseURL}:\${Const|PublicPort}/\n"
        . "    register = http://10.0.0.5:8830/register\n    password = \"http://10.0.0.5:8830/forgot_password\"\n    about = \"https://elsewhere.example.org/about\"\n");
    $machine->write("$grid/OpenSim.ini", "[Search]\n    SearchURL = \"http://10.0.0.5:8830/helpers/query.php\"\n[DataSnapshot]\n    DATA_SRV_demo = \"http://10.0.0.5:8830/helpers/register.php\"\n");
    $machine->write("$grid/sims/alpha.ini", file_get_contents("$grid/sims/alpha.ini") . "\n[DataSnapshot]\n    DATA_SRV_demo = \"http://10.0.0.5:8830/helpers/register.php\"\n");
    $machine->write("$grid/sims/alpha/regions/Plaza.ini", file_get_contents("$grid/sims/alpha/regions/Plaza.ini") . "\nExternalHostName = SYSTEMIP\n");
    $machine->write("$grid/sims/beta/regions/Plaza.ini", file_get_contents("$grid/sims/beta/regions/Plaza.ini") . "\nExternalHostName = \"far.example.org\"\n");
    $machine->write("$grid/destinations.txt", "# Our places at old.example.org:8002\nWelcome|old.example.org:8002:Welcome\nFar|far.example.org:8002:Far\nPlaza|http://old.example.org:8002/Plaza|128,128,25\n");
    $machine->write("$grid/web/demo-apache.conf", "# Helpers of the grid demo.\n#\n# Add this line in http://10.0.0.5:8830 virtual host:\n");
    $machine->write("$grid/setup.json", json_encode(['grid' => ['hostname' => 'old.example.org', 'web_url' => 'http://10.0.0.5:8830', 'public_port' => 8002, 'database' => ['host' => 'old.example.org']]], JSON_PRETTY_PRINT));

    return [$machine, new Router(new Api($machine->environment))];
}

/** The POST of the address of the demo grid. */
function address_post(Router $router, array $attributes): W4OS\Engine\Api\Response
{
    return $router->post('/grids/demo/address', ['data' => ['type' => 'address', 'attributes' => $attributes]]);
}

/** @return array<string,string> the files of the demo grid the change goes through, by name */
function address_files(Machine $machine): array
{
    $files = [];
    foreach (['Robust.HG.ini', 'OpenSim.ini', 'sims/alpha.ini', 'sims/alpha/regions/Plaza.ini', 'sims/beta/regions/Plaza.ini', 'destinations.txt', 'setup.json'] as $name) {
        $files[$name] = (string) file_get_contents("{$machine->grid}/$name");
    }

    return $files;
}

describe('The address of a grid', function () {
    test('planned, says each line that would change and writes nothing', function () {
        [$machine, $router] = address_grid();
        $before = address_files($machine);

        $plan = address_post($router, ['host' => 'dev.example.org', 'site' => 'http://site.example.org:8830']);
        $attributes = $plan->document['data']['attributes'];

        expect($plan->status)->toBe(200)
            ->and($attributes['mode'])->toBe('plan')
            ->and($attributes['from'])->toBe(['host' => 'old.example.org', 'port' => 8002, 'site' => 'http://10.0.0.5:8830'])
            ->and($attributes['to'])->toBe(['host' => 'dev.example.org', 'port' => 8002, 'site' => 'http://site.example.org:8830'])
            ->and(array_unique(array_map(static fn(array $change): string => basename(dirname($change['file'])) . '/' . basename($change['file']), $attributes['changes'])))
            ->toContain('demo/Robust.HG.ini', 'demo/OpenSim.ini', 'sims/alpha.ini', 'regions/Plaza.ini', 'demo/destinations.txt', 'demo/setup.json')
            ->and(address_files($machine))->toBe($before)
            ->and($plan->document)->not->toHaveKey('meta');

        return $attributes['changes'];
    });

    test('applied, writes the host, the port and the site everywhere, a database and a comment left as they are', function () {
        [$machine, $router] = address_grid();

        $done = address_post($router, ['host' => 'dev.example.org', 'port' => 8012, 'site' => 'http://site.example.org:8830', 'mode' => 'apply']);
        $files = address_files($machine);

        expect($done->status)->toBe(200)
            ->and($done->document['data']['attributes']['applied'])->toBeTrue()
            ->and(array_column($done->document['meta']['reminders'], 'code'))->toBe(['restart-grid', 'restart-simulators'])
            ->and($files['Robust.HG.ini'])
            ->toContain('BaseHostname = "dev.example.org"', 'BaseURL = "http://dev.example.org"', 'PublicPort = 8012', 'WebURL = "http://site.example.org:8830"', 'ConsoleHost = "dev.example.org"')
            ->toContain('register = ${Const|WebURL}/register', 'password = "${Const|WebURL}/forgot_password"', 'about = "https://elsewhere.example.org/about"')
            ->toContain('Data Source=old.example.org;', '; the grid was at http://old.example.org:8002 before')
            ->and($files['OpenSim.ini'])->toContain('SearchURL = "http://site.example.org:8830/helpers/query.php"', 'DATA_SRV_demo = "http://site.example.org:8830/helpers/register.php"')
            ->and($files['sims/alpha.ini'])->toContain('DATA_SRV_demo = "http://site.example.org:8830/helpers/register.php"')
            ->and($files['sims/alpha/regions/Plaza.ini'])->toContain('ExternalHostName = dev.example.org')
            ->and($files['sims/beta/regions/Plaza.ini'])->toContain('ExternalHostName = "far.example.org"')
            ->and($files['destinations.txt'])->toBe("# Our places at old.example.org:8002\nWelcome|dev.example.org:8012:Welcome\nFar|far.example.org:8002:Far\nPlaza|http://dev.example.org:8012/Plaza|128,128,25\n")
            ->and(json_decode($files['setup.json'], true))->toBe(['grid' => ['hostname' => 'dev.example.org', 'web_url' => 'http://site.example.org:8830', 'public_port' => 8012, 'database' => ['host' => 'old.example.org']]])
            ->and(file_get_contents("{$machine->grid}/web/demo-apache.conf"))->toContain('# Add this line in http://site.example.org:8830 virtual host:')
            ->and(W4OS\Engine\Models\Grid::find($machine->environment, 'demo')->loginUri())->toBe('dev.example.org:8012');

        return $router;
    });

    test('the address it has already, or one that cannot be, is refused', function () {
        [, $router] = address_grid();

        $same = address_post($router, ['host' => 'old.example.org']);
        $wrong = [address_post($router, ['host' => 'not a host']), address_post($router, ['port' => 70000]), address_post($router, ['site' => 'site.example.org'])];

        expect([$same->status, $same->document['code']])->toBe([400, 'invalid-request'])
            ->and(array_map(static fn($answer): int => $answer->status, $wrong))->toBe([400, 400, 400])
            ->and(address_post($router, ['host' => 'dev.example.org', 'mode' => 'now'])->status)->toBe(400)
            ->and($router->post('/grids/nowhere/address', ['data' => ['type' => 'address', 'attributes' => ['host' => 'dev.example.org']]])->status)->toBe(404);
    });
});
