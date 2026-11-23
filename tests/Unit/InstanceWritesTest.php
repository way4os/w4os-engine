<?php

declare(strict_types=1);

/**
 * Where an instance writes (InstanceWrites): the folders its config names, and the ones the account it runs as cannot write.
 */

use W4OS\Engine\Runtime\InstanceWrites;

describe('Where an instance writes', function () {
    test('the folders its config names, a relative one in the bin folder of its core', function () {
        $folders = InstanceWrites::folders(
            ['Startup' => ['PIDFile' => '"/run/opensim/sim.pid"'], 'AssetCache' => ['CacheDirectory' => './assetcache']],
            '/opt/core/bin',
        );

        expect($folders)->toBe(['/run/opensim', '/opt/core/bin/./assetcache']);
    });

    test('what it cannot write is the folder, or the first of its parents there when it is not made yet, with its owner', function () {
        $dir = test_tmp() . '/writes-' . bin2hex(random_bytes(4));
        mkdir("$dir/closed", 0o755, true);
        mkdir("$dir/open", 0o755, true);
        chmod("$dir/closed", 0o555);
        $refused = InstanceWrites::unwritable(["$dir/closed/maptiles", "$dir/open/maptiles", "$dir/open"]);
        chmod("$dir/closed", 0o755);

        expect(array_keys($refused))->toBe(["$dir/closed"])
            ->and($refused["$dir/closed"])->toBe(posix_getpwuid(posix_geteuid())['name']);
    })->skip(fn() => posix_geteuid() === 0, 'root writes everywhere');

    test('by the account named: root writes everywhere, another one by the mode of the folder', function () {
        $dir = test_tmp() . '/writes-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        $other = posix_getpwuid(0)['name'] === 'root' ? 'nobody' : '';

        expect(InstanceWrites::unwritable([$dir], 'root'))->toBe([])
            ->and(array_keys(InstanceWrites::unwritable([$dir], $other)))->toBe([$dir]);
    })->skip(fn() => posix_geteuid() === 0 || posix_getpwnam('nobody') === false, 'needs an account of its own and nobody');
});
