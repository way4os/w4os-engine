<?php
/**
 * Pest setup. Run the suite with ./vendor/bin/pest.
 *
 * - tests/Environment: what the project needs from its environment (PHP version, compatibility)
 */

/** The temporary folder by its real path (macOS: /var is a link to /private/var, the paths the code finds are not the same). */
function test_tmp(): string
{
    return realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
}

/**
 * The database the tests use, as `tests/.env` says it (`TEST_DATABASE=<PDO dsn>`); null when it says none, and the tests that need
 * one are skipped.
 */
function test_pdo(): ?W4OS\Engine\Data\OSPDO
{
    $file = __DIR__ . '/.env';
    $dsn = null;
    foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
        if (preg_match('/^\s*TEST_DATABASE\s*=\s*(.+?)\s*$/', $line, $m) === 1) {
            $dsn = trim($m[1], '"\'');
        }
    }
    $pdo = $dsn === null ? null : new W4OS\Engine\Data\OSPDO($dsn);

    return $pdo?->connected ? $pdo : null;
}

/**
 * The Robust config a plan makes: the example of the core, in the bin folder of the plan, with the presets of the grid laid over
 * it. The folder of the plan is made in the temporary folder when it has none.
 *
 * @param ?array<string,mixed> $answers the answers that choose the options (see GridOptions::selection())
 */
function robust_made(W4OS\Engine\Provisioning\GridPlan $plan, ?array $answers = null): string
{
    if ($plan->gridDir === '') {
        $plan->gridDir = $plan->etcDirectory = test_tmp() . '/robust-made-' . bin2hex(random_bytes(4));
    }
    is_dir($plan->gridDir) || mkdir($plan->gridDir, 0o755, true);
    W4OS\Engine\Config\IniEditor::load(
        $plan->binDir . '/' . ($plan->enableHypergrid ? 'Robust.HG.ini.example' : 'Robust.ini.example'),
    )->save($plan->robustIni());
    $options = new W4OS\Engine\Provisioning\GridOptions(
        W4OS\Engine\Models\Grid::planned(W4OS\Engine\Testing\EnvironmentFake::with(), $plan),
        layered: true,
        extra: $plan->variables(),
    );
    foreach ($options->selection($answers ?? []) as $name) {
        $options->enable($name);
    }

    return (string) file_get_contents($plan->robustIni());
}

/**
 * Names resolved without the network: no host resolves, no address has a name, this machine is itself. A test that needs more gives
 * its own (ServerNames::using()) and puts this one back.
 */
function offline_names(): array
{
    return [
        'addresses' => static fn(string $host): array => [],
        'name' => static fn(string $ip): ?string => null,
        'self' => static fn(): array => ['name' => (string) gethostname(), 'addresses' => []],
    ];
}

W4OS\Engine\Network\ServerNames::using(offline_names());

/**
 * Why a test may not start a container here, null when it may: never on macOS (its podman machine is too small), unless
 * PODMAN_LOCAL=1 says so; a test that only talks to podman runs on another machine when CONTAINER_CONNECTION names one.
 */
function podman_refused(bool $mountsLocalFiles = false): ?string
{
    if (PHP_OS_FAMILY !== 'Darwin' || getenv('PODMAN_LOCAL') === '1') {
        return null;
    }
    if (!$mountsLocalFiles && (string) getenv('CONTAINER_CONNECTION') !== '') {
        return null;
    }

    return $mountsLocalFiles
        ? 'it mounts files of this Mac: run it on Linux, or PODMAN_LOCAL=1'
        : 'no container on this Mac: CONTAINER_CONNECTION names another machine, or PODMAN_LOCAL=1';
}
