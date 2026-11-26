<?php

namespace W4OS\Engine\Provisioning\Web;

use W4OS\Engine\Provisioning\Presets;

/**
 * What a web server needs to serve the site of a grid: the document root of the site, where what exists is
 * served as it is and everything else goes to `index.php`, the router of the helpers (see the helpers), PHP run
 * for it, the grid named to the helpers (OPENSIM_GRID).
 *
 * Caddy, nginx and Apache are written, to include in the site of the grid, not to replace its config. The files are presets
 * (`webserver-caddy`, `webserver-nginx`, `webserver-apache`, see OptionTemplate): this only gives them the site of the grid.
 */
final class Snippets
{
    public const SERVERS = ['caddy', 'nginx', 'apache'];

    /** Where the helpers are installed. */
    public const WEBROOT = '/usr/share/w4os-helpers';

    /** The entry point of the helpers when their preset does not say it (webroot-helpers). */
    private const HELPERS_PUBLIC = self::WEBROOT . '/public';

    /** The folder of the entry point of the engine API (public/index.php), where this engine is installed. */
    public static function engineRoot(): string
    {
        return dirname(__DIR__, 3) . '/public';
    }

    /** The folder of the entry point of the helpers, as their preset says it (webroot-helpers, docroot). */
    public static function helpersRoot(): string
    {
        $docroot = (new Presets())->options()['webroot-helpers']?->variables()['docroot'] ?? '';

        return $docroot !== '' ? $docroot : self::HELPERS_PUBLIC;
    }

    /**
     * Where the site answers, from its web URL, as the files of a web server say it: the host (none: any), the scheme, the port when
     * it is not the usual one of its scheme, the address of the site as Caddy writes it, the port a server listens on.
     *
     * @return array<string,string>
     */
    public static function siteVariables(string $webUrl): array
    {
        $parts = parse_url(trim($webUrl)) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $port = $port === ($scheme === 'http' ? 80 : 443) ? null : $port;
        $host = (string) ($parts['host'] ?? '');
        $scheme = $scheme === 'http' ? 'http' : 'https';
        $explicit = $port !== null ? ":$port" : '';

        return [
            'web_url' => trim($webUrl),
            'web_host' => $host,
            'web_scheme' => $scheme,
            'web_port' => $port === null ? '' : (string) $port,
            'site_address' => match (true) {
                $host === '' => ':' . ($port ?? 80),
                $scheme === 'http' => "http://$host$explicit",
                default => $host . $explicit,
            },
            'listen_port' => (string) ($scheme === 'http' ? $port ?? 80 : 80),
        ];
    }

    /** Where the engine API for the sites of this machine is reached: its socket (its port on 127.0.0.1 is the grid's, Grid::apiPort()). */
    public const API_SOCKET = '/run/php/w4os-api.sock';

    /** The files of the API for the sites of this machine, by web server (the preset `engine-api`). */
    private const API_FILES = [
        'caddy' => 'api/{{grid_nick}}-api.caddyfile',
        'nginx' => 'api/{{grid_nick}}-api-nginx.conf',
        'apache' => 'api/{{grid_nick}}-api-apache.conf',
    ];

    /**
     * The PHP-FPM pool that runs the engine API as the user of the instances, whose files the grids are: the web server only reaches
     * its socket (listen.group), a site calls it with a key, as `engine: remote`.
     *
     * @param string $webGroup the group of the web server (www-data, caddy...)
     */
    public static function pool(string $systemUser, string $webGroup = 'www-data', string $socket = self::API_SOCKET): string
    {
        return self::poolText('w4os-api', $systemUser, $systemUser, $webGroup, $socket, sprintf(
            'The engine API of the grids of this machine, run as the user of their instances (%s), whose files they are: what a site changes is done by that user, never by the web server, which only reaches this socket; the site calls it with a key (opensim api-key create).',
            $systemUser,
        ));
    }

    /** Where the helpers of the grids of this machine are reached once their pool is installed (helpersPool()). */
    public const HELPERS_SOCKET = '/run/php/w4os-helpers.sock';

    /**
     * The PHP-FPM pool that runs the helpers of the grids of this machine as the web user and its group (WebUser, WebGroup), the
     * group that reads the settings of the grids: whatever user and groups the pool of the host runs with, the helpers read them.
     *
     * @param string $listenGroup the group of the web server that reaches the socket (www-data, caddy...)
     */
    public static function helpersPool(string $webUser, string $webGroup, string $listenGroup = 'www-data'): string
    {
        return self::poolText('w4os-helpers', $webUser, $webGroup, $listenGroup, self::HELPERS_SOCKET, sprintf(
            'The helpers of the grids of this machine, run as %s of the group %s, which reads the settings of the grids, whatever the pool of the host runs as.',
            $webUser,
            $webGroup,
        ));
    }

    /** Where the Quick Start website of this machine is reached (sitePool()). */
    public const SITE_SOCKET = '/run/php/w4os-site.sock';

    /** The PHP-FPM pool of the Quick Start website, as the web user whose files it are: the PHP and the account of the host stay out of it. */
    public static function sitePool(string $webUser, string $webGroup, string $listenGroup = 'www-data'): string
    {
        return self::poolText('w4os-site', $webUser, $webGroup, $listenGroup, self::SITE_SOCKET, sprintf(
            'The Quick Start website of this machine, run as %s of the group %s, whose files it are, whatever the web server runs as.',
            $webUser,
            $webGroup,
        ));
    }

    /** A pool of the suite: its name, the account it runs as, the group of the web server that reaches its socket. */
    private static function poolText(string $name, string $user, string $group, string $listenGroup, string $socket, string $about): string
    {
        return self::apiPreset()->text('api/w4os-pool.conf', [
            'pool_name' => $name,
            'pool_user' => $user,
            'pool_group' => $group,
            'pool_about' => wordwrap($about, 125, "\n; "),
            'web_group' => $listenGroup,
            'php_socket' => $socket,
        ]) . "\n";
    }

    /**
     * What a web server needs to serve the engine API of a grid to the sites of this machine only: a listener on 127.0.0.1, on the port
     * of the grid ($port, Grid::apiPort()), its paths (/api) and no other, through the pool of pool(), to the entry point of the engine
     * (engineRoot(), public/index.php, when $docroot is empty).
     *
     * @throws \InvalidArgumentException when the server is not one
     */
    public static function api(
        string $server,
        string $nick,
        string $docroot,
        string $socket,
        int $port,
    ): string {
        $server = strtolower(trim($server));
        isset(self::API_FILES[$server]) || throw new \InvalidArgumentException("unknown web server '$server'");

        return self::apiPreset()->text(self::API_FILES[$server], [
            'grid_nick' => $nick,
            // The entry point of the engine itself: the API needs no helpers
            'docroot' => $docroot !== '' ? $docroot : self::engineRoot(),
            'php_socket' => $socket,
            'api_port' => (string) $port,
        ]) . "\n";
    }

    private static function apiPreset(): \W4OS\Engine\Provisioning\OptionTemplate
    {
        return (new Presets())->options()['engine-api'] ?? throw new \RuntimeException('the preset engine-api is not installed');
    }

    /**
     * The paths a web server hands over to the helpers: their base and what is under it (`/helpers`, `/helpers/*`), and each address
     * of the grid elsewhere on its site as it is, with what follows it (`/helper/query.php`, `/helper/query.php/*`): never a whole
     * first segment, which may be the site's (`/opensim*` would take the mini site under /opensim), nor the root's /health. A base at
     * the root of the site takes nothing by itself: its pages are each their own. Under a prefix, the ones under it alone.
     *
     * @param string $base the base of the helpers (`/helpers`)
     * @param list<string> $exact the other paths: their pages, their scripts, the addresses the grid names (Grid::helperPaths())
     * @return array{globs:list<string>,regex:list<string>} the globs of a matcher (Caddy), the parts of a regular expression (nginx, Apache)
     */
    public static function routes(string $base, array $exact, string $prefix = ''): array
    {
        $prefix = trim($prefix, '/');
        $base = trim($base, '/');
        $under = static fn(string $path, string $root): bool => $root === '' || $path === $root || str_starts_with("$path/", "$root/");
        $globs = [];
        $regex = [];
        $add = static function (string $path) use (&$globs, &$regex): void {
            array_push($globs, "/$path", "/$path/*");
            $regex[] = preg_quote($path);
        };
        // The base, when it is a folder of the site (and under the prefix)
        $base !== '' && $under($base, $prefix) && $base !== $prefix && $add($base);
        $seen = [];
        foreach ($exact as $path) {
            $path = trim($path, '/');
            if ($path === '' || $path === $prefix || isset($seen[$path]) || !$under($path, $prefix) || ($base !== '' && $under($path, $base))) {
                continue;
            }
            $seen[$path] = true;
            $add($path);
        }

        return ['globs' => $globs, 'regex' => $regex];
    }

    /**
     * The paths of the helpers of a grid outside a prefix (the mini site under /opensim): the addresses its config names on its site
     * elsewhere, each exactly with what follows it (routes()), for a block of their own beside the one of the prefix.
     *
     * @return list<string> the paths, as Caddy addresses them (`/helper/query.php`, `/helper/query.php/*`)
     */
    public static function outside(\W4OS\Engine\Models\Grid $grid, string $prefix): array
    {
        $prefix = trim($prefix, '/');
        $services = HelpersConfig::services($grid->directory());
        $routes = self::routes(
            $services->base(),
            [...array_map(static fn(string $page): string => $services->path($page), [...array_keys(Services::SCRIPTS), ...array_keys(Services::PAGES)]), ...array_keys($grid->helperPaths())],
        );

        return array_values(array_filter($routes['globs'], static fn(string $glob): bool => $prefix === '' ? false : !str_starts_with(rtrim($glob, '*') . '/', "/$prefix/")));
    }

    /**
     * Render the web-server include for a grid from its shipped preset. Under a prefix (`/opensim`: the block of the site of the grid
     * has that path, beside the one of its host), the paths of the helpers are the ones under it, each by its segment after it
     * (`/opensim/economy`), never the prefix itself; the others stay with the block of the host.
     *
     * @throws \InvalidArgumentException when the server has no preset
     * @throws \RuntimeException when the preset file cannot be read
     */
    public static function render(
        string $server,
        string $nick,
        string $gridDirectory,
        string $docroot = '',
        string $socket = '',
        string $webUrl = '',
        string $prefix = '',
        ?\W4OS\Engine\Models\Grid $grid = null,
    ): string {
        $server = strtolower(trim($server));
        $prefix = trim($prefix, '/');
        if (!in_array($server, self::SERVERS, true)) {
            throw new \InvalidArgumentException("unknown web server '$server'");
        }

        $preset = ((new Presets())->options()["webserver-$server"] ?? null)?->directory();
        $templates = glob(($preset ?? '') . '/web/*') ?: [];
        if ($preset === null || $templates === []) {
            throw new \RuntimeException("web-server preset '$server' is not installed");
        }
        $template = $templates[0];
        $text = file_get_contents($template);
        if ($text === false) {
            throw new \RuntimeException("cannot read web-server preset $template");
        }

        $webUrl = $webUrl !== '' ? $webUrl : HelpersConfig::webUrl($gridDirectory);
        $helpers = PhpPools::installed('w4os-helpers');
        $services = HelpersConfig::services($gridDirectory);
        $routes = self::routes(
            $services->base(),
            [...array_map(static fn(string $page): string => $services->path($page), [...array_keys(Services::SCRIPTS), ...array_keys(Services::PAGES)]), ...array_keys($grid?->helperPaths() ?? [])],
            $prefix,
        );
        $variables = self::siteVariables($webUrl) + [
            'grid_nick' => $nick,
            'grid_dir' => rtrim($gridDirectory, '/'),
            'docroot' => $docroot !== '' ? $docroot : self::helpersRoot(),
            // The pool of the helpers once installed, else the socket given (the pool of the host), else the default one of Debian
            'php_socket' => $helpers ? self::HELPERS_SOCKET : ($socket !== '' ? $socket : '/run/php/php-fpm.sock'),
            'php_handler' => $helpers ? "\t<FilesMatch \"\\.php\$\">\n\t\tSetHandler \"proxy:unix:" . self::HELPERS_SOCKET . "|fcgi://w4os-helpers\"\n\t</FilesMatch>\n" : '',
            'web_globs' => implode(' ', $routes['globs']),
            'web_regex' => implode('|', $routes['regex']),
        ];

        return (string) preg_replace_callback(
            '/{{([a-z_]+)}}/',
            static fn(array $match): string => $variables[$match[1]] ?? $match[0],
            $text,
        );
    }
}
