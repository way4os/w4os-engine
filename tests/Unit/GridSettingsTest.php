<?php

declare(strict_types=1);

/**
 * The settings of a grid a site shows and changes, by group, each with its form (`x-form`): the rule of the avatar models first,
 * the one of w4os-wp (Match, MatchPattern, List of `[Models]`).
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Config\ConfigReader;
use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\I18n\Catalog;
use W4OS\Engine\Models\AvatarModel;
use W4OS\Engine\Testing\Machine;

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

    test('has its form in the contract, in the words of w4os-wp and their translations: Match as radios, the help in five points, the preview', function () {
        $contract = W4OS\Engine\Api\Contract::document();
        $operations = $contract['paths']['/grids/{grid}/settings/models'];
        $form = $operations['get']['x-form'];
        $fields = array_column($form['fields'], null, 'name');
        $en = Catalog::MESSAGES;
        $fr = Catalog::messages('fr_FR');
        $text = static fn(array $texts, string ...$keys): array => array_map(static fn(string $key): ?string => $texts[$key] ?? null, $keys);

        expect(array_keys($fields))
            ->toBe(['match', 'pattern', 'list'])
            ->and($fields['match'])
            ->toMatchArray(['type' => 'select', 'widget' => 'radio'])
            ->and(array_keys($fields['match']['options']))
            ->toBe(['first', 'any', 'last', 'uuid'])
            ->and($text($en, $form['title'], $fields['match']['label'], ...array_values($fields['match']['options'])))
            ->toBe(['Avatar Models', 'Match', 'First Name', 'Any', 'Last Name', 'Custom list'])
            ->and($text($en, $fields['pattern']['label'], $fields['list']['label'], $fields['list']['placeholder'], $form['submit']))
            ->toBe(['Name', 'Select Models', 'Select one or more existing avatars', 'Save Changes'])
            ->and($text($fr, $fields['match']['label'], $fields['match']['options']['uuid'], $fields['list']['label'], $form['submit']))
            ->toBe(['Correspondance', 'Liste personnalisée', 'Sélectionner les modèles', 'Enregistrer les modifications'])
            ->and($fields['pattern']['condition'])
            ->toBe(['match' => ['first', 'any', 'last']])
            ->and($fields['list'])
            ->toMatchArray(['type' => 'avatar', 'multiple' => true, 'condition' => ['match' => ['uuid']]])
            ->and($fields)
            ->each->not->toHaveKey('description')
            // The help of w4os-wp, its five points
            ->and($text($en, ...$form['description']))
            ->toBe([
                'If avatar models are defined, the avatar creation form offers a choice of them, which determines the initial outfit of the new avatar.',
                'The grid administrator needs to create each model from the ROBUST console, log in with the viewer, customize the outfit, and add a profile picture.',
                'The avatars used for the models should never be avatars that people use.',
                'The new avatar will wear the same outfit as the model at the time of registration.',
                'If the model assignment rule is based on the name, each newly created avatar matching that rule will be automatically added to the list.',
            ])
            ->and($fr[$form['description'][2]] ?? null)
            ->toBe('Les avatars utilisés comme modèles ne doivent jamais être des avatars que des personnes utilisent.')
            // The models the rule of the form picks, asked as it is changed
            ->and($form['preview'])
            ->toBe(['label' => 'models_preview', 'empty' => 'models_none', 'path' => '/grids/{grid}/avatar-models', 'filters' => ['match', 'pattern', 'list']])
            ->and($text($en, 'models_preview', 'models_none'))
            ->toBe(['Models Preview', 'No models found.'])
            ->and([$operations['get']['x-access']['level'], $operations['patch']['x-access']['level']])
            ->toBe([100, 200]);
    });
});

describe('The grid info of a grid', function () {
    test('refuses what OpenSim cannot send in it, its ASCII form proposed', function () {
        $machine = new \W4OS\Engine\Testing\Machine();
        $router = new \W4OS\Engine\Api\Router(new \W4OS\Engine\Api\Api($machine->environment));
        $patch = static fn(string $name) => $router->patch('/grids/demo/settings/grid', ['data' => ['type' => 'settings', 'id' => 'grid', 'attributes' => ['gridName' => $name]]]);

        $refused = $patch('Kimbé Rèd');

        expect($refused->status)->toBe(400)
            ->and($refused->document['errors'][0]['detail'] ?? $refused->document['detail'] ?? '')->toContain("'Kimbé Rèd' has characters OpenSim's grid info cannot send non-ASCII characters: 'Kimbe Red' instead")
            ->and($patch('Kimbe Red')->status)->toBe(200);
    });
});
