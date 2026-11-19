<?php

declare(strict_types=1);

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
        $v2Lines = $router->get('/grids/demo/settings/offline')->document['meta']['snippets']['offlineModule'][0]['text'] ?? '';
        $helpers = address_patch($router, 'offline', ['provide' => false, 'offlineModule' => 'OfflineMessageModule', 'offlineMessageUrl' => 'https://play.example.org/helpers/offline.php']);
        $v1 = address_patch($router, 'offline', ['provide' => false, 'offlineModule' => 'OfflineMessageModule', 'offlineMessageUrl' => 'https://im.example.org/offline.php']);
        $v1Lines = $router->get('/grids/demo/settings/offline')->document['meta']['snippets']['offlineModule'][0]['text'] ?? '';
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
