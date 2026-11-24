<?php

declare(strict_types=1);

/**
 * The avatars in world the status says: people only (a NPC never reaches Presence), by grid and by simulator that runs.
 */

use W4OS\Engine\Data\Connections;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\OpenSim;
use W4OS\Engine\Testing\Machine;

describe('The avatars of the status', function () {
    /** The Robust of demo and its simulator alpha run, beta is down; who Presence says is where. */
    $running = static function (): Machine {
        $machine = new Machine();
        $machine->processes->add('dotnet Robust.dll -inifile=' . $machine->etc . '/robust.d/demo.ini');
        $machine->processes->add('dotnet OpenSim.dll -inifile=' . $machine->etc . '/opensim.d/alpha.ini');

        return $machine;
    };
    $presence = static fn(?array $rows): \Closure => static fn(Grid $grid): ?array => $rows === null ? null : $grid->withoutStopped($rows);
    $rows = [
        // alpha's Plaza: two members and a visitor
        '11111111-1111-4111-8111-111111111111' => ['members' => 2, 'visitors' => 1],
        // beta's Plaza: a row left by a simulator that is down, nobody is there
        '11111111-1111-4111-8111-111111111112' => ['members' => 1, 'visitors' => 0],
        // a region of a simulator of another machine
        '22222222-2222-4222-8222-222222222222' => ['members' => 1, 'visitors' => 0],
    ];

    test('are counted by grid and by simulator that runs, never in the regions of a simulator that is down', function () use ($running, $presence, $rows) {
        $status = OpenSim::status($running()->environment);

        expect($status->avatars($presence($rows), []))->toBe(['grids' => ['demo' => 4], 'instances' => ['demo' => 4, 'alpha' => 3]])
            // 0 is a number, said as one
            ->and($status->avatars($presence([]), []))->toBe(['grids' => ['demo' => 0], 'instances' => ['demo' => 0, 'alpha' => 0]]);
    });

    test('are unknown when the databases of the grid cannot be read, or its Robust runs without its database', function () use ($running, $presence, $rows) {
        $status = OpenSim::status($running()->environment);

        expect($status->avatars($presence(null), []))->toBe(['grids' => ['demo' => null], 'instances' => ['demo' => null, 'alpha' => null]])
            ->and($status->avatars($presence($rows), ['demo']))->toBe(['grids' => ['demo' => null], 'instances' => ['demo' => null, 'alpha' => null]]);
    });
    test('are the ones the API gives of the grid: the status shows the figure of the engine', function () use ($running) {
        $machine = $running();
        $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        $pdo->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36), active INT DEFAULT 1)');
        $pdo->exec("INSERT INTO UserAccounts (PrincipalID) VALUES ('aaaaaaaa-0000-4000-8000-000000000001'), ('aaaaaaaa-0000-4000-8000-000000000002')");
        $pdo->exec('CREATE TABLE Presence (UserID VARCHAR(255), RegionID CHAR(36))');
        // A member and a visitor in alpha's Plaza, a member in beta's (down: a row left), one in a region of another machine, one logged out
        $pdo->exec("INSERT INTO Presence VALUES ('aaaaaaaa-0000-4000-8000-000000000001', '11111111-1111-4111-8111-111111111111'), ('bbbbbbbb-0000-4000-8000-000000000009;http://other.example.org;Vis', '11111111-1111-4111-8111-111111111111'), " .
            "('aaaaaaaa-0000-4000-8000-000000000002', '11111111-1111-4111-8111-111111111112'), ('cccccccc-0000-4000-8000-000000000003', '22222222-2222-4222-8222-222222222222'), ('dddddddd-0000-4000-8000-000000000004', '00000000-0000-0000-0000-000000000000')");
        Connections::using(static fn() => $pdo);
        try {
            $api = (new W4OS\Engine\Api\Router(new W4OS\Engine\Api\Api($machine->environment)))->get('/grids/demo')->document['data']['attributes']['avatars'] ?? 'none';
            $status = OpenSim::status($machine->environment)->avatars(null, []);
        } finally {
            Connections::using(null);
        }

        expect($api)->toBe(3)
            ->and($status)->toBe(['grids' => ['demo' => 3], 'instances' => ['demo' => 3, 'alpha' => 2]]);
    })->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');
});
