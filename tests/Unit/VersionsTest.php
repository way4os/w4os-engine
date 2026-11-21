<?php

declare(strict_types=1);

/**
 * The versions of what matters on a machine: the projects (the package that installed them, else their git version, else the one
 * being worked on), the cores of OpenSimulator, the runtimes.
 */

use W4OS\Engine\Support\Versions;
use W4OS\Engine\Testing\Machine;

describe('The version of a project', function () {
    test('is its git version in a clone, the one being worked on without git, unknown without anything', function () {
        $root = test_tmp() . '/versions-' . bin2hex(random_bytes(4));
        mkdir($root, 0o755, true);
        expect(Versions::of($root, 'nothing-of-the-kind'))->toBe('unknown');

        file_put_contents("$root/.version", "3.0.0-beta.9\n");
        expect(Versions::of($root, 'nothing-of-the-kind'))->toBe('3.0.0-beta.9 (development)');

        exec('git -C ' . escapeshellarg($root) . ' init -q && git -C ' . escapeshellarg($root) . ' -c user.email=t@example.org -c user.name=t commit -q --allow-empty -m first 2>&1', $out, $code);
        $hash = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD'));
        expect($code)->toBe(0)->and(Versions::of($root, 'nothing-of-the-kind'))->toContain($hash);
    });

    test('of the engine itself is known', function () {
        expect(Versions::engine())->not->toBe('unknown');
    });
});

describe('The versions of a machine', function () {
    test('list the engine, rest-php, the cores and PHP', function () {
        $machine = new Machine();
        $all = Versions::all($machine->environment, ['w4os-cli' => '3.0.0-test']);

        expect(array_key_first($all))
            ->toBe('w4os-cli')
            ->and($all)
            ->toHaveKeys(['w4os-cli', 'w4os-engine', 'opensim-rest-php', 'OpenSimulator 0.9.3.0', 'PHP'])
            ->and($all['PHP'])
            ->toBe(PHP_VERSION);
    });
});
