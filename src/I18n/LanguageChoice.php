<?php

declare(strict_types=1);

namespace W4OS\Engine\I18n;

/**
 * The language of a page a browser opens, one rule for every app of the suite (the engine, the helpers, the bridges): the one the
 * address asks (`?lang=`), else the cookie of the suite (`w4os_lang`), else the `lang` cookie another app of the host may set (then
 * saved into the cookie of the suite), else the languages of the browser (`Accept-Language`), else English. A source counts only for
 * a language the app has; otherwise the next one. A viewer sends no cookie: its pages follow its `Accept-Language`.
 */
final class LanguageChoice
{
    /** The cookie every app of the suite reads, and the site writes when the visitor chooses (path /, a language code: fr, pt-BR). */
    public const COOKIE = 'w4os_lang';

    /** The cookie of another app of the host, taken when the suite has none. */
    public const OTHER = 'lang';

    /** How long the cookie of the suite is kept when an app saves it: about a year. */
    public const LIFETIME = 31536000;

    /**
     * The languages to use, in the form the app reads them (a code, or the Accept-Language header), and the code to save into the
     * cookie of the suite (the `lang` cookie taken while the suite had none), null for nothing to save.
     *
     * @param array<string,mixed> $cookies the cookies of the request
     * @param callable(string):bool $has whether the app has a language (a code: fr, pt-BR)
     * @return array{0:string,1:?string}
     */
    public static function pick(?string $asked, array $cookies, string $accept, callable $has): array
    {
        foreach ([$asked, $cookies[self::COOKIE] ?? null] as $code) {
            if (self::valid($code) && $has((string) $code)) {
                return [(string) $code, null];
            }
        }
        $other = $cookies[self::OTHER] ?? null;
        if (self::valid($other) && $has((string) $other)) {
            return [(string) $other, (string) $other];
        }

        return [trim($accept) !== '' ? $accept : 'en', null];
    }

    /** Whether the engine has a language (fr, pt-BR): English, or one of its catalogs (Catalog::LOCALES). */
    public static function engineHas(string $code): bool
    {
        $language = strtolower(substr(str_replace('_', '-', $code), 0, 2));

        return $language === 'en' || in_array($language, array_map(static fn(string $locale): string => substr($locale, 0, 2), Catalog::LOCALES), true);
    }

    /** The header that saves a code into the cookie of the suite. */
    public static function cookie(string $code): string
    {
        return self::COOKIE . '=' . rawurlencode($code) . '; Path=/; Max-Age=' . self::LIFETIME . '; SameSite=Lax';
    }

    /** Whether a value is a language code (fr, pt-BR, zh-Hant), never a header nor a text. */
    private static function valid(mixed $code): bool
    {
        return is_string($code) && preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/', $code) === 1;
    }
}
