<?php

declare(strict_types=1);

namespace W4OS\Engine\Api;

use W4OS\Engine\Runtime\Environment;
use W4OS\Engine\Security\ApiKeys;

/**
 * The API over HTTP on its own: what a web server hands to `public/index.php` of the engine (PHP-FPM, mod_php...) answered by the
 * Router, with the keys of the install, without a framework and without the helpers. The paths are the ones of the contract, under
 * `/api` when the request asks there (the base, `OPENSIM_API_BASE` to choose another); `OPENSIM_GRID` names the grid of the short
 * paths, as for the helpers.
 *
 *     Server::serve(OpenSim::environment());   // public/index.php
 */
final class Server
{
    /** Where the API is mounted when the web server says nothing. */
    public const BASE = '/api';

    private static ?Router $router = null;

    /**
     * The answer to a request, as PHP gives it.
     *
     * @param array<string,mixed> $server $_SERVER
     * @param ?array<string,mixed> $query $_GET (default: the query of REQUEST_URI)
     * @param ?array<string,mixed> $parsed $_POST, for a form
     */
    public static function answer(Environment $environment, array $server, string $body, ?array $query = null, ?array $parsed = null): Response
    {
        [$path, $asked] = Router::target((string) ($server['REQUEST_URI'] ?? '/'));
        if ($query === null) {
            parse_str($asked, $query);
        }
        $base = rtrim((string) ($server['OPENSIM_API_BASE'] ?? (getenv('OPENSIM_API_BASE') ?: self::BASE)), '/');
        $base = $base !== '' && ($path === $base || str_starts_with($path, "$base/")) ? $base : '';
        $grid = (string) ($server['OPENSIM_GRID'] ?? (getenv('OPENSIM_GRID') ?: ''));
        // Apache gives the header of the key under another name when it is not passed as it is (CGIPassAuth)
        $authorization = (string) ($server['HTTP_AUTHORIZATION'] ?? ($server['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        self::$router = new Router(new Api($environment), $base, null, $grid !== '' ? $grid : null, ApiKeys::for($environment));

        return self::$router->route(
            strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $query,
            $body,
            (string) ($server['REMOTE_ADDR'] ?? ''),
            (string) ($server['HTTP_ACCEPT'] ?? ''),
            $parsed !== null && $parsed !== [] ? $parsed : null,
            $authorization,
        );
    }

    /** Answer the request of this PHP process, then do what waited for the answer (a simulator that registered is read). */
    public static function serve(Environment $environment): void
    {
        $answer = self::answer($environment, $_SERVER, (string) file_get_contents('php://input'), $_GET, $_POST);
        http_response_code($answer->status);
        foreach ($answer->headers as $name => $value) {
            header("$name: $value");
        }
        $answer->status === 204 || print $answer->body();
        function_exists('fastcgi_finish_request') && fastcgi_finish_request();
        self::$router?->terminate();
    }
}
