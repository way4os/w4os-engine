<?php

declare(strict_types=1);

namespace W4OS\Engine\Services;

/**
 * Sends an email: the instant messages of an avatar who is not there, forwarded to their address.
 */
interface MailerInterface
{
    /**
     * @param string $html the body, an HTML document
     * @param string $from the address and the name the mail comes from (`Name <address>`)
     * @param string $text the same body in plain text, a part of its own beside the HTML; none: the HTML alone
     * @return bool whether the mail was handed to the mail system
     */
    public function send(string $to, string $subject, string $html, string $from, string $text = ''): bool;
}
