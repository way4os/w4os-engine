<?php

declare(strict_types=1);

namespace W4OS\Engine\Testing;

use W4OS\Engine\Runtime\ReporterInterface;

/**
 * Keeps what the engine says, by kind.
 */
final class ReporterFake implements ReporterInterface
{
    /** @var list<string> */
    public array $info = [];
    /** @var list<string> */
    public array $errors = [];
    /** @var list<string> */
    public array $debug = [];
    /** @var list<string> */
    public array $steps = [];

    public function __construct(public bool $terminal = false) {}

    public function info(string $message): void
    {
        $this->info[] = $message;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function debug(string $message): void
    {
        $this->debug[] = $message;
    }

    public function step(string $text): void
    {
        $this->steps[] = $text;
    }

    public function stepEnd(): void {}

    public function isTerminal(): bool
    {
        return $this->terminal;
    }
}
