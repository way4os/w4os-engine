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

    test('database, fixed where the money server reads it when this process cannot write it, its lines without the password', function () {
        [$machine, $router] = provided_grid('', "[MySql]\n    hostname = localhost\n    database = money\n    username = opensim\n    password = s3cret\n");
        chmod($machine->grid . '/MoneyServer.ini', 0o444);

        $fixed = $router->get('/grids/demo/settings/economy')->document['meta']['fixed']['moneyDatabase'] ?? [];
        chmod($machine->grid . '/MoneyServer.ini', 0o644);

        expect($fixed)->toMatchArray(['file' => 'MoneyServer.ini'])
            ->and($fixed['reason']['code'] ?? null)->toBe('setting-file-unwritable')
            ->and($fixed['text'] ?? '')->toContain('[MySql]')->toContain('hostname = localhost')->toContain('database = money')
            ->not->toContain('s3cret');
    })->skip(fn() => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes everywhere');
});

describe('The database of the money server', function () {
    test('is the field of every database, its Use default the main database of the grid, written as its own keys', function () {
        [$machine, $router] = provided_grid('', "[MoneyServer]\n    EnableScriptSendMoney = true\n");
        $machine->write($machine->grid . '/Robust.HG.ini', file_get_contents($machine->grid . '/Robust.HG.ini') . "\n[DatabaseService]\n    ConnectionString = \"Data Source=db.example.org;Port=3307;Database=robust;User ID=opensim;Password=robust-pass;\"\n");
        $read = static fn(): mixed => $router->get('/grids/demo/settings/economy')->document['data']['attributes']['moneyDatabase'];

        $done = $router->patch('/grids/demo/settings/economy', ['data' => ['type' => 'settings', 'id' => 'economy', 'attributes' => ['economyModule' => 'DTLNSLMoneyModule', 'moneyDatabase' => ['useDefault' => true]]]]);
        $ini = file_get_contents($machine->grid . '/MoneyServer.ini');
        $default = $read();
        $router->patch('/grids/demo/settings/economy', ['data' => ['type' => 'settings', 'id' => 'economy', 'attributes' => ['moneyDatabase' => ['host' => 'money.example.org', 'database' => 'money', 'user' => 'money', 'password' => 'money-pass']]]]);

        expect(provided_fields('economy')['moneyDatabase'])->toMatchArray(['type' => 'connection-string', 'fallback' => 'db_use_default'])
            ->and($done->status)->toBe(200)
            // The money server reads its own keys: the ones of the main database, written for it
            ->and($ini)->toContain('hostname = db.example.org')->toContain('port = 3307')->toContain('database = robust')->toContain('username = opensim')->toContain('password = robust-pass')
            ->and($default)->toBeNull()
            ->and($read())->toMatchArray(['host' => 'money.example.org', 'database' => 'money', 'user' => 'money']);
    });
});
