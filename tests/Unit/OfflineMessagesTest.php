<?php

declare(strict_types=1);

/**
 * The offline messages (the module V1 of the simulators): the messages posted in the XML of the module are kept, given back to the
 * avatar who comes back, and mailed to the one who asked for it.
 */

use Nyholm\Psr7\Factory\Psr17Factory;
use W4OS\Engine\Data\Connections;
use W4OS\Engine\Data\Database;
use W4OS\Engine\Services\MailerInterface;
use W4OS\Engine\Services\OfflineMessages;
use W4OS\Engine\Testing\Machine;

const OFFLINE_TO = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
const OFFLINE_FROM = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';

/** The XML the module posts for a message. */
function offline_message(string $text = "Hello\nthere", string $dialog = '0', string $from = 'Ada Love'): string
{
    return '<?xml version="1.0" encoding="utf-8"?>' .
        "\n" .
        '<GridInstantMessage xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">' .
        "\n" .
        '<fromAgentID>' .
        OFFLINE_FROM .
        '</fromAgentID><fromAgentName>' .
        $from .
        '</fromAgentName><toAgentID>' .
        OFFLINE_TO .
        '</toAgentID>' .
        "<dialog>$dialog</dialog><fromGroup>false</fromGroup><message>" .
        htmlspecialchars($text) .
        '</message></GridInstantMessage>';
}

/** The databases of the offline messages and of the profiles, with the preferences of an avatar. */
function offline_databases(string $email = 'ada@example.org', string $wanted = 'true'): array
{
    $messages = test_pdo();
    $messages->exec(
        'CREATE TABLE im_offline (ID INTEGER PRIMARY KEY, PrincipalID CHAR(36), FromID CHAR(36), Message TEXT, TMStamp INT)',
    );
    $profiles = test_pdo();
    $profiles->exec('CREATE TABLE usersettings (useruuid CHAR(36), imviaemail VARCHAR(5), email VARCHAR(254))');
    $profiles->exec("INSERT INTO usersettings VALUES ('" . OFFLINE_TO . "', '$wanted', '$email')");

    return [$messages, $profiles];
}

/** A mailer that keeps what it is asked to send. */
function offline_mailer(array &$sent): MailerInterface
{
    return new class ($sent) implements MailerInterface {
        public function __construct(private array &$sent) {}

        public function send(string $to, string $subject, string $html, string $from, string $text = ''): bool
        {
            $this->sent[] = compact('to', 'subject', 'html', 'from', 'text');

            return true;
        }
    };
}

describe('The offline messages', function () {
    test('keep a message for its recipient, answer true, and mail it once the answer is sent', function () {
        [$messages, $profiles] = offline_databases();
        $sent = [];
        $service = new OfflineMessages(
            $messages,
            $profiles,
            'Demo World',
            'no-reply@example.org',
            offline_mailer($sent),
        );

        $answer = $service->call('SaveMessage', offline_message());
        $mailedBefore = count($sent);
        $service->finish();

        expect($answer->status)
            ->toBe(200)
            ->and($answer->body())
            ->toContain('<boolean>true</boolean>')
            ->and($messages->get_row('SELECT PrincipalID, FromID FROM im_offline', [], PDO::FETCH_ASSOC))
            ->toBe(['PrincipalID' => OFFLINE_TO, 'FromID' => OFFLINE_FROM])
            ->and($messages->get_var('SELECT Message FROM im_offline'))
            ->toStartWith('<?xml version="1.0" encoding="utf-8"?><GridInstantMessage')
            ->and($mailedBefore)
            ->toBe(0)
            ->and($sent)
            ->toHaveCount(1)
            ->and($sent[0])
            ->toMatchArray([
                'to' => 'ada@example.org',
                'subject' => '[Demo World] Message from Ada Love',
                'from' => 'Ada Love via Demo World <no-reply@example.org>',
            ])
            ->and($sent[0]['html'])
            ->toContain("Hello<br>\nthere")
            ->toContain('<b>Demo World</b>')
            ->and($sent[0]['text'])
            ->toContain("Hello\nthere")
            ->not->toContain('<');
    });

    test('do not mail an avatar who did not ask, or has no address', function () {
        foreach ([offline_databases('ada@example.org', 'false'), offline_databases('')] as [$messages, $profiles]) {
            $sent = [];
            $service = new OfflineMessages(
                $messages,
                $profiles,
                'Demo World',
                'no-reply@example.org',
                offline_mailer($sent),
            );

            $service->call('SaveMessage', offline_message());
            $service->finish();

            expect($sent)->toBe([])->and($messages->get_var('SELECT COUNT(*) FROM im_offline'))->toBe(1);
        }
    });

    test(
        'write the mail for what the message is: a notice, an invitation, an offer, a friendship, a message from the grid',
        function () {
            [$messages, $profiles] = offline_databases();
            $sent = [];
            $service = new OfflineMessages(
                $messages,
                $profiles,
                'Demo World',
                'no-reply@example.org',
                offline_mailer($sent),
            );
            $compose = static fn(string $text, string $dialog, string $from = 'Ada Love'): array => $service->compose(
                simplexml_load_string(offline_message($text, $dialog, $from)),
            );

            $notice = $compose('Meeting|At noon', '32');
            $invitation = $compose('Join us', '3');
            $offer = $compose('a hat', '4');
            $friendship = $compose('Be friends?', '38');
            $server = $compose('Welcome', '0', 'Server');

            expect($notice['subject'])
                ->toBe('[Demo World] Group notice: Meeting')
                ->and($notice['html'])
                ->toContain('<p>Ada Love sent a group notice in Demo World:</p>')
                ->toContain('<h4>Meeting</h4>At noon')
                ->and($invitation['subject'])
                ->toBe('[Demo World] Group invitation from Ada Love')
                ->and($invitation['html'])
                ->toContain('<p>Join us</p>Log in-world to accept or decline')
                ->and($offer['subject'])
                ->toBe('[Demo World] Inventory offer from Ada Love')
                ->and($offer['html'])
                ->toContain('Ada Love returned you a hat')
                ->and($friendship['subject'])
                ->toBe('[Demo World] Friendship offer from Ada Love')
                ->and($server['from'])
                ->toBe('Demo World')
                ->and($server['subject'])
                ->toBe('[Demo World] Message from Demo World');
        },
    );

    test('answer what the module reads, a boolean: true when the message is kept, false when not (the sender is told which)', function () {
        [$messages, $profiles] = offline_databases();
        $kept = (new OfflineMessages($messages, $profiles, 'Demo World'))->call('SaveMessage', offline_message());
        // A database without the table: nothing is kept
        $lost = (new OfflineMessages(test_pdo(), $profiles, 'Demo World'))->call('SaveMessage', offline_message());

        // OfflineMessageModule reads it with XmlSerializer as a bool: "User is not logged in. Message saved." or "Message not saved"
        expect(simplexml_load_string($kept->body())->getName())->toBe('boolean')
            ->and(trim((string) simplexml_load_string($kept->body())))->toBe('true')
            ->and(simplexml_load_string($lost->body())->getName())->toBe('boolean')
            ->and(trim((string) simplexml_load_string($lost->body())))->toBe('false');
    });

    test('mail nobody whose preferences the profiles do not have', function () {
        [$messages, $profiles] = offline_databases();
        $profiles->exec('DELETE FROM usersettings');
        $sent = [];
        $service = new OfflineMessages($messages, $profiles, 'Demo World', 'no-reply@example.org', offline_mailer($sent));

        $service->call('SaveMessage', offline_message());
        $service->finish();

        expect($sent)->toBe([])->and($messages->get_var('SELECT COUNT(*) FROM im_offline'))->toBe(1);
    });

    test('write a subject as text, never as HTML: a name with an apostrophe or an ampersand stays as it is', function () {
        [$messages, $profiles] = offline_databases();
        $service = new OfflineMessages($messages, $profiles, 'Kimbé & Co');
        $mail = $service->compose(simplexml_load_string(offline_message('Hi', '0', "Ada O'Hara")));
        $notice = $service->compose(simplexml_load_string(offline_message('Rock & roll|Tonight', '32')));

        expect($mail['subject'])->toBe("[Kimbé & Co] Message from Ada O'Hara")
            ->and($notice['subject'])->toBe('[Kimbé & Co] Group notice: Rock & roll')
            // In the HTML part, the same values are HTML; in the text part, as they are
            ->and($mail['html'])->toContain('Kimbé &amp; Co')->toContain('Ada O&#039;Hara')
            ->and($mail['text'])->toContain("Sent by Ada O'Hara  in Kimbé & Co");
    });

    test('are written from the template of the kit, the one of the config folder of the install in its place', function () {
        [$messages, $profiles] = offline_databases();
        $etc = test_tmp() . '/etc-' . bin2hex(random_bytes(4));
        $roots = ["$etc/mail", ...W4OS\Engine\Provisioning\ResourceRegistry::directories('share/mail')];
        $compose = static fn(): array => (new OfflineMessages($messages, $profiles, 'Demo World', templates: $roots))->compose(simplexml_load_string(offline_message('1 < 2 & 3')));
        $kit = $compose();
        // The installation's own, in its config folder: the same name, read in place of the kit's
        mkdir("$etc/mail/en", 0o755, true);
        file_put_contents("$etc/mail/en/offline-message.txt", "# ours\nSubject: {{grid}}: {{sender}} wrote\nFrom: {{sender}} at {{grid}}\n\nOurs: {{message}}\n");
        file_put_contents("$etc/mail/en/offline-message.html", "<!-- ours -->\n<p>Ours: {{message}}</p>\n");
        $ours = $compose();

        expect($kit['subject'])->toBe('[Demo World] Message from Ada Love')
            ->and($kit['text'])->toContain('1 < 2 & 3')
            ->and($kit['html'])->toContain('1 &lt; 2 &amp; 3')
            ->and($ours)->toMatchArray(['subject' => 'Demo World: Ada Love wrote', 'from' => 'Ada Love at Demo World', 'text' => "Ours: 1 < 2 & 3\n", 'html' => "<p>Ours: 1 &lt; 2 &amp; 3</p>\n"]);
    });

    test('are in the language of the recipient when a template has it, else in English', function () {
        [$messages, $profiles] = offline_databases();
        $etc = test_tmp() . '/etc-' . bin2hex(random_bytes(4));
        mkdir("$etc/mail/fr", 0o755, true);
        file_put_contents("$etc/mail/fr/offline-message.txt", "Subject: [{{grid}}] Message de {{sender}}\nFrom: {{sender}} via {{grid}}\n\n{{message}}\n");
        file_put_contents("$etc/mail/fr/offline-message.html", "<p>{{message}}</p>\n");
        // The language its viewer gave Robust (AgentPrefs)
        $prefs = test_pdo();
        $prefs->exec('CREATE TABLE AgentPrefs (PrincipalID CHAR(36), Language VARCHAR(5))');
        $said = static function (string $language) use ($messages, $profiles, $prefs, $etc): string {
            $prefs->exec('DELETE FROM AgentPrefs');
            $prefs->exec("INSERT INTO AgentPrefs VALUES ('" . OFFLINE_TO . "', '$language')");
            $sent = [];
            $service = new OfflineMessages($messages, $profiles, 'Demo World', 'no-reply@example.org', offline_mailer($sent),
                templates: ["$etc/mail", ...W4OS\Engine\Provisioning\ResourceRegistry::directories('share/mail')], preferences: $prefs);
            $service->call('SaveMessage', offline_message('Salut'));
            $service->finish();

            return $sent[0]['subject'] ?? '';
        };

        expect($said('fr-fr'))->toBe('[Demo World] Message de Ada Love')
            ->and($said('fr'))->toBe('[Demo World] Message de Ada Love')
            ->and($said('de'))->toBe('[Demo World] Message from Ada Love');
    });

    test('have a default template that says the names it replaces, for whoever edits it', function () {
        $kit = W4OS\Engine\Provisioning\ResourceRegistry::directories('share/mail')[0] ?? '';
        $files = glob("$kit/en/offline-*.txt") ?: [];

        expect($files)->not->toBe([]);
        foreach ($files as $file) {
            expect((string) file_get_contents($file))->toStartWith('#')->toContain('{{grid}}')->toContain('{{sender}}')
                ->and(is_file(substr($file, 0, -4) . '.html'))->toBeTrue();
        }
    });

    test('give back the messages kept for an avatar, as the module reads them, and forget them', function () {
        [$messages, $profiles] = offline_databases();
        $service = new OfflineMessages($messages, $profiles, 'Demo World');
        $service->call('SaveMessage', offline_message('first'));
        $service->call('SaveMessage', offline_message('second'));

        $answer = $service->call('RetrieveMessages', '<UUID><Guid>' . OFFLINE_TO . '</Guid></UUID>');
        $again = $service->call('RetrieveMessages', '<UUID><Guid>' . OFFLINE_TO . '</Guid></UUID>');

        expect($answer->body())
            ->toStartWith('<?xml version="1.0" encoding="utf-8"?><ArrayOfGridInstantMessage')
            ->toEndWith('</ArrayOfGridInstantMessage>')
            ->and(substr_count($answer->body(), '<GridInstantMessage'))
            ->toBe(2)
            ->and(substr_count($answer->body(), '<?xml'))
            ->toBe(1)
            ->and($messages->get_var('SELECT COUNT(*) FROM im_offline'))
            ->toBe(0)
            ->and(substr_count($again->body(), '<GridInstantMessage'))
            ->toBe(0)
            ->and($service->call('RetrieveMessages', '<UUID><Guid>nobody</Guid></UUID>')->body())
            ->toBe('');
    });

    test('refuse what is not a call of the module', function () {
        [$messages] = offline_databases();
        $service = new OfflineMessages($messages);

        expect($service->call('SaveMessage', '')->status)
            ->toBe(400)
            ->and($service->call('SaveMessage', 'not xml')->status)
            ->toBe(400)
            ->and($service->call('Other', '<a/>')->status)
            ->toBe(404)
            ->and($service->call('Other', '<a/>')->body())
            ->toContain('OfflineMessageModule = OfflineMessageModule');
    });

    test('are served by the handler at the address of the module, and refused without their database', function () {
        [$messages, $profiles] = offline_databases();
        Connections::using(
            static fn($what) => match ($what) {
                Database::Offline => $messages,
                Database::Profiles => $profiles,
                default => null,
            },
        );
        $sent = [];
        $factory = new Psr17Factory();
        $handler = new W4OS\Engine\Api\Handler(
            new W4OS\Engine\Api\Api((new Machine())->environment, mailer: offline_mailer($sent)),
            $factory,
            $factory,
            '/api',
            grid: 'demo',
            authenticator: new W4OS\Engine\Security\OpenAccess(),
        );
        $post = static fn(string $path, string $body) => $handler->handle(
            $factory->createServerRequest('POST', $path)->withBody($factory->createStream($body)),
        );

        $saved = $post('/api/offline-messages/SaveMessage/', offline_message());
        $handler->terminate();
        $retrieved = $post('/api/offline-messages/RetrieveMessages/', '<UUID><Guid>' . OFFLINE_TO . '</Guid></UUID>');
        Connections::using(static fn() => null);
        $without = $post('/api/offline-messages/SaveMessage/', offline_message());

        expect((string) $saved->getBody())
            ->toContain('<boolean>true</boolean>')
            ->and($sent)
            ->toHaveCount(1)
            ->and((string) $retrieved->getBody())
            ->toContain('<GridInstantMessage')
            ->and($without->getStatusCode())
            ->toBe(503)
            ->and(
                $handler
                    ->handle($factory->createServerRequest('GET', '/api/offline-messages/SaveMessage/'))
                    ->getHeaderLine('Allow'),
            )
            ->toBe('POST');
    });
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');
