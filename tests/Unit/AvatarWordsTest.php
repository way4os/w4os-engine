<?php

declare(strict_types=1);

/**
 * The people of the world are avatars, never users (a site has users that are not avatars): the texts of the catalog say so in
 * every language. The cards of a dashboard have their titles, the ones of the blocks of w4os-wp.
 */

use OpenSim\I18n\Catalog;

describe('The texts of the catalog', function () {
    test('call the people of the world avatars, never users', function () {
        // What is no person of the world: the user of a database, of the machine, the services of OpenSimulator by their names
        $apart = ['tip_web_search', 'db_user', 'setting_uas', 'setting_uas_help', 'service_user_account', 'service_grid_user', 'notice_import_read_access', 'notice_import_stop_source'];
        $users = static fn(array $texts, string $word): array => array_keys(array_filter(
            array_diff_key($texts, array_flip($apart)),
            static fn(string $text, string $key): bool => !str_starts_with($key, 'health_') && preg_match("/\\b$word/iu", $text) === 1,
            ARRAY_FILTER_USE_BOTH,
        ));

        expect($users(Catalog::MESSAGES, 'users?'))->toBe([])
            ->and($users(Catalog::messages('fr_FR', false), 'utilisat'))->toBe([])
            ->and(Catalog::MESSAGES['avatar_type_user'])->toBe('Resident');
    });

    test('give the titles of the cards of a dashboard, as the blocks of w4os-wp', function () {
        expect([Catalog::MESSAGES['dashboard_grid_info'], Catalog::MESSAGES['dashboard_grid_status'], Catalog::MESSAGES['total']])
            ->toBe(['Grid Info', 'Grid Status', 'Total'])
            ->and(Catalog::messages('fr_FR', false)['dashboard_grid_info'] ?? '')->toBe('Infos sur la grille');
    });
});
