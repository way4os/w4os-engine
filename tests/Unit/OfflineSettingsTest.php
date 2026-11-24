<?php

declare(strict_types=1);

use W4OS\Engine\Config\ConfigReader;

/**
 * The page of the offline messages (provided_grid() of ProvideChoicesTest, address_patch() of ProvideAddressesTest): provided, the
 * helpers serve the module V1 and keep the messages in their database; not provided, the module is chosen, V1 with the address of
 * another service (never the helpers'), or V2, Robust's, without one. The lines of the files are the ones of the choice.
 */

describe('The offline messages', function () {
    test('Provide first, the service on or off (on by default), then the module: V2, or V1 (deprecated) with its helper, its database, its mail', function () {
        $fields = provided_fields('offline');
        $v1 = ['provide' => [true], 'offlineModule' => ['OfflineMessageModule']];

        expect(array_keys($fields))->toBe(['provide', 'offlineModule', 'path', 'offlineDatabase', 'mailSender'])
            ->and($fields['provide'])->toMatchArray(['type' => 'boolean', 'widget' => 'switch', 'default' => true, 'label' => 'provide_offline'])
            ->and($fields['offlineModule'])->toMatchArray(['type' => 'select', 'condition' => ['provide' => [true]], 'default' => 'Offline Message Module V2', 'description' => ['offline_module_help_v2', 'offline_module_help_v1']])
            ->and($fields['offlineModule']['options'])->toBe(['Offline Message Module V2' => 'offline_module_v2', 'OfflineMessageModule' => 'offline_module_v1'])
            ->and(W4OS\Engine\I18n\Catalog::MESSAGES['offline_module_v1'])->toContain('(deprecated)')
            ->and($fields['path']['condition'])->toBe($v1)
            ->and($fields['offlineDatabase'])->toMatchArray(['type' => 'connection-string', 'fallback' => 'db_use_default', 'condition' => $v1])
            ->and($fields['mailSender']['condition'])->toBe($v1);
    });

    test('off: no module, the address of the helper kept as it is; on again, Robust keeps them (V2) unless V1 is said', function () {
        [$machine, $router] = provided_grid();
        $ini = static fn(): array => W4OS\Engine\Config\ConfigReader::file($machine->grid . '/OpenSim.ini')['Messaging'] ?? [];
        $v1 = address_patch($router, 'offline', ['provide' => true, 'offlineModule' => 'OfflineMessageModule', 'mailSender' => 'no-reply@example.org']);
        $v1Ini = $ini();

        $off = address_patch($router, 'offline', ['provide' => false]);
        $offIni = $ini();
        $on = address_patch($router, 'offline', ['provide' => true]);

        expect([$v1->status, $off->status, $on->status])->toBe([200, 200, 200])
            ->and($v1Ini['OfflineMessageModule'] ?? null)->toBe('OfflineMessageModule')
            ->and($v1Ini['OfflineMessageURL'] ?? '')->toContain('https://play.example.org/helpers/offline')
            ->and($off->document['data']['attributes']['provide'] ?? null)->toBeFalse()
            ->and($offIni['OfflineMessageModule'] ?? null)->toBe('None')
            ->and($offIni['OfflineMessageURL'] ?? null)->toBe($v1Ini['OfflineMessageURL'] ?? 'none')
            ->and($on->document['data']['attributes'])->toMatchArray(['provide' => true, 'offlineModule' => 'Offline Message Module V2'])
            ->and($ini()['OfflineMessageModule'] ?? null)->toBe('Offline Message Module V2');
    });

    test('V2 needs no address; V1 is served by the helpers at their address', function () {
        [$machine, $router] = provided_grid();
        $lines = static fn(): string => array_column($router->get('/grids/demo/settings/offline')->document['meta']['snippets'], 'text', 'file')['OpenSim.ini'] ?? '';

        $v2 = address_patch($router, 'offline', ['provide' => true, 'offlineModule' => 'Offline Message Module V2']);
        $v2Lines = $lines();
        $v1 = address_patch($router, 'offline', ['provide' => true, 'offlineModule' => 'OfflineMessageModule', 'mailSender' => 'no-reply@example.org']);
        $v1Lines = $lines();

        expect([$v2->status, $v1->status])->toBe([200, 200])
            ->and($v2->document['data']['attributes'])->toMatchArray(['provide' => true, 'offlineModule' => 'Offline Message Module V2'])
            ->and($v2Lines)->toContain('Offline Message Module V2')->not->toContain('offline.php')
            ->and($v1->document['data']['attributes'])->toMatchArray(['provide' => true, 'offlineModule' => 'OfflineMessageModule'])
            ->and($v1Lines)->toContain('OfflineMessageModule = OfflineMessageModule')->toContain('OfflineMessageURL = https://play.example.org/helpers/offline');
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

        $on = address_patch($router, 'offline', ['provide' => true, 'offlineModule' => 'OfflineMessageModule']);
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

        address_patch($router, 'offline', ['provide' => true, 'offlineModule' => 'Offline Message Module V2']);
        $v2 = $robust();
        address_patch($router, 'offline', ['provide' => true, 'offlineModule' => 'OfflineMessageModule', 'mailSender' => 'no-reply@example.org']);
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
        address_patch($router, 'offline', ['provide' => true, 'offlineModule' => 'OfflineMessageModule', 'mailSender' => 'no-reply@example.org', 'offlineDatabase' => ['host' => 'db.example.org', 'database' => 'offline', 'user' => 'im', 'password' => 'pw-secret']]);
        $set = array_column($router->get('/grids/demo/settings/offline')->document['meta']['snippets'], 'text', 'file');
        $robust = $set['Robust.HG.ini'] ?? '';

        expect(implode("\n", $default))->not->toContain('[Messaging]' . "\n    ConnectionString")
            ->and($robust)->toContain('[Messaging]')->toContain('ConnectionString = "Data Source=db.example.org;Database=offline;User ID=im;Password=your_password;"')
            ->and(implode("\n", $set))->not->toContain('pw-secret');
    });
});
