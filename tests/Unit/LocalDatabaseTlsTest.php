<?php

declare(strict_types=1);

/**
 * A database of this machine takes no TLS (the MySql.Data of an OpenSimulator on Mono cannot do the one a MariaDB of today offers,
 * and Robust then starts without its database): the setup writes `SslMode=None;`, an upgrade adds it, and the status sees a Robust
 * that runs without its database.
 */

use W4OS\Engine\Config\ConnectionString;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\GridCompletion;
use W4OS\Engine\Testing\Machine;

describe('A connection string to a database of this machine', function () {
    test('is written with SslMode=None, one to another server without it', function () {
        expect((string) ConnectionString::for('localhost', 'robust', 'os', 'pw'))->toEndWith('Old Guids=true;SslMode=None;')
            ->and((string) ConnectionString::for('127.0.0.1:3307', 'robust', 'os', 'pw'))->toEndWith('SslMode=None;')
            ->and((string) ConnectionString::for('db.example.org', 'robust', 'os', 'pw'))->not->toContain('SslMode')
            ->and(ConnectionString::withLocalTls('Data Source=localhost;Database=r;User ID=u;Password=p;SslMode=Required;'))->toBeNull()
            ->and(ConnectionString::withLocalTls('URI=file:grid.db,version=3'))->toBeNull()
            ->and(ConnectionString::isLocal('/run/mysqld/mysqld.sock'))->toBeTrue();
    });

    test('gets SslMode=None at an upgrade, once, the other ones and the comments left as they are', function () {
        $machine = new Machine();
        $robust = $machine->grid . '/Robust.HG.ini';
        $machine->write($robust, file_get_contents($robust) . "\n[DatabaseService]\n    ConnectionString = \"Data Source=localhost;Database=robust;User ID=os;Password=pw;Old Guids=true;\"\n"
            . "    ; ConnectionString = \"Data Source=localhost;Database=old;User ID=os;Password=pw;\"\n"
            . "[AssetService]\n    ConnectionString = \"Data Source=db.example.org;Database=assets;User ID=os;Password=pw;\"\n");
        $alpha = $machine->grid . '/sims/alpha.ini';
        $machine->write($alpha, file_get_contents($alpha) . "\n[DatabaseService]\n    ConnectionString = Data Source=127.0.0.1;Database=alpha;User ID=os;Password=pw;\n"
            . "    EstateConnectionString = \"Data Source=localhost;Database=robust;User ID=os;Password=pw;SslMode=Required;\"\n");

        $first = GridCompletion::run($machine->environment, $machine->etc);
        $second = implode("\n", GridCompletion::run($machine->environment, $machine->etc));
        $robustText = (string) file_get_contents($robust);
        $alphaText = (string) file_get_contents($alpha);

        expect(implode("\n", $first))->toContain('demo: SslMode=None added to 1 connection string of a local database in Robust.HG.ini', 'in sims/alpha.ini')
            ->and($second)->not->toContain('SslMode')
            ->and($robustText)->toContain('ConnectionString = "Data Source=localhost;Database=robust;User ID=os;Password=pw;Old Guids=true;SslMode=None;"')
            ->toContain('; ConnectionString = "Data Source=localhost;Database=old;User ID=os;Password=pw;"')
            ->toContain('ConnectionString = "Data Source=db.example.org;Database=assets;User ID=os;Password=pw;"')
            ->and($alphaText)->toContain('ConnectionString = Data Source=127.0.0.1;Database=alpha;User ID=os;Password=pw;SslMode=None;')
            ->toContain('SslMode=Required;"');
    });
});

describe('A Robust that runs', function () {
    test('is seen without its database when its grid service answers with an error, not when it does not answer yet', function () {
        $machine = new Machine();
        $robust = Grid::find($machine->environment, 'demo')->robust();
        $answers = [[200, '<?xml version="1.0"?><ServerResponse><region0 type="List"></region0></ServerResponse>'], [500, 'Object reference not set to an instance of an object'], [0, '']];
        $asked = [];
        $post = static function (string $url, array $form) use (&$answers, &$asked): array {
            $asked[] = $url . ' ' . $form['METHOD'];

            return array_shift($answers);
        };

        expect([$robust->databaseAnswers($post), $robust->databaseAnswers($post), $robust->databaseAnswers($post)])->toBe([true, false, null])
            ->and($asked[0])->toBe('http://127.0.0.1:8003/grid get_default_regions');
    });

    test('whose services did not all load since its start is in the status, as its log says it', function () {
        $machine = new Machine();
        $machine->processes->add('dotnet Robust.dll -inifile=' . $machine->etc . '/robust.d/demo.ini');
        $status = W4OS\Engine\OpenSim::status($machine->environment);
        $log = $status->instances[0]->logFile()->path();
        is_dir(dirname($log)) || mkdir(dirname($log), 0o755, true);
        file_put_contents($log, implode("\n", [
            '2026-11-26 10:00:00,000 INFO  (1) - OpenSim.Framework.Servers.ServerBase [SERVER BASE]: Logging started to file ' . $log,
            '2026-11-26 10:00:04,000 ERROR (1) - OpenSim.Server.Base.ServerUtils [SERVER UTILS]: Error loading plugin OpenSim.Services.Interfaces.IGridService from OpenSim.Services.GridService.dll. Exception: GatekeeperURI configuration error',
            '2026-11-26 11:00:00,000 INFO  (1) - OpenSim.Framework.Servers.ServerBase [SERVER BASE]: Logging started to file ' . $log,
        ]) . "\n");
        $before = $status->brokenServices();
        file_put_contents($log, '2026-11-26 11:00:04,000 ERROR (1) - OpenSim.Server.Base.ServerUtils [SERVER UTILS]: Error loading plugin OpenSim.Services.Interfaces.IGridService from OpenSim.Services.GridService.dll. Exception: GatekeeperURI configuration error' . "\n", FILE_APPEND);

        // The start before it does not count
        expect($before)->toBe([])
            ->and($status->brokenServices())->toBe(['demo' => ['OpenSim.Services.GridService.dll']]);
    });

    test('without its database is in the status, one that is stopped is not', function () {
        $machine = new Machine();
        $machine->processes->add('dotnet Robust.dll -inifile=' . $machine->etc . '/robust.d/demo.ini');
        $status = W4OS\Engine\OpenSim::status($machine->environment);
        $failing = static fn(string $url, array $form): array => [500, 'error'];

        expect($status->withoutDatabase($failing))->toBe(['demo'])
            ->and($status->withoutDatabase(static fn(string $url, array $form): array => [200, '<ServerResponse/>']))->toBe([])
            ->and(W4OS\Engine\OpenSim::status((new Machine())->environment)->withoutDatabase($failing))->toBe([]);
    });
});
