<?php

declare(strict_types=1);

/**
 * The avatar models of a grid, as w4os has them: existing avatars picked by a rule of `[Models]` (a first name, a last name, either,
 * or a list of UUIDs; "Default" by default), shown with their profile picture, whose outfit and appearance a new avatar gets.
 */

use OpenSim\Api\Router;
use OpenSim\Data\Connections;
use OpenSim\Data\OSPDO;
use OpenSim\Models\Avatar;
use OpenSim\Models\AvatarModel;

const RUTH = 'aaaaaaaa-0000-4000-8000-000000000001';
const ROTH = 'aaaaaaaa-0000-4000-8000-000000000002';
const JANE = 'aaaaaaaa-0000-4000-8000-000000000003';
const OFF = 'aaaaaaaa-0000-4000-8000-000000000004';

/**
 * The accounts of avatar_creation_database() and four models: Default Ruth (with her outfit, her appearance and a picture), Default
 * Roth (no appearance), Jane Default, and Default Off (not active).
 */
function model_database(): OSPDO
{
    $pdo = avatar_creation_database();
    $pdo->exec('CREATE TABLE Avatars (PrincipalID CHAR(36), Name VARCHAR(32), Value TEXT)');
    $pdo->exec("INSERT INTO UserAccounts (PrincipalID, FirstName, LastName, Email, Created, active) VALUES
        ('" . RUTH . "', 'Default', 'Ruth', '', 1500000000, 1),
        ('" . ROTH . "', 'Default', 'Roth', '', 1500000001, 1),
        ('" . JANE . "', 'Jane', 'Default', '', 1500000002, 1),
        ('" . OFF . "', 'Default', 'Off', '', 1500000003, 0)");
    $pdo->exec(
        "INSERT INTO userprofile (useruuid, profileImage, profileAboutText) VALUES ('" .
            RUTH .
            "', 'bbbbbbbb-0000-4000-8000-00000000000f', 'Ruth 2.0'), ('" .
            JANE .
            "', '00000000-0000-0000-0000-000000000000', '')",
    );
    // Ruth's inventory: her shape and her skin worn, a hat attached, a sweater she does not wear
    foreach (
        [
            ['cccccccc-0000-4000-8000-000000000001', 'dddddddd-0000-4000-8000-000000000001', 13, 'Ruth Shape', 0],
            ['cccccccc-0000-4000-8000-000000000002', 'dddddddd-0000-4000-8000-000000000002', 13, 'Ruth Skin', 1],
            ['cccccccc-0000-4000-8000-000000000003', 'dddddddd-0000-4000-8000-000000000003', 6, 'Ruth Hat', 0],
            ['cccccccc-0000-4000-8000-000000000004', 'dddddddd-0000-4000-8000-000000000004', 5, 'Sweater', 4],
        ]
        as [$item, $asset, $type, $name, $flags]
    ) {
        $pdo->exec(
            "INSERT INTO inventoryitems (assetID, assetType, inventoryName, inventoryDescription, inventoryNextPermissions, inventoryCurrentPermissions, invType, creatorID, inventoryBasePermissions, inventoryEveryOnePermissions, salePrice, saleType, creationDate, groupID, groupOwned, flags, inventoryID, avatarID, parentFolderID, inventoryGroupPermissions) VALUES ('$asset', $type, '$name', '', 581632, 581632, 18, '" .
                RUTH .
                "', 581632, 0, 0, 0, 1500000000, '00000000-0000-0000-0000-000000000000', 0, $flags, '$item', '" .
                RUTH .
                "', 'eeeeeeee-0000-4000-8000-000000000001', 0)",
        );
    }
    $pdo->exec("INSERT INTO Avatars (PrincipalID, Name, Value) VALUES
        ('" . RUTH . "', 'AvatarType', '1'),
        ('" . RUTH . "', 'Serial', '12'),
        ('" . RUTH . "', 'VisualParams', '33,61,85'),
        ('" . RUTH . "', 'Wearable 0:0', 'cccccccc-0000-4000-8000-000000000001:dddddddd-0000-4000-8000-000000000001'),
        ('" . RUTH . "', 'Wearable 1:0', 'cccccccc-0000-4000-8000-000000000002:dddddddd-0000-4000-8000-000000000002'),
        ('" . RUTH . "', 'Wearable 4:0', 'ffffffff-0000-4000-8000-000000000009:dddddddd-0000-4000-8000-000000000009'),
        ('" . RUTH . "', '_ap_2', 'cccccccc-0000-4000-8000-000000000003')");

    return $pdo;
}

afterEach(fn() => Connections::using(null));

describe('avatar models', function () {
    test('are the active avatars named Default, first or last name, by default', function () {
        avatars_in(model_database());

        expect(array_column(AvatarModel::all(avatar_grid())->array(), 'name'))->toBe([
            'Default Roth',
            'Default Ruth',
            'Jane Default',
        ]);
    });

    test('follow the rule of [Models]: the first name, the last name, or a list', function () {
        avatars_in(model_database());
        $names = static fn(string $models): array => array_column(
            AvatarModel::all(avatar_grid("[Models]\n$models\n"))->array(),
            'name',
        );

        expect($names("Match = first\nMatchPattern = Default"))
            ->toBe(['Default Roth', 'Default Ruth'])
            ->and($names("Match = last\nMatchPattern = Default"))
            ->toBe(['Jane Default'])
            ->and($names("Match = any\nMatchPattern = Jane"))
            ->toBe(['Jane Default'])
            ->and($names('Match = uuid' . "\nList = \"" . JANE . ', ' . RUTH . '"'))
            ->toBe(['Default Ruth', 'Jane Default'])
            ->and($names("Match = uuid\nList = \"" . OFF . '"'))
            ->toBe([])
            ->and($names('Match = uuid'))
            ->toBe([]);
    });

    test('are previewed with a rule asked, not the one of the grid: the filters match, pattern and list', function () {
        avatars_in(model_database());
        $names = static fn(array $filters): array => array_column(AvatarModel::all(avatar_grid(), ['filters' => $filters])->array(), 'name');

        expect($names(['match' => 'last', 'pattern' => 'Default']))
            ->toBe(['Jane Default'])
            ->and($names(['match' => 'uuid', 'list' => JANE . ',' . RUTH]))
            ->toBe(['Default Ruth', 'Jane Default'])
            // What is not asked is the rule of the grid
            ->and($names(['pattern' => 'Jane']))
            ->toBe(['Jane Default'])
            ->and(fn() => $names(['match' => 'middle']))
            ->toThrow(OpenSim\Exceptions\InvalidValue::class);
    });

    test('have the filters of the preview in the contract, a list that has no pages', function () {
        $parameters = array_column(OpenSim\Api\Contract::document()['paths']['/grids/{grid}/avatar-models']['get']['parameters'], null, 'name');

        expect(array_keys($parameters))
            ->toBe(['grid', 'filter[match]', 'filter[pattern]', 'filter[list]'])
            ->and($parameters['filter[match]']['schema']['enum'] ?? null)
            ->toBe(['first', 'any', 'last', 'uuid']);
    });

    test('show their name without the pattern, their profile picture, a placeholder when they have none', function () {
        avatars_in(model_database());
        $ruth = AvatarModel::find(avatar_grid(), RUTH)->detail();
        $jane = AvatarModel::find(avatar_grid(), 'Jane Default')->detail();

        expect($ruth)
            ->toMatchArray([
                'uuid' => RUTH,
                'name' => 'Default Ruth',
                'slug' => 'default-ruth',
                'firstName' => 'Default',
                'lastName' => 'Ruth',
                'label' => 'Ruth',
                'profileImage' => 'bbbbbbbb-0000-4000-8000-00000000000f',
                'picture' => 'bbbbbbbb-0000-4000-8000-00000000000f',
                'about' => 'Ruth 2.0',
            ])
            ->and($jane['label'])
            ->toBe('Jane')
            ->and($jane['profileImage'])
            ->toBeNull()
            ->and($jane['picture'])
            ->toBe(AvatarModel::PLACEHOLDER);
    });

    test('are found by their UUID or their name, only when the rule picks them', function () {
        avatars_in(model_database());

        expect(AvatarModel::find(avatar_grid(), 'Default Ruth')?->id())
            ->toBe(RUTH)
            ->and(AvatarModel::find(avatar_grid(), 'Ada Love'))
            ->toBeNull()
            ->and(AvatarModel::find(avatar_grid(), OFF))
            ->toBeNull()
            ->and(AvatarModel::find(avatar_grid(), '11111111-1111-1111-1111-111111111111'))
            ->toBeNull();
    });
});

describe('An avatar made from a model', function () {
    test('wears a copy of its outfit: the items in a folder of their own, linked in the Current Outfit', function () {
        $pdo = model_database();
        avatars_in($pdo);
        $new = Avatar::create(avatar_grid(), ['name' => 'New Person', 'password' => 'secret', 'model' => 'Default Ruth']);
        $id = $new->id();
        $folder = static fn(string $name) => $pdo->get_var(
            'SELECT folderID FROM inventoryfolders WHERE agentID = :id AND folderName = :name',
            ['id' => $id, 'name' => $name],
        );
        $items = $pdo->get_results(
            'SELECT inventoryID, assetID, assetType, inventoryName, parentFolderID, flags, creatorID FROM inventoryitems WHERE avatarID = :id ORDER BY inventoryName, assetType',
            ['id' => $id],
            PDO::FETCH_ASSOC,
        );
        $copies = array_values(array_filter($items, static fn(array $item): bool => (int) $item['assetType'] !== 24));
        $links = array_values(array_filter($items, static fn(array $item): bool => (int) $item['assetType'] === 24));

        expect($pdo->get_var('SELECT parentFolderID FROM inventoryfolders WHERE agentID = :id AND folderName = :n', [
            'id' => $id,
            'n' => 'Default Ruth outfit',
        ]))
            ->toBe($folder('Body Parts'))
            ->and(array_column($copies, 'inventoryName'))
            ->toBe(['Ruth Hat', 'Ruth Shape', 'Ruth Skin'])
            ->and(array_unique(array_column($copies, 'parentFolderID')))
            ->toBe([$folder('Default Ruth outfit')])
            ->and(array_column($copies, 'assetID'))
            ->toBe([
                'dddddddd-0000-4000-8000-000000000003',
                'dddddddd-0000-4000-8000-000000000001',
                'dddddddd-0000-4000-8000-000000000002',
            ])
            ->and(array_column($copies, 'creatorID'))
            ->toBe([RUTH, RUTH, RUTH])
            ->and(array_intersect(array_column($copies, 'inventoryID'), ['cccccccc-0000-4000-8000-000000000001']))
            ->toBe([])
            ->and(array_column($links, 'assetID'))
            ->toEqualCanonicalizing(array_column($copies, 'inventoryID'))
            ->and(array_unique(array_column($links, 'parentFolderID')))
            ->toBe([$folder('Current Outfit')])
            ->and(array_column($items, 'inventoryName'))
            ->not->toContain('Default Eyes', 'Sweater');
    });

    test('has its appearance, pointing at its own copies; a wearable the model lost is left out', function () {
        $pdo = model_database();
        avatars_in($pdo);
        $id = Avatar::create(avatar_grid(), ['name' => 'New Person', 'password' => 'secret', 'model' => RUTH])->id();
        $appearance = array_column(
            $pdo->get_results('SELECT Name, Value FROM Avatars WHERE PrincipalID = :id', ['id' => $id], PDO::FETCH_ASSOC),
            'Value',
            'Name',
        );
        $copy = static fn(string $name) => $pdo->get_var(
            'SELECT inventoryID FROM inventoryitems WHERE avatarID = :id AND inventoryName = :n AND assetType != 24',
            ['id' => $id, 'n' => $name],
        );

        expect(array_keys($appearance))
            ->toEqualCanonicalizing(['AvatarType', 'Serial', 'VisualParams', 'Wearable 0:0', 'Wearable 1:0', '_ap_2'])
            ->and($appearance['VisualParams'])
            ->toBe('33,61,85')
            ->and($appearance['Wearable 0:0'])
            ->toBe($copy('Ruth Shape') . ':dddddddd-0000-4000-8000-000000000001')
            ->and($appearance['Wearable 1:0'])
            ->toBe($copy('Ruth Skin') . ':dddddddd-0000-4000-8000-000000000002')
            ->and($appearance['_ap_2'])
            ->toBe($copy('Ruth Hat'));
    });

    test('wears the default outfit when the model has no appearance yet', function () {
        $pdo = model_database();
        avatars_in($pdo);
        $id = Avatar::create(avatar_grid(), ['name' => 'New Person', 'password' => 'secret', 'model' => ROTH])->id();

        expect((int) $pdo->get_var('SELECT COUNT(*) FROM inventoryitems WHERE avatarID = :id', ['id' => $id]))
            ->toBe(12)
            ->and((int) $pdo->get_var('SELECT COUNT(*) FROM Avatars WHERE PrincipalID = :id', ['id' => $id]))
            ->toBe(0);
    });

    test('is refused when the model is not one, before anything is written', function () {
        $pdo = model_database();
        avatars_in($pdo);

        expect(
            fn() => Avatar::create(avatar_grid(), ['name' => 'New Person', 'password' => 'secret', 'model' => 'Ada Love']),
        )->toThrow(InvalidArgumentException::class, 'not an avatar model');
        expect(Avatar::find(avatar_grid(), 'New Person'))->toBeNull();
    });
});

describe('The avatars of a grid, without its models', function () {
    test('are the newest people: no model, no technical account, an email', function () {
        avatars_in(model_database());
        $config = avatar_grid();
        $names = static fn(array $filters, string $sort = 'name') => array_column(
            Avatar::all($config, ['filters' => $filters, 'sort' => $sort])->array(),
            'name',
        );

        expect($names(['model' => 'yes']))
            ->toBe(['Default Roth', 'Default Ruth', 'Jane Default'])
            ->and($names(['model' => 'no', 'email' => 'yes', 'technical' => 'no'], 'created desc'))
            ->toBe(['Ada Love', 'Cy Gone', 'Bob Root'])
            ->and($names(['email' => 'no']))
            ->toBe(['Default Off', 'Default Roth', 'Default Ruth', 'Jane Default']);
    });
});

describe('The API, for the avatar models', function () {
    test('lists them whole, reads one, and makes an avatar from one', function () {
        avatars_in(model_database());
        $router = new Router(machine_api(avatar_grid()));

        $list = $router->get('/grids/demo/avatar-models');
        $one = $router->get('/grids/demo/avatar-models/' . RUTH);
        $none = $router->get('/grids/demo/avatar-models/Ada%20Love');
        $made = $router->post('/grids/demo/avatars', [
            'data' => [
                'type' => 'avatars',
                'attributes' => ['name' => 'Api Person', 'password' => 'secret', 'model' => 'Default Ruth'],
            ],
        ]);
        $refused = $router->post('/grids/demo/avatars', [
            'data' => [
                'type' => 'avatars',
                'attributes' => ['name' => 'Other Person', 'password' => 'secret', 'model' => 'Ada Love'],
            ],
        ]);

        expect($list->status)
            ->toBe(200)
            ->and(array_column($list->document['data'], 'id'))
            ->toBe([ROTH, RUTH, JANE])
            ->and($list->document['meta']['total'])
            ->toBe(3)
            ->and($one->document['data'])
            ->toMatchArray([
                'type' => 'avatar-models',
                'id' => RUTH,
                'links' => ['self' => '/grids/demo/avatar-models/' . RUTH],
            ])
            ->and($one->document['data']['attributes']['picture'])
            ->toBe('bbbbbbbb-0000-4000-8000-00000000000f')
            ->and($none->status)
            ->toBe(404)
            ->and($made->status)
            ->toBe(201)
            ->and($refused->status)
            ->toBe(400);
    });

    test('are in the contract: a list and a read, and the model of a new avatar', function () {
        $paths = OpenSim\Api\Contract::document()['paths'];

        expect(array_keys($paths['/grids/{grid}/avatar-models']))
            ->toBe(['get'])
            ->and(array_keys($paths['/grids/{grid}/avatar-models/{id}']))
            ->toBe(['get'])
            ->and($paths['/grids/{grid}/avatar-models']['get']['operationId'])
            ->toBe('listAvatarModels')
            ->and(
                $paths['/grids/{grid}/avatars']['post']['requestBody']['content']['application/vnd.api+json']['schema'][
                    'properties'
                ]['data']['properties']['attributes']['properties'],
            )
            ->toHaveKey('model');
    });
});
