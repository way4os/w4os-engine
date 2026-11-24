<?php

declare(strict_types=1);

/**
 * What the helpers of a grid answer: the addresses its config names on its own site, whatever their path (the four prefixes of an
 * install of before), never a path nobody set, never another host.
 */

use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\Web\Snippets;
use W4OS\Engine\Testing\Machine;

describe('The paths of the helpers', function () {
    /** A grid whose site is kimbe.example, its helpers under /opensim/economy, its services where an install of before put them. */
    $kimbe = static function (): Grid {
        $machine = new Machine();
        $robust = $machine->grid . '/Robust.HG.ini';
        file_put_contents($robust, str_replace("[Const]\n", "[Const]\n    WebURL = \"https://kimbe.example\"\n", (string) file_get_contents($robust)) .
            "\n[GridInfoService]\n    economy = \"\${Const|WebURL}/opensim/economy\"\n    message = \"https://other.example/motd.php\"\n");
        $machine->write($machine->grid . '/Helpers.ini', "[Helpers]\nHelpersURL = \"https://kimbe.example/opensim/economy\"\n");
        $machine->write($machine->grid . '/OpenSim.ini', "[Search]\n    SearchURL = \"https://kimbe.example/helper/query.php\"\n" .
            "[Messaging]\n    OfflineMessageModule = OfflineMessageModule\n    OfflineMessageURL = \"https://kimbe.example/helpers/helper/offline.php\"\n");

        return Grid::find($machine->environment, 'demo');
    };

    test('are the addresses its config names on its site, each where it is, never one of another host', function () use ($kimbe) {
        $paths = $kimbe()->helperPaths();

        expect($paths['query'] ?? null)->toBe('/helper/query.php')
            ->and($paths['offline'] ?? null)->toBe('/helpers/helper/offline.php')
            ->and($paths['currency'] ?? null)->toBe('/opensim/economy/currency.php')
            ->and($paths['landtool'] ?? null)->toBe('/opensim/economy/landtool.php')
            // The message of the day of another host is not theirs
            ->and($paths)->not->toHaveKey('motd');
    });

    test('are what the helpers answer: those and their base, never a path nobody set, never another host', function () use ($kimbe) {
        $grid = $kimbe();

        expect($grid->servedByHelpers('https://kimbe.example/helper/query.php'))->toBeTrue()
            // The two methods of the module V1 follow its address
            ->and($grid->servedByHelpers('https://kimbe.example/helpers/helper/offline.php/SaveMessage/'))->toBeTrue()
            // Their base, its pages, as before
            ->and($grid->servedByHelpers('https://kimbe.example/opensim/economy/welcome'))->toBeTrue()
            ->and($grid->servedByHelpers('https://kimbe.example/helper/other.php'))->toBeFalse()
            ->and($grid->servedByHelpers('https://kimbe.example/helpers/helper/offline.phpx'))->toBeFalse()
            ->and($grid->servedByHelpers('https://other.example/motd.php'))->toBeFalse();
    });

    test('are the ones the web server hands over, under a prefix those under it alone', function () use ($kimbe) {
        $grid = $kimbe();
        $matcher = static fn(string $snippet): string => (string) preg_replace('/^.*@opensim_helpers path ([^\n]*)\n.*$/s', '$1', $snippet);
        $root = $matcher(Snippets::render('caddy', 'demo', $grid->directory(), grid: $grid));
        $prefixed = $matcher(Snippets::render('caddy', 'demo', $grid->directory(), prefix: '/opensim', grid: $grid));

        expect($root)->toContain('/helper/query.php')->toContain('/helpers/helper/offline.php /helpers/helper/offline.php/*')->toContain('/opensim*')
            ->and($prefixed)->toBe('/opensim/economy /opensim/economy/*');
    });

    test('leave the economy to a third-party application when the grid says it does not provide it, even on its own host', function () use ($kimbe) {
        $grid = $kimbe();
        // The economy on the site, run by another application: Provide off decides, never the address alone
        file_put_contents($grid->directory() . '/Helpers.ini', "[Helpers]\nHelpersURL = \"https://kimbe.example/opensim/economy\"\nEconomyProvided = false\n");
        $grid = Grid::find($grid->environment(), 'demo');

        expect($grid->helperPaths())->not->toHaveKey('currency')->not->toHaveKey('landtool')
            ->and($grid->servedByHelpers('https://kimbe.example/opensim/economy/currency.php'))->toBeFalse()
            // The rest of the helpers, under the same base, stays theirs
            ->and($grid->servedByHelpers('https://kimbe.example/opensim/economy/welcome'))->toBeTrue()
            ->and($grid->helperPaths()['query'] ?? null)->toBe('/helper/query.php');
    });
});
