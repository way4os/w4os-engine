<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning\Import;

use W4OS\Engine\Provisioning\UiInterface;

/**
 * The folders of data of an install that a migration brings into the layout of this one (its assets of FSAssets, its map tiles, its
 * caches): each folder its config names in the old install, to the place the new config gives it. Moved by default (a rename on
 * the same file system, at once; the move tool across file systems), or copied, the old install then left whole. Never measured
 * first: a folder of assets can take hours to count.
 */
final class DataMoves
{
    /** @var list<array{from:string,to:string,setting:string,mode:string,acrossFilesystems:bool}> what it brings, as planned */
    private readonly array $planned;

    /** @var list<string> what keeps it from bringing them, as planned */
    private readonly array $refused;

    /**
     * What it brings and what keeps it from it are read once, as planned: once brought, the old folders are gone.
     *
     * @param list<array{from:string,to:string,setting:string}> $moves
     * @param bool $copy copy instead of moving
     * @param list<string> $unseen places this process cannot see into, so cannot tell whether they hold data
     * @param list<string> $unknown places it cannot tell from the folders that could be them, said
     */
    public function __construct(public readonly array $moves, public readonly bool $copy = false, public readonly array $unseen = [], array $unknown = [])
    {
        $this->planned = array_map(fn(array $move): array => $move + [
            'mode' => $copy ? 'copy' : 'move',
            'acrossFilesystems' => self::device($move['from']) !== self::device($move['to']),
        ], $moves);
        $refused = [...$unknown, ...array_map(static fn(string $place): string => "cannot see whether $place holds data (a folder above it is not readable here): run the import as root", $unseen)];
        foreach ($moves as $move) {
            $from = $move['from'];
            if ($copy) {
                is_readable($from) && is_executable($from) || $refused[] = "cannot read $from to copy it: run the import as root";
            } elseif (!is_writable(dirname($from)) || !is_writable($from)) {
                $refused[] = "cannot move $from (its folder is not writable here): run the import as root, or copy it (copyData)";
            }
        }
        $this->refused = $refused;
    }

    /**
     * The folders to bring: the places of the old install its new config replaces (RobustImporter::sorted(), `place`), that are
     * there; a folder inside another one goes with it.
     *
     * @param list<array{section:string,key:string,value:string,reason:string,place?:bool}> $dropped
     * @param array<string,array<string,string>> $generated the new config, its references replaced
     * @param list<string> $names what a `${Launch|...}` of the old install may be, the one whose folder is there is taken (its launcher
     *        names the instance at its start: its folder of config, its name in [Launch], the name of its config)
     * @param string $base the folder a relative place of the old install is in (its bin, where it runs)
     * @param array<string,string> $required places of the old install whose data must come, by their setting (`[Section] Key`):
     *        one that holds files and is not brought keeps the import from going
     */
    public static function plan(array $dropped, array $generated, array $names, bool $copy = false, string $base = '', array $required = []): self
    {
        $moves = [];
        $unseen = [];
        $unknown = [];
        foreach ($dropped as $setting) {
            if (($setting['place'] ?? false) !== true || $setting['reason'] !== 'replaced') {
                continue;
            }
            $from = self::absolute(self::launched($setting['value'], $names), $base);
            $to = rtrim(trim((string) ($generated[$setting['section']][$setting['key']] ?? ''), " \t\""), '/');
            if (!str_starts_with($from, '/') || $to === '' || str_contains($to, '${') || !str_starts_with($to, '/') || $from === $to) {
                continue;
            }
            // A folder that is not there holds nothing; one this process cannot see is said, never taken for absent (a name of its
            // launcher it cannot find either: the folder before it)
            if (str_contains($from, '${') || !is_dir($from)) {
                // A name of its launcher none of the names known makes a folder: never taken for nothing when folders could be it
                $could = str_contains($from, '${') ? array_values(array_filter(glob((string) preg_replace('/\$\{[^}]+\}/', '*', $from), GLOB_ONLYDIR) ?: [], static fn(string $dir): bool => (new \FilesystemIterator($dir))->valid())) : [];
                if ($could !== []) {
                    $unknown[] = "cannot tell which folder [{$setting['section']}] {$setting['key']} is ($from): " . implode(', ', $could) . ' could be its data, which the import does not move without knowing';
                } elseif (self::hidden(str_contains($from, '${') ? substr($from, 0, (int) strpos($from, '${')) . '_' : $from)) {
                    $unseen[] = $from;
                }
                continue;
            }
            $moves[$from] = ['from' => $from, 'to' => $to, 'setting' => "[{$setting['section']}] {$setting['key']}"];
        }
        ksort($moves);
        $kept = [];
        foreach ($moves as $from => $move) {
            foreach ($kept as $parent) {
                if (str_starts_with($from . '/', $parent['from'] . '/')) {
                    continue 2;
                }
            }
            $kept[] = $move;
        }

        // The data that must come, said when it does not: never left behind in the old install
        foreach ($required as $label => $place) {
            $from = self::absolute(self::launched($place, $names), $base);
            if ($from === '' || str_contains($from, '${') || !is_dir($from) || !(new \FilesystemIterator($from))->valid()) {
                continue;
            }
            $brought = array_filter($kept, static fn(array $move): bool => str_starts_with($from . '/', $move['from'] . '/'));
            [$section, $key] = self::setting($label);
            $to = rtrim(trim((string) ($generated[$section][$key] ?? ''), " \t\""), '/');
            if ($brought === [] && realpath($from) !== realpath($to)) {
                $unknown[] = "$label of the old install is $from, which holds data the import does not bring" . ($to === '' ? '' : " to $to");
            }
        }

        return new self($kept, $copy, array_values(array_unique($unseen)), array_values(array_unique($unknown)));
    }

    /** A place of the old install, absolute: a relative one is in the folder it runs in. */
    private static function absolute(string $place, string $base): string
    {
        $place = rtrim($place, '/');
        if ($place === '' || str_starts_with($place, '/') || str_contains($place, '${') || $base === '') {
            return $place;
        }

        return rtrim($base, '/') . '/' . (string) preg_replace('#^(\./)+#', '', $place);
    }

    /**
     * The section and the key of a setting said `[Section] Key`.
     *
     * @return array{0:string,1:string}
     */
    private static function setting(string $label): array
    {
        return preg_match('/^\[([^\]]+)\]\s*(.+)$/', $label, $m) === 1 ? [$m[1], $m[2]] : ['', $label];
    }

    /** Whether a path cannot be seen by this process: the first of its parents that is there cannot be searched. */
    private static function hidden(string $path): bool
    {
        for ($there = dirname($path); !file_exists($there) && $there !== dirname($there); $there = dirname($there));

        return is_dir($there) && !is_executable($there);
    }

    /** A place of the old install with its `${Launch|...}` replaced by the first of these names that makes it a folder that is there. */
    private static function launched(string $place, array $names): string
    {
        if (!str_contains($place, '${Launch|')) {
            return $place;
        }
        foreach (array_unique(array_filter($names, 'strlen')) as $name) {
            $there = (string) preg_replace('/\$\{Launch\|[^}]+\}/', $name, $place);
            if (is_dir($there)) {
                return $there;
            }
        }

        return $place;
    }

    /**
     * What it brings, folder to folder: moved or copied, and whether across file systems (the move tool copies then, without a
     * rename).
     *
     * @return list<array{from:string,to:string,setting:string,mode:string,acrossFilesystems:bool}>
     */
    public function document(): array
    {
        return $this->planned;
    }

    /**
     * What keeps it from bringing them: a folder this process cannot move (its folder or itself not writable here: the import is
     * run as root, or with copyData), or cannot read to copy.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->refused;
    }

    /**
     * Bring them, each in turn.
     *
     * @return list<array{from:string,to:string}> what was brought, for undo()
     * @throws \RuntimeException when one cannot be: what was brought before stays brought, undo() takes it back
     */
    public function apply(UiInterface $ui, array &$done = []): array
    {
        foreach ($this->moves as $move) {
            ['from' => $from, 'to' => $to] = $move;
            is_dir(dirname($to)) || @mkdir(dirname($to), 0o755, true) || throw new \RuntimeException('cannot make ' . dirname($to));
            // A folder the new grid made already is taken only when it is empty
            if (is_dir($to) && !is_link($to)) {
                (new \FilesystemIterator($to))->valid() && throw new \RuntimeException("$to has files already: $from is not brought there");
                @rmdir($to);
            }
            // The place is free now: a move renames the folder, a copy makes it and takes what the old one holds
            if ($this->copy) {
                (@mkdir($to, 0o755) && self::run('cp -a', "$from/.", "$to/")) || throw new \RuntimeException("cannot copy $from to $to");
            } elseif (!(self::device($from) === self::device(dirname($to)) && @rename($from, $to)) && !self::run('mv', $from, $to)) {
                throw new \RuntimeException("cannot move $from to $to");
            }
            $done[] = ['from' => $from, 'to' => $to];
            $ui->note(sprintf($this->copy ? _('Copied %s to %s') : _('Moved %s to %s'), $from, $to));
        }

        return $done;
    }

    /**
     * Take back what apply() brought, the last first: a move moved back, a copy removed.
     *
     * @param list<array{from:string,to:string}> $done
     */
    public function undo(array $done, UiInterface $ui): void
    {
        foreach (array_reverse($done) as ['from' => $from, 'to' => $to]) {
            if ($this->copy) {
                self::run('rm -rf', $to);
            } elseif (file_exists($from) || !((self::device($to) === self::device(dirname($from)) && @rename($to, $from)) || self::run('mv', $to, $from))) {
                $ui->warn(sprintf(_('Could not move %s back to %s: it is there now'), $to, $from));

                continue;
            }
            $ui->note(sprintf($this->copy ? _('Removed the copy %s') : _('Moved %s back to %s'), $to, $from));
        }
    }

    /** The file system of a path, or of the first of its parents that is there. */
    private static function device(string $path): int
    {
        for ($there = $path; !file_exists($there) && $there !== dirname($there); $there = dirname($there));

        return (int) (@stat($there)['dev'] ?? -1);
    }

    /** Run a tool on paths: whether it did it. */
    private static function run(string $tool, string ...$paths): bool
    {
        exec($tool . ' ' . implode(' ', array_map('escapeshellarg', $paths)) . ' 2>&1', $output, $status);

        return $status === 0;
    }
}
