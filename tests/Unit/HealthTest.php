<?php
/**
 * Health checks: what an answer means, the answers of real addresses (a small web server of the test), and the sections and
 * settings named as the ini files name them.
 */

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Health\Health;

/** A web server that answers the code its path says: /200, /404, /500, /redirect (to /200), /redirect404 (to /404); /needs-data refuses (400) what has no data; /block/NAME shows the block NAME of the helpers, far in the page. */
function code_server(int $workers = 1): array
{
    $dir = sys_get_temp_dir() . '/code-server-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents(
        "$dir/router.php",
        '<?php $path = trim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
        if ($path === "redirect") { header("Location: /200", true, 308); return; }
        if ($path === "to-register") { header("Location: http://" . $_SERVER["HTTP_HOST"] . "/register?return=profile", true, 302); return; }
        if ($path === "elsewhere") { header("Location: /200", true, 302); return; }
        if ($path === "profile-slash") { header("Location: /profile-chain", true, 301); return; }
        if ($path === "profile-chain") { header("Location: /to-register", true, 301); return; }
        if (str_starts_with($path, "chain-away")) { header("Location: /elsewhere", true, 301); return; }
        if ($path === "redirect404") { header("Location: /404", true, 308); return; }
        if ($path === "get_grid_info") { header("Content-Type: text/xml"); echo "<gridinfo><gridname>Alpha</gridname><login>http://play.example.org:8002/</login></gridinfo>"; return; }
        if ($path === "busy") { $f = __DIR__ . "/busy"; $h = fopen($f, "c+"); flock($h, LOCK_EX); [$now, $max] = array_map("intval", explode(" ", stream_get_contents($h) ?: "0 0") + [0, 0]); $now++; $max = max($max, $now); ftruncate($h, 0); rewind($h); fwrite($h, "$now $max"); flock($h, LOCK_UN); usleep(300000); flock($h, LOCK_EX); rewind($h); [$now, $max] = array_map("intval", explode(" ", stream_get_contents($h)) + [0, 0]); $now--; ftruncate($h, 0); rewind($h); fwrite($h, "$now $max"); flock($h, LOCK_UN); return; }
        if (preg_match("#^slow/(\\d+)$#", $path, $m)) { usleep((int) $m[1] * 1000); http_response_code(200); return; }
        if (preg_match("#^block/(.+)$#", $path, $m)) { echo str_repeat("x", 70000) . "<section data-helpers-block=\"" . $m[1] . "\">"; return; }
        if ($path === "needs-data") { http_response_code(file_get_contents("php://input") === "" && empty($_SERVER["QUERY_STRING"]) ? 400 : 200); return; }
        $first = explode("/", $path)[0]; http_response_code(is_numeric($first) ? (int) $first : 200); echo str_repeat("x", 5000);',
    );
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/router.php"],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        null,
        // Several requests at once when asked (a pool of workers, as a site has)
        $workers > 1 ? ['PHP_CLI_SERVER_WORKERS' => (string) $workers] + getenv() : null,
    );
    // Until it listens (a refused connection is expected meanwhile)
    set_error_handler(static fn() => true);
    for ($try = 0; $try < 50 && !($connection = fsockopen('127.0.0.1', $port)); ++$try) {
        usleep(100_000);
    }
    restore_error_handler();
    if (!empty($connection)) {
        fclose($connection);
    }

    // Its workers too, whatever stops the server: proc_terminate() stops only the first one
    register_shutdown_function(static fn() => exec('pkill -f ' . escapeshellarg("$dir/router.php") . ' 2>/dev/null'));

    return [$process, $port, $dir];
}

describe('An address', function () {
    test(
        'is fine for 2xx, an error for anything else (a 404 is red) unless it is the answer expected, gives the error code when nothing answers, and is not defined when empty',
        function () {
            expect(Health::verdict(200, '', 12))
                ->toBe(['level' => 'ok', 'status' => '200', 'ms' => 12])
                ->and(Health::verdict(404, '', 3))
                ->toBe(['level' => 'error', 'status' => '404', 'ms' => 3])
                ->and(Health::verdict(500, '', 3)['level'])
                ->toBe('error')
                // What is expected of an address is not an error (a service called without parameters refuses, a teapot is not a page)
                ->and(Health::verdict(405, '', 3, 0, [400, 405]))
                ->toBe(['level' => 'ok', 'status' => '405', 'ms' => 3])
                ->and(Health::verdict(418, '', 3, 0, [418])['level'])
                ->toBe('ok')
                ->and(Health::verdict(404, '', 3, 0, [400, 405])['level'])
                ->toBe('error')
                // No answer is not an answer: the code of the error is, and what it says
                ->and(Health::verdict(0, 'Could not resolve host: x', 0, 6))
                ->toBe([
                    'level' => 'error',
                    'status' => 'cURL error 6',
                    'ms' => null,
                    'detail' => 'Could not resolve host: x',
                ])
                // An address that is not defined says nothing: it shows
                ->and(Health::verdict(null, '', 0))
                ->toBe(['level' => 'none', 'status' => '', 'ms' => null]);
        },
    );

    test('is asked, all of them, and the answer is the one of each, where the redirections lead', function () {
        [$process, $port] = code_server();
        $url = fn(string $path) => "http://127.0.0.1:$port/$path";
        $groups = Health::answers([
            [
                'title' => 'One',
                'rows' => [
                    ['label' => 'fine', 'url' => $url('200'), 'method' => 'get'],
                    ['label' => 'missing', 'url' => $url('404'), 'method' => 'get'],
                    ['label' => 'broken', 'url' => $url('500'), 'method' => 'get'],
                    ['label' => 'moved', 'url' => $url('redirect'), 'method' => 'get'],
                    ['label' => 'expected', 'url' => $url('404'), 'method' => 'get', 'expect' => [404]],
                    ['label' => 'movedaway', 'url' => $url('redirect404'), 'method' => 'get'],
                    ['label' => 'api', 'url' => $url('200'), 'method' => 'xmlrpc', 'body' => '<x/>'],
                    ['label' => 'probed', 'url' => $url('404'), 'probe' => $url('200'), 'method' => 'get'],
                    ['label' => 'template', 'url' => $url('404'), 'method' => 'get', 'skip' => true],
                    ['label' => 'empty', 'url' => '', 'method' => 'get'],
                    ['label' => 'nobody', 'url' => 'http://127.0.0.1:9/', 'method' => 'get'],
                ],
            ],
        ]);
        proc_terminate($process);
        $by = array_column($groups[0]['rows'], null, 'label');

        expect($by['fine']['status'])
            ->toBe('200')
            ->and($by['missing'])
            ->toMatchArray(['level' => 'error', 'status' => '404'])
            ->and($by['broken']['level'])
            ->toBe('error')
            // The redirection is anecdotal, the result at the end is what counts
            ->and($by['moved']['status'])
            ->toBe('200')
            ->and($by['movedaway']['status'])
            ->toBe('404')
            ->and($by['expected'])
            ->toMatchArray(['level' => 'ok', 'status' => '404'])
            ->and($by['api'])
            ->toMatchArray(['level' => 'ok', 'status' => '200'])
            ->and($by['probed'])
            ->toMatchArray(['level' => 'ok', 'url' => $url('404')])
            ->and($by['template'])
            ->toMatchArray(['level' => 'none', 'status' => ''])
            ->and($by['empty'])
            ->toMatchArray(['level' => 'none', 'status' => ''])
            ->and($by['nobody'])
            ->toMatchArray(['level' => 'error', 'status' => 'cURL error 7'])
            ->and($by['nobody']['detail'])
            ->not->toBe('')
            ->and($by['fine'])
            ->not->toHaveKeys(['method', 'probe', 'skip', 'expect', 'body']);
    });

    test('are asked a few at a time, never all at once: a site whose pool of workers is small answers them all', function () {
        [$process, $port, $dir] = code_server(6);
        $rows = array_map(static fn(int $i): array => ['label' => "page$i", 'url' => "http://127.0.0.1:$port/busy?$i", 'method' => 'get'], range(1, 6));

        $answered = Health::answers([['title' => 'One', 'rows' => $rows]]);
        $max = (int) explode(' ', (string) file_get_contents("$dir/busy"))[1];
        proc_terminate($process);

        expect(array_unique(array_column($answered[0]['rows'], 'level')))
            ->toBe(['ok'])
            ->and($max)
            ->toBe(Health::CONCURRENCY)
            ->and(Health::CONCURRENCY)
            ->toBeLessThanOrEqual(2);
    });

    test('is asked again, longer, when it took too long: slow when it answers then, an error when it never does', function () {
        [$process, $port] = code_server();
        $ask = static fn(int $ms, float $timeout, float $retry) => Health::answers(
            [['title' => 'One', 'rows' => [['label' => 'page', 'url' => "http://127.0.0.1:$port/slow/$ms", 'method' => 'get']]]],
            $timeout,
            $retry,
        )[0]['rows'][0];

        // One at a time: the server of the test answers one request after the other
        $fast = $ask(10, 1, 4);
        $slow = $ask(1500, 1, 4);
        $never = $ask(2500, 1, 1);
        proc_terminate($process);

        expect($slow)
            ->toMatchArray(['level' => 'slow', 'status' => '200'])
            ->and($slow['detail'] ?? '')
            ->toContain('1 s')
            ->and($never)
            ->toMatchArray(['level' => 'error', 'status' => 'cURL error 28'])
            ->and($fast)
            ->toMatchArray(['level' => 'ok', 'status' => '200'])
            ->and(Health::TIMEOUT)
            ->toBe(3)
            ->and(Health::RETRY_TIMEOUT)
            ->toBeGreaterThan(Health::TIMEOUT);
    });

    test(
        'is asked twice when it is a service: with nothing, it must refuse, with what it wants, it must answer',
        function () {
            [$process, $port] = code_server();
            $url = "http://127.0.0.1:$port/needs-data";
            $groups = Health::answers([
                [
                    'title' => 'Services',
                    'rows' => [
                        ...Health::xmlrpc('SearchURL', $url, 'dir_places_query', ['text' => 'a']),
                        ...Health::post('OfflineMessageURL', $url, '<x/>'),
                        ...Health::query('DATA_SRV_a', $url, 'service=offline'),
                        // A service that answers a call with nothing in it is not one that works: it is red
                        ...Health::xmlrpc('Lazy', "http://127.0.0.1:$port/200", 'm', []),
                    ],
                ],
            ]);
            proc_terminate($process);
            $rows = $groups[0]['rows'];
            $of = fn(string $label, string $tag) => array_values(
                array_filter($rows, fn($row) => $row['label'] === $label && $row['tag'] === $tag),
            )[0];

            expect($of('SearchURL', 'without data'))
                ->toMatchArray(['status' => '400', 'level' => 'ok'])
                ->and($of('SearchURL', 'with valid XMLRPC request'))
                ->toMatchArray(['status' => '200', 'level' => 'ok'])
                ->and($of('OfflineMessageURL', 'without data')['level'])
                ->toBe('ok')
                ->and($of('OfflineMessageURL', 'with valid post data'))
                ->toMatchArray(['status' => '200', 'level' => 'ok'])
                ->and($of('DATA_SRV_a', 'without data')['level'])
                ->toBe('ok')
                ->and($of('DATA_SRV_a', 'with valid query'))
                ->toMatchArray(['status' => '200', 'level' => 'ok'])
                ->and($of('Lazy', 'without data'))
                ->toMatchArray(['status' => '200', 'level' => 'error']);
        },
    );

    test(
        'is asked with the data in the right place: the body for a post, the query for a get, a path after the address when it takes one',
        function () {
            [$search, $good] = Health::xmlrpc(
                'SearchURL',
                'https://play.example.org/helpers/query.php',
                'dir_places_query',
                ['text' => 'a'],
            );
            [$offline, $retrieve] = Health::post(
                'OfflineMessageURL',
                'https://play.example.org/helpers/offline.php',
                '<UUID/>',
                'RetrieveMessages/',
            );
            [$bare, $registered] = Health::query(
                'DATA_SRV_a',
                'https://play.example.org/helpers/register.php',
                'service=offline&host=x',
            );

            expect($search)
                ->toMatchArray(['method' => 'post', 'body' => '', 'expect' => [400, 405], 'tag' => 'without data'])
                ->and($good)
                ->toMatchArray(['method' => 'xmlrpc', 'tag' => 'with valid XMLRPC request'])
                ->and($good['body'])
                ->toContain('<methodName>dir_places_query</methodName>')
                ->and($retrieve)
                ->toMatchArray([
                    'method' => 'post',
                    'body' => '<UUID/>',
                    'probe' => 'https://play.example.org/helpers/offline.php/RetrieveMessages/',
                    'url' => 'https://play.example.org/helpers/offline.php',
                ])
                ->and($bare)
                ->toMatchArray(['method' => 'get', 'tag' => 'without data'])
                ->and($registered)
                ->toMatchArray([
                    'method' => 'get',
                    'tag' => 'with valid query',
                    'probe' => 'https://play.example.org/helpers/register.php?service=offline&host=x',
                ]);
        },
    );
});

describe('The pages of a grid', function () {
    test('are the ones the grid declares, each with what it must answer and what it answered: the profile of a site sends to its registration', function () {
        [$process, $port] = code_server();
        $url = fn(string $path) => "http://127.0.0.1:$port/$path";
        $rows = Health::pages([
            ['page' => 'helpers', 'url' => $url('200'), 'source' => 'config', 'servedBy' => 'helpers'],
            ['page' => 'search', 'url' => $url('200'), 'source' => 'config', 'servedBy' => 'site', 'helpersDefault' => $url('404')],
            // Not declared: the default of the helpers is not what the grid tells, nothing to ask
            ['page' => 'guide', 'url' => $url('404'), 'source' => 'default', 'servedBy' => 'helpers', 'helpersDefault' => $url('404')],
            ['page' => 'welcome', 'url' => $url('404'), 'source' => 'config', 'servedBy' => 'helpers'],
            ['page' => 'profile', 'url' => $url('to-register/[AGENT_NAME]'), 'source' => 'config', 'servedBy' => 'site'],
            ['page' => 'directory', 'url' => null, 'servedBy' => null],
        ], $url('register'));
        $wrong = Health::pages([['page' => 'profile', 'url' => $url('elsewhere/[AGENT_NAME]'), 'source' => 'config', 'servedBy' => 'site']], $url('register'));
        $groups = Health::answers([['file' => 'pages', 'title' => '', 'rows' => $rows], ['file' => 'pages', 'title' => '', 'rows' => $wrong]]);
        proc_terminate($process);
        $by = array_column($groups[0]['rows'], null, 'label');

        expect(array_map(static fn(array $row): string => $row['level'], $by))
            // Not declared, or not set: neither served nor tested
            // The base of the helpers is no page of the grid: not asked
            ->toBe(['search' => 'ok', 'guide' => 'unset', 'welcome' => 'error', 'profile' => 'ok', 'directory' => 'unset'])
            // What it must answer, what it answered
            ->and(array_intersect_key($by['search'], ['expectedStatus' => 1, 'status' => 1]))
            ->toBe(['status' => '200', 'expectedStatus' => '2xx'])
            ->and(array_intersect_key($by['profile'], ['expectedStatus' => 1, 'expectedLocation' => 1, 'status' => 1, 'location' => 1]))
            ->toBe(['status' => '302', 'expectedStatus' => '3xx', 'expectedLocation' => $url('register'), 'location' => $url('register?return=profile')])
            ->and($by['profile']['tag'] ?? '')
            ->toContain('signed out')
            // A profile that sends elsewhere than the registration is wrong
            ->and($groups[1]['rows'][0])
            ->toMatchArray(['level' => 'error', 'location' => $url('200')]);
    });
});

describe('The profile the grid tells', function () {
    test('is not a page without a name: a teapot for the helpers, the registration of a site for whoever is signed out', function () {
        [$process, $port] = code_server();
        $url = fn(string $path) => "http://127.0.0.1:$port/$path";
        $ask = static fn(string $profile, bool $helpers) => array_column(Health::answers(Health::grid([
            'live' => ['web_profile_url' => $profile],
            'register' => $url('register'),
            'servedByHelpers' => static fn(string $address): bool => $helpers,
        ])), null, 'file')['get_grid_info']['rows'];
        $site = array_column($ask($url('to-register/[AGENT_NAME]'), false), null, 'label')['web_profile_url'];
        $helpers = array_column($ask($url('418/[AGENT_NAME]'), true), null, 'label')['web_profile_url'];
        // The redirections of the site on the way (a slash taken away), the registration at the end
        $chain = array_column($ask($url('profile-slash/[AGENT_NAME]'), false), null, 'label')['web_profile_url'];
        $away = array_column($ask($url('chain-away/[AGENT_NAME]'), false), null, 'label')['web_profile_url'];
        proc_terminate($process);

        expect($site)
            ->toMatchArray(['level' => 'ok', 'status' => '302', 'expectedStatus' => '3xx', 'expectedLocation' => $url('register')])
            ->and($chain)
            ->toMatchArray(['level' => 'ok', 'status' => '301', 'expectedLocation' => $url('register'), 'location' => $url('register?return=profile')])
            ->and($away)
            ->toMatchArray(['level' => 'error', 'location' => $url('200')])
            ->and($helpers)
            ->toMatchArray(['level' => 'ok', 'status' => '418']);
    });
});

describe('What a grid says', function () {
    test('is what it answers at get_grid_info, as it says it, nothing when it does not answer', function () {
        [$process, $port] = code_server();
        $says = Health::gridInfo("http://127.0.0.1:$port/");
        $nothing = Health::gridInfo("http://127.0.0.1:$port/200");
        proc_terminate($process);

        expect($says)
            ->toBe(['gridname' => 'Alpha', 'login' => 'http://play.example.org:8002/'])
            ->and(Health::gridInfo('http://127.0.0.1:9/'))
            ->toBe([])
            ->and(Health::gridInfo('not an address'))
            ->toBe([]);
    });
});

describe('An address with a certificate this server does not trust', function () {
    test('answers its code, the check of the certificate said apart: what counts is the answer', function () {
        $openssl = trim((string) shell_exec('command -v openssl 2>/dev/null'));
        $dir = sys_get_temp_dir() . '/tls-' . bin2hex(random_bytes(4));
        mkdir($dir);
        exec(
            escapeshellarg($openssl) .
                " req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -keyout $dir/k.pem -out $dir/c.pem -days 1 -subj /CN=localhost 2>/dev/null",
            $out,
            $code,
        );
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        // A server that answers 200 to whatever is asked, with a certificate nobody signed
        $process = proc_open(
            [
                $openssl,
                's_server',
                '-accept',
                (string) $port,
                '-cert',
                "$dir/c.pem",
                '-key',
                "$dir/k.pem",
                '-www',
                '-quiet',
            ],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        set_error_handler(static fn() => true);
        for ($try = 0; $try < 50 && !($connection = fsockopen('127.0.0.1', $port)); ++$try) {
            usleep(100_000);
        }
        restore_error_handler();
        if (!empty($connection)) {
            fclose($connection);
        }

        $groups = Health::answers([
            ['title' => 'One', 'rows' => [['label' => 'tls', 'url' => "https://127.0.0.1:$port/", 'method' => 'get']]],
        ]);
        proc_terminate($process);
        $row = $groups[0]['rows'][0];

        expect($code)
            ->toBe(0)
            ->and($row)
            ->toMatchArray(['status' => '200', 'level' => 'ok', 'cert' => true])
            ->not->toHaveKey('note');
    })->skip(
        fn() => !str_contains((string) shell_exec('openssl version 2>/dev/null'), 'OpenSSL'),
        'no OpenSSL to make a certificate and serve it (LibreSSL does not do it the same way)',
    );
});

describe('sections of a grid', function () {
    /** A config as the files give it: Robust.HG.ini has all of it, as in the tests of the sections. */
    $config = fn(array $sections, array $roles = []) => new GridConfig(['Robust' => $sections] + $roles, [
        'Robust' => '/etc/opensim/grids/alpha/Robust.HG.ini',
    ]);
    $in = fn(array $sections, array $more = []) => ['config' => $config($sections)] + $more;
    $by = fn(array $groups) => array_column(
        array_map(fn($g) => ['title' => $g['file'] . ' ' . $g['title'], 'rows' => $g['rows']], $groups),
        'rows',
        'title',
    );
    $live = [
        'gridname' => 'Alpha World',
        'login' => 'http://play.example.org:8002/',
        'economy' => 'https://play.example.org/helpers',
        'welcome' => 'https://play.example.org/welcome',
        'web_profile_url' => 'https://play.example.org/profile/[AGENT_NAME]',
    ];

    test(
        'start with what the grid says, the address of login first, which is asked even when the grid says nothing',
        function () use ($by) {
            $groups = Health::grid([
                'live' => [
                    'gridname' => 'Alpha World',
                    'welcome' => 'https://play.example.org/welcome',
                    'login' => 'http://play.example.org:8002/',
                ],
                'login' => 'http://play.example.org:8002/',
            ]);
            $rows = array_column($groups[0]['rows'], null, 'label');

            expect(count($groups))
                ->toBe(1)
                ->and($groups[0]['file'])
                ->toBe('get_grid_info')
                ->and(array_column($groups[0]['rows'], 'label'))
                ->toBe(['login', 'welcome', 'gridname'])
                // The addresses are asked, a name is only shown
                ->and($rows['login'])
                ->toMatchArray([
                    'url' => 'http://play.example.org:8002/',
                    'probe' => 'http://play.example.org:8002/get_grid_info',
                ])
                ->and($rows['gridname'])
                ->toMatchArray(['kind' => 'value', 'text' => 'Alpha World', 'level' => 'none']);

            // The grid does not answer: its login address is there all the same, to be asked
            $down = Health::grid(['login' => 'http://play.example.org:8002/']);

            expect(array_column($down[0]['rows'], 'label'))->toBe(['login']);
        },
    );

    test('ask the profile without a name only in what the grid says, which is not a page (a teapot)', function () use (
        $live,
    ) {
        $says = array_values(
            array_filter(
                Health::grid(['live' => $live, 'owner' => 'Jane.Doe'])[0]['rows'],
                fn($row) => $row['label'] === 'web_profile_url',
            ),
        );

        // The address without the name, whatever there is to ask with
        expect($says)
            ->toHaveCount(1)
            ->and($says[0])
            ->toMatchArray(['url' => 'https://play.example.org/profile', 'expect' => [418]])
            ->not->toHaveKey('display');
    });

    test(
        'ask the profile in one row in the config: shown as the config says it, linked and asked with the name of the owner of the grid',
        function () use ($live, $in) {
            $profile = fn(array $groups) => array_values(
                array_filter($groups[1]['rows'], fn($row) => $row['label'] === 'web_profile_url'),
            );
            $info = ['GridInfoService' => ['web_profile_url' => 'https://play.example.org/profile/[AGENT_NAME]']];
            $rows = $profile(Health::grid($in($info, ['live' => $live, 'owner' => 'Jane.Doe'])));

            expect($rows)
                ->toHaveCount(1)
                ->and($rows[0])
                ->toMatchArray([
                    'display' => 'https://play.example.org/profile/[AGENT_NAME]',
                    'url' => 'https://play.example.org/profile/Jane.Doe',
                    'method' => 'get',
                ])
                ->not->toHaveKeys(['tag', 'skip', 'expect']);

            // The grid does not answer (nothing from get_grid_info): it is asked all the same, in the same row
            $down = array_values(
                array_filter(
                    Health::grid($in($info, ['owner' => 'Jane.Doe']))[0]['rows'],
                    fn($row) => $row['label'] === 'web_profile_url',
                ),
            );

            expect($down)
                ->toHaveCount(1)
                ->and($down[0])
                ->toMatchArray([
                    'display' => 'https://play.example.org/profile/[AGENT_NAME]',
                    'url' => 'https://play.example.org/profile/Jane.Doe',
                    'method' => 'get',
                ]);

            // What the config says is not what the grid says: an error, the row is still the one of the owner
            $differs = $profile(
                Health::grid(
                    $in(
                        ['GridInfoService' => ['web_profile_url' => 'https://other.example.org/profile/[AGENT_NAME]']],
                        ['live' => $live, 'owner' => 'Jane.Doe'],
                    ),
                ),
            );

            expect($differs[0])
                ->toMatchArray([
                    'level' => 'error',
                    'status' => 'differs from web_profile_url of get_grid_info',
                    'display' => 'https://other.example.org/profile/[AGENT_NAME]',
                    'url' => 'https://other.example.org/profile/Jane.Doe',
                ])
                ->and($differs[0]['detail'])
                ->toBe('get_grid_info web_profile_url: https://play.example.org/profile/[AGENT_NAME]');

            // No account to read: the address as it is, not asked
            $none = $profile(Health::grid($in($info, ['live' => $live])));

            expect($none[0])->toMatchArray([
                'level' => 'ok',
                'status' => 'matches web_profile_url of get_grid_info',
                'url' => 'https://play.example.org/profile/[AGENT_NAME]',
            ]);
        },
    );

    test(
        'are asked at /get_grid_info when they are the home of the grid (host:publicport), which answers nothing alone',
        function () {
            $groups = Health::grid([
                'live' => [
                    'login' => 'http://play.example.org:8002/',
                    'uas' => 'http://play.example.org:8002/',
                    'home' => 'http://play.example.org:8002',
                    'about' => 'http://play.example.org:8002/about',
                    'help' => 'https://play.example.org/help',
                ],
                'login' => 'http://play.example.org:8002/',
            ]);
            $rows = array_column($groups[0]['rows'], null, 'label');

            expect($rows['uas']['probe'])
                ->toBe('http://play.example.org:8002/get_grid_info')
                ->and($rows['home']['probe'])
                ->toBe('http://play.example.org:8002/get_grid_info')
                // A page of the same server is a page
                ->and($rows['about'])
                ->not->toHaveKey('probe')
                ->and($rows['help'])
                ->not->toHaveKey('probe');
        },
    );

    test(
        'expect a teapot where the viewers only add a name, and tell the web search (search, what the viewers read) from the API',
        function () use ($live) {
            $rows = array_column(
                Health::grid(['live' => $live + ['search' => 'https://play.example.org/search/']])[0]['rows'],
                null,
                'label',
            );

            expect($rows['economy']['expect'])
                ->toBe([418])
                ->and($rows['search'])
                ->toMatchArray(['tag' => 'Web search', 'method' => 'get']);
        },
    );

    test(
        'are the ones of the config by file, named as the ini files do, when there is one, the expected addresses of [GridInfoService] first',
        function () use ($in, $by, $live) {
            $groups = Health::grid(
                $in(
                    [
                        'GridInfoService' => [
                            'gridname' => 'Alpha World',
                            'economy' => 'https://play.example.org/helpers',
                            'partner' => 'https://partner.example.org/',
                        ],
                        'LoginService' => ['DestinationGuide' => 'https://play.example.org/helpers/guide.php'],
                        'DataSnapshot' => ['DATA_SRV_Alpha' => 'https://play.example.org/helpers/register.php'],
                    ],
                    ['live' => $live],
                ),
            );
            $rows = $by($groups);

            expect(array_map(fn($g) => $g['file'] . ' ' . $g['title'], $groups))
                ->toBe([
                    'get_grid_info ',
                    'Robust.HG.ini [GridInfoService]',
                    'Robust.HG.ini [LoginService]',
                    'OpenSim.ini [Search]',
                    'OpenSim.ini [DataSnapshot]',
                    'OpenSim.ini [Messaging]',
                    'MoneyServer.ini [MoneyServer]',
                ])
                // The ones of Robust.HG.ini.example, not what the example does not have (uas is the one of a standalone)
                ->and(array_column($rows['Robust.HG.ini [GridInfoService]'], 'label'))
                ->toBe([
                    'login',
                    'gatekeeper',
                    'welcome',
                    'economy',
                    'about',
                    'register',
                    'help',
                    'password',
                    'GridStatus',
                    'GridStatusRSS',
                    'web_profile_url',
                    'search',
                    'gridname',
                    'partner',
                ])
                ->and(array_column($rows['Robust.HG.ini [LoginService]'], 'label'))
                ->toBe(['DestinationGuide', 'MessageUrl', 'SearchURL']);
        },
    );

    test(
        'compare what the grid says with what the config says: the same is fine, a difference is an error that says what the grid says',
        function () use ($in, $by, $live) {
            $rows = array_column(
                $by(
                    Health::grid(
                        $in(
                            [
                                'GridInfoService' => [
                                    'gridname' => 'Alpha World',
                                    'login' => 'http://play.example.org:8002',
                                    'economy' => 'https://other.example.org/helpers',
                                    'about' => 'https://play.example.org/about',
                                ],
                                'Search' => [],
                            ],
                            ['live' => $live],
                        ),
                    ),
                )['Robust.HG.ini [GridInfoService]'],
                null,
                'label',
            );

            expect($rows['login'])
                ->toMatchArray(['level' => 'ok', 'status' => 'matches login of get_grid_info', 'url' => 'http://play.example.org:8002'])
                ->and($rows['gridname'])
                ->toMatchArray(['level' => 'ok', 'status' => 'matches gridname of get_grid_info', 'text' => 'Alpha World'])
                // The config says one thing and the grid another: a restart not done, or a config that does not reach it
                ->and($rows['economy'])
                ->toMatchArray([
                    'level' => 'error',
                    'status' => 'differs from economy of get_grid_info',
                    'url' => 'https://other.example.org/helpers',
                    'detail' => 'get_grid_info economy: https://play.example.org/helpers',
                ])
                // The config says it and the grid does not
                ->and($rows['about'])
                ->toMatchArray(['level' => 'error', 'status' => 'differs from about of get_grid_info', 'detail' => 'get_grid_info about: '])
                // Nothing in the config: nothing to compare
                ->and($rows['help'])
                ->toMatchArray(['level' => 'unset', 'reason' => 'health_unset']);
        },
    );

    test(
        'ask the addresses of the config from here when the grid does not answer, nothing to compare with',
        function () use ($in, $by) {
            $rows = array_column(
                $by(Health::grid($in(['GridInfoService' => ['economy' => 'https://play.example.org/helpers']])))[
                    'Robust.HG.ini [GridInfoService]'
                ],
                null,
                'label',
            );

            expect($rows['economy'])->toMatchArray([
                'url' => 'https://play.example.org/helpers',
                'method' => 'get',
                'expect' => [418],
            ]);
        },
    );

    test(
        'have no file of Robust in them when this machine has none, the grid info and the rest are there all the same',
        function () use ($live) {
            $config = new GridConfig([
                'Helpers' => ['Search' => ['SearchURL' => 'https://play.example.org/helpers/query.php']],
            ]);
            $groups = Health::grid(['config' => $config, 'live' => $live]);

            expect(array_column($groups, 'file'))->toBe([
                'get_grid_info',
                'OpenSim.ini',
                'OpenSim.ini',
                'OpenSim.ini',
                'MoneyServer.ini',
            ]);
        },
    );

    test('are in the file of Robust the grid has, whichever it is', function () use ($in) {
        $file = fn($robust) => Health::names(
            $robust === null ? null : new GridConfig(['Robust' => []], ['Robust' => "/etc/$robust"]),
        )['robust'];

        expect($file('Robust.ini'))
            ->toBe('Robust.ini')
            ->and($file('Robust.HG.ini'))
            ->toBe('Robust.HG.ini')
            ->and($file(null))
            ->toBe('Robust.ini');
    });

    test(
        'call each service the OpenSim.ini gives twice, in its own section: the search (Search), the registration (DataSnapshot), the offline messages (Messaging)',
        function () use ($in, $by) {
            $rows = $by(
                Health::grid(
                    $in([
                        'Search' => ['SearchURL' => 'https://play.example.org/helpers/query.php'],
                        'DataSnapshot' => ['DATA_SRV_a' => 'https://play.example.org/helpers/register.php'],
                        'Messaging' => ['OfflineMessageURL' => 'https://play.example.org/helpers/offline.php'],
                    ]),
                ),
            );
            $tags = fn(string $section) => array_column($rows["OpenSim.ini $section"], 'tag');

            expect($tags('[Search]'))
                ->toBe(['without data', 'with valid XMLRPC request'])
                ->and($tags('[DataSnapshot]'))
                ->toBe(['without data', 'with valid query'])
                ->and($tags('[Messaging]'))
                ->toBe(['without data', 'with valid post data'])
                // The web search of Robust is not among them: it is another setting, in another file
                ->and(array_column($rows['OpenSim.ini [Search]'], 'label'))
                ->toBe(['SearchURL', 'SearchURL']);
        },
    );

    test(
        'have the web search where each file says it: search in get_grid_info and Robust, SearchURL in LoginService, the API in OpenSim.ini',
        function () use ($in, $live) {
            $groups = Health::grid(
                $in(
                    [
                        'GridInfoService' => ['search' => 'https://play.example.org/search/'],
                        'LoginService' => ['SearchURL' => 'https://play.example.org/search/'],
                        'Search' => ['SearchURL' => 'https://play.example.org/helpers/query.php'],
                    ],
                    ['live' => $live + ['search' => 'https://play.example.org/search/']],
                ),
            );
            $where = [];
            foreach ($groups as $group) {
                foreach ($group['rows'] as $row) {
                    if (in_array($row['label'], ['search', 'SearchURL'], true)) {
                        $where[] =
                            preg_replace(
                                '/\s+/',
                                ' ',
                                trim($group['file'] . ' ' . $group['title'] . ' ' . $row['label']),
                            ) .
                            ' ' .
                            ($row['tag'] ?? '');
                    }
                }
            }

            // Each in its own file and section, the two pages of the web tagged, the calls of the API as calls
            expect($where)->toBe([
                'get_grid_info search Web search',
                'Robust.HG.ini [GridInfoService] search Web search',
                'Robust.HG.ini [LoginService] SearchURL Web search',
                'OpenSim.ini [Search] SearchURL without data',
                'OpenSim.ini [Search] SearchURL with valid XMLRPC request',
            ]);
        },
    );

    test(
        'say SearchURL of the login service fine when it is search of the grid info, and ask it for what it is when it is another address',
        function () use ($in, $live) {
            $row = function (array $login, array $info) use ($in, $live) {
                $groups = Health::grid($in(['GridInfoService' => $info, 'LoginService' => $login], ['live' => $live]));
                $rows = array_values(
                    array_filter(
                        array_merge(
                            ...array_column(array_filter($groups, fn($g) => $g['title'] === '[LoginService]'), 'rows'),
                        ),
                        fn($r) => $r['label'] === 'SearchURL',
                    ),
                );

                return $rows[0];
            };

            expect(
                $row(
                    ['SearchURL' => 'https://play.example.org/search'],
                    ['search' => 'https://play.example.org/search/'],
                ),
            )
                ->toMatchArray(['level' => 'ok', 'status' => 'matches [GridInfoService] search'])
                ->not->toHaveKey('method')
                // Two addresses are allowed (the operator's), each is tested for what it is, and `search` is the one the grid says
                ->and($row(['SearchURL' => 'https://a.example.org/'], ['search' => 'https://b.example.org/']))
                ->toMatchArray(['method' => 'get', 'tag' => 'Web search', 'url' => 'https://a.example.org/'])
                ->not->toHaveKey('level')
                ->and($row(['SearchURL' => 'https://a.example.org/'], []))
                ->toMatchArray(['method' => 'get', 'tag' => 'Web search', 'url' => 'https://a.example.org/'])
                ->and($row([], ['search' => 'https://b.example.org/']))
                ->toMatchArray(['level' => 'unset', 'tag' => 'Web search']);
        },
    );

    test(
        'say whether the grid has the key of its currency, never what it is, and an error for a grid with a currency that has none',
        function () use ($in, $by) {
            $row = fn(array $sections) => array_column(
                $by(Health::grid($in($sections)))['MoneyServer.ini [MoneyServer]'],
                null,
                'label',
            )['MoneyScriptAccessKey'];
            $key = ['MoneyServer' => ['MoneyScriptAccessKey' => 'a-secret-key']];

            expect($row($key))
                ->toMatchArray(['kind' => 'secret', 'set' => true, 'level' => 'ok', 'status' => 'set'])
                ->and(json_encode($row($key)))
                ->not->toContain('a-secret-key')
                ->and($row(['Economy' => ['economymodule' => 'Gloebit']]))
                ->toMatchArray(['level' => 'error', 'status' => 'missing'])
                // A grid without a currency needs none, and says nothing
                ->and($row(['Economy' => ['economymodule' => 'BetaGridLikeMoneyModule']]))
                ->toMatchArray(['level' => 'unset', 'reason' => 'health_unset']);
        },
    );
});

describe('What an install needs', function () {
    test('is said by what it is, each its own fields and its own words: an extension installed, a folder writable, a file readable', function () {
        $dir = sys_get_temp_dir() . '/health-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/Robust.ini", '');
        $extensions = array_column(Health::extensions(), null, 'label');
        $gettext = $extensions['gettext'];
        $folder = Health::writable('tasks', $dir);
        $file = Health::files(["$dir/Robust.ini", "$dir/absent.ini"]);

        expect($gettext)
            ->toMatchArray(['kind' => 'requirement', 'installed' => true, 'level' => 'ok', 'version' => phpversion('gettext')])
            ->and($gettext['status'])
            ->toBe('installed ' . phpversion('gettext'))
            ->and($folder)
            ->toMatchArray(['kind' => 'folder', 'path' => $dir, 'exists' => true, 'writable' => true, 'level' => 'ok', 'status' => 'writable'])
            ->and($file)
            ->toBe([['label' => 'Robust.ini', 'kind' => 'file', 'path' => "$dir/Robust.ini", 'readable' => true, 'level' => 'ok', 'status' => 'readable']])
            // What is not an address has none, nor a time
            ->and([...array_values($extensions), $folder, ...$file])
            ->each->not->toHaveKeys(['url', 'ms']);
    });

    test('says whether the pictures of the world can be shown: imagick that reads JPEG 2000', function () {
        $row = array_column(Health::extensions(), null, 'label')['imagick JPEG 2000'] ?? null;
        $reads = class_exists('Imagick') && Imagick::queryFormats('JP2') !== [];

        expect($row)
            ->toMatchArray(['kind' => 'requirement', 'installed' => $reads, 'level' => $reads ? 'ok' : 'error'])
            ->and($row['detail'] ?? '')
            ->toContain('pictures of the world');
    });

    test('says a setting that is not given apart: not set, so neither served nor tested, which is not a wrong one', function () {
        $unset = Health::emptyRow('DirectoryURL');
        $groups = static fn(string ...$levels): array => [['rows' => array_map(static fn(string $level): array => ['level' => $level], $levels)]];

        expect($unset)
            ->toBe(['label' => 'DirectoryURL', 'kind' => 'address', 'level' => 'unset', 'reason' => 'health_unset'])
            ->and(W4OS\Engine\I18n\Catalog::MESSAGES['health_unset'] ?? null)
            ->toBe('Not set: neither served nor tested.')
            ->and([Health::level($groups('unset', 'ok')), Health::level($groups('unset'))])
            ->toBe(['ok', 'none']);
    });
});

describe('The health in the contract', function () {
    test('says each field of a row, the certificate too, and the level of all: an error first, then slow, then fine', function () {
        $schema = W4OS\Engine\Api\Contract::document()['paths']['/grids/{grid}/health']['get']['responses']['200']['content'][W4OS\Engine\Api\Response::JSON_API]['schema'];
        $attributes = $schema['properties']['data']['properties']['attributes']['properties'];
        $row = $attributes['groups']['items']['properties']['rows']['items']['properties'];
        $groups = static fn(string ...$levels): array => [['rows' => array_map(static fn(string $level): array => ['level' => $level], $levels)]];

        expect($attributes['level']['enum'])
            ->toBe(['ok', 'slow', 'error', 'none'])
            ->and(array_keys($row))
            ->toContain('label', 'url', 'text', 'display', 'tag', 'level', 'status', 'ms', 'detail', 'cert')
            ->and($row['cert']['description'] ?? '')
            ->toContain('certificate')
            ->and($row['level']['enum'])
            ->toBe(['ok', 'slow', 'error', 'unset', 'none'])
            ->and($row['kind']['enum'])
            ->toBe(['address', 'value', 'secret', 'requirement', 'folder', 'file', 'option'])
            ->and([Health::level($groups('ok', 'slow', 'error')), Health::level($groups('ok', 'slow')), Health::level($groups('ok', 'none')), Health::level($groups('none'))])
            ->toBe(['error', 'slow', 'ok', 'none']);
    });
});

describe('A row of the health', function () {
    test('has the name and the description of its setting in the catalog, and says a service that needs an application of its own', function () {
        $groups = Health::describe([
            ['file' => 'get_grid_info', 'title' => '', 'rows' => [['label' => 'welcome', 'level' => 'ok'], ['label' => 'gridname', 'level' => 'none']]],
            ['file' => 'Robust.HG.ini', 'title' => '[LoginService]', 'rows' => [['label' => 'DestinationGuide', 'level' => 'ok'], ['label' => 'SearchURL', 'level' => 'ok']]],
            ['file' => 'OpenSim.ini', 'title' => '[Search]', 'rows' => [
                ['label' => 'SearchURL', 'url' => 'https://elsewhere.example.org/query.php', 'level' => 'error'],
                ['label' => 'SearchURL', 'url' => 'https://grid.example.org/helpers/query.php', 'level' => 'error'],
            ]],
            ['file' => 'OpenSim.ini', 'title' => '[DataSnapshot]', 'rows' => [['label' => 'DATA_SRV_demo', 'url' => 'https://grid.example.org/helpers/register.php', 'level' => 'ok']]],
            ['file' => 'pages', 'title' => '', 'rows' => [['label' => 'profile', 'level' => 'ok']]],
        ], static fn(string $url): bool => str_starts_with($url, 'https://grid.example.org/helpers/'));
        $rows = array_merge(...array_column($groups, 'rows'));
        $names = array_column($rows, 'name');
        $keys = [...$names, ...array_filter(array_column($rows, 'description')), 'health_third_party'];

        expect($names)
            ->toBe(['setting_welcome', 'setting_destination_guide', 'setting_web_search', 'setting_search_service', 'setting_search_service', 'setting_data_service', 'setting_web_profile'])
            ->and($rows[1])
            ->not->toHaveKey('name')
            ->and($rows[4]['description'])
            ->toBe('setting_search_service_help')
            // An application of its own: an address that does not answer, not the helpers of the grid
            ->and([$rows[4]['thirdParty'] ?? false, $rows[5]['thirdParty'] ?? false, $rows[6]['thirdParty'] ?? false])
            ->toBe([true, false, false])
            ->and(array_diff($keys, array_keys(W4OS\Engine\I18n\Catalog::MESSAGES)))
            ->toBe([])
            ->and(W4OS\Engine\I18n\Catalog::messages('fr_FR')['setting_search_service'] ?? '')
            ->not->toBe('');
    });
});

describe('A wrong or missing address', function () {
    test('says what it should be and the line of its file to write, as the options of the grid write it', function () {
        $expected = [
            'Robust' => ['GridInfoService' => ['welcome' => '"${Const|WebURL}/helpers/welcome"', 'economy' => '"${Const|WebURL}/helpers"']],
            'OpenSim' => ['Search' => ['SearchURL' => '"https://grid.example.org/helpers/query.php"'], 'DataSnapshot' => ['DATA_SRV_demo' => '"https://grid.example.org/helpers/register.php"']],
        ];
        $groups = Health::expect([
            ['file' => 'Robust.HG.ini', 'title' => '[GridInfoService]', 'rows' => [
                ['label' => 'welcome', 'url' => 'https://grid.example.org/old/welcome', 'level' => 'error'],
                ['label' => 'economy', 'url' => 'https://grid.example.org/helpers', 'level' => 'error'],
            ]],
            ['file' => 'OpenSim.ini', 'title' => '[Search]', 'rows' => [['label' => 'SearchURL', 'url' => '', 'level' => 'none']]],
            ['file' => 'OpenSim.ini', 'title' => '[DataSnapshot]', 'rows' => [['label' => 'DATA_SRV_*', 'url' => '', 'level' => 'none']]],
            ['file' => 'get_grid_info', 'title' => '', 'rows' => [['label' => 'welcome', 'url' => 'https://grid.example.org/old/welcome', 'level' => 'error']]],
        ], $expected, ['WebURL' => 'https://grid.example.org']);
        $rows = array_merge(...array_column($groups, 'rows'));

        expect($rows[0])
            ->toMatchArray([
                'expected' => 'https://grid.example.org/helpers/welcome',
                'snippet' => "[GridInfoService]\n    welcome = \"\${Const|WebURL}/helpers/welcome\"",
            ])
            // What answers wrong at the right address is not a setting to change
            ->and($rows[1])
            ->not->toHaveKey('expected')
            ->and($rows[2])
            ->toMatchArray(['expected' => 'https://grid.example.org/helpers/query.php', 'snippet' => "[Search]\n    SearchURL = \"https://grid.example.org/helpers/query.php\""])
            ->and($rows[3]['snippet'] ?? null)
            ->toBe("[DataSnapshot]\n    DATA_SRV_demo = \"https://grid.example.org/helpers/register.php\"")
            // What the grid says is not a file: its config is
            ->and($rows[4])
            ->not->toHaveKey('expected');
    });

    test('is the one the options of the grid give: what they write in Robust and in OpenSim.ini', function () {
        $machine = new W4OS\Engine\Testing\Machine();
        $expected = (new W4OS\Engine\Provisioning\GridOptions(W4OS\Engine\Models\Grid::find($machine->environment, 'demo')))->expected();

        expect($expected['Robust']['LoginService']['DestinationGuide'] ?? null)
            ->toBe('"${Const|WebURL}/guide"')
            ->and($expected['OpenSim']['Messaging']['OfflineMessageModule'] ?? null)
            ->toBe('"Offline Message Module V2"')
            ->and($expected['Robust'])
            ->not->toHaveKey('ServiceList');
    });
});

describe('The last check of the health', function () {
    test('is kept with its time and given again, checked anew on demand or by its task in the background', function () {
        $machine = new W4OS\Engine\Testing\Machine();
        $machine->clock->now = 1_800_000_000.0;
        // The search database of the grid, which keeps its cache
        $search = new W4OS\Engine\Data\OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        W4OS\Engine\Data\Connections::using(static fn() => $search);
        $router = new W4OS\Engine\Api\Router(new W4OS\Engine\Api\Api($machine->environment));
        $attributes = static fn(array $query = []) => $router->get('/grids/demo/health', $query)->document['data']['attributes'];

        $first = $attributes();
        $machine->clock->now += 600;
        $kept = $attributes();
        $asked = $attributes(['refresh' => '1']);
        $machine->clock->now += 1000;
        $scheduler = W4OS\Engine\Scheduling\Scheduler::for($machine->environment);
        W4OS\Engine\Services\SearchTasks::add($scheduler, $machine->environment, W4OS\Engine\Config\GridConfig::fromFolder($machine->grid));
        $scheduler->run('health.demo');
        $background = $attributes();
        W4OS\Engine\Data\Connections::using(null);

        $lineTimes = static fn(array $check): array => array_values(array_unique(array_column(array_merge(...array_column($check['groups'], 'rows')), 'checkedAt')));

        expect([$first['checkedAt'], $first['cached']])
            ->toBe([1_800_000_000, false])
            // Every line says when it was checked: all at once here
            ->and($lineTimes($first))
            ->toBe([1_800_000_000])
            ->and([$kept['checkedAt'], $kept['cached'], $kept['groups']])
            ->toBe([1_800_000_000, true, $first['groups']])
            // Asked anew (refresh): checked now, not the last check kept
            ->and([$asked['checkedAt'], $asked['cached'], $lineTimes($asked)])
            ->toBe([1_800_000_600, false, [1_800_000_600]])
            ->and([$background['checkedAt'], $background['cached']])
            ->toBe([1_800_001_600, true])
            ->and(array_column($scheduler->status(), 'id'))
            ->toContain('health.demo');
    });
});

describe('The same address', function () {
    test('is asked once, its answer given to every row that asks it', function () {
        [$process, $port, $dir] = code_server(4);
        $row = ['label' => 'page', 'url' => "http://127.0.0.1:$port/busy?same", 'method' => 'get'];
        $answered = Health::answers([['title' => 'One', 'rows' => [$row, $row]], ['title' => 'Two', 'rows' => [$row]]]);
        $max = (int) explode(' ', (string) file_get_contents("$dir/busy"))[1];
        proc_terminate($process);

        expect([$answered[0]['rows'][0]['level'], $answered[0]['rows'][1]['level'], $answered[1]['rows'][0]['level']])
            ->toBe(['ok', 'ok', 'ok'])
            ->and($max)
            ->toBe(1);
    });
});

describe('The health, line by line', function () {
    test('lists every check at once with its id and the last result, then checks one on demand, which the list keeps', function () {
        $machine = new W4OS\Engine\Testing\Machine();
        $machine->clock->now = 1_800_000_000.0;
        $search = new W4OS\Engine\Data\OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        W4OS\Engine\Data\Connections::using(static fn() => $search);
        $router = new W4OS\Engine\Api\Router(new W4OS\Engine\Api\Api($machine->environment));

        $list = $router->get('/grids/demo/health')->document['data']['attributes'];
        $rows = array_merge(...array_column($list['groups'], 'rows'));
        $asked = array_values(array_filter($rows, static fn(array $row): bool => ($row['url'] ?? '') !== ''))[0];
        $machine->clock->now += 60;
        $one = $router->get('/grids/demo/health/' . $asked['id']);
        $kept = array_column(array_merge(...array_column($router->get('/grids/demo/health')->document['data']['attributes']['groups'], 'rows')), null, 'id')[$asked['id']];
        $unknown = $router->get('/grids/demo/health/nothing');
        $operation = W4OS\Engine\Api\Contract::document()['paths']['/grids/{grid}/health/{check}']['get'] ?? [];
        W4OS\Engine\Data\Connections::using(null);

        expect(count(array_unique(array_column($rows, 'id'))))
            ->toBe(count($rows))
            ->and($one->status)
            ->toBe(200)
            ->and($one->document['data'])
            ->toMatchArray(['type' => 'health-checks', 'id' => $asked['id']])
            ->and($one->document['data']['attributes'])
            ->toMatchArray(['label' => $asked['label'], 'url' => $asked['url'], 'checkedAt' => 1_800_000_060])
            ->and($kept['checkedAt'] ?? null)
            ->toBe(1_800_000_060)
            ->and($unknown->status)
            ->toBe(404)
            ->and([$operation['operationId'] ?? null, $operation['x-access']['level'] ?? null])
            ->toBe(['getHealthCheck', 100]);
    });
});

describe('A setting of the health', function () {
    test('names its full context: its instance, its file, its section, its key', function () {
        $groups = Health::describe([
            ['file' => 'Robust.HG.ini', 'title' => '[LoginService]', 'rows' => [['label' => 'SearchURL', 'level' => 'ok']]],
            ['file' => 'OpenSim.ini', 'title' => '[Search]', 'rows' => [['label' => 'SearchURL', 'level' => 'ok']]],
            ['file' => 'get_grid_info', 'title' => '', 'rows' => [['label' => 'search', 'level' => 'ok']]],
            ['file' => 'PHP', 'title' => '', 'rows' => [['label' => 'gettext', 'level' => 'ok']]],
        ]);

        expect(array_column(array_merge(...array_column($groups, 'rows')), 'context'))
            ->toBe([
                ['instance' => 'robust', 'file' => 'Robust.HG.ini', 'section' => 'LoginService', 'key' => 'SearchURL'],
                ['instance' => 'opensim', 'file' => 'OpenSim.ini', 'section' => 'Search', 'key' => 'SearchURL'],
                ['instance' => 'grid_info', 'file' => 'get_grid_info', 'key' => 'search'],
            ])
            // The same key, two things: the web search of the viewers, the search the simulators ask
            ->and(array_column(array_merge(...array_column($groups, 'rows')), 'name'))
            ->toBe(['setting_web_search', 'setting_search_service', 'setting_web_search']);
    });
});

describe('The words of the health', function () {
    test('are the ones of w4os-wp and its translations, where w4os-wp has them', function () {
        $en = W4OS\Engine\I18n\Catalog::MESSAGES;
        $fr = W4OS\Engine\I18n\Catalog::messages('fr_FR');

        expect([$en['setting_welcome'], $en['setting_login'], $en['setting_destination_guide'], $en['setting_search_service'], $en['health_third_party']])
            ->toBe(['Splash', 'Login URI', 'Destinations Guide', 'Search Service', 'This service requires a separate web application.'])
            ->and([$fr['setting_register'], $fr['setting_offline_messages'], $fr['health_third_party']])
            ->toBe(["Page d'enregistrement", 'Messages hors-ligne', 'Ce service requiert une application web distincte.']);
    });
});

describe('The helpers of a grid', function () {
    test('are the pages Robust sets: a page set only in the files of the helpers is not asked', function () {
        $pages = [
            ['page' => 'guide', 'url' => 'https://grid.example.org/guide', 'source' => 'config', 'servedBy' => 'site', 'keys' => ['[LoginService] DestinationGuide']],
            ['page' => 'directory', 'url' => 'https://grid.example.org/helpers/directory', 'source' => 'config', 'servedBy' => 'helpers', 'keys' => ['[Helpers] DirectoryURL']],
        ];

        expect(array_column(Health::configured($pages, [], static fn(string $url): bool => false), 'source', 'page'))
            ->toBe(['guide' => 'config', 'directory' => 'default']);
    });

    test('a grid with none is healthy, nothing of them asked; one set and down is an error', function () {
        $dir = sys_get_temp_dir() . '/health-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/Robust.ini", "[GridInfoService]\n gridname = \"Bare\"\n");
        $bare = Health::answers([...Health::grid(['config' => GridConfig::fromFolder($dir)]), ['file' => 'pages', 'title' => '', 'rows' => Health::pages([['page' => 'helpers', 'url' => 'https://bare.example.org/helpers', 'source' => 'default', 'servedBy' => 'helpers']])]]);
        $rows = array_merge(...array_column($bare, 'rows'));
        file_put_contents("$dir/Robust.ini", "[GridInfoService]\n gridname = \"Down\"\n economy = \"http://127.0.0.1:9/helpers\"\n");
        $down = array_column(array_column(Health::answers(Health::grid(['config' => GridConfig::fromFolder($dir)])), null, 'title')['[GridInfoService]']['rows'], null, 'label');

        expect(Health::level($bare))
            ->not->toBe('error')
            ->and(array_filter($rows, static fn(array $row): bool => isset($row['ms']) && $row['ms'] !== null))
            ->toBe([])
            ->and(array_unique(array_column(array_filter($rows, static fn(array $row): bool => ($row['kind'] ?? '') === 'address'), 'level')))
            ->toBe(['unset'])
            ->and($down['economy'])
            ->toMatchArray(['level' => 'error', 'status' => 'cURL error 7']);
    });
});

describe('A page the site serves', function () {
    test('must show the block of the helpers it is the page of: a page without it is wrong, whatever it answers', function () {
        [$server, $port] = code_server();
        $base = "http://127.0.0.1:$port";
        $pages = Health::pages([
            ['page' => 'guide', 'url' => "$base/block/destination_guide", 'servedBy' => 'site', 'block' => 'destination_guide'],
            ['page' => 'search', 'url' => "$base/200", 'servedBy' => 'site', 'block' => 'search'],
            ['page' => 'status', 'url' => "$base/200", 'servedBy' => 'helpers', 'block' => 'grid-status'],
        ]);
        $rows = array_column(Health::answers([['file' => 'pages', 'title' => '', 'rows' => $pages]])[0]['rows'], null, 'label');
        proc_terminate($server);

        expect([$rows['guide']['level'], $rows['search']['level'], $rows['status']['level']])
            ->toBe(['ok', 'error', 'ok'])
            ->and($rows['search']['detail'] ?? '')->toContain('search')
            ->and($rows['search']['block'] ?? null)->toBe('search')
            // What it looks for in the page is the engine's business
            ->and($rows['guide'])->not->toHaveKey('contains');
    });
});

