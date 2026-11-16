<?php
/**
 * Makes the gettext files of the texts of the websites of a grid (OpenSim\I18n\Catalog): the template (.pot) from
 * Catalog::MESSAGES, and the translations (.po) of each language merged with it by msgmerge (gettext tools). A new text, or one
 * whose English changed, is to translate: msgmerge does not guess it from another (its guesses are other meanings, and the sites
 * get the fuzzy translations); the old one stays in the file, obsolete (#~), for the translator.
 *
 *     composer translations
 *     php dev/translations.php --seed ~/Projects/w4os-wp/languages/w4os-%s.po --seed ~/Projects/opensim-helpers/locales/%s/LC_MESSAGES/messages.po
 *
 * --seed fills the texts not translated yet from other gettext files whose English text is the same (letter case aside, no guess), %s
 * being the language (fr_FR). The first file given wins.
 */

use OpenSim\I18n\Catalog;
use OpenSim\I18n\PoFile;

require dirname(__DIR__) . '/vendor/autoload.php';

$seeds = [];
for ($i = 1; $i < count($argv); $i++) {
    $argv[$i] === '--seed' && isset($argv[$i + 1]) ? $seeds[] = $argv[++$i] : exit("usage: php dev/translations.php [--seed file-%s.po]...\n");
}

is_dir(Catalog::directory()) || mkdir(Catalog::directory(), 0o755, true);
file_put_contents(Catalog::template(), Catalog::pot());
echo 'template: ', Catalog::template(), "\n";

$scratch = sys_get_temp_dir() . '/opensim-translations-' . getmypid();
mkdir($scratch);
$normal = static fn(string $text): string => mb_strtolower(trim($text));

foreach (Catalog::LOCALES as $locale) {
    $file = Catalog::file($locale);
    if (!is_file($file)) {
        file_put_contents($file, "#, fuzzy\n" . PoFile::entry('', '', implode("\n", [
            'Project-Id-Version: ' . Catalog::DOMAIN,
            "Language: $locale",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Plural-Forms: ' . Catalog::PLURAL_FORMS[$locale],
            '',
        ])));
    }
    // The header says how the language picks the form of a number (msgmerge keeps the header of the file): added when it has none
    $content = (string) file_get_contents($file);
    if (!str_contains($content, '"Plural-Forms:')) {
        $rule = '"Plural-Forms: ' . Catalog::PLURAL_FORMS[$locale] . '\\n"';
        file_put_contents($file, (string) preg_replace('/^("Content-Transfer-Encoding:.*")$/m', '$1' . "\n" . addcslashes($rule, '\\$'), $content, 1));
    }

    // The seeds become a compendium in the catalog's own keys, that msgmerge takes for the texts not translated yet
    [$known, $exact] = [[], []];
    foreach (array_reverse($seeds) as $pattern) {
        $seed = sprintf($pattern, $locale);
        if (!is_file($seed)) {
            continue;
        }
        foreach (PoFile::read((string) file_get_contents($seed)) as $entry) {
            if ($entry['translation'] !== '' && !$entry['fuzzy']) {
                $known[$normal($entry['source'])] = $entry['translation'];
                $exact[$entry['source']] = $entry['translation'];
            }
        }
    }
    $compendium = [];
    foreach (Catalog::MESSAGES as $key => $text) {
        // A text in the very same letter case wins over another case; a text that changes with a number is translated here, not
        // seeded: the seeds have one form of it
        $translation = isset(Catalog::SINGULARS[$key]) ? null : $exact[$text] ?? $known[$normal($text)] ?? null;
        $translation === null || $compendium[] = PoFile::entry($key, $text, $translation);
    }
    $options = '--no-fuzzy-matching';
    if ($seeds !== []) {
        file_put_contents("$scratch/$locale.po", "msgid \"\"\nmsgstr \"Content-Type: text/plain; charset=UTF-8\\n\"\n\n" . implode("\n", $compendium));
        $options .= ' --compendium=' . escapeshellarg("$scratch/$locale.po");
    }

    passthru(sprintf('msgmerge --quiet --update --backup=off %s %s %s', $options, escapeshellarg($file), escapeshellarg(Catalog::template())), $status);
    $status === 0 || exit("msgmerge failed on $file\n");
    printf("%s: %d of %d translated\n", $file, count(Catalog::messages($locale)), count(Catalog::MESSAGES));
}

array_map('unlink', glob("$scratch/*") ?: []);
rmdir($scratch);
