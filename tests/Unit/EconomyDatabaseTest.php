<?php

declare(strict_types=1);

/**
 * The database of the economy is the one its module reads (provided_grid() of ProvideChoicesTest): the [MySql] of MoneyServer.ini
 * for the generic money server and Podex, GLBSpecificConnectionString of [Gloebit] for Gloebit, which uses the database of the
 * simulator by default.
 */

use OpenSim\Config\Settings;

describe('The money of a grid', function () {
    test('keeps its database where its module reads it: the [MySql] of the money server, the connection of Gloebit', function () {
        [$machine, $router] = provided_grid(
            "[Gloebit]\n    GLBSpecificConnectionString = \"Data Source=db.example.org;Database=gloebit;User ID=glb;Password=pw;Old Guids=true;\"\n",
            "[MySql]\n    hostname = localhost\n    database = money\n    username = opensim\n    password = s3cret\n",
        );
        $fields = provided_fields('economy');

        $read = $router->get('/grids/demo/settings/economy')->document['data']['attributes'];
        $money = $router->patch('/grids/demo/settings/economy', ['data' => ['type' => 'settings', 'id' => 'economy', 'attributes' => [
            'moneyDatabase' => ['host' => 'db.example.org', 'port' => 3307, 'database' => 'currency', 'user' => 'money', 'password' => Settings::MASK],
        ]]]);
        $default = $router->patch('/grids/demo/settings/economy', ['data' => ['type' => 'settings', 'id' => 'economy', 'attributes' => [
            'gloebitDatabase' => ['useDefault' => true],
        ]]]);
        $ini = file_get_contents($machine->grid . '/MoneyServer.ini');

        expect($fields['moneyDatabase'])->toMatchArray(['type' => 'connection-string', 'label' => 'economy_database', 'condition' => ['provide' => [true], 'economyModule' => ['DTLNSLMoneyModule', 'Podex']]])
            ->and($fields['moneyDatabase'])->not->toHaveKey('fallback')
            ->and($fields['gloebitDatabase'])->toMatchArray(['type' => 'connection-string', 'label' => 'economy_database', 'fallback' => 'db_use_simulator', 'condition' => ['provide' => [true], 'economyModule' => ['Gloebit']]])
            // The search uses the database of Robust by default
            ->and(provided_fields('search')['searchDatabase']['fallback'] ?? null)->toBe('db_use_default')
            ->and($read['moneyDatabase'])->toMatchArray(['host' => 'localhost', 'database' => 'money', 'user' => 'opensim', 'password' => Settings::MASK])
            ->and($read['gloebitDatabase'])->toMatchArray(['host' => 'db.example.org', 'database' => 'gloebit', 'password' => Settings::MASK])
            ->and($money->status)->toBe(200)
            ->and($ini)->toContain('hostname = db.example.org')->toContain('port = 3307')->toContain('database = currency')->toContain('username = money')
            // The mask sent back keeps the password
            ->and($ini)->toContain('password = s3cret')
            ->and($default->document['data']['attributes']['gloebitDatabase'])->toBeNull()
            ->and(file_get_contents($machine->grid . '/OpenSim.ini'))->not->toContain('GLBSpecificConnectionString');
    });

    test('database, kept by the site where the money server cannot be written, its lines to apply by hand without the password', function () {
        [$machine] = provided_grid('', "[MySql]\n    hostname = localhost\n    database = money\n    username = opensim\n    password = s3cret\n");
        $store = new OpenSim\Config\ArraySettings();
        $router = new OpenSim\Api\Router(new OpenSim\Api\Api($machine->environment->withSettings($store)));
        chmod($machine->grid . '/MoneyServer.ini', 0o444);

        $done = $router->patch('/grids/demo/settings/economy', ['data' => ['type' => 'settings', 'id' => 'economy', 'attributes' => [
            'moneyDatabase' => ['host' => 'db.example.org', 'database' => 'currency', 'user' => 'money', 'password' => 'n3w'],
        ]]]);
        $byHand = $router->get('/grids/demo/settings/economy')->document['meta']['byHand']['moneyDatabase'] ?? [];
        chmod($machine->grid . '/MoneyServer.ini', 0o644);

        expect($done->status)->toBe(200)
            ->and($store->all()['MySql'] ?? [])->toMatchArray(['hostname' => 'db.example.org', 'database' => 'currency', 'username' => 'money', 'password' => 'n3w'])
            ->and(file_get_contents($machine->grid . '/MoneyServer.ini'))->toContain('database = money')
            ->and($byHand)->toMatchArray(['file' => 'MoneyServer.ini'])
            ->and($byHand['reason']['code'] ?? null)->toBe('setting-file-unwritable')
            ->and($byHand['text'] ?? '')->toContain('[MySql]')->toContain('hostname = db.example.org')->toContain('database = currency')
            ->not->toContain('n3w')->not->toContain('s3cret');
    })->skip(fn() => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes everywhere');
});
