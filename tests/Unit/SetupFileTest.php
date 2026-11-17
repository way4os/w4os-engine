<?php

declare(strict_types=1);

/**
 * A setup in a file: what it says of a region, by the names of the fields of the model, and what the setup writes back of it.
 */

use OpenSim\Provisioning\SetupFile;
use OpenSim\Provisioning\SimulatorPlan;

describe('A setup file', function () {
    test('gives the size of a region with its other answers, and keeps it when the setup writes it back', function () {
        $data = SetupFile::parse('{"simulators":[{"name":"North","database":{"user":"opensim","password":"secret"},"regions":[{"name":"Big Garden","location":"1000,1000","size":512,"roles":["FallbackRegion"]}]}]}');
        $plan = new SimulatorPlan();
        $plan->simulatorName = 'North';
        $plan->slug = 'demo.north';
        $plan->regionName = 'Big Garden';
        $plan->regionLocation = '1000,1000';
        $plan->regionSize = 512;
        $plan->createRegion = true;

        expect(SetupFile::regionValues($data, 0, 0))
            ->toMatchArray(['name' => 'Big Garden', 'location' => '1000,1000', 'size' => 512, 'roles' => ['FallbackRegion']])
            ->and(SetupFile::withSimulator([], $plan)['simulators'][0]['regions'][0]['size'])
            ->toBe(512);
    });
});
