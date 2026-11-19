<?php

declare(strict_types=1);

/**
 * The page of the destinations guide (provided_grid() of ProvideChoicesTest): its source is 2do.directory's guide by default, the
 * help of the field says only what it takes, the format of a list is the help of the page, and the example of a list is shown
 * apart, at the bottom, as what it is. The lines of a file are named as such.
 */

use OpenSim\I18n\Catalog;
use OpenSim\Models\GridSettings;

describe('The page of the guide', function () {
    test('has the guide of 2do.directory as its source by default, the help of the field about what it takes only', function () {
        [, $router] = provided_grid();
        $source = provided_fields('guide')['guideSource'];

        $read = $router->get('/grids/demo/settings/guide')->document;

        expect($read['data']['attributes']['guideSource'])->toBe('https://2do.directory/destinations/guide')
            ->and($source['placeholder'])->toBe('https://2do.directory/destinations/guide')
            ->and($source['description'])->toBe(['guide_source_help_where', 'guide_source_help_name'])
            ->and(GridSettings::form('guide')['description'])->toContain('guide_source_help_pipe')->toContain('guide_source_help_categories')
            // The example of a list, apart; the lines of a file, named
            ->and($read['meta']['examples'] ?? [])->toHaveCount(1)
            ->and($read['meta']['examples'][0]['label'])->toBe('guide_example')
            ->and($read['meta']['examples'][0]['text'])->toContain('Display Name|yourgrid.org:8002/Region Name')
            ->and(array_column($read['meta']['snippets'], 'file'))->toBe(['Robust.HG.ini', 'Helpers.ini'])
            ->and(Catalog::MESSAGES['guide_example'])->toBe('Example of a destinations file')
            ->and(Catalog::messages('fr_FR', false)['guide_source_help_name'] ?? '')->not->toBe('');
    });
});

describe('The guide of a grid that sets no source', function () {
    test('has no destination: no source, no guide', function () {
        $machine = new OpenSim\Testing\Machine();
        $machine->http['https://2do.directory/destinations/guide'] = "Hypergrid\nPlace|hg.example.org:8002/Place\n";

        expect(OpenSim\Models\Grid::find($machine->environment, 'demo')->destinations())->toBe([]);
    });

    test('gets the guide of 2do.directory when it is saved empty or switched on', function () {
        [$machine, $router] = provided_grid();
        $patch = static fn(array $attributes) => $router->patch('/grids/demo/settings/guide', ['data' => ['type' => 'settings', 'id' => 'guide', 'attributes' => $attributes]]);

        $on = $patch(['provide' => true]);
        $onIni = file_get_contents($machine->grid . '/Helpers.ini');
        $patch(['guideSource' => 'https://guide.example.org/list.txt']);
        $empty = $patch(['guideSource' => '']);

        expect(provided_fields('guide')['guideSource'])->not->toHaveKey('required')
            ->and($on->status)->toBe(200)
            ->and($onIni)->toContain('GuideSource = "https://2do.directory/destinations/guide"')
            ->and($empty->document['data']['attributes']['guideSource'])->toBe('https://2do.directory/destinations/guide')
            ->and(file_get_contents($machine->grid . '/Helpers.ini'))->toContain('GuideSource = "https://2do.directory/destinations/guide"');
    });
});
