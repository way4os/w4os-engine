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
    /**
     * @param list<array{from:string,to:string,setting:string}> $moves
     * @param bool $copy copy instead of moving
     */
    public function __construct(public readonly array $moves, public readonly bool $copy = false) {}

    /**
     * The folders to bring: the places of the old install its new config replaces (RobustImporter::sorted(), `place`), that are
     * there; a folder inside another one goes with it.
     *
     * @param list<array{section:string,key:string,value:string,reason:string,place?:bool}> $dropped
     * @param array<string,array<string,string>> $generated the new config, its references replaced
     * @param string $machineName what `${Launch|MachineName}` is for the old install (the name of its config)
     */
    public static function plan(array $dropped, array $generated, string $machineName, bool $copy = false): self
    {
        $moves = [];
        foreach ($dropped as $setting) {
            if (($setting['place'] ?? false) !== true || $setting['reason'] !== 'replaced') {
                continue;
            }
            $from = rtrim(str_replace('${Launch|MachineName}', $machineName, $setting['value']), '/');
            $to = rtrim(trim((string) ($generated[$setting['section']][$setting['key']] ?? ''), " \t\""), '/');
            if (str_contains($from, '${') || !str_starts_with($from, '/') || !is_dir($from) || $to === '' || str_contains($to, '${') || !str_starts_with($to, '/') || $from === $to) {
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

        return new self($kept, $copy);
    }

    /**
     * What it brings, folder to folder: moved or copied, and whether across file systems (the move tool copies then, without a
     * rename).
     *
     * @return list<array{from:string,to:string,setting:string,mode:string,acrossFilesystems:bool}>
     */
    public function document(): array
    {
        return array_map(fn(array $move): array => $move + [
            'mode' => $this->copy ? 'copy' : 'move',
            'acrossFilesystems' => self::device($move['from']) !== self::device($move['to']),
        ], $this->moves);
    }

    /**
     * What keeps it from bringing them: a folder this process cannot move (its folder or itself not writable here: the import is
     * run as root, or with copyData), or cannot read to copy.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        foreach ($this->moves as $move) {
            $from = $move['from'];
            if ($this->copy) {
                is_readable($from) && is_executable($from) || $problems[] = "cannot read $from to copy it: run the import as root";
            } elseif (!is_writable(dirname($from)) || !is_writable($from)) {
                $problems[] = "cannot move $from (its folder is not writable here): run the import as root, or copy it (copyData)";
            }
        }

        return $problems;
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
