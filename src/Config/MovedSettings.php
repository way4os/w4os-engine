<?php

declare(strict_types=1);

namespace W4OS\Engine\Config;

/**
 * The settings OpenSimulator still reads at an older place than the one the kit writes them at: an old config that has one there
 * means the same as the kit's place. The older place wins, as OpenSimulator reads it first.
 *
 *     MovedSettings::place('Startup', 'EconomyModule');   // ['Economy', 'economymodule']
 */
final class MovedSettings
{
    /** The older place (section/key in lower case) => the kit's. */
    private const MOVED = [
        // SampleMoneyModule and Gloebit read [Startup] first, then [Economy]
        'startup/economymodule' => ['Economy', 'economymodule'],
    ];

    /**
     * The kit's place of a setting at an older one, null when it has not moved.
     *
     * @return ?array{0:string,1:string}
     */
    public static function place(string $section, string $key): ?array
    {
        return self::MOVED[strtolower("$section/$key")] ?? null;
    }

    /**
     * A config read with its settings of an older place at the kit's (its sections as they are written, whatever their case).
     *
     * @param array<string,array<string,mixed>> $ini by section then key
     * @return array<string,array<string,mixed>>
     */
    public static function normalize(array $ini): array
    {
        foreach ($ini as $section => $values) {
            foreach (is_array($values) ? $values : [] as $key => $value) {
                $to = self::place((string) $section, (string) $key);
                if ($to === null) {
                    continue;
                }
                unset($ini[$section][$key]);
                $target = self::sectionOf($ini, $to[0]);
                // The same key at the kit's place, whatever its case, is replaced: the older place wins
                foreach (array_keys($ini[$target] ?? []) as $existing) {
                    strcasecmp((string) $existing, $to[1]) === 0 && ($to[1] = (string) $existing);
                }
                $ini[$target][$to[1]] = $value;
            }
        }

        return $ini;
    }

    /** The name a section has in a config, whatever its case, else the one given. */
    private static function sectionOf(array $ini, string $section): string
    {
        foreach (array_keys($ini) as $name) {
            if (strcasecmp((string) $name, $section) === 0) {
                return (string) $name;
            }
        }

        return $section;
    }
}
