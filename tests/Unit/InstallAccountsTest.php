<?php

declare(strict_types=1);

/**
 * The accounts of an install: the units of the suite run as its SystemUser (ServiceUnits), the account a web server serves a site
 * as (WebServerAccount), and what w4os.conf takes of an install that exists (InstallAccount).
 */

use W4OS\Engine\Config\Layout;
use W4OS\Engine\Provisioning\InstallAccount;
use W4OS\Engine\Provisioning\NullUi;
use W4OS\Engine\Provisioning\ServiceUnits;
use W4OS\Engine\Provisioning\SystemLayout;
use W4OS\Engine\Provisioning\WebServerAccount;

/** A folder of its own for a test. */
function accounts_dir(string $name): string
{
    $dir = test_tmp() . "/$name-" . bin2hex(random_bytes(4));
    mkdir($dir, 0o755, true);

    return $dir;
}

/** Write a file, its folders made. */
function accounts_write(string $file, string $text): void
{
    is_dir(dirname($file)) || mkdir(dirname($file), 0o755, true);
    file_put_contents($file, $text);
}

describe('The units of the suite', function () {
    test('run as the account of the install: a drop-in of each, systemd told once, nothing written again', function () {
        $dir = accounts_dir('units');
        $commands = [];
        $units = new ServiceUnits($dir, function (string $command) use (&$commands): int {
            $commands[] = $command;

            return 0;
        }, true);

        $first = $units->apply('durant', 'durant');
        $written = file_get_contents("$dir/opensim.service.d/" . ServiceUnits::DROP_IN);
        $again = $units->apply('durant', 'durant');
        $changed = $units->apply('dupneu', 'web');

        expect($first)->toHaveCount(count(ServiceUnits::UNITS))
            ->and($written)->toContain("[Service]\nUser=durant\nGroup=durant\n")
            ->and(is_file("$dir/opensim-backup-oar.service.d/" . ServiceUnits::DROP_IN))->toBeTrue()
            ->and($again)->toBe([])
            ->and(file_get_contents("$dir/opensim-backup.service.d/" . ServiceUnits::DROP_IN))->toContain("User=dupneu\nGroup=web\n")
            ->and($changed)->toHaveCount(3)
            ->and($commands)->toBe(['systemctl daemon-reload', 'systemctl daemon-reload']);
    });

    test('follow the account of w4os.conf each time the layout is made, whichever it names', function () {
        $base = accounts_dir('layout');
        mkdir("$base/etc/w4os", 0o755, true);
        file_put_contents("$base/etc/w4os/w4os.conf", "[Defaults]\nSystemUser = durant\n");
        $units = new ServiceUnits("$base/systemd", static fn(): int => 0, false);
        $commands = [];
        $made = (new SystemLayout(new NullUi(), function (string $c) use (&$commands): int {
            $commands[] = $c;

            return 0;
        }, fn() => true, true, fn() => false, "$base/etc/w4os/w4os.conf", $units))->prepare(new Layout([
            'EtcRoot' => "$base/etc/opensim", 'VarRoot' => "$base/var", 'DataRoot' => "$base/var/data", 'LogsRoot' => "$base/log",
            'CacheRoot' => "$base/cache", 'CoreRoot' => "$base/core", 'SystemUser' => 'durant',
        ]));

        expect($made)->toBeTrue()
            ->and(file_get_contents("$base/systemd/opensim.service.d/" . ServiceUnits::DROP_IN))->toContain('User=durant')
            ->and(implode("\n", $commands))->toContain("chown 'durant':")
            ->and(implode("\n", $commands))->not->toContain("chown 'opensim'")
            // What the folders of the suite hold follows the account: its config, its state, its cron
            ->and(implode("\n", $commands))->toContain("find '$base/etc/opensim' -mindepth 1 ! -user 'durant'", "find '$base/var/cron' -mindepth 1 ! -user 'durant'");
    });
});

describe('The folders of the suite', function () {
    /** The layout made with these accounts, on its own folders: [the commands it ran, its folders and their accounts]. */
    $made = static function (string $system, string $web): array {
        $base = accounts_dir('folders');
        mkdir("$base/etc/w4os", 0o755, true);
        mkdir("$base/site/www", 0o755, true);
        $conf = "$base/etc/w4os/w4os.conf";
        file_put_contents($conf, "[Defaults]\nSystemUser = $system\nWebUser = $web\nWebGroup = $web\n");
        $layout = new Layout([
            'EtcRoot' => "$base/etc/opensim", 'VarRoot' => "$base/var", 'DataRoot' => "$base/var/data", 'LogsRoot' => "$base/log",
            'CacheRoot' => "$base/cache", 'CoreRoot' => "$base/core", 'BackupRoot' => "$base/backups", 'SiteRoot' => "$base/site",
            'SystemUser' => $system, 'WebUser' => $web, 'WebGroup' => $web,
        ]);
        $commands = [];
        (new SystemLayout(new NullUi(), function (string $c) use (&$commands): int {
            $commands[] = $c;

            return 0;
        }, fn() => true, true, fn() => false, $conf, new ServiceUnits("$base/systemd", static fn(): int => 0, false)))->prepare($layout);

        return [$commands, SystemLayout::folders($layout, $conf)];
    };

    test('each follows its account when it changes, the instances\' or the web server\'s, and no other folder', function () use ($made) {
        foreach ([['durant', 'webby'], ['durant', 'durant']] as [$system, $web]) {
            [$commands, $folders] = $made($system, $web);
            $followed = [];
            foreach ($commands as $command) {
                preg_match("/^find '([^']+)' -mindepth 1 ! -user '([^']+)'/", $command, $m) === 1 && $followed[$m[1]] = $m[2];
            }
            $expected = array_map(static fn(array $folder): string => $folder[0] === SystemLayout::SYSTEM ? $system : $web, $folders);

            expect($followed)->toEqualCanonicalizing($expected)
                ->and(array_keys($followed))->toEqualCanonicalizing(array_keys($folders))
                ->and(array_filter($folders, static fn(array $folder): bool => $folder[0] === SystemLayout::WEB))->toHaveCount(1);
        }
    });
});

describe('What a folder holds, given to its account', function () {
    test('is changed in batches, the files of the account left as they are, never one process a file', function () {
        $command = SystemLayout::follow('/var/lib/opensim/data', 'opensim', 'opensim');

        expect($command)->toStartWith("find '/var/lib/opensim/data' -mindepth 1 ! -user 'opensim'")
            ->toContain("-exec chown -h 'opensim:opensim' {} +", "! -user 'opensim' -exec chown -h 'opensim' {} +")
            ->not->toContain('sh -c')
            ->not->toContain('stat -c');
    });

    test('gives a former owner\'s own group with its files, and keeps another group', function () {
        $dir = accounts_dir('follow');
        mkdir("$dir/a", 0o755, true);
        file_put_contents("$dir/a/file", 'x');
        $me = (string) (posix_getpwuid(posix_geteuid())['name'] ?? '');
        $group = (string) (posix_getgrgid(posix_getegid())['name'] ?? '');
        // Everything is the account's already: nothing to do, nothing changed
        exec(SystemLayout::follow($dir, $me, $group), $out, $status);

        expect($status)->toBe(0)
            ->and(fileowner("$dir/a/file"))->toBe(posix_geteuid());
    })->skip(fn() => PHP_OS_FAMILY !== 'Linux', 'GNU find');
});

describe('The account a web server serves a site as', function () {
    test('is the AssignUserID of an Apache site of mpm-itk', function () {
        $etc = accounts_dir('etc');
        accounts_write("$etc/apache2/envvars", "export APACHE_RUN_USER=www-data\nexport APACHE_RUN_GROUP=www-data\n");
        accounts_write("$etc/apache2/sites-enabled/other.conf", "<VirtualHost *:80>\n  ServerName other.example.org\n</VirtualHost>\n");
        accounts_write("$etc/apache2/sites-enabled/w4os.conf", "<VirtualHost *:80>\n  ServerName w4os.example.org\n  ServerAlias www.w4os.example.org\n  AssignUserID magic magic\n</VirtualHost>\n");

        $web = new WebServerAccount($etc);

        expect($web->serving(['nowhere.example.org', 'www.w4os.example.org']))->toMatchArray(['user' => 'magic', 'group' => 'magic', 'server' => 'apache'])
            // A site of Apache without its own account is served as Apache's
            ->and($web->serving(['other.example.org']))->toMatchArray(['user' => 'www-data', 'group' => 'www-data'])
            ->and($web->serving(['nowhere.example.org']))->toBeNull();
    });

    test('is the account of the pool of PHP-FPM that nginx or Caddy sends its PHP to', function () {
        $etc = accounts_dir('etc');
        accounts_write("$etc/php/8.2/fpm/pool.d/kimbe.conf", "[kimbe]\nuser = dupneu\ngroup = dupneu\nlisten = /run/php/kimbe.sock\n");
        accounts_write("$etc/nginx/nginx.conf", "user www-data;\n");
        accounts_write("$etc/nginx/sites-enabled/kimbe", "server {\n  server_name kimbe.example.org;\n  location ~ \\.php$ {\n    fastcgi_pass unix:/run/php/kimbe.sock;\n  }\n}\n");
        accounts_write("$etc/caddy/Caddyfile", "grid.example.org {\n\troot * /srv/grid\n\tphp_fastcgi unix//run/php/kimbe.sock\n}\n");

        $web = new WebServerAccount($etc);

        expect($web->serving(['kimbe.example.org']))->toMatchArray(['user' => 'dupneu', 'group' => 'dupneu', 'server' => 'nginx'])
            ->and($web->serving(['grid.example.org']))->toMatchArray(['user' => 'dupneu', 'server' => 'caddy']);
    });
});

describe('What w4os.conf takes of an install that exists', function () {
    test('its owner and the account of its site, only what changes, written when asked', function () {
        $dir = accounts_dir('conf');
        $conf = "$dir/w4os.conf";
        // A profile of a release has its own SystemUser, which the install reads over [Defaults]
        file_put_contents($conf, "[Defaults]\nSystemUser = nobody-here\nWebUser = www-data\nWebGroup = www-data\nDefaultProfile = opensim-0.9.3.0\n\n[opensim-0.9.3.0]\nSystemUser = opensim\n");
        file_put_contents("$dir/Robust.ini", "[Const]\n    BaseHostname = \"grid.example.org\"\n    WebURL = \"https://www.example.org/\"\n[GridInfoService]\n    homepage = \"https://www.example.org/welcome\"\n");

        $changes = InstallAccount::changes($conf, 'durant', ['user' => 'dupneu', 'group' => 'dupneu']);
        InstallAccount::adopt($conf, $changes);

        expect($changes)->toBe(['SystemUser' => ['opensim', 'durant'], 'WebUser' => ['www-data', 'dupneu'], 'WebGroup' => ['www-data', 'dupneu']])
            ->and(substr_count(file_get_contents($conf), 'SystemUser = durant'))->toBe(2)
            ->and(Layout::load($conf)->systemUser())->toBe('durant')
            ->and(file_get_contents($conf))->toContain('WebGroup = dupneu')
            ->and(InstallAccount::changes($conf, 'durant', null))->toBe([])
            ->and(InstallAccount::hosts("$dir/Robust.ini"))->toBe(['www.example.org', 'grid.example.org'])
            ->and(InstallAccount::owner("$dir/Robust.ini"))->toBe(posix_getpwuid(posix_geteuid())['name']);
    });
});
