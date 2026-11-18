<?php

declare(strict_types=1);

/**
 * The web address of a profile, as the grid allows it (AllowUserProfileWebURLs of [UserProfiles], read as OpenSimulator reads it,
 * true when the grid says nothing): the form of the avatar shows it only then, a change ignores it otherwise, the API says it.
 */

use OpenSim\Api\Api;
use OpenSim\Api\Contract;
use OpenSim\Api\Router;
use OpenSim\Config\GridConfig;
use OpenSim\Models\Avatar;
use OpenSim\Testing\Machine;

const WEB_ADA = '11111111-1111-1111-1111-111111111111';

/** The grid of the avatars, its OpenSim.ini this text (none when null). */
function web_urls_grid(?string $opensim, array $files = []): GridConfig
{
    $dir = dirname((string) avatar_grid()->file('Robust'));
    $opensim === null || file_put_contents("$dir/OpenSim.ini", str_replace('{dir}', $dir, $opensim));
    foreach ($files as $name => $text) {
        file_put_contents("$dir/$name", $text);
    }

    return GridConfig::fromFolder($dir);
}

describe('The web address of a profile', function () {
    test('is allowed as OpenSimulator reads it: true by default, its includes and its constants resolved', function () {
        $allowed = static fn(?string $opensim, array $files = []): bool => Avatar::profileWebUrls(web_urls_grid($opensim, $files));

        expect([
            $allowed(null),
            $allowed("[UserProfiles]\n ProfileServiceURL = \"http://play.example.org:8002\"\n"),
            $allowed("[UserProfiles]\n AllowUserProfileWebURLs = false\n"),
            $allowed("[UserProfiles]\n AllowUserProfileWebURLs = No\n"),
            $allowed("[UserProfiles]\n AllowUserProfileWebURLs = \"true\"\n"),
            $allowed("[Const]\n WebLinks = \"false\"\n[UserProfiles]\n AllowUserProfileWebURLs = \"\${Const|WebLinks}\"\n"),
            $allowed("[Includes]\n Include-Profiles = \"{dir}/Profiles.ini\"\n", ['Profiles.ini' => "[UserProfiles]\n AllowUserProfileWebURLs = off\n"]),
            $allowed("[UserProfiles]\n AllowUserProfileWebURLs = maybe\n"),
        ])->toBe([true, true, false, false, true, false, false, true]);
    });

    test('is not given nor changed when the grid does not allow it, as the viewers do', function () {
        $pdo = avatar_creation_database();
        avatars_in($pdo);
        $closed = web_urls_grid("[UserProfiles]\n AllowUserProfileWebURLs = false\n");
        $ada = Avatar::find($closed, 'Ada Love');
        $ada->update(['profileUrl' => 'https://elsewhere.example.org', 'about' => 'Changed']);
        $kept = $pdo->get_var('SELECT profileURL FROM userprofile WHERE useruuid = :id', ['id' => WEB_ADA]);
        $read = Avatar::find($closed, 'Ada Love')->detail();
        Avatar::find(avatar_grid(), 'Ada Love')->update(['profileUrl' => 'https://new.example.org']);

        expect([$kept, $read['about'], $read['profileUrl']])
            ->toBe(['https://ada.example.org', 'Changed', null])
            ->and($pdo->get_var('SELECT profileURL FROM userprofile WHERE useruuid = :id', ['id' => WEB_ADA]))
            ->toBe('https://new.example.org');
    });

    test('is said by the API: the grid, the avatar, and the field of its form that needs it', function () {
        avatars_in(avatar_database());
        [$open] = access_api();
        [$closed] = access_api(web_urls_grid("[UserProfiles]\n AllowUserProfileWebURLs = false\n"));
        $machine = new Machine();
        file_put_contents($machine->grid . '/OpenSim.ini', "[UserProfiles]\n AllowUserProfileWebURLs = false\n");
        $grid = (new Router(new Api($machine->environment)))->get('/grids/demo');
        $fields = array_column(Contract::document()['paths']['/grids/{grid}/avatars/{id}']['get']['x-form']['fields'], null, 'name');

        expect($open->get('/grids/demo/avatars/' . WEB_ADA)->document['meta']['settings'] ?? null)
            ->toBe(['allowUserProfileWebUrls' => true])
            ->and($closed->get('/grids/demo/avatars/' . WEB_ADA)->document['meta']['settings'] ?? null)
            ->toBe(['allowUserProfileWebUrls' => false])
            ->and($grid->document['data']['attributes']['allowUserProfileWebUrls'] ?? null)
            ->toBeFalse()
            ->and($fields['profileUrl']['setting'] ?? null)
            ->toBe('allowUserProfileWebUrls')
            ->and(array_filter(array_column($fields, 'setting', 'name')))
            ->toBe(['profileUrl' => 'allowUserProfileWebUrls']);
    });

    test('is never set by the presets of the options: OpenSimulator keeps its own default', function () {
        $said = [];
        foreach (array_filter(glob(dirname(__DIR__, 2) . '/share/presets/*/*') ?: [], 'is_file') as $file) {
            stripos((string) file_get_contents($file), 'AllowUserProfileWebURLs') === false || ($said[] = basename(dirname($file)) . '/' . basename($file));
        }

        expect($said)->toBe([]);
    });
});
