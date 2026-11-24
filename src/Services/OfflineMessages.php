<?php

declare(strict_types=1);

namespace W4OS\Engine\Services;

use W4OS\Engine\Api\Response;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Text\Uuid;

/**
 * The instant messages sent to avatars who are not there, kept until they come back (the service the module V1 of the simulators
 * calls: `OfflineMessageModule = OfflineMessageModule` and `OfflineMessageURL`). The simulators post the messages in XML to
 * `<url>/SaveMessage/` and ask them back at `<url>/RetrieveMessages/`; the format is the one of the module.
 *
 * The messages are kept in the table of the offline messages of Robust (`im_offline`), the one the module V2 reads too. An avatar
 * who asked it in the preferences of their viewer (Send IM to email) also gets the message by email, once the answer is sent.
 */
final class OfflineMessages
{
    /** The table of Robust's service of offline messages. */
    public const TABLE = 'im_offline';

    /** @var list<\Closure():void> what is done once the answer is sent */
    private array $after = [];

    /**
     * @param OSPDO $messages the database of the offline messages
     * @param ?OSPDO $profiles the database of the profiles, who knows the mail preferences (`usersettings`)
     * @param string $sender the address the mails come from
     * @param list<string> $templates the folders of the templates of the mails (MailTemplate::roots()); none: the kit's
     * @param ?OSPDO $preferences the database of the preferences of the avatars (`AgentPrefs`), who knows their language
     */
    public function __construct(
        private readonly OSPDO $messages,
        private readonly ?OSPDO $profiles = null,
        private readonly string $gridName = '',
        private readonly string $sender = '',
        private readonly ?MailerInterface $mailer = null,
        private readonly string $table = self::TABLE,
        private readonly array $templates = [],
        private readonly ?OSPDO $preferences = null,
    ) {}

    /**
     * The answer to a call of the module.
     *
     * @param string $method SaveMessage or RetrieveMessages
     */
    public function call(string $method, string $body): Response
    {
        if (trim($body) === '') {
            return XmlRpc::failure(new Failure('Bad request: no request', 400));
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            return XmlRpc::failure(new Failure('Bad request: not XML', 400));
        }

        return match ($method) {
            'SaveMessage' => $this->save($xml),
            'RetrieveMessages' => $this->retrieve($xml),
            default => XmlRpc::failure(
                new Failure(
                    "method $method not implemented, please configure OfflineMessageModule = OfflineMessageModule in OpenSim.ini",
                    404,
                ),
            ),
        };
    }

    /** What waits for the answer to be sent: the mails. */
    public function finish(): void
    {
        while (($todo = array_shift($this->after)) !== null) {
            $todo();
        }
    }

    /** Keep a message for its recipient, and mail it when they asked. */
    private function save(\SimpleXMLElement $xml): Response
    {
        $saved = $this->messages->prepareAndExecute(
            "INSERT INTO {$this->table} (PrincipalID, FromID, Message) VALUES (:PrincipalID, :FromID, :Message)",
            [
                'PrincipalID' => (string) $xml->toAgentID,
                'FromID' => (string) $xml->fromAgentID,
                'Message' => preg_replace('/>\n/', '>', (string) $xml->asXML()),
            ],
        );
        $saved && ($this->after[] = fn() => $this->mail($xml));

        // The module reads a boolean, and tells the sender whether it was saved
        return Response::binary(XmlRpc::boolean((bool) $saved), XmlRpc::CONTENT_TYPE);
    }

    /** Give the messages kept for an avatar, and forget them. */
    private function retrieve(\SimpleXMLElement $xml): Response
    {
        $guid = (string) $xml->Guid;
        $head =
            '<?xml version="1.0" encoding="utf-8"?><ArrayOfGridInstantMessage xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">';
        if (!Uuid::isValid($guid)) {
            error_log("$guid is not a valid UUID");

            return Response::binary('', XmlRpc::CONTENT_TYPE);
        }
        $pending = $this->messages->prepareAndExecute(
            "SELECT ID, Message FROM {$this->table} WHERE PrincipalID = :PrincipalID",
            [
                'PrincipalID' => $guid,
            ],
        );
        if (!$pending) {
            error_log('error retrieving pending messages');

            return Response::binary(XmlRpc::plain(false), XmlRpc::CONTENT_TYPE);
        }
        $delivered = [];
        $list = '';
        foreach ($pending->fetchAll(\PDO::FETCH_NUM) as [$id, $message]) {
            // What was kept is the whole document: its declaration is not part of the list
            $end = strpos((string) $message, '?>');
            $list .= $end === false ? $message : substr((string) $message, $end + 2);
            $delivered[] = $id;
        }
        if ($delivered !== []) {
            $this->messages->prepareAndExecute(
                "DELETE FROM {$this->table} WHERE ID IN (" . implode(',', array_fill(0, count($delivered), '?')) . ')',
                $delivered,
            );
        }

        return Response::binary($head . $list . '</ArrayOfGridInstantMessage>', XmlRpc::CONTENT_TYPE);
    }

    /** Send the message by email to the avatar who asked it. */
    private function mail(\SimpleXMLElement $xml): void
    {
        $settings = $this->profiles?->prepareAndExecute(
            'SELECT imviaemail, email FROM usersettings WHERE useruuid = :useruuid',
            [
                'useruuid' => (string) $xml->toAgentID,
            ],
        );
        $row = $settings ? $settings->fetch(\PDO::FETCH_NUM) : false;
        [$wanted, $email] = $row === false ? [null, null] : [$row[0], $row[1]];
        if (empty($email) || $wanted == 'false' || $this->mailer === null) {
            return;
        }
        $mail = $this->compose($xml, $this->language((string) $xml->toAgentID));
        $from = $mail['from'] . ($this->sender === '' ? '' : " <{$this->sender}>");
        $this->mailer->send((string) $email, $mail['subject'], $mail['html'], $from, $mail['text']) ||
            error_log("error sending the IM notification to $email");
    }

    /** The language of an avatar, as its viewer gave it to Robust (AgentPrefs); English when it is not known. */
    private function language(string $avatar): string
    {
        if ($this->preferences === null || empty($this->preferences->connected) || !$this->preferences->table_exists('AgentPrefs')) {
            return MailTemplate::FALLBACK;
        }
        $found = $this->preferences->prepareAndExecute('SELECT Language FROM AgentPrefs WHERE PrincipalID = :id', ['id' => $avatar]);
        $language = $found ? $found->fetchColumn() : false;

        return is_string($language) && trim($language) !== '' ? trim($language) : MailTemplate::FALLBACK;
    }

    /**
     * The mail of a message, from the template of its kind (MailTemplate, `offline-<kind>`) in a language: the name it comes from, its
     * subject, its text and its HTML.
     *
     * @return array{from:string,subject:string,text:string,html:string}
     */
    public function compose(\SimpleXMLElement $xml, string $language = MailTemplate::FALLBACK): array
    {
        $sender = (string) $xml->fromAgentName;
        $message = (string) $xml->message;
        // The kinds of messages of http://wiki.secondlife.com/wiki/ImprovedInstantMessage; the grid itself writes as the grid
        $kind = match ((string) $xml->dialog) {
            '32' => 'group-notice',
            '3' => 'group-invitation',
            '4' => 'inventory-offer',
            '38' => 'friendship-offer',
            default => $sender === 'Server' ? 'grid-message' : 'message',
        };
        $sender === 'Server' && ($sender = $this->gridName);
        // A notice starts with its title: "Title|Text"
        [$title, $text] = $kind === 'group-notice' && str_contains($message, '|') ? explode('|', $message, 2) : ['', $message];
        $values = ['grid' => $this->gridName, 'sender' => $sender, 'title' => $title, 'message' => $text];
        $roots = $this->templates !== [] ? $this->templates : \W4OS\Engine\Provisioning\ResourceRegistry::directories('share/mail');
        $mail = (new MailTemplate($roots))->render("offline-$kind", $language, $values)
            ?? throw new \RuntimeException("no template of the mail offline-$kind (share/mail of the kit)");

        return ['from' => $mail['from'], 'subject' => $mail['subject'], 'text' => $mail['text'], 'html' => $mail['html']];
    }
}
