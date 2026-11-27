<?php

declare(strict_types=1);

/**
 * The address of the Quick Start website of a grid, its own setting: changed alone by the API of its address, no other address of the
 * grid following it; where it stands (chosen, installed) said with the grid.
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Testing\Machine;

describe('The address of the website of a grid', function () {
    test('is changed alone: planned, then written, its web site and its pages left as they are, nothing to restart', function () {
        $machine = new Machine();
        $grid = Grid::find($machine->environment, 'demo');
        $web = $grid->webUrl();
        $api = new Api($machine->environment);
        $plan = json_decode($api->changeAddress('demo', ['website' => 'https://example.org/world', 'mode' => 'plan'])->body(), true)['data']['attributes'];
        $kept = Grid::find($machine->environment, 'demo')->siteAddress();
        $applied = json_decode($api->changeAddress('demo', ['website' => 'https://example.org/world', 'mode' => 'apply'])->body(), true);
        $after = Grid::find($machine->environment, 'demo');
        $wrong = json_decode($api->changeAddress('demo', ['website' => 'ftp://example.org/', 'mode' => 'apply'])->body(), true);

        expect($plan['to']['website'])->toBe('https://example.org/world/')
            ->and(array_column($plan['changes'], 'after'))->toBe(['SiteAddress = https://example.org/world/'])
            ->and($kept)->toBe('')
            ->and($after->siteAddress())->toBe('https://example.org/world/')
            ->and($after->siteState())->toBe('chosen')
            ->and($after->webUrl())->toBe($web)
            ->and($applied['meta'] ?? [])->toBe([])
            ->and($wrong['status'] ?? null)->toBe(400);
    });
});
