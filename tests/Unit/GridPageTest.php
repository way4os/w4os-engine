<?php

declare(strict_types=1);

/**
 * The page of the grid (provided_grid() of ProvideChoicesTest, address_patch() of ProvideAddressesTest): what it is (its login URI,
 * its name, its nick), the ways to reach its Robust in one place (its address, its database, its console), the services that may be
 * in Robust instances of their own (the assets, the profiles: Use default), the page of a profile, what the numbers leave out.
 */

use OpenSim\Config\ConfigReader;
use OpenSim\Config\Settings;
use OpenSim\Data\OSPDO;
use OpenSim\Data\Stats;
use OpenSim\I18n\Catalog;
use OpenSim\Models\GridSettings;

/** A database of accounts: two people, a model, a console account without email; a visitor of another grid came this month. */
function excluded_stats_database(): OSPDO
{
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64), Email VARCHAR(64), active INT DEFAULT 1)');
    $pdo->exec("INSERT INTO UserAccounts VALUES ('u1', 'Ada', 'Love', 'ada@example.org', 1), ('u2', 'Bob', 'Root', 'bob@example.org', 1), ('m1', 'Default', 'Ruth', 'models@example.org', 1), ('s1', 'Service', 'Bot', '', 1)");
    $recent = STATS_NOW - 86400;
    $pdo->exec('CREATE TABLE GridUser (UserID VARCHAR(255), Login CHAR(16))');
    $pdo->exec("INSERT INTO GridUser VALUES ('u1', '$recent'), ('m1', '$recent'), ('s1', '$recent'), ('v1;http://other.example.org;Vis One', '$recent')");
    $pdo->exec('CREATE TABLE Presence (UserID VARCHAR(255), RegionID CHAR(36))');
    $pdo->exec("INSERT INTO Presence VALUES ('u1', 'r1'), ('m1', 'r1'), ('s1', 'r1'), ('v1;http://other.example.org;Vis One', 'r1')");
    $pdo->exec('CREATE TABLE regions (uuid VARCHAR(36), sizeX INT, sizeY INT)');
    $pdo->exec("INSERT INTO regions VALUES ('r1', 256, 256)");

    return $pdo;
}

describe('The page of the grid', function () {
    test('has what the grid is, the ways to reach its Robust in one place, its services apart, what the numbers leave out', function () {
        $form = GridSettings::form('grid');
        $fields = array_column($form['fields'], null, 'name');
        $en = static fn(string $key): string => Catalog::MESSAGES[$key] ?? $key;

        expect(array_map(static fn(array $field): array => [$field['name'], $field['group'] ?? null], $form['fields']))->toBe([
            ['loginUri', 'grid'], ['gridName', 'grid'], ['gridNick', 'grid'],
            ['gridServerUri', 'robust'], ['database', 'robust'], ['consoleUser', 'robust'], ['consolePass', 'robust'], ['consolePort', 'robust'],
            ['assetServerUri', 'services'], ['profileServiceUrl', 'services'],
            ['webProfileUrl', 'avatars'], ['excludeFromStats', 'avatars'],
        ])
            ->and(array_map(static fn(array $group): string => $en($group['label']), $form['groups']))->toBe(['Grid Info', 'Robust', 'Services', 'Avatars'])
            ->and(array_map(static fn(array $field): string => $en($field['label']), $form['fields']))->toBe([
                'Login URI', 'Grid Name', 'Grid Nickname', 'Robust URI', 'ROBUST Database', 'Console User', 'Console Password', 'Console Port',
                'Assets Service URI', 'Profile Service URI', 'Profile Page', 'Exclude from stats',
            ])
            ->and($fields['consolePass']['type'])->toBe('secret')
            // What Robust URI is, not the text of the grid service
            ->and($en($fields['gridServerUri']['description']))->toBe('The private address of Robust, which the simulators call for the services of the grid.')
            // Use default: a field of its own label, the switch saying Use default only
            ->and([$fields['assetServerUri']['fallback'], $fields['profileServiceUrl']['fallback']])->toBe(['db_use_default', 'db_use_default'])
            ->and([$fields['assetServerUri']['description'], $fields['profileServiceUrl']['description']])->toBe([['uri_default_robust'], ['uri_default_login']])
            ->and($fields['excludeFromStats'])->toMatchArray(['type' => 'checkboxes', 'default' => ['models', 'nomail']])
            ->and(array_map($en, $fields['excludeFromStats']['options']))->toBe(['models' => 'Models', 'nomail' => 'Accounts without mail address', 'hypergrid' => 'Hypergrid visitors'])
            ->and(Catalog::MESSAGES[$form['title']])->toBe('Grid Settings');
        foreach (Catalog::LOCALES as $locale) {
            expect(Catalog::messages($locale, false))->toHaveKeys([$form['title'], $form['menu'], ...array_column($form['groups'], 'label'), ...array_column($form['fields'], 'label'), 'uri_default_robust', 'uri_default_login', ...array_values($fields['excludeFromStats']['options'])]);
        }
    });

    test('changes the console of Robust, its secret masked; a service on the address of Robust by default', function () {
        [$machine, $router] = provided_grid();

        $saved = address_patch($router, 'grid', ['consoleUser' => 'admin', 'consolePass' => 'c0nsole-s3cret', 'consolePort' => 8404, 'assetServerUri' => 'http://assets.example.org:8003']);
        $robust = ConfigReader::load($machine->grid . '/Robust.HG.ini', expand: false);
        $assets = ConfigReader::load($machine->grid . '/OpenSim.ini', expand: false)['AssetService']['AssetServerURI'] ?? null;
        $default = address_patch($router, 'grid', ['assetServerUri' => ['useDefault' => true]]);

        expect($saved->status)->toBe(200)
            ->and([$robust['Network']['ConsoleUser'] ?? null, $robust['Network']['ConsolePass'] ?? null, $robust['Network']['ConsolePort'] ?? null])->toBe(['admin', 'c0nsole-s3cret', '8404'])
            ->and($saved->document['data']['attributes'])->toMatchArray(['consoleUser' => 'admin', 'consolePass' => Settings::MASK, 'consolePort' => 8404, 'assetServerUri' => 'http://assets.example.org:8003'])
            ->and($assets)->toBe('http://assets.example.org:8003')
            ->and(ConfigReader::load($machine->grid . '/OpenSim.ini', expand: false)['AssetService']['AssetServerURI'] ?? null)->toBeNull()
            ->and($default->status)->toBe(200)
            ->and(json_encode($saved->document))->not->toContain('c0nsole-s3cret');
    });

    test('leaves out of the numbers what it says: the models and the accounts without email by default, the visitors too when asked', function () {
        $db = excluded_stats_database();
        $count = static fn(array $exclude): array => array_intersect_key(
            Stats::counts(static fn() => $db, STATS_NOW, $exclude, ['FirstName = :model_pattern OR LastName = :model_pattern', ['model_pattern' => 'Default']]) ?? [],
            ['members' => 1, 'active_members' => 1, 'members_in_world' => 1, 'active_users' => 1, 'total_users' => 1],
        );

        expect($count([]))->toBe(['members' => 4, 'active_members' => 3, 'members_in_world' => 3, 'active_users' => 4, 'total_users' => 4])
            ->and($count(['models', 'nomail']))->toBe(['members' => 2, 'active_members' => 1, 'members_in_world' => 1, 'active_users' => 2, 'total_users' => 2])
            ->and($count(['models', 'nomail', 'hypergrid']))->toBe(['members' => 2, 'active_members' => 1, 'members_in_world' => 1, 'active_users' => 1, 'total_users' => 1]);
    });
});
