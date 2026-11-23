<?php

declare(strict_types=1);

/**
 * The port of the engine API of a grid (Network\ApiPort): one of its block, kept with it, the one a web server serves already kept.
 */

use W4OS\Engine\Config\Layout;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Network\ApiPort;
use W4OS\Engine\Network\Ports;
use W4OS\Engine\Testing\Machine;

describe('The port of the engine API of a grid', function () {
    test('is the first free one of its block after its own, its console left to it, kept in its conf', function () {
        $machine = new Machine();
        $grid = Grid::find($machine->environment, 'demo');
        $conf = $machine->grid . '/demo.conf';

        $port = $grid->apiPort();

        expect([$grid->publicPort(), $grid->privatePort()])->toBe([8002, 8003])
            ->and($port)->toBe(8005)
            ->and(file_get_contents($conf))->toContain('ApiPort = 8005')
            ->and(Grid::find($machine->environment, 'demo')->knownApiPort())->toBe(8005)
            // Taken: no other grid nor simulator gets it
            ->and((new Ports($machine->environment))->isFree(8005))->toBeFalse()
            ->and(ApiPort::free($machine->environment, 8002, 8003))->toBe(8006);
    });

    test('is the one a web server of this machine serves already, and nothing is allocated to list it', function () {
        $machine = new Machine();
        $etc = test_tmp() . '/etc-' . bin2hex(random_bytes(4));
        mkdir("$etc/apache2/conf-available", 0o755, true);
        file_put_contents("$etc/apache2/conf-available/opensim-api-demo.conf", "Listen 127.0.0.1:8780\n<VirtualHost 127.0.0.1:8780>\n\tSetEnv OPENSIM_GRID demo\n</VirtualHost>\n");
        $conf = $machine->grid . '/demo.conf';
        $before = is_file($conf) ? file_get_contents($conf) : '';

        $listed = ApiPort::known($conf, 'Grid', 'demo', $etc);
        $unwritten = is_file($conf) ? file_get_contents($conf) : '';
        $kept = ApiPort::of($machine->environment, $conf, 'Grid', 'demo', 8002, 8003, $etc);

        expect($listed)->toBe(8780)
            ->and($unwritten)->toBe($before)
            ->and(ApiPort::served('other', $etc))->toBeNull()
            ->and($kept)->toBe(8780)
            ->and(file_get_contents($conf))->toContain('ApiPort = 8780');
    });

    test('of a grid in place is kept in its section of the config of the suite, never in a file of the install', function () {
        $machine = new Machine();
        $conf = $machine->etc . '/' . Layout::FILE;
        file_put_contents($conf, "[Defaults]\nEtcRoot = {$machine->etc}\n\n[Grid oldgrid]\nRobustIni = /opt/old/Robust.ini\n");

        $port = ApiPort::of($machine->environment, $conf, 'Grid oldgrid', 'oldgrid', 8102, 8103);

        expect($port)->toBe(8105)
            ->and(\W4OS\Engine\Config\InPlaceGrids::section($conf, 'oldgrid')['ApiPort'] ?? null)->toBe('8105');
    });
});
