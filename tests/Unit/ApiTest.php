<?php

declare(strict_types=1);

/**
 * The resources of the machine, of a grid, of the application as JSON:API documents, what is written, the errors as problems, and the
 * contract that says so: checked against the models.
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Assets\WebAssets;
use W4OS\Engine\Api\Contract;
use W4OS\Engine\Api\Problem;
use W4OS\Engine\Api\Response;
use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Connections;
use W4OS\Engine\Exceptions\ChannelUnavailable;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Scheduling\Scheduler;
use W4OS\Engine\Scheduling\Task;
use W4OS\Engine\Testing\ClockFake;
use W4OS\Engine\Testing\Machine;

/** An API over the test machine, the config of its grids being the one the test gives. */
function machine_api(?GridConfig $config = null, ?Scheduler $scheduler = null): Api
{
    return new Api(
        (new Machine())->environment,
        $scheduler,
        $config === null ? null : static fn(Grid $grid): GridConfig => $config,
    );
}

/** The attributes of the first resource of a list. */
function first_attributes(Response $response): array
{
    return $response->document['data'][0]['attributes'];
}

describe('The API', function () {
    test('uses the current account when SystemUser is not configured', function () {
        $uid = posix_geteuid();
        $user = posix_getpwuid($uid);
        $environment = W4OS\Engine\Testing\EnvironmentFake::with();
        $response = (new W4OS\Engine\Api\Router(new Api($environment)))->get('/layout');

        expect($response->document['data']['attributes']['systemUser'])
            ->toBe($user['name']);
    });

    test('returns the resolved layout and system identities', function () {
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $user = posix_getpwuid($uid);
        $group = posix_getgrgid($gid);
        $environment = W4OS\Engine\Testing\EnvironmentFake::with([
            'SystemUser' => (string) $user['name'],
            'EtcRoot' => '/etc/opensim-test',
            'VarRoot' => '/var/lib/opensim-test',
            'LogsRoot' => '/var/log/opensim-test',
        ]);
        $response = (new W4OS\Engine\Api\Router(new Api($environment)))->get('/layout');
        $systemGroup = posix_getgrgid((int) $user['gid']);

        expect($response->status)
            ->toBe(200)
            ->and($response->document['data']['attributes'])
            ->toMatchArray([
                'systemUser' => $user['name'],
                'systemUid' => $uid,
                'systemGroup' => $systemGroup['name'],
                'systemGid' => (int) $user['gid'],
                'currentUser' => $user['name'],
                'currentUid' => $uid,
                'currentGroup' => $group['name'],
                'currentGid' => $gid,
                'etcRoot' => '/etc/opensim-test',
                'varRoot' => '/var/lib/opensim-test',
                'logsRoot' => '/var/log/opensim-test',
            ]);
    });

    test('refuses a filter, a value or a sort the list does not take, naming the ones it takes', function () {
        $api = machine_api();
        $asked = static fn(array $query): array => [
            $api->list('services', $query)->status,
            $api->list('services', $query)->document['code'] ?? null,
        ];
        $filter = $api->list('services', ['filter' => ['nope' => 'yes']])->document;

        expect($asked(['filter' => ['nope' => 'yes']]))
            ->toBe([400, 'filter-unknown'])
            ->and($filter['detail'])
            ->toBe("'nope' is not a filter of this list: served, port")
            ->and($asked(['filter' => ['served' => 'maybe']]))
            ->toBe([400, 'filter-value-unknown'])
            ->and($asked(['sort' => '-nope']))
            ->toBe([400, 'sort-unknown'])
            ->and($asked(['filter' => ['served' => '']]))
            ->toBe([200, null]);
    });

    test('lists the resources of a type in a JSON:API document, with the page and what was applied', function () {
        $api = machine_api();
        $response = $api->list('services', [
            'filter' => ['served' => 'yes'],
            'sort' => '-name',
            'page' => ['number' => 1, 'size' => 1],
        ]);

        expect($response->status)
            ->toBe(200)
            ->and($response->headers['Content-Type'])
            ->toBe(Response::JSON_API)
            ->and($response->document['data'])
            ->toHaveCount(1)
            ->and($response->document['data'][0])
            ->toMatchArray(['type' => 'services', 'id' => 'demo.grid', 'links' => ['self' => '/services/demo.grid']])
            ->and($response->document['data'][0]['attributes']['key'])
            ->toBe('grid')
            ->and($response->document['data'][0]['attributes'])
            ->not->toHaveKey('id')
            ->and($response->document['meta'])
            ->toMatchArray([
                'total' => 2,
                'page' => 1,
                'perPage' => 1,
                'sort' => 'name desc',
                'filters' => ['served' => 'yes'],
            ])
            ->and(json_decode($response->body(), true)['data'][0]['type'])
            ->toBe('services');
    });

    test('reads one resource by its id', function () {
        $api = machine_api();

        $grid = $api->get('grids', 'demo');
        $simulator = $api->get('simulators', 'alpha');

        expect($grid->status)
            ->toBe(200)
            ->and($grid->document['data'])
            ->toMatchArray(['type' => 'grids', 'id' => 'demo'])
            ->and($grid->document['data']['attributes']['name'])
            ->toBe('Demo')
            ->and($simulator->status)
            ->toBe(200)
            ->and($simulator->document['data']['type'])
            ->toBe('simulators');
    });

    test('tells what is not there as a problem, RFC 9457', function () {
        $api = machine_api();

        $missing = $api->get('grids', 'nowhere');
        $kind = $api->list('nothings');
        $noGrid = $api->list('avatars', [], grid: 'nowhere');
        $outside = $api->list('avatars');

        expect($missing->status)
            ->toBe(404)
            ->and($missing->headers['Content-Type'])
            ->toBe(Problem::CONTENT_TYPE)
            ->and($missing->document)
            ->toMatchArray([
                'title' => 'Not found',
                'status' => 404,
                'instance' => '/grids/nowhere',
                'type' => 'urn:opensim:problem:not-found',
            ])
            ->and($kind->status)
            ->toBe(404)
            ->and($noGrid->status)
            ->toBe(404)
            ->and($noGrid->document['detail'])
            ->toContain("grid called 'nowhere'")
            ->and($outside->status)
            ->toBe(404);
    });

    test(
        'tells an exception as the problem it stands for, without the message of a failure of the server',
        function () {
            expect(Problem::from(new InvalidArgumentException('a name'))->status)
                ->toBe(400)
                ->and(Problem::from(ChannelUnavailable::for('read the avatars', 'no database'))->toArray())
                ->toMatchArray([
                    'status' => 503,
                    'title' => 'Not available',
                    'detail' => 'cannot read the avatars: no database',
                ])
                ->and(Problem::from(new \W4OS\Engine\Exceptions\InstanceFailed('demo', 'demo exited while starting'))->toArray())
                ->toMatchArray([
                    'status' => 500,
                    'title' => 'Instance failed',
                    'detail' => 'demo exited while starting',
                    'type' => 'urn:opensim:problem:instance-failed',
                ])
                ->and(Problem::from(new RuntimeException('/secret/path password'))->toArray())
                ->toBe([
                    'type' => 'about:blank',
                    'title' => 'Internal error',
                    'status' => 500,
                    'code' => 'internal-error',
                ])
                ->and(Problem::from(new RuntimeException('private detail'), '/setups/steps')->toArray())
                ->toBe([
                    'type' => 'about:blank',
                    'title' => 'Internal error',
                    'status' => 500,
                    'code' => 'internal-error',
                    'instance' => '/setups/steps',
                ])
                ->and(json_decode(Problem::from(new InvalidArgumentException('x'), '/grids')->json(), true)['instance'])
                ->toBe('/grids');
        },
    );

    test('turns the query of JSON:API into the options of the models', function () {
        expect(
            Api::options([
                'search' => 'demo',
                'sort' => '-name',
                'filter' => ['remote' => 'no'],
                'page' => ['number' => '2', 'size' => '10'],
            ]),
        )
            ->toBe([
                'search' => 'demo',
                'sort' => 'name desc',
                'filters' => ['remote' => 'no'],
                'page' => 2,
                'perPage' => 10,
            ])
            ->and(Api::options(['sort' => 'port']))
            ->toBe(['sort' => 'port'])
            ->and(Api::options([]))
            ->toBe([])
            ->and(Api::path('avatars', 'a b', 'my grid'))
            ->toBe('/grids/my%20grid/avatars/a%20b');
    });
});

describe('The API, for the resources of a grid', function () {
    test('lists and reads the avatars of a grid, in the database of its services', function () {
        Connections::using(static fn() => avatar_creation_database());
        $api = machine_api(avatar_grid());

        $list = $api->list('avatars', ['search' => 'ada'], grid: 'demo');
        $one = $api->get('avatars', '11111111-1111-1111-1111-111111111111', grid: 'demo');

        expect($list->status)
            ->toBe(200)
            ->and($list->document['data'][0])
            ->toMatchArray([
                'type' => 'avatars',
                'id' => '11111111-1111-1111-1111-111111111111',
                'links' => ['self' => '/grids/demo/avatars/11111111-1111-1111-1111-111111111111'],
            ])
            ->and(first_attributes($list)['name'])
            ->toBe('Ada Love')
            ->and($list->document['meta']['search'])
            ->toBe('ada')
            ->and($one->document['data']['attributes']['email'])
            ->toBe('ada@example.org');
    });

    test('makes an avatar, changes it, and deletes nothing when no channel does', function () {
        $pdo = avatar_creation_database();
        Connections::using(static fn() => $pdo);
        $api = machine_api(avatar_grid());

        $made = $api->create(
            'avatars',
            ['name' => 'Api Person', 'email' => 'api@example.org', 'password' => 'secret'],
            grid: 'demo',
        );
        $id = $made->document['data']['id'];
        $changed = $api->update('avatars', $id, ['level' => 5, 'active' => false, 'password' => 'other-secret'], grid: 'demo');
        $taken = $api->create('avatars', ['name' => 'Api Person', 'password' => 'secret'], grid: 'demo');
        $unknown = $api->update('avatars', $id, ['nonsense' => 1], grid: 'demo');
        $deleted = $api->delete('avatars', $id, grid: 'demo');

        expect($made->status)
            ->toBe(201)
            ->and($made->headers['Location'])
            ->toBe("/grids/demo/avatars/$id")
            ->and($made->document['data']['attributes']['name'])
            ->toBe('Api Person')
            // Ready to sign in, said as a notice for a site to say it in its language
            ->and($made->document['meta']['notice'])
            ->toMatchArray(['code' => 'registered', 'params' => ['name' => 'Api Person']])
            ->and($changed->status)
            ->toBe(200)
            ->and($changed->document['data']['attributes'])
            ->toMatchArray(['level' => 5, 'active' => false])
            ->and($taken->status)
            ->toBe(400)
            ->and($taken->document['detail'])
            ->toContain('exists already')
            ->and($unknown->status)
            ->toBe(400)
            ->and($deleted->status)
            ->toBe(503)
            ->and($api->update('avatars', 'nobody', ['level' => 1], grid: 'demo')->status)
            ->toBe(404);
    });

    test('enables and disables what can be, and says nothing makes what the setup makes', function () {
        $api = machine_api();

        $off = $api->update('simulators', 'alpha', ['enabled' => false]);
        $on = $api->update('simulators', 'alpha', ['enabled' => true]);
        $made = $api->create('grids', ['name' => 'New']);

        expect($off->status)
            ->toBe(200)
            ->and($off->document['data']['attributes']['enabled'])
            ->toBeFalse()
            ->and($on->document['data']['attributes']['enabled'])
            ->toBeTrue()
            ->and($made->status)
            ->toBe(503)
            ->and($made->document['detail'])
            ->toContain('made by the setup');
    });

    test('lists the regions, the parcels and the estates of a grid', function () {
        Connections::using(static fn() => region_database());
        $regions = machine_api(region_config())->list('regions', [], grid: 'demo');

        [$alpha, $beta] = parcel_databases();
        parcels_in([$alpha, $beta]);
        $parcels = machine_api(parcel_config())->list('parcels', ['sort' => '-dwell'], grid: 'demo');

        $estates = machine_api(estate_world())->list('estates', [], grid: 'demo');

        expect($regions->document['meta']['total'])
            ->toBe(3)
            ->and($regions->document['data'][0]['type'])
            ->toBe('regions')
            ->and(array_column(array_column($parcels->document['data'], 'attributes'), 'name'))
            ->toBe(['Harbour Front', 'The Garden', 'Kiosk'])
            ->and(array_column($estates->document['data'], 'id'))
            ->toBe(['alpha:101', 'beta:101', 'alpha:102']);
    });
});

describe('The API, for the application', function () {
    test('lists the tasks with their last run, reads one, and runs it now', function () {
        $clock = new ClockFake();
        $scheduler = new Scheduler(test_tmp() . '/api-cron-' . bin2hex(random_bytes(4)), $clock);
        $scheduler
            ->add(new Task('count', 3600, fn() => 'counted', 'Count'))
            ->add(new Task('broken', 60, fn() => throw new RuntimeException('no host')));
        $api = machine_api(scheduler: $scheduler);

        $before = $api->list('tasks');
        $run = $api->run('count');
        $after = $api->get('tasks', 'count');
        $failed = $api->run('broken');

        expect($before->document['data'])
            ->toHaveCount(2)
            ->and($before->document['data'][0])
            ->toMatchArray(['type' => 'tasks', 'id' => 'count'])
            ->and($before->document['data'][0]['attributes'])
            ->toMatchArray(['label' => 'Count', 'every' => 3600, 'lastRun' => null, 'due' => true])
            ->and($run->status)
            ->toBe(200)
            ->and($run->document['data']['attributes'])
            ->toMatchArray(['task' => 'count', 'status' => 'ran', 'message' => 'counted'])
            ->and($after->document['data']['attributes'])
            ->toMatchArray(['lastRun' => 1000, 'status' => 'ran', 'due' => false])
            ->and($failed->status)
            ->toBe(500)
            ->and($api->run('nothing')->status)
            ->toBe(404)
            ->and($api->get('tasks', 'nothing')->status)
            ->toBe(404)
            ->and(machine_api()->list('tasks')->document['data'])
            ->toBe([]);
    });

    test('gives the forms of the setup, without what only PHP can do', function () {
        $api = machine_api();

        $list = $api->list('forms');
        $grid = $api->get('forms', 'grid');
        $names = array_column($grid->document['data']['attributes']['fields'], 'name');

        expect(array_column($list->document['data'], 'id'))
            ->toBe(['grid', 'remote-grid', 'simulator', 'region'])
            ->and($names)
            ->toContain('name', 'nick', 'public_port')
            ->and($grid->document['data']['attributes']['fields'][0])
            ->not->toHaveKey('callback')
            ->and(json_encode($grid->document))
            ->not->toBeFalse()
            ->and($api->get('forms', 'nothing')->status)
            ->toBe(404);
    });
});

describe('The contract', function () {
    test(
        'is an OpenAPI 3.1 document with a list and a read for each resource, the writes that exist, and the problem',
        function () {
            $document = Contract::document();
            $paths = $document['paths'];

            expect($document['openapi'])
                ->toBe('3.1.0')
                ->and(array_keys($paths))
                ->toContain(
                    '/layout',
                    '/grids',
                    '/grids/{id}',
                    '/services',
                    '/grids/{grid}/avatars',
                    '/grids/{grid}/avatars/{id}',
                    '/grids/{grid}/estates',
                    '/grids/{grid}/destinations',
                    '/grids/{grid}/motd',
                    '/grids/{grid}/health',
                    '/grids/{grid}/search/register',
                    '/grids/{grid}/search/query',
                    '/grids/{grid}/currency.php',
                    '/grids/{grid}/landtool.php',
                    '/grids/{grid}/offline-messages/SaveMessage',
                    '/grids/{grid}/offline-messages/RetrieveMessages',
                    '/get_grid_info',
                    '/text-images',
                    '/fonts',
                    '/tasks',
                    '/tasks/{id}/runs',
                    '/forms/{id}',
                )
                ->and(array_keys($document['components']['schemas']))
                ->toBe([
                    'Problem',
                    'Grids',
                    'Simulators',
                    'Services',
                    'Archives',
                    'Avatars',
                    'AvatarModels',
                    'Regions',
                    'Parcels',
                    'Posts',
                    'SearchPeople',
                    'SearchGroups',
                    'SearchPlaces',
                    'SearchLand',
                    'SearchEvents',
                    'SearchClassifieds',
                    'Estates',
                    'Assets',
                    'Destinations',
                    'Tasks',
                    'Forms',
                    'Fonts',
                ])
                ->and($paths['/services']['get']['parameters'])
                ->toContain([
                    'name' => 'filter[served]',
                    'in' => 'query',
                    'schema' => ['type' => 'string', 'enum' => ['yes', 'no']],
                ])
                ->and(array_keys($paths['/grids/{grid}/avatars']))
                ->toBe(['get', 'post'])
                ->and(array_keys($paths['/grids/{grid}/avatars/{id}']))
                ->toBe(['get', 'patch', 'delete'])
                ->and(array_keys($paths['/grids/{id}']))
                ->toBe(['get', 'patch'])
                ->and(array_keys($paths['/grids/{grid}/parcels/{id}']))
                ->toBe(['get'])
                ->and($paths['/grids/{id}']['get']['responses'])
                ->toHaveKeys(['200', '404'])
                ->and(json_encode($document))
                ->not->toBeFalse();
        },
    );

    test('describes the list of each model as the model does (x-list), with the keys of the catalog', function () {
        $paths = Contract::document()['paths'];
        $described = array_keys(array_filter(
            $paths,
            static fn(array $operations): bool => isset($operations['get']['x-list']),
        ));
        $avatars = $paths['/grids/{grid}/avatars']['get'];
        $regions = $paths['/grids/{grid}/regions']['get'];
        $parameters = static fn(array $operation): array => array_column($operation['parameters'], 'schema', 'name');
        $keys = [];
        $unknown = [];
        foreach ($paths as $path => $operations) {
            foreach ($operations as $operation) {
                if (isset($operation['x-list'])) {
                    $listing = $operation['x-list'];
                    // Its type: its path under the grid (`search/places`)
                    $type = (string) preg_replace('#^(/grids/\{grid\})?/#', '', $path);
                    // What it shows and asks of a row is an attribute of the resource
                    // An indicators column shows the attributes of its indicators (the first part of a dotted path), its actions are
                    // actions of the list
                    $named = [];
                    foreach ($listing['columns'] as $column) {
                        $indicators = $column['indicators'] ?? [];
                        // A column of several values shows the attributes of its parts
                        $column['type'] === 'group' ? array_push($named, ...array_column($column['parts'], 'name')) : ($column['type'] === 'indicators' || ($named[] = $column['name']));
                        $column['type'] === 'group' && array_push($keys, ...array_column($column['parts'], 'label'));
                        array_push($named, ...array_values(array_intersect_key($column, ['picture' => 1, 'tag' => 1, 'link' => 1])));
                        array_push($named, ...array_map(static fn(array $indicator): string => explode('.', $indicator['name'])[0], $indicators));
                        foreach ($indicators as $indicator) {
                            array_push($keys, $indicator['label'], ...array_column($indicator['states'], 'label'));
                            array_push($unknown, ...array_map(
                                static fn(string $name): string => $type . ".action:$name",
                                array_diff($indicator['actions'] ?? [], array_column($listing['actions'], 'name')),
                            ));
                        }
                    }
                    foreach ($listing['actions'] as $action) {
                        array_push($named, ...array_keys($action['when'] ?? []), ...$action['requires'] ?? []);
                    }
                    $attributes = array_keys(Contract::attributes($type));
                    array_push($unknown, ...array_map(static fn(string $name): string => $type . ".$name", array_diff($named, $attributes)));
                    array_push(
                        $keys,
                        ...array_column($listing['columns'], 'label'),
                        ...array_column($listing['filters'], 'label'),
                        ...array_column($listing['actions'], 'label'),
                    );
                    foreach ($listing['filters'] as $filter) {
                        array_push($keys, ...array_column($filter['values'] ?? [], 'label'));
                    }
                }
            }
        }

        expect($avatars['x-list']['columns'][0])
            ->toBe([
                'name' => 'name',
                'label' => 'avatar_name',
                'type' => 'text',
                'sort' => 'name',
                'picture' => 'profileImage',
                'default' => 'person',
                'bare' => false,
                'priority' => 1,
            ])
            ->and(array_column($avatars['x-list']['columns'], 'type', 'name'))
            ->toBe([
                'name' => 'text',
                'email' => 'email',
                'shownRole' => 'role',
                'type' => 'list',
                'created' => 'datetime',
                'lastSeen' => 'datetime',
                    'estates' => 'number',
                    'regions' => 'number',
                    'parcels' => 'number',
                'status' => 'indicators',
            ])
            // The account, the button that enables or disables it, and the presence in the world
            ->and(array_map(
                static fn(array $indicator): array => [$indicator['name'], $indicator['icon'], array_column($indicator['states'], 'tone', 'label'), $indicator['actions'] ?? []],
                $avatars['x-list']['columns'][9]['indicators'],
            ))
            ->toBe([
                ['accountState', 'account', ['online' => 'ok', 'status_active' => 'ok', 'status_pending' => 'off', 'status_disabled' => 'off', 'status_banned' => 'failed'], ['enable', 'disable']],
                ['ban', 'ban', ['state_grid_banned' => 'failed', 'state_estate_banned' => 'warning'], []],
            ])
            ->and($avatars['x-list']['columns'][9]['sort'])
            ->toBe('active')
            ->and($avatars['x-list']['sort'])
            ->toBe([
                'default' => '-created',
                'values' => ['name', 'email', 'level', 'type', 'created', 'lastSeen', 'active', 'online'],
                'descending' => '-',
            ])
            ->and($avatars['x-list']['search'])
            ->toBe(['name', 'email'])
            ->and($avatars['x-list']['filters'][0])
            ->toBe([
                'name' => 'status',
                'label' => 'status',
                'values' => [
                    ['value' => 'active', 'label' => 'status_active'],
                    ['value' => 'disabled', 'label' => 'status_disabled'],
                    ['value' => 'pending', 'label' => 'status_pending'],
                    ['value' => 'banned', 'label' => 'status_banned'],
                ],
            ])
            // The bans after them (AvatarBanTest)
            ->and(array_slice($avatars['x-list']['actions'], 0, 3))
            ->toBe([
                [
                    'name' => 'enable',
                    'label' => 'action_enable',
                    'method' => 'PATCH',
                    'path' => '/grids/{grid}/avatars/{id}',
                    'body' => ['status' => 'active'],
                    'when' => ['status' => ['disabled', 'pending']],
                ],
                [
                    'name' => 'disable',
                    'label' => 'action_disable',
                    'method' => 'PATCH',
                    'path' => '/grids/{grid}/avatars/{id}',
                    'body' => ['status' => 'disabled'],
                    'when' => ['status' => ['active']],
                    'notSelf' => true,
                ],
                [
                    'name' => 'reset',
                    'label' => 'action_reset',
                    'method' => 'POST',
                    'path' => '/grids/{grid}/password-resets',
                    'body' => ['name' => '{name}'],
                    'when' => ['status' => ['active']],
                    'requires' => ['email'],
                    'link' => 'reset',
                ],
            ])
            // The parameters of the list are the ones the model describes
            ->and($parameters($avatars)['filter[role]'])
            ->toBe(['type' => 'string', 'enum' => ['resident', 'god-like', 'customer-service', 'liaison', 'god', 'maintenance']])
            ->and($parameters($avatars)['sort']['enum'])
            ->toContain('created', '-created')
            ->and($parameters($regions)['filter[owner]'])
            ->toBe(['type' => 'string'])
            ->and(array_keys($parameters($regions)))
            ->toContain('filter[simulator]', 'filter[flags]', 'filter[owner]')
            ->and(array_intersect_key($regions['x-list']['columns'][0], ['subtitle' => 1, 'subtitleLink' => 1]))
            ->toBe(['subtitle' => ['uri'], 'subtitleLink' => 'teleport'])
            ->and($described)
            ->toEqualCanonicalizing([
                '/grids',
                '/simulators',
                '/services',
                '/archives',
                '/grids/{grid}/avatars',
                '/grids/{grid}/regions',
                '/grids/{grid}/parcels',
                '/grids/{grid}/posts',
                '/grids/{grid}/estates',
                '/grids/{grid}/assets',
                '/grids/{grid}/search/people',
                '/grids/{grid}/search/groups',
                '/grids/{grid}/search/places',
                '/grids/{grid}/search/land',
                '/grids/{grid}/search/events',
                '/grids/{grid}/search/classifieds',
            ])
            ->and($paths['/grids']['get']['x-list']['actions'][0]['path'])
            ->toBe('/grids/{id}')
            ->and($paths['/grids/{grid}/assets']['get']['x-list']['filters'][0]['values'][0])
            ->toBe(['value' => 'texture', 'label' => 'asset_texture'])
            ->and($paths['/grids/{grid}/parcels']['get']['x-list']['sort']['values'])
            ->toBe(['name', 'area', 'dwell', 'claimed', 'price'])
            ->and($unknown)
            ->toBe([])
            ->and($keys)
            ->not->toBeEmpty()
            ->and(array_values(array_diff(array_unique($keys), array_keys(\W4OS\Engine\I18n\Catalog::MESSAGES))))
            ->toBe([]);
    });

    test('describes the page of an avatar and of a region as their models do (x-form), what it changes being what the PATCH takes', function () {
        $document = Contract::document();
        $paths = $document['paths'];
        $avatar = $paths['/grids/{grid}/avatars/{id}']['get']['x-form'];
        $region = $paths['/grids/{grid}/regions/{id}']['get']['x-form'];
        $checks = [];
        $parcel = $paths['/grids/{grid}/parcels/{id}']['get']['x-form'];
        foreach (['avatars' => $avatar, 'regions' => $region, 'parcels' => $parcel] as $type => $form) {
            $attributes = array_keys(Contract::attributes($type));
            $writable = array_keys(Contract::RESOURCES[$type]['writable']);
            $keys = [...array_column($form['groups'], 'label'), ...array_column($form['fields'], 'label')];
            foreach ($form['fields'] as $field) {
                // Shown: an attribute of the item (a password is only sent); changed: what the PATCH takes
                ($field['writeOnly'] ?? false) || in_array($field['name'], $attributes, true) || ($checks[] = "$type.{$field['name']} shown");
                ($field['readOnly'] ?? false) || in_array($field['name'], $writable, true) || ($checks[] = "$type.{$field['name']} sent");
                array_push($keys, ...array_values($field['options'] ?? []), ...(isset($field['description']) ? [$field['description']] : []));
            }
            array_push($checks, ...array_diff($keys, array_keys(\W4OS\Engine\I18n\Catalog::MESSAGES)));
        }

        expect($checks)
            ->toBe([])
            ->and(array_column($avatar['groups'], 'name'))
            ->toBe(['inworld', 'web', 'interests', 'real_life', 'account'])
            ->and($avatar['fields'][0])
            ->toBe([
                'name' => 'profileImage',
                'label' => 'profile_picture',
                'type' => 'picture',
                'group' => 'inworld',
                'readOnly' => true,
                'default' => 'person',
                'banner' => true,
            ])
            ->and(array_column($avatar['fields'], null, 'name')['role'])
            ->toBe([
                'name' => 'role',
                'label' => 'role',
                'type' => 'select',
                'group' => 'account',
                'options' => [
                    'resident' => 'role_resident',
                    'god-like' => 'role_god_like',
                    'customer-service' => 'role_customer_service',
                    'liaison' => 'role_liaison',
                    'god' => 'role_god',
                    'maintenance' => 'role_maintenance',
                ],
                'banner' => true,
                'bare' => true,
                // A role is set by a god, as OpenSimulator sets a level
                'access' => ['level' => 200],
            ])
            ->and(array_column($avatar['fields'], null, 'name')['about']['access'])
            ->toBe(['edit' => ['self', 'manager'], 'level' => 100])
            ->and(array_column($avatar['actions'], 'name'))
            ->toBe(['enable', 'disable', 'reset', 'ban', 'unban', 'estateBan', 'estateUnban'])
            ->and(array_column($region['fields'], null, 'name')['enabled'])
            ->toBe([
                'name' => 'enabled',
                'label' => 'column_enabled',
                'type' => 'boolean',
                'group' => 'region',
                'condition' => ['local' => [true]],
                'access' => ['level' => 200],
            ])
            // One group: the simulator shown by its name only, its address and its files left to its own page
            ->and(array_column($region['groups'], 'name'))
            ->toBe(['region'])
            ->and(array_column($region['fields'], 'name'))
            ->toBe(['mapImage', 'name', 'uuid', 'location', 'size', 'internalPort', 'ownerName', 'owner', 'serverName', 'roles', 'flagNames', 'online', 'lastSeen', 'uri', 'enabled'])
            // Its roles and its owner are changed; its name, its place and its size are its file's
            ->and(array_column(array_filter($region['fields'], static fn(array $f): bool => !($f['readOnly'] ?? false)), 'type', 'name'))
            ->toBe(['owner' => 'avatar', 'roles' => 'checkboxes', 'enabled' => 'boolean'])
            // Unknown (null) when this machine has no file of the region (local false): neither the field nor its actions are shown
            ->and((new \W4OS\Engine\Forms\FieldSet($region['fields']))->visible('enabled', ['local' => false, 'enabled' => null]))
            ->toBeFalse()
            ->and((new \W4OS\Engine\Forms\FieldSet($region['fields']))->visible('enabled', ['local' => true, 'enabled' => false]))
            ->toBeTrue()
            ->and(array_column($region['actions'], 'when', 'name'))
            ->toBe([
                'enable' => ['local' => [true], 'enabled' => [false]],
                'disable' => ['local' => [true], 'enabled' => [true]],
            ])
            ->and(\W4OS\Engine\Models\Region::itemForm()->writable())
            ->toBe(['owner', 'roles', 'enabled'])
            // A parcel is only shown: it is changed in the viewer
            ->and(\W4OS\Engine\Models\Parcel::itemForm()->writable())
            ->toBe([])
            ->and(array_column($parcel['fields'], null, 'name')['uri'])
            ->toBe(['name' => 'uri', 'label' => 'column_uri', 'type' => 'link', 'group' => 'parcel', 'readOnly' => true, 'link' => 'teleport'])
            ->and($paths['/grids/{grid}/estates/{id}']['get'])
            ->not->toHaveKey('x-form');
    });

    test('is written in JSON and in YAML, the pages of the documentation being made from it', function () {
        expect(json_decode(Contract::json('2'), true)['info']['version'])
            ->toBe('2')
            ->and(Contract::yaml())
            ->toContain('openapi: 3.1.0')
            ->toContain('/grids/{grid}/avatars')
            ->and(Contract::attributes('services'))
            ->toHaveKey('kind');
    });

    test('knows every attribute the models give, and only them', function () {
        $machine = new Machine();
        $grid = Grid::find($machine->environment, 'demo');
        $details = [
            'grids' => $grid->detail(),
            'simulators' => $grid->simulator('alpha')->detail(),
            'services' => $grid->service('asset')->detail(),
            'archives' => archive_machine()->detail(),
        ];
        Connections::using(static fn() => avatar_creation_database());
        $details['avatars'] = first_attributes(machine_api(avatar_grid())->list('avatars', [], grid: 'demo'));
        Connections::using(static fn() => model_database());
        $details['avatar-models'] = first_attributes(machine_api(avatar_grid())->list('avatar-models', [], grid: 'demo'));
        Connections::using(static fn() => region_database());
        $details['regions'] = first_attributes(machine_api(region_config())->list('regions', [], grid: 'demo'));
        parcels_in(parcel_databases());
        $details['parcels'] = first_attributes(machine_api(parcel_config())->list('parcels', [], grid: 'demo'));
        $details['estates'] = first_attributes(machine_api(estate_world())->list('estates', [], grid: 'demo'));
        $profiles = new W4OS\Engine\Data\OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        Connections::using(static fn() => $profiles);
        W4OS\Engine\Flux\Posts::for(avatar_grid())->add('11111111-1111-4111-8111-111111111111', 'Ada Love', 'Hello');
        $details['posts'] = first_attributes(machine_api(avatar_grid())->list('posts', [], grid: 'demo'));
        Connections::using(static fn() => asset_database());
        $details['assets'] = first_attributes(machine_api(asset_config())->list('assets', [], grid: 'demo'));
        // The search: its places, land, events and classifieds, its groups (asked by their id: with their members), its people
        [$searched] = search_models();
        foreach (['search/places', 'search/land', 'search/events', 'search/classifieds'] as $kind) {
            $details[$kind] = first_attributes(machine_api($searched)->list($kind, [], grid: 'demo'));
        }
        $details['search/groups'] = machine_api(group_grid())->get('search/groups', 'g1', 'demo')->document['data']['attributes'];
        Connections::using(static fn() => avatar_creation_database());
        $details['search/people'] = W4OS\Engine\Models\SearchPerson::all(avatar_grid())->items()[0]->detail();
        $scheduler = new Scheduler(test_tmp() . '/api-cron-' . bin2hex(random_bytes(4)), new ClockFake());
        $scheduler->add(new Task('count', 60, fn() => null));
        $details['tasks'] = first_attributes(machine_api(scheduler: $scheduler)->list('tasks'));
        $details['forms'] = first_attributes(machine_api()->list('forms'));
        $drawn = [];
        $guided = guided_machine();
        $details['destinations'] = first_attributes(
            (new Api($guided->environment))->list('destinations', [], grid: 'demo'),
        );
        $details['fonts'] = first_attributes(
            (new Api($guided->environment, texts: text_renderer($drawn)))->list('fonts'),
        );

        expect(array_keys($details))->toEqualCanonicalizing(array_keys(Contract::RESOURCES));
        foreach ($details as $type => $detail) {
            unset($detail['id']);
            $known = array_keys(Contract::attributes($type));
            expect(array_values(array_diff(array_keys($detail), $known)))
                ->toBe([], "$type: given by the model, unknown to the contract")
                ->and(array_values(array_diff($known, array_keys($detail))))
                ->toBe([], "$type: in the contract, not given by the model");
        }
    });

    test('says the types of the values of the models', function () {
        $grid = Grid::find((new Machine())->environment, 'demo');
        $service = $grid->service('asset');
        Connections::using(static fn() => avatar_creation_database());
        $avatar = first_attributes(machine_api(avatar_grid())->list('avatars', [], grid: 'demo'));
        Connections::using(static fn() => model_database());
        $model = machine_api(avatar_grid())->list('avatar-models', [], grid: 'demo')->document['data'];
        Connections::using(static fn() => region_database());
        $region = first_attributes(machine_api(region_config())->list('regions', [], grid: 'demo'));
        parcels_in(parcel_databases());
        $parcel = first_attributes(machine_api(parcel_config())->list('parcels', [], grid: 'demo'));
        $estate = first_attributes(machine_api(estate_world())->list('estates', [], grid: 'demo'));
        Connections::using(static fn() => asset_database());
        $asset = first_attributes(machine_api(asset_config())->list('assets', [], grid: 'demo'));
        $types = [
            'string' => 'is_string',
            'integer' => 'is_int',
            'boolean' => 'is_bool',
            'array' => 'is_array',
            'object' => 'is_array',
            'number' => 'is_numeric',
        ];

        $wrong = [];
        foreach (
            [
                'grids' => $grid->detail(),
                'services' => $service->detail(),
                'avatars' => $avatar,
                'avatar-models' => $model[1]['attributes'],
                'avatar-models without a picture' => $model[2]['attributes'],
                'regions' => $region,
                'parcels' => $parcel,
                'estates' => $estate,
            ]
            as $type => $detail
        ) {
            foreach (Contract::attributes(explode(' ', $type)[0]) as $name => [$kind]) {
                $check = $types[rtrim($kind, '?')];
                $check($detail[$name]) ||
                    (str_ends_with($kind, '?') && $detail[$name] === null) ||
                    ($wrong[] = "$type.$name is $kind, the model gives " . get_debug_type($detail[$name]));
            }
        }
        expect($wrong)->toBe([]);
    });
});

describe('The API, for the assets of a grid', function () {
    test(
        'lists their records, and gives a texture as a picture, the picture of nothing with a 404 for what is not there',
        function () {
            Connections::using(static fn() => asset_database());
            $machine = new Machine();
            $id = sprintf(
                '%08x-0000-4000-8000-%012x',
                random_int(1, PHP_INT_MAX >> 40),
                random_int(1, PHP_INT_MAX >> 20),
            );
            $machine->http['http://localhost:8003/assets/' . $id] =
                '<AssetBase><Data>' . base64_encode('JP2-BYTES') . "</Data><ID>$id</ID><Type>0</Type></AssetBase>";
            $api = new Api($machine->environment, null, static fn(Grid $grid): GridConfig => asset_config());
            $calls = [];

            $list = $api->list('assets', ['filter' => ['type' => 'texture']], grid: 'demo');
            $picture = $api->media('demo', $id, 'png', asset_converter($calls));
            $nothing = $api->media('demo', 'ffffffff-0000-4000-8000-000000000000', 'png', asset_converter($calls));

            expect($list->document['meta']['total'])
                ->toBe(1)
                ->and($list->document['data'][0]['type'])
                ->toBe('assets')
                ->and($picture->status)
                ->toBe(200)
                ->and($picture->headers['Content-Type'])
                ->toBe('image/png')
                ->and($picture->body())
                ->toBe('png:JP2-BYTES')
                ->and($nothing->status)
                ->toBe(404)
                ->and($nothing->headers['Content-Type'])
                ->toBe(Problem::CONTENT_TYPE)
                ->and($api->media('nowhere', $id)->status)
                ->toBe(404);
        },
    );
});

describe('The documentation of the API', function () {
    test(
        'is made from the contract by `composer docs`: the pages of docs/api are the ones of the contract',
        function () {
            $committed = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/docs/api/openapi.json'), true);
            $current = Contract::document();
            unset($committed['info']['version'], $current['info']['version']);

            expect($committed)->toBe($current);
        },
    )->skip(fn() => !is_file(dirname(__DIR__, 2) . '/docs/api/openapi.json'), 'the documentation is not made');

    test('is written by the script, with the document in the page', function () {
        $dir = test_tmp() . '/api-docs-' . bin2hex(random_bytes(4));
        exec(
            escapeshellarg(PHP_BINARY) .
                ' ' .
                escapeshellarg(dirname(__DIR__, 2) . '/dev/api-docs.php') .
                ' ' .
                escapeshellarg($dir) .
                ' 2>&1',
            $output,
            $code,
        );

        expect($code)
            ->toBe(0, implode("\n", $output))
            ->and("$dir/openapi.json")
            ->toBeFile()
            ->and("$dir/openapi.yaml")
            ->toBeFile()
            ->and(file_get_contents("$dir/index.html"))
            ->toContain('Redoc.init')
            ->toContain('"openapi": "3.1.0"')
            ->toContain('href="try.html"')
            ->and(file_get_contents("$dir/try.html"))
            ->toContain('SwaggerUIBundle')
            ->toContain('persistAuthorization')
            ->toContain('"ApiKey"');
    })->skip(fn() => !is_file(dirname(__DIR__, 2) . '/dev/api-docs.php'), 'the script is not there');
});

describe('The endpoints of a fixed format', function () {
    test('describe the methods the services answer, no more and no fewer', function () {
        $db = test_pdo();
        $money = new W4OS\Engine\Economy\Currency(
            new W4OS\Engine\Economy\CurrencySettings(scriptKey: 'key'),
            static fn() => null,
        );
        $server = new W4OS\Engine\Services\CurrencyServer($money, '');

        expect(array_keys((new W4OS\Engine\Services\SearchQuery($db, 'regionsregister'))->methods()))
            ->toBe(array_keys(W4OS\Engine\Api\Formats::SEARCH))
            ->and(array_keys($server->currencyMethods()))
            ->toBe(array_keys(W4OS\Engine\Api\Formats::CURRENCY))
            ->and(array_keys($server->landMethods()))
            ->toBe(array_keys(W4OS\Engine\Api\Formats::LAND))
            ->and(array_keys(W4OS\Engine\Api\Formats::OFFLINE))
            ->toBe(['SaveMessage', 'RetrieveMessages']);
    })->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

    test('are in the contract with the methods and examples of their calls', function () {
        $paths = Contract::document()['paths'];
        $query = $paths['/grids/{grid}/search/query']['post'];
        $currency = $paths['/grids/{grid}/currency.php']['post'];

        expect(array_keys($query['requestBody']['content']['text/xml']['examples']))
            ->toBe(array_keys(W4OS\Engine\Api\Formats::SEARCH))
            ->and($query['description'])
            ->toContain('### `dir_places_query`', '| `text` | string |')
            ->and($query['requestBody']['content']['text/xml']['examples']['dir_places_query']['value'])
            ->toContain('<methodName>dir_places_query</methodName>', '<name>text</name>')
            ->and($query['responses']['200']['content']['text/xml']['examples']['dir_places_query']['value'])
            ->toContain('<name>parcel_id</name>')
            ->and(array_column($query['parameters'], 'name'))
            ->toBe(['grid', 'gk'])
            ->and(array_keys($currency['requestBody']['content']['text/xml']['examples']))
            ->toBe([
                'getCurrencyQuote',
                'buyCurrency',
                'simulatorUserBalanceRequest',
                'regionMoveMoney',
                'simulatorClaimUserRequest',
            ])
            ->and(array_column($paths['/grids/{grid}/search/register']['get']['parameters'], 'name'))
            ->toBe(['grid', 'host', 'port', 'service'])
            ->and($paths['/grids/{grid}/destinations']['get']['parameters'][1]['name'] ?? null)
            ->toBe('format')
            ->and(array_keys($paths['/grids/{grid}/destinations']['get']['responses']['200']['content']))
            ->toContain('text/plain');
    });

    test('are all in the contract, whatever the handler serves besides the resources', function () {
        $paths = Contract::document()['paths'];
        $served = [
            ['get', '/openapi.json', false],
            ['get', '/layout', true],
            ['post', '/tasks/{id}/runs', true],
            ['get', '/grids/{grid}/assets/{id}.{format}', true],
            ['get', '/text-images', true],
            ['get', '/grids/{grid}/motd', true],
            ['get', '/grids/{grid}/search/register', true],
            ['post', '/grids/{grid}/search/query', true],
            ['post', '/grids/{grid}/offline-messages/SaveMessage', true],
            ['post', '/grids/{grid}/offline-messages/RetrieveMessages', true],
            ['post', '/grids/{grid}/currency.php', true],
            ['post', '/grids/{grid}/landtool.php', true],
        ];
        foreach ($served as [$method, $path, $documented]) {
            expect(isset($paths[$path][$method]))->toBe($documented, "$method $path");
        }
        // The services of the catalog whose address is a page or a feed have a page of their own in the contract
        expect(array_keys($paths))->toContain(
            '/search',
            '/welcome',
            '/guide',
            '/profile/{name}',
            '/grid_status',
            '/grid_status.rss',
        );
    });
});

describe('The problems of the API', function () {
    test('each say a stable code, the ones the contract lists', function () {
        $router = new W4OS\Engine\Api\Router(machine_api(), authenticator: new W4OS\Engine\Security\Locked());
        $open = new W4OS\Engine\Api\Router(machine_api());
        $codes = [
            'not-found' => Problem::from(W4OS\Engine\Exceptions\NotFound::for('thing'))->toArray(),
            'invalid-request' => Problem::from(new InvalidArgumentException('wrong'))->toArray(),
            'channel-unavailable' => Problem::from(ChannelUnavailable::for('do it'))->toArray(),
            'internal-error' => (static function (): array {
                // The failure goes to the log, kept out of the output of the tests
                $log = ini_set('error_log', test_tmp() . '/problem.log');
                try {
                    return Problem::from(new RuntimeException('secret'))->toArray();
                } finally {
                    ini_set('error_log', (string) $log);
                }
            })(),
            'name-taken' => Problem::from(W4OS\Engine\Exceptions\InvalidValue::because('name-taken', 'taken'))->toArray(),
            'unauthorized' => $router->get('/grids')->document,
            'method-not-allowed' => $open->route('PUT', '/grids')->document,
            'invalid-document' => $open->post('/grids/demo/avatars', '{nope')->document,
            'type-conflict' => $open->post('/grids/demo/avatars', ['data' => ['type' => 'regions', 'attributes' => []]])
                ->document,
        ];
        $schema = Contract::document()['components']['schemas']['Problem']['properties']['code'] ?? [];

        expect(array_map(static fn(array $problem): ?string => $problem['code'] ?? null, $codes))
            ->toBe(array_combine(array_keys($codes), array_keys($codes)))
            ->and($schema['enum'] ?? [])
            ->toBe(array_keys(Problem::CODES))
            ->and(array_diff(array_keys($codes), $schema['enum'] ?? []))
            ->toBe([]);
    });
});

describe('The pictures of what has none', function () {
    test('are the picture of nothing for a place, the silhouette of a person for a profile, found by their ids; a missing one is a 404 without a picture', function () {
        Connections::using(static fn() => asset_database());
        $api = new Api((new Machine())->environment, null, static fn(Grid $grid): GridConfig => asset_config());
        $calls = [];
        $share = dirname(__DIR__, 2) . '/share/images';
        $missing = 'ffffffff-0000-4000-8000-000000000001';

        $nothing = $api->media('demo', WebAssets::PLACEHOLDER, 'jpg', asset_converter($calls));
        $person = $api->media('demo', WebAssets::PERSON, 'jpg', asset_converter($calls));
        $model = $api->media('demo', W4OS\Engine\Models\AvatarModel::PLACEHOLDER, 'jpg', asset_converter($calls));
        $place = $api->media('demo', $missing, 'jpg', asset_converter($calls));
        $profile = $api->media('demo', $missing, 'jpg', asset_converter($calls), null, 'person');
        $none = $api->media('demo', '00000000-0000-0000-0000-000000000000', 'jpg', asset_converter($calls), null, 'person');

        expect([$nothing->status, $nothing->headers['Content-Type'], $person->status])
            ->toBe([200, 'image/png', 200])
            ->and($nothing->body())
            ->toBe(file_get_contents("$share/assets-no-img.png"))
            ->and($person->body())
            ->toBe(file_get_contents("$share/assets-no-person.png"))
            ->and($model->body())
            ->toBe($person->body())
            ->and([$place->status, $place->headers['Content-Type'], $profile->status, $profile->headers['Content-Type']])
            ->toBe([404, Problem::CONTENT_TYPE, 404, Problem::CONTENT_TYPE])
            ->and($none->status)
            ->toBe(404)
            ->and($calls)
            ->toBe([]);
    });
});
