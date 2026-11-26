<?php

namespace W4OS\Engine\Provisioning;

/**
 * An interface that asks nothing: the answers are the proposals. For the planning of a setup that nobody sees. What is said is kept,
 * by level (note, warn, error), for whoever wants to tell it.
 */
final class NullUi implements UiInterface
{
    /** @var list<array{0:string,1:string}> level, message */
    public array $said = [];

    /** @param bool $live the notes are also told on the error output as they come, as the live line of a client that shows its progress */
    public function __construct(private readonly bool $live = false) {}

    /** Whether the error output is read by a client (a file, a pipe: the setup draws the live line), not a terminal. */
    private static function client(): bool
    {
        return !(function_exists('stream_isatty') && @stream_isatty(STDERR));
    }

    /** The step the notes are part of, `[k/n] Label`, '' for none. */
    private string $stage = '';

    /** The notes that follow are part of a step of the run, said before them on the live line (`[2/3] Start: …`). */
    public function stage(int $k, int $n, string $label): void
    {
        $this->stage = "[$k/$n] $label";
        $this->live && fwrite(STDERR, 'opensim: ' . (self::client() ? '~' : '') . "{$this->stage}\n");
    }

    /** @var list<string> what is for the final report of the setup, not for its log */
    public array $report = [];

    /** A message for the final report (an interface that has none tells it as a note). */
    public static function report(UiInterface $ui, string $message): void
    {
        $ui instanceof self ? $ui->report[] = $message : $ui->note($message);
    }

    public function intro(string $title): void {}

    public function note(string $message): void
    {
        $this->said[] = ['note', $message];
        // On the live line of its client, rewritten in place (a "~" line): a note says where the setup is, never one to keep
        // "~": the live line of a client reading the error output (the setup); a terminal (a postinst) reads a line as it is
        $this->live && fwrite(STDERR, 'opensim: ' . (self::client() ? '~' : '') . ($this->stage !== '' ? "{$this->stage}: " : '') . ltrim($message) . "\n");
    }

    public function entity(string $name): string
    {
        return $name;
    }

    public function warn(string $message): void
    {
        $this->said[] = ['warn', $message];
    }

    public function error(string $message): void
    {
        $this->said[] = ['error', $message];
    }

    public function outro(string $message): void {}

    public function choose(string $label, array $options, ?string $default = null, ?string $hint = null): string
    {
        return $default ?? (string) array_key_first($options);
    }

    public function checklist(string $label, array $options, array $defaults = [], ?string $hint = null): array
    {
        return $defaults;
    }

    public function text(string $label, string $default = '', ?\Closure $validate = null, ?string $hint = null): string
    {
        return $default;
    }

    public function secret(string $label, ?\Closure $validate = null, ?string $hint = null): string
    {
        return '';
    }

    public function form(array $fields, string $title = ''): array
    {
        return [];
    }

    public function confirm(string $label, bool $default = true): bool
    {
        return $default;
    }

    public function spin(\Closure $callback, string $message): mixed
    {
        return $callback();
    }
}
