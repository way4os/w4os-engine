<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning\Web;

/**
 * Whether an address a grid gives is the kind of address its setting needs: the web search and the search page of the login a page
 * (never the search the simulators call, query.php, nor Robust itself), the profiles a page (never Robust, nor a script), the search
 * in the world the script it calls (query.php). A migration and an upgrade put the right one in the place of a wrong one, and say it;
 * a right one always stays.
 *
 *     PageAddress::invalid('GridInfoService', 'search', 'https://example.org/helper/query.php');   // why it is wrong, else null
 */
final class PageAddress
{
    /** The settings it checks, `section/key` in lower case: what each should be. */
    public const SETTINGS = [
        'gridinfoservice/search' => 'page',
        'loginservice/searchurl' => 'page',
        'gridinfoservice/web_profile_url' => 'page',
        'search/searchurl' => 'query',
    ];

    /**
     * Why an address is wrong for its setting, its constants replaced; null when it is right, empty, or not read (a constant left).
     *
     * @param list<string> $robust the addresses of Robust (public, private), without their last slash
     */
    public static function invalid(string $section, string $key, string $address, array $robust = []): ?string
    {
        $kind = self::SETTINGS[strtolower("$section/$key")] ?? null;
        $address = rtrim(trim($address, " \t\""), '/');
        if ($kind === null || $address === '' || str_contains($address, '${')) {
            return null;
        }
        $path = (string) parse_url(str_replace('[AGENT_NAME]', 'name', $address), PHP_URL_PATH);
        $query = preg_match('#/query\.php$#i', $path) === 1;
        if ($kind === 'query') {
            return $query ? null : 'the search in the world calls the script of the helpers (query.php, XML-RPC), not a page: theirs in its place';
        }
        if ($query) {
            return 'the search the simulators call (XML-RPC), not a page: the page of this install in its place';
        }
        foreach ($robust as $base) {
            $base = rtrim($base, '/');
            if ($base !== '' && !str_contains($base, '${') && (strcasecmp($address, $base) === 0 || stripos($address, "$base/") === 0)) {
                return 'the address of Robust, no page: the page of this install in its place';
            }
        }

        // A profile is a page of the avatar, never a script (the other pages may be pages of an older site in PHP)
        return strcasecmp($key, 'web_profile_url') === 0 && preg_match('#\.php$#i', $path) === 1
            ? 'a script, not a page: the page of this install in its place'
            : null;
    }
}
