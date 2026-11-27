<?php

declare(strict_types=1);

/**
 * The instances started on this machine: detached from any terminal (a session of their own, their input /dev/null), so that the
 * terminal of the command that started them (or the one sudo gives it) is given back as soon as that command ends.
 */

use W4OS\Engine\Runtime\LaunchSpec;
use W4OS\Engine\Runtime\SystemLauncher;

describe('An instance started on this machine', function () {
    test('runs in a session of its own, without a terminal, its input /dev/null', function () {
        $dir = sys_get_temp_dir() . '/launch-' . bin2hex(random_bytes(4));
        mkdir($dir);
        // What the instance is given: its input, its session (its own when it leads it), its terminal (0: none)
        $probe = 'readlink -f /proc/$$/fd/0 >stdin; awk -v pid=$$ \'{print ($6 == pid ? "leader" : "inherited"), $7}\' /proc/$$/stat >session';
        $launched = (new SystemLauncher())->launch(new LaunchSpec('probe', $dir, ['sh', '-c', $probe], [], "$dir/out.log"));
        for ($i = 0; $i < 50 && !is_file("$dir/session"); $i++) {
            usleep(100000);
        }

        expect($launched)->toBeTrue()
            ->and(trim((string) @file_get_contents("$dir/stdin")))->toBe('/dev/null')
            ->and(trim((string) @file_get_contents("$dir/session")))->toBe('leader 0');
    })->skip(!is_dir('/proc/self') || !is_executable('/usr/bin/setsid'), 'Linux, with setsid');

    test('keeps nothing open of whoever starts it: a descriptor inherited is closed', function () {
        $dir = sys_get_temp_dir() . '/launch-fd-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        // Started by a process that holds a descriptor (7) its children inherit, as a setup holds its terminal
        $code = 'require ' . var_export($autoload, true) . '; (new W4OS\\Engine\\Runtime\\SystemLauncher())->launch(new W4OS\\Engine\\Runtime\\LaunchSpec("probe", ' . var_export($dir, true)
            . ', ["sh", "-c", "[ -e /proc/$$/fd/7 ] && echo held >fd7 || echo closed >fd7"], [], ' . var_export("$dir/out.log", true) . '));';
        $process = proc_open([PHP_BINARY, '-r', $code], [7 => ['file', "$dir/held", 'w']], $pipes);
        proc_close($process);
        for ($i = 0; $i < 50 && !is_file("$dir/fd7"); $i++) {
            usleep(100000);
        }

        expect(trim((string) @file_get_contents("$dir/fd7")))->toBe('closed');
    })->skip(!is_dir('/proc/self'), 'Linux');
});
