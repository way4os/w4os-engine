<?php

/**
 * The import of a simulator of opensim-debian (a 0.9.2 Speculoos server, its install at /opt/opensim) in a Linux container: the real
 * paths and the modes of the files, planned then applied (tests/fixtures/import/in-container.php). The project and the projects its
 * vendor folder links to are mounted where they are, read only.
 */

/** The folder that holds the engine and every project its vendor folder links to. */
function import_test_mount(): string
{
    $root = dirname(__DIR__, 2);
    $paths = [(string) realpath($root)];
    foreach (glob("$root/vendor/magicoli/*") ?: [] as $link) {
        $paths[] = (string) realpath($link);
    }
    $common = explode('/', $paths[0]);
    foreach ($paths as $path) {
        $parts = explode('/', $path);
        $i = 0;
        while ($i < count($common) && ($parts[$i] ?? null) === $common[$i]) {
            $i++;
        }
        $common = array_slice($common, 0, $i);
    }

    return implode('/', $common) ?: '/';
}

describe('A simulator imported in a container', function () {
    test('is planned without writing, then written: its config 640 on the same database, its region as it was', function () {
        $mount = import_test_mount();
        $script = (string) realpath(dirname(__DIR__) . '/fixtures/import/in-container.php');
        $image = getenv('IMPORT_TEST_IMAGE') ?: 'docker.io/library/php:8.4-cli-alpine';
        exec(
            sprintf('podman run --rm -v %s:%s:ro %s php %s 2>&1', escapeshellarg($mount), escapeshellarg($mount), escapeshellarg($image), escapeshellarg($script)),
            $output,
            $status,
        );
        $result = json_decode(implode("\n", $output), true);

        expect($status)
            ->toBe(0, implode("\n", $output))
            ->and($result['writtenByPlan'])
            ->toBe([])
            ->and($result['plan']['slug'])
            ->toBe('demo.redoutable')
            ->and($result['plan']['regions'][0]['file'])
            ->toBe('/opt/opensim/etc/grids/speculoos/sims/redoutable/regions/Redoutable.ini')
            ->and($result['mode'])
            ->toBe('640')
            ->and($result['config'])
            ->toContain('Database=osredoutable;User ID=opensim;Password=secret;Old Guids=true;')
            ->toContain('http_listener_port = 9250')
            ->and($result['region'])
            ->toBe($result['original']);
    })
        ->skip(fn() => getenv('PODMAN_INTEGRATION') !== '1', 'set PODMAN_INTEGRATION=1 to run the import in a Podman container')
        ->skip(fn() => podman_refused(true) !== null, (string) podman_refused(true));
});
