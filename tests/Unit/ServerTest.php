<?php

declare(strict_types=1);

/**
 * The engine API over HTTP on its own (public/index.php, Api\Server): every request to /api/... answered by the Router, with the
 * keys of the install, no framework and no helpers.
 */

use OpenSim\Api\Server;
use OpenSim\Security\ApiKeys;
use OpenSim\Testing\Machine;

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

    test('is the file the package installs, which loads the engine alone', function () {
        $entry = dirname(__DIR__, 2) . '/public/index.php';

        expect(is_file($entry))
            ->toBeTrue()
            ->and((string) file_get_contents($entry))
            ->toContain('Server::serve(')
            ->not->toContain('opensim-helpers');
    });
});
