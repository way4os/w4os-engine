<?php

declare(strict_types=1);

namespace W4OS\Engine\Assets;

/**
 * Draws a text with ImageMagick (the extension `imagick`): the text centered on a 1024 pixels square, in the font, the size and the
 * colors asked (render()), or as large as it fits (fit()). A font the machine does not have is replaced by DejaVu-Sans, or by the
 * first font the machine has when it has not that either. The text is drawn as it is, never read as a file or a format (annotate,
 * not caption:).
 */
final class ImagickTextRenderer implements TextRendererInterface
{
    public const SIDE = 1024;
    public const FALLBACK_FONT = 'DejaVu-Sans';

    /** The font of fit(). */
    public const FIT_FONT = 'DejaVu-Sans-Bold';

    /**
     * The band of a square fit() fills, its width and its height, and where it starts (BAND_TOP): within what every card shows of a
     * square picture (the 5:3 of the guide the narrowest: from 0.2 to 0.8 of its height), under the corner where a card puts its
     * button (its menu of links), a margin around.
     */
    public const BAND = [0.84, 0.36];

    /** Where the band of fit() starts, as a share of the side. */
    public const BAND_TOP = 0.42;

    public function render(string $text, string $font, int $size, string $color, string $background): ?string
    {
        if (!extension_loaded('imagick')) {
            return null;
        }
        try {
            $draw = new \ImagickDraw();
            ($name = $this->font($font)) !== null && $draw->setFont($name);
            $draw->setFontSize($size);
            $draw->setFillColor($color);
            $draw->setTextAlignment(\Imagick::ALIGN_CENTER);
            $image = new \Imagick();
            $image->newImage(self::SIDE, self::SIDE, $background);
            $image->setImageFormat('png');
            $metrics = $image->queryFontMetrics($draw, $text);
            $image->annotateImage($draw, self::SIDE / 2, self::SIDE / 2 + $metrics['textHeight'] / 4, 0, $text);
            $blob = $image->getImageBlob();
            $image->clear();
        } catch (\ImagickException | \ImagickDrawException | \ImagickPixelException $error) {
            throw new \InvalidArgumentException(
                "the text cannot be drawn with the color '$color' on '$background': " . $error->getMessage(),
            );
        }

        return $blob !== '' ? $blob : null;
    }

    public function fit(string $text, int $side, string $color, string $background, string $format = 'png'): ?string
    {
        if (!extension_loaded('imagick')) {
            return null;
        }
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        try {
            $draw = new \ImagickDraw();
            ($name = $this->font(self::FIT_FONT)) !== null && $draw->setFont($name);
            $draw->setFillColor($color);
            $draw->setTextAlignment(\Imagick::ALIGN_CENTER);
            $image = new \Imagick();
            $image->newImage($side, $side, $background);
            $image->setImageFormat($format);
            [$width, $height] = [$side * self::BAND[0], $side * self::BAND[1]];
            // The largest size at which its lines, of whole words, fit the band
            [$low, $high] = [4.0, $height];
            while ($high - $low > 0.5) {
                $size = ($low + $high) / 2;
                $lines = $this->wrap($image, $draw, $words, $size, $width);
                $lines !== null && count($lines) * $this->lineHeight($image, $draw, $size) <= $height ? ($low = $size) : ($high = $size);
            }
            $lines = $this->wrap($image, $draw, $words, $low, $width) ?? [implode(' ', $words)];
            $line = $this->lineHeight($image, $draw, $low);
            $metrics = $image->queryFontMetrics($draw, 'Ag');
            // The lines centered in the band, under the corner of the button of a card
            $top = $side * self::BAND_TOP + ($height - count($lines) * $line) / 2;
            foreach ($lines as $i => $text) {
                // Its baseline: the glyphs centered in their line
                $image->annotateImage($draw, $side / 2, $top + $i * $line + ($line + $metrics['ascender'] + $metrics['descender']) / 2, 0, $text);
            }
            $blob = $image->getImageBlob();
            $image->clear();
        } catch (\ImagickException | \ImagickDrawException | \ImagickPixelException $error) {
            throw new \InvalidArgumentException("the text cannot be drawn with the color '$color' on '$background': " . $error->getMessage());
        }

        return $blob !== '' ? $blob : null;
    }

    public function fonts(): array
    {
        return extension_loaded('imagick') ? array_values(\Imagick::queryFonts('*')) : [];
    }

    /** The font drawn: the one asked, else the fallback, else the first the machine has; null for the default of ImageMagick. */
    private function font(string $font): ?string
    {
        $fonts = $this->fonts();

        return in_array($font, $fonts, true) ? $font : (in_array(self::FALLBACK_FONT, $fonts, true) ? self::FALLBACK_FONT : ($fonts[0] ?? null));
    }

    /**
     * Words in lines no wider than a width at a size, as many on a line as it holds; null when a word alone is wider.
     *
     * @param list<string> $words
     * @return ?list<string>
     */
    private function wrap(\Imagick $image, \ImagickDraw $draw, array $words, float $size, float $width): ?array
    {
        $draw->setFontSize($size);
        [$lines, $line] = [[], ''];
        foreach ($words as $word) {
            $longer = $line === '' ? $word : "$line $word";
            if ($image->queryFontMetrics($draw, $longer)['textWidth'] <= $width) {
                $line = $longer;
                continue;
            }
            if ($line === '' || $image->queryFontMetrics($draw, $word)['textWidth'] > $width) {
                return null;
            }
            [$lines[], $line] = [$line, $word];
        }
        $line !== '' && ($lines[] = $line);

        return $lines;
    }

    /** The height of a line at a size: from the top of its highest glyph to the bottom of its lowest. */
    private function lineHeight(\Imagick $image, \ImagickDraw $draw, float $size): float
    {
        $draw->setFontSize($size);
        $metrics = $image->queryFontMetrics($draw, 'Ag');

        return $metrics['ascender'] - $metrics['descender'];
    }
}
