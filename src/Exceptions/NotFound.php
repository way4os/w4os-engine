<?php

declare(strict_types=1);

namespace W4OS\Engine\Exceptions;

/**
 * What is asked for is not there: a grid that is not known here, a resource that does not exist. `resource` names the kind of
 * resource that is not there (a type of the API: grids, avatars, regions), for the text a site says.
 */
final class NotFound extends \RuntimeException
{
    public ?string $resource = null;

    public static function for(string $what, ?string $resource = null): self
    {
        $error = new self("There is no $what.");
        $error->resource = $resource;

        return $error;
    }
}
