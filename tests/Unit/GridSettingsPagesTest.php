<?php

declare(strict_types=1);

/**
 * The settings pages of the services of a grid, as w4os-wp has them (Economy, Search Engine, Destinations Guide, Offline Messages,
 * Web Assets Server), made from the registry of the settings: each setting in its own file, what the options of the grid set shown
 * and not changed here, the secrets never said.
 */

use OpenSim\Api\Api;
use OpenSim\Api\Router;
use OpenSim\Config\ConfigReader;
use OpenSim\Config\Settings;
use OpenSim\I18n\Catalog;
use OpenSim\Models\GridSettings;
use OpenSim\Testing\Machine;

/** @return array{0:Machine,1:Closure(string,array<string,mixed>):OpenSim\Api\Response,2:Closure(string):OpenSim\Api\Response} */
function settings_pages(): array
{
    $machine = new Machine();
    $router = new Router(new Api($machine->environment));
    $patch = static fn(string $page, array $attributes) => $router->patch("/grids/demo/settings/$page", ['data' => ['type' => 'settings', 'id' => $page, 'attributes' => $attributes]]);
    $get = static fn(string $page) => $router->get("/grids/demo/settings/$page");

    return [$machine, $patch, $get];
}

describe('The settings pages of the services', function () {
    test('are the ones of w4os-wp, in its words, beside the avatar models', function () {
        $pages = ['economy', 'search', 'guide', 'offline', 'assets'];
        $fields = static fn(string $page): array => array_column(GridSettings::form($page)['fields'], 'name');
        $en = static fn(string $key): ?string => Catalog::MESSAGES[$key] ?? null;
        $fr = Catalog::messages('fr_FR');

        expect(GridSettings::groups())
            ->toBe(['models', ...$pages])
            ->and(array_map(static fn(string $page): ?string => $en(GridSettings::form($page)['title']), $pages))
            ->toBe(['Economy Settings', 'Search Engine Settings', 'Destinations Guide Settings', 'Offline Messages Settings', 'Web Assets Server'])
            ->and(array_map($fields, $pages))
            ->toBe([
                ['economyUrl', 'economyModule', 'moneyScriptKey', 'currencyRate', 'podexMessage', 'podexUrl'],
                ['searchUrl', 'dataServices', 'searchDatabase', 'eventsUrl'],
                ['destinationGuide', 'guideSource'],
                ['offlineModule', 'offlineMessageUrl', 'mailSender'],
                ['assetsUrl'],
            ])
            ->and(array_map(static fn(array $field): ?string => $en($field['label']), GridSettings::form('economy')['fields']))
            ->toBe(['Economy', 'Economy Module', 'Money script key', 'Currency Conversion Rate', 'Podex Redirect Message', 'Exchange Teleport URL'])
            // The translations of w4os-wp
            ->and([$fr['settings_economy'], $fr['setting_currency_rate'], $fr['setting_search_database'], $fr['setting_events_url'], $fr['setting_mail_sender'], $fr['setting_assets_url']])
            ->toBe(["Réglages de l'économie", 'Taux de conversion des devises', 'Base de données du moteur de recherche', "URL du serveur d'événements", "Adresse e-mail de l'expéditeur", 'URL du serveur de ressources web']);
    });

    test('describe their fields in the standard format: a database by its parts, a secret, a choice shown only for the module that needs it', function () {
        $economy = array_column(GridSettings::form('economy')['fields'], null, 'name');
        $search = array_column(GridSettings::form('search')['fields'], null, 'name');

        expect($search['searchDatabase'])
            ->toMatchArray(['type' => 'connection-string'])
            ->and(array_column($search['searchDatabase']['fields'], 'name'))
            ->toBe(['host', 'port', 'database', 'user', 'password'])
            ->and($economy['moneyScriptKey'])
            ->toMatchArray(['type' => 'secret', 'condition' => ['economyModule' => ['DTLNSLMoneyModule']]])
            ->and($economy['currencyRate'])
            ->toMatchArray(['type' => 'number', 'description' => 'setting_currency_rate_help'])
            ->and($economy['economyModule'])
            ->toMatchArray(['type' => 'select', 'readOnly' => true])
            ->and(array_keys($economy['economyModule']['options']))
            ->toBe(['BetaGridLikeMoneyModule', 'Gloebit', 'DTLNSLMoneyModule'])
            // Where each is kept stays the engine's
            ->and(array_merge(...array_map(static fn(string $page): array => GridSettings::form($page)['fields'], GridSettings::groups())))
            ->each->not->toHaveKeys(['section', 'key', 'instance', 'default']);
    });

    test('show what the options of the grid set, and do not change it here', function () {
        [$machine, $patch, $get] = settings_pages();
        $machine->write($machine->grid . '/OpenSim.ini', "[Search]\n    SearchURL = \"https://play.example.org/helpers/query.php\"\n[DataSnapshot]\n    DATA_SRV_demo = \"https://play.example.org/helpers/register.php\"\n    DATA_SRV_2do = \"https://2do.directory/helpers/register.php\"\n[Economy]\n    economymodule = Gloebit\n");

        $search = $get('search')->document['data']['attributes'];

        expect($search)
            ->toMatchArray(['searchUrl' => 'https://play.example.org/helpers/query.php', 'dataServices' => ['https://play.example.org/helpers/register.php', 'https://2do.directory/helpers/register.php'], 'eventsUrl' => 'https://2do.directory/events'])
            ->and($get('economy')->document['data']['attributes']['economyModule'])
            ->toBe('Gloebit')
            ->and([$patch('economy', ['economyModule' => 'DTLNSLMoneyModule'])->status, $patch('search', ['searchUrl' => 'https://x.example.org/q.php'])->status])
            ->toBe([400, 400])
            // A form sends back what it shows
            ->and($patch('search', $search + ['eventsUrl' => 'https://events.example.org'])->status)
            ->toBe(200);
    });

    test('write each setting in its own file: Robust, the helpers, the money server', function () {
        [$machine, $patch] = settings_pages();

        $done = $patch('economy', ['economyUrl' => 'https://play.example.org/helpers/', 'currencyRate' => '5.5', 'moneyScriptKey' => 'k3y-s3cret']);
        $robust = ConfigReader::load($machine->grid . '/Robust.HG.ini', expand: false);
        $helpers = ConfigReader::load($machine->grid . '/Helpers.ini', expand: false);
        $money = ConfigReader::load($machine->grid . '/MoneyServer.ini', expand: false);

        expect($done->status)
            ->toBe(200)
            ->and([$robust['GridInfoService']['economy'] ?? null, $helpers['Helpers']['CurrencyRate'] ?? null, $money['MoneyServer']['MoneyScriptAccessKey'] ?? null])
            ->toBe(['https://play.example.org/helpers/', '5.5', 'k3y-s3cret'])
            ->and($done->document['data']['attributes'])
            ->toMatchArray(['economyUrl' => 'https://play.example.org/helpers/', 'currencyRate' => 5.5, 'moneyScriptKey' => Settings::MASK])
            ->and(json_encode($done->document))
            ->not->toContain('k3y-s3cret');

        // The mask given back keeps the secret
        $patch('economy', ['moneyScriptKey' => Settings::MASK, 'podexMessage' => 'Go to the exchange']);

        expect(ConfigReader::load($machine->grid . '/MoneyServer.ini', expand: false)['MoneyServer']['MoneyScriptAccessKey'] ?? null)->toBe('k3y-s3cret');
    });

    test('keep a database by its parts, its password never said, kept when its mask comes back', function () {
        [$machine, $patch, $get] = settings_pages();

        $patch('search', ['searchDatabase' => ['host' => 'db.example.org', 'port' => 3307, 'database' => 'ossearch', 'user' => 'opensim', 'password' => 'pa55']]);
        $read = $get('search')->document['data']['attributes']['searchDatabase'];
        $patch('search', ['searchDatabase' => ['database' => 'search2'] + $read]);
        $written = ConfigReader::load($machine->grid . '/Helpers.ini', expand: false)['Helpers']['SearchConnectionString'] ?? null;

        expect($read)
            ->toMatchArray(['host' => 'db.example.org', 'port' => 3307, 'database' => 'ossearch', 'user' => 'opensim', 'password' => Settings::MASK])
            ->and($written)
            ->toBe('Data Source=db.example.org;Port=3307;Database=search2;User ID=opensim;Password=pa55;');
    });

    test('refuse what a field does not take', function () {
        [, $patch] = settings_pages();

        expect([
            $patch('economy', ['currencyRate' => 'cheap'])->status,
            $patch('offline', ['mailSender' => 'nobody'])->status,
            $patch('assets', ['assetsUrl' => 'not an address'])->status,
            $patch('guide', ['nothing' => 'x'])->status,
            $patch('assets', ['assetsUrl' => 'https://assets.example.org/a'])->status,
        ])->toBe([400, 400, 400, 400, 200]);
    });
});
