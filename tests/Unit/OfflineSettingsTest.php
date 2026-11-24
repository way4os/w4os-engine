<?php

declare(strict_types=1);

use W4OS\Engine\Config\ConfigReader;

/**
 * The page of the offline messages (provided_grid() of ProvideChoicesTest, address_patch() of ProvideAddressesTest): provided, the
 * helpers serve the module V1 and keep the messages in their database; not provided, the module is chosen, V1 with the address of
 * another service (never the helpers'), or V2, Robust's, without one. The lines of the files are the ones of the choice.
 */

describe('The offline messages', function () {
    test('not provided, choose the module: V1 with the address of another service, V2 without one', function () {
        $fields = provided_fields('offline');

        expect($fields['offlineModule'])->toMatchArray(['type' => 'select', 'condition' => ['provide' => [false]]])
            ->and($fields['offlineModule'])->not->toHaveKey('readOnly')
            ->and($fields['offlineMessageUrl']['condition'])->toBe(['provide' => [false], 'offlineModule' => ['OfflineMessageModule']])
            ->and($fields['offlineDatabase'])->toMatchArray(['type' => 'connection-string', 'fallback' => 'db_use_default', 'condition' => ['provide' => [true]]]);
    });

    test('V2 needs no address; V1 needs one, not the helpers\'; provided, the helpers serve V1', function () {
        [$machine, $router] = provided_grid();
        $ini = static fn(): string => file_get_contents($machine->grid . '/OpenSim.ini');

        $v2 = address_patch($router, 'offline', ['provide' => false, 'offlineModule' => 'Offline Message Module V2']);
        $v2Ini = $ini();
        $v2Lines = (array_column($router->get('/grids/demo/settings/offline')->document['meta']['snippets'], 'text', 'file')['OpenSim.ini'] ?? '');
        $helpers = address_patch($router, 'offline', ['provide' => false, 'offlineModule' => 'OfflineMessageModule', 'offlineMessageUrl' => 'https://play.example.org/helpers/offline.php']);
        $v1 = address_patch($router, 'offline', ['provide' => false, 'offlineModule' => 'OfflineMessageModule', 'offlineMessageUrl' => 'https://im.example.org/offline.php']);
        $v1Lines = (array_column($router->get('/grids/demo/settings/offline')->document['meta']['snippets'], 'text', 'file')['OpenSim.ini'] ?? '');
        $on = address_patch($router, 'offline', ['provide' => true]);

        expect([$v2->status, $helpers->status, $v1->status, $on->status])->toBe([200, 400, 200, 200])
            ->and($v2->document['data']['attributes'])->toMatchArray(['provide' => false, 'offlineModule' => 'Offline Message Module V2'])
            ->and($v2Ini)->toContain('OfflineMessageModule = "Offline Message Module V2"')
            ->and($v2Lines)->toContain('Offline Message Module V2')->not->toContain('offline.php')
            ->and($v1Lines)->toContain('OfflineMessageModule = OfflineMessageModule')->toContain('OfflineMessageURL = https://im.example.org/offline.php')
            ->and($on->document['data']['attributes'])->toMatchArray(['provide' => true, 'offlineModule' => 'OfflineMessageModule'])
            ->and($ini())->toContain('OfflineMessageURL = "https://play.example.org/helpers/offline');
    });
});

describe('A setting of the simulators', function () {
    test('is written where the simulators read it, never in Robust that has the same key and does not use it', function () {
        [$machine, $router] = provided_grid();
        // A grid whose master gives its simulators what it decides (OpenSim.ini of the grid)
        $machine->write($machine->core . '/bin/OpenSimDefaults.ini', "[Startup]\n    Other = core\n");
        $machine->write($machine->grid . '/OpenSim.ini', "[Messaging]\n    OfflineMessageModule = \"Offline Message Module V2\"\n");
        $master = new W4OS\Engine\Provisioning\SimulatorMaster($machine->environment);
        $master->ensure($machine->grid, $machine->core . '/bin');
        $robust = $machine->grid . '/Robust.HG.ini';
        // A Robust that has the keys of the simulators (an old install read a file of the simulators with it)
        file_put_contents($robust, file_get_contents($robust) . "[Messaging]\n    OfflineMessageModule = \"Offline Message Module V2\"\n    OfflineMessageURL = \"http://old.example.org:8003\"\n");
        $before = (string) file_get_contents($robust);

        $on = address_patch($router, 'offline', ['provide' => true]);
        $search = address_patch($router, 'search', ['provide' => true, 'path' => 'search/query.php']);
        $grid = ConfigReader::file($machine->grid . '/OpenSim.ini');
        // What a simulator runs once restarted: its master made again from the OpenSim.ini of the grid
        $run = ConfigReader::parse($master->render($machine->grid, $machine->core . '/bin'));

        expect([$on->status, $search->status])->toBe([200, 200])
            ->and($grid['Messaging']['OfflineMessageModule'] ?? null)->toBe('OfflineMessageModule')
            ->and($grid['Messaging']['OfflineMessageURL'] ?? '')->toContain('https://play.example.org/helpers/offline')
            ->and($grid['Search']['SearchURL'] ?? '')->toBe('https://play.example.org/helpers/search/query.php')
            ->and($run['Messaging']['OfflineMessageModule'] ?? null)->toBe('OfflineMessageModule')
            // The page says what else is needed: the simulators restart to take it
            ->and(array_column($on->document['meta']['reminders'] ?? [], 'code'))->toContain('restart-simulators')
            // Robust is left as it was: its keys there do nothing
            ->and((string) file_get_contents($robust))->toBe($before);
    });
});

describe('The lines of Robust for the offline messages', function () {
    test('V2: the connector of Robust that keeps them, off in the distribution; V1: the address of the helpers, relayed between grids', function () {
        [, $router] = provided_grid();
        $robust = static fn(): string => array_column($router->get('/grids/demo/settings/offline')->document['meta']['snippets'], 'text', 'file')['Robust.HG.ini'] ?? '';

        address_patch($router, 'offline', ['provide' => false, 'offlineModule' => 'Offline Message Module V2']);
        $v2 = $robust();
        address_patch($router, 'offline', ['provide' => true, 'mailSender' => 'no-reply@example.org']);
        $v1 = $robust();

        expect($v2)->toContain("[ServiceList]\n    OfflineIMServiceConnector = \"\${Const|PrivatePort}/OpenSim.Addons.OfflineIM.dll:OfflineIMServiceRobustConnector\"")
            ->not->toContain('OfflineMessageURL')
            ->and($v1)->toContain("[GridInfoService]\n    OfflineMessageURL = https://play.example.org/helpers/offline")->not->toContain('OfflineIMServiceConnector');
    });
});

describe('The database of the offline messages', function () {
    test('is in the lines of the page when it is not the one of Robust, never with its password', function () {
        [$machine, $router] = provided_grid();

        $default = array_column($router->get('/grids/demo/settings/offline')->document['meta']['snippets'], 'text', 'file');
        address_patch($router, 'offline', ['provide' => true, 'mailSender' => 'no-reply@example.org', 'offlineDatabase' => ['host' => 'db.example.org', 'database' => 'offline', 'user' => 'im', 'password' => 'pw-secret']]);
        $set = array_column($router->get('/grids/demo/settings/offline')->document['meta']['snippets'], 'text', 'file');
        $robust = $set['Robust.HG.ini'] ?? '';

        expect(implode("\n", $default))->not->toContain('[Messaging]' . "\n    ConnectionString")
            ->and($robust)->toContain('[Messaging]')->toContain('ConnectionString = "Data Source=db.example.org;Database=offline;User ID=im;Password=your_password;"')
            ->and(implode("\n", $set))->not->toContain('pw-secret');
    });
});
