<?php

declare(strict_types=1);

namespace W4OS\Engine\Hypergrid;

/**
 * The link of a Reachable, from its address (tpUri()) and the login of its grid (tpLogin()): the one place an object makes its
 * link, through Destination::format().
 */
trait TeleportLinks
{
    public function tpLink(?int $format = null, array $context = []): string|array|null
    {
        $uri = $this->tpUri();
        if ($uri === null || trim($uri) === '') {
            return null;
        }
        $login = $this->tpLogin();
        $context += $login === null || $login === '' ? [] : ['login' => $login];

        $link = Destination::format($uri, $format ?? Destination::AUTO, context: $context);

        return is_array($link) ? $link : (string) $link;
    }

    /** The login of the grid it is on, which tells AUTO a place of that grid from one of another; null when it is not known. */
    protected function tpLogin(): ?string
    {
        return null;
    }
}
