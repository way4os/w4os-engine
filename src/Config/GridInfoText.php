<?php

declare(strict_types=1);

namespace W4OS\Engine\Config;

use W4OS\Engine\Provisioning\Slug;

/**
 * The values a Robust sends in its grid info (`/get_grid_info`): ASCII only. OpenSimulator writes them one byte per character since
 * 0.9.2 (osUTF8.AppendASCII), so an accent makes XML the viewers refuse ("broken grid info xml"). A value with other characters is
 * transliterated (Kimbé Rèd becomes Kimbe Red); the sites keep their own titles as they are.
 */
final class GridInfoText
{
    /** Why a value is refused, said with its ASCII form. */
    public const WHY = "OpenSim's grid info cannot send non-ASCII characters";

    /** Whether the grid info can send a value as it is. */
    public static function sendable(string $value): bool
    {
        return preg_match('/[^\x00-\x7F]/', $value) !== 1;
    }

    /** A value the grid info can send: transliterated to ASCII, its punctuation kept. */
    public static function ascii(string $value): string
    {
        return self::sendable($value) ? $value : Slug::ascii($value);
    }

    /**
     * The settings of a Robust config, file by file, that its grid info would send with other characters than ASCII: the keys of
     * its [GridInfoService], and the constants they refer to, where each is written.
     *
     * @param list<string> $files the files of the config, in the order Robust reads them (ConfigReader::files())
     * @return list<array{file:string,section:string,key:string,from:string,to:string}>
     */
    public static function changes(array $files): array
    {
        $read = array_map(static fn(string $file): array => ConfigReader::file($file), $files);
        // The constants its grid info refers to
        $constants = [];
        foreach ($read as $sections) {
            foreach (self::section($sections, 'GridInfoService') as $value) {
                preg_match_all('/\$\{Const\|([^}]+)\}/i', (string) $value, $m);
                array_push($constants, ...array_map('strtolower', $m[1]));
            }
        }
        $changes = [];
        foreach ($files as $i => $file) {
            foreach ($read[$i] as $section => $values) {
                $info = strcasecmp($section, 'GridInfoService') === 0;
                if (!$info && strcasecmp($section, 'Const') !== 0) {
                    continue;
                }
                foreach ($values as $key => $value) {
                    $value = (string) $value;
                    if (($info || in_array(strtolower($key), $constants, true)) && !self::sendable($value)) {
                        $changes[] = ['file' => $file, 'section' => $section, 'key' => $key, 'from' => $value, 'to' => self::ascii($value)];
                    }
                }
            }
        }

        return $changes;
    }

    /**
     * Write the changes in their files, nothing else of them changed.
     *
     * @param list<array{file:string,section:string,key:string,from:string,to:string}> $changes
     */
    public static function apply(array $changes): void
    {
        foreach ($changes as $change) {
            $ini = IniEditor::load($change['file']);
            $ini->set($change['section'], $change['key'], '"' . $change['to'] . '"');
            $ini->save($change['file']);
        }
    }

    /**
     * What a change says: `[GridInfoService] gridname "Kimbé Rèd" -> "Kimbe Red"`.
     *
     * @param array{section:string,key:string,from:string,to:string} $change
     */
    public static function said(array $change): string
    {
        $bare = static fn(string $value): string => trim($value, " \t\"");

        return "[{$change['section']}] {$change['key']} '{$bare($change['from'])}' -> '{$bare($change['to'])}': " . self::WHY;
    }

    /**
     * The keys of a section, whatever the case of its name.
     *
     * @param array<string,array<string,string>> $sections
     * @return array<string,string>
     */
    private static function section(array $sections, string $name): array
    {
        foreach ($sections as $section => $values) {
            if (strcasecmp($section, $name) === 0) {
                return $values;
            }
        }

        return [];
    }
}
