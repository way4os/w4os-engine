<?php

namespace W4OS\Engine\Provisioning\Web;

/**
 * The web services of a grid, the scripts and the pages of w4os-helpers that answer them, and the address each has by default:
 * `/helpers/<script>`, `/helpers/<page>`. The grid says otherwise in the official settings (the addresses of Robust.ini and OpenSim.ini: this is
 * where the helpers read them), `<base>` being the path of its economy, which the viewers complete with the name of the script.
 *
 * The helpers route the addresses of the grid that are on this site (see `index.php` of the helpers).
 */
final class Services
{
    /** The service and the script of the helpers that answers it. */
    public const SCRIPTS = [
        'query' => 'query.php',
        'register' => 'register.php',
        'offline' => 'offline.php',
        'currency' => 'currency.php',
        'motd' => 'motd.php',
        'landtool' => 'landtool.php',
        'parser' => 'parser.php',
        'eventsparser' => 'eventsparser.php',
        'textgen' => 'textgen.php',
        'directory_info' => 'directory_info.php',
    ];

    /**
     * The pages of the helpers and their default path under the base of the helpers (the home page is the base itself): the splash
     * page of the viewer, the profiles, the search, the destinations guide, the status, the directory, the health. A grid gives
     * another address to a page in its config (see SitePages): the site serves it then.
     */
    public const PAGES = [
        'home' => '/',
        'welcome' => '/welcome',
        'profile' => '/profile',
        'search' => '/search',
        'guide' => '/guide',
        'grid_status' => '/grid_status',
        'directory' => '/directory_info',
        'health' => '/health',
    ];

    /**
     * @param string $base the path the helpers are served under (`/helpers`), the one of the economy
     */
    public function __construct(private string $base = '/helpers') {}

    /** A path as the web server has it: a slash first, none at the end (the root is `/`). */
    public static function normalize(string $path): string
    {
        $path = '/' . trim(trim($path), '/');

        return $path;
    }

    /**
     * The path of an address a Robust setting gives, with its constants as they are written (`${Const|WebURL}/helpers`); the root (`/`)
     * for an address without a path (`https://example.com`).
     */
    public static function pathOf(string $address): string
    {
        $address = (string) preg_replace('/^\$\{Const\|[^}]+\}/', '', trim($address));
        $parts = parse_url($address);
        if (!is_array($parts)) {
            return self::normalize($address);
        }

        // An address of a site without a path is its root, never the address itself
        return self::normalize((string) ($parts['path'] ?? (isset($parts['host']) ? '/' : $address)));
    }

    /** The base path. */
    public function base(): string
    {
        return self::normalize($this->base);
    }

    /** The default path of a service or a page, under the base. */
    public function path(string $service): string
    {
        $base = rtrim($this->base(), '/');
        if (isset(self::PAGES[$service])) {
            return self::PAGES[$service] === '/' ? ($base === '' ? '/' : $base) : $base . self::PAGES[$service];
        }

        return $base . '/' . (self::SCRIPTS[$service] ?? $service);
    }

    /** The scheme, the host and the port of an address (`https://example.org:8443`), empty when it is not one. */
    public static function originOf(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        return ($parts['scheme'] ?? 'http') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Whether an address is under a base: the same scheme, host and port, and a path that is the one of the base or below it (a
     * base at the root of a site has every address of the site). This is what says that the helpers serve a page (Grid::servedByHelpers()).
     */
    public static function isUnder(string $url, string $base): bool
    {
        $url = parse_url(trim($url));
        $base = parse_url(trim($base));
        if (!is_array($url) || !is_array($base) || empty($url['host']) || empty($base['host'])) {
            return false;
        }
        $origin = static fn(array $parts): string => strtolower(($parts['scheme'] ?? 'http') . '://' . $parts['host']) .
            ':' .
            ($parts['port'] ?? (strtolower($parts['scheme'] ?? 'http') === 'https' ? 443 : 80));
        if ($origin($url) !== $origin($base)) {
            return false;
        }
        $path = rtrim(rawurldecode($url['path'] ?? ''), '/');
        $root = rtrim($base['path'] ?? '', '/');

        return $root === '' || $path === $root || str_starts_with($path, "$root/");
    }

    /** The address of a service on a web site. */
    public function url(string $webUrl, string $service): string
    {
        return rtrim($webUrl, '/') . $this->path($service);
    }
}
