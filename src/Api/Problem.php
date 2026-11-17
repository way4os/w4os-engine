<?php

declare(strict_types=1);

namespace OpenSim\Api;

use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Exceptions\InstanceFailed;
use OpenSim\Exceptions\InvalidValue;
use OpenSim\Exceptions\NotFound;

/**
 * What went wrong, told as RFC 9457 asks (`application/problem+json`): a status, a title, what happened, and where. The same
 * problem for the command line, the HTTP API and the PHP library: the exception says it, the adapter shows it.
 *
 * Every problem has a `code` (an extension member), one of CODES: what a site or an application tells its people in its own
 * language, the `detail` being the English one. The `type` names the kind of problem, the code says which one it is.
 */
final class Problem
{
    public const CONTENT_TYPE = 'application/problem+json';

    /** The codes of the problems, and what each says. */
    public const CODES = [
        'not-found' => 'What is asked is not there (a grid, a resource, an avatar)',
        'invalid-request' => 'A value of the request is wrong, the detail says which',
        'invalid-document' => 'The body is not a JSON:API document',
        'type-conflict' => 'The type of the resource of the body is not the one of the address',
        'unauthorized' => 'The operation needs an API key',
        'method-not-allowed' => 'The address does not take this method',
        'channel-unavailable' => 'Nothing can do it now: a database, a simulator, a file cannot be reached',
        'instance-failed' => 'An instance (Robust, a simulator) failed',
        'internal-error' => 'The server failed, the log says why',
        'sign-in-refused' => 'The name (or the email) or the password is wrong',
        'account-disabled' => 'The password is right, but a manager disabled the account',
        'email-unconfirmed' => 'The password is right, but the account waits for the confirmation of its email',
        'name-invalid' => 'The name is not a first and a last name of letters, digits and -',
        'name-reserved' => 'A part of the name is reserved: Default, Test, Admin, the name of the grid',
        'name-taken' => 'An avatar has this name already',
        'password-required' => 'A password is needed',
        'password-invalid' => 'The password is too short, too long, or has a double quote',
        'email-invalid' => 'The email is not an address',
        'email-required' => 'An email is needed to be confirmed',
        'model-unknown' => 'The model is not an avatar model of the grid',
        'link-invalid' => 'The link is not a web address',
        'field-unknown' => 'A value given cannot be changed',
        'field-read-only' => 'The value is the one of a file its operator changes (the name, the place, the size of a region)',
        'flag-unknown' => 'The flag is not one of a region (DefaultRegion, FallbackRegion, Persistent...)',
        'owner-unknown' => 'The grid has no avatar of this UUID',
        'config-not-writable' => 'A config file of the grid cannot be written by the process of the API (it belongs to the user of the grid): nothing was changed, the detail names the file',
        'instance-user-required' => 'The instances are started and stopped by the user that runs them: this process is another (the web server of a site); nothing was done',
        'estate-shared' => 'The owner of a region is the one of its estate, which has other regions: they would change owner too',
        'filter-unknown' => 'The list has no such filter (the detail names the ones it has)',
        'filter-value-unknown' => 'The filter does not take this value (the detail names the ones it takes)',
        'sort-unknown' => 'The list cannot be sorted so (the detail names the sorts it takes)',
        'role-unknown' => 'The role is not one of resident, god-like, customer-service, liaison, god',
        'role-level-conflict' => 'The level given with a role is not one of that role',
        'token-refused' => 'The token is not valid, was used, or has expired',
        'no-confirmation-pending' => 'The avatar does not wait for the confirmation of its email',
        'mail-failed' => 'The mail could not be sent',
    ];

    /** The status of a refusal that is not a wrong value of the request: it is right, this process cannot do it. */
    private const REASON_STATUSES = ['config-not-writable' => 403, 'instance-user-required' => 403];

    /** The titles of the statuses of a refusal. */
    private const TITLES = [400 => 'Invalid request', 403 => 'Forbidden'];

    /** The code of a problem that has none of its own, by its status (else invalid-request, or internal-error for the server). */
    private const STATUS_CODES = [
        401 => 'unauthorized',
        404 => 'not-found',
        405 => 'method-not-allowed',
        503 => 'channel-unavailable',
    ];

    /**
     * @param array<string,mixed> $extensions members of its own, beside the standard ones
     */
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly string $detail = '',
        public readonly string $type = 'about:blank',
        public readonly ?string $instance = null,
        public readonly array $extensions = [],
        public readonly ?string $code = null,
    ) {}

    /** A problem with a code of its own. */
    public static function coded(
        int $status,
        string $title,
        string $code,
        string $detail,
        string $type,
        ?string $instance = null,
    ): self {
        return new self($status, $title, $detail, $type, $instance, [], $code);
    }

    /** What is asked is not there. */
    public static function notFound(string $what, ?string $instance = null): self
    {
        return new self(404, 'Not found', "There is no $what.", 'urn:opensim:problem:not-found', $instance);
    }

    /** The problem an exception stands for. A failure of the server does not tell its message: it can hold a path or a password. */
    public static function from(\Throwable $error, ?string $instance = null): self
    {
        return match (true) {
            $error instanceof NotFound => new self(
                404,
                'Not found',
                $error->getMessage(),
                'urn:opensim:problem:not-found',
                $instance,
            ),
            $error instanceof \InvalidArgumentException => new self(
                self::statusOf($error),
                self::TITLES[self::statusOf($error)] ?? 'Invalid request',
                $error->getMessage(),
                'urn:opensim:problem:invalid-request',
                $instance,
                [],
                $error instanceof InvalidValue ? $error->reason : 'invalid-request',
            ),
            $error instanceof InstanceFailed => new self(
                500,
                'Instance failed',
                $error->getMessage(),
                'urn:opensim:problem:instance-failed',
                $instance,
            ),
            $error instanceof ChannelUnavailable => new self(
                503,
                'Not available',
                $error->getMessage(),
                'urn:opensim:problem:channel-unavailable',
                $instance,
            ),
            // The detail is not for whoever asks: it goes to the log (the error output of the command line)
            default => self::internal($error, $instance),
        };
    }

    /** The status of a wrong value: 400, or the one of a refusal its reason names (REASON_STATUSES). */
    private static function statusOf(\InvalidArgumentException $error): int
    {
        return $error instanceof InvalidValue ? self::REASON_STATUSES[$error->reason] ?? 400 : 400;
    }

    private static function internal(\Throwable $error, ?string $instance): self
    {
        error_log(sprintf('opensim-api: %s: %s (%s:%d)', $error::class, $error->getMessage(), $error->getFile(), $error->getLine()));

        return new self(500, 'Internal error', '', 'about:blank', $instance);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter(
            [
                'type' => $this->type,
                'title' => $this->title,
                'status' => $this->status,
                'code' => $this->code(),
                'detail' => $this->detail,
                'instance' => $this->instance,
            ] + $this->extensions,
            static fn($value): bool => $value !== null && $value !== '',
        );
    }

    /** The code of the problem: its own, else the one its type names, else the one of its status. */
    public function code(): string
    {
        if ($this->code !== null) {
            return $this->code;
        }
        $named = str_starts_with($this->type, 'urn:opensim:problem:') ? substr($this->type, 20) : '';

        return isset(self::CODES[$named])
            ? $named
            : self::STATUS_CODES[$this->status] ?? ($this->status >= 500 ? 'internal-error' : 'invalid-request');
    }

    public function json(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
