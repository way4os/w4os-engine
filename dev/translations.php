<?php
/**
 * Makes the gettext files of the texts of the websites of a grid (OpenSim\I18n\Catalog): the template (.pot) from
 * Catalog::MESSAGES, and the translations (.po) of each language merged with it by msgmerge (gettext tools). A text whose English
 * changed keeps its translation, marked fuzzy for a translator to check; a text removed becomes obsolete (#~).
 *
 *     composer translations
 *     php dev/translations.php --seed ~/Projects/w4os/languages/w4os-%s.po --seed ~/Projects/opensim-helpers/locales/%s/LC_MESSAGES/messages.po
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
            '',
        ])));
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
        // A text in the very same letter case wins over another case
        $translation = $exact[$text] ?? $known[$normal($text)] ?? null;
        $translation === null || $compendium[] = PoFile::entry($key, $text, $translation);
    }
    $options = '';
    if ($seeds !== []) {
        // The guesses of msgmerge from the seeds are other texts: only the same ones count
        file_put_contents("$scratch/$locale.po", "msgid \"\"\nmsgstr \"Content-Type: text/plain; charset=UTF-8\\n\"\n\n" . implode("\n", $compendium));
        $options = '--no-fuzzy-matching --compendium=' . escapeshellarg("$scratch/$locale.po");
    }

    passthru(sprintf('msgmerge --quiet --update --backup=off %s %s %s', $options, escapeshellarg($file), escapeshellarg(Catalog::template())), $status);
    $status === 0 || exit("msgmerge failed on $file\n");
    printf("%s: %d of %d translated\n", $file, count(Catalog::messages($locale)), count(Catalog::MESSAGES));
}

array_map('unlink', glob("$scratch/*") ?: []);
rmdir($scratch);
