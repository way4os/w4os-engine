<?php

declare(strict_types=1);

/**
 * The settings an application keeps for the engine it bundles (a site, where the user of the web server cannot read the files of the
 * grid): read before the files of the grid (GridConfig::find()), and where the settings pages write for a grid that has no files
 * here, as the database access an application gives (DatabaseAccess).
 */

use OpenSim\Api\Api;
use OpenSim\Api\Router;
use OpenSim\Config\ArraySettings;
use OpenSim\Config\GridConfig;
use OpenSim\Config\Layout;
use OpenSim\Economy\CurrencySettings;
use OpenSim\Models\Grid;
use OpenSim\Runtime\Environment;

/** @return array{0:Environment,1:string} the environment of a site that keeps these settings, and its folder */
function site_with_settings(array|ArraySettings|null $settings): array
{
    $site = test_tmp() . '/site-settings-' . bin2hex(random_bytes(4));
    mkdir($site);

    return [Environment::system(new Layout(['EtcRoot' => "$site/etc", 'CacheRoot' => "$site/cache", 'StateRoot' => "$site/state"]), settings: $settings), $site];
}

describe('The settings an application keeps', function () {
    test('are read before the files of the grid: the key of the money a site keeps where its web server reads it', function () {
        [$environment] = site_with_settings(['MoneyServer' => ['MoneyScriptAccessKey' => 'site-key'], 'Helpers' => ['AssetsURL' => 'https://site.example.org/assets']]);
        $far = Grid::described($environment, ['nick' => 'far', 'name' => 'Far', 'remote' => true], new GridConfig([
            'MoneyServer' => ['MoneyServer' => ['MoneyScriptAccessKey' => 'file-key']],
            'Robust' => ['GridInfoService' => ['gridname' => 'Far']],
        ]));
        $bare = Grid::described($environment, ['nick' => 'bare', 'name' => 'Bare', 'remote' => true]);

        expect($far->config()->find('MoneyServer', 'MoneyScriptAccessKey'))->toBe('site-key')
            ->and($far->config()->find('GridInfoService', 'gridname'))->toBe('Far')
            ->and(CurrencySettings::fromConfig($far->config())->scriptKey)->toBe('site-key')
            // A grid the site gives no config still has the site's settings
            ->and($bare->config()?->find('Helpers', 'AssetsURL'))->toBe('https://site.example.org/assets');
    });

    test('are where the settings pages write for a grid without files, nothing written on the disk', function () {
        $kept = new ArraySettings();
        [$environment, $site] = site_with_settings($kept);
        $far = Grid::described($environment, ['nick' => 'far', 'name' => 'Far', 'remote' => true, 'baseHostname' => 'far.example.org', 'publicPort' => 8002]);
        $router = new Router(new Api($environment, grids: static fn(string $nick): ?Grid => $nick === 'far' ? $far : null));

        $write = $router->patch('/grids/far/settings/assets', ['data' => ['type' => 'settings', 'id' => 'assets', 'attributes' => ['provide' => false, 'assetsUrl' => 'https://assets.example.org/a']]]);
        $key = $router->patch('/grids/far/settings/economy', ['data' => ['type' => 'settings', 'id' => 'economy', 'attributes' => ['moneyScriptKey' => 'k3y']]]);
        $read = $router->get('/grids/far/settings/assets');

        expect([$write->status, $key->status])->toBe([200, 200])
            ->and($kept->all())->toBe(['Helpers' => ['AssetsURL' => 'https://assets.example.org/a'], 'MoneyServer' => ['MoneyScriptAccessKey' => 'k3y']])
            ->and($read->document['data']['attributes']['assetsUrl'])->toBe('https://assets.example.org/a')
            ->and($key->document['data']['attributes']['moneyScriptKey'])->toBe(OpenSim\Config\Settings::MASK)
            ->and(glob("$site/{,*/,*/*/}*.ini", GLOB_BRACE))->toBe([]);
    });

    test('given as an array, are read only: a settings page of a grid without files is then unavailable, with its reason', function () {
        [$environment] = site_with_settings(['Helpers' => ['AssetsURL' => 'https://site.example.org/assets']]);
        $far = Grid::described($environment, ['nick' => 'far', 'name' => 'Far', 'remote' => true]);
        $router = new Router(new Api($environment, grids: static fn(string $nick): ?Grid => $nick === 'far' ? $far : null));

        $write = $router->patch('/grids/far/settings/assets', ['data' => ['type' => 'settings', 'id' => 'assets', 'attributes' => ['provide' => false, 'assetsUrl' => 'https://other.example.org/a']]]);

        expect([$write->status, $write->document['code'] ?? null])->toBe([503, 'channel-unavailable'])
            ->and($far->access())->not->toContain('settings');
    });
});
