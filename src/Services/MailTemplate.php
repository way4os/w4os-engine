<?php

declare(strict_types=1);

namespace W4OS\Engine\Services;

use W4OS\Engine\I18n\Catalog;
use W4OS\Engine\Provisioning\ResourceRegistry;

/**
 * The templates of the mails: for each, a text part (`<name>.txt`: its headers, Subject and From, a blank line, its text) and an HTML
 * part (`<name>.html`), in a folder of its language (`en/`, `fr/`). The kit has them in share/mail; a file of the same name in the
 * config folder of the install (`<etc>/mail`) is read in its place. A template has no logic: `{{name}}` is replaced by its value,
 * as it is in the text part, HTML-escaped in the HTML one, so that any program reads the same file. The comment at its top (lines
 * that start with `#`, an HTML comment) says what it replaces, and is left out.
 *
 *     (new MailTemplate(MailTemplate::roots('/etc/w4os')))->render('offline-message', 'fr', ['grid' => 'Kimbé Red', ...]);
 */
final class MailTemplate
{
    /** The language of the templates of the kit, the one a mail is in when its own has none. */
    public const FALLBACK = 'en';

    /** @param list<string> $roots the folders of templates, the first that has one wins */
    public function __construct(private readonly array $roots) {}

    /**
     * The folders of templates of an install: its config folder, then the kit's.
     *
     * @return list<string>
     */
    public static function roots(string $etcRoot): array
    {
        return [rtrim($etcRoot, '/') . '/mail', ...ResourceRegistry::directories('share/mail')];
    }

    /**
     * A mail from its template, in a language (a locale of a viewer, `fr-fr`, or of the engine, `fr_FR`) or else in English: its
     * subject, the name it comes from, its text, its HTML. Null when no folder has it.
     *
     * @param array<string,string> $values by name
     * @return ?array{subject:string,from:string,text:string,html:string}
     */
    public function render(string $name, string $language, array $values): ?array
    {
        $text = $this->find("$name.txt", $language);
        $html = $this->find("$name.html", $language);
        if ($text === null || $html === null) {
            return null;
        }
        // The text part: its comment, its headers, then its text
        $lines = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($text)));
        while ($lines !== [] && str_starts_with($lines[0], '#')) {
            array_shift($lines);
        }
        $headers = [];
        while ($lines !== [] && preg_match('/^([A-Za-z-]+):\s*(.*)$/', $lines[0], $m) === 1) {
            $headers[strtolower($m[1])] = $m[2];
            array_shift($lines);
        }
        $lines !== [] && trim($lines[0]) === '' && array_shift($lines);
        $raw = static fn(string $template): string => self::fill($template, $values);
        $escaped = array_map(static fn(string $value): string => nl2br(htmlspecialchars($value), false), $values);

        return [
            'subject' => $raw($headers['subject'] ?? ''),
            'from' => $raw($headers['from'] ?? ''),
            'text' => $raw(implode("\n", $lines)),
            'html' => self::fill(ltrim((string) preg_replace('/^\s*<!--.*?-->\s*/s', '', (string) file_get_contents($html))), $escaped),
        ];
    }

    /** The first file of a name in the folders, in the language asked (its locale, then its language alone), else in English. */
    private function find(string $file, string $language): ?string
    {
        $locale = Catalog::locale($language);
        $languages = array_values(array_unique(array_filter([$locale, explode('_', $locale)[0], self::FALLBACK])));
        foreach ($languages as $code) {
            foreach ($this->roots as $root) {
                if (is_file("$root/$code/$file")) {
                    return "$root/$code/$file";
                }
            }
        }

        return null;
    }

    /** @param array<string,string> $values */
    private static function fill(string $template, array $values): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static fn(array $m): string => $values[$m[1]] ?? $m[0], $template);
    }
}
