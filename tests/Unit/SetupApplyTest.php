<?php

declare(strict_types=1);

/**
 * A setup file applied to a grid of this machine without a question: a new simulator with its regions, a region given to a
 * simulator that exists. Planned (nothing written), then applied.
 */

use W4OS\Engine\Exceptions\InvalidValue;
use W4OS\Engine\Provisioning\DatabaseService;
use W4OS\Engine\Provisioning\NullUi;
use W4OS\Engine\Provisioning\SetupApply;
use W4OS\Engine\Testing\Machine;

/** A file that adds the simulator North (two regions, the first of 512 meters, a fallback) and a region to alpha. */
function setup_apply_file(array $first = []): array
{
    return [
        'grid' => ['nick' => 'demo'],
        'owner' => ['name' => 'Ada Love', 'password' => 'secret-pass'],
        'simulators' => [
            [
                'name' => 'North',
                'database' => ['user' => 'opensim', 'password' => 'secret'],
                'regions' => [
                    $first + ['name' => 'Big Garden', 'size' => 512, 'roles' => ['FallbackRegion']],
                    ['name' => 'Small Garden'],
                ],
            ],
            ['name' => 'alpha', 'regions' => [['name' => 'Alpha Two', 'location' => '1200,1200']]],
        ],
    ];
}

describe('A setup file applied', function () {
    test('is planned first: a new simulator with its regions, a region of a simulator that exists, apart, nothing written', function () {
        $machine = new Machine();
        $plan = SetupApply::prepare($machine->environment, setup_apply_file())->document();
        [$north, $small, $alpha] = $plan['steps'];

        expect($plan['problems'])
            ->toBe([])
            ->and(array_column($plan['steps'], 'kind'))
            ->toBe(['simulator', 'region', 'region'])
            ->and([$north['slug'], $north['region']['name'], $north['region']['size'], $north['region']['roles']])
            ->toBe(['demo.north', 'Big Garden', 512, ['FallbackRegion']])
            ->and([$small['slug'], $small['region']['name'], $small['region']['size']])
            ->toBe(['demo.north', 'Small Garden', 256])
            ->and([$alpha['slug'], $alpha['region']['location']])
            ->toBe(['alpha', '1200,1200'])
            // Two regions of the file never get one port or one place; the regions of a new simulator are in its block of ports
            ->and($small['region']['port'])
            ->not->toBe($north['region']['port'])
            ->and(intdiv($north['region']['port'], 10) * 10)
            ->toBe($north['ports']['http'])
            ->and(intdiv($small['region']['port'], 10) * 10)
            ->toBe($north['ports']['http'])
            ->and($small['region']['location'])
            ->not->toBe($north['region']['location'])
            ->and(array_column(array_slice($plan['reminders'], 0, 2), 'params', 'code'))
            ->toBe([
                'start-simulator' => ['slug' => 'demo.north', 'command' => 'opensim start demo.north'],
                'start-simulator-regions' => ['slug' => 'alpha', 'command' => 'opensim start alpha'],
            ])
            // The role of a region of a new simulator: Robust gives it once it has read its config, or opensim region set
            ->and($plan['reminders'][2]['code'])
            ->toBe('region-roles-later')
            ->and($plan['reminders'][2]['detail'])
            ->toContain('Big Garden', 'FallbackRegion', 'opensim restart demo', 'opensim region set demo "Big Garden" --roles FallbackRegion')
            ->and(array_filter($plan['writes'], 'file_exists'))
            ->toBe([]);
    });

    test('gives a simulator whose owner it names an estate of that owner, unless it names the estate', function () {
        $machine = new Machine();
        $file = static fn(array $estate): array => [
            'grid' => ['nick' => 'demo'],
            'simulators' => [['name' => 'North', 'estate' => $estate, 'database' => ['user' => 'opensim', 'password' => 'secret'], 'regions' => [['name' => 'Big Garden']]]],
        ];

        expect(SetupApply::prepare($machine->environment, $file(['owner' => 'Bob Root']))->steps[0]['plan']->estateName)
            ->toBe('Bob Root Estate')
            ->and(SetupApply::prepare($machine->environment, $file(['owner' => 'Bob Root', 'name' => 'Shared Land']))->steps[0]['plan']->estateName)
            ->toBe('Shared Land')
            ->and(SetupApply::prepare($machine->environment, $file([]))->steps[0]['plan']->estateName)
            ->toEndWith(' Estate')
            ->not->toBe('Bob Root Estate');
    });

    test('loads the regions of a running simulator through its console, which needs no restart', function () {
        $machine = new Machine();
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'demo');
        $machine->processes->add('dotnet OpenSim.dll -inifile=' . $grid->simulatorLink($grid->simulator('alpha')->slug()));
        $plan = SetupApply::prepare($machine->environment, ['grid' => ['nick' => 'demo'], 'simulators' => [['name' => 'alpha', 'regions' => [['name' => 'Alpha Two']]]]]);

        expect($plan->steps[0]['plan']->start)
            ->toBeTrue()
            ->and($plan->document()['reminders'])
            ->toBe([]);
    });

    test('takes over a region the grid has: a simulator made for it, its UUID, place, size and owner kept, written as it is', function () {
        $machine = new Machine();
        SetupApply::$robust = [
            'registered' => static fn(string $name): array|false => strcasecmp($name, 'Old Garden') === 0 ? [
                'uuid' => '11111111-2222-3333-4444-555555555555', 'name' => 'Old Garden', 'location' => '1300,1300', 'size' => 512,
                'owner' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'server' => 'http://old.example.org:9000/', 'flags' => 16,
            ] : false,
            'serving' => static fn(string $server): bool => false,
            'accountName' => static fn(string $uuid): string => 'Bob Root',
        ];
        $setup = SetupApply::prepare($machine->environment, [
            'grid' => ['nick' => 'demo'],
            'simulators' => [['name' => 'Moved', 'database' => ['user' => 'opensim', 'password' => 'secret'], 'regions' => [['name' => 'old garden', 'existing' => true]]]],
        ]);
        $setup->apply(new NullUi(), static fn(): int => DatabaseService::OK);
        SetupApply::$robust = [];
        $plan = $setup->document();
        $region = $plan['steps'][0]['region'];

        expect($plan['problems'])->toBe([])
            ->and([$region['name'], $region['location'], $region['size'], $region['existing'], $region['uuid']])
            ->toBe(['Old Garden', '1300,1300', 512, true, '11111111-2222-3333-4444-555555555555'])
            ->and($setup->steps[0]['plan']->estateOwner)->toBe('Bob Root')
            ->and(file_get_contents($region['file']))
            ->toContain('[Old Garden]', 'RegionUUID = 11111111-2222-3333-4444-555555555555', 'Location = 1300,1300', 'SizeX = 512');
    });

    test('loads a region with its archive once it runs, or makes the database of a new simulator from a dump, never both', function () {
        $machine = new Machine();
        $oar = test_tmp() . '/garden.oar';
        $dump = test_tmp() . '/sim.sql.gz';
        file_put_contents($oar, 'oar');
        file_put_contents($dump, 'dump');
        $file = static fn(array $north, array $alpha = []): array => [
            'grid' => ['nick' => 'demo'],
            'simulators' => [
                ['name' => 'North', 'database' => ['user' => 'opensim', 'password' => 'secret'], 'regions' => [$north + ['name' => 'Big Garden']]],
                ['name' => 'alpha', 'regions' => [$alpha + ['name' => 'Alpha Two']]],
            ],
        ];
        $file = static fn(array $north, array $alpha = []): array => ['owner' => ['name' => 'Ada Love', 'password' => 'secret-pass']] + $file($north, $alpha);
        $loaded = SetupApply::prepare($machine->environment, $file(['dump' => $dump], ['oar' => $oar]));
        $plan = $loaded->document();
        // The database is made from the dump before anything is written: what it cannot be made from stops the setup there
        $made = [];
        $failed = null;
        try {
            $loaded->apply(new NullUi(), static fn(): int => DatabaseService::OK, static function ($plan) use (&$made): int {
                $made[] = [$plan->dbName, $plan->regionDump];

                throw new RuntimeException('statement 3 failed');
            });
        } catch (InvalidValue $error) {
            $failed = $error->getMessage();
        }

        expect($plan['problems'])->toBe([])
            ->and([$plan['steps'][0]['region']['dump'], $plan['steps'][1]['region']['oar']])->toBe([$dump, $oar])
            // What is loaded is loaded once it runs: the simulators start, nothing is left to start
            ->and(array_column($plan['reminders'], 'code'))->not->toContain('start-simulator', 'start-simulator-regions')
            ->and($made)->toBe([[$loaded->steps[0]['plan']->dbName, $dump]])
            ->and($failed)->toContain("cannot be made from $dump: statement 3 failed")
            ->and(is_file($loaded->steps[0]['plan']->iniPath()))->toBeFalse()
            ->and(SetupApply::prepare($machine->environment, $file([], ['dump' => $dump]))->problems)
            ->toBe(['simulators.1.regions.0.dump' => 'the dump of a database makes the database of a new simulator: give the region a simulator of its own, or load its archive (oar)'])
            ->and(SetupApply::prepare($machine->environment, $file(['oar' => $oar, 'dump' => $dump]))->problems)
            ->toBe(['simulators.0.regions.0.oar' => 'an archive (oar) or the dump of a database (dump), not both'])
            ->and(SetupApply::prepare($machine->environment, $file(['oar' => '/nonexistent/garden.oar']))->problems)
            ->toBe(['simulators.0.regions.0.oar' => 'cannot read /nonexistent/garden.oar']);
    });

    test('gives a region made from a dump the UUID of the region of the dump, the one it takes over, and no other', function () {
        $machine = new Machine();
        $dump = static function (string ...$uuids): string {
            $file = test_tmp() . '/dump-' . count($uuids) . '.sql';
            file_put_contents($file, "-- Dump\nCREATE TABLE `regionsettings` (`regionUUID` char(36));\nINSERT INTO `regionsettings` VALUES "
                . implode(',', array_map(static fn(string $uuid): string => "('$uuid',0,'x')", $uuids)) . ";\n");

            return $file;
        };
        $one = '11111111-2222-3333-4444-555555555555';
        $file = static fn(array $region): array => [
            'grid' => ['nick' => 'demo'],
            'owner' => ['name' => 'Ada Love', 'password' => 'secret-pass'],
            'simulators' => [['name' => 'Moved', 'database' => ['user' => 'opensim', 'password' => 'secret'], 'regions' => [$region + ['name' => 'Old Garden']]]],
        ];
        SetupApply::$robust = [
            'registered' => static fn(string $name): array => ['uuid' => '99999999-2222-3333-4444-555555555555', 'name' => 'Old Garden', 'location' => '1300,1300', 'size' => 256, 'owner' => '', 'server' => '', 'flags' => 16],
            'registeredName' => static fn(string $uuid): string|false => $uuid === '22222222-2222-3333-4444-555555555555' ? 'Other Place' : false,
        ];
        $new = SetupApply::prepare($machine->environment, $file(['dump' => $dump($one)]));
        $several = SetupApply::prepare($machine->environment, $file(['dump' => $dump($one, '33333333-2222-3333-4444-555555555555')]))->problems;
        // mysqldump of MariaDB: a row on each line after the INSERT
        $lines = test_tmp() . '/dump-lines.sql';
        file_put_contents($lines, "INSERT INTO `regionsettings` VALUES\n('$one',0,'x'),\n('33333333-2222-3333-4444-555555555555',0,'y');\nINSERT INTO `land` VALUES\n('44444444-2222-3333-4444-555555555555',0);\n");
        $other = SetupApply::prepare($machine->environment, $file(['dump' => $dump('22222222-2222-3333-4444-555555555555')]))->problems;
        $takenOver = SetupApply::prepare($machine->environment, $file(['dump' => $dump($one), 'existing' => true]))->problems;
        SetupApply::$robust = [];

        expect([$new->problems, $new->steps[0]['plan']->regionUuid])->toBe([[], $one])
            ->and($several['simulators.0.regions.0.dump'])->toStartWith('the dump has 2 regions')
            ->and(W4OS\Engine\Archives\DatabaseDump::regionUuids($lines))->toBe([$one, '33333333-2222-3333-4444-555555555555'])
            ->and($other['simulators.0.regions.0.dump'])->toContain('the region Other Place of the grid')
            ->and($takenOver['simulators.0.regions.0.dump'])->toStartWith('the dump has no region 99999999-2222-3333-4444-555555555555');
    });

    test('is written: the database of the new simulator first, then its files and the regions', function () {
        $machine = new Machine();
        $setup = SetupApply::prepare($machine->environment, setup_apply_file());
        $made = [];
        $setup->apply(new NullUi(), static function ($db) use (&$made): int {
            $made[] = $db->dbName;

            return DatabaseService::OK;
        });
        $plan = $setup->document();

        expect($made)
            ->toHaveCount(1)
            ->and(array_filter($plan['writes'], 'is_file'))
            ->toBe($plan['writes'])
            ->and(file_get_contents($plan['steps'][0]['region']['file']))
            ->toContain('SizeX = 512');
    });

    test('makes the database of a new simulator with the administrator the file gives, never shown', function () {
        $machine = new Machine();
        $file = setup_apply_file();
        $file['simulators'][0]['database'] += ['admin_user' => 'root', 'admin_password' => 'admin-secret'];
        $setup = SetupApply::prepare($machine->environment, $file);
        $setup->apply(new NullUi(), static fn(): int => DatabaseService::OK);
        $admins = (new ReflectionProperty(DatabaseService::class, 'admins'))->getValue();

        expect($admins[$setup->steps[0]['plan']->dbHost] ?? null)
            ->toBe(['root', 'admin-secret'])
            ->and(json_encode($setup->document()))
            ->not->toContain('admin-secret');
    });

    test('says why a database cannot be had, and how to give an administrator', function () {
        $machine = new Machine();
        $setup = SetupApply::prepare($machine->environment, setup_apply_file());

        expect(fn() => $setup->apply(new NullUi(), static function ($db, $ui): int {
            $ui->error('Could not create the database: access denied');
            $ui->note("From an account with administrator rights on the database server, run what is missing:\n  CREATE DATABASE `demo_north`;");

            return DatabaseService::ABORT;
        }))->toThrow(InvalidValue::class, 'CREATE DATABASE `demo_north`')
            ->and(fn() => $setup->apply(new NullUi(), static fn(): int => DatabaseService::ABORT))
            ->toThrow(InvalidValue::class, 'admin_user');
    });

    test('says what is wrong by its place in the file, and is not written then; a file that makes a grid is refused', function () {
        $machine = new Machine();
        $setup = SetupApply::prepare($machine->environment, setup_apply_file(['size' => 300]));

        expect($setup->problems)
            ->toHaveKey('simulators.0.regions.0.size')
            ->and(fn() => $setup->apply(new NullUi(), static fn(): int => DatabaseService::OK))
            ->toThrow(InvalidValue::class, 'simulators.0.regions.0.size')
            ->and(fn() => SetupApply::prepare($machine->environment, ['grid' => ['name' => 'New World', 'nick' => 'new_world']]))
            ->toThrow(InvalidValue::class, 'this file makes a grid');
    });
});

describe('The API, for a setup file', function () {
    test('plans the file it is given as text, and says what is wrong', function () {
        $machine = new Machine();
        $api = new W4OS\Engine\Api\Api($machine->environment);

        $plan = $api->setupFile(['text' => json_encode(setup_apply_file())]);
        $wrong = $api->setupFile(['setup' => setup_apply_file(['size' => 300]), 'mode' => 'apply']);
        $grid = $api->setupFile(['setup' => ['grid' => ['name' => 'New World', 'nick' => 'new_world']]]);

        expect([$plan->status, $plan->document['data']['attributes']['applied'], count($plan->document['data']['attributes']['steps'])])
            ->toBe([200, false, 3])
            // A plan reserves nothing: what it proposes is chosen again when it is applied
            ->and($plan->document['data']['attributes']['notes'][0]['code'])
            ->toBe('plan-reserves-nothing')
            ->and([$wrong->status, $wrong->document['code'] ?? null])
            ->toBe([400, 'invalid-request'])
            ->and($wrong->document['detail'] ?? '')
            ->toContain('simulators.0.regions.0.size')
            ->and($grid->status)
            ->toBe(400);
    });
});
