<?php

declare(strict_types=1);

/**
 * What each block and shortcode of a site shows, said in one short plain sentence, in every language: the help of the page of the
 * shortcodes of a site.
 */

use OpenSim\I18n\Catalog;

const BLOCK_TEXTS = [
    'block_grid_info_help', 'block_grid_status_help', 'block_popular_places_help', 'block_motd_help', 'block_destination_guide_help',
    'block_directory_info_help', 'block_search_help', 'block_profile_help', 'block_new_avatars_help',
    'shortcode_home_help', 'shortcode_guest_help', 'shortcode_register_help', 'shortcode_text_help', 'shortcode_uri_help', 'shortcode_slogan_help',
];

describe('What a block or a shortcode shows', function () {
    test('is one short sentence, in every language', function () {
        $long = array_filter(BLOCK_TEXTS, static fn(string $key): bool => !isset(Catalog::MESSAGES[$key]) || mb_strlen(Catalog::MESSAGES[$key]) > 90 || substr_count(Catalog::MESSAGES[$key], ':') > 0);
        $untranslated = [];
        foreach (['fr_FR', 'de_DE', 'it_IT', 'nl_NL', 'pt_BR', 'cy_GB'] as $locale) {
            foreach (BLOCK_TEXTS as $key) {
                ($m = Catalog::messages($locale, false)[$key] ?? '') === '' && ($untranslated[] = "$locale $key");
            }
        }

        expect($long)->toBe([])
            ->and($untranslated)->toBe([])
            // The words of w4os-wp where it has them
            ->and(Catalog::MESSAGES['block_grid_status_help'])->toBe('Online users, regions, etc.');
    });
});
