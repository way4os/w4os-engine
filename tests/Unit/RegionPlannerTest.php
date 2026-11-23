<?php

declare(strict_types=1);

/**
 * From the answers about a region to what its simulator plan says: the name, the roles, the place, the port.
 */

use W4OS\Engine\Models\Grid;
use W4OS\Engine\Models\Region;
use W4OS\Engine\Provisioning\RegionPlanner;
use W4OS\Engine\Provisioning\SimulatorPlan;
use W4OS\Engine\Testing\EnvironmentFake;

/** A grid of this machine at 8002 whose center is 100,100, with Hypergrid and no region yet. */
function region_grid(array $set = []): Grid
{
    return Grid::described(EnvironmentFake::with(), [
        'nick' => 'demo',
        'name' => 'Demo',
        'directory' => '/nonexistent/grids/demo',
        'center' => '100,100',
    ])->with($set);
}

/** A planner whose random things are not: a name by a counter, a port by the block. */
function region_planner(array $more = []): RegionPlanner
{
    $count = 0;

    return RegionPlanner::with(
        $more + [
            'grid' => region_grid(),
            'simulatorName' => 'Sim1',
            'httpPort' => 9000,
            'randomName' => static function (Closure $problem) use (&$count): string {
                do {
                    $name = 'Random Name ' . ++$count;
                } while ($problem($name) !== null);

                return $name;
            },
            'uuid' => static fn(): string => '11111111-2222-4333-8444-555555555555',
            'nextPort' => static function (int $http, array $taken): int {
                for ($port = $http + 1; in_array($port, $taken, true) || $port % 10 === 4; $port++) {
                }

                return $port;
            },
        ],
    );
}

describe('A region', function () {
    test(
        'is proposed a name, the roles the grid lacks, a place around the center, the next port of its simulator',
        function () {
            $v = region_planner()->resolve();

            expect($v)->toMatchArray([
                'create' => true,
                // The first region of a simulator has the name of the simulator
                'name' => 'Sim1',
                // The default region and the one of the visitors, not the fallback
                'roles' => [Region::flagName(Region::DEFAULT_REGION), Region::flagName(Region::DEFAULT_HGREGION)],
                'location' => '100,100',
                'simulator' => '+',
                'port' => 9001,
            ]);
        },
    );

    test(
        'is named at random when the simulator has regions already, and a name that is taken is not proposed',
        function () {
            expect(region_planner(['first' => false])->resolve()['name'])->toBe('Random Name 1');

            $planner = region_planner(['taken' => ['sim1' => true, 'random name 1' => true]]);
            expect($planner->resolve()['name'])
                ->toBe('Random Name 2')
                ->and($planner->resolve()['name'])
                ->toBe('Random Name 2');
        },
    );

    test('is named after the region asked first when it fits', function () {
        expect(region_planner(['defaultName' => 'Garden'])->resolve()['name'])
            ->toBe('Garden')
            ->and(region_planner(['defaultName' => 'Not valid!'])->resolve()['name'])
            ->toBe('Sim1');
    });

    test(
        'has the roles of a grid with or without Hypergrid, and none when the grid is on another machine',
        function () {
            expect(region_planner()->roleOptions())
                ->toBe([
                    Region::flagName(Region::DEFAULT_REGION) => 'Default',
                    Region::flagName(Region::DEFAULT_HGREGION) => 'Default HG',
                    Region::flagName(Region::FALLBACK_REGION) => 'Fallback',
                ])
                ->and(region_planner(['grid' => region_grid(['hypergrid' => false])])->roleOptions())
                ->toBe([
                    Region::flagName(Region::DEFAULT_REGION) => 'Default',
                    Region::flagName(Region::FALLBACK_REGION) => 'Fallback',
                ])
                ->and(
                    region_planner([
                        'grid' => region_grid([
                            'regionFlags' => [
                                Region::flagName(Region::DEFAULT_REGION),
                                Region::flagName(Region::DEFAULT_HGREGION),
                            ],
                        ]),
                    ])->resolve()['roles'],
                )
                ->toBe([])
                ->and(region_planner(['grid' => region_grid(['remote' => true])])->roleOptions())
                ->toBe([]);
        },
    );

    test(
        'offers every role, and checks at first only the ones the grid has no region for yet (a grid can have several default regions)',
        function () {
            $checked = static fn(array $flags, bool $hypergrid = true): array => region_planner([
                'grid' => region_grid(['regionFlags' => $flags, 'hypergrid' => $hypergrid]),
            ])->resolve()['roles'];
            $held = region_planner([
                'grid' => region_grid([
                    'regionFlags' => [
                        Region::flagName(Region::DEFAULT_REGION),
                        Region::flagName(Region::DEFAULT_HGREGION),
                    ],
                ]),
            ]);

            expect($checked([]))
                ->toBe([Region::flagName(Region::DEFAULT_REGION), Region::flagName(Region::DEFAULT_HGREGION)])
                ->and($checked([Region::flagName(Region::DEFAULT_REGION)]))
                ->toBe([Region::flagName(Region::DEFAULT_HGREGION)])
                ->and($checked([Region::flagName(Region::DEFAULT_HGREGION)]))
                ->toBe([Region::flagName(Region::DEFAULT_REGION)])
                ->and($checked([Region::flagName(Region::DEFAULT_REGION), Region::flagName(Region::DEFAULT_HGREGION)]))
                ->toBe([])
                ->and($checked([Region::flagName(Region::FALLBACK_REGION)]))
                ->toBe([Region::flagName(Region::DEFAULT_REGION), Region::flagName(Region::DEFAULT_HGREGION)])
                ->and($checked([], false))
                ->toBe([Region::flagName(Region::DEFAULT_REGION)])
                // A role the grid has already is still there to be given to this region too, and what is answered is kept
                ->and(array_keys($held->roleOptions()))
                ->toBe([
                    Region::flagName(Region::DEFAULT_REGION),
                    Region::flagName(Region::DEFAULT_HGREGION),
                    Region::flagName(Region::FALLBACK_REGION),
                ])
                ->and($held->resolve(['roles' => [Region::flagName(Region::DEFAULT_REGION)]])['roles'])
                ->toBe([Region::flagName(Region::DEFAULT_REGION)])
                ->and($held->fields()->get('roles')['enable'])
                ->toBeTrue();
        },
    );

    test('takes the free place nearest to the one asked, and says when it moved', function () {
        $planner = region_planner(['known' => ['100,100' => true]]);

        expect($planner->resolve()['location'])->not->toBe('100,100');
        $settled = $planner->settle('100,100');
        expect($settled['moved'])
            ->toBeTrue()
            ->and($settled['asked'])
            ->toBe('100,100')
            ->and($settled['free'])
            ->not->toBe('100,100');
        expect($planner->settle('300,300'))->toMatchArray(['free' => '300,300', 'moved' => false]);
        // A region that moves frees the place it holds
        expect($planner->settle('100,100', '100,100'))->toMatchArray(['free' => '100,100', 'moved' => false]);
    });

    test('leaves a block free between regions when the grid asks for it', function () {
        $planner = region_planner(['grid' => region_grid(['regionSpacing' => 2]), 'known' => ['100,100' => true]]);

        [$x, $y] = array_map('intval', explode(',', $planner->settle('101,100')['free']));

        expect(max(abs($x - 100), abs($y - 100)))->toBeGreaterThan(2);
    });

    test('takes the port of its simulator, the next of the block, or the one asked', function () {
        $planner = region_planner(['ports' => [9001, 9002]]);

        expect($planner->resolve()['port'])
            ->toBe(9003)
            ->and($planner->resolve(['port' => '9500'])['port'])
            ->toBe(9500)
            ->and($planner->forPort(9010)->resolve()['port'])
            ->toBe(9011);
    });

    test('can go in a simulator of the pool, whose port is the one that counts', function () {
        $planner = region_planner([
            'pool' => ['demo_sim2' => 'Sim2 (Garden)'],
            'portOfSimulator' => static fn(string $simulator): int => 9010,
        ]);

        expect($planner->resolve()['simulator'])
            ->toBe('+')
            ->and($planner->resolve()['port'])
            ->toBe(9001)
            ->and($planner->resolve(['simulator' => 'demo_sim2'])['port'])
            ->toBe(9011)
            ->and($planner->fields()->get('simulator')['options'])
            ->toBe(['+' => 'Auto (add new)', 'demo_sim2' => 'Sim2 (Garden)'])
            ->and($planner->fields()->visible('port', ['simulator' => '+']))
            ->toBeFalse()
            ->and($planner->fields()->visible('port', ['simulator' => 'demo_sim2']))
            ->toBeTrue();
    });

    test(
        'says what is wrong: a name that is not valid, taken by a region of the grid or by one of a grid on another machine, a place, a port',
        function () {
            $planner = region_planner(['taken' => ['garden' => true]]);

            expect($planner->problems())
                ->toBe([])
                ->and($planner->problems(['name' => 'Garden']))
                ->toBe(['name' => 'The grid has a region of this name already: to run it on this machine, take it over (its UUID, place and owner kept).'])
                ->and($planner->problems(['name' => 'Not valid!']))
                ->toHaveKey('name')
                ->and($planner->problems(['location' => 'middle', 'port' => 'abc']))
                ->toHaveKeys(['location', 'port']);

            $remote = region_planner([
                'grid' => region_grid(['remote' => true]),
                'remoteHas' => static fn(string $name): ?bool => $name === 'Far Away',
                'freePlace' => static fn(int $x, int $y): array => [$x, $y, false],
            ]);
            expect($remote->problems(['name' => 'Far Away']))
                ->toHaveKey('name')
                ->and($remote->problems(['name' => 'Near']))
                ->toBe([]);
        },
    );

    test('is a transliterated name: an accent is corrected, and the person confirms', function () {
        expect(region_planner()->problems(['name' => 'Noël']))
            ->toBe(['name' => 'transliterated'])
            ->and(region_planner()->fields()->corrections(['name' => 'Noël']))
            ->toBe(['name' => 'Noel'])
            ->and(region_planner()->problems(['name' => 'Noel']))
            ->toBe([])
            ->and(Region::validateName('Noël'))
            ->toBeNull();

        $plan = new SimulatorPlan();
        region_planner()->apply($plan, ['name' => 'Noël']);
        expect($plan->regionName)->toBe('Noel');
    });

    test('is given to the plan of the simulator that takes it', function () {
        $plan = new SimulatorPlan();
        region_planner(['known' => ['100,100' => true]])->apply($plan, [
            'name' => 'Garden',
            'roles' => [Region::flagName(Region::DEFAULT_REGION)],
            'location' => '100,100',
        ]);

        expect($plan->createRegion)
            ->toBeTrue()
            ->and($plan->regionName)
            ->toBe('Garden')
            ->and($plan->regionRoles)
            ->toBe([Region::flagName(Region::DEFAULT_REGION)])
            ->and($plan->regionUuid)
            ->toBe('11111111-2222-4333-8444-555555555555')
            ->and($plan->regionLocation)
            ->not->toBe('100,100')
            ->and($plan->regionPort)
            ->toBe(9001);
    });

    test('can be left out when it is the optional first region of a simulator', function () {
        $plan = new SimulatorPlan();
        $planner = region_planner(['optional' => true]);
        $planner->apply($plan, ['create' => 'no']);

        expect($plan->createRegion)
            ->toBeFalse()
            ->and($planner->fields()->visible('name', ['create' => 'no']))
            ->toBeFalse()
            ->and($planner->fields()->visible('name', ['create' => 'yes']))
            ->toBeTrue();
    });

    test('keeps an existing region identity when moving', function () {
        $plan = new SimulatorPlan();
        $plan->regionName = 'Existing Garden';
        $plan->regionUuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $plan->regionLocation = '100,100';
        $plan->regionRoles = ['DefaultRegion'];
        $plan->regionPort = 9001;
        $plan->estateName = 'Existing Estate';
        $planner = region_planner()->fixed();
        $planner->apply($plan, ['name' => 'Replacement', 'roles' => [], 'location' => '120,120', 'port' => 9011]);
        expect($plan->regionName)->toBe('Existing Garden')
            ->and($plan->regionUuid)->toBe('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee')
            ->and($plan->regionRoles)->toBe(['DefaultRegion'])
            ->and($plan->estateName)->toBe('Existing Estate')
            ->and($plan->regionLocation)->toBe('120,120')
            ->and($plan->regionPort)->toBe(9011);
    });

    test('refuses a move without an existing region UUID', function () {
        $plan = new SimulatorPlan();
        $plan->regionName = 'Existing Garden';
        expect(fn() => region_planner()->fixed()->apply($plan, ['location' => '120,120']))
            ->toThrow(InvalidArgumentException::class, 'existing region UUID');
        expect($plan->regionUuid)->toBe('');
    });

    test('has a size, 256 meters by default, a multiple of 256, and a place where all its squares are free', function () {
        $plan = new W4OS\Engine\Provisioning\SimulatorPlan();
        region_planner()->apply($plan, ['name' => 'Big Garden', 'size' => 512]);
        // 1000,1000 is taken: a region of 512 meters needs the four places from where it is
        $used = ['1001,1000' => true];

        expect(region_planner()->resolve([])['size'])
            ->toBe(256)
            ->and($plan->regionSize)
            ->toBe(512)
            ->and(region_planner()->problems(['size' => 300]))
            ->toHaveKey('size')
            ->and(W4OS\Engine\Models\Region::validateSize(1024))
            ->toBeNull()
            ->and(W4OS\Engine\Models\Region::validateSize(8448))
            ->not->toBeNull()
            ->and(W4OS\Engine\Provisioning\LocationFinder::nearestFree($used, 1000, 1000))
            ->toBe([1000, 1000])
            ->and(W4OS\Engine\Provisioning\LocationFinder::nearestFree($used, 1000, 1000, 0, 2))
            ->not->toBe([1000, 1000]);
    });

    test('that exists only moves: its name, its roles and its simulator stay', function () {
        $fields = region_planner()->fixed()->fields();

        expect($fields->names())
            ->toBe(['create', 'existing', 'name', 'roles', 'location', 'size', 'simulator', 'port'])
            ->and($fields->visible('existing', []))
            ->toBeFalse()
            ->and($fields->visible('name', []))
            ->toBeFalse()
            ->and($fields->visible('size', []))
            ->toBeFalse()
            ->and($fields->visible('roles', []))
            ->toBeFalse()
            ->and($fields->visible('simulator', []))
            ->toBeFalse()
            ->and($fields->visible('location', []))
            ->toBeTrue()
            ->and($fields->visible('port', []))
            ->toBeTrue();
    });
});

describe('A region the grid has, taken over', function () {
    /** What the grid keeps of Old Garden: at 1000,1001, 512 meters, registered from a simulator at old.example.org that left. */
    $record = static fn(array $set = []): array => $set + [
        'uuid' => '11111111-2222-3333-4444-555555555555',
        'name' => 'Old Garden',
        'location' => '1000,1001',
        'size' => 512,
        'owner' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'server' => 'http://old.example.org:9000/',
        'flags' => 16,
    ];
    $planner = static fn(array|false|null $region, bool $serving = false, array $more = []): RegionPlanner => region_planner($more + [
        'taken' => ['old garden' => true],
        'registered' => static fn(string $name): array|false|null => strcasecmp($name, 'Old Garden') === 0 ? $region : false,
        'serving' => static fn(string $server): bool => $serving,
    ]);

    test('keeps its UUID, name, place and size, and has no other role than the ones the grid gives it by name', function () use ($record, $planner) {
        $plan = new SimulatorPlan();
        $taker = $planner($record());
        $taker->apply($plan, ['name' => 'old garden', 'existing' => true]);

        expect($taker->problems(['name' => 'old garden', 'existing' => true]))->toBe([])
            ->and([$plan->regionTakenOver, $plan->regionUuid, $plan->regionName, $plan->regionLocation, $plan->regionSize, $plan->regionRoles])
            ->toBe([true, '11111111-2222-3333-4444-555555555555', 'Old Garden', '1000,1001', 512, []])
            ->and($plan->regionPort)->toBe(9001);
    });

    test('is one whose simulator went without leaving the grid (it crashed): the flag online, nobody answering', function () use ($record, $planner) {
        expect($planner($record(['flags' => 16 | 4]))->problems(['name' => 'Old Garden', 'existing' => true]))->toBe([]);
    });

    test('is refused while the simulator that registered it answers, when the grid has none of the name, or when it does not answer', function () use ($record, $planner) {
        expect($planner($record(['flags' => 4]), serving: true)->problems(['name' => 'Old Garden', 'existing' => true]))
            ->toBe(['name' => 'The region Old Garden is still run by the simulator at http://old.example.org:9000/, which answers: stop it there (or take the region out of it) before taking it over here.'])
            ->and($planner(false)->problems(['name' => 'Old Garden', 'existing' => true]))
            ->toBe(['name' => 'The grid has no region of this name to take over.'])
            ->and($planner(null)->problems(['name' => 'Old Garden', 'existing' => true]))
            ->toBe(['name' => 'The grid does not answer: the region it has of this name cannot be taken over.']);
    });

    test('is not one a simulator of this machine describes: that one is there already', function () use ($record, $planner) {
        expect($planner($record(), more: ['files' => ['old garden' => true]])->problems(['name' => 'Old Garden', 'existing' => true]))
            ->toBe(['name' => 'The grid has a region of this name already.']);
    });
});
