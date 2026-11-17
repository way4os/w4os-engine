<?php

namespace OpenSim\Provisioning\Web;

use OpenSim\Provisioning\Presets;

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
    public const WEBROOT = '/usr/share/opensim-helpers';

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

    /** Where the engine API for the sites of this machine is reached by default: its socket, its port on 127.0.0.1. */
    public const API_SOCKET = '/run/php/opensim-api.sock';
    public const API_PORT = 8780;

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
        return self::apiPreset()->text('api/opensim-api.conf', [
            'system_user' => $systemUser,
            'web_group' => $webGroup,
            'php_socket' => $socket,
        ]) . "\n";
    }

    /**
     * What a web server needs to serve the engine API of a grid to the sites of this machine only: a listener on 127.0.0.1, its paths
     * (/api) and no other, through the pool of pool().
     *
     * @throws \InvalidArgumentException when the server is not one
     */
    public static function api(
        string $server,
        string $nick,
        string $docroot = '',
        string $socket = self::API_SOCKET,
        int $port = self::API_PORT,
    ): string {
        $server = strtolower(trim($server));
        isset(self::API_FILES[$server]) || throw new \InvalidArgumentException("unknown web server '$server'");

        return self::apiPreset()->text(self::API_FILES[$server], [
            'grid_nick' => $nick,
            'docroot' => $docroot !== '' ? $docroot : self::WEBROOT . '/html',
            'php_socket' => $socket,
            'api_port' => (string) $port,
        ]) . "\n";
    }

    private static function apiPreset(): \OpenSim\Provisioning\OptionTemplate
    {
        return (new Presets())->options()['engine-api'] ?? throw new \RuntimeException('the preset engine-api is not installed');
    }

    /**
     * Render the web-server include for a grid from its shipped preset.
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
    ): string {
        $server = strtolower(trim($server));
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
        $segments = [];
        foreach ([...array_keys(Services::SCRIPTS), ...array_keys(Services::PAGES)] as $page) {
            $segment = explode('/', trim($services->path($page), '/'))[0];
            $segment === '' || ($segments[$segment] = true);
        }
        $segments['health'] = true;
        $variables = self::siteVariables($webUrl) + [
            'grid_nick' => $nick,
            'grid_dir' => rtrim($gridDirectory, '/'),
            'docroot' => $docroot !== '' ? $docroot : self::WEBROOT . '/html',
            'php_socket' => $socket !== '' ? $socket : '/run/php/php-fpm.sock',
            'web_globs' => implode(' ', array_map(static fn(string $path): string => "/$path*", array_keys($segments))),
            'web_regex' => implode('|', array_map('preg_quote', array_keys($segments))),
        ];

        return (string) preg_replace_callback(
            '/{{([a-z_]+)}}/',
            static fn(array $match): string => $variables[$match[1]] ?? $match[0],
            $text,
        );
    }
}
