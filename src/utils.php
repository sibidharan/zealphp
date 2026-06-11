<?php
namespace ZealPHP;

use Exception;
use ZealPHP\App;
use ZealPHP\StringUtils;
use OpenSwoole\Process;
use OpenSwoole\Coroutine as co;
use Throwable;

/**
 * Read a value from `$_GET` by key.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function get($key, $default = null)
{
    return $_GET[$key] ?? $default;
}

/**
 * Read a boolean environment variable using ZealPHP's truthiness convention.
 *
 * Returns `$default` when the variable is unset or empty. Otherwise, returns
 * `false` when the value is one of `'0'`, `'false'`, `'off'`, `'no'`, or
 * `'none'` (case-insensitive); returns `true` for everything else.
 */
function env_flag(string $name, bool $default): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    $value = strtolower(trim((string) $value));
    return !in_array($value, ['0', 'false', 'off', 'no', 'none'], true);
}

/**
 * Whether benchmark mode is active (`ZEALPHP_BENCH_MODE` env flag).
 *
 * Bench mode disables all logging to avoid I/O overhead skewing results.
 * The result is memoised after the first call.
 */
function bench_mode_enabled(): bool
{
    /** @var bool|null $enabled */
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    $enabled = env_flag('ZEALPHP_BENCH_MODE', false);
    return $enabled;
}

/**
 * Absolute base URL for the ZealPHP OSS site.
 *
 * Resolution order:
 *   1. `ZEALPHP_SITE_URL` env var.
 *   2. `ZEALPHP_SITE_HOST` env var (scheme `https://` prepended if absent).
 *   3. Hard-coded fallback `https://php.zeal.ninja`.
 *
 * When `$path` is non-empty it is appended with a single `/` separator.
 * The result is memoised after the first call.
 */
function site_url(string $path = ''): string
{
    /** @var string|null $base */
    static $base = null;
    if ($base === null) {
        $configured = getenv('ZEALPHP_SITE_URL');
        if ($configured === false || trim((string) $configured) === '') {
            $configured = getenv('ZEALPHP_SITE_HOST');
        }
        if ($configured === false || trim((string) $configured) === '') {
            $configured = 'https://php.zeal.ninja';
        }

        $configured = trim((string) $configured);
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $configured)) {
            $configured = 'https://' . ltrim($configured, '/');
        }
        $base = rtrim($configured, '/');
    }

    $path = trim($path);
    if ($path === '') {
        return $base;
    }

    return $base . '/' . ltrim($path, '/');
}

/**
 * Return just the host component of `site_url()`.
 *
 * Falls back to the full `site_url()` string when `parse_url()` cannot extract
 * a host (e.g. a bare domain without scheme).
 */
function site_host(): string
{
    $url = site_url();
    $parts = parse_url($url);
    if (is_array($parts) && !empty($parts['host'])) {
        return $parts['host'];
    }

    return $url;
}

/**
 * Whether async (coroutine-channel-backed) logging is enabled.
 *
 * Controlled by the `ZEALPHP_LOG_ASYNC` env flag (default `true`).
 * The result is memoised after the first call.
 */
function async_logging_enabled(): bool
{
    /** @var bool|null $enabled */
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    $enabled = env_flag('ZEALPHP_LOG_ASYNC', true);
    return $enabled;
}

/**
 * Ordered list of directories ZealPHP will try for its logs + PID files, most
 * preferred first. Pure (no I/O, no memoization) so it is unit-testable; the
 * actual pick — the first candidate that is writable or creatable — happens in
 * `resolve_log_dir()`.
 *
 * Order:
 *   1. `$ZEALPHP_LOG_DIR` — explicit override, when set.
 *   2. `/tmp/zealphp` — the shared default, kept first for BC (used whenever the
 *      current user can create/write it: single-user box, root, or fresh box).
 *   3. Per-user fallbacks for the collision case where `/tmp/zealphp` already
 *      exists owned by ANOTHER user (e.g. root started a server there first), so
 *      this user cannot write it: `$XDG_RUNTIME_DIR/zealphp`, then a uid/user-
 *      suffixed temp dir (`sys_get_temp_dir()/zealphp-<uid>`). These keep us off a
 *      non-writable `/tmp/zealphp` without polluting the project tree, and resolve
 *      deterministically so `start` and `stop`/`status` agree on the same dir.
 *   4. Project-tree last resorts (`./tmp/zealphp`, `./logs/zealphp`).
 *
 * @return list<string>
 */
function zealphp_log_dir_candidates(): array
{
    $candidates = [];

    $envDir = getenv('ZEALPHP_LOG_DIR');
    if ($envDir !== false && trim((string) $envDir) !== '') {
        $candidates[] = trim((string) $envDir);
    }

    $candidates[] = '/tmp/zealphp';

    $uid = function_exists('posix_getuid')
        ? (string) posix_getuid()
        : trim((string) (getenv('USER') ?: getenv('LOGNAME') ?: ''));
    if ($uid !== '') {
        $xdg = getenv('XDG_RUNTIME_DIR');
        if ($xdg !== false && trim((string) $xdg) !== '') {
            $candidates[] = rtrim(trim((string) $xdg), '/') . '/zealphp';
        }
        $safeUid = preg_replace('/[^A-Za-z0-9_-]/', '', $uid);
        if (is_string($safeUid) && $safeUid !== '') {
            $candidates[] = rtrim(sys_get_temp_dir(), '/') . '/zealphp-' . $safeUid;
        }
    }

    $cwd = getcwd();
    if ($cwd !== false) {
        $candidates[] = $cwd . '/tmp/zealphp';
        $candidates[] = $cwd . '/logs/zealphp';
    }

    return array_values(array_unique($candidates));
}

/**
 * Resolve the first writable log directory from `zealphp_log_dir_candidates()`.
 *
 * Creates the directory (with `0775` permissions, recursively) if it does not
 * yet exist. Memoises the result so the filesystem is only probed once per
 * worker lifetime. Returns `null` when no candidate is writable or creatable.
 */
function resolve_log_dir(): ?string
{
    /** @var string|null $resolved */
    static $resolved = null;
    /** @var bool $checked */
    static $checked = false;
    if ($checked) {
        return $resolved;
    }
    $checked = true;

    foreach (zealphp_log_dir_candidates() as $candidate) {
        if (!is_dir($candidate)) {
            @mkdir($candidate, 0775, true);
        }
        if (is_dir($candidate) && is_writable($candidate)) {
            $resolved = rtrim($candidate, '/');
            return $resolved;
        }
    }

    $resolved = null;
    return $resolved;
}

/**
 * The container's CPU allowance from its cgroup CPU quota, or `null` when there
 * is no quota (unlimited, or not running under a limited cgroup).
 *
 * Reads cgroup v2 (`/sys/fs/cgroup/cpu.max` = `"quota period"`) first, then v1
 * (`cpu.cfs_quota_us` / `cpu.cfs_period_us`). Returns quota ÷ period as a float
 * (e.g. `6.0` for `"600000 100000"`); `null` for `"max"` / unset / unreadable.
 */
function cgroup_cpu_quota(): ?float
{
    $v2 = @file_get_contents('/sys/fs/cgroup/cpu.max');
    if (is_string($v2) && trim($v2) !== '') {
        $parts = preg_split('/\s+/', trim($v2));
        if (is_array($parts) && isset($parts[0], $parts[1])) {
            if ($parts[0] === 'max') {
                return null; // unlimited
            }
            if (ctype_digit($parts[0]) && ctype_digit($parts[1]) && (int) $parts[1] > 0) {
                return (int) $parts[0] / (int) $parts[1];
            }
        }
        return null;
    }
    $q = @file_get_contents('/sys/fs/cgroup/cpu/cpu.cfs_quota_us');
    $p = @file_get_contents('/sys/fs/cgroup/cpu/cpu.cfs_period_us');
    if (is_string($q) && is_string($p)) {
        $qi = (int) trim($q);
        $pi = (int) trim($p);
        if ($qi > 0 && $pi > 0) {
            return $qi / $pi;
        }
    }
    return null;
}

/**
 * Default HTTP worker count for a bare `php app.php` (no `ZEALPHP_WORKERS`),
 * capped to the container's cgroup CPU quota.
 *
 * OpenSwoole's own default when `worker_num` is unset is `swoole_cpu_num()` =
 * the HOST cpu count — so a bare boot in a CPU-limited Docker container
 * over-spawns (e.g. 24 workers on a 4–6 CPU container) and gets OOM-killed.
 * Returns `max(1, min($preferred, floor(cgroup_quota)))`; when there is no
 * cgroup quota it returns the conservative `$preferred` (NOT the host count).
 *
 * @param int $preferred desired worker count when unconstrained (default `4`)
 */
function default_worker_count(int $preferred = 4): int
{
    $preferred = max(1, $preferred);
    $quota = cgroup_cpu_quota();
    if ($quota !== null && $quota >= 1.0) {
        return max(1, min($preferred, (int) floor($quota)));
    }
    return $preferred;
}

/**
 * Whether debug logging is enabled.
 *
 * Always `false` in bench mode. Controlled by `ZEALPHP_DEBUG_LOG` or the legacy
 * `ZEALPHP_ELOG` env var (default `true` when neither is set). The result is
 * memoised after the first call.
 */
function debug_logging_enabled(): bool
{
    /** @var bool|null $enabled */
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    if (bench_mode_enabled()) {
        $enabled = false;
        return $enabled;
    }

    $value = getenv('ZEALPHP_DEBUG_LOG');
    if ($value === false || $value === '') {
        $value = getenv('ZEALPHP_ELOG');
    }

    if ($value === false || $value === '') {
        $enabled = true;
        return $enabled;
    }

    $value = strtolower(trim((string) $value));
    $enabled = !in_array($value, ['0', 'false', 'off', 'no', 'none'], true);
    return $enabled;
}

/**
 * Whether access logging is enabled.
 *
 * Always `false` in bench mode. Controlled by the `ZEALPHP_ACCESS_LOG` env flag
 * (default `true`). The result is memoised after the first call.
 */
function access_logging_enabled(): bool
{
    /** @var bool|null $enabled */
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    if (bench_mode_enabled()) {
        $enabled = false;
        return $enabled;
    }

    $enabled = env_flag('ZEALPHP_ACCESS_LOG', true);
    return $enabled;
}

/**
 * Resolve the absolute path for a named log file.
 *
 * `$kind` is one of `'access'`, `'zlog'`, or `'debug'`. Checks (in order):
 *   1. Kind-specific env var (`ZEALPHP_ACCESS_LOG_FILE`, `ZEALPHP_ZLOG_FILE`,
 *      `ZEALPHP_DEBUG_LOG_FILE`).
 *   2. `ZEALPHP_LOG_FILE` (generic override, all kinds).
 *   3. `resolve_log_dir()` + kind-specific filename (`access.log`, `zlog.log`,
 *      `debug.log`).
 *
 * Returns `null` when no writable log directory can be found.
 * Results are memoised per kind.
 */
function log_file_for(string $kind): ?string
{
    /** @var array<string, string|null> $cache */
    static $cache = [];
    if (array_key_exists($kind, $cache)) {
        return $cache[$kind];
    }

    $path = null;
    if ($kind === 'access') {
        $path = getenv('ZEALPHP_ACCESS_LOG_FILE');
    } elseif ($kind === 'zlog') {
        $path = getenv('ZEALPHP_ZLOG_FILE');
    } elseif ($kind === 'debug') {
        $path = getenv('ZEALPHP_DEBUG_LOG_FILE');
    }

    if ($path === false || $path === null || $path === '') {
        $path = getenv('ZEALPHP_LOG_FILE');
    }

    if ($path === false || trim((string) $path) === '') {
        $dir = resolve_log_dir();
        if ($dir === null) {
            return null;
        }
        if ($kind === 'access') {
            $path = $dir . '/access.log';
        } elseif ($kind === 'zlog') {
            $path = $dir . '/zlog.log';
        } else {
            $path = $dir . '/debug.log';
        }
    }

    $path = trim((string) $path);
    $cache[$kind] = $path === '' ? null : $path;
    return $cache[$kind];
}

/**
 * Return (or create) the async `Channel`-backed log sink for `$path`.
 *
 * When async logging is enabled and a coroutine scheduler is running, this
 * function returns an `OpenSwoole\Coroutine\Channel` that a background `go()`
 * consumer drains to the file at `$path`. Callers push log lines onto the
 * channel; the consumer does the actual `fwrite()` without blocking the request.
 *
 * Returns `null` when async logging is disabled, no scheduler is running, or
 * `go()` is unavailable — callers fall back to a synchronous `fopen`/`fwrite`.
 *
 * The consumer goroutine falls back to `php://stderr` when the file cannot be
 * opened (avoids silent log loss). Results are memoised per path.
 */
function log_sink_for(string $path): ?\OpenSwoole\Coroutine\Channel
{
    /** @var array<string, \OpenSwoole\Coroutine\Channel> $sinks */
    static $sinks = [];
    /** @var array<string, bool> $started */
    static $started = [];

    if (isset($sinks[$path])) {
        return $sinks[$path];
    }

    if (!async_logging_enabled() || co::getCid() < 0 || !function_exists('go')) {
        return null;
    }

    // The async sink spawns a detached `go()` consumer that loops on
    // Channel::pop() until the channel closes. It only runs when async logging
    // is enabled inside a live coroutine scheduler — never reached by the unit
    // suite (no scheduler) and deliberately disabled in every coverage server
    // pass (ZEALPHP_LOG_ASYNC=0), since exercising the consumer risks a
    // pop()-loop deadlock at coverage-dump time. Verified by live integration
    // use, not measured as a coverage unit.
    // @codeCoverageIgnoreStart
    $queue = new \OpenSwoole\Coroutine\Channel(8192);
    $sinks[$path] = $queue;

    if (!isset($started[$path])) {
        $started[$path] = true;
        go(static function () use ($queue, $path): void {
            if (!str_contains($path, '://')) {
                $dir = dirname($path);
                if ($dir !== '.' && !is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
            }

            $handle = @fopen($path, 'ab');
            if ($handle === false) {
                while (($message = $queue->pop()) !== false) {
                    // Last-resort sink — write to stderr directly, NOT error_log():
                    // error_log() is uopz-overridden to route into log_write(), so
                    // calling it here would recurse. stderr is where error_log(0)
                    // lands under the CLI SAPI anyway. pop() returns mixed.
                    if (is_scalar($message)) {
                        @file_put_contents('php://stderr', (string)$message);
                    }
                }
                return;
            }

            stream_set_write_buffer($handle, 0);
            while (($message = $queue->pop()) !== false) {
                if ($message === '') {
                    continue;
                }
                // @phpstan-ignore-next-line — OpenSwoole\Coroutine\Channel::pop() returns mixed
                fwrite($handle, (string)$message);
            }
            fclose($handle);
        });
    }

    return $queue;
    // @codeCoverageIgnoreEnd
}

/**
 * Write a log line to the appropriate sink for `$kind`.
 *
 * Pushes to the async `Channel` sink when one is available (non-blocking,
 * coroutine-safe). Falls through to a synchronous `fopen`/`fwrite` when called
 * outside a coroutine or when the channel push fails. Writes to `php://stderr`
 * as a last resort when no log file can be resolved.
 *
 * Note: uses `php://stderr` directly (not `error_log()`) in fallback paths
 * because `error_log()` is uopz-overridden to route into this very function —
 * calling it would recurse infinitely.
 */
function log_write(string $message, string $kind = 'debug'): void
{
    $path = log_file_for($kind);
    if ($path === null) {
        // stderr, not error_log() — see the consumer's fallback note above
        // (error_log() is overridden to route back into log_write()).
        @file_put_contents('php://stderr', $message);
        return;
    }

    $sink = log_sink_for($path);
    // Channel::push requires a coroutine context — guard via Coroutine::getCid
    // before pushing. Falls through to the synchronous fopen+fwrite path below
    // when called outside a coroutine (e.g. from a unit test harness, a CLI
    // script, or any sync-mode boot code).
    if ($sink instanceof \OpenSwoole\Coroutine\Channel && \OpenSwoole\Coroutine::getCid() >= 0) {
        if ($sink->push($message, 0.001)) {
            return;
        }
    }

    if (!str_contains($path, '://')) {
        $dir = dirname($path);
        if ($dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    $handle = @fopen($path, 'ab');
    if ($handle === false) {
        // stderr, not error_log() (overridden — would recurse into log_write()).
        @file_put_contents('php://stderr', $message);
        return;
    }
    stream_set_write_buffer($handle, 0);
    fwrite($handle, $message);
    fclose($handle);
}

/**
 * Run a closure in a throwaway child process that HAS the coroutine scheduler,
 * even though the caller does not. This is the escape hatch for parallel I/O
 * from a coroutine-scheduler-OFF worker — i.e. `superglobals(true)` /
 * `enableCoroutine(false)` mode (the Symfony/FPM-style lifecycle, where running
 * coroutines in the main worker would race process-wide `$_GET`/`$_POST`/`$_SESSION`
 * and shared framework singletons).
 *
 * The child is spawned with OpenSwoole's coroutine runtime enabled, so inside
 * `$taskLogic` you can `go()` + `Channel` + hooked I/O (`curl`, `file_get_contents`,
 * PDO over the network, `Co\System::exec`, ...) and they run concurrently. The
 * call BLOCKS until the child finishes (when `$wait` is `true`) and returns whatever
 * the child echoed — so serialise structured results (`json_encode` in the child,
 * `json_decode` in the caller).
 *
 * Cost & caveats:
 *  - One `proc`-style fork per call (~ms). Worth it when a request needs N
 *    genuinely-parallel slow I/O calls; not worth it for a single call or
 *    CPU-bound work.
 *  - The child is a FRESH process — it does NOT inherit your framework
 *    container, DB connection pool, or request state. Pass everything it needs
 *    as captured variables; do raw I/O inside (it can't reach Symfony services
 *    / Doctrine's managed connection).
 *  - Refused when `superglobals(false)` (coroutine mode) — there you already
 *    have a scheduler, so just `go()` directly.
 *
 * Example (3 parallel HTTP fetches from a sequential worker):
 *
 * ```php
 * $json = coprocess(function () {
 *     $chan = new \OpenSwoole\Coroutine\Channel(3);
 *     foreach (['a','b','c'] as $svc) {
 *         go(function () use ($svc, $chan) {
 *             $chan->push([$svc => file_get_contents("https://api/$svc")]);
 *         });
 *     }
 *     $out = [];
 *     for ($i = 0; $i < 3; $i++) { $out += $chan->pop(); }
 *     echo json_encode($out);            // returned to the caller as a string
 * });
 * $data = json_decode($json, true);
 * ```
 *
 * @param callable $taskLogic The logic to run in the coroutine-enabled child.
 *                            Receives the `OpenSwoole\Process` as its argument.
 * @param bool $wait Whether to block until the child completes. Default `true`.
 *
 * @return mixed The child's echoed output (string) when `$wait` is `true`.
 */
function coprocess($taskLogic, $wait = true)
{
    if(App::$superglobals == false){
        throw new \Exception("Superglobals are disabled which enables coroutines, cannot use coprocess inside coroutine, use coroutines directly.");
    }
    // The body forks a child OpenSwoole\Process with its own coroutine runtime.
    // It only runs in superglobals(true)+enableCoroutine(false) mode and cannot
    // be exercised in-process by the coverage harness (the coroutine pass — the
    // assertion gate — is exactly the mode where coprocess() is refused above;
    // the mixed/cgi exercise passes never call it). Verified by live use.
    // @codeCoverageIgnoreStart
    $worker = new Process(function (Process $worker) use ($taskLogic) {
        try{
            ob_start();
            $taskLogic($worker);
            $data = ob_get_clean();
            $worker->write(empty($data) ? 'EOF' : (string)$data);
            $worker->exit();
        } catch (\Throwable $e) {
            $data = ob_get_clean();
            if(!empty($data)){
                $worker->write((string)$data);
            } else {
                $worker->write('EOF');
            }
            if($e instanceof \OpenSwoole\ExitException){
                $worker->exit(0);
            } else {
                $worker->exit(1);
            }
        }
    }, false, SOCK_STREAM, true);

    // Start the worker
    $worker->start();
    Process::wait($wait);
    $data = $worker->read(65535);
    if($data == 'EOF'){
        $data   = '';
    }
    return $data;
    // @codeCoverageIgnoreEnd
}

/**
 * Thin alias for `coprocess()` — same fork-a-coroutine-child semantics, so
 * it shares `coprocess()`'s untestability (forks a child process; only valid in
 * the `superglobals(true)`+`enableCoroutine(false)` mode the coverage gate excludes).
 *
 * @param callable $taskLogic
 * @return mixed
 * @codeCoverageIgnore
 */
function coproc($taskLogic){
    return coprocess($taskLogic);
}


/**
 * Produce a Java-style exception trace string.
 *
 * @param \Throwable        $e
 * @param array<int,string>|null $seen array passed to recursive calls to accumulate trace lines already seen;
 *                                     leave as `null` when calling this function
 * @return string of array strings, one entry per trace line
*/
function jTraceEx($e, $seen=null): string
{
    $starter = $seen ? 'Caused by: ' : '';
    $result = array();
    if (!$seen) {
        $seen = array();
    }
    $trace  = $e->getTrace();
    $prev   = $e->getPrevious();
    $result[] = sprintf('%s%s: %s', $starter, get_class($e), $e->getMessage());
    $file = $e->getFile();
    $line = $e->getLine();
    while (true) {
        $current = "$file:$line";
        if (in_array($current, $seen, true)) {
            $result[] = sprintf(' ... %d more', count($trace)+1);
            break;
        }
        $result[] = sprintf(
            ' at %s%s%s(%s%s%s)',
            count($trace) && array_key_exists('class', $trace[0]) ? str_replace('\\', '.', $trace[0]['class']) : '',
            count($trace) && array_key_exists('class', $trace[0]) ? '.' : '',
            count($trace) ? str_replace('\\', '.', $trace[0]['function']) : '(main)',
            $line === null ? $file : str_replace(App::$cwd, '', $file),
            $line === null ? '' : ':',
            $line === null ? '' : $line
        );
        $seen[] = "$file:$line";
        if (!count($trace)) {
            break;
        }
        $file = array_key_exists('file', $trace[0]) ? $trace[0]['file'] : 'anonymous';
        $line = array_key_exists('file', $trace[0]) && array_key_exists('line', $trace[0]) && $trace[0]['line'] ? $trace[0]['line'] : null;
        array_shift($trace);
    }
    $result = join("\n", $result);
    if ($prev) {
        $result  .= "\n" . jTraceEx($prev, $seen);
    }

    return $result;
}

/**
 * Return the `basename` (without `.php` extension) of the calling API file.
 *
 * Used inside `api/` handlers to obtain the endpoint name for logging without
 * hard-coding the filename. Reads one frame from `debug_backtrace()`.
 */
function zapi(): string {
    $bt = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 1);
    $caller = array_shift($bt);
    return basename($caller['file'] ?? '(unknown)', '.php');
}

/**
 * Log a debug message with caller location.
 *
 * Writes to the debug log (`debug.log`) when `debug_logging_enabled()` is
 * `true`. Messages tagged `'wordpress'` are silently suppressed to avoid noise
 * from WordPress's verbose internal logging.
 *
 * @param string $message The message to log.
 * @param string $tag     The tag to associate with the log message. Default `"*"`.
 * @param int    $limit   Stack depth passed to `debug_backtrace()`. Default `1`.
 */
function elog($message, $tag = "*", $limit = 1): void {
    if (!debug_logging_enabled()) {
        return;
    }
    if($tag == "wordpress"){
        return;
    }
    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);
    $caller = $bt[0];
    $date = date('d-m-Y H:i:s') . substr((string)microtime(), 1, 6);
    $relative_path = str_replace(App::$cwd, '', $caller['file'] ?? '(unknown)');
    $callerLine = $caller['line'] ?? 0;
    log_write("┌[$tag] $date $relative_path:$callerLine\n└❯ $message \n");
}

/**
 * Log a structured message to `zlog.log` with request context.
 *
 * Writes caller file/line, request URL, request ID, and render timer alongside
 * the message. Valid `$tag` values: `'system'`, `'fatal'`, `'error'`,
 * `'warning'`, `'info'`, `'debug'`. Messages with unknown tags are silently
 * dropped. No-op when `debug_logging_enabled()` is `false`.
 *
 * @param mixed  $log           The message or data to log (arrays/objects are JSON-encoded).
 * @param string $tag           The tag to categorize the log entry. Default `"system"`.
 * @param mixed  $filter        Optional URI substring filter; skips logging when the
 *                              current `REQUEST_URI` does not contain this string.
 * @param bool   $invert_filter Whether to invert the filter logic. Default `false`.
 */
function zlog($log, $tag = "system", $filter = null, $invert_filter = false): void
{
    /** @var array<string, int> $validTags */
    static $validTags = ['system' => 1, 'fatal' => 1, 'error' => 1, 'warning' => 1, 'info' => 1, 'debug' => 1];

    if (!debug_logging_enabled()) {
        return;
    }
    // @phpstan-ignore-next-line — $filter is documented mixed; coerced to string at boundary
    if ($filter != null and !StringUtils::str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), (string)$filter)) {
        return;
    }
    if ($filter != null and $invert_filter) {
        return;
    }

    if (!isset($validTags[$tag])) {
        return;
    }

    if (!isset($_SERVER['REQUEST_URI'])) {
        $_SERVER['REQUEST_URI'] = 'cli';
    }

    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    $caller = $bt[0];
    $g = RequestContext::instance();
    $date = date('Y-m-d H:i:s');
    if (is_object($log)) {
        $log = purify_array($log);
    }
    if (is_array($log)) {
        $log = json_encode($log);
    }
    // @phpstan-ignore-next-line — session map is array<string, mixed>; UNIQUE_REQUEST_ID coerced to string at boundary
    $unique_req_id = (string)($g->memo['UNIQUE_REQUEST_ID'] ?? '');
    $request_uri = $g->server['REQUEST_URI'] ?? '';
    $callerFile = $caller['file'] ?? '(unknown)';
    $callerLine = $caller['line'] ?? 0;
    // @phpstan-ignore-next-line — $log is documented mixed (string|array|object); coerced via json_encode above
    $msg = indent((string)$log);
    log_write(
        "[*] #{$tag} [{$date}] Request ID: {$unique_req_id}\n" .
            "    URL: {$request_uri}\n" .
            "    Caller: {$callerFile}:{$callerLine}\n" .
            "    Timer: " . get_current_render_time() . " sec\n" .
            "    Message:\n" . $msg . "\n\n",
        'zlog'
    );
}


/**
 * Read a site configuration value by key.
 *
 * Decodes the global `$__site_config` JSON string and returns the value for
 * `$key`, or `null` when the key is absent or the config is not valid JSON.
 *
 * @param string $key
 * @return mixed
 */
function get_config($key)
{
    global $__site_config;
    $configStr = is_scalar($__site_config) ? (string)$__site_config : '';
    $array = json_decode($configStr, true);
    if (is_array($array) && isset($array[$key])) {
        return $array[$key];
    } else {
        return null;
    }
}

/**
 * Get the current render time since request received and started processing.
 *
 * This function calculates and returns the current render time.
 *
 * @return float The current render time in seconds.
 */
function get_current_render_time()
{
    $finish = microtime(true);
    $start = RequestContext::instance()->memo['__start_time'] ?? 0.0;
    $startFloat = is_numeric($start) ? (float)$start : 0.0;
    return (float) number_format(
        ($finish - $startFloat),
        5
    );
}


/**
 * Indent the given text with the given number of spaces.
 *
 * @param String $string
 * @param Integer $indend	Number of lines to indent
 * @return String
 */
function indent($string, $indend = 4)
{
    $lines = explode(PHP_EOL, $string);
    $newlines = array();
    $s = "";
    $i = 0;
    while ($i < $indend) {
        $s = $s . " ";
        $i++;
    }
    foreach ($lines as $line) {
        array_push($newlines, $s . $line);
    }
    return implode(PHP_EOL, $newlines);
}

/**
 * Convert an iterator or object into an array via JSON round-trip.
 *
 * @param  mixed $obj
 * @return array<int|string, mixed>
 */
function purify_array($obj)
{
    $h = json_decode((string)json_encode($obj), true);
    //print_r($h);
    return is_array($h) ? $h : [];
}


/**
 * Generates a unique identifier of a specified length.
 *
 * @param int $length The length of the unique identifier to generate. Default is `13`.
 * @return string The generated unique identifier.
 */
function uniqidReal($length = 13)
{
    // uniqid gives 13 chars, but you could adjust it to your needs.
    if (function_exists("random_bytes")) {
        $bytes = random_bytes(ceil($length / 2));
    } elseif (function_exists("openssl_random_pseudo_bytes")) {
        $bytes = openssl_random_pseudo_bytes(ceil($length / 2));
    } else {
        throw new \Exception("no cryptographically secure random function available");
    }
    return substr(bin2hex($bytes), 0, $length);
}

/**
 * Write an access log line for the current request.
 *
 * Delegates to `App::formatAccessLogLine()` so the entry honours
 * `App::$access_log_format` (Apache `LogFormat` / `CustomLog` parity) and the
 * trusted-proxy `X-Forwarded-For` walk in `App::clientIp()`. No-op when
 * `access_logging_enabled()` is `false`.
 *
 * @param int        $status      The HTTP status code to log.
 * @param int        $length      The response body length in bytes.
 * @param float|null $durationSec Request duration in seconds, or `null` to omit.
 */
function access_log(int $status = 200, int $length = 0, ?float $durationSec = null): void {
    if (!access_logging_enabled()) {
        return;
    }
    // Render through App::formatAccessLogLine() so the line honours
    // App::$access_log_format (Apache LogFormat / CustomLog parity) and the
    // trusted-proxy X-Forwarded-For walk in App::clientIp(). Compiled format
    // spec is cached inside App; the per-request hot path is just token
    // dispatch + string concat.
    $line = App::formatAccessLogLine($status, $length, $durationSec) . "\n";
    log_write($line, 'access');
}

/**
 * Add a header to the current response.
 *
 * Delegates to `$g->zealphp_response->header()`. The 3rd argument is the
 * `$replace` flag (PHP `header()` semantics): `true` (default) drops prior
 * same-name entries so this value wins; `false` is the APPEND form, keeping
 * earlier same-name headers so multiple Link / WWW-Authenticate / CSP headers
 * all reach the wire (#260).
 *
 * Note: the parameter was historically named `$ucwords` and passed as a DEAD
 * 3rd argument to a 2-param method (silently ignored). It is repurposed here as
 * `$replace` — every existing 2-arg call keeps the replace-by-default behaviour
 * it already had on the wire, so this is BC.
 *
 * @param string $key     The header name.
 * @param string $value   The header value.
 * @param bool   $replace Whether to replace a prior same-name header (default
 *                        true). Pass false to append.
 */
function response_add_header($key, $value, $replace = true): void
{
    $g = RequestContext::instance();
    // elog("response_add_header: $key ".var_export($value, true));
    if ($g->zealphp_response === null) {
        // No response object in worker-start / tick / CLI / task contexts — a
        // header has nowhere to go, so no-op instead of a null method call (#195).
        return;
    }
    $g->zealphp_response->header($key, $value, $replace);
}

/**
 * Sets the HTTP response status code.
 *
 * Coerces out-of-range codes to 500 (Apache parity, RFC 7230 §3.1.2 — a status
 * code is three digits, 100-599). This is the single chokepoint every status
 * sink converges on — `http_response_code()`, `header("HTTP/1.1 600 …")` and the
 * `Status:` CGI form all route here — so an out-of-range code never reaches the
 * wire as a silent 200 (OpenSwoole's one-arg `status()` drops unknown codes).
 * The coercion is logged once here via `App::coerceStatusCode()` (#292).
 *
 * @param int $status The HTTP status code to set for the response.
 */
function response_set_status(int $status): void
{
    $g = RequestContext::instance();
    $g->status = \ZealPHP\App::coerceStatusCode($status);
    // Any explicit status set supersedes a prior raw `HTTP/x.x` status line —
    // last write wins, like mod_php (#327).
    $g->raw_status_code = null;
    $g->raw_status_reason = null;
}

/**
 * Retrieves all the response headers.
 *
 * @return array<int, array{0: string, 1: string}> An associative array of all the response headers.
 */
function response_headers_list(): array
{
    $response = RequestContext::instance()->zealphp_response;
    return $response === null ? [] : $response->headersList;
}

/**
 * Set a response cookie (uopz override of PHP's built-in `setcookie()`).
 *
 * Validates the cookie name and value for control characters (matching PHP
 * native behaviour since PHP 7). Supports the PHP 7.3+ options-array form for
 * `$expire_or_options`. Delegates to `$g->zealphp_response->cookie()`.
 *
 * @param string $name
 * @param string $value
 * @param int|array{expires?: int, path?: string, domain?: string, secure?: bool, httponly?: bool, samesite?: string} $expire_or_options
 * @param string $path
 * @param string $domain
 * @param bool $secure
 * @param bool $httponly
 * @param string $samesite
 */
function setcookie($name, $value = "", int|array $expire_or_options = 0, $path = "", $domain = "", $secure = false, $httponly = false, $samesite = ''): bool {
    if (is_array($expire_or_options)) {
        $expire   = (int) ($expire_or_options['expires'] ?? 0);
        $path     = (string) ($expire_or_options['path'] ?? '');
        $domain   = (string) ($expire_or_options['domain'] ?? '');
        $secure   = (bool) ($expire_or_options['secure'] ?? false);
        $httponly  = (bool) ($expire_or_options['httponly'] ?? false);
        $samesite = (string) ($expire_or_options['samesite'] ?? '');
    } else {
        $expire = $expire_or_options;
    }
    if (strpbrk((string)$name, "=,; \t\r\n\013\014\0") !== false) {
        trigger_error("Cookie names cannot contain any of the following '=,; \\t\\r\\n\\013\\014'", E_USER_WARNING);
        return false;
    }
    if (strpbrk((string)$value, "\r\n\0") !== false
        || strpbrk((string)$path, "\r\n\0") !== false
        || strpbrk((string)$domain, "\r\n\0") !== false
        || strpbrk((string)$samesite, "\r\n\0") !== false) {
        trigger_error('Cookie value/path/domain/samesite contains control characters', E_USER_WARNING);
        return false;
    }
    $g = RequestContext::instance();
    if ($g->zealphp_response === null) {
        return false; // no response object (worker-start/tick/CLI/task) — cookie not sent (#195)
    }
    $g->zealphp_response->cookie($name, $value, $expire, $path, $domain, $secure, $httponly, $samesite);
    return true;
}

/**
 * Set a raw (URL-encoded) response cookie (uopz override of PHP's built-in `setrawcookie()`).
 *
 * Like `setcookie()` but the value is sent as-is without URL-encoding. Supports
 * the PHP 7.3+ options-array form for `$expire_or_options`. Because the raw
 * variant does NOT url-encode, PHP 8.4 rejects a name or value carrying any of
 * `,; \t\r\n\013\014\0` by throwing a `ValueError` (not a warning) — this
 * override mirrors that so legacy code relying on the throw behaves identically
 * (#291). `setcookie()` keeps its warn-and-return-false behaviour because it
 * url-encodes the value, so the same characters are harmless there.
 *
 * @param string $name
 * @param string $value
 * @param int|array{expires?: int, path?: string, domain?: string, secure?: bool, httponly?: bool} $expire_or_options
 * @param string $path
 * @param string $domain
 * @param bool   $secure
 * @param bool   $httponly
 */
function setrawcookie($name, $value = "", int|array $expire_or_options = 0, $path = "", $domain = "", $secure = false, $httponly = false): bool {
    if (is_array($expire_or_options)) {
        $expire   = (int) ($expire_or_options['expires'] ?? 0);
        $path     = (string) ($expire_or_options['path'] ?? '');
        $domain   = (string) ($expire_or_options['domain'] ?? '');
        $secure   = (bool) ($expire_or_options['secure'] ?? false);
        $httponly  = (bool) ($expire_or_options['httponly'] ?? false);
    } else {
        $expire = $expire_or_options;
    }
    // PHP 8.4 raw-cookie semantics: a name or value containing a separator,
    // SP/HTAB, or control char throws a ValueError (the value is never
    // url-encoded, so these would corrupt the Set-Cookie header on the wire).
    if (strpbrk((string)$name, "=,; \t\r\n\013\014\0") !== false) {
        throw new \ValueError("Cookie name cannot be empty or contain any of the following ',; ', or any control characters.");
    }
    if (strpbrk((string)$value, ",; \t\r\n\013\014\0") !== false) {
        throw new \ValueError("Cookie value cannot contain any of the following ',; ', or any control characters.");
    }
    if (strpbrk((string)$path, "\r\n\0") !== false
        || strpbrk((string)$domain, "\r\n\0") !== false) {
        trigger_error('Raw cookie path/domain contains control characters', E_USER_WARNING);
        return false;
    }
    $cookie = "$name=$value";
    if ($expire) {
        $cookie .= "; expires=" . gmdate('D, d-M-Y H:i:s T', $expire);
    }
    if ($path) {
        $cookie .= "; path=$path";
    }
    if ($domain) {
        $cookie .= "; domain=$domain";
    }
    if ($secure) {
        $cookie .= "; secure";
    }
    if ($httponly) {
        $cookie .= "; httponly";
    }
    $g = RequestContext::instance();
    if ($g->zealphp_response === null) {
        return false; // no response object (worker-start/tick/CLI/task) — cookie not sent (#195)
    }
    $g->zealphp_response->rawCookie($name, $value, $expire, $path, $domain, $secure, $httponly);
    return true;
}

/**
 * Set a response header (uopz override of PHP's built-in `header()`).
 *
 * Guards against CRLF/NUL injection (HTTP response splitting). Recognises
 * the Apache mod_php status-line forms:
 *   - `header("HTTP/1.1 404 Not Found")` — sets the response status code.
 *   - `header("Status: 404 Not Found")` — CGI variant; sets the status code.
 *
 * When `$replace` is `true`, any previously queued header with the same name
 * (case-insensitive) is removed before the new value is added.
 *
 * @param string   $header
 * @param bool     $replace
 * @param int|null $http_response_code
 * @return false|void
 */
function header($header, $replace = true, $http_response_code = null) {
    // CRLF / NUL injection guard — matches PHP native header() since 4.4.2.
    // Without this, `header("X-Foo: " . $userInput)` with CRLF in $userInput
    // enables HTTP response splitting (smuggle a second header / response body).
    if (strpbrk($header, "\r\n\0") !== false) {
        trigger_error('Header may not contain more than a single header, new line detected', E_USER_WARNING);
        return false;
    }
    // Apache mod_php form 1: status line — header("HTTP/1.1 404 Not Found");
    // mod_php forwards this form VERBATIM — code AND reason — even for codes
    // outside 100–599 (verified live: Apache 2.4.67 emits `HTTP/1.1 600
    // Custom Reason` untouched, while http_response_code(600) → 500). The
    // explicit status line is the developer saying "I know what I'm doing",
    // so the raw pair is carried on RequestContext for the emit chokepoints
    // (#327); response_set_status() keeps feeding the PSR layer a
    // withStatus()-safe code (out-of-range → the #320 placeholder).
    if (stripos($header, 'HTTP/') === 0) {
        if (preg_match('/\s(\d{3})\s*(.*)$/', $header, $m)) {
            response_set_status((int)$m[1]);
            $g = RequestContext::instance();
            $g->raw_status_code = (int)$m[1];
            $reason = trim($m[2]);
            $g->raw_status_reason = $reason !== '' ? $reason : null;
        }
        return;
    }
    // Apache mod_php form 2: Status: 404 — variant used by some CGI tooling
    if (stripos($header, 'Status:') === 0) {
        if (preg_match('/(\d{3})/', $header, $m)) {
            response_set_status((int)$m[1]);
        }
        return;
    }
    $parts = explode(':', $header, 2);
    if (count($parts) < 2) {
        return false;
    }
    $name = trim($parts[0]);
    $value = trim($parts[1]);
    // Thread $replace through to the response wrapper, which owns the same-name
    // dedup (replace=true → last wins) vs append (replace=false → both kept, and
    // flush() groups appends into one array-valued OpenSwoole call so multiple
    // same-name headers all reach the wire — #260).
    response_add_header($name, $value, (bool)$replace);
    if ($http_response_code !== null && (int)$http_response_code > 0) {
        response_set_status((int)$http_response_code);
    }
}


/**
 * Get or set the HTTP response status code (uopz override of PHP's built-in `http_response_code()`).
 *
 * When `$code` is `null`, returns the current status. Otherwise sets it and
 * returns `null`.
 *
 * @param int|null $code
 * @return int|null
 */
function http_response_code($code = null) {
   if ($code !== null) {
       response_set_status($code);
   } else {
       return RequestContext::instance()->status;
   }
   return null;
}

/**
 * Per-coroutine `putenv()` — stores the assignment in the request-scoped
 * `RequestContext` (`$g->memo['_env']`), which is isolated per coroutine in
 * Mode 4, instead of the process-wide environment. Pairs with `getenv()`.
 * The process environment stays at its boot value, so concurrent requests no
 * longer race `putenv()` (a process-level landmine in any persistent server).
 *
 * Trade-off: subprocesses (`proc_open`) do NOT inherit a request-scoped `putenv` —
 * use it for request-scoped config (tenant id, locale), not for child-process
 * environment. Registered only in coroutine-isolated mode (Mode 4).
 *
 * @param string $assignment `"NAME=value"` to set, or `"NAME"` to unset.
 */
function zeal_putenv(string $assignment): bool {
    $g = RequestContext::instance();
    if (!isset($g->memo['_env']) || !\is_array($g->memo['_env'])) {
        $g->memo['_env'] = [];
    }
    $eq = strpos($assignment, '=');
    if ($eq === false) {
        // "NAME" with no '=' removes the variable (getenv returns false).
        $g->memo['_env'][$assignment] = false;
    } else {
        $g->memo['_env'][substr($assignment, 0, $eq)] = substr($assignment, $eq + 1);
    }
    return true;
}

/**
 * Per-coroutine `getenv()` — reads the request-scoped env first (set via
 * `putenv()`), then the process environment captured at boot
 * (`App::$boot_env`). No-arg form returns the merged map. `$local_only`
 * returns only request-scoped variables (matches the native signature).
 *
 * @param string|null $name
 * @return string|array<string,string>|false
 */
function zeal_getenv($name = null, bool $local_only = false) {
    $g = RequestContext::instance();
    $local = (\is_array($g->memo['_env'] ?? null)) ? $g->memo['_env'] : [];
    if ($name === null) {
        $merged = $local_only ? [] : App::$boot_env;
        foreach ($local as $k => $v) {
            if ($v === false) { unset($merged[(string) $k]); }
            elseif (\is_scalar($v)) { $merged[(string) $k] = (string) $v; }
        }
        return $merged;
    }
    if (\array_key_exists($name, $local)) {
        $v = $local[$name];
        return \is_scalar($v) ? (string) $v : false;
    }
    if ($local_only) {
        return false;
    }
    return App::$boot_env[$name] ?? false;
}

/**
 * Coroutine-safe `shell_exec()` shim — routes through `App::exec()`.
 *
 * Registered as a uopz override of the `shell_exec` builtin when exec hooking
 * is enabled (see `App::$hook_exec`). Because the PHP backtick operator
 * compiles down to a `shell_exec()` call, overriding `shell_exec` also makes
 * `` `cmd` `` coroutine-safe transparently.
 *
 * Preserves the builtin's documented return shape: `null` when the command
 * produced no output and failed, otherwise the captured stdout string.
 */
function zeal_shell_exec(string $cmd): ?string {
    $r = App::exec($cmd);
    return ($r['output'] === '' && $r['code'] !== 0) ? null : $r['output'];
}

/**
 * Coroutine-safe `system()` shim — routes through `App::exec()`.
 *
 * Echoes the full output (like the builtin) and returns the last line of
 * output, writing the exit code into `$code` by reference.
 *
 * @param int|null $code Exit status, written by reference.
 * @param-out int  $code
 */
function zeal_system(string $cmd, &$code = null): string {
    $r = App::exec($cmd);
    $code = $r['code'];
    echo $r['output'];
    // explode() always returns at least one element, so end() is safe.
    $lines = explode("\n", rtrim($r['output'], "\n"));
    return (string) end($lines);
}

/**
 * Coroutine-safe `passthru()` shim — routes through `App::exec()`.
 *
 * Echoes the raw output and writes the exit code into `$code` by reference.
 *
 * @param int|null $code Exit status, written by reference.
 * @param-out int  $code
 */
function zeal_passthru(string $cmd, &$code = null): void {
    $r = App::exec($cmd);
    $code = $r['code'];
    echo $r['output'];
}

/**
 * Coroutine-safe `exec()` shim — routes through `App::exec()`.
 *
 * Appends each output line to `$output` (like the builtin) and writes the
 * exit code into `$code` by reference. Returns the last line of output.
 *
 * @param list<string> $output Output lines, appended by reference.
 * @param int|null     $code   Exit status, written by reference.
 * @param-out int      $code
 */
function zeal_exec(string $cmd, array &$output = [], &$code = null): string {
    $r = App::exec($cmd);
    $code = $r['code'];
    foreach (explode("\n", rtrim($r['output'], "\n")) as $l) {
        $output[] = $l;
    }
    // $output always has at least one element here (explode yields >=1), so end() is safe.
    return (string) end($output);
}

/**
 * Return all outbound response headers as formatted strings (uopz override of `headers_list()`).
 *
 * Each element is formatted as `"Name: value"`.
 *
 * @return array<int, string>
 */
function headers_list(): array {
   $headers = response_headers_list();
   $result = [];
   foreach ($headers as $pair) {
       $result[] = "$pair[0]: $pair[1]";
   }
   return $result;
}

/**
 * Check whether response headers have already been sent (uopz override of `headers_sent()`).
 *
 * Under OpenSwoole, headers are considered "sent" when the underlying
 * `openswoole_response` is no longer writable. The `$file` and `$line`
 * out-parameters are not populated (no PHP output-started tracking in this
 * runtime).
 *
 * @param string|null $file Optional. If provided, this will be set to the filename where output started.
 * @param int|null    $line Optional. If provided, this will be set to the line number where output started.
 * @return bool Returns `true` if headers have already been sent, `false` otherwise.
 */
function headers_sent(&$file = null, &$line = null) {
   $g = RequestContext::instance();
   if (isset($g->openswoole_response)) {
       return !$g->openswoole_response->isWritable();
   }
   return false;
}

/**
 * Remove a previously set response header (uopz override of `header_remove()`).
 *
 * With no argument (or `null`), clears all queued response headers. Otherwise
 * removes all headers matching `$name` (case-insensitive).
 */
function header_remove(?string $name = null): void
{
    $response = RequestContext::instance()->zealphp_response;
    if ($response === null) {
        return;
    }
    if ($name === null) {
        $response->headersList = [];
        return;
    }
    $response->headersList = array_values(array_filter(
        $response->headersList,
        static fn($pair) => strcasecmp($pair[0], $name) !== 0
    ));
}

/**
 * Force the current output buffer to the client (uopz override of `flush()`).
 *
 * In main-worker mode, this switches the response into streaming mode: headers
 * are flushed once, then body chunks are written via `openswoole_response->write()`.
 * Subsequent `echo` + `flush()` calls stream incrementally. No-op when the
 * response is no longer writable or no response context is available.
 */
function flush(): void
{
    $g = RequestContext::instance();
    if (!isset($g->openswoole_response)) {
        return;
    }
    if (!$g->openswoole_response->isWritable()) {
        return;
    }
    if (!($g->_streaming ?? false)) {
        $g->_streaming = true;
        if (isset($g->zealphp_response)) {
            $g->zealphp_response->flush();
        }
    }
    if (ob_get_level() > 0) {
        $data = ob_get_clean();
        if ($data !== false && $data !== '') {
            $g->openswoole_response->write($data);
        }
        ob_start();
    }
}

/**
 * Alias for `ZealPHP\flush()` — flushes the current output buffer to the client.
 */
function ob_flush(): void
{
    \ZealPHP\flush();
}

/**
 * Flush the current output buffer and close it (uopz override of `ob_end_flush()`).
 *
 * Delegates to `ZealPHP\flush()`, then ends the active output buffer level.
 */
function ob_end_flush(): void
{
    \ZealPHP\flush();
    if (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

/**
 * Apache mod_php `ob_implicit_flush()` compatibility shim.
 *
 * Toggles implicit flush on/off under mod_php. ZealPHP buffers per request
 * by default; this call is accepted as a no-op rather than crashing legacy code.
 *
 * @param bool|int $enable
 */
function ob_implicit_flush($enable = true): void
{
    // no-op
}

/**
 * mod_php-parity `phpinfo()`: render a self-contained HTML document instead of the
 * CLI SAPI's plain-text dump. Matches the native signature — echoes output and
 * returns `true`. Wired via uopz in `App::__construct()`; the renderer lives in
 * `\ZealPHP\Diagnostics\PhpInfo`.
 *
 * @param int $flags `INFO_*` bitmask.
 */
function phpinfo(int $flags = INFO_ALL): bool
{
    echo \ZealPHP\Diagnostics\PhpInfo::render($flags);
    return true;
}

/**
 * mod_php-parity `php_sapi_name()`: under the CLI SAPI this natively returns `"cli"`,
 * which legacy apps branch on to disable web-only behavior. When an app opts in
 * via `App::sapiName('apache2handler')` (or `'fpm-fcgi'`), this returns the configured
 * value so such code takes its web path. Default (`App::$sapi_name === null`) returns
 * the real `PHP_SAPI` — zero behavior change unless explicitly configured.
 *
 * Note: the `PHP_SAPI` *constant* cannot be redefined (`uopz_redefine` refuses it), so
 * code reading the constant directly still sees `"cli"`. Documented limitation.
 */
function php_sapi_name(): string
{
    return \ZealPHP\App::$sapi_name ?? PHP_SAPI;
}

/**
 * mod_php-parity `filter_input()`: native `filter_input()` reads PHP's internal SAPI
 * request tables, which OpenSwoole never populates (so it returns `null` under CLI).
 * This resolves the value from `RequestContext` (`$g`) and applies the requested filter.
 *
 * @param array<string, mixed>|int $options
 */
function filter_input(int $type, string $var_name, int $filter = FILTER_DEFAULT, array|int $options = 0): mixed
{
    $bag = \ZealPHP\Input\RequestInput::bagFor($type);
    return \ZealPHP\Input\RequestInput::filterValue($bag, $var_name, $filter, $options);
}

/**
 * mod_php-parity `filter_input_array()`: the array counterpart of `filter_input()`.
 *
 * @param array<string, mixed>|int $options
 * @return array<string, mixed>
 */
function filter_input_array(int $type, array|int $options = FILTER_DEFAULT, bool $add_empty = true): array
{
    $bag = \ZealPHP\Input\RequestInput::bagFor($type);
    return \ZealPHP\Input\RequestInput::filterArray($bag, $options, $add_empty);
}

/**
 * mod_php-parity `header_register_callback()`: native PHP fires the callback when
 * the SAPI is about to send headers — which never happens the normal way under
 * OpenSwoole. ZealPHP stores it per-request (coroutine-safe, in `$g->memo`) and
 * invokes it once just before the buffered response headers are flushed, so
 * `header()` calls inside the callback still land. Last registration wins (matches
 * native, which keeps a single callback). Returns `false` if there's no request
 * context (e.g. called outside a request).
 *
 * Scope note: fires for buffered responses (the common case). Streaming / SSE
 * paths flush headers eagerly and are intentionally excluded, consistent with
 * the framework's buffered-vs-streaming split (e.g. `Range`/`ETag` middleware).
 */
function header_register_callback(callable $callback): bool
{
    try {
        \ZealPHP\RequestContext::instance()->memo['_header_callback'] = $callback;
        return true;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * mod_php-parity `error_log()`: under the CLI SAPI native `error_log()` writes to
 * stderr / the `php.ini` `error_log` path. ZealPHP routes `message_type` `0` (system
 * logger) and `4` (SAPI logger) into the framework's async log (`debug.log`, or
 * stderr if logging is disabled) so legacy `error_log()` calls land where the
 * rest of the app's diagnostics go — the "we have `elog` for `error_log`" contract.
 *
 *   - type `3` (append to file): honored verbatim — explicit destination intent.
 *   - type `1` (email): unsupported under the coroutine runtime; logged + `false`.
 *   - type `0` / `4`: routed to `log_write()` (`debug.log` → stderr fallback).
 *
 * Always lands somewhere (never silently dropped), unlike `elog()` which gates on
 * debug logging; that's why this routes through `log_write()` directly.
 */
function error_log(string $message, int $message_type = 0, ?string $destination = null, ?string $additional_headers = null): bool
{
    if ($message_type === 3 && $destination !== null && $destination !== '') {
        return @file_put_contents($destination, $message, FILE_APPEND | LOCK_EX) !== false;
    }
    $line = '[error_log] ' . date('d-m-Y H:i:s') . ' ' . rtrim($message, "\r\n") . "\n";
    log_write($line, 'debug');
    return $message_type !== 1; // email path can't actually deliver
}

/**
 * Apache mod_php `getallheaders()` / `apache_request_headers()` — return all
 * inbound request headers with canonical (`Hyphen-Capitalized`) case.
 *
 * @return array<string, string>
 */
function apache_request_headers(): array
{
    $g = RequestContext::instance();
    $out = [];
    $raw = [];
    if (isset($g->zealphp_request)) {
        $raw = $g->zealphp_request->parent->header ?? [];
    }
    if (!is_array($raw)) {
        return $out;
    }
    foreach ($raw as $name => $value) {
        $canonical = str_replace(' ', '-', ucwords(str_replace('-', ' ', strtolower((string)$name))));
        if (is_array($value)) {
            $strValues = [];
            foreach ($value as $v) {
                if (is_scalar($v)) {
                    $strValues[] = (string)$v;
                }
            }
            $out[$canonical] = implode(', ', $strValues);
        } else {
            $out[$canonical] = is_scalar($value) ? (string)$value : '';
        }
    }
    return $out;
}

/**
 * Alias for `apache_request_headers()` — return all inbound request headers.
 *
 * @return array<string, string>
 */
function getallheaders(): array
{
    return apache_request_headers();
}

/**
 * Apache mod_php `apache_response_headers()` — return currently queued outbound headers.
 *
 * @return array<string, string>
 */
function apache_response_headers(): array
{
    $response = RequestContext::instance()->zealphp_response;
    if ($response === null) {
        return [];
    }
    $out = [];
    foreach ($response->headersList as $pair) {
        $out[$pair[0]] = $pair[1];
    }
    return $out;
}

/**
 * Apache mod_php per-request env table setter (`apache_setenv()`).
 *
 * Backed by `Legacy\ApacheContext` on `G`; lifetime = one request. Lazy —
 * only allocated if legacy code calls `apache_setenv()`/`apache_getenv()`/`apache_note()`.
 * The `$walk_to_top` flag is accepted for API compatibility but has no effect.
 */
function apache_setenv(string $variable, string $value, bool $walk_to_top = false): bool
{
    $g = RequestContext::instance();
    if ($g->apacheContext === null) {
        $g->apacheContext = new \ZealPHP\Legacy\ApacheContext();
    }
    $g->apacheContext->env[$variable] = $value;
    return true;
}

/**
 * Apache mod_php per-request env table getter (`apache_getenv()`).
 *
 * Returns `false` when no Apache context has been initialised or the variable
 * is not set. The `$walk_to_top` flag is accepted for API compatibility but
 * has no effect.
 *
 * @return string|false
 */
function apache_getenv(string $variable, bool $walk_to_top = false)
{
    $ctx = RequestContext::instance()->apacheContext;
    return $ctx === null ? false : ($ctx->env[$variable] ?? false);
}

/**
 * Apache mod_php `apache_note()` — per-request note table. Returns previous value.
 *
 * When `$note_value` is `null`, acts as a getter only. Setting a value
 * lazily initialises the `ApacheContext` if needed.
 */
function apache_note(string $note_name, ?string $note_value = null): string
{
    $g = RequestContext::instance();
    $previous = (string)($g->apacheContext->notes[$note_name] ?? '');
    if ($note_value !== null) {
        if ($g->apacheContext === null) {
            $g->apacheContext = new \ZealPHP\Legacy\ApacheContext();
        }
        $g->apacheContext->notes[$note_name] = $note_value;
    }
    return $previous;
}

/**
 * Apache mod_php `virtual()` — performs an internal subrequest.
 *
 * Not supported in ZealPHP's single-process model; logs once via `elog()` and
 * returns `false` rather than crashing legacy code.
 */
function virtual(string $uri): bool
{
    elog("virtual() is not supported in ZealPHP — ignored: $uri", 'warn');
    return false;
}

/**
 * `set_time_limit()` compatibility shim.
 *
 * OpenSwoole has its own coroutine/worker timeouts and the native PHP
 * execution-time limit is irrelevant here. Treated as no-op success.
 */
function set_time_limit(int $seconds): bool
{
    return true;
}

/**
 * `ignore_user_abort()` compatibility shim (uopz override).
 *
 * Apache mod_php controls whether the script keeps running after the client
 * disconnects. The state is tracked in `G`; with OpenSwoole the coroutine
 * continues regardless, but we honor the API contract. When called with no
 * argument, returns the current setting without changing it.
 *
 * @param bool|null $enable
 */
function ignore_user_abort($enable = null): int
{
    $g = RequestContext::instance();
    $previous = $g->ignore_user_abort_state;
    if ($enable !== null) {
        $g->ignore_user_abort_state = $enable ? 1 : 0;
    }
    return $previous;
}

/**
 * Return the connection status for the current request.
 *
 * Returns `1` (`CONNECTION_ABORTED`) when the underlying `openswoole_response`
 * is no longer writable, `0` (`CONNECTION_NORMAL`) otherwise.
 */
function connection_status(): int
{
    $g = RequestContext::instance();
    if (isset($g->openswoole_response)
        && !$g->openswoole_response->isWritable()) {
        return 1; // CONNECTION_ABORTED
    }
    return 0; // CONNECTION_NORMAL
}

/**
 * Return `1` when the client connection has been aborted, `0` otherwise.
 *
 * Equivalent to `connection_status() === 1`.
 */
function connection_aborted(): int
{
    $g = RequestContext::instance();
    if (isset($g->openswoole_response)
        && !$g->openswoole_response->isWritable()) {
        return 1;
    }
    return 0;
}

/**
 * Apache's URL-rewrite output handler — not used in ZealPHP. No-op returning `false`.
 */
function output_add_rewrite_var(string $name, string $value): bool
{
    return false;
}

/**
 * Apache's URL-rewrite output handler reset — not used in ZealPHP. No-op returning `true`.
 */
function output_reset_rewrite_vars(): bool
{
    return true;
}

/**
 * `is_uploaded_file()` compatibility shim (uopz override).
 *
 * Verifies that `$filename` is one of the temp paths registered in this
 * request's `$_FILES` (via `$g->files`). Rejects forged paths from user input.
 */
function is_uploaded_file(string $filename): bool
{
    $g = RequestContext::instance();
    foreach ($g->files as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        // PHP-canonical $_FILES is field-major (#304): the per-field 'tmp_name'
        // is a scalar (single file) OR a (possibly nested) array of tmp paths
        // for array/nested uploads. Walk it recursively so every registered
        // tmp_name leaf is recognised.
        $tmp = $entry['tmp_name'] ?? null;
        if ($tmp !== null && _zealphp_tmp_name_matches($tmp, $filename)) {
            return true;
        }
    }
    return false;
}

/**
 * Recursively test whether `$filename` is one of the temp-path leaves in a
 * field-major `$_FILES[...]['tmp_name']` value (scalar or nested array).
 *
 * @param mixed $tmp Scalar tmp path or an (possibly nested) array of them.
 */
function _zealphp_tmp_name_matches(mixed $tmp, string $filename): bool
{
    if (is_string($tmp)) {
        return $tmp === $filename;
    }
    if (is_array($tmp)) {
        foreach ($tmp as $leaf) {
            if (_zealphp_tmp_name_matches($leaf, $filename)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * `move_uploaded_file()` compatibility shim (uopz override).
 *
 * Equivalent to Apache+mod_php behaviour, gated by `is_uploaded_file()` and
 * falling back to `copy()`+`unlink()` across filesystems when `rename()` fails.
 */
function move_uploaded_file(string $from, string $to): bool
{
    if (!is_uploaded_file($from)) {
        return false;
    }
    if (@rename($from, $to)) {
        return true;
    }
    if (@copy($from, $to)) {
        @unlink($from);
        return true;
    }
    return false;
}

/**
 * Per-request `set_error_handler()` (uopz override).
 *
 * The native PHP error handler is installed at boot and delegates to `G`'s
 * per-coroutine stack. This override records the user-space registration in
 * `$g->error_handlers_stack` without touching the engine handler. Passing
 * `null` pops the most recently registered handler (matches native behaviour).
 */
function set_error_handler(?callable $callback, int $error_levels = E_ALL): ?callable
{
    $g = RequestContext::instance();
    $stack = $g->error_handlers_stack;
    $prev = !empty($stack) ? $stack[count($stack) - 1][0] : null;
    if ($callback === null) {
        array_pop($stack);
    } else {
        $stack[] = [$callback, $error_levels];
    }
    $g->error_handlers_stack = $stack;
    return $prev;
}

/**
 * Pop the most recently registered per-request error handler.
 *
 * Mirrors the native `restore_error_handler()` contract; always returns `true`.
 */
function restore_error_handler(): bool
{
    $g = RequestContext::instance();
    $stack = $g->error_handlers_stack;
    array_pop($stack);
    $g->error_handlers_stack = $stack;
    return true;
}

/**
 * Per-request `set_exception_handler()` (uopz override).
 *
 * Stores the handler in `$g->exception_handlers_stack`. Passing `null` pops
 * the most recently registered handler. Returns the previously active handler
 * (or `null` when none was set).
 */
function set_exception_handler(?callable $callback): ?callable
{
    $g = RequestContext::instance();
    $stack = $g->exception_handlers_stack;
    $prev = !empty($stack) ? $stack[count($stack) - 1] : null;
    if ($callback === null) {
        array_pop($stack);
    } else {
        $stack[] = $callback;
    }
    $g->exception_handlers_stack = $stack;
    return $prev;
}

/**
 * Pop the most recently registered per-request exception handler.
 *
 * Mirrors the native `restore_exception_handler()` contract; always returns `true`.
 */
function restore_exception_handler(): bool
{
    $g = RequestContext::instance();
    $stack = $g->exception_handlers_stack;
    array_pop($stack);
    $g->exception_handlers_stack = $stack;
    return true;
}

/**
 * Per-request shutdown function (uopz override of `register_shutdown_function()`).
 *
 * Fires after the route handler returns and before the PSR response is emitted,
 * so the callback can still call `echo`/`header()`/`http_response_code()` and
 * have those land in the response. Multiple callbacks are supported and called
 * in registration order.
 */
function register_shutdown_function(callable $callback, mixed ...$args): void
{
    $g = RequestContext::instance();
    $list = $g->shutdown_functions;
    $list[] = [$callback, $args];
    $g->shutdown_functions = $list;
}

/**
 * Per-coroutine `error_reporting()` (uopz override).
 *
 * When called without an argument, returns the current reporting level for this
 * coroutine (falling back to the level captured at `App` boot via
 * `App::$initial_error_reporting`). When called with a level, stores it in
 * `$g->error_reporting_level` and returns the previous level.
 */
function error_reporting(?int $error_level = null): int
{
    $g = RequestContext::instance();
    $current = $g->error_reporting_level ?? \ZealPHP\App::$initial_error_reporting;
    if ($error_level !== null) {
        $g->error_reporting_level = $error_level;
    }
    return $current;
}
