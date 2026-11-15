<?php

declare(strict_types=1);

namespace OpenSim\SignIn;

use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Data\OSPDO;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Runtime\ClockInterface;

/**
 * The one-time tokens of the avatars of a grid (a forgotten password, the confirmation of an email), in the `avatar_tokens` table of
 * the database of its passwords (the authentication service), made when it is not there. A token is given once and kept hashed; it
 * is taken back once, before it expires. An avatar has one token at a time for a purpose: a new one replaces the last.
 *
 *     $tokens = AvatarTokens::for($config, $clock);
 *     $token = $tokens->issue($uuid, AvatarTokens::PASSWORD_RESET, 86400, $email);   // to send
 *     $tokens->redeem($token, AvatarTokens::PASSWORD_RESET);                          // ['avatar' => $uuid, 'email' => $email], once
 */
final class AvatarTokens
{
    public const PASSWORD_RESET = 'password-reset';
    public const EMAIL_CONFIRMATION = 'email-confirmation';

    /** A new email an avatar asked for, kept in the token until it is confirmed. */
    public const EMAIL_CHANGE = 'email-change';

    private const TABLE = 'avatar_tokens';

    private bool $ready = false;

    public function __construct(private readonly OSPDO $pdo, private readonly ClockInterface $clock) {}

    /**
     * The tokens of a grid.
     *
     * @throws ChannelUnavailable when the database of the passwords cannot be reached
     */
    public static function for(GridConfig $config, ClockInterface $clock): self
    {
        $pdo =
            Connections::pdo($config, 'AuthenticationService') ??
            throw ChannelUnavailable::for(
                'keep the tokens of the avatars',
                Connections::reason($config, 'AuthenticationService') ?? 'the database cannot be reached',
            );

        return new self($pdo, $clock);
    }

    /**
     * A new token for an avatar, which replaces the one it had for this purpose.
     *
     * @param int $ttl seconds it is valid
     * @param string $email the address it is sent to, given back with it
     * @return string the token, 32 hexadecimal characters: it is not kept, only its hash is
     * @throws ChannelUnavailable when it cannot be kept
     */
    public function issue(string $avatar, string $purpose, int $ttl, string $email = ''): string
    {
        $this->ready();
        $now = (int) $this->clock->time();
        $token = bin2hex(random_bytes(16));
        $this->run('DELETE FROM ' . self::TABLE . ' WHERE avatar = :avatar AND purpose = :purpose', [
            'avatar' => strtolower($avatar),
            'purpose' => $purpose,
        ]);
        // The forgotten passwords nobody came back for
        $this->run('DELETE FROM ' . self::TABLE . ' WHERE purpose = :purpose AND expires < :now', [
            'purpose' => self::PASSWORD_RESET,
            'now' => $now,
        ]);
        $this->run(
            'INSERT INTO ' .
                self::TABLE .
                ' (token, avatar, purpose, email, created, expires) VALUES (:token, :avatar, :purpose, :email, :created, :expires)',
            [
                'token' => self::hash($token),
                'avatar' => strtolower($avatar),
                'purpose' => $purpose,
                'email' => $email,
                'created' => $now,
                'expires' => $now + $ttl,
            ],
        );

        return $token;
    }

    /**
     * Take a token back: what it was given for, once, before it expires.
     *
     * @return ?array{avatar:string,email:string} null for a token that is not one of this purpose, was taken, or expired
     */
    public function redeem(string $token, string $purpose): ?array
    {
        $token = strtolower(trim($token));
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return null;
        }
        $this->ready();
        $row = $this->pdo->get_row(
            'SELECT avatar, email, expires FROM ' . self::TABLE . ' WHERE token = :token AND purpose = :purpose',
            ['token' => self::hash($token), 'purpose' => $purpose],
            \PDO::FETCH_ASSOC,
        );
        // An expired token stays: what it was for is still pending
        if (!is_array($row) || (int) $row['expires'] < (int) $this->clock->time()) {
            return null;
        }
        $statement = $this->run('DELETE FROM ' . self::TABLE . ' WHERE token = :token', ['token' => self::hash($token)]);
        // Another request took it in between
        if ($statement->rowCount() !== 1) {
            return null;
        }

        return ['avatar' => (string) $row['avatar'], 'email' => (string) $row['email']];
    }

    /** Whether an avatar has a token for this purpose, valid or expired. */
    public function pending(string $avatar, string $purpose): bool
    {
        $this->ready();

        return (int) $this->pdo->get_var(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE avatar = :avatar AND purpose = :purpose',
            ['avatar' => strtolower($avatar), 'purpose' => $purpose],
        ) > 0;
    }

    /** Forget the tokens of an avatar for this purpose. */
    public function forget(string $avatar, string $purpose): void
    {
        $this->ready();
        $this->run('DELETE FROM ' . self::TABLE . ' WHERE avatar = :avatar AND purpose = :purpose', [
            'avatar' => strtolower($avatar),
            'purpose' => $purpose,
        ]);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param array<string,scalar> $params
     * @throws ChannelUnavailable when the database refuses it
     */
    private function run(string $sql, array $params): \PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
        } catch (\PDOException $e) {
            throw ChannelUnavailable::for('keep the tokens of the avatars', $e->getMessage());
        }

        return $statement;
    }

    private function ready(): void
    {
        if ($this->ready) {
            return;
        }
        $this->pdo->table_exists(self::TABLE) ||
            $this->run(
                'CREATE TABLE IF NOT EXISTS ' .
                    self::TABLE .
                    ' (token CHAR(64) NOT NULL PRIMARY KEY, avatar CHAR(36) NOT NULL, purpose VARCHAR(32) NOT NULL,' .
                    " email VARCHAR(255) NOT NULL DEFAULT '', created INT NOT NULL, expires INT NOT NULL)",
                [],
            );
        $this->ready = true;
    }
}
