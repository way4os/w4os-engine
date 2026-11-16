<?php

declare(strict_types=1);

/**
 * Signing an avatar in with the databases of its grid, as the API offers it to the sites and the command line: a name or an email,
 * and the password Robust keeps.
 */

use OpenSim\Api\Api;
use OpenSim\Api\Cli;
use OpenSim\Api\Router;
use OpenSim\Data\Connections;
use OpenSim\Data\OSPDO;
use OpenSim\Provisioning\AccountWriter;
use OpenSim\SignIn\DatabaseAuthenticator;
use OpenSim\SignIn\SignInResult;

const ADA = '11111111-1111-1111-1111-111111111111';
const CY = '33333333-3333-3333-3333-333333333333';

/** The accounts of the avatars, Ada with the password `ada-secret`, Cy (inactive) with `cy-secret`. */
function signin_database(): OSPDO
{
    $pdo = avatar_creation_database();
    $pdo->exec('DELETE FROM auth');
    foreach ([ADA => 'ada-secret', CY => 'cy-secret'] as $id => $password) {
        $pdo->prepareAndExecute('INSERT INTO auth (UUID, passwordHash, passwordSalt) VALUES (:id, :h, :s)', [
            'id' => $id,
            'h' => AccountWriter::passwordHash($password, 'salt' . $id[0]),
            's' => 'salt' . $id[0],
        ]);
    }

    return $pdo;
}

afterEach(fn() => Connections::using(null));

describe('database of a grid', function () {
    test('grants the avatar whose password is the one Robust keeps, by its name or its email', function () {
        avatars_in(signin_database());
        $auth = new DatabaseAuthenticator(avatar_grid());

        $byName = $auth->authenticate('Ada Love', 'ada-secret');
        $byDot = $auth->authenticate('ada.love', 'ada-secret');
        $byEmail = $auth->authenticate('ADA@example.org', 'ada-secret');

        expect($byName->isGranted())
            ->toBeTrue()
            ->and($byName->avatar->id)
            ->toBe(ADA)
            ->and($byName->avatar->fullName())
            ->toBe('Ada Love')
            ->and($byName->avatar->email)
            ->toBe('ada@example.org')
            ->and($byDot->avatar?->id)
            ->toBe(ADA)
            ->and($byEmail->avatar?->id)
            ->toBe(ADA)
            ->and($auth->opensSession())
            ->toBeFalse();
    });

    test('refuses a wrong password, an unknown avatar, an empty password, alike', function () {
        avatars_in(signin_database());
        $auth = new DatabaseAuthenticator(avatar_grid());

        foreach (
            [
                ['Ada Love', 'wrong'],
                ['Nobody Here', 'ada-secret'],
                ['Ada Love', ''],
                ['', 'ada-secret'],
                ['nobody@example.org', 'ada-secret'],
                ['Ada Love@other.example.org:8002', 'ada-secret'],
            ]
            as [$name, $password]
        ) {
            $result = $auth->authenticate($name, $password);
            expect($result->status)->toBe(SignInResult::REFUSED, "$name / $password")->and($result->reason)->toBe('');
        }
    });

    test('refuses an account that cannot log in, saying so once the password is right', function () {
        avatars_in(signin_database());
        $auth = new DatabaseAuthenticator(avatar_grid());

        $right = $auth->authenticate('Cy Gone', 'cy-secret');
        $wrong = $auth->authenticate('Cy Gone', 'wrong');

        expect($right->status)
            ->toBe(SignInResult::REFUSED)
            ->and($right->reason)
            ->toContain('not active')
            ->and($wrong->reason)
            ->toBe('');
    });

    test('is unavailable when the database of the accounts cannot be reached', function () {
        Connections::using(static fn() => null);

        $result = (new DatabaseAuthenticator(avatar_grid()))->authenticate('Ada Love', 'ada-secret');

        expect($result->status)->toBe(SignInResult::UNAVAILABLE)->and($result->reason)->not->toBe('');
    });
});

describe('The API, for the sign-in of an avatar', function () {
    test('gives the avatar whose name or email and password are right, refuses the others', function () {
        avatars_in(signin_database());
        $router = new Router(machine_api(avatar_grid()));
        $ask = static fn(array $attributes) => $router->post('/grids/demo/sign-ins', [
            'data' => ['type' => 'sign-ins', 'attributes' => $attributes],
        ]);

        $granted = $ask(['name' => 'Ada Love', 'password' => 'ada-secret']);
        $byEmail = $ask(['email' => 'ada@example.org', 'password' => 'ada-secret']);
        $refused = $ask(['name' => 'Ada Love', 'password' => 'wrong']);
        $inactive = $ask(['name' => 'Cy Gone', 'password' => 'cy-secret']);
        $nothing = $ask([]);

        expect($granted->status)
            ->toBe(200)
            ->and($granted->document['data'])
            ->toMatchArray(['type' => 'avatars', 'id' => ADA, 'links' => ['self' => '/grids/demo/avatars/' . ADA]])
            ->and($granted->document['data']['attributes']['name'])
            ->toBe('Ada Love')
            ->and($byEmail->document['data']['id'])
            ->toBe(ADA)
            ->and($refused->status)
            ->toBe(403)
            ->and($refused->document['type'])
            ->toBe('urn:opensim:problem:sign-in-refused')
            ->and($refused->document['detail'])
            ->toBe('The name or the password is wrong.')
            ->and($refused->document['code'])
            ->toBe('sign-in-refused')
            ->and($inactive->status)
            ->toBe(403)
            ->and($inactive->document['detail'])
            ->toContain('not active')
            ->and($inactive->document['code'])
            ->toBe('account-disabled')
            ->and($nothing->status)
            ->toBe(400)
            ->and($nothing->document['code'])
            ->toBe('invalid-request');
    });

    test('tells an avatar that waits for the confirmation of its email from one a manager disabled', function () {
        $pdo = signin_database();
        avatars_in($pdo);
        $pdo->exec('UPDATE UserAccounts SET UserFlags = ' . OpenSim\Models\Avatar::PENDING_FLAG . " WHERE PrincipalID = '" . CY . "'");

        $waiting = (new DatabaseAuthenticator(avatar_grid()))->authenticate('Cy Gone', 'cy-secret');
        $answer = (new Router(machine_api(avatar_grid())))->post('/grids/demo/sign-ins', [
            'data' => ['type' => 'sign-ins', 'attributes' => ['name' => 'Cy Gone', 'password' => 'cy-secret']],
        ]);

        expect($waiting->code)
            ->toBe('email-unconfirmed')
            ->and($answer->status)
            ->toBe(403)
            ->and($answer->document['code'])
            ->toBe('email-unconfirmed')
            ->and($answer->document['detail'])
            ->toContain('not confirmed');
    });

    test('is unavailable without a database, and unknown for a grid that is not here', function () {
        Connections::using(static fn() => null);
        $router = new Router(machine_api(avatar_grid()));
        $body = ['data' => ['type' => 'sign-ins', 'attributes' => ['name' => 'Ada Love', 'password' => 'x']]];

        expect($router->post('/grids/demo/sign-ins', $body)->status)
            ->toBe(503)
            ->and($router->post('/grids/nowhere/sign-ins', $body)->status)
            ->toBe(404)
            ->and($router->get('/grids/demo/sign-ins')->status)
            ->toBe(405);
    });

    test('is closed: it needs an API key, on a host that serves one grid as well', function () {
        avatars_in(signin_database());
        $locked = new Router(
            machine_api(avatar_grid()),
            grid: 'demo',
            authenticator: new OpenSim\Security\Locked(),
        );
        $open = new Router(machine_api(avatar_grid()), grid: 'demo');
        $body = ['data' => ['type' => 'sign-ins', 'attributes' => ['name' => 'Ada Love', 'password' => 'ada-secret']]];

        expect($locked->post('/sign-ins', $body)->status)
            ->toBe(401)
            ->and($open->post('/sign-ins', $body)->document['data']['id'])
            ->toBe(ADA);
    });

    test('opensim api POST /grids/demo/sign-ins name=... password=...', function () {
        avatars_in(signin_database());
        $cli = new Cli(new Router(machine_api(avatar_grid())));

        [$status, $out] = $cli->run(['POST', '/grids/demo/sign-ins', 'name=Ada Love', 'password=ada-secret']);
        [$refused, , $err] = $cli->run(['POST', '/grids/demo/sign-ins', 'name=Ada Love', 'password=nope']);

        expect($status)
            ->toBe(0)
            ->and(json_decode($out, true)['data']['id'])
            ->toBe(ADA)
            ->and($refused)
            ->toBe(1)
            ->and($err)
            ->toStartWith('HTTP 403');
    });
});

describe('The contract, for the sign-in of an avatar', function () {
    test('has the operation, closed, with the avatar it gives and the problems', function () {
        $operation = OpenSim\Api\Contract::document()['paths']['/grids/{grid}/sign-ins']['post'] ?? [];

        expect($operation['operationId'] ?? null)
            ->toBe('signIn')
            ->and($operation)
            ->not->toHaveKey('security')
            ->and(array_keys($operation['responses']))
            ->toBe([200, 400, 403, 404, 503])
            ->and($operation['responses']['200']['content']['application/vnd.api+json']['schema']['properties']['data'])
            ->toBe(['$ref' => '#/components/schemas/Avatars'])
            ->and(
                array_keys(
                    $operation['requestBody']['content']['application/vnd.api+json']['schema']['properties']['data'][
                        'properties'
                    ]['attributes']['properties'],
                ),
            )
            ->toBe(['name', 'email', 'password']);
    });
});
