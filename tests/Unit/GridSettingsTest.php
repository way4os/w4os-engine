<?php

declare(strict_types=1);

/**
 * The settings of a grid a site shows and changes, by group, each with its form (`x-form`): the rule of the avatar models first,
 * the one of w4os-wp (Match, MatchPattern, List of `[Models]`).
 */

use OpenSim\Api\Api;
use OpenSim\Api\Router;
use OpenSim\Config\ConfigReader;
use OpenSim\Config\GridConfig;
use OpenSim\I18n\Catalog;
use OpenSim\Models\AvatarModel;
use OpenSim\Testing\Machine;

const MODEL_ONE = '11111111-1111-4111-8111-111111111111';
const MODEL_TWO = '22222222-2222-4222-8222-222222222222';

describe('The rule of the avatar models', function () {
    test('is read with the defaults of w4os when the grid says none: either name, Default, no list', function () {
        $machine = new Machine();
        $router = new Router(new Api($machine->environment));

        $read = $router->get('/grids/demo/settings/models');

        expect($read->status)
            ->toBe(200)
            ->and($read->document['data'])
            ->toMatchArray(['type' => 'settings', 'id' => 'models'])
            ->and($read->document['data']['attributes'])
            ->toBe(['match' => 'any', 'pattern' => 'Default', 'list' => []]);
    });

    test('is written in the files of the grid, read back, and is the rule the models are picked by', function () {
        $machine = new Machine();
        $router = new Router(new Api($machine->environment));
        $patch = static fn(array $attributes) => $router->patch('/grids/demo/settings/models', ['data' => ['type' => 'settings', 'id' => 'models', 'attributes' => $attributes]]);

        $pattern = $patch(['match' => 'last', 'pattern' => 'Model']);
        $list = $patch(['match' => 'uuid', 'list' => [MODEL_TWO, strtoupper(MODEL_ONE)]]);
        $read = $router->get('/grids/demo/settings/models');
        $helpers = ConfigReader::load($machine->grid . '/Helpers.ini', expand: false);

        expect($pattern->status)
            ->toBe(200)
            ->and($pattern->document['data']['attributes'])
            ->toBe(['match' => 'last', 'pattern' => 'Model', 'list' => []])
            ->and($list->document['data']['attributes'])
            ->toBe(['match' => 'uuid', 'pattern' => 'Model', 'list' => [MODEL_TWO, MODEL_ONE]])
            ->and($read->document['data']['attributes'])
            ->toBe($list->document['data']['attributes'])
            ->and($helpers['Models'] ?? [])
            ->toBe(['Match' => 'uuid', 'MatchPattern' => 'Model', 'List' => MODEL_TWO . ', ' . MODEL_ONE])
            ->and(AvatarModel::rule(GridConfig::fromFolder($machine->grid, ['Helpers' => 'Helpers.ini'])))
            ->toBe(['uuid', 'Model', [MODEL_TWO, MODEL_ONE]]);
    });

    test('is written where the grid already says it, and refuses what is not a rule', function () {
        $machine = new Machine();
        $robust = $machine->grid . '/Robust.HG.ini';
        file_put_contents($robust, (string) file_get_contents($robust) . "\n[Models]\n    Match = first\n");
        $router = new Router(new Api($machine->environment));
        $patch = static fn(array $attributes) => $router->patch('/grids/demo/settings/models', ['data' => ['type' => 'settings', 'id' => 'models', 'attributes' => $attributes]]);

        $written = $patch(['match' => 'any']);
        $match = $patch(['match' => 'middle']);
        $uuid = $patch(['list' => ['not-a-uuid']]);
        $unknown = $router->get('/grids/demo/settings/nothing');

        expect($written->status)
            ->toBe(200)
            ->and(ConfigReader::load($robust, expand: false)['Models']['Match'] ?? null)
            ->toBe('any')
            ->and(is_file($machine->grid . '/Helpers.ini'))
            ->toBeFalse()
            ->and([$match->status, $uuid->status, $unknown->status])
            ->toBe([400, 400, 404]);
    });

    test('has its form in the contract: the choices, the fields shown for each, the levels, the texts of the catalog', function () {
        $contract = OpenSim\Api\Contract::document();
        $operations = $contract['paths']['/grids/{grid}/settings/models'];
        $form = $operations['get']['x-form'];
        $fields = array_column($form['fields'], null, 'name');
        $labels = [...array_column($form['fields'], 'label'), ...array_filter(array_column($form['fields'], 'description')), $form['title'], $form['submit']];
        foreach ($fields['match']['options'] as $label) {
            $labels[] = $label;
        }

        expect(array_keys($fields))
            ->toBe(['match', 'pattern', 'list'])
            ->and(array_keys($fields['match']['options']))
            ->toBe(['first', 'last', 'any', 'uuid'])
            ->and($fields['pattern']['condition'])
            ->toBe(['match' => ['first', 'last', 'any']])
            ->and($fields['list'])
            ->toMatchArray(['type' => 'avatar', 'multiple' => true, 'condition' => ['match' => ['uuid']]])
            ->and([$operations['get']['x-access']['level'], $operations['patch']['x-access']['level']])
            ->toBe([100, 200])
            ->and(array_diff($labels, array_keys(Catalog::MESSAGES)))
            ->toBe([]);
    });
});
