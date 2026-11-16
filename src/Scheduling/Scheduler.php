<?php

declare(strict_types=1);

namespace OpenSim\Scheduling;

use OpenSim\Runtime\ClockInterface;
use OpenSim\Runtime\Environment;

/**
 * The tasks that have to run now and then, and who runs them when they are due. The consumers say what to schedule; this is the
 * mechanics, the same whoever asks:
 *
 *     $scheduler = Scheduler::for($environment);
 *     $scheduler->add(new Task('search-parse', 60, fn() => parse(), 'Read the snapshots of the hosts'));
 *
 *     $scheduler->runDue();   // a real cron, through the command line:  * * * * *  php cron.php
 *     $scheduler->tick();     // a pseudo cron, at the end of a request, once the answer is sent
 *
 * Whoever comes first runs a task that is due. A task never runs twice at once (a lock), and one that fails is told and waits for
 * its next turn: it is not tried again in a loop. The last run and the next of each task are kept in the state folder of the
 * install, for whoever shows them (the health page).
 */
final class Scheduler
{
    /** @var array<string,Task> */
    private array $tasks = [];

    /** @var array<string,true> the folders already said in the log not to be writable, once per process */
    private static array $warned = [];

    /**
     * @param string $directory where the state and the locks of the tasks are kept
     * @param int $tickEvery seconds between two looks at the tasks by tick()
     */
    public function __construct(
        private readonly string $directory,
        private readonly ClockInterface $clock,
        private readonly int $tickEvery = 60,
    ) {}

    /** The scheduler of an install: the state of its tasks is in its folder of the tasks (Layout::tasksRoot(), `TasksRoot`). */
    public static function for(Environment $environment): self
    {
        return new self($environment->layout->tasksRoot(), $environment->clock);
    }

    /** The folder of the state and the locks of the tasks. */
    public function directory(): string
    {
        return $this->directory;
    }

    /** Whether the state of the tasks can be kept: the folder is writable, or the closest folder that is there lets it be made. */
    public function writable(): bool
    {
        $folder = $this->directory;
        while (!file_exists($folder)) {
            $parent = dirname($folder);
            if ($parent === $folder) {
                return false;
            }
            $folder = $parent;
        }

        // What is there must be a folder: a file in the way keeps it from being made
        return is_dir($folder) && is_writable($folder);
    }

    public function add(Task $task): self
    {
        $this->tasks[$task->id()] = $task;

        return $this;
    }

    /**
     * The tasks, with what is known of their last run and their next.
     *
     * @return list<array{name:string,label:string,every:int,lastRun:?int,status:?string,message:string,duration:?float,next:?int,due:bool,running:bool}>
     */
    public function status(): array
    {
        $now = (int) $this->clock->time();
        $status = [];
        foreach ($this->tasks as $task) {
            $state = $this->read($task);
            $last = $state['started'] ?? null;
            $status[] = [
                'id' => $task->id(),
                'name' => $task->name,
                'scope' => $task->scope,
                'label' => $task->label,
                'every' => $task->every,
                'lastRun' => $last,
                'status' => $state['status'] ?? null,
                'message' => (string) ($state['message'] ?? ''),
                'duration' => isset($state['duration']) ? (float) $state['duration'] : null,
                'next' => $last !== null ? $last + $task->every : null,
                'due' => $last === null || $now >= $last + $task->every,
                'running' => $this->isRunning($task->id()),
            ];
        }

        return $status;
    }

    /**
     * Run what is due. A task that is running (started by someone else) is left alone.
     *
     * @return list<TaskResult> one for each task that was run, failed or found running
     */
    public function runDue(): array
    {
        $results = [];
        foreach ($this->tasks as $task) {
            $result = $this->run($task->id());
            $result->status === TaskResult::NOT_DUE || ($results[] = $result);
        }

        return $results;
    }

    /**
     * The pseudo cron: looks at the tasks at most once every `$tickEvery` seconds, whoever asks. Called once the answer to a
     * request is sent, so that nobody waits for a task.
     *
     * @return list<TaskResult>
     */
    public function tick(): array
    {
        $stamp = "{$this->directory}/tick";
        if (!$this->ensureDirectory()) {
            return [];
        }
        $now = (int) $this->clock->time();
        $before = is_file($stamp) ? (int) @filemtime($stamp) : 0;
        if ($before > 0 && $now - $before < $this->tickEvery) {
            return [];
        }
        // Taken before it is done: a second request does not look at the same time
        @touch($stamp, $now);

        return $this->runDue();
    }

    /**
     * Run a task when it is due, or when it is forced.
     */
    public function run(string $name, bool $force = false): TaskResult
    {
        $task = $this->task($name);
        $name = $task->id();
        $now = (int) $this->clock->time();
        $state = $this->read($task);
        if (!$force && isset($state['started']) && $now < (int) $state['started'] + $task->every) {
            return new TaskResult($name, TaskResult::NOT_DUE);
        }
        if (!$this->ensureDirectory()) {
            return new TaskResult(
                $name,
                TaskResult::FAILED,
                "cannot write in {$this->directory}: the task is not run, nothing would keep it from running again at once",
            );
        }

        $lock = @fopen("{$this->directory}/$name.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $lock !== false && fclose($lock);

            return new TaskResult($name, TaskResult::RUNNING);
        }
        try {
            // Looked at again with the lock: another process may have run it since
            $state = $this->read($task);
            if (!$force && isset($state['started']) && $now < (int) $state['started'] + $task->every) {
                return new TaskResult($name, TaskResult::NOT_DUE);
            }
            $started = microtime(true);
            // The run is kept before it is done: a task that fails, or kills the process, waits for its next turn
            $this->write($name, ['started' => $now, 'status' => 'running', 'message' => '', 'duration' => 0.0]);
            try {
                $message = (string) (($task->run)() ?? '');
                $status = TaskResult::RAN;
            } catch (\Throwable $e) {
                $message = $e->getMessage() !== '' ? $e->getMessage() : $e::class;
                $status = TaskResult::FAILED;
            }
            $duration = round(microtime(true) - $started, 3);
            $this->write($name, [
                'started' => $now,
                'status' => $status,
                'message' => $message,
                'duration' => $duration,
            ]);

            return new TaskResult($name, $status, $message, $duration);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Whether someone is running the task now. */
    private function isRunning(string $name): bool
    {
        $file = "{$this->directory}/$name.lock";
        if (!is_file($file)) {
            return false;
        }
        $lock = @fopen($file, 'c');
        if ($lock === false) {
            return false;
        }
        $free = flock($lock, LOCK_EX | LOCK_NB);
        $free && flock($lock, LOCK_UN);
        fclose($lock);

        return !$free;
    }

    /** @return array<string,mixed> */
    private function read(Task $task): array
    {
        $file = "{$this->directory}/{$task->id()}.json";
        // The state kept under the name alone, before the tasks of several grids: read until the task of a grid has its own
        if ($task->scope !== '' && !is_file($file)) {
            $file = "{$this->directory}/{$task->name}.json";
        }
        $text = is_file($file) ? @file_get_contents($file) : false;
        $state = $text !== false ? json_decode($text, true) : null;

        return is_array($state) ? $state : [];
    }

    /**
     * A task by its id (`search-parse.demo`), or by its name when no other task has it.
     *
     * @throws \InvalidArgumentException for a task that is not one, a name several tasks have (one for each grid)
     */
    private function task(string $id): Task
    {
        if (isset($this->tasks[$id])) {
            return $this->tasks[$id];
        }
        $named = array_values(array_filter($this->tasks, static fn(Task $task): bool => $task->name === $id));
        count($named) > 1 &&
            throw new \InvalidArgumentException(
                "several tasks are called '$id': " . implode(', ', array_map(static fn(Task $task): string => $task->id(), $named)),
            );

        return $named[0] ?? throw new \InvalidArgumentException("no task called '$id'");
    }

    /** @param array<string,mixed> $state */
    private function write(string $name, array $state): void
    {
        $file = "{$this->directory}/$name.json";
        $temporary = $file . '.' . getmypid();
        if (@file_put_contents($temporary, json_encode($state, JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($temporary, $file);
        }
    }

    private function ensureDirectory(): bool
    {
        $ready = is_dir($this->directory)
            ? is_writable($this->directory)
            : $this->writable() && (@mkdir($this->directory, 0o775, true) || is_dir($this->directory));
        if (!$ready && !isset(self::$warned[$this->directory])) {
            // Said once: no task runs until the folder is writable (TasksRoot for a site the web server runs)
            self::$warned[$this->directory] = true;
            $user = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? (string) posix_geteuid()) : get_current_user();
            error_log(
                "opensim: warning: the folder of the tasks {$this->directory} cannot be written by $user: no task runs (TasksRoot sets another)",
            );
        }

        return $ready;
    }
}
