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
            ->toBe('Statut');
    });

    test('has, for each of its actions, the message a site says once it is done (manage_<action>)', function () {
        $missing = [];
        foreach (contract_lists() as $path => $list) {
            foreach ($list['actions'] ?? [] as $action) {
                $key = 'manage_' . strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $action['name']));
                isset(OpenSim\I18n\Catalog::MESSAGES[$key]) || ($missing[] = "$path: {$action['name']} ($key)");
            }
        }

        expect($missing)
            ->toBe([])
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
