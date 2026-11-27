<?php

declare(strict_types=1);

/**
 * The scenario of an install of before the kit, screens of the setup: the choice, the questions of a migration, its review, then its
 * steps one by one (the client's as root by their name, the engine's done by it), a failure's choice, the summary.
 */

use W4OS\Engine\Provisioning\Import\LegacyInstall;
use W4OS\Engine\Provisioning\MigrationScenario;
use W4OS\Engine\Provisioning\QuickSetup;
use W4OS\Engine\Testing\Machine;

require_once __DIR__ . '/LegacyInstallTest.php';

/** The plan of a migration as the importer gives it, and what it was asked. */
function migration_doubles(array &$calls): array
{
    ['install' => $legacy] = legacy_install_fixture();

    return [
        'legacy' => static fn(): LegacyInstall => $legacy,
        'importer' => static function (string $action, array $args) use (&$calls): array {
            $calls[] = [$action, $args['site'], $args['name']];

            return $action !== 'plan' ? [] : [
                'grid' => 'w4osdemogrid', 'name' => 'W4OS Demo Grid', 'site' => 'https://demo.w4os.org/', 'login' => 'demo.w4os.org:3405',
                'data' => [['from' => '/opt/opensim/var/data/W4osDemoGrid/fsassets', 'to' => '/var/lib/opensim/data/w4osdemogrid/fsassets', 'place' => 'fsassets', 'size' => '11 GB']],
                'database' => ['name' => 'w4osdemogrid_robust'], 'dropped' => [], 'maptiles' => 'opensim-0.9.3.0-maptiles', 'asciiName' => '',
            ];
        },
    ];
}

/** One request of the client, the setup answering. */
function migration_step(Machine $machine, array $screen, array $answers = [], string $button = 'next', array $more = []): array
{
    return QuickSetup::next($machine->environment, ['state' => $screen['state'] ?? '', 'answers' => $answers, 'button' => $button] + $more);
}

describe('The scenario of an install of before the kit', function () {
    afterEach(fn() => QuickSetup::through());

    test('starts with the install found and the choice of what to do with it, only when the client asks', function () {
        $calls = [];
        QuickSetup::through(migration_doubles($calls));
        $machine = new Machine();
        $first = QuickSetup::next($machine->environment, ['existing' => true]);
        $fresh = QuickSetup::next($machine->environment, []);

        expect($first['kind'])->toBe('menu')
            ->and(array_column($first['items'], 'key'))->toBe(['migrate', 'in-place', 'new', 'abort'])
            ->and($first['text'])->toContain('Found an existing install in', 'grid w4osdemogrid, 1 simulator')
            ->and($fresh['name'] ?? null)->toBe('type')
            ->and(migration_step($machine, $first, ['existing' => 'abort']))->toBe(['action' => 'abort', 'error' => 'Stopped: nothing changed.'])
            ->and(migration_step($machine, $first, ['existing' => 'new'])['name'])->toBe('type');
    });

    test('asks what a migration needs, then reviews it, each line changed there and checked again', function () {
        $calls = [];
        QuickSetup::through(migration_doubles($calls));
        $machine = new Machine();
        $questions = migration_step($machine, QuickSetup::next($machine->environment, ['existing' => true]), ['existing' => 'migrate']);
        $refused = migration_step($machine, $questions, ['stop' => 'no']);
        $review = migration_step($machine, $questions, ['stop' => 'yes', 'website' => 'https://w4os.org/opensim/', 'maptiles' => 'yes']);
        $changed = migration_step($machine, $review, ['website' => 'https://kimbe.example/opensim/'], 'edit');
        $wrong = migration_step($machine, $changed, ['website' => 'not an address'], 'edit');
        $rows = array_column($changed['rows'], 'value', 'label');

        expect(array_column($questions['fields'], 'name'))->toBe(['stop', 'website', 'maptiles'])
            ->and(array_column($questions['fields'], 'value', 'name')['website'])->toBe('https://demo.w4os.org/')
            ->and($refused['notice'])->toContain('needs the old instances stopped')
            ->and($review['kind'])->toBe('review')
            ->and(array_values(array_unique(array_column($review['rows'], 'section'))))->toBe(['Grid', 'Web', 'Data', 'Options', 'Old install'])
            ->and($rows['Website'])->toBe('https://kimbe.example/opensim/')
            ->and($rows['fsassets'])->toBe('11 GB, moved (same disk)')
            ->and($wrong['notice'])->toBe('Not an address: not an address');
    });

    test('runs its steps one by one, the client\'s by their name as root, the engine\'s done by it, numbered as the script has them', function () {
        $calls = [];
        QuickSetup::through(migration_doubles($calls));
        $machine = new Machine();
        $questions = migration_step($machine, QuickSetup::next($machine->environment, ['existing' => true]), ['existing' => 'migrate']);
        $step = migration_step($machine, migration_step($machine, $questions, ['website' => 'https://w4os.org/opensim/']));
        $seen = [];
        while (($step['action'] ?? '') === 'step') {
            $seen[] = sprintf('[%d/%d] %s %s', $step['step']['number'], $step['step']['count'], $step['step']['label'], $step['step']['name']);
            expect(!$step['step']['root'] || in_array($step['step']['name'], MigrationScenario::ROOT_STEPS, true))->toBeTrue();
            $step = migration_step($machine, $step);
        }

        expect($seen)->toBe([
            '[1/8] Stop set-aside-starters', '[1/8] Stop stop-old-instances', '[3/8] Config import-apply', '[5/8] Ownership give-ownership',
            '[6/8] 3D map install-packages', '[7/8] Website install-website', '[8/8] Start switch',
        ])
            ->and(array_column(array_filter($calls, static fn(array $c): bool => $c[0] !== 'plan'), 0))->toBe(['apply', 'switch'])
            ->and($step['action'])->toBe('quit')
            ->and($step['message'])->toContain('Website    https://w4os.org/opensim/', 'Old install  ');
    });

    test('gives the choice after a step that failed: retry, skip when the run goes on without it, abort with what stands', function () {
        $calls = [];
        QuickSetup::through(migration_doubles($calls));
        $machine = new Machine();
        $questions = migration_step($machine, QuickSetup::next($machine->environment, ['existing' => true]), ['existing' => 'migrate']);
        $step = migration_step($machine, migration_step($machine, $questions, []));
        while ($step['step']['name'] !== 'install-website') {
            $step = migration_step($machine, $step);
        }
        $failed = migration_step($machine, $step, ['error_text' => 'Apache refuses it'], 'failed');
        $skipped = migration_step($machine, $failed, ['error' => 'skip']);
        $done = migration_step($machine, $skipped);
        $stopped = migration_step($machine, $failed, ['error' => 'abort']);

        expect($failed['kind'])->toBe('menu')
            ->and(array_column($failed['items'], 'key'))->toBe(['retry', 'skip', 'abort'])
            ->and($failed['text'])->toBe('Apache refuses it')
            ->and($skipped['step']['name'])->toBe('switch')
            ->and($done['message'])->toContain('Website    not installed (rerun to install it)')
            ->and($stopped['error'])->toContain('the grid is written here, not started');
    });
});
