<?php

declare(strict_types=1);

namespace W4OS\Engine\I18n;

/**
 * Reads and writes the entries of a gettext file (.po, .pot): the context, the text, its translation, and whether it is fuzzy; for
 * a text that changes with a number, its plural (msgid_plural) and its forms (msgstr[0], msgstr[1]...). Obsolete entries (#~) are
 * left out.
 */
final class PoFile
{
    /**
     * The entries of a gettext file, the header (empty msgid) left out. An entry with a plural has its forms, and its first form
     * as its translation.
     *
     * @return list<array{context:string,source:string,translation:string,fuzzy:bool,plural?:string,forms?:list<string>}>
     */
    public static function read(string $content): array
    {
        $entries = [];
        $entry = self::blank();
        $field = null;
        $form = 0;
        $flush = static function () use (&$entries, &$entry): void {
            if ($entry['source'] !== '') {
                if ($entry['plural'] === '') {
                    unset($entry['plural'], $entry['forms']);
                } else {
                    ksort($entry['forms']);
                    $entry['forms'] = array_values($entry['forms']);
                    $entry['translation'] = $entry['forms'][0] ?? '';
                }
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
            } elseif (preg_match('/^(msgctxt|msgid_plural|msgid|msgstr)(?:\[(\d+)\])?\s+(".*")$/', $line, $match)) {
                if (($match[1] === 'msgctxt' || $match[1] === 'msgid') && in_array($field, ['translation', 'forms'], true)) {
                    // A new entry that no empty line announced
                    $flush();
                }
                $field = $match[2] !== '' ? 'forms' : ['msgctxt' => 'context', 'msgid' => 'source', 'msgid_plural' => 'plural', 'msgstr' => 'translation'][$match[1]];
                $form = (int) $match[2];
                $field === 'forms' ? $entry['forms'][$form] = self::unquote($match[3]) : $entry[$field] = self::unquote($match[3]);
            } elseif ($field !== null && str_starts_with($line, '"')) {
                $field === 'forms' ? $entry['forms'][$form] .= self::unquote($line) : $entry[$field] .= self::unquote($line);
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

    /**
     * An entry of a text that changes with a number, as gettext writes it: its singular, its plural, a translation for each form.
     *
     * @param list<string> $forms
     * @param list<string> $flags php-format, fuzzy...
     */
    public static function plural(string $context, string $singular, string $plural, array $forms, array $flags = []): string
    {
        $entry = ($flags !== [] ? '#, ' . implode(', ', $flags) . "\n" : '')
            . ($context !== '' ? 'msgctxt ' . self::quote($context) . "\n" : '')
            . 'msgid ' . self::quote($singular) . "\n"
            . 'msgid_plural ' . self::quote($plural) . "\n";
        foreach ($forms as $i => $form) {
            $entry .= "msgstr[$i] " . self::quote($form) . "\n";
        }

        return $entry;
    }

    /** @return array{context:string,source:string,translation:string,fuzzy:bool,plural:string,forms:array<int,string>} */
    private static function blank(): array
    {
        return ['context' => '', 'source' => '', 'translation' => '', 'fuzzy' => false, 'plural' => '', 'forms' => []];
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
