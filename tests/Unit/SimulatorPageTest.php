<?php

declare(strict_types=1);

/**
 * The page of a simulator: its address, its console ([Network] of its own file) and its database, Use default by default: the
 * database of the OpenSim.ini of its grid, else the main database of the grid, whose localhost is the machine of Robust.
 */

use OpenSim\Api\Api;
use OpenSim\Api\Router;
use OpenSim\Config\ConfigReader;
use OpenSim\Config\ConnectionString;
use OpenSim\Config\GridConfig;
use OpenSim\Config\Settings;
use OpenSim\I18n\Catalog;
use OpenSim\Models\Simulator;
use OpenSim\Testing\Machine;

/** A machine whose grid has a main database on the machine of Robust, and the PATCH of its simulator alpha. */
function simulator_page(): array
{
    $machine = new Machine();
    $machine->write($machine->grid . '/Robust.HG.ini', file_get_contents($machine->grid . '/Robust.HG.ini') . "\n[DatabaseService]\n    ConnectionString = \"Data Source=localhost;Database=robust;User ID=opensim;Password=robust-pass;\"\n");
    $router = new Router(new Api($machine->environment));
    $patch = static fn(array $attributes) => $router->patch('/simulators/alpha', ['data' => ['type' => 'simulators', 'attributes' => $attributes]]);

    return [$machine, $router, $patch, GridConfig::simulatorsDirectory($machine->grid) . '/alpha.ini'];
}

describe('The page of a simulator', function () {
    test('has its address, its console and its database, Use default by default', function () {
        $form = Simulator::itemForm()->document();
        $fields = array_column($form['fields'], null, 'name');
        $en = static fn(string $key): string => Catalog::MESSAGES[$key] ?? $key;

        expect(array_map(static fn(array $field): array => [$field['name'], $field['group']], $form['fields']))->toBe([
            ['uri', 'simulator'], ['httpPort', 'simulator'],
            ['consoleUser', 'console'], ['consolePass', 'console'], ['consolePort', 'console'],
            ['database', 'database'],
        ])
            ->and(array_map(static fn(array $group): string => $en($group['label']), $form['groups']))->toBe(['Simulator', 'Console', 'Database'])
            ->and(array_map(static fn(array $field): string => $en($field['label']), $form['fields']))->toBe(['Simulator URI', 'HTTP Port', 'Console User', 'Console Password', 'Console Port', 'Simulator Database'])
            ->and($fields['uri']['readOnly'] ?? false)->toBeTrue()
            ->and($fields['consolePass']['type'])->toBe('secret')
            ->and($fields['database'])->toMatchArray(['type' => 'connection-string', 'fallback' => 'db_use_default', 'description' => ['db_default_opensim']])
            ->and(array_column($fields['database']['fields'], 'name'))->toBe(['host', 'port', 'database', 'user', 'password'])
            ->and($en('db_default_opensim'))->toBe('Default: the database of the simulators of the grid, else its main database.');
        foreach (Catalog::LOCALES as $locale) {
            expect(Catalog::messages($locale, false))->toHaveKeys([...array_column($form['groups'], 'label'), ...array_column($form['fields'], 'label'), 'db_default_opensim']);
        }
    });

    test('changes its console in [Network] of its own file, the password masked and kept when the mask is sent back', function () {
        [, , $patch, $ini] = simulator_page();

        $saved = $patch(['httpPort' => 9020, 'consoleUser' => 'admin', 'consolePass' => 'c0nsole-s3cret', 'consolePort' => 9024]);
        $again = $patch(['consolePass' => Settings::MASK, 'consoleUser' => 'root']);
        $network = ConfigReader::load($ini, expand: false)['Network'] ?? [];

        expect($saved->status)->toBe(200)
            ->and($saved->document['data']['attributes'])->toMatchArray(['httpPort' => 9020, 'consoleUser' => 'admin', 'consolePass' => Settings::MASK, 'consolePort' => 9024])
            ->and($again->status)->toBe(200)
            ->and([$network['http_listener_port'] ?? null, $network['ConsoleUser'] ?? null, $network['ConsolePass'] ?? null, $network['console_port'] ?? null])
            ->toBe(['9020', 'root', 'c0nsole-s3cret', '9024'])
            ->and(json_encode($saved->document))->not->toContain('c0nsole-s3cret');
    });

    test('uses the default database unless it has its own; Use default writes the main database where the grid has no other', function () {
        [, $router, $patch, $ini] = simulator_page();

        $read = $router->get('/simulators/alpha')->document['data']['attributes'];
        $default = array_key_exists('database', $read) ? $read['database'] : 'missing';
        $own = $patch(['database' => ['host' => 'db.example.org', 'database' => 'alpha', 'user' => 'sim', 'password' => 'sim-pass']]);
        $ownLine = ConfigReader::load($ini, expand: false)['DatabaseService']['ConnectionString'] ?? null;
        $back = $patch(['database' => ['useDefault' => true]]);
        $backLine = ConfigReader::load($ini, expand: false)['DatabaseService']['ConnectionString'] ?? null;

        expect($default)->toBeNull()
            ->and($own->document['data']['attributes']['database'])->toMatchArray(['host' => 'db.example.org', 'database' => 'alpha', 'user' => 'sim', 'password' => Settings::MASK])
            ->and($ownLine)->toBe('Data Source=db.example.org;Database=alpha;User ID=sim;Password=sim-pass;')
            // The main database of the grid, Robust being on this machine: its localhost is this one
            ->and($backLine)->toBe('Data Source=localhost;Database=robust;User ID=opensim;Password=robust-pass;')
            ->and($back->document['data']['attributes']['database'])->toBeNull()
            ->and(json_encode([$own->document, $back->document]))->not->toContain('sim-pass')->not->toContain('robust-pass');
    });

    test('reads the localhost of the main database as the machine of Robust when Robust runs elsewhere', function () {
        $robust = ConnectionString::parse('Data Source=localhost;Database=robust;User ID=opensim;Password=pw;');
        $remote = ConnectionString::parse('Data Source=db.example.org;Database=robust;User ID=opensim;Password=pw;');

        expect(Simulator::onRobustHost($robust, 'grid.example.org')->source)->toBe('grid.example.org')
            ->and(Simulator::onRobustHost(ConnectionString::parse('Data Source=127.0.0.1;Database=robust;User ID=opensim;Password=pw;'), 'grid.example.org')->source)->toBe('grid.example.org')
            ->and(Simulator::onRobustHost($remote, 'grid.example.org')->source)->toBe('db.example.org')
            ->and((string) Simulator::onRobustHost($robust, 'grid.example.org'))->toContain('Password=pw;');
    });
});
