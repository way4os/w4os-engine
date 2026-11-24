<?php

declare(strict_types=1);

/**
 * The cores installed on a machine and the runtime they need.
 */

use W4OS\Engine\Runtime\Core;

/** A folder of cores: name => assembly file name in its bin. */
function cores_root(array $cores): string
{
    $root = sys_get_temp_dir() . '/cores-' . bin2hex(random_bytes(4));
    foreach ($cores as $name => $assembly) {
        mkdir("$root/$name/bin", 0o755, true);
        touch("$root/$name/bin/$assembly");
    }

    return $root;
}

describe('cores of a folder', function () {
    test('are the releases by version, then the builds', function () {
        $root = cores_root([
            '0.9.3.0' => 'OpenSim.dll',
            '0.9.2.2' => 'OpenSim.exe',
            'unstable' => 'OpenSim.dll',
            '0.9.10.0' => 'OpenSim.dll',
        ]);

        expect(array_map(static fn(Core $c): string => $c->name(), Core::all($root)))->toBe([
            '0.9.2.2',
            '0.9.3.0',
            '0.9.10.0',
            'unstable',
        ]);
    });

    test('have the newest release as the latest, the last build when there is no release', function () {
        expect(
            Core::latest(
                cores_root(['0.9.2.2' => 'OpenSim.exe', '0.9.3.0' => 'OpenSim.dll', 'unstable' => 'OpenSim.dll']),
            )?->name(),
        )
            ->toBe('0.9.3.0')
            ->and(Core::latest(cores_root(['dev' => 'OpenSim.dll']))?->name())
            ->toBe('dev')
            ->and(Core::latest(cores_root([])))
            ->toBeNull()
            ->and(Core::latest(''))
            ->toBeNull();
    });
});

describe('runtime of a core', function () {
    test('is .NET from 0.9.3.0, on the major it targets, never rolled forward; Mono before', function () {
        $root = cores_root(['0.9.3.0' => 'OpenSim.dll', '0.9.2.2' => 'OpenSim.exe']);
        $new = Core::at("$root/0.9.3.0");
        $old = Core::at("$root/0.9.2.2");

        expect([$new->runtime(), $new->executable('Robust'), $new->runtimeOptions(), $new->dotnetMajor()])
            ->toBe(['dotnet', 'Robust.dll', '', 8])
            ->and([$old->runtime(), $old->executable('Robust'), $old->runtimeOptions(), $old->dotnetMajor()])
            ->toBe(['mono', 'Robust.exe', '', null])
            // The runtimes this machine needs: the majors its cores target
            ->and(Core::dotnetMajors($root))
            ->toBe([8]);
    });

    test('is .NET for a build that has a runtimeconfig.json, whatever its folder is called', function () {
        $root = cores_root(['unstable' => 'OpenSim.dll']);
        touch("$root/unstable/bin/OpenSim.runtimeconfig.json");

        expect(Core::at("$root/unstable")->runtime())->toBe('dotnet');
    });

    test('asks for the .NET version of its runtimeconfig.json, 8.0.0 when it does not say', function () {
        $root = cores_root(['0.9.3.0' => 'Robust.dll']);
        file_put_contents(
            "$root/0.9.3.0/bin/Robust.runtimeconfig.json",
            '{"runtimeOptions":{"framework":{"name":"Microsoft.NETCore.App","version":"9.0.1"}}}',
        );

        expect(Core::at("$root/0.9.3.0")->dotnetRequired('Robust.dll'))
            ->toBe('9.0.1')
            ->and(Core::at("$root/0.9.3.0")->dotnetRequired('OpenSim.dll'))
            ->toBe('8.0.0');
    });

    test('has native libraries linked under the plain names, for the architecture of the machine', function () {
        $root = cores_root(['0.9.3.0' => 'OpenSim.dll']);
        mkdir("$root/0.9.3.0/bin/lib64");
        touch("$root/0.9.3.0/bin/lib64/libBulletSim-x86_64.so");
        touch("$root/0.9.3.0/bin/lib64/libopenjpeg-dotnet.so");
        touch("$root/0.9.3.0/bin/lib64/libsqlite3_64.so");

        $links = Core::at("$root/0.9.3.0")->nativeLibraries("$root/native", 'x86_64');

        expect($links)
            ->toBe("$root/native/0.9.3.0")
            ->and(readlink("$links/libBulletSim.so"))
            ->toBe("$root/0.9.3.0/bin/lib64/libBulletSim-x86_64.so")
            ->and(readlink("$links/libopenjpeg-dotnet.so"))
            ->toBe("$root/0.9.3.0/bin/lib64/libopenjpeg-dotnet.so")
            // SQLite by the name Mono.Data.Sqlite asks for, the build of the core its dllmap names
            ->and(readlink("$links/libsqlite3.so"))
            ->toBe("$root/0.9.3.0/bin/lib64/libsqlite3_64.so")
            ->and(Core::at("$root/0.9.3.0")->nativeLibraries("$root/native2", 'aarch64'))
            ->toBe("$root/native2/0.9.3.0")
            ->and(is_link("$root/native2/0.9.3.0/libBulletSim.so"))
            ->toBeFalse();
    });
});
