<?php

declare(strict_types=1);

use W4OS\Engine\Provisioning\DatabaseService;

describe('The accounts of the machine', function () {
    test('are read before a person\'s own file, which wins; the administrator\'s only by who can read it', function () {
        $base = sys_get_temp_dir() . '/machine-rc-' . bin2hex(random_bytes(4));
        mkdir("$base/etc", 0o755, true);
        mkdir("$base/home");
        file_put_contents("$base/etc/database.rc", "export OPENSIM_DB_USER=machine_user\nexport OPENSIM_DB_PASS=machine-pass\n");
        file_put_contents("$base/etc/database-admin.rc", "export OPENSIM_DB_ADMIN_USER=machine_admin\n");
        file_put_contents("$base/home/.opensimrc", "export OPENSIM_DB_USER=my_user\n");
        $read = static function (string $more = '') use ($base): string {
            $script = 'unset OPENSIM_RC OPENSIM_DB_USER OPENSIM_DB_PASS OPENSIM_DB_ADMIN_USER; ' . $more
                . 'source ' . escapeshellarg(dirname(__DIR__, 2) . '/lib/rc-helpers') . '; read_rc; echo "$OPENSIM_DB_USER ${OPENSIM_DB_PASS:-} ${OPENSIM_DB_ADMIN_USER:-none}"';

            return trim((string) shell_exec('HOME=' . escapeshellarg("$base/home") . ' W4OS_MACHINE_RC_DIR=' . escapeshellarg("$base/etc") . ' bash -c ' . escapeshellarg($script)));
        };
        $all = $read();
        chmod("$base/etc/database-admin.rc", 0);
        $hidden = $read();
        unlink("$base/home/.opensimrc");
        $machine = $read();

        expect($all)->toBe('my_user machine-pass machine_admin')
            ->and($machine)->toStartWith('machine_user machine-pass');
        // Root reads every file: the rest is for an account that cannot
        function_exists('posix_geteuid') && posix_geteuid() === 0 || expect($hidden)->toBe('my_user machine-pass none');
    });

    test('give the user of a grid the databases named after it, the underscore of its name never a wildcard', function () {
        expect(DatabaseService::gridPattern('kimbe_test_robust'))->toBe('kimbe\\_test\\_%')
            ->and(DatabaseService::gridPattern('amy_robust'))->toBe('amy\\_%')
            ->and(DatabaseService::gridPattern('solo'))->toBeNull()
            ->and(DatabaseService::gridPattern('_robust'))->toBeNull();
    });
});
