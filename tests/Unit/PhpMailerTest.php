<?php

declare(strict_types=1);

/**
 * The mail of PHP: what it hands to the mail system, its headers written as a mail has them (a name or a subject that is not ASCII
 * encoded, RFC 2047).
 */

use W4OS\Engine\Services\PhpMailer;

describe('A mail sent by PHP', function () {
    test('has its subject and the name it comes from encoded when they are not ASCII, its address as it is', function () {
        $subject = PhpMailer::subject('Message from Zoé Lapointe');
        $headers = PhpMailer::headers('Zoé Lapointe <no-reply@kimbe.example>');

        expect($subject)->toContain('=?UTF-8?')->not->toContain('Zoé')
            ->and(iconv_mime_decode($subject, 0, 'UTF-8'))->toBe('Message from Zoé Lapointe')
            ->and($headers)->toContain('<no-reply@kimbe.example>')->toContain('=?UTF-8?')->not->toContain('Zoé')
            ->and(iconv_mime_decode(preg_replace('/^From: (.*?)\r\n.*$/s', '$1', $headers), 0, 'UTF-8'))->toBe('Zoé Lapointe <no-reply@kimbe.example>')
            // What is ASCII stays readable
            ->and(PhpMailer::subject('Message from Ada'))->toBe('Message from Ada')
            ->and(PhpMailer::headers('Ada <no-reply@example.org>'))->toStartWith("From: Ada <no-reply@example.org>\r\n");
    });

    test('quotes a name that has what an address separates (a comma), so the mail system reads one sender', function () {
        expect(PhpMailer::headers('Love, Ada <no-reply@example.org>'))->toStartWith("From: \"Love, Ada\" <no-reply@example.org>\r\n");
    });
});
