<?php

declare(strict_types=1);

use OpenSim\Api\Contract;

/**
 * What every list of the contract keeps to, whatever its model: the address under the name, the status last, one label for it, the
 * dates in the order of a life (created, then last seen) and with their time.
 */

/** @return array<string,array<string,mixed>> the x-list of each list of the contract, by its path */
function contract_lists(): array
{
    return array_map(
        static fn(array $operations): array => $operations['get']['x-list'],
        array_filter(Contract::document()['paths'], static fn(array $operations): bool => isset($operations['get']['x-list'])),
    );
}

afterEach(fn() => OpenSim\Data\Connections::using(null));

describe('Every list', function () {
    test('shows the address or the slug under the name, never in a column of its own', function () {
        $wrong = [];
        foreach (contract_lists() as $path => $list) {
            $names = array_column($list['columns'], 'name');
            $subtitle = $list['columns'][0]['subtitle'] ?? [];
            foreach (array_intersect(['uri', 'slug'], $names) as $column) {
                $wrong[] = "$path: $column";
            }
            foreach ($subtitle as $attribute) {
                in_array($attribute, array_keys(Contract::attributes(basename($path))), true) || ($wrong[] = "$path: subtitle $attribute");
            }
        }
        $lists = contract_lists();

        expect($wrong)
            ->toBe([])
            ->and($lists['/simulators']['columns'][0]['subtitle'])
            ->toBe(['uri', 'slug'])
            ->and(array_intersect_key($lists['/grids/{grid}/regions']['columns'][0], ['subtitle' => 1, 'subtitleLink' => 1]))
            ->toBe(['subtitle' => ['uri'], 'subtitleLink' => 'teleport'])
            ->and($lists['/grids']['columns'][0]['subtitle'])
            ->toBe(['uri']);
    });

    test('says of each column whether its value reads without its label, and whether it shows at every width', function () {
        $wrong = [];
        foreach (contract_lists() as $path => $list) {
            foreach ($list['columns'] as $i => $column) {
                is_bool($column['bare'] ?? null) || ($wrong[] = "$path: {$column['name']} bare");
                in_array($column['priority'] ?? null, ['primary', 'secondary'], true) || ($wrong[] = "$path: {$column['name']} priority");
            }
            ($list['columns'][0]['priority'] ?? '') === 'primary' || ($wrong[] = "$path: the first column is not primary");
        }
        $avatars = array_column(contract_lists()['/grids/{grid}/avatars']['columns'], null, 'name');

        expect($wrong)
            ->toBe([])
            // An email, a role, a status say what they are; a date needs its label
            ->and([$avatars['email']['bare'], $avatars['shownRole']['bare'], $avatars['status']['bare'], $avatars['created']['bare']])
            ->toBe([true, true, true, false])
            ->and([$avatars['name']['priority'], $avatars['status']['priority'], $avatars['email']['priority']])
            ->toBe(['primary', 'primary', 'secondary']);
    });

    test('has its status last, under one label', function () {
        $wrong = [];
        foreach (contract_lists() as $path => $list) {
            foreach ($list['columns'] as $i => $column) {
                if ($column['type'] === 'indicators') {
                    $i === count($list['columns']) - 1 || ($wrong[] = "$path: not last");
                    $column['label'] === 'column_status' || ($wrong[] = "$path: {$column['label']}");
                }
            }
        }

        expect($wrong)
            ->toBe([])
            ->and(array_column(contract_lists()['/grids']['columns'], 'type', 'name'))
            ->toHaveKey('status')
            ->and(OpenSim\I18n\Catalog::messages('fr_FR')['column_status'])
            ->toBe('Statut')
            // Every tone an indicator uses is one the contract describes
            ->and(array_filter(OpenSim\Data\Listing::TONES, static fn(string $tone): bool => !str_contains(Contract::document()['info']['description'], "$tone ")))
            ->toBe([]);
    });

    test('has, for each of its actions, the message a site says once it is done (manage_<action>)', function () {
        $missing = [];
        foreach (contract_lists() as $path => $list) {
            foreach ($list['actions'] ?? [] as $action) {
                $key = OpenSim\I18n\Catalog::doneKey($action['name']);
                isset(OpenSim\I18n\Catalog::MESSAGES[$key]) || ($missing[] = "$path: {$action['name']} ($key)");
            }
        }

        expect($missing)
            ->toBe([])
            ->and([OpenSim\I18n\Catalog::doneKey('estateUnban'), OpenSim\I18n\Catalog::doneKey('disable')])
            ->toBe(['manage_estate_unban', 'manage_disable'])
            ->and(OpenSim\I18n\Catalog::messages('fr_FR')['manage_estate_unban'])
            ->toBe("%s n'est plus banni des domaines.");
    });

    test('shows when something was created before when it was last seen, both with their time', function () {
        $wrong = [];
        foreach (contract_lists() as $path => $list) {
            $names = array_column($list['columns'], 'name');
            $types = array_column($list['columns'], 'type', 'name');
            isset($types['created'], $types['lastSeen']) && array_search('created', $names, true) > array_search('lastSeen', $names, true)
                && ($wrong[] = "$path: lastSeen before created");
            foreach (array_intersect_key($types, ['created' => 1, 'lastSeen' => 1]) as $name => $type) {
                $type === 'datetime' || ($wrong[] = "$path: $name is $type");
            }
        }
        $avatars = array_column(contract_lists()['/grids/{grid}/avatars']['columns'], 'label', 'name');

        expect($wrong)
            ->toBe([])
            // An avatar is born, as the viewers say it
            ->and($avatars['created'])
            ->toBe('born')
            ->and(OpenSim\I18n\Catalog::messages('fr_FR')['born'])
            ->toBe('Né(e) le');
    });
});

describe('The counts of a list', function () {
    test('are by filter and value, each counted with the search and the other filters, not its own', function () {
        avatars_in(avatar_database());
        $counts = OpenSim\Models\Avatar::all(avatar_grid(), ['filters' => ['status' => 'active', 'role' => 'resident']])->counts();

        // Ada and Bob are active, Cy is not; among the active, Ada is a resident, Bob of the maintenance
        expect($counts['status'])
            ->toBe(['active' => 1, 'disabled' => 1, 'pending' => 0, 'banned' => 0])
            ->and(array_intersect_key($counts['role'], ['resident' => 1, 'maintenance' => 1]))
            ->toBe(['resident' => 1, 'maintenance' => 1])
            ->and($counts['type'])
            ->toBe(['user' => 1, 'model' => 0, 'service' => 0]);
    });

    test('are given by the API with the list, and by the lists kept in memory', function () {
        $machine = new OpenSim\Testing\Machine();
        $api = new OpenSim\Api\Api($machine->environment);
        $meta = $api->list('simulators', ['filter' => ['running' => 'yes']])->document['meta'];

        expect($meta['counts']['running'])
            ->toBe(['yes' => 0, 'no' => 2])
            ->and($meta['counts']['enabled'])
            ->toBe(['yes' => 0, 'no' => 0]);
    });
});

describe('The type of an avatar', function () {
    test('is a user, a model of the grid, or the account of its services, sorted and filtered so', function () {
        avatars_in(avatar_database());
        $config = avatar_grid("[Models]\n Match = \"uuid\"\n List = \"11111111-1111-1111-1111-111111111111\"\n");
        $types = static fn(array $options = []): array => array_column(OpenSim\Models\Avatar::all($config, $options)->array(), 'type', 'name');
        $columns = array_column(OpenSim\Models\Avatar::listing()->columns, null, 'name');

        expect($types())
            ->toMatchArray(['Ada Love' => 'model', 'Bob Root' => 'user'])
            ->and(array_keys($types(['filters' => ['type' => 'user']])))
            ->toBe(['Bob Root', 'Cy Gone'])
            ->and(array_keys($types(['sort' => 'type desc'])))
            ->toBe(['Ada Love', 'Bob Root', 'Cy Gone'])
            ->and($columns['type']['sort'] ?? null)
            ->toBe('type')
            ->and(array_column($columns['type']['values'], 'label', 'value'))
            ->toBe(['user' => 'avatar_type_user', 'model' => 'avatar_type_model', 'service' => 'avatar_type_service']);
    });
});

describe('The page of an item', function () {
    test('says what its head shows, as its row in the list: its name, its picture, under it its address, its status', function () {
        $paths = Contract::document()['paths'];
        $avatar = $paths['/grids/{grid}/avatars/{id}']['get']['x-form']['header'];
        $region = $paths['/grids/{grid}/regions/{id}']['get']['x-form']['header'];

        expect(array_diff_key($avatar, ['indicators' => 1]))
            ->toBe(['title' => 'name', 'picture' => 'profileImage', 'default' => 'person'])
            ->and(array_column($avatar['indicators'], 'name'))
            ->toBe(['accountState', 'ban'])
            ->and(array_diff_key($region, ['indicators' => 1]))
            ->toBe(['title' => 'name', 'picture' => 'mapImage', 'default' => 'place', 'subtitle' => ['uri'], 'subtitleLink' => 'teleport'])
            ->and(array_column($region['indicators'], 'name'))
            ->toBe(['enabled', 'online']);
    });
});
