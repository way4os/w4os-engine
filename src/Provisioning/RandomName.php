<?php

namespace W4OS\Engine\Provisioning;

use Platoniq\RandomNameGenerator\Alliteration;

/**
 * A name made of an adjective and a noun that begin the same ("Gentle Gnat"), to propose when
 * there is nothing better to propose.
 */
final class RandomName
{
    /**
     * A name the caller accepts (not taken, for instance), transliterated to ASCII: the words come
     * from lists that have an accent. Empty when none was found, the caller proposes nothing then.
     *
     * @param \Closure(string):?string $problem returns why a name is refused, null when it is fine
     * @param ?\Closure():string $words the names to try (default: the alliterations of RandomNameGenerator)
     */
    public static function make(\Closure $problem, ?\Closure $words = null): string
    {
        if ($words === null) {
            $generator = new Alliteration();
            $words = static fn(): string => $generator->getName();
        }
        for ($i = 0; $i < 50; $i++) {
            $name = Slug::ascii($words());
            if ($problem($name) === null) {
                return $name;
            }
        }

        return '';
    }
}
