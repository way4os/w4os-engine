<?php

declare(strict_types=1);

/**
 * A settings page is understood quickly: its label, a short tip under it where the label is not enough, the details under the
 * field, what the whole page is about in its help (the sidebar). The tips are keys of the catalog, a few words, in every language.
 */

use W4OS\Engine\I18n\Catalog;
use W4OS\Engine\Models\GridSettings;

describe('The fields of the settings pages', function () {
    test('have a short tip where the label is not enough, in every language, the details apart', function () {
        $tips = [];
        $long = [];
        foreach (GridSettings::groups() as $page) {
            foreach (GridSettings::form($page)['fields'] as $field) {
                if (!isset($field['tip'])) {
                    continue;
                }
                $tips["$page.{$field['name']}"] = $field['tip'];
                mb_strlen(Catalog::MESSAGES[$field['tip']] ?? str_repeat('x', 99)) > 45 && ($long[] = "$page.{$field['name']}");
                foreach (['fr_FR', 'de_DE', 'it_IT', 'nl_NL', 'pt_BR', 'cy_GB'] as $locale) {
                    (Catalog::messages($locale, false)[$field['tip']] ?? '') === '' && ($long[] = "$locale {$field['tip']}");
                }
            }
        }
        $search = array_column(GridSettings::form('search')['fields'], null, 'name');

        expect($long)->toBe([])
            ->and(count($tips))->toBeGreaterThan(15)
            ->and(Catalog::MESSAGES[$search['path']['tip']])->toBe('Used directly by the simulators')
            // What a whole page is about is its help, not the one of a field
            ->and(array_column(GridSettings::form('offline')['fields'], null, 'name')['provide'])->not->toHaveKey('description')
            ->and(GridSettings::form('offline')['description'] ?? [])->toContain('provide_offline_help');
    });

    test('never say again what the label says: no tip for a database, nor for a field whose label is enough', function () {
        $tipped = [];
        foreach (GridSettings::groups() as $page) {
            foreach (GridSettings::form($page)['fields'] as $field) {
                isset($field['tip']) && ($tipped[] = "$page.{$field['name']}");
                $field['type'] === 'connection-string' && isset($field['tip']) && ($tipped[] = "database $page.{$field['name']}");
            }
        }
        $default = static fn(string $key): string => Catalog::MESSAGES[$key];

        expect(array_intersect($tipped, ['models.list', 'economy.economyModule', 'economy.moneyDatabase', 'economy.gloebitDatabase', 'search.searchDatabase', 'search.registerPath', 'search.registers', 'search.eventsUrl', 'offline.mailSender', 'offline.offlineDatabase']))->toBe([])
            ->and(preg_grep('/^database /', $tipped))->toBe([])
            ->and(array_filter(array_keys(Catalog::MESSAGES), static fn(string $key): bool => str_starts_with($key, 'tip_') && str_ends_with($key, '_database')))->toBe([])
            // What the default is, in a few words, the switch saying Use default already
            ->and(array_map($default, ['db_default_main', 'db_default_simulator', 'uri_default_robust', 'uri_default_login']))
            ->toBe(['Default: the main database of the grid.', 'Default: the database of the simulator.', 'Default: the address of Robust.', 'Default: the login URI.']);
    });
});
