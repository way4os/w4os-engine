<?php

declare(strict_types=1);

/**
 * The settings pages of the services, ported from w4os-wp's v2 pages (v2/class-helpers-economy.php, -search.php, class-w4os-guide.php,
 * -offline.php, -assets.php): their fields in its order, its labels and help, its conditions, its placeholders, its sidebar as the
 * help of the page, the ini lines each shows, made by the engine for the grid (never a secret).
 */

use OpenSim\Api\Api;
use OpenSim\Api\Router;
use OpenSim\I18n\Catalog;
use OpenSim\Models\GridSettings;
use OpenSim\Testing\Machine;

/** @return list<string> the names of the fields of a page, in their order */
function page_fields(string $page): array
{
    return array_column(GridSettings::form($page)['fields'], 'name');
}

/** @return array<string,array<string,mixed>> the fields of a page by name */
function page_field(string $page): array
{
    return array_column(GridSettings::form($page)['fields'], null, 'name');
}

/** @param list<string>|string $keys @return list<string> their English texts */
function english(array|string $keys): array
{
    return array_map(static fn(string $key): string => Catalog::MESSAGES[$key] ?? "($key)", (array) $keys);
}

describe('The pages of the services, as w4os-wp', function () {
    test('have its fields in its order', function () {
        expect(page_fields('economy'))->toBe(['provide', 'path', 'economyUrl', 'economyModule', 'moneyDatabase', 'gloebitEnvironment', 'gloebitKey', 'gloebitSecret', 'gloebitOwnerName', 'gloebitOwnerEmail', 'gloebitDatabase', 'currencyRate', 'gloebitConfiguration', 'moneyScriptKey', 'podexMessage', 'podexUrl'])
            ->and(page_fields('search'))->toBe(['provide', 'searchDatabase', 'path', 'searchExternalUrl', 'registerPath', 'registerExternalUrl', 'eventsUrl'])
            ->and(page_fields('guide'))->toBe(['provide', 'guideSource', 'path', 'destinationGuide'])
            ->and(page_fields('offline'))->toBe(['provide', 'mailSender', 'offlineDatabase', 'offlineModule', 'path', 'offlineMessageUrl'])
            ->and(page_fields('assets'))->toBe(['provide', 'path', 'assetsUrl']);
    });

    test('in its words: the labels, the help in points, the sidebar as the help of the page', function () {
        $economy = page_field('economy');
        $search = page_field('search');
        $guide = page_field('guide');
        $offline = page_field('offline');

        expect(english([$economy['path']['label'], $economy['moneyScriptKey']['label'], $economy['gloebitConfiguration']['label'], $search['path']['label'], $search['registerPath']['label'], $guide['path']['label'], $offline['path']['label']]))
            ->toBe(['Economy Helper URI', 'Money Script Access Key', 'Gloebit Configuration', 'Search Engine URL', 'Search Register URL', 'Destinations Guide URL', 'Offline Messages Helper'])
            ->and(english($economy['path']['description']))->toBe(['The URL must be set in Robust configuration.'])
            ->and(english($search['path']['description'])[0])->toBe('URL of the search engine used by the viewer to provide search results (without arguments).')
            ->and(english($search['registerPath']['description']))->toBe(['Data service, used to register regions, objects or land for sale.', 'You can register to several search engines.'])
            ->and(count($guide['guideSource']['description']) + count(GridSettings::form('guide')['description']))->toBe(9)
            ->and(english(GridSettings::form('offline')['description']))->toBe(['Honor "Email me IMs when I\'m offline" viewer option.', 'OpenSimulator core offline messages module doesn\'t handle e-mail forwarding option available in the viewer settings.'])
            ->and(english(GridSettings::form('economy')['description'])[0])->toBe('Economy helpers are additional scripts needed if you implement economy on your grid (with real or fake currency).')
            ->and(count(GridSettings::form('search')['description']))->toBe(4)
            ->and(english(GridSettings::form('guide')['description'])[0])->toBe('Destinations guide is a Viewer 3 feature, providing a window with places suggestions.')
            ->and($offline['provide'])->not->toHaveKey('description');
    });

    test('with its conditions, its placeholders, what it requires', function () {
        $economy = page_field('economy');
        $guide = page_field('guide');
        $offline = page_field('offline');
        $search = page_field('search');

        expect($economy['currencyRate']['condition'])->toBe(['provide' => [true], 'economyModule' => ['BetaGridLikeMoneyModule', 'DTLNSLMoneyModule', 'Podex']])
            ->and($economy['currencyRate']['placeholder'])->toBe('10')
            ->and($economy['gloebitConfiguration'])->toMatchArray(['type' => 'note', 'condition' => ['provide' => [true], 'economyModule' => ['Gloebit']]])
            ->and($guide['guideSource']['required'] ?? false)->toBeTrue()
            ->and($offline['mailSender'])->toMatchArray(['type' => 'email', 'required' => true, 'placeholder' => 'no-reply@example.org'])
            ->and($search['eventsUrl']['placeholder'])->toBe('http://2do.directory/events/')
            ->and(page_field('assets')['assetsUrl'])->toMatchArray(['required' => true, 'condition' => ['provide' => [false]]]);
    });
});

describe('The ini lines of the pages', function () {
    test('are one block for each file at the end of the page, for the values chosen, never with a secret', function () {
        $machine = new Machine();
        $machine->write($machine->grid . '/Robust.HG.ini', file_get_contents($machine->grid . '/Robust.HG.ini') . "\n[Const]\n    WebURL = \"https://play.example.org\"\n[GridInfoService]\n    economy = \"https://play.example.org/helpers/\"\n");
        $machine->write($machine->grid . '/MoneyServer.ini', "[MoneyServer]\n    MoneyScriptAccessKey = \"k3y-s3cret\"\n");
        $machine->write($machine->grid . '/OpenSim.ini', "[Economy]\n    economymodule = DTLNSLMoneyModule\n");
        $router = new Router(new Api($machine->environment));
        $blocks = static fn(string $page): array => array_column($router->get("/grids/demo/settings/$page")->document['meta']['snippets'] ?? [], 'text', 'file');

        $economy = $blocks('economy');
        $search = $blocks('search');

        // One block for each file, the file named once; the lines of the module chosen only
        expect(array_keys($economy))->toBe(['Robust.HG.ini', 'OpenSim.ini', 'MoneyServer.ini', 'Helpers.ini'])
            ->and($economy['Robust.HG.ini'])->toContain("[GridInfoService]\n    economy = https://play.example.org/helpers/")
            ->and($economy['OpenSim.ini'])->toContain('economymodule = DTLNSLMoneyModule')->not->toContain('Gloebit')
            ->and($economy['MoneyServer.ini'])->toContain('EnableScriptSendMoney = true')->toContain('MoneyScriptAccessKey = (your key)')->toContain('[MySql]')
            ->and($economy['Helpers.ini'])->toContain('CurrencyRate = ')
            ->and(json_encode($economy))->not->toContain('k3y-s3cret')
            ->and(array_keys($search))->toBe(['OpenSim.ini', 'Helpers.ini'])
            ->and($search['OpenSim.ini'])->toContain("[Search]\n    Module = OpenSimSearch")->toContain('index_sims = true')->toContain('DATA_SRV_')
            ->and($blocks('guide'))->toHaveKeys(['Robust.HG.ini', 'Helpers.ini'])
            ->and($blocks('guide')['Robust.HG.ini'])->toContain('DestinationGuide = ')
            ->and($router->get('/grids/demo/settings/guide')->document['meta']['examples'][0]['text'])->toContain('Display Name|yourgrid.org:8002/Region Name')
            ->and($blocks('offline')['OpenSim.ini'])->toContain('OfflineMessageModule = OfflineMessageModule');
    });
});
