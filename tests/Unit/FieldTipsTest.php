<?php

declare(strict_types=1);

/**
 * A settings page is understood quickly: its label, a short tip under it where the label is not enough, the details under the
 * field, what the whole page is about in its help (the sidebar). The tips are keys of the catalog, a few words, in every language.
 */

use OpenSim\I18n\Catalog;
use OpenSim\Models\GridSettings;

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
            ->and(count($tips))->toBeGreaterThan(20)
            ->and(Catalog::MESSAGES[$search['path']['tip']])->toBe('Used directly by the simulators')
            // What a whole page is about is its help, not the one of a field
            ->and(array_column(GridSettings::form('offline')['fields'], null, 'name')['provide'])->not->toHaveKey('description')
            ->and(GridSettings::form('offline')['description'] ?? [])->toContain('provide_offline_help');
    });
});
