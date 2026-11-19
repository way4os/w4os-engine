<?php

declare(strict_types=1);

/**
 * A database the grid sets that does not answer is an error (ChannelUnavailable, 503 through the API), never an item that is
 * not there nor an empty list: the profile of an avatar is not "unknown" because its database is down, nor its picks "none".
 * Only a database the grid does not set at all is "none" where the engine has another way (the assets: the asset service).
 */

use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Models\Asset;
use OpenSim\Models\Avatar;
use OpenSim\Models\AvatarModel;
use OpenSim\Models\Region;

const ADA = '11111111-1111-1111-1111-111111111111';

/** The databases of the test, but the ones $down names, which do not answer. */
function databases_down(string|Database ...$down): void
{
    $pdo = avatar_database();
    $names = array_map(static fn(string|Database $what): string => $what instanceof Database ? $what->name : $what, $down);
    Connections::using(static fn(string|Database $what): ?OpenSim\Data\OSPDO => in_array($what instanceof Database ? $what->name : $what, $names, true) ? null : $pdo);
}

/** @return ?string the class of what a call throws, null when it throws nothing */
function thrown(\Closure $call): ?string
{
    try {
        $call();
    } catch (\Throwable $error) {
        return $error::class;
    }

    return null;
}

afterEach(fn() => Connections::using(null));

describe('A database that does not answer', function () {
    test('is an error when an item is asked, not an item that is not there', function () {
        Connections::using(static fn() => null);
        $config = avatar_grid();

        expect([
            thrown(fn() => Avatar::find($config, ADA)),
            thrown(fn() => Avatar::find($config, 'Ada Lovelace')),
            thrown(fn() => Avatar::lookup($config, ADA)),
            thrown(fn() => Region::find($config, 'Welcome')),
            thrown(fn() => AvatarModel::find($config, 'Default Ruth')),
            thrown(fn() => Asset::find($config, ADA)),
        ])->toBe(array_fill(0, 6, ChannelUnavailable::class));
    });

    test('is an error for what the profile of an avatar lists, not an empty list', function () {
        databases_down(Database::Profiles);
        $ada = Avatar::find(avatar_grid(), ADA);

        expect($ada)->not->toBeNull()
            ->and([thrown(fn() => $ada->picks()), thrown(fn() => $ada->classifieds())])
            ->toBe([ChannelUnavailable::class, ChannelUnavailable::class]);

        databases_down(Database::forTable('os_groups_membership') ?? Database::Robust);
        expect(thrown(fn() => Avatar::find(avatar_grid(), ADA)?->groups()))->toBe(ChannelUnavailable::class);
    });

    test('is an error for the places of the guide, not a guide without places', function () {
        $machine = new OpenSim\Testing\Machine();
        // A list the guide cannot read: the places its search knows instead
        $machine->write($machine->grid . '/Helpers.ini', "[Helpers]\n    GuideSource = missing.txt\n");
        databases_down(Database::Search);
        $grid = OpenSim\Models\Grid::find($machine->environment, 'demo');
        // A grid that sets no database of its search has a guide of none (the test machine); one that sets it, an error
        expect($grid->popularPlaces())->toBe([]);

        $config = avatar_grid();

        expect([thrown(fn() => $grid->popularPlaces(config: $config)), thrown(fn() => $grid->destinations($config))])
            ->toBe([ChannelUnavailable::class, ChannelUnavailable::class]);
    });

    test('is a 503 through the API, not a 404', function () {
        $machine = new OpenSim\Testing\Machine();
        Connections::using(static fn() => null);
        // A grid that sets its database
        $config = avatar_grid();
        $router = new OpenSim\Api\Router(new OpenSim\Api\Api($machine->environment, config: static fn(): GridConfig => $config));

        expect([
            $router->get('/grids/demo/avatars/' . ADA)->status,
            $router->get('/grids/demo/avatars/' . ADA . '/picks')->status,
            $router->get('/grids/demo/regions/Welcome')->status,
        ])->toBe([503, 503, 503]);
    });
});

describe('A database the grid does not set', function () {
    test('has none of its items here, as before: an asset is then for the asset service', function () {
        $dir = test_tmp() . '/nodb-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        file_put_contents("$dir/Robust.HG.ini", "[Const]\n BaseURL = \"http://play.example.org\"\n");

        $config = GridConfig::fromFolder($dir);

        expect([Asset::find($config, ADA), Avatar::find($config, ADA), Region::find($config, 'Welcome'), AvatarModel::find($config, 'Default Ruth')])
            ->toBe([null, null, null, null]);
    });
});
