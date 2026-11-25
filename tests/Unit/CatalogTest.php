<?php

declare(strict_types=1);

use W4OS\Engine\Api\Problem;
use W4OS\Engine\I18n\Catalog;
use W4OS\Engine\I18n\PoFile;

describe('the catalog of the texts of the sites', function () {
    test('has a text for every problem code of the API', function () {
        expect(Catalog::missingCodes())->toBe([])
            ->and(Catalog::errorKey('name-taken'))->toBe('error_name_taken')
            ->and(Catalog::error('email-unconfirmed'))->toBe(Catalog::MESSAGES['error_email_unconfirmed'])
            ->and(Catalog::error('no-such-code'))->toBe(Catalog::MESSAGES['error_invalid_request']);
    });

    test('has the texts of the toolbar of a list: its filters, how many are on, a label before its value', function () {
        expect([Catalog::plural('fr_FR', 'filters_active', 1), Catalog::plural('fr_FR', 'filters_active', 2), Catalog::plural('en_US', 'filters_active', 2)])
            ->toBe(['%d filtre actif', '%d filtres actifs', '%d active filters'])
            // French puts a narrow no-break space before its colon
            ->and(Catalog::messages('fr_FR')['label_value'])
            ->toBe("%1\$s\u{202F}: %2\$s")
            ->and([Catalog::MESSAGES['label_value'], Catalog::messages('fr_FR')['filters'], Catalog::messages('fr_FR')['tab_overview']])
            ->toBe(['%1$s: %2$s', 'Filtres', 'Aperçu']);
    });

    test('has a text for every status, every role, every want and every skill of an avatar', function () {
        $keys = [
            ...array_map(Catalog::statusKey(...), W4OS\Engine\Models\Avatar::STATUSES),
            ...array_map(Catalog::roleKey(...), array_keys(W4OS\Engine\Models\Avatar::ROLES)),
            ...array_column([...W4OS\Engine\Models\Avatar::profileFlags()['wants'], ...W4OS\Engine\Models\Avatar::profileFlags()['skills']], 'key'),
        ];

        expect(array_diff($keys, array_keys(Catalog::MESSAGES)))
            ->toBe([])
            ->and(Catalog::roleKey('customer-service'))
            ->toBe('role_customer_service');
    });

    test('names the languages as its files do', function () {
        expect(array_map(Catalog::locale(...), ['fr', 'fr-FR', 'fr_FR.UTF-8', 'pt-br', 'pt', 'cy', 'en', 'en_US', 'es']))
            ->toBe(['fr_FR', 'fr_FR', 'fr_FR', 'pt_BR', 'pt_BR', 'cy_GB', 'en', 'en', 'es']);
    });

    test('ships its template as composer translations makes it', function () {
        expect(file_get_contents(Catalog::template()))->toBe(Catalog::pot());
    });

    test('ships every language, in the keys and English texts of the template, and how it picks the form of a number', function () {
        // A text that changes with a number has its singular as msgid, the text of MESSAGES as msgid_plural
        $english = array_replace(Catalog::MESSAGES, Catalog::SINGULARS);
        foreach (Catalog::LOCALES as $locale) {
            $content = (string) file_get_contents(Catalog::file($locale));
            // A long line of the header is cut in several strings
            $header = str_replace("\"\n\"", '', $content);
            $entries = PoFile::read($content);
            $current = array_filter($entries, fn($entry) => isset(Catalog::MESSAGES[$entry['context']]));
            $plurals = array_filter($current, fn($entry) => isset($entry['plural']));
            expect(array_column($current, 'source', 'context'))
                ->toBe($english, $locale)
                ->and(array_column($plurals, 'plural', 'context'))
                ->toBe(array_intersect_key(Catalog::MESSAGES, Catalog::SINGULARS), $locale)
                ->and($header)
                ->toContain('Plural-Forms: ' . Catalog::PLURAL_FORMS[$locale] . '\\n"');
        }
    });

    test('picks the form of a number as each language does, the English one when a language has none', function () {
        $days = static fn(string $locale, int $n): string => Catalog::plural($locale, 'profile_age_days', $n);

        expect([$days('en', 1), $days('en', 0), $days('en', 3)])
            ->toBe(['%d day', '%d days', '%d days'])
            ->and([$days('fr', 0), $days('fr', 1), $days('fr', 2)])
            ->toBe(['%d jour', '%d jour', '%d jours'])
            ->and([$days('de', 0), $days('de', 1), $days('de', 2)])
            ->toBe(['%d Tage', '%d Tag', '%d Tage'])
            ->and([$days('cy', 1), $days('cy', 2), $days('cy', 3)])
            ->toBe(['%d diwrnod', '%d ddiwrnod', '%d diwrnod'])
            ->and($days('es', 1))
            ->toBe('%d day')
            ->and([Catalog::plural('fr', 'regions_count', 1), Catalog::plural('fr', 'regions_count', 5), Catalog::plural('cy', 'regions_count', 2)])
            ->toBe(['%d région', '%d régions', '%d ranbarth'])
            ->and([Catalog::plural('en', 'avatars_count', 1), Catalog::plural('de', 'avatars_count', 2), Catalog::plural('pt_BR', 'avatars_count', 2)])
            ->toBe(['%d avatar', '%d Avatare', '%d avatares'])
            ->and(Catalog::plural('fr', 'profile_name', 3))
            ->toBe('Nom')
            ->and(Catalog::messages('fr')['profile_age_days'])
            ->toBe('%d jours')
            ->and(Catalog::plurals('fr')['profile_age_days'])
            ->toBe(['%d jour', '%d jours'])
            ->and(array_keys(Catalog::PLURAL_FORMS))
            ->toBe(['en', ...Catalog::LOCALES]);
    });

    test('names the search of the grid as a page and an entry of a menu by a noun, its buttons by a verb', function () {
        expect(Catalog::messages('fr')['search_title'])->toBe('Recherche')
            ->and(Catalog::messages('fr')['search'])->toBe('Chercher')
            ->and(Catalog::messages('de')['search_title'])->toBe('Suche')->and(Catalog::messages('de')['search'])->toBe('Suchen');
    });

    test('names a teleport of a menu by what it does, then its format, never a link to copy', function () {
        $teleports = ['link_menu_grid', 'link_menu_hop', 'link_menu_apptp', 'link_menu_local'];
        $french = Catalog::messages('fr');

        expect(array_map(fn ($key) => Catalog::MESSAGES[$key], $teleports))->toBe(['Teleport (secondlife://)', 'Teleport (hop://)', 'Teleport (app/teleport)', 'Teleport (this grid)'])
            ->and(array_map(fn ($key) => $french[$key], $teleports))->each->toStartWith('Se téléporter (')
            ->and(Catalog::MESSAGES['link_menu_copy'])->toBe('Copy the address');
        foreach (['en', 'de', 'fr', 'cy', 'it', 'nl', 'pt-BR'] as $language) {
            $texts = $language === 'en' ? Catalog::MESSAGES : Catalog::messages($language);
            foreach ($teleports as $key) {
                expect($texts[$key])->toEndWith(')')->not->toMatch('/\\b(link|lien|collegamento|koppeling|ligação|dolen)\\b/iu');
            }
        }
    });

    test('gives the texts of a language by key, the translated ones only', function () {
        $french = Catalog::messages('fr');

        expect($french)->toHaveKey('register_title')
            ->and(array_diff_key($french, Catalog::MESSAGES))->toBe([])
            ->and(count($french))->toBeLessThanOrEqual(count(Catalog::MESSAGES))
            ->and(Catalog::messages('en-US'))->toBe(Catalog::MESSAGES)
            ->and(Catalog::messages('es'))->toBe([]);
    });

    test('translates every text in every language, none fuzzy', function () {
        foreach (Catalog::LOCALES as $locale) {
            expect(array_keys(array_diff_key(Catalog::MESSAGES, Catalog::messages($locale, fuzzy: false))))->toBe([], $locale);
        }
    });

    test('translates the words that do translate: a text left as the English one is a name, a format or the same word there', function () {
        // The texts each language writes as the English one, reviewed: '*' for all of them (names, files, formats)
        $same = [
        '*' => [
            'column_cpu', 'column_prims_short', 'column_slug', 'column_uri', 'column_url', 'connection_config', 'connection_mysql',
            'connection_regions', 'connection_screen', 'connection_sqlite', 'economy_help_modules', 'economy_module_gloebit',
            'economy_module_podex', 'filter_hypergrid', 'flux_menu', 'group_robust', 'health_helpers', 'setting_gatekeeper',
            'unit_dimensions', 'unit_km2', 'unit_percent',
        ],
        'cy_GB' => [
            'column_grid', 'column_map', 'map_title', 'column_prims', 'label_value', 'role_model', 'settings_menu_grid', 'unit_mb',
            // The web search: the words of the viewers, the same in this language
            'web_search_partner',
        ],
        'de_DE' => [
            // The word for a visitor of the welcome of a page
            'motd_avatar_visitor',
            'asset_animation', 'asset_link', 'asset_material', 'asset_mesh', 'asset_sound', 'asset_sound_wav', 'column_estates',
            'column_flags', 'column_grid', 'column_name', 'column_port', 'column_presence_short', 'column_prims', 'column_region',
            'column_server', 'column_status', 'count_avatars_short', 'count_prims', 'dashboard_title', 'db_host', 'db_port',
            'filter_simulator', 'gloebit_sandbox', 'group_simulator', 'health_ok', 'kind_oar', 'label_value', 'menu_admin',
            'models_pattern', 'offline', 'online', 'partner', 'port_web', 'profile_name', 'profile_tab_feed',
            'profile_tab_inworld', 'profile_tab_web', 'region_flag_hyperlink', 'region_flag_region_online', 'setting_welcome',
            'settings_menu_grid', 'status', 'unit_mb',
            // The web search: the words of the viewers, the same in this language
            'classified_category_shopping', 'place_category_shopping', 'web_search_events', 'web_search_name', 'web_search_rating_adult', 'web_search_region',
            'web_search_traffic',
            'web_search_online', 'web_search_partner', 'web_search_status',
        ],
        'fr_FR' => [
            // The word for a visitor of the welcome of a page
            'motd_avatar_visitor',
            // The units of a duration, the same symbols
            'unit_hours', 'unit_hours_minutes', 'unit_minutes',
            'actions', 'asset_animation', 'asset_image_jpeg', 'asset_image_tga', 'asset_mesh', 'asset_script', 'asset_texture',
            'asset_texture_tga', 'avatars_count', 'avatars_menu', 'avatars_title', 'column_avatars', 'column_date', 'column_port',
            'column_presence_short', 'column_prims', 'column_scripts', 'column_scripts_short', 'column_service', 'column_type',
            'connection_console', 'count_avatars_short', 'count_prims', 'count_scripts', 'db_port', 'filter_local',
            'flux_post', 'gloebit_production', 'group_avatars', 'group_console', 'group_services', 'health_ok', 'health_site', 'manage_title',
            'menu_admin', 'parcel_scripts', 'port_public', 'port_web', 'profile_tab_web', 'role_liaison', 'role_maintenance',
            'setting_guide_source', 'setting_welcome', 'shortcode_options', 'skill_architecture', 'total',
            // The web search: the words of the viewers, the same in this language
            'classified_category_service', 'event_category_discussion', 'event_category_sports', 'place_category_arts', 'place_category_shopping', 'web_search_date',
            'web_search_day', 'web_search_description', 'web_search_land_type',
        ],
        'it_IT' => [
            // The word for a visitor of the welcome of a page
            'motd_avatar_visitor',
            // The units of a duration, the same symbols
            'unit_hours', 'unit_hours_minutes', 'unit_minutes',
            'asset_mesh', 'asset_script', 'asset_texture', 'asset_texture_tga', 'column_file', 'column_presence_short',
            'column_scripts_short', 'column_server', 'connection_console', 'count_avatars_short', 'db_password', 'email',
            'gloebit_sandbox', 'group_console', 'group_database', 'health_ok', 'kind_backup', 'label_value', 'menu_admin', 'no',
            'offline', 'online', 'partner', 'password', 'password_title', 'port_web', 'profile_tab_account', 'profile_tab_feed',
            'profile_tab_web', 'region_flag_hyperlink', 'region_flag_region_online', 'setting_directory', 'setting_welcome',
            'skill_scripting', 'unit_mb',
            // The web search: the words of the viewers, the same in this language
            'classified_category_shopping', 'place_category_shopping',
            'web_search_no',
            'web_search_account', 'web_search_online', 'web_search_partner',
        ],
        'nl_NL' => [
            // The word for a visitor of the welcome of a page
            'motd_avatar_visitor',
            // The unit of minutes, the same symbol
            'unit_minutes',
            'asset_landmark', 'asset_link', 'asset_mesh', 'asset_object', 'asset_script', 'avatars_count', 'avatars_menu',
            'avatars_title', 'column_avatars', 'column_grid', 'column_presence_short', 'column_prims', 'column_scripts',
            'column_scripts_short', 'column_server', 'column_status', 'column_type', 'connection_console', 'count_avatars_short',
            'count_prims', 'count_scripts', 'dashboard_title', 'filter_simulator', 'filters', 'gloebit_sandbox', 'god',
            'group_avatars', 'group_console', 'group_database', 'group_simulator', 'health_ok', 'health_site', 'label_value',
            'manage_title', 'offline', 'online', 'parcel_scripts', 'partner', 'port_web', 'profile_tab_account',
            'profile_tab_feed', 'profile_tab_inworld', 'profile_tab_web', 'region_flag_hyperlink', 'region_flag_region_online',
            'role_god', 'role_model', 'setting_welcome', 'settings_menu_grid', 'simulators_count', 'simulators_title',
            'skill_scripting', 'start_login', 'status', 'unit_mb',
            // The web search: the words of the viewers, the same in this language
            'web_search_account', 'web_search_online', 'web_search_partner', 'web_search_status',
        ],
        'pt_BR' => [
            // The word for a visitor of the welcome of a page
            'motd_avatar_visitor',
            // The units of a duration, the same symbols
            'unit_hours', 'unit_hours_minutes', 'unit_minutes',
            'asset_link', 'asset_material', 'asset_mesh', 'asset_script', 'column_grid', 'column_presence_short', 'column_prims',
            'column_scripts', 'column_scripts_short', 'column_status', 'connection_console', 'count_avatars_short', 'count_prims',
            'count_scripts', 'email', 'filter_local', 'gloebit_sandbox', 'group_console', 'health_ok', 'health_site',
            'kind_backup', 'label_value', 'offline', 'online', 'parcel_scripts', 'port_web', 'profile_tab_feed', 'profile_tab_web',
            'region_flag_region_online', 'settings_menu_grid', 'skill_scripting', 'status', 'total', 'unit_mb',
            // The web search: the words of the viewers, the same in this language
            'web_search_name', 'web_search_traffic',
            'web_search_status',
        ],
        ];
        foreach (Catalog::LOCALES as $locale) {
            $kept = array_keys(array_filter(
                Catalog::messages($locale),
                fn(string $text, string $key) => mb_strtolower($text) === mb_strtolower(Catalog::MESSAGES[$key]),
                ARRAY_FILTER_USE_BOTH,
            ));
            sort($kept);
            $listed = [...$same['*'], ...$same[$locale]];
            sort($listed);
            expect($kept)->toBe($listed, $locale);
        }
    });

    test('says the technical words with their meaning, not another one', function () {
        // Words of another meaning met in the translations: a harbour for a port, a lattice for the grid, a watcher or a slide
        // projector for the viewer, an assistant (a person) for the helpers, the web services the viewers and the simulators call
        $wrong = [
            'cy_GB' => '/porthladd|cynorthwy|\\bg?wyl(iwr|wyr)\\b|golygydd/iu',
            'de_DE' => '/\\b(Hafen|Raster)\\b|Helfer|Assistent|Betrachter/u',
            'fr_FR' => '/\\bassistants?\\b|visionneuse|visualiseur/iu',
            'it_IT' => '/\\bporto\\b|\\b(aiutant|assistent|visualizzator)\\w*/iu',
            'nl_NL' => '/\\b(haven|raster|rooster|kijker)\\b|assistent/iu',
            'pt_BR' => '/\\b(porto|grade|visualizador|ajudantes?|assistentes?|auxiliar(es)?)\\b/iu',
        ];
        foreach ($wrong as $locale => $pattern) {
            $found = array_filter(Catalog::messages($locale), fn(string $text) => preg_match($pattern, preg_replace('/\\{\\w+\\}/', '', $text)) === 1);
            expect($found)->toBe([], $locale);
        }
    });

    test('speaks to a visitor informally, a meeting place, not a bank: tu in French, ti in Welsh', function () {
        // The formal register: vous and its possessives; chi, eich and its contractions ('ch: i'ch, o'ch, a'ch), gennych, ydych
        $formal = [
            'fr_FR' => '/\\b(vous|votre|vos)\\b/iu',
            'cy_GB' => "/\\b(chi|chwi|eich|gennych|ydych|atoch)\\b|['’]ch\\b/iu",
        ];
        foreach ($formal as $locale => $pattern) {
            $found = array_filter(Catalog::messages($locale), fn(string $text) => preg_match($pattern, $text) === 1);
            expect($found)->toBe([], $locale);
        }
    });

    test('says viewer and helpers in every language, as OpenSimulator does', function () {
        foreach (['viewer', 'helper'] as $term) {
            foreach (Catalog::LOCALES as $locale) {
                $renamed = array_filter(
                    Catalog::messages($locale),
                    fn(string $text, string $key) => stripos(Catalog::MESSAGES[$key], $term) !== false && stripos($text, $term) === false,
                    ARRAY_FILTER_USE_BOTH,
                );
                expect($renamed)->toBe([], "$locale $term");
            }
        }
    });

    test('keeps the placeholders of a text in its translations', function () {
        foreach (Catalog::LOCALES as $locale) {
            foreach (Catalog::messages($locale) as $key => $text) {
                preg_match_all('/%(\d+\$)?s/', Catalog::MESSAGES[$key], $source);
                preg_match_all('/%(\d+\$)?s/', $text, $translated);
                sort($source[0]);
                sort($translated[0]);
                expect($translated[0])->toBe($source[0], "$locale $key");
            }
        }
    });
});

describe('a gettext file', function () {
    test('reads the context, the text, the translation and whether it is fuzzy', function () {
        $po = <<<'PO'
            # A comment
            #, fuzzy
            msgid ""
            msgstr ""
            "Language: fr_FR\n"

            #: somewhere
            msgctxt "greeting"
            msgid "Hello"
            msgstr "Bonjour"

            #, fuzzy, php-format
            msgctxt "named"
            msgid "Hello %s"
            msgstr "Salut %s"

            msgctxt "long"
            msgid ""
            "A \"quoted\" text "
            "on two lines"
            msgstr ""
            "Un texte \"cité\" "
            "sur deux lignes"
            msgctxt "glued"
            msgid "No blank line before"
            msgstr ""
            PO;

        expect(PoFile::read($po))->toBe([
            ['context' => 'greeting', 'source' => 'Hello', 'translation' => 'Bonjour', 'fuzzy' => false],
            ['context' => 'named', 'source' => 'Hello %s', 'translation' => 'Salut %s', 'fuzzy' => true],
            ['context' => 'long', 'source' => 'A "quoted" text on two lines', 'translation' => 'Un texte "cité" sur deux lignes', 'fuzzy' => false],
            ['context' => 'glued', 'source' => 'No blank line before', 'translation' => '', 'fuzzy' => false],
        ]);
    });

    test('reads the forms of a text that changes with a number', function () {
        $po = <<<'PO'
            msgctxt "days"
            msgid "%d day"
            msgid_plural "%d days"
            msgstr[0] "%d jour"
            msgstr[1] "%d jours"
            msgctxt "next"
            msgid "Next"
            msgstr "Suivant"
            PO;

        expect(PoFile::read($po))->toBe([
            ['context' => 'days', 'source' => '%d day', 'translation' => '%d jour', 'fuzzy' => false, 'plural' => '%d days', 'forms' => ['%d jour', '%d jours']],
            ['context' => 'next', 'source' => 'Next', 'translation' => 'Suivant', 'fuzzy' => false],
        ])
            ->and(PoFile::read(PoFile::plural('days', '%d day', '%d days', ['', ''], ['php-format'])))
            ->toBe([['context' => 'days', 'source' => '%d day', 'translation' => '', 'fuzzy' => false, 'plural' => '%d days', 'forms' => ['', '']]]);
    });

    test('writes an entry that it reads back', function () {
        $entry = PoFile::entry('key', "Two\nlines with a \"quote\" and a \\", 'Deux', ['php-format']);

        expect(PoFile::read($entry))->toBe([
            ['context' => 'key', 'source' => "Two\nlines with a \"quote\" and a \\", 'translation' => 'Deux', 'fuzzy' => false],
        ]);
    });

    test('is one msgfmt takes', function () {
        foreach ([Catalog::template(), ...array_map(Catalog::file(...), Catalog::LOCALES)] as $file) {
            exec('msgfmt --check -o /dev/null ' . escapeshellarg($file) . ' 2>&1', $output, $status);
            expect($status)->toBe(0, implode("\n", $output));
        }
    })->skip(fn() => trim((string) shell_exec('command -v msgfmt')) === '', 'msgfmt is not installed (gettext)');
});

describe('The translations of the catalog', function () {
    test('are in their language: no line in another script, none left in English where the English is a sentence', function () {
        $foreign = '/[\x{0400}-\x{04FF}\x{0590}-\x{06FF}\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{AC00}-\x{D7AF}]/u';
        $wrong = [];
        foreach (W4OS\Engine\I18n\Catalog::LOCALES as $locale) {
            foreach (W4OS\Engine\I18n\Catalog::messages($locale) as $key => $text) {
                $english = W4OS\Engine\I18n\Catalog::MESSAGES[$key] ?? '';
                preg_match($foreign, $text) === 1 && ($wrong[] = "$locale $key: another script");
                str_contains($text, "\n") && !str_contains($english, "\n") && ($wrong[] = "$locale $key: a line more");
                $text === $english && str_word_count($english) > 6 && !preg_match('/OpenSim|Gloebit|Podex|DTL|github|\.com/', $english)
                    && ($wrong[] = "$locale $key: in English");
            }
        }

        expect($wrong)->toBe([]);
    });
});
