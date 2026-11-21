<?php

declare(strict_types=1);

namespace W4OS\Engine\Exceptions;

use W4OS\Engine\Text\Notice;

/**
 * A change that no channel can make now: the database cannot be reached, the simulator is stopped, the file cannot be written.
 * Its `reason`, when it has one, is a notice (Text\Notice): a code and its values, for a site to say it in its language.
 */
final class ChannelUnavailable extends \RuntimeException
{
    /** @var ?array{code:string,params:array<string,string|int>,detail:string} */
    public ?array $reason = null;

    public static function for(string $what, string $reason = ''): self
    {
        return new self($reason !== '' ? "cannot $what: $reason" : "cannot $what: no channel is available");
    }

    /**
     * Unavailable for a reason of the catalog (a code of Text\Notice and its values); `$detail` the English reason when it says
     * more than the notice (the message of the driver of a database).
     *
     * @param array<string,string|int> $params
     */
    public static function because(string $what, string $code, array $params = [], ?string $detail = null): self
    {
        $reason = Notice::of($code, $params);
        $error = self::for($what, $detail ?? lcfirst(rtrim($reason['detail'], '.')));
        $error->reason = $reason;

        return $error;
    }
}
