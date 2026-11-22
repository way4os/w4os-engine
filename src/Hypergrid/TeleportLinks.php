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
        $uri = $this->tpTarget();
        if ($uri === null || trim($uri) === '') {
            return null;
        }
        $link = Destination::format($uri, $format ?? Destination::AUTO, context: $this->tpContext($context));

        return is_array($link) ? $link : (string) $link;
    }

    public function tpLinks(?string $client = null, array $context = []): array
    {
        $uri = $this->tpTarget();
        $context = $client === null ? $context : ['client' => $client] + $context;

        $grid = $this->tpGrid();

        return $uri === null || trim($uri) === '' ? [] : Destination::links($uri, $this->tpContext($context + ($grid === null ? [] : ['grid' => $grid])));
    }

    /** Its login URI when it is a grid alone, which its links offer to add to the viewer (Destination::ADDGRID); null otherwise. */
    protected function tpGrid(): ?string
    {
        return null;
    }

    /** Where its links send a viewer: its address (tpUri()), unless it lands somewhere more precise (a grid on its region). */
    protected function tpTarget(): ?string
    {
        return $this->tpUri();
    }

    /** A context of Destination with the login and the formats of its grid, unless it gives them. */
    private function tpContext(array $context): array
    {
        $login = $this->tpLogin();
        $context += $login === null || $login === '' ? [] : ['login' => $login];
        $formats = $this->tpFormats();

        return $context + ($formats === [] ? [] : ['formats' => $formats]);
    }

    /**
     * The formats its grid chooses for AUTO, in a browser and in the viewer (Destination::linkFormats()); none, the default ones.
     *
     * @return array{browser?:int,viewer?:int}
     */
    protected function tpFormats(): array
    {
        return [];
    }

    /** The login of the grid it is on, which tells AUTO a place of that grid from one of another; null when it is not known. */
    protected function tpLogin(): ?string
    {
        return null;
    }
}
