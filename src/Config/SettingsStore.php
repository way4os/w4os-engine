<?php

declare(strict_types=1);

namespace OpenSim\Config;

/**
 * The settings an application keeps for the engine it bundles (a site): read before the files of a grid (Grid::config()), and where
 * the settings pages write for a grid that has no files here, when the application can keep them (writable()).
 */
interface SettingsStore
{
    /** @return array<string,array<string,string>> section => key => value */
    public function all(): array;

    /** Whether the application keeps what the engine writes. */
    public function writable(): bool;

    /** Keep a setting, or forget it (null). */
    public function set(string $section, string $key, ?string $value): void;
}
