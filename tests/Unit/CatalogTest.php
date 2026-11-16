<?php

declare(strict_types=1);

use OpenSim\Api\Problem;
use OpenSim\I18n\Catalog;
use OpenSim\I18n\PoFile;

describe('the catalog of the texts of the sites', function () {
    test('has a text for every problem code of the API', function () {
        expect(Catalog::missingCodes())->toBe([])
            ->and(Catalog::errorKey('name-taken'))->toBe('error_name_taken')
            ->and(Catalog::error('email-unconfirmed'))->toBe(Catalog::MESSAGES['error_email_unconfirmed'])
            ->and(Catalog::error('no-such-code'))->toBe(Catalog::MESSAGES['error_invalid_request']);
    });

    test('has a text for every status, every role, every want and every skill of an avatar', function () {
        $keys = [
            ...array_map(Catalog::statusKey(...), OpenSim\Models\Avatar::STATUSES),
            ...array_map(Catalog::roleKey(...), array_keys(OpenSim\Models\Avatar::ROLES)),
            ...array_column([...OpenSim\Models\Avatar::profileFlags()['wants'], ...OpenSim\Models\Avatar::profileFlags()['skills']], 'key'),
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
            ->and(Catalog::plural('fr', 'profile_name', 3))
            ->toBe('Nom')
            ->and(Catalog::messages('fr')['profile_age_days'])
            ->toBe('%d jours')
            ->and(Catalog::plurals('fr')['profile_age_days'])
            ->toBe(['%d jour', '%d jours'])
            ->and(array_keys(Catalog::PLURAL_FORMS))
            ->toBe(['en', ...Catalog::LOCALES]);
    });

    test('gives the texts of a language by key, the translated ones only', function () {
        $french = Catalog::messages('fr');

        expect($french)->toHaveKey('register_title')
            ->and(array_diff_key($french, Catalog::MESSAGES))->toBe([])
            ->and(count($french))->toBeLessThan(count(Catalog::MESSAGES))
            ->and(Catalog::messages('en-US'))->toBe(Catalog::MESSAGES)
            ->and(Catalog::messages('es'))->toBe([]);
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
