<?php

declare(strict_types=1);

namespace W4OS\Engine\SignIn;

/**
 * The answer to a sign-in: granted with the avatar, refused (unknown avatar or wrong password, alike), or unavailable.
 */
final class SignInResult
{
    public const GRANTED = 'granted';
    public const REFUSED = 'refused';
    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly string $status,
        public readonly ?Avatar $avatar = null,
        /** What was wrong when the grid is unavailable, or why a sign-in whose password is right is refused. */
        public readonly string $reason = '',
        /** What more the grid told when it granted the sign-in. */
        public readonly array $details = [],
        /** Why it is refused, as a code of the problems of the API (Problem::CODES): `sign-in-refused`, `account-disabled`... */
        public readonly string $code = '',
    ) {}

    public static function granted(Avatar $avatar, array $details = []): self
    {
        return new self(self::GRANTED, $avatar, '', $details);
    }

    /**
     * @param string $reason why, when the password is right (an account that is not active); none for a wrong name or password
     * @param string $code the same as a code: `account-disabled`, `email-unconfirmed`; `sign-in-refused` for a wrong one
     */
    public static function refused(string $reason = '', string $code = 'sign-in-refused'): self
    {
        return new self(self::REFUSED, null, $reason, [], $code);
    }

    public static function unavailable(string $reason): self
    {
        return new self(self::UNAVAILABLE, null, $reason);
    }

    public function isGranted(): bool
    {
        return $this->status === self::GRANTED;
    }
}
