<?php

declare(strict_types=1);

namespace W4OS\Engine\SignIn;

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Connections;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Exceptions\ChannelUnavailable;
use W4OS\Engine\Runtime\ClockInterface;

/**
 * The registrations of new avatars that wait for the confirmation of their email, in the `avatar_registrations` table of the database
 * of the passwords (the authentication service), made when it is not there. A registration is what the avatar will be: its name, its
 * email, its model, the UUID it will have, and its password as Robust keeps it (hashed and salted, never in clear). Nothing of the
 * avatar is in the tables of the grid until its email is confirmed. A registration keeps no name: two may ask for the same one, the
 * first confirmed gets it.
 *
 * A registration has a one-time token, kept hashed. The expired registrations are forgotten whenever one is kept, found or renewed.
 *
 *     $registrations = Registrations::for($config, $clock);
 *     $token = $registrations->keep($request, 172800);   // to send
 *     $registrations->find($token);                      // the request, until it is forgotten or expires
 */
final class Registrations
{
    private const TABLE = 'avatar_registrations';

    private bool $ready = false;

    public function __construct(private readonly OSPDO $pdo, private readonly ClockInterface $clock) {}

    /**
     * The registrations of a grid.
     *
     * @throws ChannelUnavailable when the database of the passwords cannot be reached
     */
    public static function for(GridConfig $config, ClockInterface $clock): self
    {
        $pdo =
            Connections::pdo($config, 'AuthenticationService') ??
            throw ChannelUnavailable::for(
                'keep the registrations of the avatars',
                Connections::reason($config, 'AuthenticationService') ?? 'the database cannot be reached',
            );

        return new self($pdo, $clock);
    }

    /**
     * Keep a registration, which replaces the ones of the same name and the same email.
     *
     * @param array{avatar:string,first:string,last:string,email:string,model:string,hash:string,salt:string} $request
     * @param int $ttl seconds it is valid
     * @return string its token, 32 hexadecimal characters: only its hash is kept
     * @throws ChannelUnavailable when it cannot be kept
     */
    public function keep(array $request, int $ttl): string
    {
        $this->purge();
        $this->run(
            'DELETE FROM ' .
                self::TABLE .
                ' WHERE LOWER(first_name) = :first AND LOWER(last_name) = :last AND LOWER(email) = :email',
            [
                'first' => strtolower($request['first']),
                'last' => strtolower($request['last']),
                'email' => strtolower($request['email']),
            ],
        );
        $token = bin2hex(random_bytes(16));
        $now = (int) $this->clock->time();
        $this->run(
            'INSERT INTO ' .
                self::TABLE .
                ' (token, avatar, first_name, last_name, email, model, password_hash, password_salt, created, expires)' .
                ' VALUES (:token, :avatar, :first, :last, :email, :model, :hash, :salt, :created, :expires)',
            [
                'token' => self::hash($token),
                'avatar' => strtolower($request['avatar']),
                'first' => $request['first'],
                'last' => $request['last'],
                'email' => $request['email'],
                'model' => $request['model'],
                'hash' => $request['hash'],
                'salt' => $request['salt'],
                'created' => $now,
                'expires' => $now + $ttl,
            ],
        );

        return $token;
    }

    /**
     * The registration of a token, before it expires. It stays until it is forgotten.
     *
     * @return ?array{avatar:string,first:string,last:string,email:string,model:string,hash:string,salt:string,expires:int}
     */
    public function find(string $token): ?array
    {
        $token = strtolower(trim($token));
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return null;
        }
        $this->purge();
        $row = $this->pdo->get_row(
            'SELECT * FROM ' . self::TABLE . ' WHERE token = :token',
            ['token' => self::hash($token)],
            \PDO::FETCH_ASSOC,
        );

        return is_array($row) ? self::request($row) : null;
    }

    /**
     * Give the registrations of an avatar to come a new token each (the last one is refused), valid again for `ttl`.
     *
     * @param string $who the UUID it will have, or its name, "First Last"
     * @return list<array{token:string,request:array{avatar:string,first:string,last:string,email:string,model:string,hash:string,salt:string,expires:int}}>
     * @throws ChannelUnavailable when they cannot be kept
     */
    public function renew(string $who, int $ttl): array
    {
        $this->purge();
        $who = trim($who);
        [$first, $last] = array_pad(explode(' ', $who, 2), 2, '');
        $rows = $this->pdo->get_results(
            'SELECT * FROM ' .
                self::TABLE .
                ' WHERE avatar = :id OR (LOWER(first_name) = :first AND LOWER(last_name) = :last) ORDER BY created',
            ['id' => strtolower($who), 'first' => strtolower($first), 'last' => strtolower(trim($last))],
            \PDO::FETCH_ASSOC,
        ) ?:
            [];
        $renewed = [];
        foreach ($rows as $row) {
            $token = bin2hex(random_bytes(16));
            $expires = (int) $this->clock->time() + $ttl;
            $this->run('UPDATE ' . self::TABLE . ' SET token = :new, expires = :expires WHERE token = :old', [
                'new' => self::hash($token),
                'expires' => $expires,
                'old' => $row['token'],
            ]);
            $renewed[] = ['token' => $token, 'request' => ['expires' => $expires] + self::request($row)];
        }

        return $renewed;
    }

    /**
     * Forget the registration of a token.
     *
     * @return bool whether it was there: false when another request took it in between
     */
    public function forget(string $token): bool
    {
        $this->ready();

        return $this->run('DELETE FROM ' . self::TABLE . ' WHERE token = :token', [
            'token' => self::hash(strtolower(trim($token))),
        ])->rowCount() === 1;
    }

    /** The expired registrations nobody confirmed (a typo in an email, a robot) are forgotten. */
    private function purge(): void
    {
        $this->ready();
        $this->run('DELETE FROM ' . self::TABLE . ' WHERE expires < :now', ['now' => (int) $this->clock->time()]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{avatar:string,first:string,last:string,email:string,model:string,hash:string,salt:string,expires:int}
     */
    private static function request(array $row): array
    {
        return [
            'avatar' => (string) $row['avatar'],
            'first' => (string) $row['first_name'],
            'last' => (string) $row['last_name'],
            'email' => (string) $row['email'],
            'model' => (string) $row['model'],
            'hash' => (string) $row['password_hash'],
            'salt' => (string) $row['password_salt'],
            'expires' => (int) $row['expires'],
        ];
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
            throw ChannelUnavailable::for('keep the registrations of the avatars', $e->getMessage());
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
                    ' (token CHAR(64) NOT NULL PRIMARY KEY, avatar CHAR(36) NOT NULL, first_name VARCHAR(64) NOT NULL,' .
                    " last_name VARCHAR(64) NOT NULL, email VARCHAR(255) NOT NULL, model VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' password_hash CHAR(32) NOT NULL, password_salt VARCHAR(64) NOT NULL, created INT NOT NULL, expires INT NOT NULL)',
                [],
            );
        $this->ready = true;
    }
}
