<?php

declare(strict_types=1);

namespace W4OS\Engine\Exceptions;

/**
 * An instance that could not be started or stopped: the message says why, the reason in a word for a site (`port-taken`, else
 * `instance-failed`).
 */
final class InstanceFailed extends \RuntimeException
{
    public function __construct(
        public readonly string $instance,
        string $message,
        public readonly string $reason = 'instance-failed',
    ) {
        parent::__construct($message);
    }
}
