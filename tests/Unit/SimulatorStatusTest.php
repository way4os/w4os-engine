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
            ->toBe(['enabled', 'state', 'connections.console', 'connections.screen', 'connections.config', 'connections.regions', 'connections.mysql', 'connections.sqlite'])
            ->and($indicators['state'])
            ->toMatchArray(['icon' => 'play', 'actions' => ['start', 'stop']])
            // Play while it runs, pause while it does not; red when it should answer and does not
            ->and($indicators['state']['states'])
            ->toBe([
                ['value' => 'running', 'label' => 'state_running', 'tone' => 'ok', 'icon' => 'play'],
                ['value' => 'stopped', 'label' => 'state_stopped', 'tone' => 'off', 'icon' => 'pause'],
                ['value' => 'unresponsive', 'label' => 'state_unresponsive', 'tone' => 'failed', 'icon' => 'play'],
                ['value' => 'crashed', 'label' => 'state_crashed', 'tone' => 'failed', 'icon' => 'pause'],
            ])
            ->and(OpenSim\Data\Listing::TONES)
            ->toBe(['ok', 'off', 'warning', 'failed'])
            ->and($indicators['enabled']['actions'])
            ->toBe(['enable', 'disable'])
            // None shows no icon; each state says it all on its own: the thing, and what is wrong with it when something is
            ->and(array_column($indicators['connections.mysql']['states'], 'label', 'value'))
            ->toBe(['configured' => 'connection_mysql', 'ok' => 'connection_mysql', 'failed' => 'connection_mysql_failed'])
            ->and(array_column($indicators['connections.mysql']['states'], 'tone', 'value'))
            ->toBe(['configured' => 'off', 'ok' => 'ok', 'failed' => 'failed'])
            ->and(OpenSim\I18n\Catalog::MESSAGES['connection_config'])
            ->toBe('OpenSim.ini')
            ->and(OpenSim\I18n\Catalog::MESSAGES['connection_config_failed'])
            ->toBe('OpenSim.ini unreadable')
            // The thing as it is named, the same in every language
            ->and(array_unique(array_map(
                static fn(string $locale): string => OpenSim\I18n\Catalog::messages($locale)['connection_regions'] ?? '',
                ['en_US', 'cy_GB', 'de_DE', 'fr_FR', 'it_IT', 'nl_NL', 'pt_BR'],
            )))
            ->toBe(['Regions .ini'])
            ->and(array_column($listing->columns, 'name'))
            ->not->toContain('enabled', 'running', 'state')
            // An instance of this machine only is started or stopped
            ->and([$actions['start']['requires'], $actions['stop']['requires'], $actions['stop']['ask']])
            ->toBe([['slug'], ['slug'], 'ask_stop_instance']);
    });
});

describe('The ways the engine reaches a simulator', function () {
    test('are checked in a list by what costs nothing (its files, the ports bound), tried when it is asked alone', function () {
        $machine = new Machine();
        $stopped = Simulator::find($machine->environment, 'alpha')->connections();
        $machine->processes->add('dotnet OpenSim.dll -inifile=' . $machine->etc . '/opensim.d/alpha.ini');
        $machine->processes->listening = [9000];
        $deaf = Simulator::find($machine->environment, 'alpha')->connections();
        $machine->processes->listening = [9000, 9004];
        $listening = Simulator::find($machine->environment, 'alpha')->connections();
        $alone = Simulator::find($machine->environment, 'alpha');
        $alone->probe();

        // Its console is not tried while it does not run: not there is normal then
        expect($stopped)
            ->toBe(['console' => 'configured', 'screen' => 'none', 'config' => 'ok', 'regions' => 'ok', 'mysql' => 'none', 'sqlite' => 'none'])
            ->and([$deaf['console'], $listening['console']])
            ->toBe(['failed', 'ok'])
            // Alone, it is tried: nothing listens at its console in a test
            ->and($alone->connections()['console'])
            ->toBe('failed');
    });

    test('name its database by what it is: MySQL, or SQLite, a file read and never made by trying it', function () {
        $machine = new Machine();
        $file = $machine->root . '/data/beta.db';
        $ini = OpenSim\Config\GridConfig::simulatorsDirectory($machine->grid) . '/beta.ini';
        file_put_contents($ini, (string) file_get_contents($ini) . "\n[DatabaseService]\nStorageProvider = \"OpenSim.Data.SQLite.dll\"\nConnectionString = \"URI=file:$file,version=3\"\n");
        $beta = Simulator::find($machine->environment, 'beta');
        $beta->probe();
        $missing = array_intersect_key($beta->connections(), ['mysql' => 1, 'sqlite' => 1]);
        touch($file);

        expect($missing)
            ->toBe(['mysql' => 'none', 'sqlite' => 'failed'])
            ->and(is_file($file))
            ->toBeTrue()
            ->and($beta->connections()['sqlite'])
            ->toBe('ok');
    });

    test('are tried by the API for one simulator, not for the list', function () {
        $machine = new Machine();
        $machine->processes->add('dotnet OpenSim.dll -inifile=' . $machine->etc . '/opensim.d/alpha.ini');
        $machine->processes->listening = [9000];
        $api = new Api($machine->environment);
        $list = array_column(array_column($api->list('simulators')->document['data'], 'attributes'), null, 'slug');
        $one = $api->get('simulators', 'alpha')->document['data']['attributes'];

        // Its port bound is enough for the list; alone, its /simstatus/ does not answer in a test
        expect([$list['alpha']['state'], $list['alpha']['connections']['config']])
            ->toBe(['running', 'ok'])
            ->and([$one['state'], $one['local']])
            ->toBe(['unresponsive', true]);
    });
});

describe('The state of a simulator', function () {
    test('is running, stopped, unresponsive when its process does not answer, crashed when its session is left without it', function () {
        $machine = new Machine();
        $state = static fn(bool $probe = false): string => (static function (Simulator $simulator) use ($probe): string {
            $probe && $simulator->probe();

            return $simulator->detail()['state'];
        })(Simulator::find($machine->environment, 'alpha'));
        $stopped = $state();
        $process = $machine->processes->add('dotnet OpenSim.dll -inifile=' . $machine->etc . '/opensim.d/alpha.ini');
        // Up, its port not bound: in a list it does not answer
        $deaf = $state();
        $machine->processes->listening = [9000];
        $listed = $state();
        // Alone, it is asked: /simstatus/ answers OK
        $silent = $state(true);
        $machine->http['http://127.0.0.1:9000/simstatus/'] = 'OK';
        $answering = $state(true);
        // Its screen session stays, its process is gone
        $machine->processes->remove($process->pid);
        $machine->consoles->sessions['alpha'] = true;
        $crashed = $state();

        expect([$stopped, $deaf, $listed, $silent, $answering, $crashed])
            ->toBe(['stopped', 'unresponsive', 'running', 'unresponsive', 'running', 'crashed']);
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
