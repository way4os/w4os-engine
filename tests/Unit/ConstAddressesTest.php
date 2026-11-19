<?php

declare(strict_types=1);

/**
 * An address a page writes in a file of the grid keeps the form of its constants: under a value of [Const] the file uses (WebURL,
 * BaseURL...), it is written as `${Const|Name}` and the rest, the form the line already had whenever only the path changes.
 */

use OpenSim\Api\Api;
use OpenSim\Api\Router;
use OpenSim\Testing\Machine;

describe('An address written in a file of the grid', function () {
    test('under a constant of the grid, is written with the constant', function () {
        $machine = new Machine();
        $robust = $machine->grid . '/Robust.HG.ini';
        $machine->write($robust, str_replace("[Const]\n", "[Const]\n    BaseURL = \"http://192.168.8.69\"\n    WebURL = \"\${Const|BaseURL}:8830\"\n", file_get_contents($robust))
            . "\n[LoginService]\n    DestinationGuide = \"\${Const|WebURL}/guide\"\n");
        $router = new Router(new Api($machine->environment));
        $patch = static fn(string $page, array $attributes) => $router->patch("/grids/demo/settings/$page", ['data' => ['type' => 'settings', 'id' => $page, 'attributes' => $attributes]]);

        $guide = $patch('guide', ['provide' => true, 'path' => '/places/guide']);
        $economy = $patch('economy', ['provide' => false, 'economyUrl' => 'http://192.168.8.69:8002/money/']);
        $elsewhere = $patch('offline', ['provide' => false, 'offlineModule' => 'OfflineMessageModule', 'offlineMessageUrl' => 'https://im.example.org/offline.php']);
        $ini = file_get_contents($robust);

        expect([$guide->status, $economy->status, $elsewhere->status])->toBe([200, 200, 200])
            ->and($guide->document['data']['attributes']['path'])->toBe('places/guide')
            ->and($ini)->toContain('DestinationGuide = "${Const|WebURL}/places/guide"')
            ->and($ini)->toContain('economy = "${Const|BaseURL}:8002/money/"')
            ->and(file_get_contents($machine->grid . '/OpenSim.ini'))->toContain('OfflineMessageURL = "https://im.example.org/offline.php"');
    });
});
