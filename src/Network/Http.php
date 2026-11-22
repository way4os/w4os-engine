<?php

declare(strict_types=1);

namespace W4OS\Engine\Network;

/**
 * HTTP requests to the services of a grid: the body of the answer, null when there is none or its status is not 2xx.
 */
final class Http
{
    /**
     * @param array<string,scalar>|string $body the fields of a form, or the body as it is
     * @param list<string> $headers
     */
    public static function post(string $url, array|string $body, array $headers = [], int $timeout = 5): ?string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => is_array($body) ? http_build_query($body) : $body,
            CURLOPT_HTTPHEADER => $headers ?: ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);

        return self::answer($handle, curl_exec($handle));
    }

    /**
     * A POST and its answer, whatever its status: [the status, 0 when nothing answered; the body].
     *
     * @param array<string,scalar>|string $body the fields of a form, or the body as it is
     * @return array{0:int,1:string}
     */
    public static function exchange(string $url, array|string $body, int $timeout = 5): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return [0, ''];
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => is_array($body) ? http_build_query($body) : $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $answer = curl_exec($handle);

        return [is_string($answer) ? (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) : 0, is_string($answer) ? $answer : ''];
    }

    public static function get(string $url, int $timeout = 5): ?string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);

        return self::answer($handle, curl_exec($handle));
    }

    /**
     * A text at an address: a text/* answer, or one that says no type (a plain file some servers send without one, the guide of
     * 2do.directory), of `$max` bytes at most (a longer one is cut off and refused); any other type is refused.
     */
    public static function text(string $url, int $max, int $timeout = 5): ?string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        $body = '';
        curl_setopt_array($handle, [
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            // Read until it is too long
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $max): int {
                $body .= $chunk;

                return strlen($body) > $max ? 0 : strlen($chunk);
            },
        ]);
        $done = curl_exec($handle);
        $type = strtolower((string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE));

        return $done !== false && strlen($body) <= $max && ($type === '' || str_starts_with($type, 'text/')) ? self::answer($handle, $body) : null;
    }

    private static function answer(\CurlHandle $handle, string|bool $body): ?string
    {
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return is_string($body) && $status >= 200 && $status < 300 ? $body : null;
    }
}
