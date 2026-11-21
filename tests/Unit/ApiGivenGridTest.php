<?php

declare(strict_types=1);

/**
 * The API of a site that has the engine in its own folder: the grids are the ones the site gives (their settings, their databases
 * over the network), nothing read from the machine (no w4os.conf, no folder of a grid).
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Config\Layout;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Runtime\Environment;

describe('The API of a site', function () {
    test('serves the grids the site gives, read from nowhere on the machine', function () {
        $site = sys_get_temp_dir() . '/site-' . bin2hex(random_bytes(4));
        mkdir($site);
        $environment = Environment::system(new Layout(['EtcRoot' => "$site/etc", 'CacheRoot' => "$site/cache", 'TasksRoot' => "$site/tasks", 'StateRoot' => "$site/state"]));
        $far = Grid::described($environment, ['nick' => 'far', 'name' => 'Far', 'remote' => true, 'baseHostname' => 'far.example.org', 'publicPort' => 8002]);
        $config = new GridConfig(['Robust' => ['DatabaseService' => ['ConnectionString' => 'Data Source=db.example.org;Database=robust;User ID=site;Password=pw;']]]);
        avatars_in(avatar_database());
        $router = new Router(new Api($environment, config: static fn(Grid $grid): ?GridConfig => $config, grids: static fn(string $nick): ?Grid => $nick === 'far' ? $far : null));

        $avatars = $router->get('/grids/far/avatars');
        $unknown = $router->get('/grids/near/avatars');

        expect($avatars->status)
            ->toBe(200)
            ->and(array_column(array_column($avatars->document['data'], 'attributes'), 'name'))
            ->toContain('Ada Love')
            ->and($unknown->status)
            ->toBe(404)
            // Nothing of the machine was made: no folder of the site but the ones the engine wrote in
            ->and(is_dir("$site/etc"))
            ->toBeFalse();
    });
});

describe('A grid described by a site', function () {
    test('reads its settings in the config the site gives, as a grid on the machine reads its files', function () {
        $site = sys_get_temp_dir() . '/site-' . bin2hex(random_bytes(4));
        $environment = Environment::system(new Layout(['EtcRoot' => "$site/etc", 'CacheRoot' => "$site/cache"]));
        $config = new GridConfig([
            'Robust' => [
                'Const' => ['WebURL' => 'https://far.example.org'],
                'GridInfoService' => ['economy' => 'https://far.example.org/tools', 'search' => 'https://far.example.org/search'],
            ],
            'OpenSim' => ['UserProfiles' => ['AllowUserProfileWebURLs' => 'false']],
            'Helpers' => ['Helpers' => ['LogoURL' => 'https://far.example.org/logo.png']],
        ]);
        $far = Grid::described($environment, ['nick' => 'far', 'name' => 'Far', 'remote' => true], $config);
        $bare = Grid::described($environment, ['nick' => 'bare', 'name' => 'Bare', 'remote' => true]);

        expect([$far->logoUrl(), $far->helpersUrl(), $far->helpersUrlSource(), $far->searchUrl(), $far->economy(), $far->allowUserProfileWebUrls()])
            ->toBe(['https://far.example.org/logo.png', 'https://far.example.org/tools', 'economy', 'https://far.example.org/search', 'https://far.example.org/tools', false])
            ->and($far->config())
            ->toBe($config)
            // A grid given no config has none: nothing read from the machine
            ->and([$bare->config(), $bare->logoUrl(), $bare->helpersUrl(), $bare->allowUserProfileWebUrls()])
            ->toBe([null, null, null, true])
            ->and(is_dir("$site/etc"))
            ->toBeFalse();
    });
});
