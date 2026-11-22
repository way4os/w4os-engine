<?php

declare(strict_types=1);

/**
 * The tasks that run now and then: due by their interval, never twice at once, told when they fail and not tried again in a loop.
 */

use W4OS\Engine\Scheduling\Scheduler;
use W4OS\Engine\Scheduling\Task;
use W4OS\Engine\Scheduling\TaskResult;
use W4OS\Engine\Testing\ClockFake;
use W4OS\Engine\Testing\EnvironmentFake;

/** A scheduler over a temporary folder and a clock that goes only where the test sends it. */
function scheduler(?ClockFake &$clock = null, int $tickEvery = 60): Scheduler
{
    $clock = new ClockFake();

    return new Scheduler(test_tmp() . '/cron-' . bin2hex(random_bytes(4)), $clock, $tickEvery);
}

describe('A task', function () {
    test('is named with lower case words, and is due at least every second', function () {
        expect(fn() => new Task('Search Parse', 60, fn() => null))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn() => new Task('search-parse', 0, fn() => null))
            ->toThrow(InvalidArgumentException::class)
            ->and((new Task('search-parse', 60, fn() => null))->name)
            ->toBe('search-parse');
    });
});

describe('The tasks of several grids', function () {
    test('keep each its state and its lock: every grid on its own schedule', function () {
        $scheduler = scheduler($clock);
        $folder = $scheduler->directory();
        $ran = [];
        foreach (['demo', 'other_grid'] as $grid) {
            $scheduler->add(new Task('search-parse', 60, function () use (&$ran, $grid): string {
                $ran[] = $grid;

                return "read $grid";
            }, 'Read the snapshots', $grid));
        }

        $first = $scheduler->run('search-parse.demo');
        $status = array_column($scheduler->status(), null, 'id');
        $second = $scheduler->runDue();

        expect($first->status)
            ->toBe(TaskResult::RAN)
            ->and($status['search-parse.demo'])
            ->toMatchArray(['name' => 'search-parse', 'scope' => 'demo', 'due' => false])
            ->and($status['search-parse.other_grid'])
            ->toMatchArray(['name' => 'search-parse', 'scope' => 'other_grid', 'due' => true])
            ->and(array_map(static fn(TaskResult $r): string => $r->task, $second))
            ->toBe(['search-parse.other_grid'])
            ->and($ran)
            ->toBe(['demo', 'other_grid'])
            ->and(["$folder/search-parse.demo.json", "$folder/search-parse.other_grid.json"])
            ->each->toBeFile()
            ->and(fn() => $scheduler->run('search-parse'))
            ->toThrow(InvalidArgumentException::class);
    });

    test('read the state kept under the name alone once, the one before the grids', function () {
        $scheduler = scheduler($clock);
        $clock->now = 1_000;
        @mkdir($scheduler->directory(), 0o755, true);
        file_put_contents($scheduler->directory() . '/search-parse.json', json_encode(['started' => 990, 'status' => 'ran']));
        $scheduler->add(new Task('search-parse', 60, fn() => 'read', '', 'demo'));

        $before = $scheduler->status()[0];
        $clock->now = 1_100;
        $scheduler->run('search-parse');

        expect($before)
            ->toMatchArray(['lastRun' => 990, 'due' => false])
            ->and($scheduler->directory() . '/search-parse.demo.json')
            ->toBeFile()
            ->and($scheduler->status()[0]['lastRun'])
            ->toBe(1_100);
    });

    test('of the search are named by the folder of their grid', function () {
        $machine = new W4OS\Engine\Testing\Machine();
        $config = W4OS\Engine\Config\GridConfig::fromFolder($machine->grid);
        W4OS\Engine\Data\Connections::using(static fn() => new W4OS\Engine\Data\OSPDO(['driver' => 'sqlite', 'name' => ':memory:']));
        $scheduler = W4OS\Engine\Scheduling\Scheduler::for($machine->environment);

        W4OS\Engine\Services\SearchTasks::add($scheduler, $machine->environment, $config);
        W4OS\Engine\Data\Connections::using(null);

        expect(array_column($scheduler->status(), 'id'))->toBe(['search-parse.demo', 'events-parse.demo', 'guide-pictures.demo', 'health.demo']);
    });

    test('of a grid with no folder (the one a site makes of its description) are named by its nick, the pictures of its guide and its health with them', function () {
        $machine = new W4OS\Engine\Testing\Machine();
        $grid = W4OS\Engine\Models\SiteDescription::grid($machine->environment, W4OS\Engine\Models\SiteDescription::of(W4OS\Engine\Models\Grid::find($machine->environment, 'demo')));
        W4OS\Engine\Data\Connections::using(static fn() => new W4OS\Engine\Data\OSPDO(['driver' => 'sqlite', 'name' => ':memory:']));
        $scheduler = W4OS\Engine\Scheduling\Scheduler::for($machine->environment);

        W4OS\Engine\Services\SearchTasks::add($scheduler, $machine->environment, $grid->config(), $grid);
        W4OS\Engine\Data\Connections::using(null);

        expect(array_column($scheduler->status(), 'id'))->toBe(['search-parse.demo', 'events-parse.demo', 'guide-pictures.demo', 'health.demo']);
    });
});

describe('The scheduler', function () {
    test('runs a task the first time, then when its interval is over', function () {
        $scheduler = scheduler($clock);
        $runs = 0;
        $scheduler->add(
            new Task('count', 60, function () use (&$runs): string {
                $runs++;

                return "run $runs";
            }),
        );

        expect($scheduler->runDue()[0]->message)
            ->toBe('run 1')
            ->and($scheduler->runDue())
            ->toBe([]);
        $clock->now += 59;
        expect($scheduler->runDue())->toBe([]);
        $clock->now += 1;
        $result = $scheduler->runDue()[0];

        expect($result->ok())->toBeTrue()->and($result->message)->toBe('run 2')->and($runs)->toBe(2);
    });

    test('tells a task that fails, and waits for its next turn instead of trying again', function () {
        $scheduler = scheduler($clock);
        $tries = 0;
        $scheduler->add(
            new Task(
                'broken',
                300,
                function () use (&$tries): void {
                    $tries++;

                    throw new RuntimeException('the host does not answer');
                },
                'A host to read',
            ),
        );

        $result = $scheduler->runDue()[0];
        expect($result->status)
            ->toBe(TaskResult::FAILED)
            ->and($result->message)
            ->toBe('the host does not answer')
            ->and($result->ok())
            ->toBeFalse()
            ->and($scheduler->runDue())
            ->toBe([])
            ->and($tries)
            ->toBe(1);
        $status = $scheduler->status()[0];
        expect($status['status'])
            ->toBe('failed')
            ->and($status['message'])
            ->toBe('the host does not answer')
            ->and($status['due'])
            ->toBeFalse();
        $clock->now += 300;
        expect($scheduler->status()[0]['due'])->toBeTrue();
    });

    test('keeps the last run and the next of each task', function () {
        $scheduler = scheduler($clock);
        $scheduler
            ->add(new Task('daily', 86400, fn() => 'done', 'Once a day'))
            ->add(new Task('never-run', 10, fn() => null));
        $scheduler->run('daily');

        [$daily, $never] = $scheduler->status();
        expect($daily['label'])
            ->toBe('Once a day')
            ->and($daily['lastRun'])
            ->toBe(1000)
            ->and($daily['next'])
            ->toBe(1000 + 86400)
            ->and($daily['status'])
            ->toBe('ran')
            ->and($daily['running'])
            ->toBeFalse()
            ->and($never['lastRun'])
            ->toBeNull()
            ->and($never['due'])
            ->toBeTrue();
    });

    test('never runs a task twice at once', function () {
        $scheduler = scheduler($clock);
        $inside = null;
        $scheduler->add(
            new Task('slow', 60, function () use (&$scheduler, &$inside): void {
                // Someone else asks while it runs
                $inside = [$scheduler->run('slow', force: true)->status, $scheduler->status()[0]['running']];
            }),
        );

        $scheduler->run('slow');

        expect($inside)
            ->toBe([TaskResult::RUNNING, true])
            ->and($scheduler->status()[0]['running'])
            ->toBeFalse();
    });

    test('runs a task that is not due when it is forced', function () {
        $scheduler = scheduler($clock);
        $runs = 0;
        $scheduler->add(
            new Task('count', 3600, function () use (&$runs): void {
                $runs++;
            }),
        );
        $scheduler->run('count');

        expect($scheduler->run('count')->status)
            ->toBe(TaskResult::NOT_DUE)
            ->and($scheduler->run('count', force: true)->ok())
            ->toBeTrue()
            ->and($runs)
            ->toBe(2)
            ->and(fn() => $scheduler->run('nothing'))
            ->toThrow(InvalidArgumentException::class);
    });

    test('looks at its tasks at most once every tick, the pseudo cron of a request', function () {
        $scheduler = scheduler($clock, tickEvery: 60);
        $runs = 0;
        $scheduler->add(
            new Task('often', 10, function () use (&$runs): void {
                $runs++;
            }),
        );

        expect($scheduler->tick())->toHaveCount(1);
        $clock->now += 30;
        expect($scheduler->tick())->toBe([])->and($runs)->toBe(1);
        $clock->now += 30;
        expect($scheduler->tick())->toHaveCount(1)->and($runs)->toBe(2);
    });

    test('keeps its state in the state folder of the install', function () {
        $root = test_tmp() . '/cron-env-' . bin2hex(random_bytes(4));
        $scheduler = Scheduler::for(EnvironmentFake::with(['VarRoot' => $root]));
        $scheduler->add(new Task('kept', 60, fn() => 'ok'));
        $scheduler->run('kept');

        expect("$root/cron/kept.json")->toBeFile();
    });

    test('says once in the log that it cannot keep the state of its tasks, and tells whether it can', function () {
        $locked = test_tmp() . '/cron-locked-' . bin2hex(random_bytes(4));
        mkdir($locked, 0o555, true);
        $log = test_tmp() . '/cron-log-' . bin2hex(random_bytes(4));
        $before = ini_set('error_log', $log);
        $scheduler = new Scheduler("$locked/cron", new ClockFake());
        $scheduler->add(new Task('search-parse', 60, fn() => 'read'));

        $ticks = [$scheduler->tick(), (new Scheduler("$locked/cron", new ClockFake()))->tick()];
        $run = $scheduler->run('search-parse');
        ini_set('error_log', (string) $before);
        $writable = $scheduler->writable();
        chmod($locked, 0o755);
        $lines = array_values(array_filter(explode("\n", (string) @file_get_contents($log))));

        expect($ticks)
            ->toBe([[], []])
            ->and($run->status)
            ->toBe(TaskResult::FAILED)
            ->and($lines)
            ->toHaveCount(1)
            ->and($lines[0])
            ->toContain("$locked/cron")
            ->and([$writable, $scheduler->writable(), $scheduler->directory()])
            ->toBe([false, true, "$locked/cron"]);
    })->skip(fn() => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes everywhere');

    test(
        'does not run a task it cannot keep the state of: nothing would stop it from running again at once',
        function () {
            $file = test_tmp() . '/cron-blocked-' . bin2hex(random_bytes(4));
            file_put_contents($file, 'a file where the folder should be');
            $scheduler = new Scheduler("$file/cron", new ClockFake());
            $ran = false;
            $scheduler->add(
                new Task('blocked', 60, function () use (&$ran): void {
                    $ran = true;
                }),
            );

            $result = $scheduler->run('blocked');

            expect($result->status)
                ->toBe(TaskResult::FAILED)
                ->and($result->message)
                ->toContain('cannot write')
                ->and($ran)
                ->toBeFalse();
        },
    );
});
