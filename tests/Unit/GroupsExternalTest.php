<?php

declare(strict_types=1);

/**
 * The address a grid gives the other grids for its groups (`[Groups] GroupsExternalURI` of its simulators): OpenSimulator's default
 * is http://127.0.0.1, under which every other grid keeps its groups ("name ＠ 127.0.0.1", unreachable). The public address of its
 * Robust, where the connector of the hypergrid groups answers: laid by the options, a loopback one left out by a migration, said by
 * the health.
 */

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Health\Health;
use W4OS\Engine\Provisioning\GridOptions;
use W4OS\Engine\Provisioning\RetiredSettings;
use W4OS\Engine\Testing\Machine;

describe('The address of the groups for the other grids', function () {
    test('is the public address of Robust in the options of the hypergrid groups', function () {
        $options = new GridOptions(W4OS\Engine\Models\Grid::find((new Machine())->environment, 'demo'));
        foreach (['groups-hypergrid', 'groups-local-hypergrid'] as $name) {
            $groups = W4OS\Engine\Config\ConfigReader::parse((string) file_get_contents($options->template($name)->directory() . '/OpenSim.ini'))['Groups'] ?? [];

            expect(trim((string) ($groups['GroupsExternalURI'] ?? ''), '"'))->toBe('${Const|BaseURL}:${Const|PublicPort}', $name);
        }
    });

    test('at a loopback or private address is left out by a migration, the public one of the options in its place', function () {
        expect(RetiredSettings::why('Groups', 'GroupsExternalURI', '"http://127.0.0.1"', '/nowhere/opensim-0.9.2.2'))->toContain('public address')
            ->and(RetiredSettings::why('Groups', 'GroupsExternalURI', 'http://192.168.1.5:8002', '/nowhere/opensim/0.9.3.0'))->toContain('public address')
            ->and(RetiredSettings::why('Groups', 'GroupsExternalURI', 'http://grid.example.org:8002', '/nowhere/opensim/0.9.3.0'))->toBeNull()
            ->and(RetiredSettings::why('Groups', 'GroupsExternalURI', '${Const|BaseURL}:${Const|PublicPort}', '/nowhere/opensim/0.9.3.0'))->toBeNull();
    });

    test('is said by the health of a hypergrid grid when it is missing or loopback, with what to set', function () {
        $row = static function (array $groups): ?array {
            $config = new GridConfig(['Robust' => ['Hypergrid' => ['GatekeeperURI' => 'http://grid.example.org:8002']], 'OpenSim' => ['Groups' => $groups]]);
            foreach (Health::grid(['live' => [], 'config' => $config]) as $group) {
                foreach ($group['rows'] as $line) {
                    if ($line['label'] === 'GroupsExternalURI') {
                        return $line;
                    }
                }
            }

            return null;
        };

        expect($row([])['level'] ?? null)->toBe('error')
            ->and($row([])['detail'] ?? '')->toContain('${Const|BaseURL}:${Const|PublicPort}')
            ->and($row(['GroupsExternalURI' => 'http://127.0.0.1'])['level'] ?? null)->toBe('error')
            ->and($row(['GroupsExternalURI' => 'http://grid.example.org:8002'])['level'] ?? null)->not->toBe('error');
    });
});
