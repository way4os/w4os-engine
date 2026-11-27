<?php

declare(strict_types=1);

use W4OS\Engine\Config\Layout;
use W4OS\Engine\Provisioning\NullUi;
use W4OS\Engine\Provisioning\SystemLayout;

function system_layout_roots(string $base): Layout
{
    return new Layout([
        'EtcRoot' => "$base/etc/opensim",
        'VarRoot' => "$base/var/lib/opensim",
        'DataRoot' => "$base/var/lib/opensim/data",
        'LogsRoot' => "$base/var/log/opensim",
        'CacheRoot' => "$base/var/cache/opensim",
        'CoreRoot' => "$base/core",
    ]);
}

describe('The system layout', function () {
    test('gives the folders to the caller where no account can be made, the core root with them, and names the caller in w4os.conf', function () {
        $base = sys_get_temp_dir() . '/syslayout-caller-' . bin2hex(random_bytes(4));
        $commands = [];
        $ui = new NullUi();
        $before = getenv('SUDO_USER');
        putenv('SUDO_USER=ada');
        try {
            $made = (new SystemLayout($ui, function (string $c) use (&$commands): int {
                $commands[] = $c;

                return 0;
            }, fn(string $user) => $user === 'ada', true, conf: "$base/etc/w4os/w4os.conf", canMake: fn() => false))->prepare(system_layout_roots($base));
            $none = (new SystemLayout(new NullUi(), fn(): int => 0, fn() => false, true, conf: "$base/none/w4os.conf", canMake: fn() => false))->prepare(system_layout_roots("$base/none"));
        } finally {
            putenv($before === false ? 'SUDO_USER' : "SUDO_USER=$before");
        }

        expect($made)->toBeTrue()
            ->and(implode("\n", $commands))->not->toContain('adduser', 'useradd')
            ->and(implode("\n", $commands))->toContain("chown 'ada':", "'$base/core'")
            ->and(is_dir("$base/core"))->toBeTrue()
            ->and(file_get_contents("$base/etc/w4os/w4os.conf"))->toContain('SystemUser = ada')
            ->and(implode("\n", array_column($ui->said, 1)))->toContain('No account can be made here: the folders are given to ada')
            // Nobody to give them to: said, nothing made
            ->and($none)->toBeFalse();
    });

    test('writes the folders a person placed in w4os.conf, in the profile in use too, and refuses what is not one', function () {
        $base = sys_get_temp_dir() . '/syslayout-roots-' . bin2hex(random_bytes(4));
        mkdir($base, 0o755, true);
        $conf = "$base/w4os.conf";
        file_put_contents($conf, "[Defaults]\nDefaultProfile = opensim-0.9.3.0\nEtcRoot = /etc/opensim\nDataRoot = /var/lib/opensim/data\nSystemUser = opensim\n\n[opensim-0.9.3.0]\nDataRoot = /var/lib/opensim/data\n");
        SystemLayout::setRoots($conf, ['DataRoot' => '/srv/opensim/data/', 'LogsRoot' => '/srv/opensim/log']);
        $layout = Layout::load($conf);
        $refused = static function (array $roots) use ($conf): string {
            try {
                SystemLayout::setRoots($conf, $roots);
            } catch (InvalidArgumentException $error) {
                return $error->getMessage();
            }

            return '';
        };

        expect($layout->dataRoot())->toBe('/srv/opensim/data')
            ->and($layout->logsRoot())->toBe('/srv/opensim/log')
            ->and($layout->etcRoot())->toBe('/etc/opensim')
            ->and($layout->systemUser())->toBe('opensim')
            ->and($refused(['SystemUser' => '/srv/x']))->toBe('not a folder of the install: SystemUser = /srv/x')
            ->and($refused(['DataRoot' => 'relative']))->toBe('not a folder of the install: DataRoot = relative')
            ->and($refused(['DataRoot' => '/srv/../etc']))->toBe('not a folder of the install: DataRoot = /srv/../etc');
    });

    test('is needed when the place of the grids cannot be written', function () {
        $base = sys_get_temp_dir() . '/syslayout-' . getmypid();
        mkdir($base, 0o555);
        $needed = SystemLayout::needed(system_layout_roots($base));
        chmod($base, 0o755);
        rmdir($base);

        // A folder that cannot be written (unless root) needs the layout; one that can does not
        expect($needed)->toBe(function_exists('posix_geteuid') && posix_geteuid() === 0 ? false : true)
            ->and(SystemLayout::needed(new Layout(['EtcRoot' => sys_get_temp_dir() . '/zz-new/etc'])))->toBeFalse();
    });

    test('makes the account, the folders and w4os.conf', function () {
        $base = sys_get_temp_dir() . '/syslayout-ok-' . getmypid();
        $commands = [];
        $ui = new NullUi();
        $made = (new SystemLayout($ui, function (string $c) use (&$commands): int {
            $commands[] = $c;

            return 0;
        }, fn() => false, true, conf: "$base/etc/w4os/w4os.conf", canMake: fn() => true))->prepare(system_layout_roots($base));

        expect($made)->toBeTrue()
            ->and(is_dir("$base/etc/opensim/grids"))->toBeTrue()
            ->and(is_dir("$base/etc/opensim/robust.d"))->toBeTrue()
            ->and(is_dir("$base/var/log/opensim"))->toBeTrue()
            ->and(file_get_contents("$base/etc/w4os/w4os.conf"))->toContain('SystemUser = opensim', "EtcRoot = $base/etc/opensim")
            ->and(implode("\n", $commands))->toContain('opensim')
            ->and(implode("\n", $commands))->toMatch('/adduser|useradd/');
        exec('rm -rf ' . escapeshellarg($base));
    });

    test('keeps a w4os.conf that is there, and does not make an account that exists', function () {
        $base = sys_get_temp_dir() . '/syslayout-keep-' . getmypid();
        mkdir("$base/etc/w4os", 0o755, true);
        file_put_contents("$base/etc/w4os/w4os.conf", "[Defaults]\nSystemUser = mine\n");
        $commands = [];
        $made = (new SystemLayout(new NullUi(), function (string $c) use (&$commands): int {
            $commands[] = $c;

            return 0;
        }, fn() => true, true, conf: "$base/etc/w4os/w4os.conf"))->prepare(system_layout_roots($base));

        expect($made)->toBeTrue()
            ->and(file_get_contents("$base/etc/w4os/w4os.conf"))->toContain('SystemUser = mine')
            ->and(implode("\n", $commands))->not->toMatch('/adduser|useradd/');
        exec('rm -rf ' . escapeshellarg($base));
    });

    test('moves the opensim.conf of before w4os to its place, and takes its settings', function () {
        $base = sys_get_temp_dir() . '/syslayout-move-' . getmypid();
        mkdir("$base/etc/opensim", 0o755, true);
        file_put_contents("$base/etc/opensim/opensim.conf", "[Defaults]\nSystemUser = mine\n\n[Grid oldgrid]\nRobustIni = /opt/old/bin/Robust.HG.ini\n");
        $commands = [];
        $ui = new NullUi();
        $made = (new SystemLayout($ui, function (string $c) use (&$commands): int {
            $commands[] = $c;

            return 0;
        }, fn(string $user) => $user === 'mine', true, conf: "$base/etc/w4os/w4os.conf"))->prepare(system_layout_roots($base));

        expect($made)->toBeTrue()
            ->and(file_exists("$base/etc/opensim/opensim.conf"))->toBeFalse()
            ->and(file_get_contents("$base/etc/w4os/w4os.conf"))->toContain('SystemUser = mine', '[Grid oldgrid]')
            ->and(implode("\n", $commands))->not->toMatch('/adduser|useradd/')
            ->and(implode("\n", $commands))->toContain("chown 'mine':'mine' '$base/etc/w4os'");
        exec('rm -rf ' . escapeshellarg($base));
    });

    test('does not modify membership when the system user is already in the web group', function () {
        $base = sys_get_temp_dir() . '/syslayout-group-' . getmypid();
        $commands = [];
        $made = (new SystemLayout(
            new NullUi(),
            function (string $command) use (&$commands): int {
                $commands[] = $command;

                return 0;
            },
            fn() => true,
            true,
            fn(string $name): array|false => $name === 'www-data' ? ['members' => ['opensim']] : false,
            "$base/etc/w4os/w4os.conf",
        ))->prepare(system_layout_roots($base));

        expect($made)->toBeTrue()
            ->and(implode("\n", $commands))->not->toContain('/usr/sbin/usermod');
        exec('rm -rf ' . escapeshellarg($base));
    });

    test('makes its default backup root, named or not, never a chosen one', function () {
        expect(SystemLayout::makesBackupRoot(new Layout([])))
            ->toBeTrue()
            ->and(SystemLayout::makesBackupRoot(new Layout(['BackupRoot' => '/var/backups/opensim'])))
            ->toBeTrue()
            ->and(SystemLayout::makesBackupRoot(new Layout(['BackupRoot' => '/mnt/backups/opensim'])))
            ->toBeFalse();
    });

    test('refuses when it is not root', function () {
        $ui = new NullUi();

        expect((new SystemLayout($ui, null, null, false))->prepare(system_layout_roots('/nonexistent-zz')))->toBeFalse()
            ->and($ui->said[0][0])->toBe('error');
    });
});
