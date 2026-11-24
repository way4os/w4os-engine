<?php

declare(strict_types=1);

namespace W4OS\Engine\Services;

/**
 * Sends mail with the mail function of PHP: it needs a mail system on the machine (a local SMTP server, sendmail).
 */
final class PhpMailer implements MailerInterface
{
    public function send(string $to, string $subject, string $html, string $from): bool
    {
        return mail($to, self::subject($subject), $html, self::headers($from));
    }

    /** A subject as a header has it: encoded when it is not ASCII (RFC 2047). */
    public static function subject(string $subject): string
    {
        return self::ascii($subject) ? $subject : mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    }

    /**
     * The headers of an HTML mail from `Name <address>`: the name quoted when it has what separates addresses, encoded when it is
     * not ASCII (RFC 5322, RFC 2047), the address as it is.
     */
    public static function headers(string $from): string
    {
        if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $from, $m) === 1 && $m[1] !== '') {
            $name = trim($m[1], '"');
            $name = match (true) {
                !self::ascii($name) => mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n"),
                preg_match('/[()<>@,;:\\\\".\[\]]/', $name) === 1 => '"' . addcslashes($name, '"\\') . '"',
                default => $name,
            };
            $from = "$name <{$m[2]}>";
        }

        return "From: $from\r\nMIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\n";
    }

    private static function ascii(string $text): bool
    {
        return preg_match('/[^\x20-\x7E]/', $text) !== 1;
    }
}
