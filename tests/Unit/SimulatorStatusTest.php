<?php

use OpenSim\Api\Api;
use OpenSim\Data\Connections;
use OpenSim\Data\OSPDO;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Models\Simulator;
use OpenSim\Testing\Machine;

/**
 * The status of the simulators: enabled and running, the ways the engine reaches each, and the simulators of other machines that
 * run regions of a grid of this one.
 */

/** The regions of the grid demo: Plaza of its simulator alpha, and Suzi's region of another machine, online. */
function status_regions(): OSPDO
{
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec(
        'CREATE TABLE regions (uuid VARCHAR(36) PRIMARY KEY, regionHandle BIGINT, regionName VARCHAR(128), locX INT, locY INT, sizeX INT DEFAULT 0, sizeY INT DEFAULT 0, serverIP VARCHAR(64), serverPort INT, serverURI VARCHAR(255), flags INT DEFAULT 0, last_seen INT DEFAULT 0, owner_uuid CHAR(36), access INT DEFAULT 21, ScopeID CHAR(36), regionMapTexture VARCHAR(36))',
    );
    $here = gethostname();
    $pdo->exec("INSERT INTO regions (uuid, regionName, locX, locY, serverURI, flags) VALUES
        ('11111111-1111-4111-8111-111111111111', 'Plaza', 256000, 256000, 'http://$here:9000/', 4),
        ('22222222-1111-4111-8111-111111111111', 'Suzi Home', 256256, 256000, 'http://suzi.osgrid.example:9000/', 4),
        ('33333333-1111-4111-8111-111111111111', 'Elsewhere', 0, 0, 'http://other.example.org:8002/', 512)");

    return $pdo;
}

describe('The list of the simulators', function () {
    test('shows their status in one column: enabled and running as the buttons that change them, then the ways to each', function () {
        $listing = Simulator::listing();
        $status = array_column($listing->columns, null, 'name')['status'];
        $indicators = array_column($status['indicators'], null, 'name');
        $actions = array_column($listing->actions, null, 'name');

        expect($status['type'])
            ->toBe('indicators')
            ->and(array_keys($indicators))
            ->toBe(['enabled', 'running', 'connections.console', 'connections.screen', 'connections.config', 'connections.regions', 'connections.database'])
            ->and($indicators['running'])
            ->toMatchArray(['icon' => 'run', 'actions' => ['start', 'stop']])
            ->and($indicators['running']['states'][0])
            ->toBe(['value' => true, 'label' => 'state_running', 'tone' => 'ok'])
            ->and($indicators['enabled']['actions'])
            ->toBe(['enable', 'disable'])
            // None shows no icon; each state says it all on its own: the thing, and what is wrong with it when something is
            ->and(array_column($indicators['connections.database']['states'], 'label', 'value'))
            ->toBe(['configured' => 'connection_database', 'ok' => 'connection_database', 'failed' => 'connection_database_failed'])
            ->and(OpenSim\I18n\Catalog::MESSAGES['connection_config'])
            ->toBe('OpenSim.ini')
            ->and(OpenSim\I18n\Catalog::MESSAGES['connection_config_failed'])
            ->toBe('OpenSim.ini unreadable')
            ->and(array_column($listing->columns, 'name'))
            ->not->toContain('enabled', 'running')
            // An instance of this machine only is started or stopped
            ->and([$actions['start']['requires'], $actions['stop']['requires'], $actions['stop']['ask']])
            ->toBe([['slug'], ['slug'], 'ask_stop_instance']);
    });
});

describe('The ways the engine reaches a simulator', function () {
    test('are said configured in a list, tried when it is asked alone', function () {
        $machine = new Machine();
        $listed = Simulator::find($machine->environment, 'alpha');
        $alone = Simulator::find($machine->environment, 'alpha');
        $alone->probe();

        expect($listed->connections())
            ->toBe(['console' => 'configured', 'screen' => 'none', 'config' => 'configured', 'regions' => 'configured', 'database' => 'none'])
            // Nothing listens at its console in a test, its files are there
            ->and($alone->connections())
            ->toBe(['console' => 'failed', 'screen' => 'none', 'config' => 'ok', 'regions' => 'ok', 'database' => 'none']);
    });

    test('are tried by the API for one simulator, not for the list', function () {
        $machine = new Machine();
        $api = new Api($machine->environment);
        $list = $api->list('simulators')->document['data'];
        $one = $api->get('simulators', 'alpha')->document['data']['attributes'];

        expect(array_column(array_column(array_column($list, 'attributes'), 'connections'), 'config'))
            ->toBe(['configured', 'configured'])
            ->and($one['connections']['config'])
            ->toBe('ok')
            ->and($one['local'])
            ->toBeTrue();
    });
});

describe('A simulator of another machine', function () {
    test('is listed when it runs regions of a grid of this one: by its address, running while a region is online, no way to it', function () {
        $machine = new Machine();
        Connections::using(static fn() => status_regions());
        try {
            $all = Simulator::all($machine->environment)->items();
            $suzi = Simulator::find($machine->environment, 'suzi.osgrid.example:9000');
            $detail = $suzi?->detail();
            try {
                $suzi?->enable();
            } catch (ChannelUnavailable $e) {
                $refused = $e;
            }
        } finally {
            Connections::using(null);
        }

        expect(array_map(static fn(Simulator $simulator): string => $simulator->displayName(), $all))
            ->toBe(['Alpha', 'Beta', 'suzi.osgrid.example:9000'])
            ->and(array_intersect_key($detail, array_flip(['uri', 'slug', 'grid', 'enabled', 'running', 'local', 'httpPort'])))
            ->toBe(['uri' => 'suzi.osgrid.example:9000', 'slug' => null, 'grid' => 'demo', 'enabled' => null, 'running' => true, 'local' => false, 'httpPort' => 9000])
            ->and(array_unique($detail['connections']))
            ->toBe(['console' => 'none'])
            ->and($refused ?? null)
            ->toBeInstanceOf(ChannelUnavailable::class);
    });
});
