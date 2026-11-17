<?php

declare(strict_types=1);

/**
 * A backup put back on a machine (BackupRestore): planned, then written, its links pointing to the configuration of this machine, its
 * databases made again from their dumps; refused when this machine has what it holds.
 */

use OpenSim\Archives\BackupRestore;
use OpenSim\Archives\BackupService;
use OpenSim\Exceptions\InvalidValue;
use OpenSim\Testing\EnvironmentFake;

/** An install with the grid alpha (its Robust, two simulators), and its backup made. @return array{0:array<string,string>,1:string} */
function restore_backup(?string $simulator = null, bool $central = false): array
{
    $root = test_tmp() . '/restore-' . bin2hex(random_bytes(4));
    $etc = "$root/etc";
    $grid = "$etc/grids/alpha";
    foreach (["$grid/sims/alpha.sim1/regions", "$grid/sims/alpha.sim2/regions", "$etc/robust.d", "$etc/opensim.d", "$root/data/alpha/alpha.sim1", "$root/logs", "$root/backups"] as $dir) {
        mkdir($dir, 0o755, true);
    }
    // Robust serves the estates (EstateDataService): they are in its database, which a simulator shares
    file_put_contents("$grid/Robust.HG.ini", ($central ? "[ServiceList]\nEstateDataService = \"8003/OpenSim.Server.Handlers.dll:EstateDataRobustConnector\"\n" : '') . "[DatabaseService]\nConnectionString = \"Data Source=localhost;Database=alpha_robust;User ID=opensim;Password=secret;Old Guids=true;\"\n");
    foreach (['alpha.sim1', 'alpha.sim2'] as $sim) {
        file_put_contents("$grid/sims/$sim.ini", "[DatabaseService]\nConnectionString = \"Data Source=localhost;Database=" . str_replace('.', '_', $sim) . ";User ID=opensim;Password=secret;Old Guids=true;\"\n");
        symlink("$grid/sims/$sim.ini", "$etc/opensim.d/$sim.ini");
    }
    symlink("$grid/Robust.HG.ini", "$etc/robust.d/alpha.ini");
    file_put_contents("$grid/sims/alpha.sim1/regions/Welcome.ini", "[Welcome]\nRegionUUID = 11111111-0000-4000-8000-000000000001\nLocation = 1000,1000\n");
    file_put_contents("$grid/sims/alpha.sim2/regions/North.ini", "[North]\nLocation = 1000,1001\n");
    file_put_contents("$root/data/alpha/alpha.sim1/state", 'persistent');
    $profile = ['EtcRoot' => $etc, 'DataRoot' => "$root/data", 'LogsRoot' => "$root/logs", 'BackupRoot' => "$root/backups"];
    $files = (new BackupService(
        EnvironmentFake::with($profile),
        static function (array $db, string $file): void {
            file_put_contents($file, gzencode("-- dump of {$db['name']}\n"));
        },
        static function (string $message): void {},
        '20261116-120000',
    ))->run($simulator === null ? ['alpha'] : ['alpha', $simulator]);

    return [$profile, $files[0]];
}

/** An empty install elsewhere. @return array<string,string> */
function restore_target(): array
{
    $root = test_tmp() . '/restored-' . bin2hex(random_bytes(4));
    foreach (["$root/etc/robust.d", "$root/etc/opensim.d", "$root/data", "$root/logs"] as $dir) {
        mkdir($dir, 0o755, true);
    }

    return ['EtcRoot' => "$root/etc", 'DataRoot' => "$root/data", 'LogsRoot' => "$root/logs"];
}

describe('A backup restored', function () {
    test('is planned first: what it writes and where, its databases, the instances to start, nothing written', function () {
        [, $file] = restore_backup();
        $target = restore_target();
        $plan = BackupRestore::prepare(EnvironmentFake::with($target), $file)->document();

        expect([$plan['grid'], $plan['simulator']])
            ->toBe(['alpha', null])
            ->and(array_column($plan['databases'], 'name'))
            ->toBe(['alpha_robust', 'alpha_sim1', 'alpha_sim2'])
            ->and($plan['writes'])
            ->toContain("{$target['EtcRoot']}/grids/alpha/Robust.HG.ini", "{$target['EtcRoot']}/robust.d/alpha.ini", "{$target['DataRoot']}/alpha/alpha.sim1/state")
            ->and(array_column($plan['reminders'], 'code'))
            ->toBe(['restore-start', 'restore-start', 'restore-start'])
            ->and(is_dir("{$target['EtcRoot']}/grids/alpha"))
            ->toBeFalse();
    });

    test('is written: its config here, its links pointing to it, its data, each database from its dump', function () {
        [, $file] = restore_backup();
        $target = restore_target();
        $loaded = [];
        BackupRestore::prepare(EnvironmentFake::with($target), $file)->apply(static function ($db, string $dump) use (&$loaded): void {
            $loaded[$db->database] = [$db->user, $db->password, (string) gzdecode((string) file_get_contents($dump))];
        });

        expect(readlink("{$target['EtcRoot']}/robust.d/alpha.ini"))
            ->toBe("{$target['EtcRoot']}/grids/alpha/Robust.HG.ini")
            ->and(is_file("{$target['EtcRoot']}/opensim.d/alpha.sim2.ini"))
            ->toBeTrue()
            ->and(file_get_contents("{$target['DataRoot']}/alpha/alpha.sim1/state"))
            ->toBe('persistent')
            ->and($loaded['alpha_sim1'])
            ->toBe(['opensim', 'secret', "-- dump of alpha_sim1\n"])
            ->and(array_keys($loaded))
            ->toBe(['alpha_robust', 'alpha_sim1', 'alpha_sim2']);
    });

    test('of one simulator joins its grid here, the files of the grid kept as they are', function () {
        [, $file] = restore_backup('sim1');
        $target = restore_target();
        mkdir("{$target['EtcRoot']}/grids/alpha", 0o755, true);
        file_put_contents("{$target['EtcRoot']}/grids/alpha/Robust.HG.ini", "; the grid of this machine\n");
        $restore = BackupRestore::prepare(EnvironmentFake::with($target), $file);
        $restore->apply(static function (): void {});

        expect($restore->simulator())
            ->toBe('alpha.sim1')
            ->and($restore->document()['kept'])
            ->toContain("{$target['EtcRoot']}/grids/alpha/Robust.HG.ini")
            ->and(file_get_contents("{$target['EtcRoot']}/grids/alpha/Robust.HG.ini"))
            ->toBe("; the grid of this machine\n")
            ->and(is_file("{$target['EtcRoot']}/grids/alpha/sims/alpha.sim1/regions/Welcome.ini"))
            ->toBeTrue();
    });

    test('of one simulator keeps the database of the grid it shares when the grid is here, its regions found in its estates', function () {
        [$source, $file] = restore_backup('sim1', central: true);
        $target = restore_target();
        mkdir("{$target['EtcRoot']}/grids/alpha", 0o755, true);
        copy("{$source['EtcRoot']}/grids/alpha/Robust.HG.ini", "{$target['EtcRoot']}/grids/alpha/Robust.HG.ini");
        $estates = new OpenSim\Data\OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        $estates->exec('CREATE TABLE estate_settings (EstateID INTEGER PRIMARY KEY, EstateName VARCHAR(64))');
        $estates->exec('CREATE TABLE estate_map (RegionID CHAR(36) PRIMARY KEY, EstateID INT)');
        $estates->exec("INSERT INTO estate_settings VALUES (1, 'Alpha Estate')");
        $estates->exec("INSERT INTO estate_map VALUES ('11111111-0000-4000-8000-000000000001', 1)");
        OpenSim\Data\Connections::using(static fn() => $estates);
        try {
            $restore = BackupRestore::prepare(EnvironmentFake::with($target), $file);
            $loaded = [];
            $restore->apply(static function ($db) use (&$loaded): void {
                $loaded[] = $db->database;
            });
            $plan = $restore->document();
        } finally {
            OpenSim\Data\Connections::using(null);
        }

        expect(array_column($plan['databases'], 'kept', 'name'))
            ->toBe(['alpha_sim1' => false, 'alpha_robust' => true])
            ->and($loaded)
            ->toBe(['alpha_sim1'])
            ->and(array_column($plan['reminders'], 'code'))
            ->toBe(['restore-start']);
    });

    test('of one simulator says when the grid here has no estate for its regions: they would start with none', function () {
        [$source, $file] = restore_backup('sim1', central: true);
        $target = restore_target();
        mkdir("{$target['EtcRoot']}/grids/alpha", 0o755, true);
        copy("{$source['EtcRoot']}/grids/alpha/Robust.HG.ini", "{$target['EtcRoot']}/grids/alpha/Robust.HG.ini");
        $estates = new OpenSim\Data\OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        $estates->exec('CREATE TABLE estate_settings (EstateID INTEGER PRIMARY KEY, EstateName VARCHAR(64))');
        $estates->exec('CREATE TABLE estate_map (RegionID CHAR(36) PRIMARY KEY, EstateID INT)');
        OpenSim\Data\Connections::using(static fn() => $estates);
        try {
            $plan = BackupRestore::prepare(EnvironmentFake::with($target), $file)->document();
        } finally {
            OpenSim\Data\Connections::using(null);
        }
        $notice = array_column($plan['reminders'], null, 'code')['restore-estate-missing'] ?? null;

        expect($notice['params'] ?? null)
            ->toBe(['regions' => 'Welcome', 'database' => 'alpha_robust']);
    });

    test('is refused when this machine has the grid, or the simulator, already, and for a file that is not a backup', function () {
        [$source, $file] = restore_backup();
        [, $one] = restore_backup('sim1');
        $not = test_tmp() . '/not-a-backup-' . bin2hex(random_bytes(4)) . '.tar.gz';
        file_put_contents($not, 'nothing');

        expect(fn() => BackupRestore::prepare(EnvironmentFake::with($source), $file))
            ->toThrow(fn(InvalidValue $e) => expect($e->reason)->toBe('restore-conflict'))
            ->and(fn() => BackupRestore::prepare(EnvironmentFake::with($source), $one))
            ->toThrow(fn(InvalidValue $e) => expect($e->reason)->toBe('restore-conflict'))
            ->and(fn() => BackupRestore::prepare(EnvironmentFake::with(restore_target()), $not))
            ->toThrow(fn(InvalidValue $e) => expect($e->reason)->toBe('invalid-request'));
    });
});

describe('The API, for the backups', function () {
    test('makes the backup of a grid, which the list of the archives then has', function () {
        [$profile] = restore_backup();
        $api = new OpenSim\Api\Api(EnvironmentFake::with($profile), dumper: static function (array $db, string $file): void {
            file_put_contents($file, gzencode("-- dump of {$db['name']}\n"));
        });

        $made = $api->create('archives', ['kind' => 'backup', 'grid' => 'alpha']);
        $list = $api->list('archives', ['filter' => ['kind' => 'backup']]);

        expect($made->status)
            ->toBe(201)
            ->and($made->document['data']['attributes']['kind'])
            ->toBe('backup')
            ->and(is_file($made->document['data']['attributes']['file']))
            ->toBeTrue()
            ->and(count($list->document['data']))
            ->toBe(2);
    });

    test('plans a restore by default, applies it when asked, refuses what is here already', function () {
        [, $file] = restore_backup();
        $target = restore_target();
        $api = new OpenSim\Api\Api(EnvironmentFake::with($target), restoreLoader: static function (): void {});

        $plan = $api->restore(['file' => $file]);
        $written = is_dir("{$target['EtcRoot']}/grids/alpha");
        $applied = $api->restore(['file' => $file, 'mode' => 'apply']);
        $again = $api->restore(['file' => $file]);

        expect([$plan->status, $plan->document['data']['attributes']['applied'], $written])
            ->toBe([200, false, false])
            ->and([$applied->status, $applied->document['data']['attributes']['applied']])
            ->toBe([200, true])
            ->and(is_file("{$target['EtcRoot']}/grids/alpha/Robust.HG.ini"))
            ->toBeTrue()
            ->and([$again->status, $again->document['code']])
            ->toBe([400, 'restore-conflict'])
            ->and($api->restore(['archive' => 'nothing_backup_here'])->status)
            ->toBe(404);
    });

    test('refuses to write a backup or a restore when this process is not the user of the instances', function () {
        [$profile, $file] = restore_backup();
        $api = new OpenSim\Api\Api(EnvironmentFake::with(restore_target()), instanceAccess: static fn(): ?string => 'this process runs as www-data');

        expect($api->restore(['file' => $file, 'mode' => 'apply'])->document['code'])
            ->toBe('instance-user-required')
            ->and($api->restore(['file' => $file])->status)
            ->toBe(200);
    });
});
