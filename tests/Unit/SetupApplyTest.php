<?php

declare(strict_types=1);

/**
 * A setup file applied to a grid of this machine without a question: a new simulator with its regions, a region given to a
 * simulator that exists. Planned (nothing written), then applied.
 */

use OpenSim\Exceptions\InvalidValue;
use OpenSim\Provisioning\DatabaseService;
use OpenSim\Provisioning\NullUi;
use OpenSim\Provisioning\SetupApply;
use OpenSim\Testing\Machine;

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
            ->and(array_slice($plan['reminders'], 0, 2))
            ->toBe(['opensim start demo.north', 'opensim start alpha (it loads its new regions when it starts)'])
            // The role of a region of a new simulator: Robust gives it once it has read its config, or opensim region set
            ->and($plan['reminders'][2])
            ->toContain('Big Garden', 'FallbackRegion', 'opensim restart demo', 'opensim region set demo "Big Garden" --roles FallbackRegion')
            ->and(array_filter($plan['writes'], 'file_exists'))
            ->toBe([]);
    });

    test('loads the regions of a running simulator through its console, which needs no restart', function () {
        $machine = new Machine();
        $grid = OpenSim\Models\Grid::find($machine->environment, 'demo');
        $machine->processes->add('dotnet OpenSim.dll -inifile=' . $grid->simulatorLink($grid->simulator('alpha')->slug()));
        $plan = SetupApply::prepare($machine->environment, ['grid' => ['nick' => 'demo'], 'simulators' => [['name' => 'alpha', 'regions' => [['name' => 'Alpha Two']]]]]);

        expect($plan->steps[0]['plan']->start)
            ->toBeTrue()
            ->and($plan->document()['reminders'])
            ->toBe([]);
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
        $api = new OpenSim\Api\Api($machine->environment);

        $plan = $api->setupFile(['text' => json_encode(setup_apply_file())]);
        $wrong = $api->setupFile(['setup' => setup_apply_file(['size' => 300]), 'mode' => 'apply']);
        $grid = $api->setupFile(['setup' => ['grid' => ['name' => 'New World', 'nick' => 'new_world']]]);

        expect([$plan->status, $plan->document['data']['attributes']['applied'], count($plan->document['data']['attributes']['steps'])])
            ->toBe([200, false, 3])
            // A plan reserves nothing: what it proposes is chosen again when it is applied
            ->and($plan->document['data']['attributes']['notes'][0])
            ->toContain('reserves nothing')
            ->and([$wrong->status, $wrong->document['code'] ?? null])
            ->toBe([400, 'invalid-request'])
            ->and($wrong->document['detail'] ?? '')
            ->toContain('simulators.0.regions.0.size')
            ->and($grid->status)
            ->toBe(400);
    });
});
