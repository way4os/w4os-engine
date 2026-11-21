<?php

declare(strict_types=1);

namespace W4OS\Engine\Config;

/**
 * Settings an application gives as an array: read only when given so (Environment::system(settings: [...])), kept in memory when
 * made to be written (the application saves all() where it keeps its own data).
 *
 *     $kept = new ArraySettings($site->load());     // what the site kept
 *     ...                                            // the engine writes in it
 *     $site->save($kept->all());
 */
final class ArraySettings implements SettingsStore
{
    /** @param array<string,array<string,string>> $settings section => key => value */
    public function __construct(private array $settings = [], private readonly bool $writable = true) {}

    public function all(): array
    {
        return $this->settings;
    }

    public function writable(): bool
    {
        return $this->writable;
    }

    public function set(string $section, string $key, ?string $value): void
    {
        $this->writable || throw new \LogicException('these settings are read only');
        if ($value === null) {
            unset($this->settings[$section][$key]);

            return;
        }
        $this->settings[$section][$key] = $value;
    }
}
