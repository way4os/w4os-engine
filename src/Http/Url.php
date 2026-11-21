<?php

declare(strict_types=1);

namespace W4OS\Engine\Http;

/**
 * The address of a page of a site, built for the page being shown: the base of the site, the prefix of its language and the arguments
 * of the request that must carry over (the ones a viewer gives the guide or the search, a source...) kept, whatever the framework.
 *
 * What the engine cannot know of a site, the application gives it once (its context):
 *
 *     $url = new Url(
 *         base: 'https://example.org/site',   // the address of the site, its scheme and host ('' for links without them)
 *         language: 'fr',                     // the prefix of the language of the page, '' for none
 *         languages: ['en', 'fr', 'de'],      // the prefixes the site has: a target that has one keeps it
 *         path: '/guide',                     // the page shown, under the base and the language
 *         query: $_GET,                       // the arguments of the request
 *         keep: ['source', 'gk'],             // the ones that carry over to every link built
 *     );
 *     $url->build('/search', ['q' => 'plaza']);  // https://example.org/site/fr/search?source=...&q=plaza
 *     $url->build('?category=Music');            // the page shown, another category
 *
 * Grav gives its root URL and its active language, WordPress its home_url() with the prefix of Polylang or WPML, the helpers alone
 * their own base without a language.
 */
final class Url
{
    /** The address of the site, without its last slash. */
    private string $base;

    /** The path of the base on its host, without its last slash ('' at the root). */
    private string $basePath;

    /** The prefix of the language of the page, without slashes. */
    private string $language;

    /** @var list<string> the language prefixes of the site */
    private array $languages;

    /** The page shown, under the base and the language, from its slash. */
    private string $path;

    /** @var array<string,mixed> the arguments of the request that carry over */
    private array $kept;

    /**
     * @param array<string,mixed> $query the arguments of the request
     * @param list<string> $keep the names of the arguments that carry over to the links built
     * @param list<string> $languages the language prefixes of the site; the one of the page is one of them
     */
    public function __construct(
        string $base = '',
        string $language = '',
        array $languages = [],
        string $path = '/',
        array $query = [],
        array $keep = [],
    ) {
        $this->base = rtrim(trim($base), '/');
        $this->basePath = rtrim((string) (parse_url($this->base, PHP_URL_PATH) ?? ''), '/');
        $this->language = trim($language, '/ ');
        $this->languages = array_values(array_unique(array_filter(array_map(static fn(string $l): string => trim($l, '/ '), [...$languages, $this->language]))));
        $this->path = '/' . ltrim($path, '/');
        $this->kept = array_intersect_key($query, array_flip($keep));
    }

    /**
     * The address of a target for the page shown.
     *
     * A path of the site (`/search`, `search`, under the base or not) gets the base and the language of the page, unless it has a
     * language prefix of the site already; `?…` alone is the page shown; an address of another host is left as it is; one of the
     * site is read as its path. The arguments that carry over come first, then the target's own, then `$args`; an argument null or
     * '' in `$args` is taken out. The fragment is kept.
     *
     * @param array<string,mixed> $args arguments of this link
     */
    public function build(string $target, array $args = []): string
    {
        $parts = parse_url(trim($target));
        if ($parts === false) {
            return $target;
        }
        if (isset($parts['host']) && !$this->isSite($parts)) {
            return $args === [] ? $target : self::withQuery($target, $args);
        }
        $path = (string) ($parts['path'] ?? '');
        $path = $path === '' && !isset($parts['host']) ? $this->path : '/' . ltrim($path, '/');
        // Under the base already: the path of the site
        if ($this->basePath !== '' && ($path === $this->basePath || str_starts_with($path, $this->basePath . '/'))) {
            $path = '/' . ltrim(substr($path, strlen($this->basePath)), '/');
        }
        if ($this->language !== '' && $this->prefix($path) === null) {
            $path = '/' . $this->language . ($path === '/' ? '/' : $path);
        }
        parse_str((string) ($parts['query'] ?? ''), $own);
        $query = self::merged($this->kept, $own, $args);

        return $this->base . $path . ($query === [] ? '' : '?' . http_build_query($query)) . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    /**
     * An address with these arguments added (or replaced; null or '' takes one out), as add_query_args() of w4os-wp.
     *
     * @param array<string,mixed> $args
     */
    public static function withQuery(string $url, array $args): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        parse_str((string) ($parts['query'] ?? ''), $own);
        $query = self::merged($own, $args);
        $before = preg_replace('/[?#].*$/s', '', $url);

        return $before . ($query === [] ? '' : '?' . http_build_query($query)) . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    /** The language prefix a path starts with, of the ones of the site; null for none. */
    private function prefix(string $path): ?string
    {
        $first = explode('/', ltrim($path, '/'))[0];
        foreach ($this->languages as $language) {
            if (strcasecmp($first, $language) === 0) {
                return $language;
            }
        }

        return null;
    }

    /**
     * Whether the parts of an address are of the site: its host (and its port when the base says one).
     *
     * @param array<string,mixed> $parts
     */
    private function isSite(array $parts): bool
    {
        $site = parse_url($this->base);
        if (!is_array($site) || !isset($site['host'])) {
            return false;
        }

        return strcasecmp((string) $parts['host'], (string) $site['host']) === 0 && ($parts['port'] ?? null) === ($site['port'] ?? null);
    }

    /**
     * Arguments merged, the last over the first; null or '' takes one out.
     *
     * @param array<string,mixed> ...$sets
     * @return array<string,mixed>
     */
    private static function merged(array ...$sets): array
    {
        $query = [];
        foreach ($sets as $set) {
            foreach ($set as $name => $value) {
                if ($value === null || $value === '') {
                    unset($query[$name]);
                } else {
                    $query[$name] = $value;
                }
            }
        }

        return $query;
    }
}
