<?php

declare(strict_types=1);

/**
 * The engine API over HTTP on its own (public/index.php, Api\Server): every request to /api/... answered by the Router, with the
 * keys of the install, no framework and no helpers.
 */

use W4OS\Engine\Api\Server;
use W4OS\Engine\Security\ApiKeys;
use W4OS\Engine\Testing\Machine;

describe('The entry point of the engine API', function () {
    test('answers /api/... with a key of the install, and refuses what is not public without one', function () {
        $machine = new Machine();
        $key = ApiKeys::for($machine->environment)->create('site')['key'];
        $ask = static fn(string $uri, string $authorization = '') => Server::answer(
            $machine->environment,
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1'] + ($authorization !== '' ? ['HTTP_AUTHORIZATION' => $authorization] : []),
            '',
        );

        $grids = $ask('/api/grids?page%5Bsize%5D=5', "Bearer $key");
        $closed = $ask('/api/grids');
        $wrong = $ask('/api/grids', 'Bearer osk_nothing');
        $open = $ask('/api/openapi.json');

        expect([$grids->status, $grids->document['data'][0]['id'] ?? null])
            ->toBe([200, 'demo'])
            ->and($closed->status)
            ->toBe(401)
            ->and($wrong->status)
            ->toBe(401)
            ->and($open->status)
            ->toBe(200);
    });

    test('reads an address with a colon in it as written, encoded or not (host:port of a simulator)', function () {
        $machine = new Machine();
        $key = ApiKeys::for($machine->environment)->create('site')['key'];
        $id = gethostname() . ':9000';
        $ask = static fn(string $uri) => Server::answer(
            $machine->environment,
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => "Bearer $key"],
            '',
        );
        $raw = $ask("/api/simulators/$id");
        $encoded = $ask('/api/simulators/' . rawurlencode($id));
        $queried = $ask("/api/simulators/$id?fields=uri");

        [$cli] = (new W4OS\Engine\Api\Cli(new W4OS\Engine\Api\Router(new W4OS\Engine\Api\Api($machine->environment))))->run(['GET', "/simulators/$id"]);

        expect([$raw->status, $raw->document['data']['id'] ?? null])
            ->toBe([200, $id])
            ->and([$encoded->status, $queried->status, $cli])
            ->toBe([200, 200, 0]);
    });

    test('is the file the package installs, which loads the engine alone', function () {
        $entry = dirname(__DIR__, 2) . '/public/index.php';

        expect(is_file($entry))
            ->toBeTrue()
            ->and((string) file_get_contents($entry))
            ->toContain('Server::serve(')
            ->not->toContain('w4os-helpers');
    });
});
