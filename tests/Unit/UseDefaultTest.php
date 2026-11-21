<?php

declare(strict_types=1);

/**
 * A field that may use the default (`fallback`): a field of its own, its label the name of what it is (Economy Database, Search
 * Database), its switch only "Use default", what the default is said in its description. provided_fields() of ProvideChoicesTest.
 */

use W4OS\Engine\I18n\Catalog;

describe('A field that may use the default', function () {
    test('has its own label, a switch that says Use default only, and what the default is in its description', function () {
        $fields = [...array_values(array_intersect_key(provided_fields('economy'), ['moneyDatabase' => 1, 'gloebitDatabase' => 1])), provided_fields('search')['searchDatabase'], provided_fields('offline')['offlineDatabase']];

        expect(array_unique(array_column($fields, 'fallback')))->toBe(['db_use_default'])
            ->and(Catalog::MESSAGES['db_use_default'])->toBe('Use default')
            ->and(array_map(static fn(array $field): string => Catalog::MESSAGES[$field['label']], $fields))->toBe(['Economy Database', 'Economy Database', 'OS Search Database', 'Offline Messages Database'])
            ->and(array_map(static fn(array $field): array => $field['description'] ?? [], $fields))
            ->toBe([['db_default_main'], ['db_default_simulator'], ['db_default_main'], ['db_default_main']]);
        foreach (Catalog::LOCALES as $locale) {
            expect(Catalog::messages($locale, false))->toHaveKeys(['db_use_default', 'db_default_main', 'db_default_simulator']);
        }
    });
});
