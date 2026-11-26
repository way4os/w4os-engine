<?php

declare(strict_types=1);

/**
 * The owner of a grid: the owner of its first estate (the lowest EstateID), an administrator of its sites whatever its user level
 * (OpenSimulator makes a region's owner its god, region_owner_is_god), its user level kept as it is.
 */

use W4OS\Engine\Data\Connections;
use W4OS\Engine\Models\Avatar;

afterEach(fn() => Connections::using(null));

/** The grid of avatar_grid() with a simulator whose database (the one given) holds the estates. */
function owned_grid(PDO $pdo, string $estates): W4OS\Engine\Config\GridConfig
{
    $estates === '' || $pdo->exec('CREATE TABLE estate_settings (EstateID INT, EstateName VARCHAR(64), EstateOwner CHAR(36))');
    $estates === '' || $pdo->exec("INSERT INTO estate_settings VALUES $estates");
    Connections::using(static fn() => $pdo);
    $dir = dirname(avatar_grid()->files()[0]);
    is_dir("$dir/sims") || mkdir("$dir/sims", 0o755, true);
    file_put_contents("$dir/sims/alpha.ini", "[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=alpha;User ID=os;Password=pw;\"\n");

    return W4OS\Engine\Config\GridConfig::fromFolder($dir);
}

describe('The owner of a grid', function () {
    test('is the owner of its first estate: an administrator at level 0, its user level as it is; the others their own level', function () {
        // Ada (level 0) owns the first estate, Cy (level 0) a later one, Bob (250) is a god who owns none
        $config = owned_grid(avatar_database(), "(102, 'Cy estate', '33333333-3333-3333-3333-333333333333'), (101, 'Ada estate', '11111111-1111-1111-1111-111111111111')");
        $rows = array_column(Avatar::all($config)->array(), null, 'name');
        $ada = Avatar::find($config, '11111111-1111-1111-1111-111111111111');

        expect([$rows['Ada Love']['owner'], $rows['Ada Love']['level'], $rows['Ada Love']['accessLevel']])->toBe([true, 0, Avatar::GOD_FULL])
            ->and([$rows['Cy Gone']['owner'], $rows['Cy Gone']['accessLevel']])->toBe([false, 0])
            // A god keeps its own level, higher than an owner's; the owner stays one while gods exist
            ->and([$rows['Bob Root']['owner'], $rows['Bob Root']['accessLevel']])->toBe([false, 250])
            ->and([$ada?->isOwner(), $ada?->accessLevel(), $ada?->level()])->toBe([true, Avatar::GOD_FULL, 0])
            ->and($ada?->detail())->toMatchArray(['owner' => true, 'level' => 0, 'accessLevel' => Avatar::GOD_FULL, 'isGod' => false])
            // The avatar itself, the name the health asks a profile with
            ->and(Avatar::gridOwner($config)?->name())->toBe('Ada Love');
    });

    test('is read from the database of its Robust on a grid whose simulators are not known (the description a site works with)', function () {
        $pdo = avatar_database();
        $pdo->exec('CREATE TABLE estate_settings (EstateID INT, EstateName VARCHAR(64), EstateOwner CHAR(36))');
        $pdo->exec("INSERT INTO estate_settings VALUES (101, 'Ada estate', '11111111-1111-1111-1111-111111111111')");
        Connections::using(static fn() => $pdo);
        $config = avatar_grid();
        $ada = Avatar::find($config, '11111111-1111-1111-1111-111111111111');

        expect($config->tree()['simulators'] ?? [])->toBe([])
            ->and([$ada?->isOwner(), $ada?->accessLevel(), $ada?->level()])->toBe([true, Avatar::GOD_FULL, 0]);
    });

    test('is nobody on a grid whose estates cannot be read: each its own level', function () {
        $rows = array_column(Avatar::all(owned_grid(avatar_database(), ''))->array(), null, 'name');

        expect([$rows['Ada Love']['owner'], $rows['Ada Love']['accessLevel'], $rows['Bob Root']['accessLevel']])->toBe([false, 0, 250])
            // An avatar to ask a page of an avatar with: the oldest active one (Cy is not)
            ->and(Avatar::anyone(owned_grid(avatar_database(), ''))?->name())->toBe('Bob Root');
    });

    test('is described by the contract of the avatars', function () {
        $attributes = W4OS\Engine\Api\Contract::document()['components']['schemas']['Avatars']['properties']['attributes']['properties'] ?? [];

        expect($attributes)->toHaveKeys(['owner', 'accessLevel']);
    });
});
