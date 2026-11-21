<?php

declare(strict_types=1);

namespace W4OS\Engine\Text;

/**
 * The secrets a log or a config holds, masked in what is shown: the secret of the data service (DataSnapshot), the passwords of the
 * connection strings and of the settings, the keys and the passwords given in an address.
 *
 *     Secrets::mask('... notified. Secret: 4f1c2a6e-...');   // '... notified. Secret: ***'
 */
final class Secrets
{
    /** What replaces a secret. */
    public const MASK = '***';

    /** The patterns of a secret: what precedes it is kept (group 1), the secret is masked. */
    private const PATTERNS = [
        // The secret of the data service, as the simulator logs it
        '/(\bSecret:\s*)[^\s,;]+/i',
        // A key or a password given in an address (?secret=..., &password=...)
        '/([?&](?:secret|password|pass|pwd|token|key|api_?key)=)[^&\s"\']+/i',
        // A password of a connection string (Password=...;)
        '/(\b(?:Password|Pwd)=)[^;"\s]+/i',
        // A setting whose name says it is a password or a secret: Password, ConsolePass, Secret, ApiKey...
        '/(^\s*[A-Za-z_]*(?:Pass(?:word)?|Secret|ApiKey|Token)\s*=\s*"?)[^"\r\n]+/im',
        // The password of an address (user:password@host)
        '#(\b[a-z][a-z0-9+.-]*://[^/\s:@]+:)[^@\s/]+(?=@)#i',
    ];

    /** The text with its secrets masked. */
    public static function mask(string $text): string
    {
        foreach (self::PATTERNS as $pattern) {
            $text = (string) preg_replace($pattern, '${1}' . self::MASK, $text);
        }

        return $text;
    }
}
