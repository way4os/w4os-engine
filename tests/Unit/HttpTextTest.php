<?php

declare(strict_types=1);

/**
 * A text at an address (Http::text()), as the guide reads its source: text, or an answer that says no type (the guide of
 * 2do.directory), never another type.
 */

use W4OS\Engine\Network\Http;

describe('A text at an address', function () {
    test('is read when it is text or says no type, refused when it is of another type', function () {
        $dir = test_tmp() . '/http-text-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        file_put_contents("$dir/router.php", '<?php
            $type = ["/text" => "text/plain; charset=utf-8", "/none" => "", "/json" => "application/json"][$_SERVER["REQUEST_URI"]] ?? "";
            $type === "" ? header_remove("Content-Type") : header("Content-Type: $type");
            $type === "" && header("Content-Type:");
            echo "Place|hg.example.org:8002/Place\n";');
        // A free port, then the server, waited for
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
        $read = static fn(string $path): ?string => Http::text("http://127.0.0.1:$port$path", 1000);
        $text = $read('/text');
        $none = $read('/none');
        $json = $read('/json');
        proc_terminate($server);

        expect($text)->toBe("Place|hg.example.org:8002/Place\n")
            ->and($none)->toBe("Place|hg.example.org:8002/Place\n")
            ->and($json)->toBeNull();
    });
});
