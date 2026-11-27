<?php

declare(strict_types=1);

use W4OS\Engine\Provisioning\QuickSetup;
use W4OS\Engine\Runtime\Core;
use W4OS\Engine\Runtime\CoreReleases;
use W4OS\Engine\Testing\Machine;

require_once __DIR__ . '/MigrationScenarioTest.php';

/** A release as its site publishes it: an archive of one folder, opensim-<version>, with its bin. */
function core_archive(string $folder, string $version): string
{
    $source = "$folder/src/opensim-$version/bin";
    mkdir($source, 0o755, true);
    touch("$source/OpenSim.dll");
    file_put_contents("$source/System.Drawing.Common.dll.linux", 'linux');
    $archive = "$folder/opensim-$version.tar.gz";
    exec('tar czf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg("$folder/src") . ' ' . escapeshellarg("opensim-$version"));

    return $archive;
}

/** The releases of a site that lists three, its archives made here; what was downloaded is counted. */
function core_releases(string $folder, int &$downloads): CoreReleases
{
    $page = '<a href="opensim-0.9.2.2.tar.gz">x</a><a href="opensim-0.9.3.0-source.tar.gz">s</a><a href="opensim-0.9.3.0.tar.gz">x</a><a href="opensim-0.9.3.0.zip">z</a><a href="opensim-0.9.1.1.tar.gz">x</a>';

    return new CoreReleases(
        "$folder/cache",
        static fn(string $url): string => $page,
        static function (string $url, string $file) use ($folder, &$downloads): bool {
            $downloads++;
            preg_match('/opensim-([0-9.]+)\.tar\.gz$/', $url, $m);

            return $m[1] === '0.9.2.2' ? false : copy(core_archive("$folder/made-$downloads", $m[1]), $file);
        },
    );
}

describe('The releases of OpenSimulator', function () {
    test('are the binary archives of its site, the newest first, one unpacked under a core root and kept when it is there', function () {
        $folder = sys_get_temp_dir() . '/core-releases-' . bin2hex(random_bytes(4));
        mkdir($folder, 0o755, true);
        $downloads = 0;
        $releases = core_releases($folder, $downloads);
        $said = [];
        $directory = $releases->install('0.9.3.0', "$folder/cores", static function (string $line) use (&$said): void {
            $said[] = $line;
        });
        $again = $releases->install('0.9.3.0', "$folder/cores");
        $refused = static function (callable $call): string {
            try {
                $call();
            } catch (RuntimeException $error) {
                return $error->getMessage();
            }

            return '';
        };

        expect(array_column($releases->available(), 'version'))->toBe(['0.9.3.0', '0.9.2.2', '0.9.1.1'])
            ->and($releases->available()[0]['archive'])->toBe('http://opensimulator.org/dist/opensim-0.9.3.0.tar.gz')
            ->and($directory)->toBe("$folder/cores/0.9.3.0")
            ->and(Core::latest("$folder/cores")?->directory())->toBe("$folder/cores/0.9.3.0")
            ->and(file_get_contents("$directory/bin/System.Drawing.Common.dll"))->toBe('linux')
            ->and($said)->toBe(['downloading opensim-0.9.3.0.tar.gz', 'unpacking opensim-0.9.3.0.tar.gz'])
            ->and($again)->toBe($directory)
            ->and($downloads)->toBe(1)
            ->and($refused(fn() => $releases->install('9.9', "$folder/cores")))->toBe('Not a release of OpenSimulator: 9.9')
            ->and($refused(fn() => $releases->install('0.9.2.2', "$folder/cores")))->toBe('Could not download http://opensimulator.org/dist/opensim-0.9.2.2.tar.gz.')
            ->and(is_dir("$folder/cores/0.9.2.2"))->toBeFalse();
    });

    test('are offered by the setup of a machine that has no core, the one chosen unpacked before its questions', function () {
        $machine = new Machine();
        $folder = sys_get_temp_dir() . '/core-setup-' . bin2hex(random_bytes(4));
        mkdir($folder, 0o755, true);
        // A machine without a core: its root empty
        rename($machine->core, "$folder/kept");
        $downloads = 0;
        QuickSetup::through(['region' => [
            'releases' => static fn(): CoreReleases => core_releases($folder, $downloads),
            'manages' => static fn(): bool => true,
            'accountHosts' => static fn(): ?array => [],
        ]]);
        try {
            $menu = QuickSetup::next($machine->environment, ['state' => QuickSetup::next($machine->environment, [])['state'], 'answers' => ['type' => 'grid']]);
            $failed = QuickSetup::next($machine->environment, ['state' => $menu['state'], 'answers' => ['core' => '0.9.2.2']]);
            $next = QuickSetup::next($machine->environment, ['state' => $menu['state'], 'answers' => ['core' => '0.9.3.0']]);
        } finally {
            QuickSetup::through();
        }

        expect($menu)->toMatchArray(['kind' => 'menu', 'name' => 'core', 'title' => 'OpenSimulator version', 'value' => '0.9.3.0'])
            ->and($menu['text'])->toBe('No OpenSimulator core in ' . dirname($machine->core) . ' yet.')
            ->and(array_column($menu['items'], 'key'))->toBe(['0.9.3.0', '0.9.2.2', '0.9.1.1'])
            ->and($failed)->toMatchArray(['name' => 'core', 'notice' => 'Could not download http://opensimulator.org/dist/opensim-0.9.2.2.tar.gz.'])
            ->and(is_file($machine->core . '/bin/OpenSim.dll'))->toBeTrue()
            // Then the questions of the type as on any machine, the core said as done
            ->and($next['name'])->toBe('db-admin')
            ->and($next['done'])->toBe('OpenSimulator 0.9.3.0');
    });

    test('come after the folders, made first when root sets up a machine no config of the install was written for', function () {
        $machine = new Machine();
        QuickSetup::through(['region' => ['root' => true]]);
        try {
            $step = QuickSetup::next($machine->environment, ['state' => QuickSetup::next($machine->environment, [])['state'], 'answers' => ['type' => 'grid']]);
        } finally {
            QuickSetup::through();
        }

        expect($step['action'])->toBe('step')
            ->and($step['step'])->toMatchArray(['name' => 'give-ownership', 'root' => true, 'label' => 'Folders', 'done' => 'Folders']);
    });

    test('are offered before a migration on a machine that has no core, never for an install taken where it is', function () {
        $machine = new Machine();
        $folder = sys_get_temp_dir() . '/core-migrate-' . bin2hex(random_bytes(4));
        mkdir($folder, 0o755, true);
        rename($machine->core, "$folder/kept");
        $downloads = 0;
        $calls = [];
        QuickSetup::through(migration_doubles($calls) + ['region' => ['releases' => static fn(): CoreReleases => core_releases($folder, $downloads)]]);
        $step = static fn(array $screen, array $answers): array => QuickSetup::next($machine->environment, ['state' => $screen['state'], 'answers' => $answers]);
        try {
            $choice = $step(QuickSetup::next($machine->environment, []), ['type' => 'found']);
            $inPlace = $step($choice, ['existing' => 'in-place']);
            $menu = $step($choice, ['existing' => 'migrate']);
            $questions = $step($menu, ['core' => '0.9.3.0']);
        } finally {
            QuickSetup::through();
        }

        expect($inPlace['kind'])->toBe('review')
            ->and($menu)->toMatchArray(['kind' => 'menu', 'name' => 'core'])
            ->and($questions['done'])->toBe('OpenSimulator 0.9.3.0')
            ->and($questions)->toMatchArray(['kind' => 'form', 'title' => 'Migration of w4osdemogrid'])
            ->and(is_file($machine->core . '/bin/OpenSim.dll'))->toBeTrue();
    });
});
