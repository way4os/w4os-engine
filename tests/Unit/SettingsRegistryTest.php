<?php

declare(strict_types=1);

/**
 * The registry of the settings (W4OS\Engine\Config\Settings): a table of conversion between the settings as the files of OpenSimulator
 * say them (instance > section > key) and the clean name of the engine and the API, with what each is (type, label, default...).
 * The values stay in their own files: the registry says where.
 */

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Config\Settings;
use W4OS\Engine\I18n\Catalog;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Models\Service;
use W4OS\Engine\Testing\Machine;

describe('The registry of the settings', function () {
    test('names each setting of the files twice: as OpenSimulator says it and as the engine does', function () {
        $entries = Settings::entries();
        $names = array_column($entries, 'name');

        expect(Settings::entry('opensim', 'Search', 'SearchURL'))
            ->toMatchArray(['instance' => 'opensim', 'section' => 'Search', 'key' => 'SearchURL', 'name' => 'searchUrl', 'type' => 'url', 'label' => 'setting_search_service', 'service' => 'query'])
            ->and(count($entries))
            ->toBeGreaterThan(50)
            ->and(array_unique($names))
            ->toBe($names)
            ->and(array_filter($names, static fn(string $name): bool => preg_match('/^[a-z][A-Za-z0-9]*$/', $name) !== 1))
            ->toBe([])
            ->and(array_diff(array_unique(array_column($entries, 'instance')), array_keys(Settings::INSTANCES)))
            ->toBe([])
            ->and(array_diff(array_unique(array_column($entries, 'type')), Settings::TYPES))
            ->toBe([])
            // Its words are the catalog's
            ->and(array_diff(array_column($entries, 'label'), array_keys(Catalog::MESSAGES)))
            ->toBe([])
            ->and(Settings::named('searchUrl'))
            ->toBe(Settings::entry('opensim', 'Search', 'SearchURL'));
    });

    test('finds a key whatever its case, and a key of a family by its prefix', function () {
        expect(Settings::entry('robust', 'gridinfoservice', 'GRIDNAME')['name'] ?? null)
            ->toBe('gridName')
            ->and(Settings::entry('opensim', 'DataSnapshot', 'DATA_SRV_demo')['name'] ?? null)
            ->toBe('dataServices')
            ->and(Settings::entry('opensim', 'Search', 'Nothing'))
            ->toBeNull()
            ->and(Settings::named('nothing'))
            ->toBeNull();
    });

    test('has the setting of every address the engine shows, but the connectors of Robust', function () {
        $grid = Grid::find((new Machine())->environment, 'demo');
        $missing = [];
        foreach (Service::keys() as $key) {
            $address = (new Service($grid->environment(), $grid, $key))->address();
            if (!in_array($address['section'], [null, 'ServiceList'], true) && Settings::forService($key) === null) {
                $missing[] = $key;
            }
        }

        expect($missing)->toBe([])
            ->and(array_column(Settings::ofService('search'), 'key'))
            ->toBe(['search', 'SearchURL'])
            ->and(Settings::serviceLabel('landtool'))
            ->toBe('service_landtool')
            ->and(Settings::serviceLabel('login'))
            ->toBe('setting_login');
    });

    test('says how the health asks each setting it shows', function () {
        $asked = array_filter(Settings::entries(), static fn(array $entry): bool => isset($entry['health']));

        expect(array_diff(array_unique(array_column($asked, 'health')), ['address', 'login-search', 'xmlrpc', 'register', 'offline', 'secret']))
            ->toBe([])
            ->and(array_column(array_filter($asked, static fn(array $entry): bool => $entry['section'] !== 'GridInfoService'), 'key'))
            ->toBe(['DestinationGuide', 'MessageUrl', 'SearchURL', 'SearchURL', 'DATA_SRV_', 'OfflineMessageURL', 'MoneyScriptAccessKey']);
    });

    test('says the file of a setting for a grid: its Robust.HG.ini when it has one', function () {
        $dir = test_tmp() . '/settings-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        file_put_contents("$dir/Robust.HG.ini", "[Const]\n BaseURL = \"http://play.example.org\"\n");

        expect(Settings::where('gridName', GridConfig::fromFolder($dir)))
            ->toBe(['file' => 'Robust.HG.ini', 'section' => 'GridInfoService', 'key' => 'gridname'])
            ->and(Settings::where('gridName'))
            ->toBe(['file' => 'Robust.ini', 'section' => 'GridInfoService', 'key' => 'gridname'])
            ->and(Settings::where('assetsUrl'))
            ->toBe(['file' => 'Helpers.ini', 'section' => 'Helpers', 'key' => 'AssetsURL'])
            ->and(Settings::where('moneyScriptKey'))
            ->toBe(['file' => 'MoneyServer.ini', 'section' => 'MoneyServer', 'key' => 'MoneyScriptAccessKey']);
    });

    test('marks the secrets', function () {
        $secrets = array_column(array_filter(Settings::entries(), static fn(array $entry): bool => $entry['secret'] ?? false), 'name');

        expect($secrets)->toContain('database', 'moneyScriptKey', 'searchDatabase')
            ->and(array_filter(Settings::entries(), static fn(array $entry): bool => $entry['type'] === 'connection-string' && !($entry['secret'] ?? false)))
            ->toBe([]);
    });
});

describe('A connection string', function () {
    test('reads as its parts, the others kept as they are', function () {
        $type = Settings::type('connection-string');

        expect($type->decode('Data Source=db.example.org;Port=3307;Database=robust;User ID=opensim;Password=s3cret;Old Guids=true;'))
            ->toBe(['host' => 'db.example.org', 'port' => 3307, 'database' => 'robust', 'user' => 'opensim', 'password' => 's3cret', 'options' => ['Old Guids' => 'true']])
            ->and($type->decode('"Data Source=db:3308;Database=search;User ID=os;Password=x;"'))
            ->toMatchArray(['host' => 'db', 'port' => 3308, 'database' => 'search'])
            ->and($type->decode(''))
            ->toBeNull();
    });

    test('is written in the form of dotNet, back from its parts', function () {
        $type = Settings::type('connection-string');
        $raw = 'Data Source=db.example.org;Port=3307;Database=robust;User ID=opensim;Password=s3cret;Old Guids=true;';

        expect($type->encode($type->decode($raw)))
            ->toBe($raw)
            ->and($type->encode(['host' => 'localhost', 'database' => 'os', 'user' => 'u', 'password' => 'p']))
            ->toBe('Data Source=localhost;Database=os;User ID=u;Password=p;');
    });

    test('never says its password: masked when read, kept when the mask comes back', function () {
        $type = Settings::type('connection-string');
        $raw = 'Data Source=db;Database=robust;User ID=opensim;Password=s3cret;';
        $masked = $type->mask($type->decode($raw));

        expect($masked['password'])
            ->toBe(Settings::MASK)
            ->and(json_encode($masked))
            ->not->toContain('s3cret')
            ->and($type->encode(['database' => 'other'] + $masked, $raw))
            ->toBe('Data Source=db;Database=other;User ID=opensim;Password=s3cret;')
            ->and($type->mask($type->decode('Data Source=db;Database=robust;User ID=opensim;'))['password'])
            ->toBe('')
            ->and(array_column($type->fields(), 'name'))
            ->toBe(['host', 'port', 'database', 'user', 'password']);
    });
});
