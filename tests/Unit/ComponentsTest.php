<?php

declare(strict_types=1);

use W4OS\Engine\Config\Components;
use W4OS\Engine\Config\Layout;

/** A machine in a temporary folder: its config, the folder of its packages, the folder of its clones. */
function components_machine(array $settings = []): array
{
    $base = test_tmp() . '/components-' . bin2hex(random_bytes(4));
    mkdir("$base/etc", 0o755, true);
    mkdir("$base/var", 0o755, true);
    mkdir("$base/packages", 0o755, true);
    mkdir("$base/clones", 0o755, true);
    $text = "[Defaults]\nVarRoot = $base/var\n";
    foreach ($settings as $key => $value) {
        $text .= "$key = $value\n";
    }
    file_put_contents("$base/etc/w4os.conf", $text);
    Components::$clones = "$base/clones";
    Components::$packages = "$base/packages";

    return [$base, static fn(): Layout => Layout::load("$base/etc/w4os.conf")];
}

/** The clone of a component as its build leaves it. */
function components_clone(string $base, string $name): void
{
    $files = [
        'helpers' => ['opensim-helpers/vendor/autoload.php', 'opensim-helpers/public/index.php'],
        'site' => ['w4os-site/build/w4os-site/grav/index.php', 'w4os-site/build/w4os-site/bin/w4os-site', 'w4os-site/build/w4os-site/bin/w4os-site-tree'],
        'modules' => ['opensim-kit/build/modules/opensimsearch/OpenSimSearch.Modules.dll', 'opensim-kit/build/modules/gloebit/Gloebit.dll', 'opensim-kit/build/modules/maptiles/OpenSim.Region.CoreModules.dll'],
    ][$name];
    foreach ($files as $file) {
        is_dir(dirname("$base/clones/$file")) || mkdir(dirname("$base/clones/$file"), 0o755, true);
        file_put_contents("$base/clones/$file", '');
    }
    $name !== 'modules' || file_put_contents("$base/clones/opensim-kit/build/packaging.env", "VERSION=3.0.0\nMODULES_CORE=0.9.3.0\n");
}

describe('The components of the suite', function () {
    afterEach(fn() => test_components());

    test('are nowhere on a machine that has neither their package nor their clone', function () {
        [, $layout] = components_machine();

        foreach (Components::names() as $name) {
            expect(Components::root($layout(), $name))->toBe('')
                ->and(Components::source($layout(), $name))->toBe('')
                ->and(Components::unready($layout(), $name))->toBe('')
                ->and(Components::bring($layout(), $name))->toBeNull();
        }
        expect(Components::siteCommand($layout()))->toBe('');
    });

    test('are the ones of their package, before any clone, and nothing is written for them', function () {
        [$base, $layout] = components_machine();
        mkdir("$base/packages/w4os-helpers");
        mkdir("$base/packages/w4os-site");
        components_clone($base, 'helpers');
        components_clone($base, 'site');
        $before = file_get_contents("$base/etc/w4os.conf");

        expect(Components::root($layout(), 'helpers'))->toBe("$base/packages/w4os-helpers")
            ->and(Components::source($layout(), 'helpers'))->toBe('package')
            ->and(Components::bring($layout(), 'helpers'))->toBeNull()
            ->and(Components::bring($layout(), 'site'))->toBeNull()
            ->and(Components::siteCommand($layout()))->toBe(escapeshellarg("$base/packages/w4os-site/bin/w4os-site"))
            ->and(file_get_contents("$base/etc/w4os.conf"))->toBe($before);
    });

    test('are the ones of the clone beside this one once it is ready, their place then kept in the config', function () {
        [$base, $layout] = components_machine();
        mkdir("$base/clones/opensim-helpers");
        mkdir("$base/clones/w4os-site");

        expect(Components::root($layout(), 'helpers'))->toBe('')
            ->and(Components::unready($layout(), 'helpers'))->toBe("composer install in $base/clones/opensim-helpers first")
            ->and(Components::unready($layout(), 'site'))->toBe("packaging/build in $base/clones/w4os-site first");

        components_clone($base, 'helpers');
        components_clone($base, 'site');
        // The temporary folder of a machine may be its person's alone
        Components::$open = static fn(string $folder): bool => true;

        expect(Components::source($layout(), 'helpers'))->toBe('clone')
            ->and(Components::unready($layout(), 'helpers'))->toBe('')
            ->and(Components::siteCommand($layout()))->toBe('W4OS_SITE_SHARE=' . escapeshellarg("$base/clones/w4os-site/build/w4os-site") . ' ' . escapeshellarg("$base/clones/w4os-site/build/w4os-site/bin/w4os-site"))
            // The site of a grid serves them from there, the website from its own folder
            ->and(W4OS\Engine\Provisioning\Web\Snippets::docroot('/usr/share/w4os-helpers/public', $layout()))->toBe("$base/clones/opensim-helpers/public")
            ->and(W4OS\Engine\Provisioning\Web\Snippets::docroot('/var/lib/w4os/www', $layout()))->toBe('/var/lib/w4os/www')
            ->and(Components::bring($layout(), 'helpers'))->toBe("$base/clones/opensim-helpers")
            ->and(Components::bring($layout(), 'site'))->toBe("$base/clones/w4os-site/build/w4os-site")
            ->and($layout()->get('HelpersRoot'))->toBe("$base/clones/opensim-helpers")
            ->and($layout()->get('SiteShare'))->toBe("$base/clones/w4os-site/build/w4os-site")
            // Kept: nothing more to bring, and the clone gone, its place still the one named
            ->and(Components::source($layout(), 'helpers'))->toBe('setting')
            ->and(Components::bring($layout(), 'helpers'))->toBeNull();
    });

    test('leave the helpers aside in a folder the web server cannot read', function () {
        [$base, $layout] = components_machine(['WebUser' => 'www-data']);
        components_clone($base, 'helpers');
        chmod("$base/clones", 0o700);

        expect(Components::root($layout(), 'helpers'))->toBe('')
            ->and(Components::unready($layout(), 'helpers'))->toBe("www-data cannot read $base/clones/opensim-helpers");
        chmod("$base/clones", 0o755);
    });

    test('give the modules built in the kit beside this one by core, as their packages do, the search enabled once', function () {
        [$base, $layout] = components_machine();
        mkdir("$base/clones/opensim-kit");

        expect(Components::unready($layout(), 'modules'))->toBe("packaging/build in $base/clones/opensim-kit first");

        components_clone($base, 'modules');

        expect(Components::bring($layout(), 'modules'))->toBe("$base/var/modules")
            ->and($layout()->modulesRoot())->toBe("$base/var/modules")
            ->and(is_file("$base/var/modules/0.9.3.0/opensimsearch/OpenSimSearch.Modules.dll"))->toBeTrue()
            ->and(is_file("$base/var/modules/0.9.3.0/gloebit/Gloebit.dll"))->toBeTrue()
            // The 3D map replaces a file of the core: its package alone does it
            ->and(file_exists("$base/var/modules/0.9.3.0/maptiles"))->toBeFalse()
            ->and($layout()->enabledModules())->toBe(['opensimsearch']);
    });
});
