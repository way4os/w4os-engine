<?php

declare(strict_types=1);

use W4OS\Engine\Provisioning\QuickSetup;
use W4OS\Engine\Testing\Machine;

function port_setup_answers(array $overrides = []): array
{
    return $overrides + [
        'type' => 'grid',
        'name' => 'Port Test',
        'login_uri' => 'http://grid.example.test:18102',
        'avatar' => 'Ada Test',
        'password' => 'test-password',
        'password2' => 'test-password',
        'private_port' => '18103',
        'grid_console_port' => '18104',
        'http_port' => '19140',
        'console_port' => '19144',
        'region_port' => '19141',
    ];
}

describe('setup ports', function () {
    test('uses chosen TCP and UDP ports', function () {
        $result = QuickSetup::plan((new Machine())->environment, port_setup_answers());
        expect($result['problems'])->toBe([])
            ->and($result['setup']['grid'])->toMatchArray(['private_port' => 18103, 'console_port' => 18104])
            ->and($result['setup']['simulators'][0])->toMatchArray(['http_port' => 19140, 'console_port' => 19144])
            ->and($result['setup']['simulators'][0]['regions'][0]['port'])->toBe(19141);
    });

    test('keeps automatic ten-port blocks', function () {
        $result = QuickSetup::plan((new Machine())->environment, port_setup_answers([
            'http_port' => '19160', 'console_port' => '', 'region_port' => '',
        ]));
        expect($result['problems'])->toBe([])
            ->and($result['setup']['simulators'][0]['console_port'])->toBe(19164)
            ->and($result['setup']['simulators'][0]['regions'][0]['port'])->toBe(19161);
    });

    test('moves to the next block when any port in the selected block is occupied', function () {
        $environment = (new Machine())->environment->onHostWithOccupiedPorts([8007, 9006]);
        $result = QuickSetup::plan($environment, [
            'type' => 'grid', 'name' => 'Port Test', 'avatar' => 'Ada Test',
            'password' => 'test-password', 'password2' => 'test-password',
        ]);

        expect($result['problems'])->toBe([])
            ->and($result['setup']['grid'])->toMatchArray([
                'public_port' => 8012, 'private_port' => 8013, 'console_port' => 8014,
            ])
            ->and($result['setup']['simulators'][0])->toMatchArray([
                'http_port' => 9010, 'console_port' => 9014,
            ])
            ->and($result['setup']['simulators'][0]['regions'][0]['port'])->toBe(9011);
    });

    test('reports invalid fields by question', function () {
        $result = QuickSetup::plan((new Machine())->environment, port_setup_answers([
            'private_port' => 'bad', 'grid_console_port' => 'bad', 'http_port' => 'bad',
            'console_port' => 'bad', 'region_port' => 'bad',
        ]));
        expect(array_keys($result['problems']))->toContain('private_port', 'grid_console_port', 'http_port', 'console_port', 'region_port');
    });

    test('offers port overrides before confirmation, in the review', function () {
        $env = (new Machine())->environment;
        $review = port_review($env);
        expect($review['kind'])->toBe('review')
            ->and(array_filter(array_column($review['rows'], 'name')))->toContain('private_port', 'grid_console_port', 'simulator_ports');
    });
    test('prefills editable port proposals', function () {
        $env = (new Machine())->environment;
        $screen = QuickSetup::next($env, ['mode' => 'prepare']);
        $form = QuickSetup::next($env, ['mode' => 'prepare', 'state' => $screen['state'], 'answers' => ['type' => 'grid']]);
        $values = array_column($form['fields'], 'value', 'name');
        $review = array_column(port_review($env)['rows'], 'current', 'name');
        [$public, $private] = explode(' ', $values['ports']);
        $planned = QuickSetup::plan($env, ['type' => 'grid', 'login_uri' => "{$values['address']}:$public", 'private_port' => $private] + port_setup_answers())['setup'];
        [$http, $udp, $console] = explode(' ', $review['simulator_ports']);
        expect($private)->toBe((string) $planned['grid']['private_port'])
            ->and($review['grid_console_port'])->toBe((string) $planned['grid']['console_port'])
            ->and($http)->toBe((string) $planned['simulators'][0]['http_port'])
            ->and($console)->toBe((string) $planned['simulators'][0]['console_port'])
            ->and($udp)->toBe((string) $planned['simulators'][0]['regions'][0]['port']);
    });

    test('saves confirmed ports', function () {
        $env = (new Machine())->environment;
        $screen = QuickSetup::next($env, ['mode' => 'prepare']);
        foreach ([['type' => 'grid'], port_setup_answers(), [], [], [], [
            'database_host' => 'localhost', 'database_user' => 'opensim', 'database_password' => 'test-db-password',
        ]] as $answers) {
            $screen = QuickSetup::next($env, ['mode' => 'prepare', 'state' => $screen['state'], 'answers' => $answers]);
        }
        expect($screen['action'])->toBe('prepared')
            ->and($screen['configuration']['setup']['simulators'][0])->toMatchArray(['http_port' => 19140, 'console_port' => 19144])
            ->and($screen['configuration']['setup']['simulators'][0]['regions'][0]['port'])->toBe(19141);
    });

    test('keeps standalone login and HTTP together', function () {
        $result = QuickSetup::plan((new Machine())->environment, port_setup_answers(['type' => 'standalone']));
        expect($result['problems'])->toHaveKey('http_port');
    });

});

test('setup proposes the destination hostname', function () {
    $env = (new Machine())->environment;
    $screen = QuickSetup::next($env, ['mode' => 'prepare']);
    $form = QuickSetup::next($env, ['mode' => 'prepare', 'state' => $screen['state'], 'answers' => [
        'type' => 'grid', 'hostname' => 'podman.example.test',
    ]]);
    $values = array_column($form['fields'], 'value', 'name');
    expect($values['address'])->toBe('podman.example.test');
});

test('setup keeps an explicit login address', function () {
    $env = (new Machine())->environment;
    $screen = QuickSetup::next($env, ['mode' => 'prepare']);
    $form = QuickSetup::next($env, ['mode' => 'prepare', 'state' => $screen['state'], 'answers' => [
        'type' => 'grid', 'hostname' => 'podman.example.test', 'login_uri' => 'grid.example.test:18302',
    ]]);
    $values = array_column($form['fields'], 'value', 'name');
    expect([$values['address'], explode(' ', $values['ports'])[0]])->toBe(['grid.example.test', '18302']);
});

/** The review of a grid in prepare mode, the form answered with port_setup_answers(), the options and the web as proposed. */
function port_review(W4OS\Engine\Runtime\Environment $env): array
{
    $screen = QuickSetup::next($env, ['mode' => 'prepare']);
    foreach ([['type' => 'grid'], port_setup_answers(), [], []] as $answers) {
        $screen['kind'] === 'review' || ($screen = QuickSetup::next($env, ['mode' => 'prepare', 'state' => $screen['state'], 'answers' => $answers]));
    }

    return $screen;
}
