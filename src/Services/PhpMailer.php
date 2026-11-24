<?php

declare(strict_types=1);

namespace W4OS\Engine\Services;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends mail with the mail function of PHP: it needs a mail system on the machine (a local SMTP server, sendmail). The mail is built
 * by symfony/mime: a text part beside the HTML one, its headers encoded.
 */
final class PhpMailer implements MailerInterface
{
    public function send(string $to, string $subject, string $html, string $from, string $text = ''): bool
    {
        $mail = self::message($to, $subject, $html, $from, $text);

        return mail($to, $mail['subject'], $mail['body'], $mail['headers']);
    }

    /**
     * What the mail function of PHP takes: the subject encoded, the other headers (To and Subject are its own arguments), the body.
     *
     * @return array{subject:string,headers:string,body:string}
     */
    public static function message(string $to, string $subject, string $html, string $from, string $text = ''): array
    {
        $email = (new Email())->from(Address::create($from))->to($to)->subject($subject)->html($html);
        $text === '' || $email->text($text);
        $body = $email->getBody();
        $headers = $email->getPreparedHeaders();
        $encoded = trim(substr($headers->get('Subject')?->toString() ?? '', strlen('Subject: ')));
        $headers->remove('To');
        $headers->remove('Subject');

        return [
            'subject' => $encoded,
            'headers' => rtrim($headers->toString() . $body->getPreparedHeaders()->toString(), "\r\n"),
            'body' => $body->bodyToString(),
        ];
    }
}
