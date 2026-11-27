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
            if ($action === 'in-place-plan') {
                return ['grid' => 'w4osdemogrid', 'name' => 'W4OS Demo Grid', 'login' => 'demo.w4os.org:3405', 'site' => '', 'accounts' => ['SystemUser' => ['from' => 'opensim', 'to' => 'magic']], 'gridInfo' => []];
            }

            return $action !== 'plan' ? [] : [
                'grid' => 'w4osdemogrid', 'name' => 'W4OS Demo Grid', 'site' => 'https://demo.w4os.org/', 'login' => 'demo.w4os.org:3405',
                'data' => [
                    ['from' => '/opt/opensim/var/data/W4osDemoGrid/fsassets', 'to' => '/var/lib/opensim/data/w4osdemogrid/fsassets', 'place' => 'fsassets', 'size' => '11 GB', 'kilobytes' => 11 * 1048576],
                    ['from' => '/opt/opensim/var/cache/W4osDemoGrid/maptiles', 'to' => '/var/cache/opensim/w4osdemogrid/maptiles', 'place' => 'cache/maptiles', 'size' => '32 KB', 'kilobytes' => 32],
                ],
                'database' => ['name' => 'w4osdemogrid_robust'],
                'dropped' => [['section' => 'AssetService', 'key' => 'AssetLoaderArgs', 'value' => '${Const|EtcDirectory}/assets/AssetSets.xml', 'reason' => 'corrected', 'replacement' => '/usr/share/opensim/0.9.3.0/bin/assets/AssetSets.xml']], 'maptiles' => 'opensim-0.9.3.0-maptiles', 'asciiName' => '',
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
            ->and(array_values(array_unique(array_column($review['rows'], 'section'))))->toBe(['Grid', 'Web', 'Data', 'Corrected from the old install', 'Options', 'Old install'])
            ->and($rows['Data'])->toBe('11 GB in 2 folders, moved (same disk)')
            ->and(array_column($review['rows'], 'options', 'label')['Data'])->toBe(['fsassets' => 'fsassets: 11 GB', 'cache/maptiles' => 'cache/maptiles: 32 KB'])
            ->and($rows['AssetLoaderArgs'])->toBe('${Const|EtcDirectory}/assets/AssetSets.xml → /usr/share/opensim/0.…n/assets/AssetSets.xml')
            ->and($rows['Website'])->toBe('https://kimbe.example/opensim/')
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

    test('names in its summary the owner of the estates of the grid migrated, on the line of its admin', function () {
        $calls = [];
        $doubles = migration_doubles($calls);
        $importer = $doubles['importer'];
        // The grid of the plan is the machine's, its simulator an owner
        $doubles['importer'] = static fn(string $action, array $args): array => ['grid' => 'demo'] + $importer($action, $args);
        QuickSetup::through($doubles);
        $machine = new Machine();
        $alpha = W4OS\Engine\Config\GridConfig::simulatorsDirectory($machine->grid) . '/alpha.ini';
        file_put_contents($alpha, "[Estates]\n    DefaultEstateOwnerName = Gudule Lapointe\n", FILE_APPEND);
        $questions = migration_step($machine, QuickSetup::next($machine->environment, ['existing' => true]), ['existing' => 'migrate']);
        $step = migration_step($machine, migration_step($machine, $questions, ['website' => 'https://w4os.org/opensim/']));
        while (($step['action'] ?? '') === 'step') {
            $step = migration_step($machine, $step);
        }

        expect($step['message'])->toContain('Admin      https://w4os.org/opensim/login (Gudule Lapointe)');
    });

    test('asks beside Apache the one line its sites need, and gives the website step the answer, never asking in it', function () {
        $calls = [];
        QuickSetup::through(migration_doubles($calls) + ['vhosts' => static fn(string $site): ?array => ['/etc/apache2/sites-available/w4os.org-le-ssl.conf']]);
        $machine = new Machine();
        $questions = migration_step($machine, QuickSetup::next($machine->environment, ['existing' => true]), ['existing' => 'migrate']);
        $review = migration_step($machine, $questions, ['website' => 'https://w4os.org/opensim/', 'vhosts' => 'yes']);
        $step = migration_step($machine, $review);
        while ($step['step']['name'] !== 'install-website') {
            $step = migration_step($machine, $step);
        }
        $without = migration_step($machine, $questions, ['website' => 'https://w4os.org/opensim/', 'vhosts' => 'no']);
        // The address changed to a host Apache serves: the question asked before the review
        $asked = migration_step($machine, $questions, ['website' => 'https://w4os.org/opensim/']);

        expect(array_column($questions['fields'], 'label', 'name')['vhosts'])->toBe('Add one line to /etc/apache2/sites-available/w4os.org-le-ssl.conf')
            ->and($step['step']['args'])->toBe(['grid' => 'w4osdemogrid', 'edit_vhosts' => true])
            ->and(array_column($without['rows'], 'value', 'label')['Website'])->toContain('not installed')
            ->and($asked['kind'])->toBe('form')
            ->and(array_column($asked['fields'], 'name'))->toContain('vhosts');
    });

    test('keeps the steps it started with, the instances it stops being stopped by then', function () {
        $calls = [];
        $doubles = migration_doubles($calls);
        QuickSetup::through($doubles);
        $machine = new Machine();
        $legacy = ($doubles['legacy'])();
        $questions = migration_step($machine, QuickSetup::next($machine->environment, ['existing' => true]), ['existing' => 'migrate']);
        $step = migration_step($machine, migration_step($machine, $questions, []));
        $names = [];
        while (($step['action'] ?? '') === 'step') {
            $names[] = $step['step']['name'];
            // The old instances stopped once their step is done: their processes gone
            $step['step']['name'] === 'stop-old-instances' && exec('rm -rf ' . escapeshellarg(dirname($legacy->root, 2) . '/proc'));
            $step = migration_step($machine, $step);
        }

        expect($names)->toBe(['set-aside-starters', 'stop-old-instances', 'import-apply', 'give-ownership', 'install-packages', 'install-website', 'switch']);
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

    test('describes the answers a run without anybody takes, the defaults of this install filled', function () {
        $calls = [];
        QuickSetup::through(migration_doubles($calls));
        $machine = new Machine();
        $keys = QuickSetup::next($machine->environment, ['existing' => true, 'keys' => true]);
        $defaults = array_column($keys['keys'], 'default', 'name');

        expect($keys['action'])->toBe('keys')
            ->and(array_keys($defaults))->toBe(array_keys(MigrationScenario::KEYS))
            ->and($defaults)->toMatchArray(['existing' => 'migrate', 'website' => 'https://demo.w4os.org/', 'name' => 'W4OS Demo Grid', 'vhosts' => 'no'])
            ->and(array_column($keys['keys'], 'values', 'name')['maptiles'])->toBe(['yes', 'no']);
    });

    test('without anybody takes its answers at once, a default for each one missing, no line of Apache unless answered', function () {
        $calls = [];
        QuickSetup::through(migration_doubles($calls) + ['vhosts' => static fn(string $site): ?array => ['/etc/apache2/sites-available/w4os.org-le-ssl.conf']]);
        $machine = new Machine();
        $step = QuickSetup::next($machine->environment, ['existing' => true, 'unattended' => true, 'answers' => ['website' => 'https://w4os.org/opensim/', 'maptiles' => false]]);
        $names = [];
        while (($step['action'] ?? '') === 'step') {
            $names[] = $step['step']['name'];
            $step = migration_step($machine, $step);
        }
        $answered = QuickSetup::next($machine->environment, ['existing' => true, 'unattended' => true, 'answers' => ['vhosts' => 'yes']]);
        $lines = explode("\n", $step['message']);

        expect($names)->toBe(['set-aside-starters', 'stop-old-instances', 'import-apply', 'give-ownership', 'switch'])
            ->and(end($lines))->toBe('Website    not installed (no answer for vhosts)')
            ->and($step['message'])->not->toContain('Admin')
            ->and($answered['step']['name'])->toBe('set-aside-starters')
            ->and(array_column(QuickSetup::decode($answered['state'])['answers']['run'], 'name'))->toContain('install-website');
    });

    test('without anybody refuses an answer it does not know or a value it does not take, nothing done', function () {
        $calls = [];
        QuickSetup::through(migration_doubles($calls));
        $machine = new Machine();
        $unknown = QuickSetup::next($machine->environment, ['existing' => true, 'unattended' => true, 'answers' => ['websit' => 'https://w4os.org/']]);
        $value = QuickSetup::next($machine->environment, ['existing' => true, 'unattended' => true, 'answers' => ['maptiles' => 'maybe']]);
        $address = QuickSetup::next($machine->environment, ['existing' => true, 'unattended' => true, 'answers' => ['website' => 'not an address']]);

        expect($unknown)->toBe(['action' => 'abort', 'code' => 2, 'error' => 'Unknown answer: websit (opensim setup --legacy --config-keys lists them).'])
            ->and($value['error'])->toBe('maptiles: maybe is not one of yes, no.')
            ->and($address['error'])->toBe('Not an address: not an address')
            ->and(array_column($calls, 0))->not->toContain('apply', 'switch');
    });

    test('without anybody stops at a step that fails, in one line with its number and what stands', function () {
        $calls = [];
        QuickSetup::through(migration_doubles($calls));
        $machine = new Machine();
        $step = QuickSetup::next($machine->environment, ['existing' => true, 'unattended' => true, 'answers' => []]);
        while ($step['step']['name'] !== 'give-ownership') {
            $step = migration_step($machine, $step);
        }
        $failed = migration_step($machine, $step, ['error_text' => 'chown: no such user'], 'failed');

        expect($failed['action'])->toBe('abort')
            ->and($failed['code'])->toBe(1)
            ->and($failed['error'])->toBe('[5/8] Ownership: chown: no such user. Stopped: the grid is written here, not started; the old install is kept. A rerun goes on from here.');
    });

    test('runs an install in place: its review, its accounts taken, its restart under the kit, then its steps and summary', function () {
        $calls = [];
        QuickSetup::through(migration_doubles($calls));
        $machine = new Machine();
        $review = migration_step($machine, QuickSetup::next($machine->environment, ['existing' => true]), ['existing' => 'in-place']);
        $rows = array_column($review['rows'], 'value', 'label');
        $kept = migration_step($machine, $review, ['restart' => 'no'], 'edit');
        $step = migration_step($machine, $review);
        $names = [];
        while (($step['action'] ?? '') === 'step') {
            $names[] = sprintf('[%d/%d] %s %s', $step['step']['number'], $step['step']['count'], $step['step']['label'], $step['step']['name']);
            $step = migration_step($machine, $step);
        }

        expect($review['kind'])->toBe('review')
            ->and($rows['SystemUser'])->toBe('opensim → magic')
            ->and($rows['Restart under the kit'])->toBe('on')
            ->and(array_column($kept['rows'], 'value', 'label')['Restart under the kit'])->toBe('off')
            ->and($names)->toBe(['[1/4] Stop set-aside-starters', '[2/4] Config in-place-apply', '[3/4] Ownership give-ownership', '[4/4] Start stop-old-instances', '[4/4] Start restart-service', '[4/4] Start in-place-check'])
            ->and(array_column($calls, 0))->toContain('in-place-apply', 'in-place-check')
            ->and($step['message'])->toContain('In place   ')
            ->and($step['message'])->not->toContain('still run as before');
    });

    test('without anybody runs in place, its instances left as they run unless restart is answered', function () {
        $calls = [];
        QuickSetup::through(migration_doubles($calls));
        $machine = new Machine();
        $step = QuickSetup::next($machine->environment, ['existing' => true, 'unattended' => true, 'answers' => ['existing' => 'in-place']]);
        $names = [];
        while (($step['action'] ?? '') === 'step') {
            $names[] = $step['step']['name'];
            $step = migration_step($machine, $step);
        }

        expect($names)->toBe(['set-aside-starters', 'in-place-apply', 'give-ownership'])
            ->and($step['message'])->toContain('Instances  still run as before: opensim restart w4osdemogrid runs them under the kit');
    });

    test('says the grid of a run before is here, starts it when it does not run, and has nothing to do once it does', function () {
        $calls = [];
        $doubles = migration_doubles($calls);
        $legacy = ($doubles['legacy'])();
        // The old install's grid is the machine's grid demo: migrated by a run before
        file_put_contents($legacy->robusts()[0], "[GridInfoService]\n    gridnick = \"demo\"\n");
        QuickSetup::through($doubles);
        $machine = new Machine();
        $here = QuickSetup::next($machine->environment, ['existing' => true]);
        $step = migration_step($machine, $here);
        $done = migration_step($machine, $step);
        $unattended = QuickSetup::next($machine->environment, ['existing' => true, 'unattended' => true, 'answers' => []]);

        expect($here['kind'])->toBe('review')
            ->and($here['text'])->toBe('demo is here already.')
            ->and(array_column($here['rows'], 'label'))->toBe(['Start'])
            ->and($step['step']['name'])->toBe('switch')
            ->and($done['action'])->toBe('quit')
            ->and(array_column($calls, 0))->toContain('switch')->not->toContain('plan', 'apply')
            ->and($unattended['step']['name'])->toBe('switch');
    });

    test('asks again on a rerun the address of a website a run before left, and keeps it alone before its step', function () {
        $calls = [];
        $doubles = migration_doubles($calls);
        $legacy = ($doubles['legacy'])();
        file_put_contents($legacy->robusts()[0], "[GridInfoService]\n    gridnick = \"demo\"\n");
        // No site of Apache serves the address of the run before; the new one has its site
        $doubles['vhosts'] = static fn(string $site): ?array => str_contains($site, 'w4os.org/') && !str_contains($site, 'www.') ? [] : ['/etc/apache2/sites-available/www.w4os.org-le-ssl.conf'];
        QuickSetup::through($doubles);
        $machine = new Machine();
        W4OS\Engine\Models\Grid::keepSiteAddress("{$machine->grid}/demo.conf", 'https://w4os.org/');
        $here = QuickSetup::next($machine->environment, ['existing' => true]);
        $changed = migration_step($machine, $here, ['website' => 'https://www.w4os.org/opensim/', 'vhosts' => 'yes'], 'edit');
        $step = migration_step($machine, $changed);
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'demo');
        $next = migration_step($machine, migration_step($machine, $step));

        expect(array_column($here['rows'], 'value', 'label')['Website'])->toBe('https://w4os.org/, not installed: no site of Apache serves w4os.org')
            ->and(array_column($changed['rows'], 'value', 'name')['website'])->toBe('https://www.w4os.org/opensim/')
            ->and($step['step']['name'])->toBe('keep-website')
            ->and($grid?->siteAddress())->toBe('https://www.w4os.org/opensim/')
            ->and($grid?->siteState())->toBe('chosen')
            ->and(migration_step($machine, $step)['step']['name'] ?? null)->toBe('install-website');
    });
});
