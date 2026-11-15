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

    test('names the languages as its files do', function () {
        expect(array_map(Catalog::locale(...), ['fr', 'fr-FR', 'fr_FR.UTF-8', 'pt-br', 'pt', 'cy', 'en', 'en_US', 'es']))
            ->toBe(['fr_FR', 'fr_FR', 'fr_FR', 'pt_BR', 'pt_BR', 'cy_GB', 'en', 'en', 'es']);
    });

    test('ships its template as composer translations makes it', function () {
        expect(file_get_contents(Catalog::template()))->toBe(Catalog::pot());
    });

    test('ships every language, in the keys and English texts of the template', function () {
        foreach (Catalog::LOCALES as $locale) {
            $entries = PoFile::read((string) file_get_contents(Catalog::file($locale)));
            $current = array_filter($entries, fn($entry) => isset(Catalog::MESSAGES[$entry['context']]));
            expect(array_column($current, 'source', 'context'))->toBe(Catalog::MESSAGES, $locale);
        }
    });

    test('gives the texts of a language by key, the translated ones only', function () {
        $french = Catalog::messages('fr');

        expect($french)->toHaveKey('register_title')
            ->and(array_diff_key($french, Catalog::MESSAGES))->toBe([])
            ->and(count($french))->toBeLessThan(count(Catalog::MESSAGES))
            ->and(Catalog::messages('en-US'))->toBe(Catalog::MESSAGES)
            ->and(Catalog::messages('es'))->toBe([]);
    });

    test('gives the translations not checked yet when asked', function () {
        $checked = Catalog::messages('fr_FR');
        $all = Catalog::messages('fr_FR', fuzzy: true);

        expect(count($all))->toBeGreaterThan(count($checked))
            ->and(array_intersect_key($all, $checked))->toBe($checked)
            ->and(array_diff_key($all, Catalog::MESSAGES))->toBe([]);
    });

    test('keeps the placeholders of a text in its translations', function () {
        foreach (Catalog::LOCALES as $locale) {
            foreach (Catalog::messages($locale, fuzzy: true) as $key => $text) {
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
