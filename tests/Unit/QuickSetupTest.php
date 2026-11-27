<?php

declare(strict_types=1);

/**
 * The quick setup: a few answers, the whole setup they stand for, and what is wrong with them by the name of the question.
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Config\Layout;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\GridPlan;
use W4OS\Engine\Provisioning\Import\LegacyInstall;
use W4OS\Engine\Provisioning\MigrationScenario;
use W4OS\Engine\Provisioning\QuickSetup;
use W4OS\Engine\Provisioning\SimulatorPlan;
use W4OS\Engine\Provisioning\DatabaseService;
use W4OS\Engine\Provisioning\InstanceControl;
use W4OS\Engine\Provisioning\UiInterface;
use W4OS\Engine\Runtime\Environment;
use W4OS\Engine\Testing\Machine;

/** The answers of a person who knows what they want. */
function quick_answers(array $set = []): array
{
    return $set + [
        'type' => 'grid',
        'name' => 'Magic Grid',
        'login_uri' => 'http://login.example.org:8102',
        'avatar' => 'Ada Love',
        'password' => 'secret123',
        'email' => 'ada@example.org',
    ];
}

describe('quick setup, the address of the grid', function () {
    afterEach(fn() => QuickSetup::through());

    test('says in one line when its name does not resolve, or points to another machine of the network, before Start', function () {
        $environment = (new Machine())->environment;
        $warn = static function (array $ips) use ($environment): array {
            QuickSetup::through(['resolve' => static fn(string $host): array => $ips]);

            return QuickSetup::plan($environment, quick_answers(['login_uri' => 'grid.example.org:8102']))['warnings'] ?? [];
        };

        expect($warn([]))->toBe(['grid.example.org does not resolve yet: the grid will not answer at this address until its DNS does.'])
            ->and($warn(['192.168.200.9']))->toBe(['grid.example.org points to 192.168.200.9, not to this machine.'])
            // A public address may be this machine behind its router: nothing said
            ->and($warn(['203.0.113.7']))->toBe([])
            ->and($warn(['127.0.0.1']))->toBe([]);
    });
});

describe('quick setup, its web services', function () {
    test('are in the plan as they are chosen, and without the helpers 2do.directory\'s', function () {
        $environment = (new Machine())->environment;
        $chosen = QuickSetup::plan($environment, quick_answers([
            'register' => 'helpers', 'search' => 'shared', 'web_search' => 'internal', 'events_url' => 'https://events.example.org/calendar',
            'economy_path' => 'https://money.example.net/economy', 'site' => true, 'site_path' => '/opensim',
        ]))['setup']['grid'];
        $without = QuickSetup::plan($environment, quick_answers(['helpers' => false]))['setup']['grid'];

        expect(array_intersect_key($chosen, array_flip(['register', 'search', 'web_search', 'events_url', 'economy_path', 'web_site', 'site_path'])))->toEqual([
            'register' => 'helpers', 'search' => 'shared', 'web_search' => 'internal', 'events_url' => 'https://events.example.org/calendar',
            'economy_path' => 'https://money.example.net/economy', 'web_site' => 'website', 'site_path' => '/opensim',
        ])
            ->and([$without['register'] ?? null, $without['search'] ?? null, $without['web_search'] ?? null])->toBe(['2do', 'shared', 'shared']);
        // Under its folder, the site is the Quick Start website's own: the root of the site keeps what it has (no webroot-website)
        expect($chosen['options'] ?? [])->not->toContain('webroot-website');
    });
});

describe('quick setup of a grid', function () {
    test('offers SQLite as a simulator database driver', function () {
        $environment = (new Machine())->environment;
        $menu = QuickSetup::next($environment, ['mode' => 'prepare']);
        $form = QuickSetup::next($environment, [
            'mode' => 'prepare',
            'state' => $menu['state'],
            'answers' => ['type' => 'grid'],
        ]);
        $fields = array_column($form['fields'], null, 'name');
        $grid = QuickSetup::plan($environment, quick_answers(['database_driver' => 'sqlite']));

        // The short form of a grid asks no driver: the one proposed, another one given by a configuration
        expect(array_keys($fields))->toBe(['name', 'address', 'ports', 'avatar', 'password', 'email'])
            ->and($grid['valid'])->toBeTrue()
            ->and($grid['setup']['grid']['database']['driver'])->toBe('mysql')
            ->and($grid['setup']['simulators'][0]['database']['driver'])->toBe('sqlite');
    });

    test('keeps a container host for the simulator region', function () {
        $result = QuickSetup::plan((new Machine())->environment, quick_answers([
            'setup' => ['simulators' => [['external_host' => 'vonda.magiiic.com']]],
        ]));

        expect($result['setup']['simulators'][0]['external_host'])->toBe('vonda.magiiic.com');
    });

    test('prepares a SQLite standalone without asking for SQL accounts', function () {
        $environment = (new Machine())->environment;
        $menu = QuickSetup::next($environment, ['mode' => 'prepare']);
        $form = QuickSetup::next($environment, [
            'mode' => 'prepare',
            'state' => $menu['state'],
            'answers' => ['type' => 'standalone'],
        ]);
        $options = QuickSetup::next($environment, [
            'mode' => 'prepare',
            'state' => $form['state'],
            'answers' => [
                'name' => 'Solo World',
                'login_uri' => 'solo.example.org:8102',
                'avatar' => 'Ada Love',
                'password' => 'secret123',
                'password2' => 'secret123',
                'database_driver' => 'sqlite',
            ],
        ]);
        $web = QuickSetup::next($environment, ['mode' => 'prepare', 'state' => $options['state']]);
        $review = QuickSetup::next($environment, ['mode' => 'prepare', 'state' => $web['state']]);
        $prepared = QuickSetup::next($environment, ['mode' => 'prepare', 'state' => $review['state']]);

        expect($prepared['action'])->toBe('prepared')
            ->and($prepared['configuration']['setup']['grid']['database']['driver'])->toBe('sqlite');
    });

    test('uses deployment host ports for interactive port proposals', function () {
        $machine = new Machine();
        $machine->processes->listening = [8012, 9010];
        $menu = QuickSetup::next($machine->environment, ['mode' => 'prepare', 'occupied_ports' => [8002, 9000]]);
        $form = QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $menu['state'], 'answers' => ['type' => 'grid'], 'occupied_ports' => [8002, 9000],
        ]);
        $values = array_column($form['fields'], 'value', 'name');
        // The ports of the simulator, proposed free too, in the review
        $step = static fn(array $screen, array $answers = []): array => QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $screen['state'], 'answers' => $answers, 'occupied_ports' => [8002, 9000],
        ]);
        $review = $form;
        foreach ([array_replace(array_column($form['fields'], 'value', 'name'), ['avatar' => 'Ada Love', 'password' => 'secret123']), [], [], []] as $answers) {
            $review['kind'] === 'review' || ($review = $step($review, $answers));
        }

        expect($values['ports'])->toStartWith('8012 ')
            ->and($review['kind'])->toBe('review')
            ->and(array_column($review['rows'], 'value', 'name')['simulator_ports'] ?? '')->toStartWith('9010 http');
    });

    test('rejects a selected port already occupied on the deployment host', function () {
        $environment = (new Machine())->environment->onHostWithOccupiedPorts([8002]);
        $result = QuickSetup::plan($environment, quick_answers(['login_uri' => 'login.example.org:8002']));

        expect($result['problems'])
            ->toHaveKey('login_uri')
            ->and($result['problems']['login_uri'])
            ->toContain('already in use on the selected host');
    });

    test('describes TCP and UDP bindings for the prepared setup', function () {
        $result = QuickSetup::plan((new Machine())->environment, quick_answers());

        expect($result['port_bindings'])->toBe([
            ['port' => 8102, 'protocol' => 'tcp', 'use' => 'public', 'host_ip' => null],
            ['port' => 8103, 'protocol' => 'tcp', 'use' => 'private', 'host_ip' => '127.0.0.1'],
            ['port' => 8104, 'protocol' => 'tcp', 'use' => 'console', 'host_ip' => '127.0.0.1'],
            ['port' => 9100, 'protocol' => 'tcp', 'use' => 'public', 'host_ip' => null],
            ['port' => 9101, 'protocol' => 'udp', 'use' => 'public', 'host_ip' => null],
            ['port' => 9104, 'protocol' => 'tcp', 'use' => 'console', 'host_ip' => '127.0.0.1'],
        ]);
    });

    test('deduplicates the standalone login listener', function () {
        $result = QuickSetup::plan((new Machine())->environment, quick_answers(['type' => 'standalone']));
        $login = array_values(array_filter($result['port_bindings'], static fn(array $binding): bool => $binding['port'] === 8102));

        expect($login)->toBe([
            ['port' => 8102, 'protocol' => 'tcp', 'use' => 'public', 'host_ip' => null],
        ]);
    });

    test('is the grid and its first simulator, with the address, the avatar that owns them and the proposals for the rest', function () {
        $result = QuickSetup::plan((new Machine())->environment, quick_answers());
        $setup = $result['setup'];

        expect($result['valid'])
            ->toBeTrue()
            ->and($result['problems'])
            ->toBe([])
            ->and($setup['grid'])
            ->toMatchArray([
                'name' => 'Magic Grid',
                'nick' => 'magic_grid',
                'standalone' => false,
                'hostname' => 'login.example.org',
                'public_port' => 8102,
            ])
            ->and($setup['grid']['private_port'])
            ->not->toBe(8102)
            ->and($setup['simulators'])
            ->toHaveCount(1)
            ->and($setup['simulators'][0])
            ->toMatchArray(['name' => 'Welcome', 'estate' => ['name' => 'Magic Grid Estate', 'owner' => 'Ada Love']])
            ->and($setup['simulators'][0]['regions'][0]['name'])
            ->toBe('Welcome')
            ->and($setup['owner'])
            ->toMatchArray(['name' => 'Ada Love', 'email' => 'ada@example.org'])
            ->and(json_encode($result))
            ->not->toContain('secret123');
    });

    test('proposes everything when nothing is answered, and asks only for the avatar and its password', function () {
        $result = QuickSetup::plan((new Machine())->environment, []);

        expect($result['valid'])
            ->toBeFalse()
            ->and(array_keys($result['problems']))
            ->toBe(['avatar', 'password'])
            ->and($result['setup']['grid']['name'])
            ->not->toBe('')
            ->and($result['setup']['grid']['public_port'])
            ->toBeInt();
    });
});

describe('quick setup of a standalone', function () {
    test('is one simulator that is the whole grid: its name, its region, its port, its database', function () {
        $setup = QuickSetup::plan((new Machine())->environment, quick_answers(['type' => 'standalone', 'name' => 'My Island']))['setup'];

        expect($setup['grid'])
            ->toMatchArray(['name' => 'My Island', 'standalone' => true, 'public_port' => 8102, 'private_port' => 8102])
            ->and($setup['simulators'])
            ->toHaveCount(1)
            ->and($setup['simulators'][0])
            ->toMatchArray(['name' => 'My Island', 'http_port' => 8102])
            ->and($setup['simulators'][0]['regions'][0]['name'])
            ->toBe('My Island')
            ->and($setup['simulators'][0]['database']['name'])
            ->toBe($setup['grid']['database']['name']);
    });
});

describe('quick setup, what is wrong', function () {
    test('is told by the name of the question', function () {
        $result = QuickSetup::plan((new Machine())->environment, [
            'type' => 'island',
            'login_uri' => 'https://login.example.org',
            'avatar' => 'Ada',
            'password' => '',
            'email' => 'not an address',
        ]);

        expect($result['valid'])
            ->toBeFalse()
            ->and(array_keys($result['problems']))
            ->toEqualCanonicalizing(['type', 'login_uri', 'avatar', 'password', 'email']);
    });

    test('has the hypergrid unless it is said to be off', function () {
        $environment = (new Machine())->environment;

        expect(QuickSetup::plan($environment, quick_answers())['setup']['grid']['hypergrid'])
            ->toBeTrue()
            ->and(QuickSetup::plan($environment, quick_answers(['hypergrid' => false]))['setup']['grid']['hypergrid'])
            ->toBeFalse()
            ->and(QuickSetup::plan($environment, quick_answers(['hypergrid' => 'no']))['setup']['grid']['hypergrid'])
            ->toBeFalse()
            ->and(QuickSetup::plan($environment, quick_answers(['hypergrid' => 'maybe']))['problems'])
            ->toHaveKey('hypergrid');
    });

    test('takes a login address as a host and a port, with or without http, and nothing else', function (string $uri, ?string $host, ?int $port) {
        $result = QuickSetup::plan((new Machine())->environment, quick_answers(['login_uri' => $uri]));

        if ($host === null) {
            expect($result['problems'])->toHaveKey('login_uri');

            return;
        }
        expect($result['problems'])
            ->not->toHaveKey('login_uri')
            ->and($result['setup']['grid']['hostname'])
            ->toBe($host)
            ->and($result['setup']['grid']['public_port'])
            ->toBe($port ?? $result['setup']['grid']['public_port']);
    })->with([
        'a host' => ['login.example.org', 'login.example.org', null],
        'a host and a port' => ['login.example.org:8102', 'login.example.org', 8102],
        'with http' => ['http://login.example.org:8102/', 'login.example.org', 8102],
        'an address' => ['127.0.0.1:9000', '127.0.0.1', 9000],
        'a typo of the scheme' => ['htp:/login.example.org:8102', null, null],
        'another scheme' => ['ftp://login.example.org', null, null],
        'a space' => ['login example.org', null, null],
        'a port out of range' => ['login.example.org:99999', null, null],
        'https, not handled yet' => ['https://login.example.org', null, null],
    ]);

    test('lists every value it defines, by section, for whoever shows the setup', function () {
        $rows = QuickSetup::plan((new Machine())->environment, quick_answers())['rows'];
        $labels = array_column($rows, 'label');

        expect(array_values(array_unique(array_column($rows, 'section'))))
            ->toBe(['grid', 'options', 'web', 'simulator', 'region', 'owner'])
            ->and($labels)
            ->toContain('Grid name', 'Login URI', 'Hypergrid', 'Grid database', 'Simulator', 'Region', 'Port', 'Avatar')
            ->and($rows[1])
            ->toBe(['section' => 'grid', 'label' => 'Login URI', 'value' => 'login.example.org:8102'])
            ->and(json_encode($rows))
            ->not->toContain('secret123');
    });

    test('shows the options chosen and the ones that are on whatever the answers, with what the setup does not apply yet', function () {
        $quick = QuickSetup::plan((new Machine())->environment, quick_answers(['economy-gloebit' => true, 'search' => 'yes']));
        $options = array_column(array_filter($quick['rows'], static fn(array $r): bool => $r['section'] === 'options'), 'value', 'label');
        $web = array_column(array_filter($quick['rows'], static fn(array $r): bool => $r['section'] === 'web'), 'value', 'label');

        expect($options['Hypergrid'])
            ->toBe('yes')
            ->and($options['Economy: Gloebit'])
            ->toBe('yes')
            // A setup of before: Cross-grid Search yes, the in-world search of 2do.directory
            ->and($web['In-world search'])
            ->toBe('2do.directory')
            ->and($options['SSL'])
            ->toBe('no (not implemented)')
            ->and($options['Extended OSSL'])
            ->toBe('yes')
            ->and(array_keys($options))
            ->toContain('Helpers', 'Search module (OpenSimSearch)', 'Offline messages: the grid (module V2)', 'Profiles', 'Destination guide', 'Grid status');
    });

    test('has the helpers, their search and the extended OSSL on by default', function () {
        $quick = QuickSetup::plan((new Machine())->environment, quick_answers());

        expect($quick['setup']['grid']['helpers'])
            ->toBeTrue()
            ->and($quick['setup']['grid']['search'])
            ->toBe('internal')
            ->and($quick['setup']['grid']['economy'])
            ->toBe('beta')
            ->and($quick['setup']['grid']['options'])
            ->toContain('ossl-extended', 'search', 'profiles', 'offline-robust')
            ->and(array_column($quick['options'], 'value', 'name'))
            ->toBe(['profiles' => true, 'groups' => true, 'search_module' => true, 'offline' => true, 'map' => false, 'ssl' => false, 'hypergrid' => true, 'helpers' => true, 'economy-gloebit' => false, 'ossl-extended' => true]);
    });

    test('has no search of its own when the helpers are off and the shared one is not wanted, and refuses Gloebit without the helpers', function () {
        $environment = (new Machine())->environment;
        $off = QuickSetup::plan($environment, quick_answers(['helpers' => false, 'search' => false]));
        $gloebit = QuickSetup::plan($environment, quick_answers(['helpers' => false, 'economy-gloebit' => true]));

        expect($off['problems'])
            ->toBe([])
            ->and($off['setup']['grid']['search'])
            ->toBe('disabled')
            ->and($off['setup']['grid']['helpers'])
            ->toBeFalse()
            ->and($gloebit['problems'])
            ->toBe(['economy-gloebit' => 'needs Helpers']);
    });

    test('tells the options and the types, for whoever shows the form', function () {
        $quick = QuickSetup::plan((new Machine())->environment, quick_answers());

        expect(array_column($quick['options'], 'label', 'name'))
            ->toBe([
                'profiles' => 'profiles',
                'groups' => 'groups',
                'search_module' => 'search',
                'offline' => 'offline messages',
                'map' => '3D map',
                'ssl' => 'SSL',
                'hypergrid' => 'Hypergrid',
                'helpers' => 'Helpers',
                'economy-gloebit' => 'Economy: Gloebit',
                'ossl-extended' => 'Extended OSSL',
            ])
            ->and(array_column($quick['options'], 'implemented', 'name'))
            ->toBe(['profiles' => true, 'groups' => true, 'search_module' => true, 'offline' => true, 'map' => true, 'ssl' => false, 'hypergrid' => true, 'helpers' => true, 'economy-gloebit' => true, 'ossl-extended' => true])
            ->and($quick['setup']['grid']['options'])
            ->toContain('ossl-extended')
            ->and($quick['types'])
            ->toBe([
                ['name' => 'grid', 'label' => 'Grid', 'implemented' => true],
                ['name' => 'standalone', 'label' => 'Standalone', 'implemented' => true],
                ['name' => 'simulator', 'label' => 'Simulator (join other grid)', 'implemented' => true],
                ['name' => 'existing', 'label' => 'Existing install (migrate its grid)', 'implemented' => true],
                ['name' => 'speculoos', 'label' => 'Speculoos', 'implemented' => true],
                ['name' => 'osgrid', 'label' => 'OSGrid', 'implemented' => true],
            ]);
    });

    test('says a grid that exists has a name that is taken, and nothing of its regions', function () {
        $environment = (new Machine())->environment;

        expect(QuickSetup::plan($environment, quick_answers(['name' => 'Demo']))['problems'])
            ->toBe(['name' => 'taken'])
            ->and(QuickSetup::plan($environment, quick_answers(['name' => 'Demo', 'type' => 'standalone']))['problems'])
            ->toBe(['name' => 'taken']);
    });

    test('proposes the password the grids of the machine already use for the same database account', function () {
        $machine = new Machine();
        $robust = $machine->grid . '/Robust.HG.ini';
        file_put_contents(
            $robust,
            (string) file_get_contents($robust) .
                "\n[DatabaseService]\n    ConnectionString = \"Data Source=localhost;Database=demo_robust;User ID=opensim;Password=sharedpass1234;Old Guids=true;\"\n",
        );

        $answers = QuickSetup::plan($machine->environment, quick_answers());
        $planner = \W4OS\Engine\Provisioning\GridPlanner::forMachine($machine->environment);

        expect($answers['valid'])
            ->toBeTrue()
            ->and($planner->plan(['name' => 'Other Grid'])->dbPass)
            ->toBe('sharedpass1234')
            ->and($planner->plan(['name' => 'Other Grid', 'database_password' => 'mine'])->dbPass)
            ->toBe('mine');
    });

    test('refuses a type it does not know, and a boolean that is not one', function () {
        $environment = (new Machine())->environment;

        expect(QuickSetup::plan($environment, quick_answers(['type' => 'nothing']))['problems'])
            ->toBe(['type' => 'invalid'])
            ->and(QuickSetup::plan($environment, quick_answers(['ossl-extended' => 'maybe']))['problems'])
            ->toBe(['ossl-extended' => 'invalid']);
    });

    test('keeps a login address that has no scheme or no port', function () {
        $grid = QuickSetup::plan((new Machine())->environment, quick_answers(['login_uri' => 'login.example.org']))['setup']['grid'];

        expect($grid['hostname'])->toBe('login.example.org')->and($grid['public_port'])->toBeInt();
    });
});

describe('quick setup through the API', function () {
    test('is asked with a document and answered with a document', function () {
        $router = new Router(new Api((new Machine())->environment));

        $answer = $router->post('/setups/quick', [
            'data' => ['type' => 'setups', 'attributes' => quick_answers(['type' => 'standalone'])],
        ]);
        $data = json_decode($answer->body(), true)['data'];

        expect($answer->status)
            ->toBe(200)
            ->and([$data['type'], $data['id']])
            ->toBe(['setups', 'quick'])
            ->and($data['attributes']['valid'])
            ->toBeTrue()
            ->and($router->get('/setups/quick')->status)
            ->toBe(405)
            ->and($router->post('/setups/quick', '{"data":{"type":"forms"}}')->status)
            ->toBe(409);
    });
});

describe('quick setup, the words of the problems', function () {
    test('are one or two words, the label is the display\'s', function () {
        $problems = QuickSetup::plan((new Machine())->environment, [
            'type' => 'island',
            'login_uri' => 'x y',
            'avatar' => 'Ada',
            'password' => 'ab',
            'email' => 'nope',
        ])['problems'];

        expect($problems)->toBe([
            'type' => 'invalid',
            'login_uri' => 'invalid (host:port)',
            'avatar' => 'invalid (First Last)',
            'email' => 'invalid',
        ]);
        // Any password OpenSim takes: a short one, a double quote, a line break
        expect(QuickSetup::plan((new Machine())->environment, quick_answers(['password' => 'ab"cdef', 'password2' => 'ab"cdef']))['problems'])->toBe([])
            ->and(QuickSetup::plan((new Machine())->environment, quick_answers(['password' => "ab\ncd", 'password2' => "ab\ncd"]))['problems'])->toBe([])->and(array_keys(QuickSetup::plan((new Machine())->environment, [])['problems']))->toBe(['avatar', 'password']);
        expect(QuickSetup::plan((new Machine())->environment, [])['problems']['avatar'])->toBe('required');
    });
});

describe('quick setup, the names and the host', function () {
    test('lowers the case of the host, whatever way it is given', function () {
        $environment = (new Machine())->environment;

        expect(QuickSetup::plan($environment, quick_answers(['login_uri' => 'HTTP://Login.Example.ORG:8102']))['setup']['grid']['hostname'])
            ->toBe('login.example.org');
    });

    test('replaces the accents of an avatar name and asks the person to validate the change', function () {
        $environment = (new Machine())->environment;

        $first = QuickSetup::plan($environment, quick_answers(['avatar' => 'José García']));
        $again = QuickSetup::plan($environment, quick_answers(['avatar' => $first['corrected']['avatar']]));

        expect($first['valid'])
            ->toBeFalse()
            ->and($first['problems'])
            ->toBe(['avatar' => 'transliterated'])
            ->and($first['corrected'])
            ->toBe(['avatar' => 'Jose Garcia'])
            ->and($again['valid'])
            ->toBeTrue()
            ->and($again['corrected'])
            ->toBe([]);
    });

    test('does the same for the name of a standalone, which is its region, and checks the rest of it', function () {
        $environment = (new Machine())->environment;

        $first = QuickSetup::plan($environment, quick_answers(['type' => 'standalone', 'name' => 'Île Noël']));
        $bad = QuickSetup::plan($environment, quick_answers(['type' => 'standalone', 'name' => 'My/Island']));
        $grid = QuickSetup::plan($environment, quick_answers(['name' => 'Île Noël']));

        expect($first['corrected'])
            ->toBe(['name' => 'Ile Noel'])
            ->and($first['problems'])
            ->toBe(['name' => 'transliterated: OpenSim\'s grid info cannot send non-ASCII characters'])
            ->and($first['setup']['simulators'][0]['regions'][0]['name'])
            ->toBe('Ile Noel')
            ->and($bad['problems'])
            ->toBe(['name' => 'invalid (letters, digits, space)'])
            ->and($grid['corrected'])
            ->toBe(['name' => 'Ile Noel']);
    });
});

describe('quick setup, what separates and what is a space', function () {
    test('refuses the dot, the at sign and the underscore in the name of an avatar: the hypergrid\'s, and taken for a space', function (string $name) {
        expect(QuickSetup::plan((new Machine())->environment, quick_answers(['avatar' => $name]))['problems'])
            ->toBe(['avatar' => 'invalid (First Last)']);
    })->with(['a dot' => 'Ada.Love Smith', 'an at sign' => 'Ada@Love Smith', 'an underscore' => 'Ada_Love Smith', 'three words' => 'Ada Love Smith', 'one word' => 'Ada']);

    test('accepts the hyphen in the name of an avatar', function () {
        expect(QuickSetup::plan((new Machine())->environment, quick_answers(['avatar' => 'Ada-Marie Love-Smith']))['problems'])->toBe([]);
    });

    test('refuses the underscore in the name of a region: it is a space for Robust', function () {
        expect(QuickSetup::plan((new Machine())->environment, quick_answers(['type' => 'standalone', 'name' => 'My_Island']))['problems'])
            ->toBe(['name' => 'invalid (letters, digits, space)']);
    });

    test('transliterates the names of every alphabet', function () {
        $first = QuickSetup::plan((new Machine())->environment, quick_answers(['avatar' => 'Владимир Путин']));

        expect($first['corrected']['avatar'])
            ->toMatch('/^[A-Za-z0-9]+ [A-Za-z0-9]+$/')
            ->and($first['problems'])
            ->toBe(['avatar' => 'transliterated']);
    });

    test('refuses a domain name in unicode: the viewers refuse it in the address of a grid', function () {
        $quick = QuickSetup::plan((new Machine())->environment, quick_answers(['login_uri' => 'Bücher.Example:8102']));

        expect($quick['problems'])->toBe(['login_uri' => 'invalid (host:port)']);
    });

    test('gives a standalone the name of its grid for its simulator and its region, the dots being spaces', function () {
        $quick = QuickSetup::plan((new Machine())->environment, quick_answers(['type' => 'standalone', 'name' => 'Solo.World']));

        expect($quick['problems'])
            ->toBe([])
            ->and($quick['setup']['grid']['name'])
            ->toBe('Solo.World')
            ->and($quick['setup']['simulators'][0]['regions'][0]['name'])
            ->toBe('Solo World');
    });
});

describe('quick setup, the alphabets', function () {
    test('transliterates the grid name and the avatar name, and removes what a name cannot have after that', function () {
        $arabic = QuickSetup::plan((new Machine())->environment, quick_answers(['name' => 'مرحباً', 'avatar' => 'مرحباً Lapointe']));

        expect($arabic['corrected'])
            ->toBe(['name' => 'mrhbaan', 'avatar' => 'mrhbaan Lapointe'])
            ->and($arabic['problems'])
            ->toBe(['name' => 'transliterated: OpenSim\'s grid info cannot send non-ASCII characters', 'avatar' => 'transliterated'])
            ->and($arabic['setup']['grid']['name'])
            ->toBe('mrhbaan');
    });

    test('leaves an error when what comes out is not what a name must be: three words are not First Last', function () {
        $three = QuickSetup::plan((new Machine())->environment, quick_answers(['avatar' => '你好嗎']));
        $mixed = QuickSetup::plan((new Machine())->environment, quick_answers(['avatar' => '早安 Lapointe']));
        $two = QuickSetup::plan((new Machine())->environment, quick_answers(['avatar' => '你好']));

        expect($three['corrected'])
            ->toBe(['avatar' => 'Ni Hao Ma'])
            ->and($three['problems'])
            ->toBe(['avatar' => 'invalid (First Last)'])
            ->and($mixed['corrected'])
            ->toBe(['avatar' => 'zao an Lapointe'])
            ->and($mixed['problems'])
            ->toBe(['avatar' => 'invalid (First Last)'])
            // Two words come out: right, but the person sees the change and validates it
            ->and($two['corrected'])
            ->toBe(['avatar' => 'Ni Hao'])
            ->and($two['problems'])
            ->toBe(['avatar' => 'transliterated']);
    });

    test('does not touch a Latin name, even with an apostrophe: the validation says what is wrong', function () {
        $grid = QuickSetup::plan((new Machine())->environment, quick_answers(['name' => "Olivier's Grid"]));

        expect($grid['corrected'])->toBe([])->and($grid['problems'])->toBe([]);
    });
});

/** One step of the scenario: the reply to the screen before it (none for the first), filled and with the button pressed. */
function quick_step(Machine $machine, ?array $before, array $answers = [], string $button = 'next'): array
{
    return QuickSetup::next($machine->environment, ['state' => $before['state'] ?? '', 'button' => $button, 'answers' => $answers]);
}

/** The form answers of a right setup. */
function quick_form(array $set = []): array
{
    return $set + [
        'name' => 'Magic Grid',
        'login_uri' => 'login.example.org:8102',
        'avatar' => 'Ada Love',
        'password' => 'secret123',
        'password2' => 'secret123',
        'email' => 'ada@example.org',
    ];
}

/** The scenario up to a screen: the types, then the type chosen, then the form, then the options, then the web services. */
function quick_until(Machine $machine, string $screen, string $type = 'grid', array $form = [], array $options = []): array
{
    $reply = quick_step($machine, null);
    if ($screen === 'menu') {
        return $reply;
    }
    $reply = quick_admin($machine, quick_step($machine, $reply, ['type' => $type]));
    if ($screen === 'form') {
        return $reply;
    }
    $reply = quick_user($machine, quick_step($machine, $reply, quick_form($form)));
    if ($screen === 'checks') {
        return $reply;
    }
    $reply = quick_step($machine, $reply, $options);

    // The web services, when the helpers are on, as they are proposed
    return $screen !== 'web' && in_array($reply['title'] ?? '', ['Web', 'Web services'], true) ? quick_step($machine, $reply, []) : $reply;
}

/** The DB admin user proposed, confirmed, made as root by the client: the screen after it. */
function quick_admin(Machine $machine, array $reply): array
{
    if (($reply['name'] ?? '') !== 'db-admin') {
        return $reply;
    }
    $reply = quick_step($machine, $reply, []);

    return ($reply['action'] ?? '') === 'step' ? quick_step($machine, $reply, []) : $reply;
}

/** The database user of a grid, as it is proposed: the screen after it. */
function quick_user(Machine $machine, array $reply): array
{
    return ($reply['name'] ?? '') === 'database_user' ? quick_step($machine, $reply, []) : $reply;
}

/** The work that needs a machine, done by doubles: the databases are fine, the account is made, the grid says who it is, the instances start. */
function quick_doubles(array $set = []): void
{
    // The DB admin user proposed manages the server, whatever else is doubled
    isset($set['region']) && ($set['region'] += ['manages' => static fn(string $user, string $password): bool => true]);
    QuickSetup::through(
        $set + [
            'database' => static fn(): int => DatabaseService::OK,
            'account' => static fn(): bool => true,
            'says' => static fn(string $host, int $port): array => ['gridname' => 'Remote World', 'gridnick' => 'remote'],
            'region' => [
                'remoteHas' => static fn(string $name): ?bool => false,
                'freePlace' => static fn(int $x, int $y, ?string $ignored): array => [$x, $y, true],
                'hasAccount' => static fn(string $name): ?bool => true,
                'accountHosts' => static fn($plan): ?array => [],
                'manages' => static fn(string $user, string $password): bool => true,
            ],
            'legacy' => static fn(): LegacyInstall => new LegacyInstall('/nonexistent/opt/opensim', '/nonexistent/cron'),
        ],
    );
    InstanceControl::runThrough(static fn(): array => [0, '']);
}

/** The files of the distribution a grid is made of, in the fake core of a machine. */
function quick_core(Machine $machine): void
{
    $bin = $machine->core . '/bin';
    @mkdir("$bin/config-include", 0o755, true);
    file_put_contents("$bin/OpenSimDefaults.ini", "[Startup]\n");
    file_put_contents("$bin/OpenSim.ini", "[Startup]\n");
    foreach (['GridHypergrid', 'Grid', 'GridCommon', 'FlotsamCache', 'osslDefaultEnable'] as $name) {
        file_put_contents("$bin/config-include/$name.ini", "[OSSL]\n");
    }
    file_put_contents("$bin/config-include/osslEnable.ini.example", "[OSSL]\n    AllowOSFunctions = false\n");
}

test('advertises setup capabilities', function () {
    $machine = new Machine();
    $response = (new Api($machine->environment))->quickSetup(quick_answers());
    $attributes = json_decode($response->body(), true)['data']['attributes'];
    expect($attributes['capabilities'])->toContain('prepare', 'apply_prepared', 'existing_region')
        ->and(is_dir($machine->etc . '/grids/magic_grid'))->toBeFalse();
});

describe('existing region setup', function () {
    beforeEach(function () {
        quick_doubles(['region' => [
            'remoteHas' => static fn(): bool => true,
            'registeredUuid' => static fn(): string => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        ]]);
    });
    afterEach(function () {
        QuickSetup::through();
        InstanceControl::runThrough(null);
    });

    test('plans existing region', function () {
        $result = QuickSetup::plan((new Machine())->environment, quick_answers([
            'type' => 'simulator', 'name' => 'Imported', 'database_name' => 'existing_db',
            'existing_region' => file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini'),
        ]));
        expect($result['valid'])->toBeTrue()
            ->and($result['identity'])->toBe('remote.imported')
            ->and($result['setup']['simulators'][0]['regions'][0])->toMatchArray([
                'name' => 'Existing Garden', 'uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
                'location' => '120,130', 'port' => 9101,
            ]);
    });

    test('prepares existing region', function () {
        $machine = new Machine();
        $source = file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini');
        $menu = QuickSetup::next($machine->environment, ['mode' => 'prepare']);
        $form = QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $menu['state'], 'answers' => ['type' => 'simulator', 'existing_region' => $source],
        ]);
        expect(array_column($form['fields'], 'value', 'name')['name'])->toBe('Existing Garden')
            ->and(array_column($form['fields'], 'label', 'name')['database_name'])->toBe('Existing database');
        $review = QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $form['state'],
            'answers' => quick_answers(['type' => 'simulator', 'name' => 'Imported', 'database_name' => 'existing_db']),
        ]);
        $database = QuickSetup::next($machine->environment, ['mode' => 'prepare', 'state' => $review['state']]);
        $prepared = QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $database['state'], 'answers' => array_column($database['fields'], 'value', 'name'),
        ]);
        expect($prepared['action'])->toBe('prepared')
            ->and($prepared['configuration']['answers']['existing_region'])->toBe($source)
            ->and($prepared['configuration']['setup']['simulators'][0]['regions'][0]['source'])->toBe($source)
            ->and(is_dir($machine->etc . '/grids/remote'))->toBeFalse();
    });

    test('stages existing region at the address its grid knows it by', function () {
        quick_doubles([
            'database' => static fn(): int => throw new RuntimeException('unexpected database provisioning'),
            'account' => static fn(): bool => throw new RuntimeException('unexpected account creation'),
            'region' => [
                'registeredUuid' => static fn(): string => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
                'registeredRecord' => static fn(): array => ['address' => 'garden.example.org', 'server' => 'http://garden.example.org:9101/'],
            ],
        ]);
        InstanceControl::runThrough(static fn(): array => throw new RuntimeException('unexpected startup'));
        $answers = quick_answers([
            'type' => 'simulator', 'name' => 'Imported', 'database_name' => 'existing_db',
            'existing_region' => file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini'),
        ]);
        $planned = QuickSetup::plan((new Machine())->environment, $answers);
        $target = new Machine();
        QuickSetup::next($target->environment, ['configuration' => [
            'version' => 1, 'identity' => $planned['identity'], 'answers' => $answers, 'setup' => $planned['setup'],
        ]]);

        expect(file_get_contents($target->etc . '/grids/remote/sims/remote.imported/regions/ExistingGarden.ini'))
            ->toContain('ExternalHostName = garden.example.org', 'CustomSetting = preserve-me');
    });

    test('stages existing region', function () {
        quick_doubles([
            'database' => static fn(): int => throw new RuntimeException('unexpected database provisioning'),
            'account' => static fn(): bool => throw new RuntimeException('unexpected account creation'),
            'region' => [
                'registeredUuid' => static fn(): string => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
                // Where SYSTEMIP sent viewers on the machine it ran on: an address of a private network, never kept
                'registeredRecord' => static fn(): array => ['address' => '10.0.2.15', 'server' => 'http://10.0.2.15:9101/'],
            ],
        ]);
        InstanceControl::runThrough(static fn(): array => throw new RuntimeException('unexpected startup'));
        $source = new Machine();
        $answers = quick_answers([
            'type' => 'simulator', 'name' => 'Imported', 'database_name' => 'existing_db',
            'existing_region' => file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini'),
        ]);
        $planned = QuickSetup::plan($source->environment, $answers);
        $target = new Machine();
        $done = QuickSetup::next($target->environment, ['configuration' => [
            'version' => 1, 'identity' => $planned['identity'], 'answers' => $answers, 'setup' => $planned['setup'],
        ]]);
        $host = \W4OS\Engine\Models\Grid::find($target->environment, 'remote')?->baseHostname();
        $adopted = str_replace('ExternalHostName = SYSTEMIP', "ExternalHostName = $host", file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini'));
        expect($done['action'])->toBe('quit')
            ->and($done['message'])->toContain('disabled')
            ->and($host)->not->toBe('')
            ->and(file_get_contents($target->etc . '/grids/remote/sims/remote.imported/regions/ExistingGarden.ini'))
            ->toBe($adopted)
            ->and(glob($target->etc . '/opensim.d/remote.imported.ini'))->toBe([]);
        $again = QuickSetup::next($target->environment, ['configuration' => [
            'version' => 1, 'identity' => $planned['identity'], 'answers' => $answers, 'setup' => $planned['setup'],
        ]]);
        expect($again['action'])->toBe('quit')
            ->and(file_get_contents($target->etc . '/grids/remote/sims/remote.imported/regions/ExistingGarden.ini'))
            ->toBe($adopted);
        $path = $target->etc . '/grids/remote/sims/remote.imported/regions/ExistingGarden.ini';
        $edited = str_replace('preserve-me', 'locally-edited', file_get_contents($path));
        file_put_contents($path, $edited);
        $conflict = QuickSetup::next($target->environment, ['configuration' => [
            'version' => 1, 'identity' => $planned['identity'], 'answers' => $answers, 'setup' => $planned['setup'],
        ]]);
        expect($conflict['action'])->toBe('abort')
            ->and($conflict['error'])->toContain('target region')
            ->and(file_get_contents($path))->toBe($edited);
    });

    test('rejects conflicting UUID', function () {
        quick_doubles(['region' => ['registeredUuid' => static fn(): string => 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff']]);
        $result = QuickSetup::plan((new Machine())->environment, quick_answers([
            'type' => 'simulator', 'name' => 'Imported', 'database_name' => 'existing_db',
            'existing_region' => file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini'),
        ]));
        expect($result['valid'])->toBeFalse()
            ->and($result['problems']['existing_region'])->toContain('another UUID');
    });

    test('rejects UUID/name conflict', function () {
        quick_doubles(['region' => [
            'registeredUuid' => static fn(): bool => false,
            'registeredName' => static fn(): string => 'Another Garden',
        ]]);
        $result = QuickSetup::plan((new Machine())->environment, quick_answers([
            'type' => 'simulator', 'name' => 'Imported', 'database_name' => 'existing_db',
            'existing_region' => file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini'),
        ]));
        expect($result['valid'])->toBeFalse()
            ->and($result['problems']['existing_region'])->toContain('another name');
    });

    test('requires existing database', function () {
        $result = QuickSetup::plan((new Machine())->environment, quick_answers([
            'type' => 'simulator', 'name' => 'Imported',
            'existing_region' => file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini'),
        ]));
        expect($result['valid'])->toBeFalse()
            ->and($result['problems'])->toHaveKey('database_name');
    });
});

describe('setup preparation', function () {
    beforeEach(fn() => quick_doubles());
    afterEach(function () {
        QuickSetup::through();
        InstanceControl::runThrough(null);
    });

    test('collects database answers without provisioning', function () {
        $machine = new Machine();
        $called = false;
        quick_doubles(['database' => static function () use (&$called): int {
            $called = true;
            return DatabaseService::OK;
        }]);
        $review = quick_until($machine, 'review');
        $database = QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $review['state'],
        ]);
        expect($database)->toMatchArray(['action' => 'screen', 'kind' => 'form', 'title' => 'Database']);
        $answers = array_column($database['fields'], 'value', 'name');
        $answers['admin_user'] = 'opensim_admin';
        $answers['admin_password'] = 'admin-secret';
        $done = QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $database['state'], 'answers' => $answers,
        ]);

        expect($done['action'])->toBe('prepared')
            ->and($done['configuration']['answers'])->toMatchArray([
                'type' => 'grid', 'name' => 'Magic Grid', 'password' => 'secret123',
                'admin_user' => 'opensim_admin', 'admin_password' => 'admin-secret',
            ])
            ->and($done['configuration']['port_bindings'])
            ->toBe(QuickSetup::plan($machine->environment, quick_answers())['port_bindings'])
            ->and($done['configuration']['setup']['grid']['nick'])->toBe('magic_grid')
            ->and($called)->toBeFalse()
            ->and(is_dir($machine->etc . '/grids/magic_grid'))->toBeFalse();
    });

    test('prepares the instance identity', function (string $type, string $expected) {
        $machine = new Machine();
        $review = quick_until($machine, 'review', $type);
        $database = QuickSetup::next($machine->environment, ['mode' => 'prepare', 'state' => $review['state']]);
        $done = QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $database['state'],
            'answers' => array_column($database['fields'], 'value', 'name'),
        ]);
        expect($done['configuration']['identity'])->toBe($expected);
    })->with(['grid' => ['grid', 'magic_grid'], 'standalone' => ['standalone', 'magic_grid.magic_grid']]);

    test('applies confirmed ports', function () {
        $source = new Machine();
        $source->processes->listening = [9100];
        $review = quick_until($source, 'review');
        $database = QuickSetup::next($source->environment, ['mode' => 'prepare', 'state' => $review['state']]);
        $prepared = QuickSetup::next($source->environment, [
            'mode' => 'prepare', 'state' => $database['state'],
            'answers' => array_column($database['fields'], 'value', 'name'),
        ])['configuration'];
        $configuration = json_decode(json_encode($prepared), true);
        $target = new Machine();
        $done = QuickSetup::next($target->environment, ['configuration' => $configuration]);
        expect($done['action'])->toBe('quit')
            ->and(file_get_contents($target->etc . '/grids/magic_grid/sims/magic_grid.welcome.ini'))->toContain('9110')
            ->and(file_get_contents($target->etc . '/grids/magic_grid/sims/magic_grid.welcome/regions/Welcome.ini'))->toContain('9111')
            ->and($prepared['setup']['grid']['console_port'])->toBeInt()
            ->and($prepared['setup']['simulators'][0]['console_port'])->toBe(9114);
    });

    test('refuses a changed prepared location', function () {
        $source = new Machine();
        $review = quick_until($source, 'checks', 'simulator', ['name' => 'Garden']);
        $database = QuickSetup::next($source->environment, ['mode' => 'prepare', 'state' => $review['state']]);
        $configuration = QuickSetup::next($source->environment, [
            'mode' => 'prepare', 'state' => $database['state'],
            'answers' => array_column($database['fields'], 'value', 'name'),
        ])['configuration'];
        quick_doubles(['region' => [
            'remoteHas' => static fn(): bool => false,
            'freePlace' => static fn(int $x, int $y): array => [$x + 1, $y, true],
        ]]);
        $target = new Machine();
        $result = QuickSetup::next($target->environment, ['configuration' => $configuration]);
        expect($result['action'])->toBe('abort')
            ->and($result['error'])->toContain('location')
            ->and(is_dir($target->etc . '/grids/remote'))->toBeFalse();
    });

    test('rejects a changed prepared identity', function () {
        $machine = new Machine();
        $result = QuickSetup::next($machine->environment, ['configuration' => [
            'version' => 1, 'identity' => 'another_grid', 'answers' => quick_answers(),
            'setup' => QuickSetup::plan($machine->environment, quick_answers())['setup'],
        ]]);
        expect($result)->toMatchArray(['action' => 'abort'])
            ->and($result['error'])->toContain('identity')
            ->and(is_dir($machine->etc . '/grids/magic_grid'))->toBeFalse();
    });

    test('rejects an invalid prepared port', function () {
        $machine = new Machine();
        $setup = QuickSetup::plan($machine->environment, quick_answers())['setup'];
        $setup['simulators'][0]['http_port'] = 0;
        $result = QuickSetup::next($machine->environment, ['configuration' => [
            'version' => 1, 'identity' => 'magic_grid', 'answers' => quick_answers(), 'setup' => $setup,
        ]]);
        expect($result['action'])->toBe('abort')
            ->and(is_dir($machine->etc . '/grids/magic_grid'))->toBeFalse();
    });

    test('keeps preparation cancellable', function () {
        $machine = new Machine();
        $review = quick_until($machine, 'review');
        $database = QuickSetup::next($machine->environment, ['mode' => 'prepare', 'state' => $review['state']]);
        $back = QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $database['state'], 'button' => 'back',
        ]);
        expect($back['kind'])->toBe('review')
            ->and($back['buttons']['next'])->toBe('Continue')
            ->and(QuickSetup::next($machine->environment, [
                'mode' => 'prepare', 'state' => $database['state'], 'button' => 'quit',
            ])['action'])->toBe('abort')
            ->and(is_dir($machine->etc . '/grids/magic_grid'))->toBeFalse();
    });

    test('rejects an unknown mode before applying setup', function () {
        expect(fn() => QuickSetup::next((new Machine())->environment, ['mode' => 'typo']))
            ->toThrow(InvalidArgumentException::class, 'Unknown setup mode');
    });

    test('keeps answers over configured defaults', function () {
        $previous = getenv('DEFAULT_OWNER_NAME');
        putenv('DEFAULT_OWNER_NAME=Default Resident');
        try {
            $machine = new Machine();
            $options = quick_until($machine, 'checks');
            $form = quick_step($machine, quick_step($machine, $options, [], 'back'), [], 'back');
            expect(array_column($form['fields'], 'value', 'name')['avatar'])->toBe('Ada Love');
        } finally {
            putenv($previous === false ? 'DEFAULT_OWNER_NAME' : 'DEFAULT_OWNER_NAME=' . $previous);
        }
    });
});

describe('quick setup, the scenario', function () {
    beforeEach(fn() => quick_doubles());
    afterEach(function () {
        QuickSetup::through();
        InstanceControl::runThrough(null);
    });

    test('asks the address of the website alone after the options, the other web services proposed in the review', function () {
        $machine = new Machine();
        $web = quick_step($machine, quick_until($machine, 'checks'), []);
        $review = quick_step($machine, $web, ['website' => 'https://login.example.org/opensim/']);
        $rows = array_column($review['rows'] ?? [], 'value', 'name');

        expect($web['title'] ?? null)->toBe('Web')
            ->and(array_column($web['fields'] ?? [], 'label', 'name'))->toBe(['website' => 'Website address'])
            ->and(array_column($web['fields'] ?? [], 'value', 'name')['website'])->toBe('https://login.example.org/')
            ->and($review['kind'])->toBe('review')
            ->and($rows)->toMatchArray(['website' => 'https://login.example.org/opensim/', 'helpers_path' => '/helpers', 'economy_path' => '/helpers', 'events_url' => 'https://2do.directory/events'])
            ->and($rows['register'])->toBe('helpers and 2do.directory')
            ->and(array_keys(array_column($review['rows'], 'options', 'name')['register'] ?? []))->toBe(['both', 'helpers', '2do']);
    });

    test('reviews what the setup set by itself, under short headings, each line changed as in a form, then Start', function () {
        $machine = new Machine();
        $review = quick_until($machine, 'review');
        $changed = quick_step($machine, $review, ['private_port' => '8199', 'simulator_ports' => '9100 9101 9104'], 'edit');
        $wrong = quick_step($machine, $changed, ['private_port' => 'abc'], 'edit');
        $rows = array_column($changed['rows'], 'value', 'name');

        expect(array_values(array_unique(array_column($review['rows'], 'section'))))->toBe(['Grid', 'Simulator', 'Web', 'Options', 'Database'])
            ->and($review['buttons'])->toBe(['next' => 'Start', 'back' => 'Back'])
            ->and($changed['kind'])->toBe('review')
            ->and([$rows['private_port'], $rows['simulator_ports']])->toBe(['8199', '9100 http, 9101 udp, 9104 console'])
            ->and($wrong['notice'])->toStartWith('Error: Private port')
            // Start: the setup with the lines changed
            ->and(QuickSetup::plan($machine->environment, ['type' => 'grid'] + quick_answers(['private_port' => '8199']))['setup']['grid']['private_port'])->toBe(8199);
    });

    test('gives a new grid a centre of its own, a line of the review changed there and checked, its region placed there', function () {
        $machine = new Machine();
        $review = quick_until($machine, 'review');
        $rows = array_column($review['rows'], 'value', 'name');
        $wrong = quick_step($machine, $review, ['center' => '12,abc'], 'edit');
        $changed = quick_step($machine, $review, ['center' => '4300,7100'], 'edit');
        $again = quick_until(new Machine(), 'review');
        // A centre another grid of this machine has
        file_put_contents("{$machine->grid}/demo.conf", "[Grid]\nGridName = Demo\nCenter = 4300,7100\n", FILE_APPEND);
        $taken = quick_step($machine, $review, ['center' => '4300,7100'], 'edit');

        expect($rows['center'])->toMatch('/^\d{2,3}00,\d{2,3}00$/')
            ->and(array_column($review['rows'], 'label'))->toContain('Centre')
            ->and($wrong['notice'])->toContain('Centre')
            ->and(array_column($changed['rows'], 'value', 'name')['center'])->toBe('4300,7100')
            ->and(array_column($changed['rows'], 'value', 'label')['Region'])->toBe('Welcome at 4300,7100')
            ->and(array_column($again['rows'], 'value', 'name')['center'])->not->toBe('8002,8002')
            ->and($taken['notice'])->toContain('The grid demo of this machine has this centre');
    });

    test('lists the options that keep their default without a question, each on or off, changed there', function () {
        $machine = new Machine();
        $review = quick_until($machine, 'review');
        $options = array_values(array_filter($review['rows'], static fn(array $row): bool => $row['section'] === 'Options'));
        $changed = quick_step($machine, $review, ['hypergrid' => 'no'], 'edit');

        expect(array_column($options, 'value', 'label'))->toBe(['Hypergrid' => 'on', 'Helpers' => 'on', 'Economy: Gloebit' => 'off', 'Extended OSSL' => 'on'])
            ->and(array_column($options, 'current', 'name'))->toBe(['hypergrid' => 'yes', 'helpers' => 'yes', 'economy-gloebit' => 'no', 'ossl-extended' => 'yes'])
            ->and(array_keys($options[0]['options']))->toBe(['yes', 'no'])
            ->and(array_column($changed['rows'], 'value', 'name')['hypergrid'])->toBe('off');
    });

    test('without the helpers asks no web service: the search, the web search and the register are 2do.directory\'s, said', function () {
        $machine = new Machine();
        $review = quick_step($machine, quick_until($machine, 'checks'), ['helpers' => false]);

        expect($review['kind'])->toBe('review')
            ->and(implode("\n", array_map(static fn(array $row): string => implode(' ', array_map('strval', $row)), $review['rows'] ?? [])))->toContain('2do.directory');
    });

    test('starts with what can be set up, the choices not implemented said and kept in the list', function () {
        $machine = new Machine();
        $reply = quick_step($machine, null);
        $join = quick_step($machine, $reply, ['type' => 'join']);

        expect($reply)
            ->toMatchArray(['action' => 'screen', 'kind' => 'menu', 'name' => 'type', 'title' => 'OpenSim setup'])
            // What is here first, by its folder, with its address and whether it runs; then the new ones
            ->and($reply['text'])->toBe('Found 1 pre-existing installation:')
            ->and(array_column($reply['items'], 'label', 'key'))
            ->toBe([
                'here:demo' => "{$machine->grid} (localhost:8002, inactive)",
                'grid' => 'New grid',
                'standalone' => 'New standalone',
                'join' => 'New simulator (connected to another grid)',
                'location' => 'Specify existing installation path',
            ])
            ->and($reply['buttons'])
            ->toBe(['next' => 'OK', 'back' => 'Quit'])
            // A simulator joins the grid of a preset, or one given by its address; Back is the first menu again
            ->and(array_column($join['items'], 'note', 'key'))
            ->toBe(['speculoos' => '', 'osgrid' => '', 'simulator' => ''])
            ->and(array_column($join['items'], 'label', 'key')['simulator'])->toBe('Other grid (its address)')
            ->and($join['buttons'])->toBe(['next' => 'OK', 'back' => 'Back'])
            ->and(quick_step($machine, $join, [], 'back')['title'])->toBe('OpenSim setup')
            ->and(quick_admin($machine, quick_step($machine, $join, ['type' => 'simulator']))['title'])->toBe('Simulator (join other grid)');
    });

    test('keeps the menu, and tells why, for a choice that is not one that can be set up', function () {
        $machine = new Machine();
        $reply = quick_step($machine, quick_step($machine, null), ['type' => 'nothing']);

        expect($reply)
            ->toMatchArray(['kind' => 'menu', 'value' => ''])
            ->and($reply['notice'])
            ->toStartWith('Not implemented yet: only Grid, Standalone');
    });

    test('goes on with the questions of the type, with what the engine proposes', function () {
        $machine = new Machine();
        $grid = quick_until($machine, 'form');
        $standalone = quick_until($machine, 'form', 'standalone');

        expect($grid)
            ->toMatchArray(['kind' => 'form', 'title' => 'Grid', 'notice' => ''])
            // A few short lines: the rest is proposed, and shown in the review
            ->and(array_column($grid['fields'], 'label', 'name'))
            ->toBe(['name' => 'Name', 'address' => 'Address', 'ports' => 'Ports', 'avatar' => 'Owner', 'password' => 'Password', 'email' => 'Email'])
            ->and(array_column($grid['fields'], 'secret', 'name'))
            ->toMatchArray(['password' => true, 'name' => false])
            ->and(array_column($grid['fields'], 'value', 'name')['ports'])
            ->toMatch('/^\d+ \d+$/')
            ->and($standalone['title'])
            ->toBe('Standalone')
            ->and($standalone['fields'][0]['label'])
            ->toBe('Name');
    });

    test('ends with an error when the person quits, at the first screen or any other', function () {
        $machine = new Machine();

        expect(quick_step($machine, quick_step($machine, null), [], 'back'))
            ->toBe(['action' => 'abort', 'code' => 4, 'stopped' => true, 'error' => 'Stopped at your request.'])
            ->and(quick_step($machine, quick_until($machine, 'form'), [], 'quit'))
            ->toBe(['action' => 'abort', 'code' => 4, 'stopped' => true, 'error' => 'Stopped at your request. The DB admin user opensim_admin stays, kept for the next run.']);
    });

    test('starts again at the first screen for a state that is not one', function () {
        $reply = QuickSetup::next((new Machine())->environment, ['state' => 'not-a-state', 'answers' => ['type' => 'grid']]);

        expect($reply['kind'])->toBe('menu');
    });

    test('shows the form again with what is wrong, everything at once, and what was typed', function () {
        $machine = new Machine();
        $reply = quick_step($machine, quick_until($machine, 'form'), quick_form(['avatar' => 'Ada', 'password2' => 'other']));

        expect($reply['kind'])
            ->toBe('form')
            ->and($reply['notice'])
            ->toBe("Error: Owner invalid (First Last)\nError: Passwords differ")
            ->and(array_column($reply['fields'], 'value', 'name'))
            ->toMatchArray(['avatar' => 'Ada', 'name' => 'Magic Grid', 'password' => ''])
            ->and($reply['buttons']['back'])
            ->toBe('Back');
    });

    test('replaces what the setup corrects in the form, and tells it, for the person to confirm', function () {
        $machine = new Machine();
        $reply = quick_step($machine, quick_until($machine, 'form'), quick_form(['avatar' => 'José García']));

        expect($reply['notice'])
            ->toBe('Error: Owner transliterated')
            ->and(array_column($reply['fields'], 'value', 'name')['avatar'])
            ->toBe('Jose Garcia')
            ->and(quick_step($machine, $reply, quick_form(['avatar' => 'Jose Garcia']))['name'])
            ->toBe('database_user');
    });

    test('goes back one screen at a time, keeping the answers', function () {
        $machine = new Machine();
        $options = quick_until($machine, 'checks');
        $user = quick_step($machine, $options, [], 'back');
        $form = quick_step($machine, $user, [], 'back');
        $menu = quick_step($machine, $form, quick_form(['avatar' => 'Grace Hopper']), 'back');

        expect($user['name'])
            ->toBe('database_user')
            ->and($form['kind'])
            ->toBe('form')
            ->and(array_column($form['fields'], 'value', 'name')['avatar'])
            ->toBe('Ada Love')
            // The menu opens on its first item, the answers are kept
            ->and($menu)
            ->toMatchArray(['kind' => 'menu', 'value' => ''])
            ->and(array_column(quick_step($machine, $menu, ['type' => 'grid'])['fields'], 'value', 'name')['avatar'])
            ->toBe('Grace Hopper');
    });

    test('asks for the options with their proposals, then shows everything the setup will do', function () {
        $machine = new Machine();
        $options = quick_until($machine, 'checks');
        $web = quick_step($machine, $options, ['ossl-extended' => true, 'economy-gloebit' => true, 'hypergrid' => false]);
        $review = quick_step($machine, $web, []);

        expect($options['kind'])
            ->toBe('checks')
            ->and($options['title'])
            ->toBe('Options')
            // The ones a first install chooses, nothing that is not implemented; the others keep their default, silently
            ->and(array_column($options['items'], 'checked', 'name'))
            ->toBe(['profiles' => true, 'groups' => true, 'search_module' => true, 'offline' => true, 'map' => false])
            ->and(array_filter(array_column($options['items'], 'note', 'name')))
            ->toBe([])
            ->and($review)
            ->toMatchArray(['kind' => 'review', 'title' => 'Review'])
            ->and($review['buttons']['next'])
            ->toBe('Start')
            // The options just answered are not said again
            ->and(array_column($review['rows'], 'section'))
            ->not->toContain('options');
        // Back: the web services, then the options
        expect(quick_step($machine, $review, [], 'back')['title'])->toBe('Web')
            ->and(quick_step($machine, $web, [], 'back')['kind'])->toBe('checks');
    });

    test('keeps the address of the Quick Start website with the grid, which the API gives (the step of the site reads it)', function () {
        $machine = new Machine();
        quick_core($machine);
        $web = quick_until($machine, 'web');
        $website = quick_step($machine, quick_step($machine, $web, ['site' => 'yes', 'site_path' => '/opensim']));
        $done = quick_step($machine, $website);
        $failed = quick_step($machine, $website, ['error_text' => 'Caddy refuses it'], 'failed');
        $skipped = quick_step($machine, $failed, ['error' => 'skip']);
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'magic_grid');

        // The website the last step of the run, the client's as root by its name; the summary once it is done, or without it
        expect($website['action'])->toBe('step')
            ->and($website['step'])->toMatchArray(['name' => 'install-website', 'root' => true, 'number' => 4, 'count' => 4, 'args' => ['grid' => 'magic_grid']])
            ->and($done['action'])->toBe('quit')
            ->and($failed['kind'])->toBe('menu')
            ->and($skipped['message'])->toContain('Website    not installed (rerun to install it)')
            ->and($grid?->siteAddress())->toEndWith('/opensim/')
            ->and($grid?->detail()['site'] ?? null)->toBe($grid?->siteAddress())
            ->and($done['message'])->toContain('Website    ' . $grid?->siteAddress());
    });

    test('leaves the website out, and says why, on a machine that has no w4os-site: no step that would fail', function () {
        quick_doubles(['siteTool' => static fn(): bool => false]);
        $machine = new Machine();
        quick_core($machine);
        $web = quick_until($machine, 'web');
        $done = quick_step($machine, quick_step($machine, $web, ['site' => 'yes', 'site_path' => '/opensim']));

        expect($done['action'])->toBe('quit')
            ->and($done['message'])->toContain('Website    not installed (w4os-site is not installed here)');
    });

    test('asks beside Apache the line of the website before the start, and leaves the website out without it', function () {
        quick_doubles(['vhosts' => static fn(string $site): ?array => ['/etc/apache2/sites-available/example.org-le-ssl.conf']]);
        $machine = new Machine();
        quick_core($machine);
        $web = quick_until($machine, 'web');
        $review = quick_step($machine, $web, ['site' => 'yes', 'site_path' => '/opensim']);
        $line = quick_step($machine, $review);
        $without = quick_step($machine, $line, ['vhosts' => 'no']);

        expect($line['kind'])->toBe('form')
            ->and($line['fields'][0]['label'])->toBe('Add one line to /etc/apache2/sites-available/example.org-le-ssl.conf')
            ->and($without['action'])->toBe('quit')
            ->and($without['message'])->toContain('Website    not installed (it needs its line in the sites of Apache)');
    });

    test('offers the website a run before left, its grid chosen in the first menu', function () {
        $machine = new Machine();
        W4OS\Engine\Models\Grid::keepSiteAddress("{$machine->grid}/demo.conf", 'https://example.org/opensim/');
        $first = quick_step($machine, QuickSetup::next($machine->environment, []), ['type' => 'here:demo']);
        $address = quick_step($machine, $first, ['website' => 'install']);
        $wrong = quick_step($machine, $address, ['website' => 'not an address']);
        $step = quick_step($machine, $address, ['website' => 'https://example.org/world']);
        $other = quick_step($machine, $first, ['website' => 'other']);
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'demo');

        expect($first['kind'])->toBe('menu')
            ->and($first['text'])->toBe('demo is here, its website not installed yet.')
            ->and(array_column($first['items'], 'key'))->toBe(['install', 'other'])
            ->and($address['fields'][0])->toMatchArray(['name' => 'website', 'value' => 'https://example.org/opensim/'])
            ->and($wrong['notice'])->toBe('not an address of a website: not an address')
            ->and($step['step'])->toMatchArray(['name' => 'install-website', 'args' => ['grid' => 'demo']])
            ->and($grid?->siteAddress())->toBe('https://example.org/world/')
            ->and($other['name'] ?? null)->toBe('type');
    });

    test('makes the folders and the files of a grid and of its first simulator when the review is confirmed', function () {
        $machine = new Machine();
        quick_core($machine);
        $review = quick_until($machine, 'review', options: ['ossl-extended' => true, 'search' => true, 'hypergrid' => true]);
        $done = quick_step($machine, $review);

        expect($done['action'])
            ->toBe('quit')
            ->and($done['message'])
            ->toContain("Grid       Magic Grid (magic_grid)\nLogin      http://login.example.org:8102\n")
            ->not->toContain('MoneyServer');
        // The group of the web server is asked for only on a machine that has one this user may not give
        $ungiven = W4OS\Engine\Config\GridFileService::webGroup() !== null && !W4OS\Engine\Config\GridFileService::canGiveWebGroup();
        expect(str_contains($done['message'], 'chgrp'))->toBe($ungiven);
        $m = [1 => array_values(array_diff(glob("{$machine->etc}/grids/*", GLOB_ONLYDIR), ["{$machine->etc}/grids/demo"]))[0] ?? ''];
        $folder = $m[1];

        expect($folder)
            ->toStartWith($machine->etc)
            ->and(is_dir($folder))
            ->toBeTrue()
            ->and(glob("$folder/Robust*.ini"))
            ->not->toBe([])
            ->and((string) file_get_contents("$folder/Robust.HG.ini"))
            ->toContain('Port = 8102')
            ->and(glob("$folder/sims/*.ini"))
            ->toHaveCount(1)
            ->and((string) file_get_contents("$folder/config-include/osslEnable.ini"))
            ->toContain('osslParcelO')
            // The search of its helpers by default, the regions registered with them and with 2do.directory
            ->and(implode("\n", array_map('file_get_contents', array_merge(glob("$folder/*.ini"), glob("$folder/sims/*.ini")))))
            ->toContain('/helpers/query.php')->toContain('https://2do.directory/helpers/register.php');
    });

    test('makes a standalone, with no Robust, and the account is left to its first start', function () {
        $machine = new Machine();
        $done = quick_step($machine, quick_until($machine, 'review', 'standalone', ['name' => 'Solo World', 'login_uri' => 'solo.example.org:9100']));

        $m = [1 => array_values(array_diff(glob("{$machine->etc}/grids/*", GLOB_ONLYDIR), ["{$machine->etc}/grids/demo"]))[0] ?? ''];

        expect($done['action'])
            ->toBe('quit')
            ->and($done['message'])
            ->toContain('Grid       Solo World')
            ->and(glob("{$m[1]}/Robust*.ini"))
            ->toBe([])
            ->and(glob("{$m[1]}/sims/*.ini"))
            ->toHaveCount(1);
    });

    test('says it cannot write, and writes nothing, when the place is not one it can write in', function () {
        $machine = new Machine();
        quick_core($machine);
        $review = quick_until($machine, 'review');
        // The folder of the grids is a file: nothing can be made in it
        rename("{$machine->etc}/grids", "{$machine->etc}/grids.kept");
        file_put_contents("{$machine->etc}/grids", 'not a folder');

        $done = quick_step($machine, $review);

        expect($done['action'])
            ->toBe('abort')
            ->and($done['error'])
            ->toContain('Cannot write in', "{$machine->etc}/grids");
    });

    test('has the folders of the install made first, as root by the client, when they are missing', function () {
        $machine = new Machine();
        $etc = "{$machine->root}/not-a-directory";
        file_put_contents($etc, '');
        $current = $machine->environment;
        $machine->environment = new Environment(
            new Layout([
                'EtcRoot' => $etc,
                'CoreRoot' => "{$machine->root}/core",
                'VarRoot' => "{$machine->root}/var",
                'DataRoot' => "{$machine->root}/data",
                'LogsRoot' => "{$machine->root}/logs",
                'CacheRoot' => "{$machine->root}/cache",
            ]),
            $current->processes,
            $current->launcher,
            $current->consoles,
            $current->clock,
            $current->reporter,
            $current->portMapping,
            $current->probe,
            $current->http,
        );
        // Its folders first, made as root by the client; still missing after it, or refused: said, nothing written
        $step = quick_step($machine, quick_step($machine, null), ['type' => 'grid']);
        $missing = quick_step($machine, $step);
        $refused = quick_step($machine, $step, ['error_text' => 'no sudo here'], 'failed');

        expect($step['step'])->toMatchArray(['name' => 'give-ownership', 'root' => true, 'label' => 'Folders'])
            ->and($missing)->toMatchArray(['action' => 'abort', 'error' => "Cannot write in $etc: its folders were not made."])
            ->and($refused)->toMatchArray(['action' => 'abort', 'error' => 'The folders of w4os were not made: no sudo here']);
    });

    test('turns off in the grid it writes an option every grid has that the person unchecked, and keeps it off', function () {
        $machine = new Machine();
        quick_core($machine);
        $review = quick_until($machine, 'review', options: ['groups' => false, 'profiles' => true]);
        $done = quick_step($machine, $review);
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'magic_grid');
        $enabled = (new W4OS\Engine\Provisioning\GridOptions($grid))->enabled();

        expect($done['action'])->toBe('quit')
            ->and(array_filter($enabled, static fn(string $name): bool => str_starts_with($name, 'groups')))->toBe([])
            ->and($enabled)->toContain('profiles')
            ->and((string) file_get_contents($grid->directory() . '/magic_grid.conf'))->toContain('OptionsOff');
    });

    test('puts the helpers on, and says so, when Gloebit needs them', function () {
        $machine = new Machine();
        $options = quick_until($machine, 'checks');

        $again = quick_step($machine, $options, ['helpers' => false, 'economy-gloebit' => true]);

        expect($again)
            ->toMatchArray(['kind' => 'checks', 'notice' => 'Economy: Gloebit needs the helpers: Helpers is on.'])
            // The helpers on: their web services next
            ->and(quick_step($machine, $again, ['helpers' => true, 'economy-gloebit' => true])['title'])
            ->toBe('Web');
    });

    test('asks the database again, with what the server said, when it cannot be reached or made, and goes on with the administrator', function () {
        $machine = new Machine();
        quick_core($machine);
        $calls = 0;
        quick_doubles([
            'database' => function (GridPlan $db, UiInterface $ui) use (&$calls): int {
                if ($calls++ === 0) {
                    $ui->error('Cannot reach the database server localhost: refused');

                    return DatabaseService::RETRY;
                }

                return DatabaseService::OK;
            },
        ]);

        $asked = quick_step($machine, quick_until($machine, 'review'));
        $done = quick_step($machine, $asked, ['database_host' => 'db.example.org', 'admin_user' => 'root', 'admin_password' => 'secret']);

        expect($asked)
            ->toMatchArray(['kind' => 'form', 'title' => 'Database'])
            ->and($asked['notice'])
            ->toContain('Cannot reach the database server localhost: refused')
            ->and(array_column($asked['fields'], 'name'))
            ->toBe(['database_host', 'database_name', 'database_user', 'database_password', 'admin_user', 'admin_password'])
            ->and($done['action'])
            ->toBe('quit');
    });

    test('uses database administrator credentials sent with the review confirmation', function () {
        $machine = new Machine();
        quick_core($machine);
        quick_doubles([
            'database' => static function (GridPlan $db, UiInterface $ui): int {
                expect($db->dbHost)->toBe('localhost')
                    ->and($db->dbUser)->toBe('opensim')
                    ->and($db->dbPass)->toBe('app-secret');
                $ui->error('Cannot reach the database server localhost: refused');

                return DatabaseService::RETRY;
            },
        ]);

        $asked = quick_step(
            $machine,
            quick_until($machine, 'review'),
            [
                'database_host' => 'localhost',
                'database_user' => 'opensim',
                'database_password' => 'app-secret',
                'admin_user' => 'opensim_admin',
                'admin_password' => 'admin-secret',
            ],
        );
        $values = array_column($asked['fields'], 'value', 'name');

        expect($asked)
            ->toMatchArray(['kind' => 'form', 'title' => 'Database'])
            ->and($values['admin_user'])
            ->toBe('opensim_admin')
            ->and($values['admin_password'])
            ->toBe('admin-secret');
    });

    test('stops, and says why, when the database cannot be made or the account of the avatar cannot be', function () {
        $machine = new Machine();
        quick_core($machine);
        quick_doubles([
            'database' => static function (GridPlan $db, UiInterface $ui): int {
                $ui->error('Could not create the database: denied');

                return DatabaseService::ABORT;
            },
        ]);
        $stopped = quick_step($machine, quick_until($machine, 'review'));
        quick_doubles(['account' => static function (Grid $grid, SimulatorPlan $sim, UiInterface $ui): bool {
            $ui->error('Robust did not make the account');

            return false;
        }]);
        $noAccount = quick_step($machine, quick_until($machine, 'review'));

        expect($stopped['action'])
            ->toBe('abort')
            ->and($stopped['error'])
            ->toContain('Could not create the database: denied', 'nothing was changed')
            ->and($noAccount)
            ->toBe(['action' => 'abort', 'error' => 'Robust did not make the account']);
    });

    test('copies the assets and the library of the core into the data folder of the grid, once, and points the config there', function () {
        $machine = new Machine();
        quick_core($machine);
        $bin = $machine->core . '/bin';
        @mkdir("$bin/assets/Sounds", 0o755, true);
        @mkdir("$bin/inventory", 0o755, true);
        file_put_contents("$bin/assets/AssetSets.xml", '<Nini/>');
        file_put_contents("$bin/assets/Sounds/click.ogg", 'sound');
        file_put_contents("$bin/inventory/Libraries.xml", '<Nini/>');
        $done = quick_step($machine, quick_until($machine, 'review'));
        $m = [1 => array_values(array_diff(glob("{$machine->etc}/grids/*", GLOB_ONLYDIR), ["{$machine->etc}/grids/demo"]))[0] ?? ''];
        $robust = (string) file_get_contents(glob("{$m[1]}/Robust*.ini")[0]);
        $data = $machine->root . '/data/magic_grid';

        expect($done['action'])
            ->toBe('quit')
            ->and(is_file("$data/assets/AssetSets.xml"))
            ->toBeTrue()
            ->and(is_file("$data/assets/Sounds/click.ogg"))
            ->toBeTrue()
            ->and(is_file("$data/inventory/Libraries.xml"))
            ->toBeTrue()
            ->and($robust)
            ->toContain('${Const|DataDirectory}/assets/AssetSets.xml', '${Const|DataDirectory}/inventory/Libraries.xml')
            ->and(is_dir("{$m[1]}/assets"))
            ->toBeFalse();
    });

    test('sets up a simulator that joins the grid of a preset: what the preset says is shown and not asked, the owner is an account of that grid', function () {
        $machine = new Machine();
        quick_core($machine);
        $form = quick_until($machine, 'form', 'speculoos');

        expect($form['title'])
            ->toBe('Speculoos')
            ->and(array_column($form['fields'], 'readonly', 'name'))
            ->toBe(['preset_grid' => true, 'preset_login' => true, 'preset_hypergrid' => true, 'name' => false, 'existing' => false, 'avatar' => false, 'database_driver' => false, 'http_port' => false, 'console_port' => false, 'region_port' => false])
            ->and(array_column($form['fields'], 'value', 'name')['name'])
            ->toBe('Welcome');
        $wrong = quick_step($machine, $form, ['name' => 'Plaza', 'avatar' => 'Ada']);
        $review = quick_step($machine, $form, ['name' => 'Plaza', 'avatar' => 'Ada Love']);
        $done = quick_step($machine, $review);

        expect($wrong['notice'])
            ->toBe('Error: Avatar name invalid (First Last)')
            ->and($review['kind'])
            ->toBe('review')
            ->and(array_column($review['rows'], 'value', 'label')['Owner'])
            ->toBe('Ada Love')
            ->and(array_column($review['rows'], 'section'))
            ->not->toContain('options')
            ->and($done['action'])
            ->toBe('quit')
            ->and($done['message'])
            ->toContain('Owner      Ada Love')
            ->and(glob("{$machine->etc}/grids/speculoos/sims/*.ini"))
            ->toHaveCount(1);
        expect(quick_step($machine, $review, [], 'back')['kind'])->toBe('form');
    });

    test('sets up a simulator that joins a grid given by its address, which says who it is', function () {
        $machine = new Machine();
        quick_core($machine);
        $form = quick_until($machine, 'form', 'simulator');
        $missing = quick_step($machine, $form, ['login_uri' => '', 'name' => 'Plaza', 'avatar' => 'Ada Love']);
        $review = quick_step($machine, $form, ['login_uri' => 'remote.example.org:8002', 'private_port' => '', 'name' => 'Plaza', 'avatar' => 'Ada Love']);
        $done = quick_step($machine, $review);

        expect($form['title'])
            ->toBe('Simulator (join other grid)')
            ->and(array_column($form['fields'], 'name'))
            ->toBe(['login_uri', 'private_port', 'name', 'existing', 'avatar', 'database_driver', 'http_port', 'console_port', 'region_port'])
            ->and($missing['notice'])
            ->toBe('Error: Login URI of the grid required')
            ->and(array_column($review['rows'], 'value', 'label')['Grid'])
            ->toBe('Remote World (remote)')
            ->and($done['action'])
            ->toBe('quit')
            ->and($done['message'])
            ->toContain('Grid       Remote World (remote)', 'Login      http://remote.example.org:8002')
            ->and(glob("{$machine->etc}/grids/remote/sims/*.ini"))
            ->toHaveCount(1);
    });

    test('shows the database accounts it is about to use, where each password comes from, masked unless asked', function () {
        $machine = new Machine();
        quick_core($machine);
        quick_doubles(['region' => [
            'remoteHas' => static fn(string $name): ?bool => false,
            'freePlace' => static fn(int $x, int $y, ?string $ignored): array => [$x, $y, true],
            'hasAccount' => static fn(string $name): ?bool => true,
            // The account of the kit exists, the one of another install too
            'accountHosts' => static fn($plan): array => ['localhost', '%'],
        ]]);
        putenv('OPENSIM_DB_USER=opensim_w4os');
        putenv('OPENSIM_DB_PASS=rc-secret');
        $form = quick_until($machine, 'form', 'simulator');
        $review = quick_step($machine, $form, ['login_uri' => 'remote.example.org:8002', 'private_port' => '', 'name' => 'Plaza', 'avatar' => 'Ada Love']);
        $shown = quick_step($machine, $review, [], 'show');
        $masked = quick_step($machine, $shown, [], 'show');
        putenv('OPENSIM_DB_USER');
        putenv('OPENSIM_DB_PASS');
        quick_doubles();
        $rows = static fn(array $screen): array => array_intersect_key(array_column($screen['rows'], 'value', 'label'), array_flip(['Database user', 'Database password', 'DB admin user']));

        // Each a name and its value, the password masked until asked
        expect($rows($review))->toBe(['Database user' => 'opensim_w4os (existing)', 'Database password' => '********', 'DB admin user' => 'opensim_admin'])
            ->and($review['buttons']['show'])->toBe('Show passwords')
            ->and($rows($shown)['Database password'])->toBe('rc-secret')
            ->and($shown['buttons']['show'])->toBe('Hide passwords')
            ->and($rows($masked)['Database password'])->toBe('********');
    });

    test('asks again for an avatar the joined grid does not have: its simulator would ask for it without end', function () {
        $machine = new Machine();
        quick_core($machine);
        quick_doubles(['region' => [
            'remoteHas' => static fn(string $name): ?bool => false,
            'freePlace' => static fn(int $x, int $y, ?string $ignored): array => [$x, $y, true],
            'hasAccount' => static fn(string $name): ?bool => $name === 'Ada Love',
        ]]);
        $form = quick_until($machine, 'form', 'simulator');
        $answers = ['login_uri' => 'remote.example.org:8002', 'private_port' => '', 'name' => 'Plaza'];
        $unknown = quick_step($machine, $form, ['avatar' => 'Nobody Here'] + $answers);
        $known = quick_step($machine, $form, ['avatar' => 'Ada Love'] + $answers);
        quick_doubles();

        expect($unknown['kind'])->toBe('form')
            ->and($unknown['notice'])->toContain('Avatar name', 'The grid Remote World has no avatar called Nobody Here')
            ->and($known['kind'])->toBe('review');
    });

    test('takes over a region the joined grid has already: its UUID, place and size, its owner the owner of the estate', function () {
        $machine = new Machine();
        quick_core($machine);
        quick_doubles(['region' => [
            'remoteHas' => static fn(string $name): ?bool => $name === 'Old Plaza',
            'freePlace' => static fn(int $x, int $y, ?string $ignored): array => [$x, $y, true],
            'registered' => static fn(string $name): array|false => $name === 'Old Plaza' ? [
                'uuid' => '11111111-2222-3333-4444-555555555555', 'name' => 'Old Plaza', 'location' => '1300,1301', 'size' => 512,
                'owner' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'server' => 'http://old.example.org:9000/', 'flags' => 16,
            ] : false,
            'serving' => static fn(string $server): bool => false,
            'accountName' => static fn(string $uuid): string => 'Bob Root',
        ]]);
        $form = quick_until($machine, 'form', 'simulator');
        $answers = ['login_uri' => 'remote.example.org:8002', 'private_port' => '', 'name' => 'Old Plaza', 'avatar' => 'Ada Love'];
        $refused = quick_step($machine, $form, $answers);
        $review = quick_step($machine, $form, ['existing' => 'yes'] + $answers);
        quick_step($machine, $review);
        quick_doubles();
        $rows = array_column($review['rows'], 'value', 'label');

        expect($refused['kind'])->toBe('form')
            ->and($review['kind'])->toBe('review')
            ->and([$rows['Region'], $rows['Location'], $rows['Owner']])->toBe(['Old Plaza', '1300,1301', 'Bob Root'])
            ->and($rows['Taken over'])->toContain('11111111-2222-3333-4444-555555555555')
            ->and(file_get_contents("{$machine->etc}/grids/remote/sims/remote.old_plaza/regions/OldPlaza.ini"))
            ->toContain('RegionUUID = 11111111-2222-3333-4444-555555555555', 'Location = 1300,1301', 'SizeX = 512');
    });

    test('is asked and answered through the API like the other operations', function () {
        $router = new Router(new Api((new Machine())->environment));
        $first = json_decode($router->post('/setups/steps', ['data' => ['type' => 'steps', 'attributes' => []]])->body(), true)['data'];
        $next = json_decode(
            $router->post('/setups/steps', ['data' => ['type' => 'steps', 'attributes' => ['state' => $first['attributes']['state'], 'answers' => ['type' => 'grid']]]])->body(),
            true,
        )['data'];

        expect($first['type'])
            ->toBe('steps')
            ->and($first['attributes']['kind'])
            ->toBe('menu')
            ->and($next['attributes']['kind'])
            ->toBe('form');
    });
});

describe('quick setup, the database users', function () {
    afterEach(fn() => QuickSetup::through());

    /** The doubles of a machine, the ones of the database users given. */
    $doubles = static fn(array $region): array => ['region' => $region + [
        'remoteHas' => static fn(string $name): ?bool => false,
        'freePlace' => static fn(int $x, int $y, ?string $ignored): array => [$x, $y, true],
        'hasAccount' => static fn(string $name): ?bool => true,
        'accountHosts' => static fn($plan): ?array => [],
    ]];

    test('asks first the DB admin user, proposed, made or checked as root by the client, refused with why', function () use ($doubles) {
        $machine = new Machine();
        $manages = false;
        quick_doubles($doubles(['manages' => static function (string $user, string $password) use (&$manages): bool {
            return $manages;
        }]));
        $admin = quick_step($machine, quick_step($machine, null), ['type' => 'grid']);
        $values = array_column($admin['fields'], 'value', 'name');
        $wrong = quick_step($machine, $admin, ['admin_user' => 'bad name']);
        $step = quick_step($machine, $admin, []);
        $failed = quick_step($machine, $step, ['error_text' => 'opensim_admin exists: its password is needed'], 'failed');
        $refused = quick_step($machine, $step, []);
        $manages = true;
        $form = quick_step($machine, $step, []);

        expect($admin)->toMatchArray(['kind' => 'form', 'name' => 'db-admin', 'title' => 'Databases admin user', 'text' => 'w4os needs credentials with admin privileges to create the databases and users.'])
            ->and(array_column($admin['fields'], 'label', 'name'))->toBe(['admin_user' => 'User', 'admin_password' => 'Password'])
            ->and(array_column($admin['fields'], 'secret', 'name'))->toBe(['admin_user' => false, 'admin_password' => true])
            ->and($values['admin_user'])->toBe('opensim_admin')
            ->and(strlen($values['admin_password']))->toBe(32)
            ->and($wrong['notice'])->toBe('User: letters, digits and _ only.')
            ->and($step['step'])->toMatchArray(['name' => 'db-admin', 'root' => true, 'args' => ['user' => 'opensim_admin', 'password' => $values['admin_password']]])
            ->and(MigrationScenario::ROOT_STEPS)->toContain('db-admin')
            ->and($failed['notice'])->toBe('opensim_admin exists: its password is needed')
            ->and($refused['notice'])->toBe('opensim_admin: its password is refused.')
            ->and($form['title'])->toBe('Grid')
            // Stopped after it: said, not an error, the DB admin user made said to stay
            ->and(quick_step($machine, $form, [], 'quit'))->toBe(['action' => 'abort', 'code' => 4, 'stopped' => true, 'error' => 'Stopped at your request. The DB admin user opensim_admin stays, kept for the next run.']);
    });

    test('takes the DB admin user of the environment as it works, with nothing made', function () use ($doubles) {
        $machine = new Machine();
        quick_doubles($doubles(['manages' => static fn(string $user, string $password): bool => $user === 'kept_admin' && $password === 'kept-secret']));
        putenv('OPENSIM_DB_ADMIN_USER=kept_admin');
        putenv('OPENSIM_DB_ADMIN_PASS=kept-secret');
        try {
            $admin = quick_step($machine, quick_step($machine, null), ['type' => 'grid']);
            $form = quick_step($machine, $admin, []);
        } finally {
            putenv('OPENSIM_DB_ADMIN_USER');
            putenv('OPENSIM_DB_ADMIN_PASS');
        }

        expect(array_column($admin['fields'], 'value', 'name'))->toBe(['admin_user' => 'kept_admin', 'admin_password' => 'kept-secret'])
            ->and($form['kind'])->toBe('form')
            ->and($form['title'])->toBe('Grid');
    });

    test('asks the database user of a grid once its name is known: OpenSimulator\'s, its nick or another', function () use ($doubles) {
        $machine = new Machine();
        quick_doubles($doubles(['accountHosts' => static fn($plan): ?array => $plan->dbUser === 'opensim' ? ['%'] : []]));
        $menu = quick_step($machine, quick_until($machine, 'form'), quick_form());
        $nick = quick_step($machine, $menu, ['database_user' => 'magic_grid']);
        $other = quick_step($machine, $menu, ['database_user' => 'other']);
        $given = quick_step($machine, $other, ['database_user' => 'gridder', 'database_password' => 'given-secret']);
        $existing = quick_step($machine, $menu, ['database_user' => 'opensim']);

        // OpenSimulator's is another install's here: the nick proposed
        expect($menu)->toMatchArray(['kind' => 'menu', 'name' => 'database_user', 'title' => 'Magic Grid database user', 'value' => 'magic_grid'])
            ->and(array_column($menu['items'], 'label', 'key'))->toBe(['opensim' => "opensim (OpenSimulator's default)", 'magic_grid' => 'magic_grid (w4os default)', 'other' => 'Other'])
            ->and($nick['kind'])->toBe('checks')
            ->and(array_column($other['fields'], 'name'))->toBe(['database_user', 'database_password'])
            ->and($given['kind'])->toBe('checks')
            // One that exists: its own password
            ->and($existing['name'])->toBe('db-user-form')
            ->and(array_column($existing['fields'], 'value', 'name'))->toBe(['database_user' => 'opensim', 'database_password' => '']);
    });
});
