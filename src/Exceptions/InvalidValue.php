<?php

declare(strict_types=1);

namespace OpenSim\Exceptions;

/**
 * A value that is refused, with the reason as a stable code (`name-taken`, `password-invalid`...) beside the English message: what
 * a site or an application shows in its own language. The codes are the ones of the problems of the API (Problem::CODES).
 *
 *     throw InvalidValue::because('name-taken', "an avatar called 'Ada Love' exists already");
 */
final class InvalidValue extends \InvalidArgumentException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
