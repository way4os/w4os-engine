<?php

declare(strict_types=1);

/**
 * The readers of the settings read the registry (W4OS\Engine\Config\Settings), not lists of their own: the addresses of the services
 * (Service::address(), getAddresses) and the health of the settings (Health::grid(), Health::describe()) give what they gave
 * (tests/fixtures/registry-readers.json, made before they read it).
 */

require_once dirname(__DIR__) . '/fixtures/registry-readers.php';

describe('The readers of the settings', function () {
    test('give the addresses of the services and the health of the settings as they did', function () {
        $now = registry_readers();
        $was = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/registry-readers.json'), true);

        expect($now['services'])->toBe($was['services'])
            ->and($now['health'])->toBe($was['health'])
            ->and($now['names'])->toBe($was['names']);
    });

    test('have no list of their own', function () {
        $service = new ReflectionClass(W4OS\Engine\Models\Service::class);
        $catalog = $service->getConstant('CATALOG');

        expect($service->hasConstant('SETTING_NAMES'))->toBeFalse()
            ->and(array_filter($catalog, static fn(array $entry): bool => array_intersect_key($entry, ['setting' => 1, 'role' => 1, 'info' => 1, 'also' => 1]) !== []))
            ->toBe([])
            ->and(array_filter(['GRID_INFO', 'SETTINGS'], static fn(string $name): bool => defined(W4OS\Engine\Health\Health::class . "::$name")))
            ->toBe([]);
    });
});
