<?php

declare(strict_types=1);

/**
 * What lets the people of a grid back in, from a site or the command line: the one-time tokens, the forgotten password (a mail with a
 * link, then a new password) and the confirmation of the email of a new avatar (made inactive, activated by the link of its mail).
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Cli;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Connections;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Models\Avatar;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\AccountWriter;
use W4OS\Engine\Services\MailerInterface;
use W4OS\Engine\SignIn\AvatarAccess;
use W4OS\Engine\SignIn\AvatarTokens;
use W4OS\Engine\SignIn\DatabaseAuthenticator;
use W4OS\Engine\Testing\ClockFake;
use W4OS\Engine\Testing\Machine;

/** A mailer that keeps the mails, and refuses them when it is told to. */
final class MailerFake implements MailerInterface
{
    /** @var list<array{to:string,subject:string,html:string,from:string}> */
    public array $sent = [];
    public bool $broken = false;

    public function send(string $to, string $subject, string $html, string $from, string $text = ''): bool
    {
        if ($this->broken) {
            return false;
        }
        $this->sent[] = compact('to', 'subject', 'html', 'from');

        return true;
    }

    /** The token of the last mail, as its code says it. */
    public function token(): string
    {
        preg_match('/code: <code>([0-9a-f]+)<\/code>/', end($this->sent)['html'] ?? '', $m);

        return $m[1] ?? '';
    }
}

/**
 * A router over the test machine, the config of its grid being the one given, with a mailer and a clock.
 *
 * @return array{0:Router,1:MailerFake,2:ClockFake}
 */
function access_api(?GridConfig $config = null, ?MailerFake $mailer = null): array
{
    $machine = new Machine();
    $machine->clock->now = 1_800_000_000.0;
    $mailer ??= new MailerFake();
    $config ??= avatar_grid();

    return [
        new Router(new Api($machine->environment, null, static fn(Grid $grid): GridConfig => $config, null, $mailer)),
        $mailer,
        $machine->clock,
    ];
}

/** A JSON:API document of a type with these attributes. */
function access_body(string $type, array $attributes): array
{
    return ['data' => ['type' => $type, 'attributes' => $attributes]];
}

/** The rows of the grid an avatar is made of, by table. @return array<string,int> */
function grid_rows(OSPDO $pdo): array
{
    $rows = [];
    foreach (['UserAccounts', 'auth', 'GridUser', 'userprofile', 'inventoryfolders', 'inventoryitems'] as $table) {
        $rows[$table] = (int) $pdo->get_var("SELECT COUNT(*) FROM $table");
    }

    return $rows;
}

/** The registrations that wait for the confirmation of their email, as they are kept. @return list<array<string,mixed>> */
function registration_rows(OSPDO $pdo): array
{
    return $pdo->table_exists('avatar_registrations')
        ? $pdo->get_results('SELECT * FROM avatar_registrations ORDER BY created, email', [], PDO::FETCH_ASSOC) ?: []
        : [];
}

afterEach(fn() => Connections::using(null));

describe('one-time tokens of the avatars', function () {
    test('are given once, kept hashed, and taken back once, before they expire', function () {
        $pdo = signin_database();
        $clock = new ClockFake();
        $tokens = new AvatarTokens($pdo, $clock);

        $token = $tokens->issue(ADA, 'password-reset', 3600, 'ada@example.org');
        $kept = $pdo->get_row('SELECT * FROM avatar_tokens', [], PDO::FETCH_ASSOC);

        expect($token)
            ->toMatch('/^[0-9a-f]{32}$/')
            ->and($kept['token'])
            ->toBe(hash('sha256', $token))
            ->and($kept)
            ->toMatchArray(['avatar' => ADA, 'purpose' => 'password-reset', 'email' => 'ada@example.org'])
            ->and($tokens->redeem($token, 'email-confirmation'))
            ->toBeNull()
            ->and($tokens->redeem($token, 'password-reset'))
            ->toBe(['avatar' => ADA, 'email' => 'ada@example.org'])
            ->and($tokens->redeem($token, 'password-reset'))
            ->toBeNull();

        $late = $tokens->issue(ADA, 'password-reset', 3600);
        $clock->now += 3601;
        expect($tokens->redeem($late, 'password-reset'))->toBeNull();
    });

    test('are one at a time for an avatar and a purpose: a new one replaces the last', function () {
        $tokens = new AvatarTokens(signin_database(), new ClockFake());

        $first = $tokens->issue(ADA, 'password-reset', 3600);
        $second = $tokens->issue(ADA, 'password-reset', 3600);
        $other = $tokens->issue(CY, 'password-reset', 3600);

        expect($tokens->redeem($first, 'password-reset'))
            ->toBeNull()
            ->and($tokens->redeem($second, 'password-reset')['avatar'])
            ->toBe(ADA)
            ->and($tokens->redeem($other, 'password-reset')['avatar'])
            ->toBe(CY);
    });

    test('say what is pending, expired or not, until it is forgotten', function () {
        $clock = new ClockFake();
        $tokens = new AvatarTokens(signin_database(), $clock);

        $tokens->issue(CY, 'email-confirmation', 60);
        $clock->now += 120;

        expect($tokens->pending(CY, 'email-confirmation'))
            ->toBeTrue()
            ->and($tokens->pending(ADA, 'email-confirmation'))
            ->toBeFalse();
        $tokens->forget(CY, 'email-confirmation');
        expect($tokens->pending(CY, 'email-confirmation'))->toBeFalse();
    });
});

describe('The API, for a forgotten password', function () {
    test('mails a link to choose a new one, by the name or the email, and says the same for nobody', function () {
        avatars_in(signin_database());
        [$router, $mailer] = access_api();

        $byName = $router->post(
            '/grids/demo/password-resets',
            access_body('password-resets', ['name' => 'Ada Love', 'link' => 'https://site.example.org/reset?key={token}']),
        );
        $byEmail = $router->post('/grids/demo/password-resets', access_body('password-resets', ['email' => 'ADA@example.org']));
        $nobody = $router->post('/grids/demo/password-resets', access_body('password-resets', ['name' => 'Nobody Here']));

        expect($byName->status)
            ->toBe(202)
            ->and($nobody->status)
            ->toBe(202)
            ->and($nobody->document)
            ->toBe($byName->document)
            // Said in English, and as a notice for a site to say it in its language
            ->and($byName->document['meta']['notice']['code'])
            ->toBe('mail-sent-if-known')
            ->and($byName->document['meta']['message'])
            ->toBe($byName->document['meta']['notice']['detail'])
            ->and($mailer->sent)
            ->toHaveCount(2)
            ->and($mailer->sent[0]['to'])
            ->toBe('ada@example.org')
            ->and($mailer->sent[0]['from'])
            ->toBe('Demo <no-reply@localhost>')
            ->and($mailer->sent[0]['subject'])
            ->toContain('Ada Love')
            ->and($mailer->sent[0]['html'])
            ->toMatch('#href="https://site\.example\.org/reset\?key=[0-9a-f]{32}"#')
            ->and($mailer->sent[1]['html'])
            ->not->toContain('href=')
            ->toContain('code: <code>');
    });

    test('takes the page of the grid for the link when none is given, and the sender it says', function () {
        avatars_in(signin_database());
        [$router, $mailer] = access_api(
            avatar_grid(
                "[GridInfoService]\n password = \"https://grid.example.org/password\"\n[Helpers]\n MailSender = \"grid@example.org\"\n",
            ),
        );

        $router->post('/grids/demo/password-resets', access_body('password-resets', ['name' => 'Ada Love']));

        expect($mailer->sent[0]['html'])
            ->toMatch('#href="https://grid\.example\.org/password\?token=[0-9a-f]{32}"#')
            ->and($mailer->sent[0]['from'])
            ->toBe('Demo <grid@example.org>');
    });

    test('changes the password with the token of the mail, once, and not after a day', function () {
        avatars_in(signin_database());
        [$router, $mailer, $clock] = access_api();
        $complete = static fn(string $token, string $password) => $router->post(
            '/grids/demo/password-resets/complete',
            access_body('password-resets', ['token' => $token, 'password' => $password]),
        );

        $router->post('/grids/demo/password-resets', access_body('password-resets', ['name' => 'Ada Love']));
        $token = $mailer->token();
        $weak = $complete($token, 'no');
        $done = $complete($token, 'brand-new');
        $again = $complete($token, 'other-one');
        $router->post('/grids/demo/password-resets', access_body('password-resets', ['name' => 'Ada Love']));
        $clock->now += 86401;
        $late = $complete($mailer->token(), 'brand-new');

        expect($weak->status)
            ->toBe(400)
            ->and($weak->document['detail'])
            ->toContain('too short')
            ->and($done->status)
            ->toBe(200)
            ->and($done->document['data']['id'])
            ->toBe(ADA)
            ->and((new DatabaseAuthenticator(avatar_grid()))->authenticate('Ada Love', 'brand-new')->isGranted())
            ->toBeTrue()
            ->and($again->status)
            ->toBe(403)
            ->and($again->document['type'])
            ->toBe('urn:opensim:problem:token-refused')
            ->and($late->status)
            ->toBe(403);
    });

    test('gives the token instead of mailing it when it is asked (send=false), for whoever delivers it', function () {
        avatars_in(signin_database());
        [$router, $mailer] = access_api();

        $given = $router->post(
            '/grids/demo/password-resets',
            access_body('password-resets', ['name' => 'Ada Love', 'send' => false]),
        );
        $nobody = $router->post(
            '/grids/demo/password-resets',
            access_body('password-resets', ['name' => 'Nobody Here', 'send' => false]),
        );
        $token = $given->document['data'][0]['attributes']['token'];

        expect($given->status)
            ->toBe(200)
            ->and($given->document['data'][0])
            ->toMatchArray(['type' => 'password-resets', 'id' => ADA])
            ->and($given->document['data'][0]['attributes'])
            ->toMatchArray(['avatar' => ADA, 'name' => 'Ada Love', 'expires' => 1_800_086_400, 'sent' => false])
            ->and($mailer->sent)
            ->toBe([])
            ->and($nobody->status)
            ->toBe(404)
            ->and(
                $router->post(
                    '/grids/demo/password-resets/complete',
                    access_body('password-resets', ['token' => $token, 'password' => 'brand-new']),
                )->status,
            )
            ->toBe(200);
    });

    test('has no link for an avatar without an email, a model or a technical account: silent when mailed, refused when its token is asked', function () {
        $pdo = signin_database();
        $pdo->exec("INSERT INTO UserAccounts (PrincipalID, FirstName, LastName, Email, Created, active) VALUES ('44444444-4444-4444-8444-444444444444', 'Default', 'Ruth', '', 1500000000, 1)");
        avatars_in($pdo);
        [$router, $mailer] = access_api();
        $ask = static fn(array $attributes) => $router->post(
            '/grids/demo/password-resets',
            access_body('password-resets', ['name' => 'Default Ruth'] + $attributes),
        );

        $mailed = $ask([]);
        $given = $ask(['send' => false]);

        expect([$mailed->status, $mailed->document['code'] ?? null])
            ->toBe([202, null])
            ->and([$given->status, $given->document['code'] ?? null])
            ->toBe([400, 'email-required'])
            ->and($mailer->sent)
            ->toBe([]);
    });

    test('opensim api: an administrator resets a password from the command line', function () {
        avatars_in(signin_database());
        [$router] = access_api();
        $cli = new Cli($router);

        [$status, $out] = $cli->run(['POST', '/grids/demo/password-resets', 'name=Ada Love', 'send=false']);
        $token = json_decode($out, true)['data'][0]['attributes']['token'];
        [$done] = $cli->run(['POST', '/grids/demo/password-resets/complete', "token=$token", 'password=brand-new']);

        expect($status)->toBe(0)->and($done)->toBe(0);
    });
});

describe('The API, for a new avatar whose email is to be confirmed', function () {
    test('keeps only the request, its password hashed, until the link of its mail makes the avatar, active, once', function () {
        $pdo = signin_database();
        avatars_in($pdo);
        [$router, $mailer] = access_api();
        $before = grid_rows($pdo);

        $asked = $router->post(
            '/grids/demo/avatars',
            access_body('avatars', [
                'name' => 'New Person',
                'email' => 'new@example.org',
                'password' => 'secret',
                'confirmEmail' => true,
                'link' => 'https://site.example.org/confirm',
            ]),
        );
        $token = $mailer->token();
        $kept = registration_rows($pdo);
        $waiting = grid_rows($pdo);
        $refused = (new DatabaseAuthenticator(avatar_grid()))->authenticate('New Person', 'secret');
        $confirmed = $router->post(
            '/grids/demo/email-confirmations/complete',
            access_body('email-confirmations', ['token' => $token]),
        );
        $again = $router->post(
            '/grids/demo/email-confirmations/complete',
            access_body('email-confirmations', ['token' => $token]),
        );

        expect($asked->status)
            ->toBe(202)
            ->and($asked->document['data'])
            ->toMatchArray(['type' => 'registrations', 'id' => $kept[0]['avatar'] ?? null])
            ->and($asked->document['data']['attributes'])
            ->toMatchArray(['name' => 'New Person', 'email' => 'new@example.org', 'expires' => 1_800_172_800])
            ->and($asked->document['meta'])
            ->toMatchArray(['emailConfirmation' => 'sent'])
            // Said as a notice too, for a site to say it in its language
            ->and($asked->document['meta']['notice'])
            ->toMatchArray(['code' => 'registered-confirm', 'params' => ['email' => 'new@example.org']])
            ->and($mailer->sent[0]['to'])
            ->toBe('new@example.org')
            ->and($mailer->sent[0]['html'])
            ->toContain('href="https://site.example.org/confirm?token=' . $token . '"')
            ->and($waiting)
            ->toBe($before)
            ->and($kept)
            ->toHaveCount(1)
            ->and(implode(' ', $kept[0]))
            ->not->toContain('secret')
            ->and($kept[0]['password_hash'])
            ->toBe(AccountWriter::passwordHash('secret', $kept[0]['password_salt']))
            ->and($refused->isGranted())
            ->toBeFalse()
            ->and($confirmed->status)
            ->toBe(200)
            ->and($confirmed->document['data'])
            ->toMatchArray(['type' => 'avatars', 'id' => $kept[0]['avatar']])
            ->and($confirmed->document['data']['attributes'])
            ->toMatchArray(['status' => 'active', 'email' => 'new@example.org', 'published' => true])
            ->and((new DatabaseAuthenticator(avatar_grid()))->authenticate('New Person', 'secret')->isGranted())
            ->toBeTrue()
            ->and(registration_rows($pdo))
            ->toBe([])
            ->and($again->status)
            ->toBe(403);
    });

    test('checks the whole form at once, and keeps nothing of what it refuses', function () {
        $pdo = signin_database();
        avatars_in($pdo);
        [$router, $mailer] = access_api();
        $new = ['name' => 'New Person', 'email' => 'new@example.org', 'password' => 'secret', 'confirmEmail' => true];
        $code = static fn(array $attributes): ?string => $router->post(
            '/grids/demo/avatars',
            access_body('avatars', $attributes + $new),
        )->document['code'] ?? null;

        expect([
            $code(['name' => 'Nobody']),
            $code(['name' => 'Admin Person']),
            $code(['name' => 'Ada Love']),
            $code(['password' => 'no']),
            $code(['password' => '']),
            $code(['email' => '']),
            $code(['model' => 'Nobody Here']),
        ])
            ->toBe([
                'name-invalid',
                'name-reserved',
                'name-taken',
                'password-invalid',
                'password-required',
                'email-required',
                'model-unknown',
            ])
            ->and(registration_rows($pdo))
            ->toBe([])
            ->and($mailer->sent)
            ->toBe([]);
    });

    test('gives the name its capitals before it checks it, as the avatar will have it', function () {
        avatars_in(signin_database());
        [$router] = access_api();
        $ask = static fn(string $name) => $router->post(
            '/grids/demo/avatars',
            access_body('avatars', [
                'name' => $name,
                'email' => 'new@example.org',
                'password' => 'secret',
                'confirmEmail' => true,
                'send' => false,
            ]),
        )->document;

        expect($ask('bart simpson')['data']['attributes'])
            ->toMatchArray(['name' => 'Bart Simpson', 'firstName' => 'Bart', 'lastName' => 'Simpson'])
            ->and($ask('ADA LOVE')['code'] ?? null)
            ->toBe('name-taken');
    });

    test('keeps no name before its link: the first confirmed gets it, the other link says it is taken', function () {
        $pdo = signin_database();
        avatars_in($pdo);
        [$router] = access_api();
        $ask = static fn(string $email): string => $router->post(
            '/grids/demo/avatars',
            access_body('avatars', [
                'name' => 'New Person',
                'email' => $email,
                'password' => 'secret',
                'confirmEmail' => true,
                'send' => false,
            ]),
        )->document['meta']['confirmation']['token'] ?? '';
        $confirm = static fn(string $token) => $router->post(
            '/grids/demo/email-confirmations/complete',
            access_body('email-confirmations', ['token' => $token]),
        );
        $first = $ask('one@example.org');
        $second = $ask('two@example.org');

        $won = $confirm($second);
        $lost = $confirm($first);
        $again = $confirm($first);

        expect($won->status)
            ->toBe(200)
            ->and($won->document['data']['attributes']['email'])
            ->toBe('two@example.org')
            ->and($lost->status)
            ->toBe(400)
            ->and($lost->document['code'])
            ->toBe('name-taken')
            ->and($again->document['code'])
            ->toBe('token-refused')
            ->and(registration_rows($pdo))
            ->toBe([]);
    });

    test('forgets a request once it expired, and the same request asked again replaces the last', function () {
        $pdo = signin_database();
        avatars_in($pdo);
        [$router, , $clock] = access_api();
        $ask = static fn(string $name, string $email): string => $router->post(
            '/grids/demo/avatars',
            access_body('avatars', [
                'name' => $name,
                'email' => $email,
                'password' => 'secret',
                'confirmEmail' => true,
                'send' => false,
            ]),
        )->document['meta']['confirmation']['token'] ?? '';
        $confirm = static fn(string $token) => $router->post(
            '/grids/demo/email-confirmations/complete',
            access_body('email-confirmations', ['token' => $token]),
        );
        $old = $ask('Old Person', 'old@example.org');
        $ask('Bot Person', 'bot@example.org');
        $clock->now += AvatarAccess::CONFIRMATION_TTL + 1;

        $late = $confirm($old);
        $left = registration_rows($pdo);
        $first = $ask('New Person', 'new@example.org');
        $second = $ask('New Person', 'NEW@example.org');
        $kept = array_column(registration_rows($pdo), 'email');

        expect($late->document['code'])
            ->toBe('token-refused')
            ->and($left)
            ->toBe([])
            ->and($kept)
            ->toBe(['NEW@example.org'])
            ->and($confirm($first)->document['code'])
            ->toBe('token-refused')
            ->and($confirm($second)->status)
            ->toBe(200)
            ->and(Avatar::find(avatar_grid(), 'Old Person'))
            ->toBeNull();
    });

    test('makes the avatar in the outfit of the model it chose', function () {
        $pdo = model_database();
        avatars_in($pdo);
        [$router] = access_api();
        $asked = $router->post(
            '/grids/demo/avatars',
            access_body('avatars', [
                'name' => 'New Person',
                'email' => 'new@example.org',
                'password' => 'secret',
                'model' => 'Default Ruth',
                'confirmEmail' => true,
                'send' => false,
            ]),
        );
        $outfits = static fn(): int => (int) $pdo->get_var(
            "SELECT COUNT(*) FROM inventoryfolders WHERE folderName = 'Default Ruth outfit'",
        );
        $before = $outfits();

        $made = $router->post(
            '/grids/demo/email-confirmations/complete',
            access_body('email-confirmations', ['token' => $asked->document['meta']['confirmation']['token'] ?? '']),
        );

        expect($asked->document['data']['attributes']['model'])
            ->toBe('Default Ruth')
            ->and($before)
            ->toBe(0)
            ->and($made->status)
            ->toBe(200)
            ->and((int) $pdo->get_var(
                "SELECT COUNT(*) FROM inventoryfolders WHERE agentID = :id AND folderName = 'Default Ruth outfit'",
                ['id' => $made->document['data']['id']],
            ))
            ->toBe(1);
    });

    test('needs an email to confirm, and refuses the avatar before it is made', function () {
        avatars_in(signin_database());
        [$router] = access_api();

        $made = $router->post(
            '/grids/demo/avatars',
            access_body('avatars', ['name' => 'New Person', 'password' => 'secret', 'confirmEmail' => true]),
        );

        expect($made->status)->toBe(400)->and(Avatar::find(avatar_grid(), 'New Person'))->toBeNull();
    });

    test('sends a new link to a request that waits, by its name, never to an avatar an administrator disabled', function () {
        avatars_in(signin_database());
        [$router, $mailer] = access_api();
        $router->post(
            '/grids/demo/avatars',
            access_body('avatars', [
                'name' => 'New Person',
                'email' => 'new@example.org',
                'password' => 'secret',
                'confirmEmail' => true,
            ]),
        );
        $first = $mailer->token();

        $again = $router->post(
            '/grids/demo/email-confirmations',
            access_body('email-confirmations', ['avatar' => 'New Person']),
        );
        $disabled = $router->post(
            '/grids/demo/email-confirmations',
            access_body('email-confirmations', ['avatar' => CY]),
        );
        $unknown = $router->post(
            '/grids/demo/email-confirmations',
            access_body('email-confirmations', ['avatar' => 'Nobody Here']),
        );
        $old = $router->post(
            '/grids/demo/email-confirmations/complete',
            access_body('email-confirmations', ['token' => $first]),
        );

        expect($again->status)
            ->toBe(202)
            ->and($mailer->sent)
            ->toHaveCount(2)
            ->and($mailer->token())
            ->not->toBe($first)
            ->and($disabled->status)
            ->toBe(409)
            ->and($unknown->status)
            ->toBe(404)
            ->and($old->status)
            ->toBe(403)
            ->and($router->post(
                '/grids/demo/email-confirmations/complete',
                access_body('email-confirmations', ['token' => $mailer->token()]),
            )->document['data']['attributes']['status'] ?? null)
            ->toBe('active');
    });

    test('says when the mail cannot be sent, and the token can then be given to whoever delivers it', function () {
        avatars_in(signin_database());
        $mailer = new MailerFake();
        $mailer->broken = true;
        [$router] = access_api(mailer: $mailer);

        $made = $router->post(
            '/grids/demo/avatars',
            access_body('avatars', [
                'name' => 'New Person',
                'email' => 'new@example.org',
                'password' => 'secret',
                'confirmEmail' => true,
            ]),
        );
        $resent = $router->post(
            '/grids/demo/email-confirmations',
            access_body('email-confirmations', ['avatar' => 'New Person']),
        );
        $given = $router->post(
            '/grids/demo/email-confirmations',
            access_body('email-confirmations', ['avatar' => 'New Person', 'send' => false]),
        );
        $confirmed = $router->post(
            '/grids/demo/email-confirmations/complete',
            access_body('email-confirmations', ['token' => $given->document['data'][0]['attributes']['token']]),
        );

        expect($made->status)
            ->toBe(202)
            ->and($made->document['meta'])
            ->toMatchArray(['emailConfirmation' => 'not sent'])
            ->and($made->document['meta']['notice']['code'])
            ->toBe('registered-mail-failed')
            ->and($resent->status)
            ->toBe(503)
            ->and($given->status)
            ->toBe(200)
            ->and($confirmed->document['data']['attributes']['active'])
            ->toBeTrue();
    });
});

describe('The API, for a new avatar whose email is confirmed by whoever delivers the token', function () {
    test('gives the token instead of mailing it when it is asked (send=false)', function () {
        avatars_in(signin_database());
        [$router, $mailer] = access_api();

        $made = $router->post(
            '/grids/demo/avatars',
            access_body('avatars', [
                'name' => 'New Person',
                'email' => 'new@example.org',
                'password' => 'secret',
                'confirmEmail' => true,
                'send' => false,
                'link' => 'https://site.example.org/confirm/{token}',
            ]),
        );
        $given = $made->document['meta']['confirmation'] ?? [];
        $confirmed = $router->post(
            '/grids/demo/email-confirmations/complete',
            access_body('email-confirmations', ['token' => $given['token'] ?? '']),
        );

        expect($made->status)
            ->toBe(202)
            ->and($made->document['meta']['emailConfirmation'])
            ->toBe('given')
            ->and($given)
            ->toMatchArray(['expires' => 1_800_172_800, 'email' => 'new@example.org'])
            ->and($given['link'])
            ->toBe('https://site.example.org/confirm/' . $given['token'])
            ->and($mailer->sent)
            ->toBe([])
            ->and($confirmed->status)
            ->toBe(200);
    });
});

describe('The states of an avatar: active, disabled by a manager, waiting for its email', function () {
    /**
     * An avatar that waits for the confirmation of its email, as the engine made them before the registrations (Pia, Quinn): its id,
     * its token, and the way to sign it in and to change it.
     */
    function pending_avatar(Router $router, ClockFake $clock): array
    {
        $avatar = Avatar::create(avatar_grid(), [
            'name' => 'Lisa Simpson',
            'email' => 'lisa@example.org',
            'password' => 'secret',
            'status' => 'pending',
        ]);
        $access = new AvatarAccess(avatar_grid(), 'Demo', 'Demo <no-reply@example.org>', new MailerFake(), $clock);
        $id = $avatar->id();

        return [
            $id,
            $access->requestEmailConfirmation($avatar, null, false)['token'],
            static fn(): string => $router->post(
                '/grids/demo/sign-ins',
                access_body('sign-ins', ['name' => 'Lisa Simpson', 'password' => 'secret']),
            )->document['code'] ?? 'granted',
            static fn(array $attributes) => $router->patch(
                "/grids/demo/avatars/$id",
                ['data' => ['type' => 'avatars', 'id' => $id, 'attributes' => $attributes]],
            ),
        ];
    }

    test('an avatar made waiting before still waits, and its link or a new one activates it', function () {
        avatars_in(signin_database());
        [$router, , $clock] = access_api();
        [$id, $token, $signIn] = pending_avatar($router, $clock);

        $waiting = $signIn();
        $new = $router->post(
            '/grids/demo/email-confirmations',
            access_body('email-confirmations', ['avatar' => 'Lisa Simpson', 'send' => false]),
        );
        $old = $router->post(
            '/grids/demo/email-confirmations/complete',
            access_body('email-confirmations', ['token' => $token]),
        );
        $confirmed = $router->post(
            '/grids/demo/email-confirmations/complete',
            access_body('email-confirmations', ['token' => $new->document['data'][0]['attributes']['token'] ?? '']),
        );

        expect($waiting)
            ->toBe('email-unconfirmed')
            ->and($new->document['data'][0]['attributes'])
            ->toMatchArray(['avatar' => $id, 'name' => 'Lisa Simpson', 'email' => 'lisa@example.org'])
            ->and($old->document['code'])
            ->toBe('token-refused')
            ->and($confirmed->document['data']['attributes']['status'])
            ->toBe('active')
            ->and($signIn())
            ->toBe('granted');
    });

    test('a manager disables an avatar that was waiting: it is disabled, not waiting', function () {
        avatars_in(signin_database());
        [$router, , $clock] = access_api();
        [$id, , $signIn, $change] = pending_avatar($router, $clock);

        $status = Avatar::find(avatar_grid(), $id)->status();
        $waiting = $signIn();
        $listed = $router->get('/grids/demo/avatars', ['filter' => ['status' => 'pending']]);
        $enabled = $change(['enabled' => true]);
        $granted = $signIn();
        $disabled = $change(['enabled' => false]);

        expect($status)
            ->toBe('pending')
            ->and($waiting)
            ->toBe('email-unconfirmed')
            ->and(array_column(array_column($listed->document['data'], 'attributes'), 'name'))
            ->toBe(['Lisa Simpson'])
            ->and($enabled->document['data']['attributes']['status'])
            ->toBe('active')
            ->and($granted)
            ->toBe('granted')
            ->and($disabled->document['data']['attributes']['status'])
            ->toBe('disabled')
            ->and($signIn())
            ->toBe('account-disabled');
    });

    test('the link of a waiting avatar never enables it once a manager disabled it', function () {
        avatars_in(signin_database());
        [$router, , $clock] = access_api();
        [, $token, $signIn, $change] = pending_avatar($router, $clock);

        $change(['enabled' => false]);
        $confirmed = $router->post(
            '/grids/demo/email-confirmations/complete',
            access_body('email-confirmations', ['token' => $token]),
        );
        $resent = $router->post(
            '/grids/demo/email-confirmations',
            access_body('email-confirmations', ['avatar' => 'Lisa Simpson', 'send' => false]),
        );

        expect($confirmed->document['code'])
            ->toBe('token-refused')
            ->and($resent->document['code'])
            ->toBe('no-confirmation-pending')
            ->and($signIn())
            ->toBe('account-disabled');
    });

    test('a manager enables a waiting avatar: its link is forgotten, it does not wait any more', function () {
        avatars_in(signin_database());
        [$router, , $clock] = access_api();
        [, $token, $signIn, $change] = pending_avatar($router, $clock);

        $change(['enabled' => true]);
        $confirmed = $router->post(
            '/grids/demo/email-confirmations/complete',
            access_body('email-confirmations', ['token' => $token]),
        );

        expect($confirmed->document['code'])->toBe('token-refused')->and($signIn())->toBe('granted');
    });

    test('Robust refuses the avatar in-world while it is not active, and gives its level back once it is', function () {
        $pdo = signin_database();
        avatars_in($pdo);
        [$router, , $clock] = access_api();
        [$id, , , $change] = pending_avatar($router, $clock);
        $row = static fn(): array => $pdo->get_row(
            'SELECT UserLevel, UserFlags, active FROM UserAccounts WHERE PrincipalID = :id',
            ['id' => $id],
            PDO::FETCH_ASSOC,
        );

        $pending = $row();
        $change(['enabled' => true, 'level' => 200]);
        $god = $row();
        $disabled = $change(['enabled' => false]);
        $off = $row();
        $back = $change(['enabled' => true]);

        // MinLoginLevel is 0 by default: a negative level is refused by the login of Robust
        expect((int) $pending['UserLevel'])
            ->toBeLessThan(0)
            ->and((int) $pending['UserFlags'] & Avatar::PENDING_FLAG)
            ->toBe(Avatar::PENDING_FLAG)
            ->and((int) $pending['active'])
            ->toBe(0)
            ->and((int) $god['UserLevel'])
            ->toBe(200)
            ->and((int) $god['UserFlags'] & Avatar::PENDING_FLAG)
            ->toBe(0)
            ->and((int) $off['UserLevel'])
            ->toBeLessThan(0)
            ->and($disabled->document['data']['attributes'])
            ->toMatchArray(['status' => 'disabled', 'level' => 200, 'active' => false])
            ->and($back->document['data']['attributes'])
            ->toMatchArray(['status' => 'active', 'level' => 200, 'active' => true, 'isGod' => true]);
    });
});

describe('The API, for the picks and the classifieds of an avatar', function () {
    test('lists what its profile shows: the picks it shows, the classifieds that have not expired', function () {
        $pdo = signin_database();
        $pdo->exec('ALTER TABLE userpicks ADD COLUMN snapshotuuid CHAR(36)');
        $pdo->exec('ALTER TABLE classifieds ADD COLUMN snapshotuuid CHAR(36)');
        $pdo->exec("UPDATE userpicks SET snapshotuuid = 'aaaaaaaa-0000-4000-8000-000000000001' WHERE pickuuid = 'k3'");
        $pdo->exec("UPDATE classifieds SET snapshotuuid = '00000000-0000-0000-0000-000000000000'");
        avatars_in($pdo);
        [$router] = access_api();

        $picks = $router->get('/grids/demo/avatars/' . ADA . '/picks');
        $classifieds = $router->get('/grids/demo/avatars/Ada%20Love/classifieds');
        $nobody = $router->get('/grids/demo/avatars/Nobody%20Here/picks');

        expect($picks->status)
            ->toBe(200)
            ->and(array_column($picks->document['data'], 'id'))
            ->toBe(['k3', 'k1'])
            ->and($picks->document['data'][1])
            ->toMatchArray(['type' => 'picks'])
            ->and($picks->document['data'][1]['attributes'])
            ->toBe([
                'name' => 'The Garden',
                'description' => 'roses',
                'region' => 'Welcome',
                'parcel' => null,
                'position' => [10, 20, 30],
                'teleport' => 'hop://play.example.org:8002/Welcome/10/20/30',
                'gatekeeper' => null,
                // No snapshot: the image of its name
                'picture' => W4OS\Engine\Assets\TitleImage::id('The Garden'),
            ])
            ->and(array_column($classifieds->document['data'], 'id'))
            ->toBe(['c1'])
            ->and($picks->document['data'][0]['attributes'])
            ->toMatchArray(['gatekeeper' => 'other.example.org:8002', 'picture' => 'aaaaaaaa-0000-4000-8000-000000000001'])
            ->and($classifieds->document['data'][0]['attributes'])
            ->toBe([
                'name' => 'Roses for sale',
                'description' => 'fresh',
                'region' => 'Welcome',
                'parcel' => 'The Garden',
                'position' => null,
                'teleport' => 'hop://play.example.org:8002/Welcome',
                'picture' => W4OS\Engine\Assets\TitleImage::id('Roses for sale'),
                'created' => 1700000000,
                'expires' => 1900000000,
            ])
            ->and($nobody->status)
            ->toBe(404)
            ->and(W4OS\Engine\Api\Router::PUBLIC)
            ->not->toContain('GET /grids/{grid}/avatars/{id}/picks')
            ->and(array_keys(W4OS\Engine\Api\Contract::document()['paths']))
            ->toContain('/grids/{grid}/avatars/{id}/picks', '/grids/{grid}/avatars/{id}/classifieds');
    });
});

describe('The API, for the groups of an avatar', function () {
    test('lists the groups its profile shows, with the title it wears', function () {
        $pdo = signin_database();
        groups_in($pdo);
        avatars_in($pdo);
        [$router] = access_api();

        $groups = $router->get('/grids/demo/avatars/Ada%20Love/groups');

        expect($groups->status)
            ->toBe(200)
            ->and(array_column($groups->document['data'], 'id'))
            ->toBe(['g2', 'g5', 'g1', 'g6'])
            ->and($groups->document['data'][2])
            ->toMatchArray(['type' => 'groups', 'id' => 'g1'])
            ->and($groups->document['data'][2]['attributes'])
            ->toMatchArray(['name' => 'Builders', 'title' => 'Chief Builder', 'picture' => 'aaaaaaaa-0000-4000-8000-000000000002', 'local' => true])
            // A group of another grid: its name without its address; the name of its grid unknown when it does not answer
            ->and($groups->document['data'][1]['attributes'])
            ->toMatchArray(['name' => 'Body Talk', 'grid' => null, 'local' => false])
            ->and($router->get('/grids/demo/avatars/Nobody%20Here/groups')->status)
            ->toBe(404)
            ->and(array_keys(W4OS\Engine\Api\Contract::document()['paths']))
            ->toContain('/grids/{grid}/avatars/{id}/groups');
    });
});

describe('The API, for the publication of a profile', function () {
    test('sets or clears "show in search", the first bit of the account, and no other', function () {
        $pdo = signin_database();
        avatars_in($pdo);
        [$router] = access_api();
        $change = static fn(string $id, array $attributes) => $router->patch(
            "/grids/demo/avatars/$id",
            ['data' => ['type' => 'avatars', 'id' => $id, 'attributes' => $attributes]],
        )->document['data']['attributes'] ?? [];
        $flags = static fn(string $id): int => (int) $pdo->get_var('SELECT UserFlags FROM UserAccounts WHERE PrincipalID = :id', ['id' => $id]);
        $pdo->exec("UPDATE UserAccounts SET UserFlags = 6 WHERE PrincipalID = '" . ADA . "'");

        $published = $change(ADA, ['published' => true]);
        $on = $flags(ADA);
        $hidden = $change(ADA, ['published' => false, 'about' => 'Changed']);

        expect([$published['published'], $on])
            ->toBe([true, 7])
            ->and([$hidden['published'], $hidden['about'], $flags(ADA)])
            ->toBe([false, 'Changed', 6])
            ->and($change(CY, ['published' => 'yes'])['published'] ?? null)
            ->toBeTrue()
            ->and($change(CY, ['published' => true])['status'] ?? null)
            ->toBe('disabled');
    });
});

describe('The API, for the role of an avatar', function () {
    test('a manager gives a role: its own level, kept by a disabled avatar until it is enabled', function () {
        $pdo = signin_database();
        avatars_in($pdo);
        [$router] = access_api();
        $change = static fn(string $id, array $attributes) => $router->patch(
            "/grids/demo/avatars/$id",
            ['data' => ['type' => 'avatars', 'id' => $id, 'attributes' => $attributes]],
        );
        $stored = static fn(string $id): int => (int) $pdo->get_var(
            'SELECT UserLevel FROM UserAccounts WHERE PrincipalID = :id',
            ['id' => $id],
        );

        $godLike = $change(ADA, ['role' => 'god-like'])->document['data']['attributes'];
        $god = $change(ADA, ['role' => 'god', 'level' => 220])->document['data']['attributes'];
        $liaison = $change(CY, ['role' => 'liaison'])->document['data']['attributes'];
        $kept = $stored(CY);
        $enabled = $change(CY, ['enabled' => true])->document['data']['attributes'];

        expect([$godLike['role'], $godLike['level'], $godLike['standardLevel']])
            ->toBe(['god-like', 1, true])
            ->and([$god['role'], $god['level'], $god['standardLevel']])
            ->toBe(['god', 220, false])
            ->and([$liaison['role'], $liaison['status']])
            ->toBe(['liaison', 'disabled'])
            ->and($kept)
            ->toBe(-151)
            ->and([$enabled['role'], $enabled['level'], $enabled['status']])
            ->toBe(['liaison', 150, 'active']);
    });

    test('refuses a role that is not one, and a level that is not of the role, changing nothing', function () {
        avatars_in(signin_database());
        [$router] = access_api();
        $change = static fn(array $attributes) => $router->patch(
            '/grids/demo/avatars/' . ADA,
            ['data' => ['type' => 'avatars', 'id' => ADA, 'attributes' => $attributes + ['about' => 'Changed']]],
        );

        $unknown = $change(['role' => 'admin']);
        $conflict = $change(['role' => 'god', 'level' => 100]);

        expect([$unknown->status, $unknown->document['code']])
            ->toBe([400, 'role-unknown'])
            ->and([$conflict->status, $conflict->document['code']])
            ->toBe([400, 'role-level-conflict'])
            ->and(Avatar::find(avatar_grid(), ADA)->detail())
            ->toMatchArray(['role' => 'resident', 'level' => 0, 'about' => 'Hello'])
            ->and(
                W4OS\Engine\Api\Contract::document()['paths']['/grids/{grid}/avatars/{id}']['patch']['requestBody']['content'][
                    'application/vnd.api+json'
                ]['schema']['properties']['data']['properties']['attributes']['properties'],
            )
            ->toHaveKey('role');
    });
});

describe('The API, for a manager who changes the password of an avatar', function () {
    test('changes it with the rules of a registration, and the avatar signs in with it', function () {
        avatars_in(signin_database());
        [$router] = access_api();
        $change = static fn(string $password) => $router->patch(
            '/grids/demo/avatars/' . ADA,
            ['data' => ['type' => 'avatars', 'id' => ADA, 'attributes' => ['password' => $password]]],
        );
        $signIn = static fn(string $password): bool => (new DatabaseAuthenticator(avatar_grid()))
            ->authenticate('Ada Love', $password)
            ->isGranted();

        $short = $change('no');
        $quote = $change('with"quote');
        $long = $change(str_repeat('x', 17));
        $kept = $signIn('ada-secret');
        $changed = $change('new-secret');

        expect(array_map(static fn($answer): ?string => $answer->document['code'] ?? null, [$short, $quote, $long]))
            ->toBe(['password-invalid', 'password-invalid', 'password-invalid'])
            ->and($kept)
            ->toBeTrue()
            ->and($changed->status)
            ->toBe(200)
            ->and($signIn('new-secret'))
            ->toBeTrue()
            ->and($signIn('ada-secret'))
            ->toBeFalse()
            ->and($router->get('/grids/demo/avatars')->document['meta']['profileFlags']['wants'][0] ?? null)
            ->toBe(['name' => 'build', 'value' => 1, 'key' => 'want_build']);
    });
});

describe('The API, for a change of the email of an avatar', function () {
    test('keeps the email until the new one is confirmed with the link mailed to it', function () {
        avatars_in(signin_database());
        [$router, $mailer] = access_api();

        $asked = $router->patch(
            '/grids/demo/avatars/' . ADA,
            access_body('avatars', [
                'email' => 'ada@new.example.org',
                'about' => 'Changed',
                'confirmEmail' => true,
                'link' => 'https://site.example.org/confirm',
            ]),
        );
        $token = $mailer->token();
        $confirmed = $router->post(
            '/grids/demo/email-confirmations/complete',
            access_body('email-confirmations', ['token' => $token]),
        );

        expect($asked->status)
            ->toBe(200)
            ->and($asked->document['data']['attributes']['email'])
            ->toBe('ada@example.org')
            ->and($asked->document['data']['attributes']['about'])
            ->toBe('Changed')
            ->and($asked->document['meta'])
            ->toMatchArray(['emailConfirmation' => 'sent', 'settings' => ['allowUserProfileWebUrls' => true]])
            ->and($mailer->sent[0]['to'])
            ->toBe('ada@new.example.org')
            ->and($mailer->sent[0]['html'])
            ->toContain('href="https://site.example.org/confirm?token=' . $token . '"')
            ->and($confirmed->status)
            ->toBe(200)
            ->and($confirmed->document['data']['attributes']['email'])
            ->toBe('ada@new.example.org')
            ->and((new DatabaseAuthenticator(avatar_grid()))->authenticate('Ada Love', 'ada-secret')->isGranted())
            ->toBeTrue();
    });

    test('never activates an avatar a manager disabled, and gives the token when it is asked', function () {
        avatars_in(signin_database());
        [$router, $mailer] = access_api();

        $asked = $router->patch(
            '/grids/demo/avatars/' . CY,
            access_body('avatars', ['email' => 'cy@new.example.org', 'confirmEmail' => true, 'send' => false]),
        );
        $confirmed = $router->post(
            '/grids/demo/email-confirmations/complete',
            access_body('email-confirmations', ['token' => $asked->document['meta']['confirmation']['token'] ?? '']),
        );
        $invalid = $router->patch(
            '/grids/demo/avatars/' . CY,
            access_body('avatars', ['email' => 'not an email', 'confirmEmail' => true]),
        );

        expect($asked->document['meta']['emailConfirmation'])
            ->toBe('given')
            ->and($mailer->sent)
            ->toBe([])
            ->and($confirmed->document['data']['attributes']['email'])
            ->toBe('cy@new.example.org')
            ->and($confirmed->document['data']['attributes']['active'])
            ->toBeFalse()
            ->and($invalid->status)
            ->toBe(400)
            ->and($invalid->document['code'])
            ->toBe('email-invalid');
    });
});

describe('The problems of the access of the avatars', function () {
    test('each say a stable code, beside the English detail', function () {
        avatars_in(signin_database());
        $mailer = new MailerFake();
        [$router] = access_api(mailer: $mailer);
        $post = static fn(string $path, string $type, array $attributes) => $router->post(
            "/grids/demo/$path",
            access_body($type, $attributes),
        );
        $code = static fn($answer): ?string => $answer->document['code'] ?? null;

        $new = ['name' => 'New Person', 'email' => 'new@example.org', 'password' => 'secret', 'confirmEmail' => true];
        $codes = [
            'name-reserved' => $post('avatars', 'avatars', ['name' => 'Admin Person', 'password' => 'secret']),
            'name-taken' => $post('avatars', 'avatars', ['name' => 'Ada Love', 'password' => 'secret']),
            'email-required' => $post('avatars', 'avatars', ['email' => ''] + $new),
            'link-invalid' => $post('password-resets', 'password-resets', [
                'name' => 'Ada Love',
                'link' => 'javascript:x',
            ]),
            'token-refused' => $post('password-resets/complete', 'password-resets', [
                'token' => 'nope',
                'password' => 'brand-new',
            ]),
            'password-invalid' => $post('password-resets/complete', 'password-resets', [
                'token' => 'nope',
                'password' => 'no',
            ]),
            'no-confirmation-pending' => $post('email-confirmations', 'email-confirmations', ['avatar' => CY]),
            'field-unknown' => $router->patch(
                '/grids/demo/avatars/' . ADA,
                access_body('avatars', ['firstName' => 'Eve']),
            ),
        ];
        $mailer->broken = true;
        $post('avatars', 'avatars', $new);
        $codes['mail-failed'] = $post('email-confirmations', 'email-confirmations', ['avatar' => 'New Person']);

        expect(array_map($code, $codes))->toBe(array_combine(array_keys($codes), array_keys($codes)));
    });
});

describe('The contract, for the access of the avatars', function () {
    test('has the four operations, closed, and the confirmation of a new avatar', function () {
        $paths = W4OS\Engine\Api\Contract::document()['paths'];
        $operations = [];
        foreach (
            [
                '/grids/{grid}/password-resets',
                '/grids/{grid}/password-resets/complete',
                '/grids/{grid}/email-confirmations',
                '/grids/{grid}/email-confirmations/complete',
            ]
            as $path
        ) {
            $operations[$path] = $paths[$path]['post']['operationId'] ?? null;
            expect($paths[$path]['post'] ?? [])->not->toHaveKey('security');
        }

        expect($operations)
            ->toBe([
                '/grids/{grid}/password-resets' => 'requestPasswordReset',
                '/grids/{grid}/password-resets/complete' => 'resetPassword',
                '/grids/{grid}/email-confirmations' => 'requestEmailConfirmation',
                '/grids/{grid}/email-confirmations/complete' => 'confirmEmail',
            ])
            ->and(
                $paths['/grids/{grid}/avatars']['post']['requestBody']['content']['application/vnd.api+json']['schema'][
                    'properties'
                ]['data']['properties']['attributes']['properties'],
            )
            ->toHaveKeys(['confirmEmail', 'link', 'send', 'reservedName'])
            ->and($paths['/grids/{grid}/avatars']['post']['responses'])
            ->toHaveKeys(['201', '202']);
    });
});
