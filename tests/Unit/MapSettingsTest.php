<?php

declare(strict_types=1);

/**
 * The page of the map of the grid (Regions › Map in a site): how the simulators draw their tiles, `[Map]` of the OpenSim.ini of the
 * grid. The 3D map (Warp3DImageModule) only where they can write its tiles, the page saying why not and what gives it.
 */

use W4OS\Engine\Models\Grid;
use W4OS\Engine\Models\GridSettings;
use W4OS\Engine\Testing\Machine;

/** The map modules of the core of the grid of a machine, with MapTilesDirectory or not, its bin read-only. */
function map_settings_core(Machine $machine, bool $folderSetting): string
{
    $bin = Grid::find($machine->environment, 'demo')->coreDirectory() . '/bin';
    @chmod($bin, 0o755);
    $machine->write("$bin/OpenSim.Region.CoreModules.dll", 'MZ assembly' . ($folderSetting ? implode('', array_map(static fn(string $c): string => "$c\0", str_split('MapTilesDirectory'))) : ''));
    chmod($bin, 0o555);

    return $bin;
}

describe('The page of the map', function () {
    test('is under the regions: the module, then what the tiles show, the 3D options for the 3D map only, how often they are made', function () {
        $form = GridSettings::form('map');
        $fields = array_column($form['fields'], null, 'name');
        $warp3d = ['mapImageModule' => ['Warp3DImageModule']];

        expect(GridSettings::groups())->toContain('map')
            ->and($form['parent'] ?? null)->toBe('regions')
            ->and(array_keys($fields))->toBe(['mapImageModule', 'textureOnMapTile', 'drawPrimOnMapTile', 'texturePrims', 'renderMeshes', 'maptileRefresh'])
            ->and($fields['mapImageModule'])->toMatchArray(['type' => 'select', 'options' => ['MapImageModule' => 'map_module_simple', 'Warp3DImageModule' => 'map_module_warp3d']])
            ->and($fields['mapImageModule'])->not->toHaveKey('default')
            ->and($fields['texturePrims']['condition'] ?? null)->toBe($warp3d)
            ->and($fields['renderMeshes']['condition'] ?? null)->toBe($warp3d)
            ->and($fields['textureOnMapTile'])->not->toHaveKey('condition')
            ->and($fields['maptileRefresh']['type'])->toBe('integer');
    });

    test('says why the 3D map cannot be drawn here, and what gives it; nothing where it can', function () {
        $machine = new Machine();
        $bin = map_settings_core($machine, folderSetting: false);
        $official = (new GridSettings(Grid::find($machine->environment, 'demo')))->meta('map');
        map_settings_core($machine, folderSetting: true);
        $patched = (new GridSettings(Grid::find($machine->environment, 'demo')))->meta('map');
        chmod($bin, 0o755);

        expect(array_column($official['notices'] ?? [], 'code'))->toBe(['map-package'])
            ->and($official['notices'][0]['params'])->toBe(['package' => 'opensim-0.9.3.0-maptiles'])
            ->and(W4OS\Engine\I18n\Catalog::MESSAGES['notice_map_package'])->toContain('{package}')
            ->and($patched)->not->toHaveKey('notices');
    })->skip(fn() => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes everywhere');

    test('writes the 3D map with the folder of its tiles where it can be drawn, refuses it where it cannot', function () {
        $machine = new Machine();
        $bin = map_settings_core($machine, folderSetting: false);
        $settings = new GridSettings(Grid::find($machine->environment, 'demo'));
        $before = $settings->read('map');
        $refused = null;
        try {
            $settings->write('map', ['mapImageModule' => 'Warp3DImageModule']);
        } catch (W4OS\Engine\Exceptions\InvalidValue $error) {
            $refused = $error->getMessage();
        }
        map_settings_core($machine, folderSetting: true);
        $patched = new GridSettings(Grid::find($machine->environment, 'demo'));
        $patched->write('map', ['mapImageModule' => 'Warp3DImageModule', 'renderMeshes' => true]);
        chmod($bin, 0o755);
        $map = W4OS\Engine\Config\ConfigReader::file($machine->grid . '/OpenSim.ini')['Map'] ?? [];

        expect($before)->toMatchArray(['mapImageModule' => 'MapImageModule', 'textureOnMapTile' => true])
            ->and($refused)->toContain('opensim-0.9.3.0-maptiles')
            ->and(array_map(static fn(string $v): string => trim($v, '"'), $map))->toMatchArray(['MapImageModule' => 'Warp3DImageModule', 'MapTilesDirectory' => '${Const|CacheDirectory}/maptiles', 'RenderMeshes' => 'true'])
            ->and(array_column($patched->reminders(), 'code'))->toContain('restart-simulators');
    })->skip(fn() => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes everywhere');
});
