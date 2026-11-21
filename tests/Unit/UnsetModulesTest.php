<?php

declare(strict_types=1);

/**
 * A module a grid does not name is the one OpenSimulator loads then: no `economymodule` is the free economy
 * (BetaGridLikeMoneyModule), no `Module` of [Search] is OpenSimSearch. The pages read it so, and their conditions match it.
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Models\GridSettings;
use W4OS\Engine\Testing\Machine;

describe('A module the grid does not name', function () {
    test('is the default of OpenSimulator, and the fields it shows are shown', function () {
        $environment = (new Machine())->environment;
        $free = Grid::described($environment, ['nick' => 'free', 'name' => 'Free', 'remote' => true], new GridConfig([
            'OpenSim' => ['Economy' => ['; economymodule' => 'DTLNSLMoneyModule']],
            'Robust' => ['GridInfoService' => ['gridname' => 'Free']],
        ]));
        $router = new Router(new Api($environment, grids: static fn(string $nick): ?Grid => $nick === 'free' ? $free : null));

        $economy = $router->get('/grids/free/settings/economy')->document['data']['attributes'];
        $fields = array_column(GridSettings::form('economy')['fields'], null, 'name');

        expect($economy['economyModule'])->toBe('BetaGridLikeMoneyModule')
            ->and($fields['currencyRate']['condition']['economyModule'])->toContain($economy['economyModule'])
            // The key of the money server is a setting of the money server only
            ->and($fields['moneyScriptKey']['condition']['economyModule'])->toBe(['DTLNSLMoneyModule', 'Podex'])
            ->and(W4OS\Engine\Config\Settings::named('searchModule')['default'])->toBe('OpenSimSearch');
    });
});

describe('A database the grid does not say', function () {
    test('is unavailable with its reason as a notice', function () {
        $environment = (new Machine())->environment;
        $bare = Grid::described($environment, ['nick' => 'bare', 'name' => 'Bare', 'remote' => true], new GridConfig([
            'Robust' => ['GridInfoService' => ['gridname' => 'Bare']],
        ]));
        $router = new Router(new Api($environment, grids: static fn(string $nick): ?Grid => $nick === 'bare' ? $bare : null));

        $avatars = $router->get('/grids/bare/avatars');

        expect([$avatars->status, $avatars->document['reason']['code'] ?? null])->toBe([503, 'database-unsaid']);
    });
});
