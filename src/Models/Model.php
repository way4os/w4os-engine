<?php

declare(strict_types=1);

namespace W4OS\Engine\Models;

use W4OS\Engine\Exceptions\ChannelUnavailable;
use W4OS\Engine\Interfaces\OpenSimModelInterface;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Connections;
use W4OS\Engine\Data\Database;

/**
 * What the models of the engine have in common: a record read from the databases or the files of a grid, and the defaults of what a
 * model does not do (no owner, no flags, no channel to write through: a write is refused).
 */
abstract class Model implements OpenSimModelInterface
{
    /**
     * @param array<string,mixed> $row the record as its table gives it
     */
    final public function __construct(protected readonly GridConfig $config, protected readonly array $row) {}

    public function uuid(): ?string
    {
        return null;
    }

    public function uri(): ?string
    {
        return null;
    }

    public function owner(): ?string
    {
        return null;
    }

    public function group(): ?string
    {
        return null;
    }

    public function flags(): int
    {
        return 0;
    }

    public function lastSeen(): ?\DateTimeImmutable
    {
        return null;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function channels(): array
    {
        return [];
    }

    public function update(array $values): void
    {
        throw ChannelUnavailable::for('change ' . $this->name());
    }

    public function enable(): bool
    {
        throw ChannelUnavailable::for('enable ' . $this->name());
    }

    public function disable(): void
    {
        throw ChannelUnavailable::for('disable ' . $this->name());
    }

    public function delete(): void
    {
        throw ChannelUnavailable::for('delete ' . $this->name());
    }

    public static function create(GridConfig $config, array $values): static
    {
        throw ChannelUnavailable::for('create a ' . strtolower((new \ReflectionClass(static::class))->getShortName()));
    }

    /**
     * The connection to a database the grid may not have: null when its config sets none, the reason it cannot be had when it sets
     * one that does not answer (never "none" for a database that is down).
     *
     * @param Database|string $what a database, or the section of a service of Robust
     * @param string $action what was being done ("read the picks"), for the message
     * @throws ChannelUnavailable
     */
    protected static function optional(
        GridConfig $config,
        Database|string $what,
        ?string $simulator,
        string $action,
    ): ?OSPDO {
        return Connections::pdo($config, $what, $simulator)
            ?? (Connections::key($config, $what, $simulator) === null ? null : self::connect($config, $what, $simulator, $action));
    }

    /**
     * The connection to a database of the grid, or the reason it cannot be had.
     *
     * @param Database|string $what a database, or the section of a service of Robust
     * @param string $action what was being done ("read the avatars"), for the message
     * @throws ChannelUnavailable
     */
    protected static function connect(
        GridConfig $config,
        Database|string $what,
        ?string $simulator,
        string $action,
    ): OSPDO {
        $pdo = Connections::pdo($config, $what, $simulator);
        if ($pdo !== null) {
            return $pdo;
        }
        if (Connections::key($config, $what, $simulator) === null) {
            throw ChannelUnavailable::because($action, 'database-unsaid');
        }

        throw ChannelUnavailable::because($action, 'database-unreachable', [], Connections::reason($config, $what, $simulator));
    }

    /**
     * The pattern of a LIKE (written with ESCAPE '!') that finds a word anywhere: the wildcards of the database, % and _, are only
     * characters of the word.
     */
    protected static function contains(string $word): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word) . '%';
    }

    /** A date from a Unix time, null when there is none (0, empty, not a number). */
    protected static function date(mixed $time): ?\DateTimeImmutable
    {
        $time = is_numeric($time) ? (int) $time : 0;

        return $time > 0 ? new \DateTimeImmutable('@' . $time) : null;
    }
}
