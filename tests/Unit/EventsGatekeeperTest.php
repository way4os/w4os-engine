<?php

declare(strict_types=1);

/**
 * The events of a calendar ask the gatekeepers of their regions whether they answer and where they are: each place once a run, a
 * host that does not answer once, the hosts all at once (Gatekeeper::prefetch()), so that a calendar of hundreds of events does not
 * keep a request waiting for minutes.
 */

use OpenSim\Hypergrid\Gatekeeper;
use OpenSim\Services\EventsParser;
use OpenSim\Testing\ClockFake;

const EVENT_WELCOME = '11111111-1111-4111-8111-111111111111';
const EVENT_PLAZA = '22222222-2222-4222-8222-222222222222';

/** An answer of XML-RPC, as a gatekeeper gives it. */
function gatekeeper_answer(array $struct): string
{
    return (new PhpXmlRpc\Response((new PhpXmlRpc\Encoder())->encode($struct)))->serialize();
}

/** @return list<array<string,mixed>> events at these places, all to come */
function events_at(ClockFake $clock, string ...$places): array
{
    return array_map(static fn(string $place, int $i): array => [
        'title' => "Event $i", 'description' => '', 'categories' => ['Music'], 'hgurl' => $place,
        'start' => gmdate('Y-m-d\TH:i:s\Z', (int) $clock->now + 3600), 'end' => gmdate('Y-m-d\TH:i:s\Z', (int) $clock->now + 7200),
    ], $places, array_keys($places));
}

afterEach(fn() => Gatekeeper::using(null));

describe('The events of a calendar', function () {
    test('ask each place once a run, and a host that does not answer once, with the same results', function () {
        $asked = [];
        Gatekeeper::using(static function (string $url, string $body) use (&$asked): ?string {
            preg_match('#<methodName>(.*?)</methodName>#', $body, $method);
            preg_match('#<string>([^<]*)</string>#', $body, $value);
            $asked[] = "$url {$method[1]} {$value[1]}";
            if (str_contains($url, 'down.example.org')) {
                return null;
            }
            $uuid = str_contains($body, 'Plaza') || str_contains($body, EVENT_PLAZA) ? EVENT_PLAZA : EVENT_WELCOME;

            return gatekeeper_answer($method[1] === 'link_region'
                ? ['uuid' => $uuid, 'result' => 'True']
                : ['uuid' => $uuid, 'region_name' => $uuid === EVENT_PLAZA ? 'Plaza' : 'Welcome', 'x' => '256000', 'y' => '256000', 'result' => 'True']);
        });
        $db = search_database();
        $clock = new ClockFake();
        $clock->now = 1_700_000_000.0;
        $calendar = events_at($clock, 'grid.example.org:8002:Welcome', 'grid.example.org:8002:Welcome/10/20/30', 'grid.example.org:8002:Plaza',
            'down.example.org:8002:Far', 'down.example.org:8002:Farther', 'grid.example.org:8002:Welcome');
        $parser = new EventsParser($db, 'https://calendar.example.org', static fn(string $url): ?string => json_encode($calendar), clock: $clock);

        $kept = $parser->parse();
        $first = $asked;
        // A second run asks again: what a gatekeeper says is remembered during a run only
        $asked = [];
        $parser->parse();

        expect($kept)->toBe(4)
            ->and($db->get_col('SELECT globalPos FROM events ORDER BY eventid'))
            ->toBe(['256128,256128,25', '256010,256020,30', '256128,256128,25', '256128,256128,25'])
            ->and($first)->toBe([
                'http://grid.example.org:8002 link_region Welcome',
                'http://grid.example.org:8002 link_region Plaza',
                'http://down.example.org:8002 link_region Far',
                'http://grid.example.org:8002 get_region ' . EVENT_WELCOME,
                'http://grid.example.org:8002 get_region ' . EVENT_PLAZA,
            ])
            ->and($asked)->toBe($first);
    });

    test('ask the hosts all at once', function () {
        $dir = test_tmp() . '/gatekeepers-' . bin2hex(random_bytes(4));
        mkdir($dir);
        // Each answer takes 400 ms
        file_put_contents("$dir/router.php", '<?php usleep(400000); $body = file_get_contents("php://input"); preg_match("#<methodName>(.*?)</methodName>#", $body, $m);
            header("Content-Type: text/xml"); echo (new PhpXmlRpc\Response((new PhpXmlRpc\Encoder())->encode($m[1] === "link_region"
                ? ["uuid" => "' . EVENT_WELCOME . '", "result" => "True"]
                : ["uuid" => "' . EVENT_WELCOME . '", "region_name" => "Welcome", "x" => "256000", "y" => "256000", "result" => "True"])))->serialize();');
        file_put_contents("$dir/boot.php", '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; require __DIR__ . "/router.php";');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/boot.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['PHP_CLI_SERVER_WORKERS' => '8'] + getenv());
        set_error_handler(static fn() => true);
        for ($try = 0; $try < 50 && !($connection = fsockopen('127.0.0.1', $port)); ++$try) {
            usleep(100_000);
        }
        restore_error_handler();
        empty($connection) || fclose($connection);
        $places = array_map(static fn(int $i): string => "127.0.0.1:$port:Region$i", range(1, 6));

        Gatekeeper::forget();
        $start = microtime(true);
        Gatekeeper::prefetch($places);
        $took = microtime(true) - $start;
        $online = array_map(static fn(string $place): bool => Gatekeeper::regionIsOnline($place), $places);
        proc_terminate($server);
        exec('pkill -f ' . escapeshellarg("$dir/boot.php") . ' 2>/dev/null');

        // One after the other: 6 links and 6 regions, 4.8 s; at once, two rounds
        expect($online)->toBe(array_fill(0, 6, true))
            ->and($took)->toBeLessThan(2.5);
    });
});
