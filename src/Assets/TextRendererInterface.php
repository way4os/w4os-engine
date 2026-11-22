<?php

declare(strict_types=1);

namespace W4OS\Engine\Assets;

/**
 * Makes the picture of a text, the texture of a prim.
 */
interface TextRendererInterface
{
    /**
     * @return ?string the PNG of the text, square, null when the machine cannot draw
     * @throws \InvalidArgumentException when the color or the background is not one
     */
    public function render(string $text, string $font, int $size, string $color, string $background): ?string;

    /**
     * The text as large as it fits in the middle band of a square, in lines of whole words, centered: the image of a title.
     *
     * @param string $format png, jpg...
     * @return ?string the image, null when the machine cannot draw
     * @throws \InvalidArgumentException when the color or the background is not one
     */
    public function fit(string $text, int $side, string $color, string $background, string $format = 'png'): ?string;

    /** @return list<string> the fonts the machine has */
    public function fonts(): array;
}
