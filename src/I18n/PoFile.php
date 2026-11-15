<?php

declare(strict_types=1);

namespace OpenSim\I18n;

/**
 * Reads and writes the entries of a gettext file (.po, .pot): the context, the text, its translation, and whether it is fuzzy.
 * Plural forms and obsolete entries (#~) are left out: the catalog has none.
 */
final class PoFile
{
    /**
     * The entries of a gettext file, the header (empty msgid) left out.
     *
     * @return list<array{context:string,source:string,translation:string,fuzzy:bool}>
     */
    public static function read(string $content): array
    {
        $entries = [];
        $entry = self::blank();
        $field = null;
        $flush = static function () use (&$entries, &$entry): void {
            if ($entry['source'] !== '') {
                $entries[] = $entry;
            }
            $entry = self::blank();
        };

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                $flush();
                $field = null;
            } elseif (str_starts_with($line, '#,')) {
                $entry['fuzzy'] = $entry['fuzzy'] || in_array('fuzzy', array_map('trim', explode(',', substr($line, 2))), true);
            } elseif (str_starts_with($line, '#')) {
                continue;
            } elseif (preg_match('/^(msgctxt|msgid|msgstr)\s+(".*")$/', $line, $match)) {
                if ($match[1] === 'msgctxt' || ($match[1] === 'msgid' && $field === 'translation')) {
                    // A new entry that no empty line announced
                    $field === 'translation' && $flush();
                }
                $field = ['msgctxt' => 'context', 'msgid' => 'source', 'msgstr' => 'translation'][$match[1]];
                $entry[$field] = self::unquote($match[2]);
            } elseif ($field !== null && str_starts_with($line, '"')) {
                $entry[$field] .= self::unquote($line);
            }
        }
        $flush();

        return $entries;
    }

    /**
     * An entry as gettext writes it.
     *
     * @param list<string> $flags php-format, fuzzy...
     */
    public static function entry(string $context, string $source, string $translation, array $flags = []): string
    {
        return ($flags !== [] ? '#, ' . implode(', ', $flags) . "\n" : '')
            . ($context !== '' ? 'msgctxt ' . self::quote($context) . "\n" : '')
            . 'msgid ' . self::quote($source) . "\n"
            . 'msgstr ' . self::quote($translation) . "\n";
    }

    /** @return array{context:string,source:string,translation:string,fuzzy:bool} */
    private static function blank(): array
    {
        return ['context' => '', 'source' => '', 'translation' => '', 'fuzzy' => false];
    }

    private static function quote(string $text): string
    {
        $escaped = addcslashes($text, "\\\"\t");
        if (!str_contains($text, "\n")) {
            return '"' . $escaped . '"';
        }
        $lines = explode("\n", str_replace("\n", "\\n\n", $escaped));

        return "\"\"\n" . implode("\n", array_map(static fn(string $line): string => '"' . $line . '"', array_filter($lines, static fn($line) => $line !== '')));
    }

    private static function unquote(string $quoted): string
    {
        return stripcslashes(substr($quoted, 1, -1));
    }
}
