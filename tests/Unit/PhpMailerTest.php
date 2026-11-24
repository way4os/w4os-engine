<?php

declare(strict_types=1);

/**
 * The mail of PHP: what it hands to the mail system, built by symfony/mime (a text part and an HTML part, the headers encoded).
 */

use W4OS\Engine\Services\PhpMailer;

describe('A mail sent by PHP', function () {
    test('has a text part and an HTML part, its subject and the name it comes from encoded when they are not ASCII', function () {
        $mail = PhpMailer::message('pam@example.org', 'Message from Zoé Lapointe', '<p>Bonjour</p>', 'Zoé Lapointe via Kimbé Red <no-reply@kimbe.example>', 'Bonjour');

        expect($mail['subject'])->toContain('=?')->not->toContain('Zoé')
            ->and(iconv_mime_decode($mail['subject'], 0, 'UTF-8'))->toBe('Message from Zoé Lapointe')
            ->and($mail['headers'])->toContain('<no-reply@kimbe.example>')->toContain('multipart/alternative')->not->toContain('Zoé')
            ->and(iconv_mime_decode(preg_replace('/^.*?From: (.*?)\r\n(?! ).*$/s', '$1', $mail['headers']), 0, 'UTF-8'))->toBe('Zoé Lapointe via Kimbé Red <no-reply@kimbe.example>')
            ->and($mail['headers'])->not->toMatch('/^(To|Subject):/m')
            ->and($mail['body'])->toContain('text/plain')->toContain('text/html')->toContain('Bonjour');
    });

    test('quotes a name that has what an address separates (a comma), so the mail system reads one sender', function () {
        $mail = PhpMailer::message('pam@example.org', 'Hi', '<p>Hi</p>', 'Love, Ada <no-reply@example.org>', 'Hi');

        expect($mail['headers'])->toContain('From: "Love, Ada" <no-reply@example.org>');
    });
});
