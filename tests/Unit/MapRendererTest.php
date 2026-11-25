<?php

declare(strict_types=1);

/**
 * Whether the simulators of a core can draw their map in 3D (Warp3DImageModule): it saves the tile of each region, in its folder of
 * the tiles when the core reads MapTilesDirectory (after 0.9.3.0, or 0.9.3.0 with opensim-0.9.3.0-maptiles), else in the bin of
 * the core. A simulator that cannot write it does not start: MapImageModule then, and what would give it said.
 */

use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\GridOptions;
use W4OS\Engine\Provisioning\MapRenderer;
use W4OS\Engine\Provisioning\RetiredSettings;
use W4OS\Engine\Testing\Machine;

/**
 * A core of OpenSimulator 0.9.3.0 in a folder of the tests: its map modules with MapTilesDirectory or not, its bin writable or not.
 */
function map_core(bool $folderSetting, bool $writableBin): string
{
    $core = test_tmp() . '/map-core-' . bin2hex(random_bytes(4)) . '/0.9.3.0';
    mkdir("$core/bin", 0o755, true);
    $text = 'MZ assembly' . ($folderSetting ? implode('', array_map(static fn(string $c): string => "$c\0", str_split('MapTilesDirectory'))) : '');
    file_put_contents("$core/bin/OpenSim.Region.CoreModules.dll", $text);
    $writableBin || chmod("$core/bin", 0o555);

    return $core;
}

/** A folder of the tests, writable or not. */
function map_folder(bool $writable): string
{
    $folder = test_tmp() . '/map-tiles-' . bin2hex(random_bytes(4));
    mkdir($folder, 0o755, true);
    $writable || chmod($folder, 0o555);

    return $folder;
}

describe('The 3D map of the simulators of a core', function () {
    test('is drawn where they may write: their folder of the tiles when the core reads one, else its bin', function () {
        $patched = map_core(folderSetting: true, writableBin: false);
        $official = map_core(folderSetting: false, writableBin: false);
        // An install that runs from the archive of a release: its bin is its own
        $own = map_core(folderSetting: false, writableBin: true);

        expect(MapRenderer::hasFolderSetting($patched))->toBeTrue()
            ->and(MapRenderer::hasFolderSetting($official))->toBeFalse()
            ->and(MapRenderer::whyNotWarp3D($patched, map_folder(true)))->toBeNull()
            ->and(MapRenderer::whyNotWarp3D($patched, '${Const|CacheDirectory}/maptiles'))->toBeNull()
            ->and(MapRenderer::whyNotWarp3D($patched, map_folder(false)))->toContain('cannot write')
            ->and(MapRenderer::whyNotWarp3D($patched))->toContain('without [Map] MapTilesDirectory')
            ->and(MapRenderer::whyNotWarp3D($official, map_folder(true)))->toContain('the package opensim-0.9.3.0-maptiles is installed')
            ->and(MapRenderer::whyNotWarp3D($own))->toBeNull()
            // A core that is not here, by its version
            ->and(MapRenderer::whyNotWarp3D('/nowhere/opensim/0.9.3.0'))->toContain('read-only')
            ->and(MapRenderer::whyNotWarp3D('/nowhere/opensim-0.9.2.2'))->toBeNull();
    })->skip(fn() => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes everywhere');

    test('keeps the setting of a config where it can be drawn, leaves it to MapImageModule where it cannot', function () {
        $patched = map_core(folderSetting: true, writableBin: false);
        $official = map_core(folderSetting: false, writableBin: false);
        $context = ['folder' => map_folder(true)];

        expect(RetiredSettings::why('Map', 'MapImageModule', '"Warp3DImageModule"', $patched, $context))->toBeNull()
            ->and(RetiredSettings::why('Map', 'MapImageModule', 'Warp3DImageModule', $official, $context))->toContain('opensim-0.9.3.0-maptiles')
            ->and(RetiredSettings::why('Map', 'MapImageModule', 'MapImageModule', $official, $context))->toBeNull();
    })->skip(fn() => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes everywhere');

    test('is the map of a new grid whose simulators can draw it, with the textures, the prims and the meshes', function () {
        $machine = new Machine();
        $bin = Grid::find($machine->environment, 'demo')->coreDirectory() . '/bin';
        $option = static fn(): ?W4OS\Engine\Provisioning\OptionTemplate => (new GridOptions(Grid::find($machine->environment, 'demo')))->template('map-warp3d');
        $options = static fn(): GridOptions => new GridOptions(Grid::find($machine->environment, 'demo'));
        // The official core, read-only
        $machine->write("$bin/OpenSim.Region.CoreModules.dll", 'MZ assembly');
        chmod($bin, 0o555);
        $official = $options()->unmet($option());
        chmod($bin, 0o755);
        $machine->write("$bin/OpenSim.Region.CoreModules.dll", 'MZ assembly' . implode('', array_map(static fn(string $c): string => "$c\0", str_split('MapTilesDirectory'))));
        chmod($bin, 0o555);
        $patched = $options()->unmet($option());
        chmod($bin, 0o755);
        $map = W4OS\Engine\Config\ConfigReader::parse((string) file_get_contents($option()->directory() . '/OpenSim.ini'))['Map'] ?? [];

        expect($option()?->default())->toBeTrue()
            ->and($official)->toBe('warp3d')
            ->and($patched)->toBeNull()
            ->and(array_map(static fn(string $v): string => trim($v, '"'), $map))->toMatchArray([
                'MapImageModule' => 'Warp3DImageModule', 'MapTilesDirectory' => '${Const|CacheDirectory}/maptiles',
                'TextureOnMapTile' => 'true', 'DrawPrimOnMapTile' => 'true', 'TexturePrims' => 'true', 'RenderMeshes' => 'true',
            ]);
    })->skip(fn() => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes everywhere');

    test('of a source kept by its import on a core that reads MapTilesDirectory, its tile in the cache of the simulator', function () {
        $machine = new Machine();
        $file = old_install($machine);
        $machine->write($file, (string) file_get_contents($file) . "[Map]\nMapImageModule = \"Warp3DImageModule\"\n");
        // A grid made before the 3D map was an option: its options say it has not
        $conf = $machine->grid . '/demo.conf';
        $machine->write($conf, str_replace("[Grid]\n", "[Grid]\nOptions = grid, robust, bakes, profiles\n", (string) file_get_contents($conf)));
        $bin = Grid::find($machine->environment, 'demo')->coreDirectory() . '/bin';
        $machine->write("$bin/OpenSim.Region.CoreModules.dll", 'MZ assembly' . implode('', array_map(static fn(string $c): string => "$c\0", str_split('MapTilesDirectory'))));

        $plan = W4OS\Engine\Provisioning\Import\SimulatorImport::prepare($machine->environment, $file, 'demo')->document();
        $kept = array_column(array_filter($plan['settings'], static fn(array $s): bool => $s['section'] === 'Map'), 'value', 'key');

        expect(array_map(static fn(string $v): string => trim($v, '"'), $kept))->toBe(['MapImageModule' => 'Warp3DImageModule', 'MapTilesDirectory' => '${Const|CacheDirectory}/maptiles'])
            ->and(array_column(array_filter($plan['dropped'], static fn(array $d): bool => $d['reason'] === 'retired'), 'key'))->not->toContain('MapImageModule');
    });

    test('is said missing by the status, by grid, with what would give it: the package of the core, else the option of the grid', function () {
        $machine = new Machine();
        $bin = Grid::find($machine->environment, 'demo')->coreDirectory() . '/bin';
        $machine->write("$bin/OpenSim.Region.CoreModules.dll", 'MZ assembly');
        chmod($bin, 0o555);
        $official = W4OS\Engine\OpenSim::status($machine->environment)->simpleMaps();
        chmod($bin, 0o755);
        $machine->write("$bin/OpenSim.Region.CoreModules.dll", 'MZ assembly' . implode('', array_map(static fn(string $c): string => "$c\0", str_split('MapTilesDirectory'))));
        $patched = W4OS\Engine\OpenSim::status($machine->environment)->simpleMaps();

        expect($official)->toBe(['demo' => ['remedy' => 'the package opensim-0.9.3.0-maptiles is installed', 'simulators' => ['alpha', 'beta']]])
            // The core can: the option of the grid is what is left
            ->and($patched)->toBe(['demo' => ['remedy' => null, 'simulators' => ['alpha', 'beta']]]);
    })->skip(fn() => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes everywhere');
});
