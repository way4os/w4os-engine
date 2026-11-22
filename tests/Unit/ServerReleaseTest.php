<?php

declare(strict_types=1);

/**
 * The answer of a request sent and the client freed before what follows it (Server::hold(), Server::release()): a simulator that
 * registers, a viewer that searches never wait for the tasks, whatever the server runs PHP with.
 */

/** A PHP server of its own for a script, on a free port; [its process, its port]. */
function release_server(string $dir, string $script): array
{
    file_put_contents("$dir/router.php", $script);
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    set_error_handler(static fn() => true);
    for ($try = 0; $try < 50 && !($connection = fsockopen('127.0.0.1', $port)); ++$try) {
        usleep(100_000);
    }
    restore_error_handler();
    empty($connection) || fclose($connection);

    return [$server, $port];
}

describe('The answer of a request', function () {
    test('frees the client once it is sent, before what follows it ends, on a server without fastcgi_finish_request', function () {
        $dir = test_tmp() . '/release-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        [$server, $port] = release_server($dir, '<?php
            require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
            W4OS\Engine\Api\Server::hold();
            header("Content-Type: text/plain");
            echo "answered";
            W4OS\Engine\Api\Server::release();
            // What follows the answer: the tasks
            sleep(2);
            file_put_contents(__DIR__ . "/done", "1");');
        $curl = curl_init("http://127.0.0.1:$port/");
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $start = microtime(true);
        $body = curl_exec($curl);
        $took = microtime(true) - $start;
        $before = is_file("$dir/done");
        for ($try = 0; $try < 50 && !is_file("$dir/done"); ++$try) {
            usleep(100_000);
        }
        proc_terminate($server);

        expect($body)->toBe('answered')
            ->and($took)->toBeLessThan(1.5)
            ->and($before)->toBeFalse()
            ->and(is_file("$dir/done"))->toBeTrue();
    });

    test('frees it the way of its server where it has one (PHP-FPM, LiteSpeed), what was written sent first', function () {
        $dir = test_tmp() . '/release-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        [$server, $port] = release_server($dir, '<?php
            require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
            W4OS\Engine\Api\Server::hold();
            echo "answered";
            var_export(W4OS\Engine\Api\Server::release(static function (): bool { echo "|freed|"; return true; }));');
        $body = file_get_contents("http://127.0.0.1:$port/");
        proc_terminate($server);

        expect($body)->toBe('answered|freed|true');
    });

    test('is nothing to free on the command line: no client', function () {
        expect(W4OS\Engine\Api\Server::release(static fn(): bool => throw new RuntimeException('never')))->toBeFalse();
    });
});
