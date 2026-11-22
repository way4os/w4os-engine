<?php

declare(strict_types=1);

namespace W4OS\Engine\Assets;

/**
 * The picture of a Pictured, from its asset (pictureAsset()) and its title (pictureTitle()): the one place an object chooses it,
 * through TitleImage::pick().
 */
trait Pictures
{
    public function picture(): string
    {
        return TitleImage::pick($this->pictureAsset(), $this->pictureTitle());
    }

    /** The UUID of its own picture, null when it has none. */
    abstract protected function pictureAsset(): ?string;

    /** What the image of its title says: its name. */
    protected function pictureTitle(): string
    {
        return $this->name();
    }
}
