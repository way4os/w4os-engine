<?php

declare(strict_types=1);

/**
 * The language of a page a browser opens, one rule for the whole suite: `?lang=`, else the `w4os_lang` cookie, else the `lang`
 * cookie (then saved into `w4os_lang`), else `Accept-Language`, else English. A source counts only for a language the app has.
 */

use W4OS\Engine\I18n\LanguageChoice;

describe('The language of a page', function () {
    $has = static fn(string $tag): bool => in_array(strtolower(substr($tag, 0, 2)), ['en', 'fr', 'de', 'pt'], true);

    test('is the cookie of the suite over the browser', function () use ($has) {
        expect(LanguageChoice::pick(null, ['w4os_lang' => 'en'], 'fr-FR,fr;q=0.9', $has))->toBe(['en', null]);
    });

    test('is the one the address asks over the cookie', function () use ($has) {
        expect(LanguageChoice::pick('de', ['w4os_lang' => 'en'], 'fr-FR', $has))->toBe(['de', null]);
    });

    test('is the lang cookie of another app when the suite has none, saved into the cookie of the suite', function () use ($has) {
        expect(LanguageChoice::pick(null, ['lang' => 'de'], 'fr-FR', $has))->toBe(['de', 'de'])
            ->and(LanguageChoice::COOKIE)->toBe('w4os_lang');
    });

    test('ignores a language the app has not, from any source: the next one counts, nothing saved', function () use ($has) {
        expect(LanguageChoice::pick(null, ['lang' => 'xx'], 'fr-FR,fr;q=0.9', $has))->toBe(['fr-FR,fr;q=0.9', null])
            ->and(LanguageChoice::pick('xx', ['w4os_lang' => 'zz'], 'pt-BR', $has))->toBe(['pt-BR', null])
            ->and(LanguageChoice::pick(null, [], '', $has))->toBe(['en', null]);
    });

    test('says the cookie to save as a header: path /, about a year, SameSite=Lax', function () {
        expect(LanguageChoice::cookie('de'))->toBe('w4os_lang=de; Path=/; Max-Age=31536000; SameSite=Lax');
    });

    test('of the engine is one of its catalogs, or English', function () {
        expect(array_map(LanguageChoice::engineHas(...), ['fr', 'pt-BR', 'en', 'cy', 'xx', 'ja']))->toBe([true, true, true, true, false, false]);
    });
});
