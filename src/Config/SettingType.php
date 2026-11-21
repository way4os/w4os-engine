<?php

declare(strict_types=1);

namespace W4OS\Engine\Config;

/**
 * A type of setting that is more than a text: how its value, as a file says it, reads and is written back, how a form edits it, and
 * what of it is never shown.
 */
interface SettingType
{
    /** The value as the engine uses it, null when the file says nothing. */
    public function decode(string $raw): mixed;

    /**
     * The value as the file keeps it.
     *
     * @param ?string $current what the file says now: a part the value leaves masked is kept from it
     */
    public function encode(mixed $value, ?string $current = null): string;

    /**
     * How a form edits it: its parts, in the standard field format.
     *
     * @return list<array<string,mixed>>
     */
    public function fields(): array;

    /** The value without what is never shown (Settings::MASK in its place when it is set). */
    public function mask(mixed $value): mixed;
}
