<?php

declare(strict_types=1);

/**
 * The web services the setup asks (opensim setup): where the simulators register for the search, the calendar of the events, the
 * economy of the helpers or of another service, as the options of the grid write them.
 */

use W4OS\Engine\Config\ConfigReader;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\GridOptions;
use W4OS\Engine\Testing\Machine;

describe('The web services a setup chose', function () {
    $layer = static function (Machine $machine, W4OS\Engine\Provisioning\GridPlan $plan): array {
        $text = (new GridOptions(Grid::planned($machine->environment, $plan), extra: $plan->variables()))->layer();

        return ConfigReader::load($machine->write($machine->root . '/layer-' . bin2hex(random_bytes(3)) . '.ini', $text), expand: false);
    };

    test('register the regions with the helpers and 2do.directory, the helpers only, or 2do.directory only', function () use ($layer) {
        $machine = new Machine();
        $plan = demo_plan($machine);
        $plan->options = ['search'];
        $registers = [];
        foreach (['both', 'helpers', '2do'] as $register) {
            $plan->register = $register;
            $registers[$register] = $layer($machine, $plan)['DataSnapshot'] ?? [];
        }

        expect(array_intersect_key($registers['both'], array_flip(['DATA_SRV_demo', 'DATA_SRV_2do'])))
            ->toBe(['DATA_SRV_demo' => 'https://demo.example.org/helpers/register.php', 'DATA_SRV_2do' => 'https://2do.directory/helpers/register.php'])
            ->and($registers['helpers'])->toHaveKey('DATA_SRV_demo')->not->toHaveKey('DATA_SRV_2do')
            ->and($registers['2do'])->toHaveKey('DATA_SRV_2do')->not->toHaveKey('DATA_SRV_demo');
    });

    test('open the web search of the helpers, and none without them: the viewers keep their own', function () {
        $read = static function (string $choice): array {
            $machine = new Machine();
            $plan = demo_plan($machine);
            $plan->webSearch = $choice;
            $plan->options = ['helpers', 'search'];
            $options = new GridOptions(Grid::planned($machine->environment, $plan), layered: true, extra: $plan->variables());
            $options->enable('helpers');
            $options->enable('search');
            $robust = ConfigReader::file($machine->grid . '/Robust.HG.ini');

            return [$robust['GridInfoService']['search'] ?? null, $robust['LoginService']['SearchURL'] ?? null];
        };
        $helpers = $read('internal');

        expect(trim((string) $helpers[0], '"'))->toStartWith('${Const|WebURL}/helpers/')
            ->and($helpers[1])->toBe($helpers[0])
            ->and($read('none'))->toBe([null, null])
            // 2do.directory has no web search yet: nothing points to it
            ->and(Grid::SHARED_WEB_SEARCH)->toBe('');
    });

    test('read the events from the calendar given, and keep the economy where it is said: the helpers, or another service', function () {
        $read = static function (string $economy): array {
            $machine = new Machine();
            $plan = demo_plan($machine);
            $plan->eventsUrl = 'https://events.example.org/calendar';
            $plan->economyPath = $economy;
            $plan->options = ['helpers'];
            (new GridOptions(Grid::planned($machine->environment, $plan), layered: true, extra: $plan->variables()))->enable('helpers');

            return [
                ConfigReader::file($machine->grid . '/Helpers.ini')['Helpers'] ?? [],
                ConfigReader::file($machine->grid . '/Robust.HG.ini')['GridInfoService']['economy'] ?? null,
            ];
        };
        [$helpers, $atBase] = $read('');
        [$elsewhereHelpers, $elsewhere] = $read('https://money.example.net/economy');
        [, $onSite] = $read('/money');

        expect($helpers['HypEventsURL'] ?? null)->toBe('https://events.example.org/calendar')
            // By default the helpers serve it at their path
            ->and(trim((string) $atBase, '"'))->toBe('${Const|WebURL}/helpers')
            ->and($helpers['EconomyProvided'] ?? null)->toBe('true')
            // A path of the site: the helpers there
            ->and(trim((string) $onSite, '"'))->toBe('${Const|WebURL}/money')
            // A full address: another service, which the helpers do not serve
            ->and(trim((string) $elsewhere, '"'))->toBe('https://money.example.net/economy')
            ->and($elsewhereHelpers['EconomyProvided'] ?? null)->toBe('false');
    });

    test('put the Quick Start website under its folder by its own commands, the root of the site left as it is', function () {
        $machine = new Machine();
        $plan = demo_plan($machine);
        $plan->gridNick = 'demo';
        $plan->webSite = 'website';
        $plan->sitePath = '/opensim';
        $root = demo_plan($machine);
        $root->webSite = 'website';

        // As root: the package when it is not there, the site connected to the grid, its web server config under the folder
        expect(W4OS\Engine\Provisioning\GridProvisioner::siteSteps($plan, false))
            ->toBe(['apt install w4os-site', 'w4os-site setup --grid demo', 'w4os-site web caddy --prefix /opensim --install'])
            ->and(W4OS\Engine\Provisioning\GridProvisioner::siteSteps($plan, true))->not->toContain('apt install w4os-site')
            // At the root of the site, the options of the grid serve it (webroot-website): no command of its own
            ->and(W4OS\Engine\Provisioning\GridProvisioner::siteSteps($root, true))->toBe([]);
    });
});
