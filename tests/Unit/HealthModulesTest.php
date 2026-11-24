<?php

declare(strict_types=1);

/**
 * The health of a grid says the modules enabled for the install that a simulator does not set up, kept off at its start, and the
 * instances that run on a .NET major other than the one their core targets.
 */

use W4OS\Engine\Config\Layout;
use W4OS\Engine\Health\HealthCheck;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Testing\Machine;

describe('The health of a grid', function () {
    test('says Gloebit is off for each simulator without its key, and how to set it, and nothing once it is set', function () {
        $machine = new Machine();
        $environment = $machine->environment->withLayout(new Layout(['EnabledModules' => 'opensimsearch gloebit'] + $machine->environment->layout->all()));
        $rows = static fn() => array_merge(...array_column(array_filter(
            HealthCheck::run($environment, Grid::find($environment, 'demo'), null, test_tmp())['groups'],
            static fn(array $group): bool => $group['file'] === 'modules',
        ), 'rows') ?: [[]]);

        $off = $rows();
        foreach (['alpha', 'beta'] as $simulator) {
            $ini = $machine->grid . "/sims/$simulator.ini";
            file_put_contents($ini, file_get_contents($ini) . "\n[Gloebit]\n    GLBKey = \"key\"\n    GLBSecret = \"secret\"\n");
        }

        expect(array_column($off, 'module'))->toBe(['gloebit', 'gloebit'])
            ->and($off[0])->toMatchArray(['kind' => 'module', 'level' => 'error', 'missing' => ['[Gloebit] GLBKey', '[Gloebit] GLBSecret'], 'fix' => 'PATCH /grids/{grid}/settings/economy'])
            ->and($off[0]['status'])->toContain('gloebit installed but not configured')
            ->and($rows())->toBe([]);
    });

    test('says an instance running on a newer .NET than its core targets, how to fix it, and status says it too', function () {
        $machine = new Machine();
        $runs = static function (string $name, string $runtime) use ($machine): void {
            $process = $machine->processes->add('dotnet OpenSim.dll -inifile=' . $machine->etc . "/opensim.d/$name.ini");
            mkdir($machine->root . "/proc/{$process->pid}", 0o755, true);
            file_put_contents($machine->root . "/proc/{$process->pid}/maps", "7f00 r-xp /usr/local/share/dotnet/shared/Microsoft.NETCore.App/$runtime/libcoreclr.so\n");
        };
        $runs('alpha', '10.0.14');
        $runs('beta', '8.0.34');

        $rows = array_merge(...array_column(array_filter(
            HealthCheck::run($machine->environment, Grid::find($machine->environment, 'demo'), null, test_tmp())['groups'],
            static fn(array $group): bool => $group['file'] === 'runtime',
        ), 'rows') ?: [[]]);

        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray(['label' => 'alpha', 'kind' => 'runtime', 'running' => 10, 'target' => 8, 'level' => 'error', 'fix' => 'sudo opensim install-dotnet 8'])
            ->and($rows[0]['status'])->toContain('runs on .NET 10, its core targets .NET 8')
            ->and(\W4OS\Engine\OpenSim::status($machine->environment)->wrongRuntimes($machine->environment->probe))->toBe(['alpha' => ['running' => 10, 'target' => 8]]);
    });
});
