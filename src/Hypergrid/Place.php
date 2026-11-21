<?php

declare(strict_types=1);

namespace W4OS\Engine\Hypergrid;

/**
 * A place given by its address, for what has no model of its own: a popular place, an entry of the guide, a result of the search,
 * an event, a pick or a classified. Its link is asked of it as of any Reachable (Grid::place() makes one for a grid).
 */
final class Place implements Reachable
{
    use TeleportLinks;

    /**
     * @param string $destination host:port/Region/x/y/z, host:port:Region, hop://... or a region of the grid by its name
     * @param ?string $login the login of the grid it is shown for: a region named alone is of that grid
     */
    public function __construct(private readonly string $destination, private readonly ?string $login = null) {}

    public function tpUri(): ?string
    {
        $uri = trim($this->destination) === '' ? '' : Destination::uri($this->destination, $this->login);

        return $uri === '' ? null : $uri;
    }

    protected function tpLogin(): ?string
    {
        return $this->login;
    }
}
