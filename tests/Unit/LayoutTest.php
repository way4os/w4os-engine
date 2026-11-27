<?php

declare(strict_types=1);

/**
 * Where an install keeps its files, from w4os.conf.
 */

use W4OS\Engine\Config\Layout;

/** A w4os.conf in a temporary folder. */
function layout_conf(string $text): string
{
    $dir = sys_get_temp_dir() . '/layout-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("$dir/w4os.conf", $text);

    return "$dir/w4os.conf";
}

describe('layout of an install', function () {
    test('is [Defaults], the profile it points to over them', function () {
        $layout = Layout::load(
            layout_conf(
                "[Defaults]\nDefaultProfile = opensim-0.9.3.0\nEtcRoot = /etc/opensim\nSystemUser = opensim\n\n[opensim-0.9.3.0]\nCoreDirectory = /usr/share/opensim/0.9.3.0\nEtcRoot = /srv/etc\nVarRoot = /var/lib/opensim\n",
            ),
        );

        expect($layout->profile())
            ->toBe('opensim-0.9.3.0')
            ->and($layout->etcRoot())
            ->toBe('/srv/etc')
            ->and($layout->varRoot())
            ->toBe('/var/lib/opensim')
            ->and($layout->systemUser())
            ->toBe('opensim')
            ->and($layout->get('CoreDirectory'))
            ->toBe('/usr/share/opensim/0.9.3.0')
            ->and($layout->coreDirectory())
            ->toBe('/usr/share/opensim/0.9.3.0')
            ->and($layout->dataSource())
            ->toBe('');
    });

    test('can be another profile than the default one', function () {
        $file = layout_conf("[Defaults]\nDefaultProfile = a\n\n[a]\nEtcRoot = /a\n\n[b]\nEtcRoot = /b\n");

        expect(Layout::load($file, [], 'b')->etcRoot())->toBe('/b');
    });

    test('has the defaults it is given for what the file does not say', function () {
        $layout = Layout::load(layout_conf("[Defaults]\nEtcRoot = /etc/opensim\n"), [
            'EtcRoot' => '/nope',
            'CoreRoot' => '/opt/core',
        ]);

        expect($layout->etcRoot())->toBe('/etc/opensim')->and($layout->coreRoot())->toBe('/opt/core');
    });

    test('is made of the defaults only when there is no file', function () {
        $layout = Layout::load('/no/such/w4os.conf', ['EtcRoot' => '/opt/etc']);

        expect($layout->file())
            ->toBeNull()
            ->and($layout->etcRoot())
            ->toBe('/opt/etc')
            ->and($layout->systemUser())
            ->toBe('');
    });

    test('names the modules enabled for the regions', function () {
        $layout = new Layout(['EnabledModules' => 'gloebit, opensimsearch']);

        expect($layout->enabledModules())
            ->toBe(['gloebit', 'opensimsearch'])
            ->and((new Layout())->enabledModules())
            ->toBe([]);
    });

    test('has the core of its CoreDirectory, else the latest one of its core root', function () {
        $root = sys_get_temp_dir() . '/layout-cores-' . bin2hex(random_bytes(4));
        foreach (['0.9.2.2', '0.9.3.0'] as $version) {
            mkdir("$root/$version/bin", 0o755, true);
            touch("$root/$version/bin/OpenSim.dll");
        }

        expect((new Layout(['CoreRoot' => $root]))->core()?->name())
            ->toBe('0.9.3.0')
            ->and((new Layout(['CoreRoot' => $root, 'CoreDirectory' => "$root/0.9.2.2"]))->core()?->name())
            ->toBe('0.9.2.2')
            ->and((new Layout(['CoreRoot' => $root, 'CoreDirectory' => "$root/gone"]))->core()?->name())
            ->toBe('0.9.3.0')
            ->and((new Layout(['CoreRoot' => "$root/none"]))->core())
            ->toBeNull();
    });
});

describe('layout a front-end works with', function () {
    test('is what its environment says, then w4os.conf, then the defaults of an install by the book', function () {
        $file = layout_conf("[Defaults]\nEtcRoot = /srv/etc\nVarRoot = /srv/var\n");
        $layout = Layout::resolve([
            'OPENSIM_CONF' => $file,
            'OPENSIM_VAR_ROOT' => '/tmp/mine',
            'OPENSIM_GRID' => 'not a setting of the layout',
        ]);

        expect($layout->file())
            ->toBe($file)
            ->and($layout->varRoot())
            ->toBe('/tmp/mine')
            ->and($layout->etcRoot())
            ->toBe('/srv/etc')
            ->and($layout->cacheRoot())
            ->toBe('/var/cache/opensim')
            ->and($layout->all())
            ->not->toHaveKey('Grid');
    });

    test('keeps a core root of its own that is not made yet: only a usual root gives way to the other', function () {
        $layout = Layout::resolve(['OPENSIM_CONF' => layout_conf("[Defaults]\nCoreRoot = /srv/nowhere/cores\nCoreDirectory = /srv/nowhere/cores/0.9.3.0\n")]);

        expect($layout->coreRoot())->toBe('/srv/nowhere/cores')
            ->and($layout->coreDirectory())->toBe('/srv/nowhere/cores/0.9.3.0');
    });

    test(
        'is the defaults of the system layout when nothing says otherwise, and the roots alone are enough',
        function () {
            $layout = Layout::resolve([
                'OPENSIM_CONF' => layout_conf("[Defaults]\n"),
                'OPENSIM_CORE_ROOT' => '/opt/cores',
                'OPENSIM_DATA_ROOT' => '/opt/data',
            ]);

            expect($layout->etcRoot())
                ->toBe('/etc/opensim')
                ->and($layout->coreRoot())
                ->toBe('/opt/cores')
                ->and($layout->dataRoot())
                ->toBe('/opt/data')
                ->and($layout->logsRoot())
                ->toBe('/var/log/opensim');
        },
    );

    test('refuses a w4os.conf named in the environment that does not exist', function () {
        expect(fn() => Layout::resolve(['OPENSIM_CONF' => '/nonexistent/w4os.conf']))->toThrow(
            RuntimeException::class,
            '/nonexistent/w4os.conf',
        );
    });

    test('names each setting in the environment the same way', function () {
        expect(Layout::environmentName('EtcRoot'))
            ->toBe('OPENSIM_ETC_ROOT')
            ->and(Layout::environmentName('CoreDirectory'))
            ->toBe('OPENSIM_CORE_DIRECTORY')
            ->and(Layout::environmentName('SystemUser'))
            ->toBe('OPENSIM_SYSTEM_USER');
    });
});

describe('layouts of an install', function () {
    test('put the folders where opensim setup proposes them', function () {
        expect(W4OS\Engine\Config\DirectoryLayout::roots('system', '', '0.9.3.0'))
            ->toBe([
                'CoreRoot' => '/usr/local/share/opensim',
                'CoreDirectory' => '/usr/local/share/opensim/0.9.3.0',
                'EtcRoot' => '/etc/opensim',
                'VarRoot' => '/var/lib/opensim',
                'LogsRoot' => '/var/log/opensim',
                'CacheRoot' => '/var/cache/opensim',
                'DataRoot' => '/var/lib/opensim/data',
                'BackupRoot' => '/var/backups/opensim',
            ])
            ->and(W4OS\Engine\Config\DirectoryLayout::roots('bundled', '/opt/opensim', '0.9.3.0')['DataRoot'])
            ->toBe('/opt/opensim/var/data')
            ->and(W4OS\Engine\Config\DirectoryLayout::roots('flat', '/home/me', '0.9.3.0')['EtcRoot'])
            ->toBe('/home/me/opensim-0.9.3.0/bin')
            ->and(W4OS\Engine\Config\DirectoryLayout::roots('system'))
            ->not->toHaveKey('CoreDirectory');
    });

    test('keep the state of the tasks in a folder of its own, given by whoever runs them (a site that cannot write the install\'s)', function () {
        expect((new Layout(['VarRoot' => '/var/lib/opensim']))->tasksRoot())
            ->toBe('/var/lib/opensim/cron')
            ->and((new Layout(['VarRoot' => '/var/lib/opensim', 'TasksRoot' => '/var/cache/w4os-site/tasks/']))->tasksRoot())
            ->toBe('/var/cache/w4os-site/tasks')
            ->and(Layout::resolve(['OPENSIM_TASKS_ROOT' => '/srv/site/tasks', 'OPENSIM_CONF' => ''])->tasksRoot())
            ->toBe('/srv/site/tasks')
            ->and(Layout::environmentName('TasksRoot'))
            ->toBe('OPENSIM_TASKS_ROOT');
    });

    test('keep the backups out of the data', function () {
        expect((new Layout(['EtcRoot' => '/etc/opensim', 'DataRoot' => '/var/lib/opensim/data']))->backupRoot())
            ->toBe('/var/backups/opensim')
            ->and((new Layout(['EtcRoot' => '/x/etc', 'DataRoot' => '/x/var/data']))->backupRoot())
            ->toBe('/x/var/backups')
            ->and((new Layout(['EtcRoot' => '/etc/opensim', 'BackupRoot' => '/var/backups/localhost/opensim/']))->backupRoot())
            ->toBe('/var/backups/localhost/opensim');
    });
});

describe('The grids of an existing install, in the config of the suite', function () {
    test('are its [Grid <nick>] sections: where the files of each are, kept apart from the layout', function () {
        $layout = Layout::load(layout_conf(
            "[Defaults]\nEtcRoot = /etc/opensim\n\n[Grid oldgrid]\nRobustIni = /opt/old/bin/Robust.HG.ini\nCoreDirectory = /opt/old\nSimulators = welcome:/opt/old/bin/OpenSim.ini\n\n[opensim-0.9.3.0]\nCoreDirectory = /usr/share/opensim/0.9.3.0\n",
        ));

        expect($layout->grids())
            ->toBe(['oldgrid' => ['RobustIni' => '/opt/old/bin/Robust.HG.ini', 'CoreDirectory' => '/opt/old', 'Simulators' => 'welcome:/opt/old/bin/OpenSim.ini']])
            ->and($layout->get('RobustIni'))
            ->toBe('')
            ->and(Layout::FILE)
            ->toBe('w4os.conf');
    });
});

describe('The config of the suite', function () {
    test('is found in the home of the user, then on the system; the one of a user from before w4os is still read', function () {
        $home = test_tmp() . '/home-' . bin2hex(random_bytes(4));
        mkdir("$home/.config/opensim", 0o755, true);
        file_put_contents("$home/.config/opensim/opensim.conf", "[Defaults]\n");
        $previous = getenv('HOME');
        putenv("HOME=$home");
        $legacy = Layout::locate();
        mkdir("$home/.config/w4os", 0o755, true);
        file_put_contents("$home/.config/w4os/w4os.conf", "[Defaults]\n");
        $current = Layout::locate();
        putenv($previous === false ? 'HOME' : "HOME=$previous");

        expect($current)
            ->toBe("$home/.config/w4os/w4os.conf")
            ->and($legacy)
            ->toBe(is_file(Layout::systemFile()) ? Layout::systemFile() : "$home/.config/opensim/opensim.conf")
            ->and(Layout::systemFile())
            ->toBe('/etc/w4os/w4os.conf');
    });
});
