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
    /** @param array{browser?:int,viewer?:int} $formats the formats its grid chooses for AUTO (Destination::linkFormats()) */
    /** @param ?string $grid the login URI of the grid it is, for a grid alone (its links offer to add it to the viewer) */
    public function __construct(private readonly string $destination, private readonly ?string $login = null, private readonly array $formats = [], private readonly ?string $grid = null) {}

    /**
     * The place of a region on a grid, at a landing point if it has one, as the search and the profiles keep them: its grid (the
     * grid it is shown for when none is given), its region, its point (`x/y/z`, `x,y,z` or `<x, y, z>`, Destination::position());
     * null without a region or a grid.
     *
     * @param ?string $login the login of the grid it is shown for
     * @param array{browser?:int,viewer?:int} $formats the formats that grid chooses for AUTO
     */
    public static function at(string $grid, string $region, string $landing = '', ?string $login = null, array $formats = []): ?self
    {
        $grid = trim($grid) !== '' ? trim($grid) : trim((string) $login);
        if (trim($region) === '' || $grid === '') {
            return null;
        }
        $point = trim($landing) === '' ? '' : str_replace(',', '/', Destination::position($landing));

        return new self(rtrim($grid, '/') . '/' . $region . ($point === '' ? '' : '/' . $point), $login, $formats);
    }

    public function tpUri(): ?string
    {
        $uri = trim($this->destination) === '' ? '' : Destination::uri($this->destination, $this->login);

        return $uri === '' ? null : $uri;
    }

    protected function tpLogin(): ?string
    {
        return $this->login;
    }

    protected function tpGrid(): ?string
    {
        return $this->grid;
    }

    protected function tpFormats(): array
    {
        return $this->formats;
    }
}
