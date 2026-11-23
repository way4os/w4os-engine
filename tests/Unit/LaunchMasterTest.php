<?php

declare(strict_types=1);

/**
 * The launch master of opensim.service (LaunchMaster) and its clients (MasterLauncher): an instance asked for by anyone is started by
 * the master, a child of the service; without the master, it starts as before.
 */

use W4OS\Engine\Runtime\LaunchMaster;
use W4OS\Engine\Runtime\LaunchSpec;
use W4OS\Engine\Runtime\LauncherInterface;
use W4OS\Engine\Runtime\MasterLauncher;

/** A master of its own on a socket of a temporary folder: [its process, the socket, the folder]. */
function launch_master(): array
{
    $dir = test_tmp() . '/master-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o755, true);
    $socket = "$dir/launch.sock";
    $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
    $master = proc_open(
        [PHP_BINARY, '-r', "require $autoload; (new W4OS\\Engine\\Runtime\\LaunchMaster('$socket'))->serve();"],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$dir/master.err", 'w']],
        $pipes,
    );
    for ($try = 0; $try < 50 && !file_exists($socket); ++$try) {
        usleep(100_000);
    }

    return [$master, $socket, $dir];
}

/** A launcher that only says what it was asked. */
function launch_recorder(): LauncherInterface
{
    return new class implements LauncherInterface {
        /** @var list<LaunchSpec> */
        public array $asked = [];

        public function launch(LaunchSpec $spec): bool
        {
            $this->asked[] = $spec;

            return true;
        }
    };
}

describe('The launch master', function () {
    test('starts what a client asks, as its own child, and says so; the socket is for its owner only', function () {
        [$master, $socket, $dir] = launch_master();
        $direct = launch_recorder();
        $launched = (new MasterLauncher($socket, $direct))->launch(new LaunchSpec('demo.sim', $dir, ['sh', '-c', 'echo $PPID started > "$1"', 'sh', "$dir/started"], ['W4OS_TEST' => '1'], "$dir/out"));
        for ($try = 0; $try < 50 && !is_file("$dir/started"); ++$try) {
            usleep(100_000);
        }
        $mode = fileperms($socket) & 0o777;
        proc_terminate($master);
        for ($try = 0; $try < 50 && file_exists($socket); ++$try) {
            usleep(100_000);
        }

        expect($launched)->toBeTrue()
            ->and($direct->asked)->toBe([])
            ->and((string) @file_get_contents("$dir/started"))->toContain('started')
            ->and($mode & 0o077)->toBe(0)
            // Told to stop, it leaves no socket behind
            ->and(file_exists($socket))->toBeFalse();
    });

    test('refuses what is not a launch of its protocol, and says why', function () {
        expect(fn() => LaunchMaster::spec(['version' => 2, 'name' => 'x']))->toThrow(InvalidArgumentException::class, 'version')
            ->and(fn() => LaunchMaster::spec(['version' => 1, 'name' => 'x', 'directory' => '/nonexistent', 'command' => ['true']]))->toThrow(InvalidArgumentException::class)
            ->and(fn() => LaunchMaster::spec(['version' => 1, 'name' => 'x', 'directory' => '/', 'command' => ['true', 3]]))->toThrow(InvalidArgumentException::class);
    });

    test('reads back the request a client sends: one line, all the launch', function () {
        $spec = new LaunchSpec('demo.sim', '/', ['dotnet', 'OpenSim.dll', '-inifile=/a b.ini'], ['LD_LIBRARY_PATH' => '/lib'], '/tmp/out', true, true);
        $request = LaunchMaster::request($spec);

        expect(substr_count($request, "\n"))->toBe(1)
            ->and(LaunchMaster::spec(json_decode($request, true)))->toEqual($spec);
    });

    test('is not there: the instance starts as before, and the caller is told when the service should run', function () {
        $direct = launch_recorder();
        $said = [];
        $warn = static function (string $message) use (&$said): void {
            $said[] = $message;
        };
        $spec = new LaunchSpec('demo.sim', '/', ['true']);

        expect((new MasterLauncher('/nonexistent/launch.sock', $direct, $warn, service: true))->launch($spec))->toBeTrue()
            ->and((new MasterLauncher('/nonexistent/launch.sock', $direct, $warn, service: false))->launch($spec))->toBeTrue()
            ->and(count($direct->asked))->toBe(2)
            ->and($said)->toHaveCount(1)
            ->and($said[0])->toContain('opensim.service is not running', 'sudo systemctl start opensim');
    });
});
