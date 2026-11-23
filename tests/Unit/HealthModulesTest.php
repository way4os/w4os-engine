<?php

declare(strict_types=1);

/**
 * The health of a grid says the modules enabled for the install that a simulator does not set up, kept off at its start.
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
});
