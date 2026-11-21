<?php

declare(strict_types=1);

namespace W4OS\Engine\Config;

/**
 * The settings of the helpers of a grid in place that have no place of their own in OpenSimulator (the source of the guide, the
 * search database, the models...): kept in its section of the config of the suite, as `Section.Key = value`, never in a file of the
 * install. What the settings pages of such a grid write for the helpers goes there.
 */
final class InPlaceSettings implements SettingsStore
{
    public function __construct(private readonly string $conf, private readonly string $nick) {}

    public function all(): array
    {
        $settings = [];
        foreach (InPlaceGrids::section($this->conf, $this->nick) as $name => $value) {
            if (str_contains($name, '.')) {
                [$section, $key] = explode('.', $name, 2);
                $settings[$section][$key] = $value;
            }
        }

        return $settings;
    }

    public function writable(): bool
    {
        return $this->conf !== '' && (is_file($this->conf) ? is_writable($this->conf) : is_writable(dirname($this->conf)));
    }

    public function set(string $section, string $key, ?string $value): void
    {
        InPlaceGrids::set($this->conf, $this->nick, ["$section.$key" => (string) ($value ?? '')]);
    }
}
