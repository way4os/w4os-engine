<?php

declare(strict_types=1);

/**
 * The module of the economy is chosen on its page, as w4os-wp chooses its provider (provided_grid() of ProvideChoicesTest): the
 * free economy, the generic money server, Gloebit, or Podex, which is the generic money server and the settings of Podex the
 * helpers use (`[Helpers] CurrencyProvider = podex`). Each shows its own settings.
 */

use OpenSim\Config\Settings;
use OpenSim\Economy\CurrencySettings;
use OpenSim\Models\Grid;

/** The PATCH of the economy page. */
function economy_patch(OpenSim\Api\Router $router, array $attributes): OpenSim\Api\Response
{
    return $router->patch('/grids/demo/settings/economy', ['data' => ['type' => 'settings', 'id' => 'economy', 'attributes' => $attributes]]);
}

describe('The module of the economy', function () {
    test('is chosen among the free economy, the generic money server, Gloebit and Podex, each with its own settings', function () {
        $fields = provided_fields('economy');

        expect($fields['economyModule'])->not->toHaveKey('readOnly')
            ->and(array_keys($fields['economyModule']['options']))->toBe(['BetaGridLikeMoneyModule', 'DTLNSLMoneyModule', 'Gloebit', 'Podex'])
            ->and(OpenSim\I18n\Catalog::MESSAGES[$fields['economyModule']['options']['DTLNSLMoneyModule']])->toBe('Generic MoneyServer, for fake money or alternate providers.')
            ->and($fields['podexUrl']['condition']['economyModule'])->toBe(['Podex'])
            ->and($fields['moneyScriptKey']['condition']['economyModule'])->toBe(['DTLNSLMoneyModule', 'Podex'])
            ->and($fields['moneyDatabase']['condition']['economyModule'])->toBe(['DTLNSLMoneyModule', 'Podex'])
            ->and($fields['currencyRate']['condition']['economyModule'])->toBe(['BetaGridLikeMoneyModule', 'DTLNSLMoneyModule', 'Podex'])
            ->and(array_map(static fn(string $name): array => $fields[$name]['condition']['economyModule'], ['gloebitEnvironment', 'gloebitKey', 'gloebitSecret', 'gloebitOwnerName', 'gloebitOwnerEmail', 'gloebitDatabase']))
            ->toBe(array_fill(0, 6, ['Gloebit']))
            ->and($fields['gloebitKey']['type'])->toBe('secret');
    });

    test('Podex is the generic money server, the helpers told it is Podex', function () {
        [$machine, $router] = provided_grid();

        $podex = economy_patch($router, ['economyModule' => 'Podex', 'podexUrl' => 'secondlife://Exchange/128/128/22']);
        $opensim = file_get_contents($machine->grid . '/OpenSim.ini');
        $helpers = file_get_contents($machine->grid . '/Helpers.ini');
        $settings = CurrencySettings::fromConfig(Grid::find($machine->environment, 'demo')->config());
        $generic = economy_patch($router, ['economyModule' => 'DTLNSLMoneyModule']);

        expect($podex->status)->toBe(200)
            ->and($podex->document['data']['attributes']['economyModule'])->toBe('Podex')
            ->and($opensim)->toContain('economymodule = DTLNSLMoneyModule')
            ->and($helpers)->toContain('CurrencyProvider = podex')
            ->and([$settings->provider, $settings->useMoneyServer, $settings->podexUrl])->toBe(['podex', true, 'secondlife://Exchange/128/128/22'])
            ->and($generic->document['data']['attributes']['economyModule'])->toBe('DTLNSLMoneyModule')
            ->and(CurrencySettings::fromConfig(Grid::find($machine->environment, 'demo')->config())->provider)->toBe('');
    });

    test('Gloebit has its settings set here, its secrets never given back', function () {
        [$machine, $router] = provided_grid();

        $done = economy_patch($router, ['economyModule' => 'Gloebit', 'gloebitEnvironment' => 'production', 'gloebitKey' => 'app-key', 'gloebitSecret' => 'app-secret', 'gloebitOwnerName' => 'Banker Name', 'gloebitOwnerEmail' => 'banker@example.org']);
        $opensim = file_get_contents($machine->grid . '/OpenSim.ini');

        expect($done->status)->toBe(200)
            ->and($done->document['data']['attributes'])->toMatchArray(['economyModule' => 'Gloebit', 'gloebitEnvironment' => 'production', 'gloebitKey' => Settings::MASK, 'gloebitSecret' => Settings::MASK, 'gloebitOwnerEmail' => 'banker@example.org'])
            ->and($opensim)->toContain('economymodule = Gloebit')->toContain('GLBKey = app-key')->toContain('GLBOwnerEmail = "banker@example.org"');
    });
});
