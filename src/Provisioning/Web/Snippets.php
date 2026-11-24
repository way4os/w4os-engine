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
        return self::apiPreset()->text('api/w4os-api.conf', [
            'system_user' => $systemUser,
            'web_group' => $webGroup,
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
     * The paths a web server hands over to the helpers: the first segment of their base and of their pages (`/helpers*`), and each
     * address of a service the grid names elsewhere on its site, as it is (`/helper/query.php`, and what follows it), never its whole
     * first segment, which may be the site's. Under a prefix, the ones under it alone, by their segment after it.
     *
     * @param list<string> $segmented the paths whose first segment is the helpers' (their base, their pages)
     * @param list<string> $exact the paths of services set apart (Grid::helperPaths())
     * @return array{globs:list<string>,regex:list<string>} the globs of a matcher (Caddy), the parts of a regular expression (nginx, Apache)
     */
    public static function routes(array $segmented, array $exact, string $prefix = ''): array
    {
        $prefix = trim($prefix, '/');
        $relative = static function (string $path) use ($prefix): ?string {
            $path = trim($path, '/');
            if ($prefix === '') {
                return $path;
            }

            return str_starts_with("$path/", "$prefix/") ? ltrim(substr($path, strlen($prefix)), '/') : null;
        };
        $segments = [];
        foreach ($segmented as $path) {
            $path = $relative($path);
            $segment = $path === null ? '' : explode('/', $path)[0];
            // The root of the prefix is the site's, never the helpers'; /health is the root's own
            $segment === '' || ($prefix !== '' && $segment === 'health') || ($segments[$segment] = true);
        }
        $at = static fn(string $path): string => $prefix === '' ? $path : "$prefix/$path";
        $globs = $prefix === ''
            ? array_map(static fn(string $segment): string => "/$segment*", array_keys($segments))
            : array_merge(...array_map(static fn(string $segment): array => ['/' . $at($segment), '/' . $at($segment) . '/*'], array_keys($segments) ?: []), ...[[]]);
        $regex = array_map(static fn(string $segment): string => preg_quote($at($segment)), array_keys($segments));
        foreach (array_unique($exact) as $path) {
            $path = $relative($path);
            if ($path === null || $path === '' || isset($segments[explode('/', $path)[0]])) {
                continue;
            }
            array_push($globs, '/' . $at($path), '/' . $at($path) . '/*');
            $regex[] = preg_quote($at($path));
        }

        return ['globs' => $globs, 'regex' => $regex];
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
        $services = HelpersConfig::services($gridDirectory);
        $routes = self::routes(
            [...array_map(static fn(string $page): string => $services->path($page), [...array_keys(Services::SCRIPTS), ...array_keys(Services::PAGES)]), '/health'],
            array_values($grid?->helperPaths() ?? []),
            $prefix,
        );
        $variables = self::siteVariables($webUrl) + [
            'grid_nick' => $nick,
            'grid_dir' => rtrim($gridDirectory, '/'),
            'docroot' => $docroot !== '' ? $docroot : self::helpersRoot(),
            'php_socket' => $socket !== '' ? $socket : '/run/php/php-fpm.sock',
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
