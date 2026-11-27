<?php

declare(strict_types=1);

/**
 * The OSSL permissions of an install of before the kit, carried into its migrated grid: read where its simulators read them (an
 * Include-osslEnable with an absolute path, the old [XEngine] section and its macros), written in the grid's osslEnable.ini as
 * [OSSL], each function OpenSimulator no longer has left out and named.
 */

use W4OS\Engine\Provisioning\Import\OsslImport;

function ossl_fixture(): array
{
    $base = sys_get_temp_dir() . '/ossl-' . bin2hex(random_bytes(4));
    foreach (["$base/old/etc", "$base/core/bin/config-include", "$base/grid/config-include"] as $dir) {
        mkdir($dir, 0o755, true);
    }
    file_put_contents("$base/old/etc/osslEnable.ini", implode("\n", [
        '[XEngine]',
        '    osslParcelO = "PARCEL_OWNER,"',
        '    osslParcelOG = "PARCEL_GROUP_MEMBER,PARCEL_OWNER,"',
        '    Allow_osAvatarPlayAnimation = ${XEngine|osslParcelOG}ESTATE_MANAGER,ESTATE_OWNER',
        '    Allow_osSetSunParam = ESTATE_MANAGER,ESTATE_OWNER',
        '    Allow_osGetGridName = true',
        '    Allow_osGoneLongAgo = true',
        '',
    ]));
    file_put_contents("$base/old/etc/Welcome.OpenSim.ini", "[Startup]\n    Include-osslEnable = \"$base/old/etc/osslEnable.ini\"\n");
    file_put_contents("$base/core/bin/config-include/osslDefaultEnable.ini", implode("\n", [
        '[OSSL]',
        '    osslParcelO = "PARCEL_OWNER,"',
        '    osslParcelOG = "PARCEL_GROUP_MEMBER,PARCEL_OWNER,"',
        '    Allow_osAvatarPlayAnimation = false',
        '    Allow_osSetSunParam = false',
        '    Allow_osGetGridName = true',
        '',
    ]));
    copy("$base/core/bin/config-include/osslDefaultEnable.ini", "$base/grid/config-include/osslDefaultEnable.ini");
    file_put_contents("$base/grid/config-include/osslEnable.ini", "[OSSL]\n  ; add lines with our region local changes, below this to replace the default on osslDefaultEnable.ini or code.\n");

    return [$base, new OsslImport("$base/old/etc/Welcome.OpenSim.ini", "$base/grid", "$base/core/bin")];
}

test('keeps the permissions the old install had, its [XEngine] read as [OSSL], the functions gone left out', function () {
    [$base, $import] = ossl_fixture();

    expect($import->kept())->toBe([
        'Allow_osAvatarPlayAnimation' => '${OSSL|osslParcelOG}ESTATE_MANAGER,ESTATE_OWNER',
        'Allow_osSetSunParam' => 'ESTATE_MANAGER,ESTATE_OWNER',
    ])
        ->and($import->dropped())->toBe(['Allow_osGoneLongAgo'])
        ->and($import->report())->toContain('2 OSSL permission(s) of the old install kept', '[XEngine] read as [OSSL]', 'Allow_osGoneLongAgo');
});

test('writes them below the line of the local changes of the grid, once', function () {
    [$base, $import] = ossl_fixture();
    $import->apply();
    $import->apply();
    $text = (string) file_get_contents("$base/grid/config-include/osslEnable.ini");

    expect(substr_count($text, 'Allow_osAvatarPlayAnimation = ${OSSL|osslParcelOG}ESTATE_MANAGER,ESTATE_OWNER'))->toBe(1)
        ->and(strpos($text, 'Allow_osSetSunParam'))->toBeGreaterThan(strpos($text, 'below this'));
});
