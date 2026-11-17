<?php

declare(strict_types=1);

/**
 * The instances of this machine started, stopped and restarted through the API (`POST /instances/{name}/start|stop|restart`), as the
 * command line does: by the user that runs them only, a clean refusal for any other (the web server of a site).
 */

use OpenSim\Api\Api;
use OpenSim\Api\Router;
use OpenSim\Config\Layout;
use OpenSim\Runtime\InstanceAccess;
use OpenSim\Testing\Machine;

/** A machine whose instances come up when they are started, and go when their console is told to shut down. */
function control_machine(): Machine
{
    $machine = new Machine();
    $machine->launcher->behavior = function ($spec) use ($machine) {
        $lines = $spec->name === 'demo'
            ? ['[SERVER]: AssetServiceConnector loaded successfully', '[SERVER]: GridServiceConnector loaded successfully']
            : ['[RegionReady]: Region "Plaza" is ready'];
        file_put_contents($machine->root . "/logs/{$spec->name}.log", implode("\n", $lines) . "\n", FILE_APPEND);
    };
    foreach (['demo', 'alpha', 'beta'] as $name) {
        $machine->consoles->console($name)->onSend = function (string $line) use ($machine, $name) {
            if ($line === 'shutdown') {
                foreach ($machine->processes->all() as $p) {
                    str_contains($p->arguments, "/$name.ini") && $machine->processes->remove($p->pid);
                }
            }
        };
    }

    return $machine;
}

describe('Who may start and stop the instances', function () {
    test('is the user that runs them; any user when the install names none', function () {
        expect(InstanceAccess::refusal(new Layout(['SystemUser' => 'opensim']), 'opensim'))
            ->toBeNull()
            ->and(InstanceAccess::refusal(new Layout([]), 'magic'))
            ->toBeNull()
            ->and(InstanceAccess::refusal(new Layout(['SystemUser' => 'opensim']), 'www-data'))
            ->toContain('www-data', 'opensim');
    });
});

describe('The API, for the instances', function () {
    test('starts an instance, its simulators when asked, and says what became of each', function () {
        $machine = control_machine();
        $router = new Router(new Api($machine->environment));

        $started = $router->post('/instances/demo/start', ['data' => ['type' => 'operations', 'attributes' => ['simulators' => true]]]);

        expect($started->status)
            ->toBe(200)
            ->and($started->document['data']['attributes']['action'])
            ->toBe('start')
            ->and(array_column($started->document['data']['attributes']['results'], 'outcome', 'instance'))
            ->toBe(['demo' => 'started', 'alpha' => 'started', 'beta' => 'started'])
            ->and(count($machine->processes->all()))
            ->toBe(3);
    });

    test('stops and restarts one, and says it does not know one that is not there', function () {
        $machine = control_machine();
        $router = new Router(new Api($machine->environment));
        $router->post('/instances/demo/start', ['data' => ['type' => 'operations', 'attributes' => ['simulators' => true]]]);

        $stopped = $router->post('/instances/alpha/stop', ['data' => ['type' => 'operations', 'attributes' => ['now' => true]]]);
        $restarted = $router->post('/instances/alpha/restart', ['data' => ['type' => 'operations', 'attributes' => ['now' => true]]]);
        $unknown = $router->post('/instances/nowhere/start', ['data' => ['type' => 'operations', 'attributes' => []]]);
        $wrong = $router->post('/instances/alpha/jump', ['data' => ['type' => 'operations', 'attributes' => []]]);

        expect([$stopped->status, $stopped->document['data']['attributes']['results'][0]['outcome']])
            ->toBe([200, 'stopped'])
            ->and([$restarted->status, $restarted->document['data']['attributes']['results'][0]['outcome']])
            ->toBe([200, 'started'])
            ->and([$unknown->status, $unknown->document['code'] ?? null])
            ->toBe([404, 'not-found'])
            ->and($wrong->status)
            ->toBe(404);
    });

    test('refuses cleanly, nothing done, when this process is not the user that runs the instances', function () {
        $machine = control_machine();
        $api = new Api($machine->environment, instanceAccess: static fn(): ?string => 'this process runs as www-data, the instances as opensim');

        $refused = $api->control('start', 'demo', []);

        expect([$refused->status, $refused->document['code']])
            ->toBe([403, 'instance-user-required'])
            ->and($refused->document['detail'])
            ->toContain('www-data')
            ->and($machine->processes->all())
            ->toBe([]);
    });
});
