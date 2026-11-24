<?php

declare(strict_types=1);

namespace W4OS\Engine\Runtime;

use W4OS\Engine\Console\ScreenInterface;
use W4OS\Engine\Controllers\AbstractController;
use W4OS\Engine\Exceptions\InstanceFailed;
use W4OS\Engine\Controllers\OpenSimController;

/**
 * Starts an instance: checks that it can run, maps its ports, links the modules, launches it detached, then waits until it
 * is ready (the services of a Robust loaded, the regions of a simulator up) or has failed, and says which.
 */
final class StartService
{
    /** Seconds an instance has to come up. */
    private const TIMEOUT = 120;

    /**
     * What a simulator says, again and again, when the owner its estate is given is not an account of its grid: OpenSim asks for
     * it without end, its log growing, and no region ever comes up.
     */
    private const OWNER_LOOP = '/you must create the estate owner account first at the grid/';

    /** What a simulator says when a region could not be made: it stops loading, and runs on with no region up. */
    private const ABORTED = '/^.*(?:aborting startup|Registration of region with grid failed).*$/m';

    /**
     * What a simulator asks on its console that nothing answers: the owner of an estate that has none. Under the service no one reads
     * its console; with a remote console, the questions of a first start (QUESTIONS) are not answered either.
     */
    private const PROMPTS = ['has no owner set', 'Estate owner first name', 'Estate owner last name'];

    /** What a region says once it is up: registered with its grid, then ready (scripts started). */
    public const REGION_UP = '/\[GRID SERVICE\]: Region (.+?) \([0-9a-f-]{36}.*registered at|\[RegionReady\]: Region "(.+?)" is ready/';

    /** The questions of a first start, which a region fully described does not ask: each is answered with its default. */
    private const QUESTIONS = [
        'RegionUUID',
        'Internal IP address',
        'External host name',
        'Do you wish to join region .* to an existing estate',
        'Name of estate to join',
    ];

    /** When the instance waited for was launched: its delays count from it. */
    private ?float $from = null;

    public function __construct(private readonly Environment $environment) {}

    /**
     * Start an instance and wait for it to be ready (launch(), then await()).
     *
     * @throws \W4OS\Engine\Exceptions\InstanceFailed
     */
    public function start(AbstractController $instance): Outcome
    {
        $launched = $this->launch($instance);

        return $launched instanceof Launched ? $this->await($launched) : $launched;
    }

    /**
     * Start instances one after another, in their order (a Robust before its simulators): each waited for until it is started, or
     * failed, or its delay is over, before the next is launched. A failure is kept and the next one started all the same.
     *
     * @param list<AbstractController> $instances
     * @return array<string,Outcome|\W4OS\Engine\Exceptions\InstanceFailed> what became of each, by its name
     */
    public function all(array $instances): array
    {
        $results = [];
        foreach ($instances as $instance) {
            try {
                $results[$instance->name()] = $this->start($instance);
            } catch (InstanceFailed $failed) {
                $results[$instance->name()] = $failed;
            }
        }

        return $results;
    }

    /** Wait for an instance launched to be ready, its delays counted from its launch. */
    public function await(Launched $launched): Outcome
    {
        $this->from = $launched->at;
        try {
            return $this->wait($launched->instance, $launched->base, $launched->log, $launched->mark, $launched->output, $launched->remote);
        } finally {
            $this->from = null;
        }
    }

    /**
     * Check that an instance can run, then launch it, detached, without waiting for it to be ready: Running when it runs already.
     *
     * @throws \W4OS\Engine\Exceptions\InstanceFailed
     */
    public function launch(AbstractController $instance): Outcome|Launched
    {
        $env = $this->environment;
        $name = $instance->name();
        $core = $instance->core();

        // Only starting needs a core: stop and status work without one
        if ($core === null || !$core->exists()) {
            $this->fail($name, 'could not find OpenSim.exe');
        }
        if (!is_file($instance->ini())) {
            $this->fail($name, 'no ini file found for ' . $name);
        }

        // Dead sessions are dropped so that they are not mistaken for a running one
        $env->consoles->wipeSessions();
        if ($instance->isRunning()) {
            $env->reporter->debug("$name is already running");

            return Outcome::Running;
        }
        // Its port taken while it does not run: another program listens on it, the simulator of an older install most often
        $port = $instance instanceof OpenSimController ? $instance->httpPort() : null;
        if ($port !== null && in_array($port, $env->processes->listening(), true)) {
            $this->fail(
                $name,
                "$name cannot start: its port $port is taken by another program (the simulator of an older install still running?): stop it first",
                reason: 'port-taken',
            );
        }
        $env->reporter->info("starting $name");

        $base = $instance->executableBase();
        $runtime = $core->runtime($base);
        $executable = $core->executable($base);
        if (!is_dir($core->bin())) {
            $this->fail($name, "$name Cannot cd to {$core->bin()}");
        }
        if (!is_file($core->bin() . "/$executable")) {
            $this->fail($name, "Executable $executable not found");
        }
        // Fail early with a clear message if the runtime is missing, rather than letting the instance crash on launch
        if (($problem = $env->probe->problem($core, $executable)) !== null) {
            $this->fail($name, $problem);
        }
        // A region stops at once without libgdiplus (dynamic textures)
        if (
            $base === 'OpenSim' &&
            $runtime === 'dotnet' &&
            PHP_OS_FAMILY === 'Linux' &&
            !$env->probe->hasLibrary('gdiplus')
        ) {
            $this->fail(
                $name,
                "$name cannot start: the native library libgdiplus is missing. Install it with: sudo apt install libgdiplus (or sudo opensim install-dotnet)",
            );
        }
        // A simulator needs a region: without one it waits for the answers of a first start, on a console nobody reads
        if ($instance instanceof OpenSimController && $base === 'OpenSim' && !$instance->hasRegion()) {
            $this->fail(
                $name,
                "$name has no region to load (all disabled, or none yet): add one first, with opensim setup",
            );
        }

        // The master of its grid, when it has one, is made again if the core or the grid changed: it is the first the config reads
        $master = $instance instanceof OpenSimController ? $instance->master($core->bin()) : null;

        // Port mapping, when the grid asks for it: ports mapped and regions address set before the instance starts
        $env->portMapping?->start($name, $instance->ini(), $instance->ports());

        // Regions load the modules enabled for the install from the addins folder of their registry
        $registry = $instance->launchSetting('RegistryLocation');
        if ($base === 'OpenSim' && $registry !== '') {
            // The folder the modules keep their own config in: one of the instance, never the core (relative, it is in the core)
            $configs = $instance->launchSetting('ConfigDirectory');
            $configs === '' || str_starts_with($configs, "/") || $env->reporter->info(
                "$name: ConfigDirectory of [Startup] is \"$configs\", a folder of the core: the add-ins cannot write their config there (opensim enable or the master give one of the simulator)",
            );
            // A module enabled that its config does not set up stays off: it would stop the simulator
            $modules = $env->layout->enabledModules();
            foreach (ModuleLinker::unconfigured($modules, \W4OS\Engine\Config\ConfigReader::expand($instance->settings())) as $module => $missing) {
                $grid = $instance->grid()?->nick() ?? 'GRID';
                $env->reporter->error("$name: the module $module is installed but not configured (" . implode(', ', $missing) . "): it stays off."
                    . (strtolower($module) === 'gloebit' ? " Set them (opensim settings $grid economy --set gloebitKey=KEY --set gloebitSecret=SECRET)," : ' Set them,')
                    . " then opensim restart $name");
                $modules = array_values(array_diff($modules, [$module]));
            }
            foreach (
                ModuleLinker::sync(
                    "$registry/addins",
                    $core,
                    $modules,
                    $env->layout->modulesRoot(),
                    str_starts_with($configs, '/') ? $configs : null,
                )
                as $note
            ) {
                $env->reporter->debug($note);
            }
        }

        $logConfig = $this->logConfig($instance, $base, $core->bin());
        if ($logConfig === false) {
            $this->fail($name, "could not make the log config of $name from {$core->bin()}/$base.exe.config");
        }
        $logPath = $instance->logFile()->path();
        if ($logConfig !== '') {
            // The log file is the one of the log config
            $logPath = LogConfig::logFile($logConfig) ?? $logPath;
            if (is_dir(dirname($logPath)) && !is_file($logPath)) {
                touch($logPath);
            }
        }
        // An instance that cannot write its files hangs or stops without a word: what it would not write is said before
        $account = $env->layout->systemUser() ?: (function_exists('posix_geteuid') ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '') : '');
        $refused = InstanceWrites::unwritable(
            [dirname($logPath), ...InstanceWrites::folders(\W4OS\Engine\Config\ConfigReader::expand($instance->settings()), $core->bin())],
            $account,
        );
        if ($refused !== []) {
            $quoted = static fn(array $paths): string => implode(' ', array_map('escapeshellarg', $paths));
            // Only the folders of the suite are given to its account: the ones of an install that exists are never changed
            $layout = $env->layout;
            $ours = array_filter(
                array_keys($refused),
                static fn(string $path): bool => array_filter(
                    [$layout->etcRoot(), $layout->varRoot(), $layout->dataRoot(), $layout->logsRoot(), $layout->cacheRoot()],
                    static fn(string $root): bool => $root !== '' && ($path === $root || str_starts_with($path, rtrim($root, '/') . '/')),
                ) !== [],
            );
            $theirs = array_values(array_filter($ours, static fn(string $path): bool => $refused[$path] === $account));
            $others = array_values(array_diff($ours, $theirs));
            $fix = implode(' && ', array_filter([$others === [] ? '' : "sudo chown -R $account: " . $quoted($others), $theirs === [] ? '' : 'sudo chmod -R u+w ' . $quoted($theirs)]));
            $this->fail(
                $name,
                "$name cannot start: $account, the account it runs as (SystemUser), cannot write in " . implode(' ', array_keys($refused)) . ' (owned by ' . implode(', ', array_unique($refused)) . ')'
                . ($fix !== '' ? ". Fix it with: $fix" : ''),
                reason: 'unwritable',
            );
        }
        $log = new LogFile($logPath);
        // Where this start begins in the log, before the instance writes to it
        $mark = $log->mark();

        $remote = $instance->remoteConsole();
        $native = $runtime === 'dotnet' ? $core->nativeLibraries($this->nativeRoot()) : '';
        $command = [
            $runtime,
            ...array_values(array_filter(explode(' ', $core->runtimeOptions($base)))),
            $core->bin() . "/$executable",
            '-inifile=' . $instance->ini(),
        ];
        if ($master !== null) {
            $command[] = '-inimaster=' . $master;
        }
        // What it reads after the rest of its config (ConfigOverrides)
        if ($base === 'OpenSim' && $instance->lastFiles() !== []) {
            $command[] = '-inidirectory=' . \W4OS\Engine\Config\ConfigOverrides::directory($instance->ini());
        }
        if ($base === 'Robust') {
            $prompt = $instance->launchSetting('consoleprompt', 'ConsolePrompt') ?: "Robust ($name) ";
            array_push($command, "-logfile=$logPath", '-prompt', $prompt);
        }
        if ($logConfig !== '') {
            $command[] = "-logconfig=$logConfig";
        }
        if ($remote !== null) {
            array_push($command, '-console', 'rest');
        }
        $env->reporter->debug(implode(' ', $command));

        // What it prints before its log has anything, next to its log: made by the account it runs as, kept after the start
        $output = dirname($logPath) . "/$name.start.log";
        $spec = new LaunchSpec(
            $name,
            $core->bin(),
            $command,
            $native !== ''
                ? [
                    'LD_LIBRARY_PATH' =>
                        $native . ((string) getenv('LD_LIBRARY_PATH') !== '' ? ':' . getenv('LD_LIBRARY_PATH') : ''),
                ]
                : [],
            $output,
            $remote === null,
            $base === 'OpenSim',
        );
        if (!$env->launcher->launch($spec)) {
            $this->fail($name, "$name failed to start: the launcher could not run it");
        }
        // Confirm it came up: an immediate exit (a missing or incompatible runtime) would leave us waiting for nothing
        $env->clock->sleep(1);
        if (!$instance->isRunning()) {
            $this->tell($output, 0);

            $this->fail($name, "$name failed to start: the process exited immediately", "Its output: $output");
        }

        return new Launched($instance, $base, $log, $mark, $output, $remote !== null, $env->clock->time() - 1);
    }

    /** Wait for the instance to be ready, and tell how it went. */
    private function wait(
        AbstractController $instance,
        string $base,
        LogFile $log,
        int $mark,
        string $output,
        bool $remote,
    ): Outcome {
        $env = $this->environment;
        $name = $instance->name();
        $failed = [];

        if ($base === 'Robust') {
            // Every service its config lists must report it loaded, in the log
            $services = RobustServices::of($instance);
            if ($services === []) {
                $env->reporter->debug("no service listed in {$instance->ini()}, only checking the instance stays up");
                $env->clock->sleep(3);
                $code = $instance->isRunning() ? 0 : 3;
            } else {
                [$code, $failed] = $this->watchServices($instance, $log, $mark, $services);
            }
        } else {
            $progress = new StartProgress($env, $instance, $log, $mark, $output);
            // 5 when it asks for the owner of its estate without end, 6 when it gave up a region, 7 when it waits at its console for
            // an answer nothing gives (its first output says it, never its log)
            $prompts = '/' . implode('|', [...array_map(static fn(string $p): string => preg_quote($p, '/'), self::PROMPTS), ...($remote ? self::QUESTIONS : [])]) . '/';
            $stuck = static function () use ($log, $mark, $output, $prompts): int {
                $since = $log->since($mark);
                $said = is_file($output) ? (string) file_get_contents($output) : '';

                return preg_match(self::OWNER_LOOP, $since) === 1 ? 5 : (preg_match(self::ABORTED, $since) === 1 ? 6 : (preg_match($prompts, $said) === 1 ? 7 : 0));
            };
            $code = $remote
                ? $this->waitLog($instance, $log, $mark, $progress, $stuck)
                : $this->waitConsole($instance, $progress, $stuck);
            // Its regions loaded, each is made, registered with its grid and started: up only once all are
            if ($code === 0) {
                $code = $this->waitRegions($instance, $progress, $stuck, $remote);
            }
            $progress->finish();
        }
        $env->reporter->stepEnd();

        switch ($code) {
            case 0:
                $env->reporter->info("$name started");

                return Outcome::Started;
            case 4:
                $this->fail(
                    $name,
                    "$name is up but these services failed to load: " . implode(' ', $failed),
                    "Its log: {$log->path()}",
                );
            case 3:
                $this->tell($output, 20);

                $this->fail($name, "$name exited while starting" . $this->errors($log, $mark), "Its log: {$log->path()}, its output: $output");
            case 6:
                $this->abandon($instance);
                preg_match(self::ABORTED, $log->since($mark), $line);
                $this->fail(
                    $name,
                    "$name stopped: a region could not be made, and it would run on without it: " . trim((string) preg_replace('/^.*?\[STARTUP\]: */', '', $line[0] ?? '')),
                    "Its log: {$log->path()}",
                    'region-failed',
                );
            case 7:
                $this->abandon($instance);
                $said = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', is_file($output) ? (string) file_get_contents($output) : '');
                // What it asked: the last lines of its output
                $asked = implode(' ', array_slice(array_values(array_filter(array_map('trim', explode("\n", $said)), 'strlen')), -2));
                $this->fail(
                    $name,
                    "$name stopped: it waited at its console for an answer nothing gives under the service ("
                    . ($asked !== '' ? $asked : 'a question of a first start') . '). Its estate could not be read, or has no owner: check its estate service and its grid, or name its owner in [Estates] (DefaultEstateOwnerName) of ' . $instance->ini()
                    . $this->errors($log, $mark),
                    "Its log: {$log->path()}, its output: $output",
                    'console-prompt',
                );
            case 5:
                $this->abandon($instance);
                $owner = $instance instanceof OpenSimController ? (string) $instance->estateOwner() : '';
                $this->fail(
                    $name,
                    "$name stopped: the owner of its estate" . ($owner !== '' ? " ($owner)" : '') . ' is not an account of its grid, and OpenSim asks for it without end. Create that account on the grid, or give the estate the name of an account it has (DefaultEstateOwnerName in [Estates] of ' . $instance->ini() . "), then: opensim start $name",
                    "Its log: {$log->path()}",
                    'estate-owner-missing',
                );
            default:
                $errors = $this->errors($log, $mark);
                if ($errors !== '') {
                    $this->fail(
                        $name,
                        "$name runs, but its regions are not up after " . self::TIMEOUT . " seconds" . $errors,
                        "Its log: {$log->path()}",
                        'regions-not-up',
                    );
                }
                $env->reporter->info("$name still starting: its regions are not up yet, check opensim status later");

                return Outcome::Starting;
        }
    }

    /**
     * Wait for all the regions of a simulator to be up: in its log (or its screen), else, when its log does not say (no DEBUG
     * line), as its grid knows them, or by its own status when it has no grid. 0 when they are, 3 when it exited, 5 or 6 when it
     * is stuck (see wait()), 1 at the end of the delay.
     */
    private function waitRegions(AbstractController $instance, StartProgress $progress, \Closure $stuck, bool $remote): int
    {
        $env = $this->environment;
        $console = $remote ? null : $env->consoles->screen($instance->name());
        for ($tick = 0, $deadline = ($this->from ?? $this->environment->clock->time()) + 2 * self::TIMEOUT; $this->environment->clock->time() < $deadline; $tick++) {
            if (($code = $stuck()) !== 0) {
                return $code;
            }
            $progress->show();
            if ($progress->up($console?->screenText() ?? '') || ($tick % 10 === 9 && $progress->blind() && $this->answers($instance, $progress->names()))) {
                // The physics and the scripts start once the regions are up: one that cannot run them ends within seconds
                $env->clock->sleep(3);

                return ($code = $stuck()) !== 0 ? $code : ($instance->isRunning() ? 0 : 3);
            }
            if (!$instance->isRunning()) {
                return 3;
            }
            $env->clock->sleep(0.5);
        }

        return 1;
    }

    /**
     * Whether its regions are up as its grid knows them (online, registered from its port), or, without a grid, whether it answers
     * at /simstatus/.
     *
     * @param list<string> $regions
     */
    private function answers(AbstractController $instance, array $regions): bool
    {
        $port = $instance instanceof OpenSimController ? (int) $instance->httpPort() : 0;
        $grid = (string) (\W4OS\Engine\Config\ConfigReader::expand($instance->settings())['GridService']['GridServerURI'] ?? '');
        if ($grid === '') {
            $body = ($this->environment->http ?? static fn(string $url): ?string => \W4OS\Engine\Network\Http::get($url, 2))("http://127.0.0.1:$port/simstatus/");

            return trim((string) $body) === 'OK';
        }
        $host = (string) parse_url(trim($grid, '"'), PHP_URL_HOST);
        $robust = (int) parse_url(trim($grid, '"'), PHP_URL_PORT);
        foreach ($regions as $region) {
            $record = \W4OS\Engine\Provisioning\RobustGrid::region($host, $robust, $region);
            if (!is_array($record) || ($record['flags'] & \W4OS\Engine\Provisioning\RobustGrid::ONLINE) === 0 || (int) parse_url($record['server'], PHP_URL_PORT) !== $port) {
                return false;
            }
        }

        return $regions !== [];
    }

    /** The last errors of its log since this start, as the end of a message: empty when there is none. */
    private function errors(LogFile $log, int $mark): string
    {
        preg_match_all('/^\S+ \S+ (?:ERROR|FATAL) +(.*)$/m', $log->since($mark), $found);
        $errors = array_slice(array_unique(array_map(static fn(string $e): string => mb_substr(trim($e), 0, 200), $found[1])), -3);

        return $errors === [] ? '' : ". Its last errors:\n  " . implode("\n  ", $errors);
    }

    /**
     * Follow the log of a Robust while it loads its connectors, showing each one as it is reported. The log, not the console: a
     * detached screen cuts the lines at 80 columns.
     *
     * @param list<string> $services the class names
     * @return array{0:int,1:list<string>} 0 when all are loaded, 4 when some failed to load (named in the list, once the others
     *                                     have reported), 3 when the instance exited, 1 when the delay is over
     */
    private function watchServices(AbstractController $instance, LogFile $log, int $mark, array $services): array
    {
        $env = $this->environment;
        $pending = array_fill_keys($services, true);
        $total = count($services);
        $reported = 0;
        $failed = [];
        $quiet = 0;
        $since = 0;
        for ($tick = 0, $deadline = ($this->from ?? $this->environment->clock->time()) + self::TIMEOUT; $this->environment->clock->time() < $deadline; $tick++) {
            $content = $log->since($mark);
            $before = $reported;
            foreach (array_keys($pending) as $class) {
                if (preg_match('/\[SERVER\]: ' . preg_quote($class, '/') . ' loaded successfully/', $content)) {
                    $took = ($tick - $since) / 2;
                    $env->reporter->step("  $class " . ++$reported . "/$total" . ($took >= 5 ? ' (' . (int) $took . 's)' : ''));
                    $since = $tick;
                    unset($pending[$class]);
                } elseif (preg_match('/Failed to load .*:' . preg_quote($class, '/') . '([^A-Za-z0-9]|$)/', $content)) {
                    $env->reporter->stepEnd();
                    $env->reporter->error("  $class: failed to load (" . ++$reported . "/$total)");
                    $failed[] = $class;
                    unset($pending[$class]);
                }
            }
            if ($pending === []) {
                return [$failed === [] ? 0 : 4, $failed];
            }
            if (!$instance->isRunning()) {
                return [3, $failed];
            }
            // Nothing new for a while: say what it is on (the last line of its log), so a long wait is not taken for a freeze
            $quiet = $reported > $before ? 0 : $quiet + 1;
            if ($quiet > 0 && $quiet % 2 === 0 && $tick - $since >= 20 && $env->reporter instanceof PendingReporterInterface) {
                $lines = array_values(array_filter(array_map('trim', explode("\n", $content)), 'strlen'));
                $last = (string) preg_replace('/^.*?\[[^\]]*\]: */', '', (string) end($lines));
                $env->reporter->pending('  ' . mb_substr($last, 0, 100) . ' (' . (int) (($tick - $since) / 2) . 's...)');
            }
            $env->clock->sleep(0.5);
        }

        return [1, $failed];
    }

    /**
     * Wait for the regions of a simulator with a remote console to be loaded, in its log: 0, 3 when it exited, 5 or 6 when it is
     * stuck (see wait()), 1 at the end of the delay.
     */
    private function waitLog(AbstractController $instance, LogFile $log, int $mark, StartProgress $progress, ?\Closure $stuck = null): int
    {
        for ($tick = 0, $deadline = ($this->from ?? $this->environment->clock->time()) + self::TIMEOUT; $this->environment->clock->time() < $deadline; $tick++) {
            if ($stuck !== null && ($code = $stuck()) !== 0) {
                return $code;
            }
            if (preg_match('/RegionReady|LOAD REGIONS PLUGIN\]: Done/', $log->since($mark))) {
                return 0;
            }
            $progress->show();
            if (!$instance->isRunning()) {
                return 3;
            }
            $this->environment->clock->sleep(0.5);
        }

        return 1;
    }

    /**
     * Wait for the regions of a simulator in a screen session. The questions of a first start (region, estate) are answered with
     * their default, each once, as they come: a region fully described asks none. A question blocks the loading, so the region is
     * ready only after them.
     */
    private function waitConsole(AbstractController $instance, StartProgress $progress, ?\Closure $stuck = null): int
    {
        $console = $this->environment->consoles->screen($instance->name());
        $answered = [];
        while (true) {
            $pattern = 'RegionReady|neighbours.*region.*is up|LOAD REGIONS PLUGIN\]: Done';
            foreach (self::QUESTIONS as $i => $question) {
                $pattern .= isset($answered[$i]) ? '' : "|$question";
            }
            [$code, $seen] = $this->waitScreen($instance, $console, $pattern, $progress, $stuck);
            if ($code !== 0) {
                return $code;
            }
            $asked = false;
            foreach (self::QUESTIONS as $i => $question) {
                if (!isset($answered[$i]) && preg_match("/$question/", $seen)) {
                    $console->send('');
                    $answered[$i] = true;
                    $asked = true;

                    break;
                }
            }
            if (!$asked) {
                return 0;
            }
        }
    }

    /**
     * Wait for a pattern in the screen of an instance.
     *
     * @return array{0:int,1:string} 0 and the lines that match, 3 when the session is gone, 5 or 6 when it is stuck (see wait()),
     *                                1 at the end of the delay
     */
    private function waitScreen(
        AbstractController $instance,
        ScreenInterface $console,
        string $pattern,
        StartProgress $progress,
        ?\Closure $stuck = null,
    ): array {
        for ($tick = 0, $deadline = ($this->from ?? $this->environment->clock->time()) + self::TIMEOUT; $this->environment->clock->time() < $deadline; $tick++) {
            if ($stuck !== null && ($code = $stuck()) !== 0) {
                return [$code, ''];
            }
            $matches = array_filter(
                explode("\n", $console->screenText()),
                static fn(string $line): bool => (bool) preg_match("/$pattern/", $line),
            );
            if ($matches !== []) {
                return [0, implode("\n", $matches)];
            }
            $progress->show();
            // Stop waiting if the session is gone: the instance has exited
            if (!$console->exists()) {
                return [3, ''];
            }
            $this->environment->clock->sleep(0.5);
        }

        return [1, ''];
    }

    /**
     * The log config of an instance: the one it names, else the one made with the grid, else one made from the model of the core.
     *
     * @return string|false the file, empty when there is none to give, false when it could not be made
     */
    private function logConfig(AbstractController $instance, string $base, string $bin): string|false
    {
        $layout = $this->environment->layout;
        $config = $instance->launchSetting('logconfig', 'LogConfig');
        if ($config === '' && is_file($instance->launchSetting('EtcDirectory') . "/$base.exe.config")) {
            $config = $instance->launchSetting('EtcDirectory') . "/$base.exe.config";
        }
        if ($config === '') {
            $data = $instance->launchSetting('datadirectory') ?: $layout->dataRoot();
            $config = "$data/" . basename($instance->ini(), '.ini') . '.LogConfig';
        }
        if (!is_file($config)) {
            $model = "$bin/$base.exe.config";
            if (!is_file($model)) {
                return false;
            }
            $text = LogConfig::render($model, $base, $instance->logsDirectory(), $instance->name());
            if (!is_dir(dirname($config)) || file_put_contents($config, $text) === false) {
                return false;
            }
            $this->environment->reporter->debug("created LogConfig file $config from core model $model");
        }

        return $config;
    }

    /** Where the native libraries of the cores are linked. */
    private function nativeRoot(): string
    {
        return $this->environment->layout->stateRoot() . '/native';
    }

    /** Tell what the process printed: the whole output, or its last lines. */
    private function tell(string $output, int $last): void
    {
        $text = is_file($output) ? trim((string) file_get_contents($output)) : '';
        if ($text === '') {
            return;
        }
        $lines = explode("\n", $text);
        $this->environment->reporter->error(implode("\n", $last > 0 ? array_slice($lines, -$last) : $lines));
    }

    /** An instance that cannot go on, ended at once: its console waits for an answer, it would not take a shutdown. */
    private function abandon(AbstractController $instance): void
    {
        $process = $instance->process();
        if ($process === null) {
            return;
        }
        $processes = $this->environment->processes;
        $processes->signal($process->pid, 'TERM');
        for ($try = 0; $try < 20 && $processes->alive($process->pid); $try++) {
            $this->environment->clock->sleep(0.5);
        }
        $processes->alive($process->pid) && $processes->signal($process->pid, 'KILL');
    }

    private function fail(string $name, string $message, string $debug = '', string $reason = 'instance-failed'): never
    {
        $this->environment->reporter->stepEnd();
        $this->environment->reporter->error($message);
        $debug !== '' && $this->environment->reporter->debug($debug);

        throw new InstanceFailed($name, $message, $reason);
    }
}
