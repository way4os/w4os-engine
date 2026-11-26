<?php
/**
 * The web side of a grid: where its services are, its Helpers.ini, what the web server needs.
 */

use W4OS\Engine\Provisioning\Web\HelpersConfig;
use W4OS\Engine\Provisioning\Web\Services;
use W4OS\Engine\Provisioning\Web\PhpPools;
use W4OS\Engine\Provisioning\Web\Snippets;

/** Whether the file system holds two files whose names differ by the case only. */
function web_test_fs_tells_cases(): bool
{
    $dir = test_tmp() . '/case-' . bin2hex(random_bytes(4));
    mkdir($dir);
    touch("$dir/a");
    touch("$dir/A");

    return count(scandir($dir)) === 4;
}

describe('Services', function () {
    test('are under /helpers by default', function () {
        $services = new Services();

        expect($services->path('query'))->toBe('/helpers/query.php');
        expect($services->path('currency'))->toBe('/helpers/currency.php');
        expect($services->url('https://play.example.org/', 'offline'))->toBe(
            'https://play.example.org/helpers/offline.php',
        );
    });

    test('follow the path of the economy', function () {
        $services = new Services('helper/');

        expect($services->path('currency'))->toBe('/helper/currency.php');
        expect($services->path('query.php'))->toBe('/helper/query.php');
        expect($services->path('motd'))->toBe('/helper/motd.php');
        expect($services->path('guide'))->toBe('/helper/guide');
    });

    test('have the root as the path of an address without one, never the address itself', function () {
        expect(array_map(Services::pathOf(...), ['https://example.com', 'https://example.com/', 'http://example.com:8080', '${Const|WebURL}', '/']))
            ->toBe(['/', '/', '/', '/', '/']);
    });

    test('have the path of an address of the Robust config, with its constants', function () {
        expect(Services::pathOf('${Const|WebURL}/helper/'))
            ->toBe('/helper')
            ->and(Services::pathOf('https://play.example.org/currency'))
            ->toBe('/currency')
            ->and(Services::pathOf('${Const|WebURL}'))
            ->toBe('/');
    });
});

describe('Helpers.ini', function () {
    test(
        'is a template that sets nothing: every setting is commented out, none is one of the official files',
        function () {
            $text = (string) file_get_contents(dirname(__DIR__, 2) . '/share/presets/helpers/Helpers.ini');

            expect($text)->toContain('[Helpers]');
            expect($text)->toContain(';; GuideSource = "destinations.txt"');
            expect($text)->toContain(';; SearchConnectionString = ');
            // The currency is the business of the modules (Gloebit.ini...), not of this file
            expect($text)->not->toMatch('/Currency/');
            // What has its place in OpenSim.ini or Robust.ini is not copied there
            expect($text)->not->toMatch('/^\s*(grid_name|login_uri|web_url|gridname|ConnectionString)\b/m');
            expect($text)->not->toMatch('/^\[(robust_db|Urls)\]/m');
        },
    );

    test('is the name of the file, in the folder of the grid', function () {
        expect(HelpersConfig::path('/etc/opensim/grids/alpha/'))->toBe('/etc/opensim/grids/alpha/Helpers.ini');
    });

    test('is the only name in the folder of the grid: helpers.ini of the first setups becomes it', function () {
        $dir = test_tmp() . '/helpers-case-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/helpers.ini", "[Helpers]\npath = \"/helper\"\n");

        expect(HelpersConfig::unifyCase($dir))
            ->toBe([['helpers.ini', 'Helpers.ini']])
            ->and(scandir($dir))
            ->toContain('Helpers.ini')
            ->not->toContain('helpers.ini')
            ->and(file_get_contents("$dir/Helpers.ini"))
            ->toContain('/helper')
            ->and(HelpersConfig::unifyCase($dir))
            ->toBe([]);
    });

    test('keeps the second file aside when the folder has both, where the system tells the cases apart', function () {
        $dir = test_tmp() . '/helpers-both-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/Helpers.ini", 'new');
        file_put_contents("$dir/helpers.ini", 'old');

        expect(HelpersConfig::unifyCase($dir))
            ->toBe([['helpers.ini', 'helpers.ini~']])
            ->and(file_get_contents("$dir/Helpers.ini"))
            ->toBe('new')
            ->and(file_get_contents("$dir/helpers.ini~"))
            ->toBe('old');
    })->skip(fn() => !web_test_fs_tells_cases(), 'the file system does not tell the cases apart');

    test(
        'gives the web address and the path of the helpers from the Robust config, their default path whatever the economy',
        function () {
            $dir = test_tmp() . '/helpers-ini-' . bin2hex(random_bytes(4));
            mkdir($dir);
            file_put_contents(
                "$dir/Robust.HG.ini",
                "[Const]\n    WebURL = \"https://play.example.org\"\n[GridInfoService]\n    economy = \"\${Const|WebURL}/helper\"\n",
            );

            expect(HelpersConfig::webUrl($dir))
                ->toBe('https://play.example.org')
                ->and(HelpersConfig::basePath($dir))
                ->toBe('/helpers')
                ->and(HelpersConfig::services($dir)->path('currency'))
                ->toBe('/helpers/currency.php');

            $bare = test_tmp() . '/helpers-ini-' . bin2hex(random_bytes(4));
            mkdir($bare);
            expect(HelpersConfig::basePath($bare))->toBe('/helpers')->and(HelpersConfig::webUrl($bare))->toBe('');
        },
    );
});

describe('Pages', function () {
    test('have a default path', function () {
        expect((new Services())->path('home'))
            ->toBe('/helpers')
            ->and((new Services())->path('welcome'))
            ->toBe('/helpers/welcome')
            ->and((new Services())->path('health'))
            ->toBe('/helpers/health')
            ->and((new Services('/'))->path('home'))
            ->toBe('/')
            ->and((new Services('/'))->path('search'))
            ->toBe('/search');
    });
});

describe('Web server snippets', function () {
    test('renders the selected preset with grid and runtime paths', function () {
        $dir = test_tmp() . '/web-snippet-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/Robust.ini", "[Const]\nWebURL = \"https://grid.example.test\"\n");

        $snippet = Snippets::render('caddy', 'test_grid', $dir, '/srv/w4os-helpers/html', '/run/php/test.sock');

        expect($snippet)
            ->toContain('grid.example.test')
            ->toContain('/srv/w4os-helpers/html')
            ->toContain('/run/php/test.sock')
            ->toContain('OPENSIM_GRID test_grid')
            ->not->toContain('{{');
    });

    test('under a prefix, takes the paths of the helpers under it alone, each by its own segment, never the whole prefix', function () {
        $dir = test_tmp() . '/web-snippet-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/Robust.ini", "[Const]\nWebURL = \"https://grid.example.test\"\n");
        // The helpers of the grid under the path of its mini site
        file_put_contents("$dir/Helpers.ini", "[Helpers]\nHelpersURL = \"https://grid.example.test/opensim/economy\"\n");

        $root = Snippets::render('caddy', 'test_grid', $dir);
        $prefixed = Snippets::render('caddy', 'test_grid', $dir, prefix: '/opensim');
        $matcher = static fn(string $snippet): string => (string) preg_replace('/^.*@opensim_helpers path ([^\n]*)\n.*$/s', '$1', $snippet);

        // At the root, their base exactly, never its whole first segment (the mini site under /opensim)
        expect($matcher($root))->toContain('/opensim/economy /opensim/economy/*')->not->toContain('/opensim*')
            // Under the prefix, by the one after it: the mini site under /opensim is not theirs, nor what stays at the root (/health)
            ->and($matcher($prefixed))->toBe('/opensim/economy /opensim/economy/*')
            ->and(Snippets::render('caddy', 'test_grid', $dir, prefix: 'opensim/'))->toBe($prefixed);
    });

    test('rejects an unknown web server', function () {
        expect(fn() => Snippets::render('unknown', 'test_grid', '/tmp/grid'))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('The engine API for the sites of this machine', function () {
    test('runs as the user of the instances: a PHP-FPM pool of its own, its socket for the web server only', function () {
        $pool = Snippets::pool('opensim', 'caddy', '/run/php/w4os-api.sock');

        expect($pool)
            ->toContain('[w4os-api]', 'user = opensim', 'group = opensim', 'listen = /run/php/w4os-api.sock', 'listen.owner = opensim', 'listen.group = caddy', 'listen.mode = 0660')
            ->not->toContain('{{');
    });

    test('the helpers run in a pool of their own, as the web user and its group, whatever group the host pool has', function () {
        $pool = Snippets::helpersPool('www-data', 'www-data', 'caddy');

        expect($pool)
            ->toContain('[w4os-helpers]', 'user = www-data', 'group = www-data', 'listen = /run/php/w4os-helpers.sock', 'listen.owner = www-data', 'listen.group = caddy', 'pm = ondemand')
            ->not->toContain('{{', '[w4os-api]');
    });

    test('both pools written by one function, into the pools of the newest PHP-FPM, only when they change', function () {
        $etc = sys_get_temp_dir() . '/pools-' . getmypid();
        mkdir("$etc/8.2/fpm/pool.d", 0o755, true);
        mkdir("$etc/8.4/fpm/pool.d", 0o755, true);
        file_put_contents("$etc/8.4/fpm/pool.d/www.conf", "[www]\nuser = www-data\ngroup = magic\nlisten = /run/php/php8.4-fpm.sock\n");
        $texts = ['w4os-api' => Snippets::pool('opensim', 'caddy'), 'w4os-helpers' => Snippets::helpersPool('www-data', 'www-data', 'caddy')];

        $folder = PhpPools::folder($etc);
        $first = PhpPools::write($folder, $texts);
        $again = PhpPools::write($folder, $texts);

        expect($folder)->toBe("$etc/8.4/fpm/pool.d")
            ->and($first)->toBe(["$folder/w4os-api.conf", "$folder/w4os-helpers.conf"])
            ->and($again)->toBe([])
            ->and(PhpPools::installed('w4os-helpers', $etc))->toBeTrue()
            ->and(PhpPools::listenGroup("$folder/w4os-api.conf"))->toBe('caddy')
            ->and(file_get_contents("$folder/www.conf"))->toContain('group = magic');
        exec('rm -rf ' . escapeshellarg($etc));
    });

    test('without PHP-FPM there is no folder of pools, and nothing is written', function () {
        expect(PhpPools::folder(sys_get_temp_dir() . '/no-php-' . getmypid()))->toBe('');
    });

    test('the files of the helpers point at their pool once it is installed, else at the socket given', function () {
        $etc = sys_get_temp_dir() . '/pools-render-' . getmypid();
        mkdir("$etc/8.4/fpm/pool.d", 0o755, true);
        $grid = sys_get_temp_dir() . '/grid-render-' . getmypid();
        mkdir($grid, 0o755, true);
        putenv("W4OS_PHP_ETC=$etc");
        $before = Snippets::render('caddy', 'test_grid', $grid, socket: '/run/php/php8.4-fpm.sock');
        $apacheBefore = Snippets::render('apache', 'test_grid', $grid);
        file_put_contents("$etc/8.4/fpm/pool.d/w4os-helpers.conf", Snippets::helpersPool('www-data', 'www-data', 'caddy'));
        $after = Snippets::render('caddy', 'test_grid', $grid, socket: '/run/php/php8.4-fpm.sock');
        $apache = Snippets::render('apache', 'test_grid', $grid);
        putenv('W4OS_PHP_ETC');
        exec('rm -rf ' . escapeshellarg($etc) . ' ' . escapeshellarg($grid));

        expect($before)->toContain('unix//run/php/php8.4-fpm.sock')
            ->and($after)->toContain('unix//run/php/w4os-helpers.sock')->not->toContain('php8.4-fpm')
            ->and($apacheBefore)->not->toContain('SetHandler')
            ->and($apache)->toContain('SetHandler "proxy:unix:/run/php/w4os-helpers.sock|fcgi://w4os-helpers"');
    });

    test('is served on 127.0.0.1 only, its paths and no other, through that pool, by each web server', function () {
        foreach (Snippets::SERVERS as $server) {
            $snippet = Snippets::api($server, 'test_grid', '/srv/w4os-helpers/html', '/run/php/w4os-api.sock', 8005);

            expect($snippet)
                ->toContain('127.0.0.1:8005', '/run/php/w4os-api.sock', '/srv/w4os-helpers/html', 'test_grid', '/api')
                ->not->toContain('{{');
        }
        expect(fn() => Snippets::api('unknown', 'test_grid', '', Snippets::API_SOCKET, 8005))->toThrow(InvalidArgumentException::class);
    });

    test('goes to the entry point of the engine itself by default, the helpers to theirs as their preset says', function () {
        $dir = test_tmp() . '/snippet-roots-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/Robust.ini", "[Const]\nWebURL = \"https://grid.example.test\"\n");

        expect(Snippets::engineRoot())
            ->toBe(dirname(__DIR__, 2) . '/public')
            ->and(is_file(Snippets::engineRoot() . '/index.php'))
            ->toBeTrue()
            ->and(Snippets::api('nginx', 'test_grid', '', Snippets::API_SOCKET, 8005))
            ->toContain(Snippets::engineRoot() . '/index.php')
            ->not->toContain('w4os-helpers')
            ->and(Snippets::helpersRoot())
            ->toBe('/usr/share/w4os-helpers/public')
            ->and(Snippets::render('nginx', 'test_grid', $dir))
            ->toContain('/usr/share/w4os-helpers/public/index.php')
            ->not->toContain('/html');
    });
});
