<?php
namespace ZealPHP;

use ZealPHP\ZealAPI;
use function ZealPHP\elog;
use function ZealPHP\jTraceEx;
use function ZealPHP\response_add_header;
use function ZealPHP\response_set_status;

use OpenSwoole\Core\Psr\Middleware\StackHandler;
use OpenSwoole\Core\Psr\Response;
use OpenSwoole\HTTP\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use OpenSwoole\Coroutine as co;
/**
 * ZealPHP framework core — the single process-wide singleton that owns the
 * OpenSwoole server lifecycle, route table, PSR-15 middleware stack, and all
 * per-request lifecycle configuration.
 *
 * Typical boot sequence:
 *
 * ```php
 * App::superglobals(false);          // choose lifecycle mode
 * $app = App::init('0.0.0.0', 8080); // create singleton + install overrides
 * $app->route('/hello', fn() => 'Hello!');
 * $app->addMiddleware(new CorsMiddleware());
 * $app->run();                        // start the OpenSwoole event loop
 * ```
 *
 * Configuration is expressed as static fluent setters (e.g. `App::superglobals()`,
 * `App::documentRoot()`) that MUST be called before `App::run()` — OpenSwoole
 * freezes the server settings at `$server->start()`. See the "Architecture"
 * section of `CLAUDE.md` for the full lifecycle-mode matrix.
 */
class App
{
    /** @var array<int, array{path:string,pattern:string,methods:array<int|string,string>,handler:callable|null,param_map:array<int,array<string, mixed>>,raw:bool,middleware:list<\Psr\Http\Server\MiddlewareInterface|string>,backend?:array{mode:string,interpreter?:string,address?:string,fcgi_params?:array<string,string>}|null}> */
    protected array $routes = [];
    /** @var array<string, array<int, array{path:string,pattern:string,methods:array<int|string,string>,handler:callable|null,param_map:array<int,array<string, mixed>>,raw:bool,middleware:list<\Psr\Http\Server\MiddlewareInterface|string>,backend?:array{mode:string,interpreter?:string,address?:string,fcgi_params?:array<string,string>}|null}>> */
    protected array $routes_by_method = [];
    /** @var array<string, array<string, array{path:string,pattern:string,methods:array<int|string,string>,handler:callable|null,param_map:array<int,array<string, mixed>>,raw:bool,middleware:list<\Psr\Http\Server\MiddlewareInterface|string>,backend?:array{mode:string,interpreter?:string,address?:string,fcgi_params?:array<string,string>}|null}>> */
    protected array $routes_by_exact_method = [];
    /**
     * Snapshot of the route/middleware registries taken at `App::run()` *before*
     * the `route/*.php` files + implicit routes are loaded — i.e. just the
     * app.php-defined explicit routes/aliases/scopes. `App::reloadRoutes()`
     * restores this baseline, then re-runs the file-based registration, so a
     * route-file edit can be picked up without restarting the worker. Null until
     * `run()` snapshots it.
     *
     * `implicit` holds the framework's own implicit routes (api dispatch, public
     * file serving, …) captured as data so reload re-appends them after the
     * re-included route files, preserving priority order without re-running
     * their registration.
     *
     * @var array{
     *   routes: array<int, array{path:string,pattern:string,methods:array<int|string,string>,handler:callable|null,param_map:array<int,array<string, mixed>>,raw:bool,middleware:list<\Psr\Http\Server\MiddlewareInterface|string>,backend?:array{mode:string,interpreter?:string,address?:string,fcgi_params?:array<string,string>}|null}>,
     *   implicit: array<int, array{path:string,pattern:string,methods:array<int|string,string>,handler:callable|null,param_map:array<int,array<string, mixed>>,raw:bool,middleware:list<\Psr\Http\Server\MiddlewareInterface|string>,backend?:array{mode:string,interpreter?:string,address?:string,fcgi_params?:array<string,string>}|null}>,
     *   when: list<array{type:string, key:string, spec:list<\Psr\Http\Server\MiddlewareInterface|string>}>,
     *   aliases: array<string, \Psr\Http\Server\MiddlewareInterface|callable>,
     *   backend_aliases?: array<string, array{mode:string, interpreter?:string, address?:string, fcgi_params?:array<string,string>}>
     * }|null
     */
    protected ?array $route_baseline = null;
    /** @var array<string, array{message: callable, open: callable|null, close: callable|null}> */
    protected array $ws_routes = [];
    /** @var array<int, callable> */
    protected static array $workerStartHooks = [];
    /** @var array<int, callable> */
    protected static array $workerStopHooks = [];

    /** @var array<string, list<callable>> channel/pattern → handlers */
    protected static array $pubsubRegistry = [];
    /** @var array<string, list<array{group:string, handler:callable, blockMs:int, batchSize:int}>> stream → consumers */
    protected static array $reliableRegistry = [];
    /** True once the onWorkerStart hook for pubsub/streams is wired (one-time guard). */
    protected static bool $pubsubBootWired = false;
    /** Unix timestamp (float) of the moment this worker's `onWorkerStart` callback finished — used by `App::stats()` to compute per-worker uptime. Zero until the first worker start fires. */
    protected static float $workerStartedAt = 0.0;

    // v0.3.0 helpers — registries for App::onSignal / App::addProcess and
    // metadata used by App::stats. Initialised lazily on first registration.
    /** @var array<int, list<array{handler:callable, worker_only:bool}>> */
    protected static array $signalHandlers = [];
    /** @var array<string, array{callable: callable, workers: int, coroutine: bool}> */
    protected static array $processHandlers = [];
    /** True once the onWorkerStart hook for process-pool is wired. */
    protected static bool $processBootWired = false;
    /** True once the per-worker coroutine autoload serializer is installed. */
    protected static bool $autoloadSerializerInstalled = false;
    /** Unix timestamp the master process booted at (set by run()). */
    protected static ?int $bootedAt = null;
    /** Resolved worker counts after `run()` reads CLI/env/settings. */
    protected static int $worker_num = 0;
    /** Resolved task-worker count after `run()` reads CLI/env/settings. Zero when task workers are disabled. */
    protected static int $task_worker_num = 0;
    /**
     * Default per-worker coroutine ceiling applied in `run()` when the operator
     * sets none (via `ZEALPHP_MAX_COROUTINE` or `$app->run(['max_coroutine' => N])`).
     *
     * OpenSwoole's own default is ~100,000/worker, which is effectively
     * unbounded relative to every downstream resource (the Redis pool of 8, the
     * DB connection budget, per-coroutine memory). With no ceiling a load burst
     * has no front-door shed path — it propagates inward until the first bounded
     * resource fails as a cliff (OOM / pool-acquire-timeout 500s) instead of
     * OpenSwoole rejecting the over-limit coroutine. This default restores
     * backpressure while staying generous: ~40k concurrent in-flight coroutines
     * across 4 workers (10k/worker), ~10x below OpenSwoole's 100k and far above
     * the c=1000 benchmark's ~250/worker — a real bound that won't shed normal
     * load or a typical streaming/WebSocket fan-in.
     *
     * SCALE IT for very high long-lived-connection counts (each SSE/WS client
     * holds a coroutine): `ZEALPHP_MAX_COROUTINE=50000` or
     * `$app->run(['max_coroutine' => 50000])`, and/or add workers/nodes. Pair
     * with `ConcurrencyLimitMiddleware` (nginx limit_conn parity) for graceful
     * 503s before the ceiling is hit.
     */
    public const DEFAULT_MAX_COROUTINE = 10000;
    /** Bind address for the OpenSwoole server (e.g. `'0.0.0.0'` or `'127.0.0.1'`). Set in `__construct()` from `App::init()`. */
    protected string $host;
    // Widened protected → public so ZealPHP\CLI (extracted from App.php in the
    // Phase 1 decomposition) can read the running instance's port for PID-file
    // / status reporting. Additive BC change — no caller relied on it being
    // protected, App is not subclassed, and the value is read-only post-boot.
    public int $port;
    /**
     * Absolute working directory the framework boots in. Resolved at boot
     * via `realpath(__DIR__ . '/..')` and exposed read-only for handlers
     * that need to build paths relative to the project root (e.g.
     * `App::$cwd . '/.cache'` for the file-tier cache directory).
     *
     * Defaults to '' so reads before `App::init()` (e.g. `elog()` building a
     * relative path) don't fatal with "typed static accessed before
     * initialization" — `str_replace('', ...)` is a harmless no-op. `init()`
     * sets the real working directory.
     */
    public static string $cwd = '';
    /**
     * The active OpenSwoole server instance after `App::run()` constructs
     * it; `null` before `run()`. Returned as a `WebSocket\Server` when any
     * `App::ws()` route was registered (the framework upgrades from
     * `Http\Server` automatically), `Http\Server` for pure HTTP apps.
     * Use `App::getServer()` for the public accessor.
     *
     * @var \OpenSwoole\WebSocket\Server|\OpenSwoole\Http\Server|null
     */
    public static $server;
    /**
     * Override value for `$_SERVER['PHP_SELF']` and friends. `null` means
     * "use the request URI verbatim", which is the normal Apache/nginx
     * convention. Apps that need a stable `PHP_SELF` (legacy WordPress
     * plugins, etc.) can pin it here.
     */
    public static ?string $default_php_self = null;
    private static ?self $instance = null;
    /**
     * Whether framework error pages render the captured exception + stack
     * trace inline. Secure-by-default: `null` (the default) resolves at
     * runtime to the `ZEALPHP_DEV` env var — OFF in production, so 5xx pages
     * show a generic message and never leak traces/secrets (#412). Call
     * `App::displayErrors(true)` for the inline-trace development view; an
     * explicit setter call always wins over the env resolution.
     *
     * Read through `App::displayErrors()` (not the raw property) to get the
     * env-resolved value; a raw `null`/`false` is treated as "do not leak".
     */
    public static ?bool $display_errors = null;
    /**
     * Per-request lifecycle mode (the dial that picks `$g` storage +
     * SessionManager + `enable_coroutine` + HOOK_ALL default). See the
     * "Lifecycle modes" matrix in CLAUDE.md — short version:
     *
     *   - `true` (default): `$g` lives in process-wide PHP superglobals
     *     (`$_GET`/`$_POST`/`$_SESSION` etc.) — Apache mod_php parity.
     *     One request at a time per worker; coroutine scheduler OFF
     *     by default. Unmodified WordPress / Drupal work here.
     *   - `false`: per-coroutine `$g` via `Coroutine::getContext()`.
     *     Concurrent coroutine handling enabled; superglobals NOT
     *     populated. Modern apps that want OpenSwoole concurrency
     *     pick this.
     *
     * Set via `App::superglobals(bool)` BEFORE `App::init()`.
     */
    public static bool $superglobals = true;

    /**
     * True when ext-zealphp's per-coroutine superglobal isolation is active.
     * Set by `App::run()` when sg=T + ec=T + ext-zealphp loaded. Tells
     * `RequestContext::instance()` to use per-coroutine instances even in
     * superglobals mode — ext-zealphp isolates `$_GET`/`$_SESSION` per
     * coroutine, so per-coroutine `$g` is safe and necessary for framework
     * state (`$g->zealphp_response`, etc.) to be isolated too.
     */
    public static bool $coroutine_isolated_superglobals = false;

    /**
     * Extra classes to compile at worker start so they are NEVER cold-autoloaded
     * under request concurrency. APPENDED to the framework's own request-path
     * warmup set (see `preloadRequestPathClasses()`).
     *
     * WHY THIS EXISTS — in coroutine-legacy mode, a class first compiled by
     * several overlapping coroutines at once (the first concurrent cold wave)
     * can intermittently fail to register durably → transient `Class "X" not
     * found` 500s on the cold burst, then fine once warm. Classes loaded at
     * boot / worker-start (single-coroutine, no overlap) are immune. The
     * framework warms its own request/response path; YOUR controllers, services,
     * and any lazily-instantiated class on a hot path must be warmed too.
     *
     * Register BEFORE `App::run()`:
     *   App::preloadClasses(App\Controller\Home::class, App\Service\Auth::class);
     *
     * No-op outside coroutine-legacy (other modes don't have the race).
     *
     * @var list<class-string>
     */
    public static array $preload_classes = [];

    /**
     * When true, warm EVERY class in Composer's classmap in the MASTER process
     * (before `$server->start()` forks the workers), so a user app's own
     * controllers/services (autoloaded on demand, deep inside handlers — "the
     * app is just the server") are born LINKED and copy-on-write-forked into
     * every worker, never compiled on the concurrent cold path. This is the
     * structural fix for the present-but-unlinked inheritance race: the whole
     * dependency graph is bound in a single process with NO coroutine scheduler,
     * so nothing can yield and let a worker interleave a cold compile. Same idea
     * as PHP's `opcache.preload`. Validated: 0 failures across cold bursts with
     * the framework's own onWorkerStart preload disabled (classmap-only).
     *
     * Requires an OPTIMIZED Composer classmap to be complete — run
     * `composer dump-autoload --optimize` (or `--classmap-authoritative`).
     * A plain PSR-4 autoloader has a sparse classmap; for that, list hot classes
     * with `App::preloadClasses()` or warm a tree with `App::preloadDir()`. A
     * pure `require_once` legacy app (no Composer/autoloader at all — classic
     * WordPress) can't be warmed this way; run it in `legacy-cgi` mode, which is
     * process-isolated and has NO coroutine race in the first place.
     *
     * Off by default (it trades a slower BOOT + higher baseline RSS for the
     * guarantee). Enable BEFORE `App::run()` via `App::preloadClassmap()`.
     */
    public static bool $preload_classmap = false;

    /**
     * Source directory trees to warm at worker start (PSR-4 roots / app source
     * whose symbols a registered autoloader can resolve). Each `.php` file's
     * declared symbols are extracted via the tokenizer and autoloaded+linked
     * single-coroutine. Append via `App::preloadDir()`.
     *
     * @var list<string>
     */
    public static array $preload_dirs = [];

    /**
     * Process environment captured at boot (real `getenv()`), before the
     * per-coroutine putenv/getenv overrides are installed in Mode 4. The
     * overridden `\ZealPHP\zeal_getenv` falls back to this for variables not set
     * request-scoped via `\ZealPHP\zeal_putenv`.
     *
     * @var array<string, string>
     */
    public static array $boot_env = [];

    /**
     * Per-request define() isolation. When true, constants defined during
     * a request are tracked and removed at request end. Boot-time constants
     * (PHP_VERSION, extension defines, autoloaded class constants) survive.
     *
     * WARNING: breaks `require_once` apps — the file won't re-execute to
     * re-define the constant on the next request. Use only with apps that
     * guard defines (`defined('X') ?: define('X', ...)`) AND use `require`
     * (not `require_once`) for the defining file, OR with processIsolation
     * where each request gets a fresh process.
     *
     * Set via `App::defineIsolation(true)` BEFORE `App::run()`.
     */
    public static bool $define_isolation = false;

    /**
     * Per-request function/class/include isolation via ext-zealphp's
     * zealphp_process_state_snapshot() / zealphp_process_state_clean().
     *
     * When enabled, the worker snapshots its function table, class table,
     * and included-files cache at boot. At request end, any functions,
     * classes, or require_once entries added during the request are removed
     * — giving fresh-process semantics without subprocess overhead.
     *
     * ONLY safe in Mode 3 (sync, sequential). In coroutine modes the
     * function table is shared across concurrent coroutines — cleaning it
     * mid-flight would crash other coroutines.
     *
     * Set via `App::functionIsolation(true)` BEFORE `App::run()`.
     */
    public static bool $function_isolation = false;

    /**
     * Per-request `require_once` cache reset. Clears EG(included_files) so
     * files loaded via `require_once` on request N re-execute on request N+1.
     * Functions and classes defined by those files stay loaded (they live in
     * CG(function_table)/CG(class_table), not in included_files). Pair with
     * `silentRedeclare(true)` so the re-executed function/class/constant
     * declarations are silently skipped instead of E_COMPILE_ERROR.
     *
     * Solves the "WordPress template-loader runs once then becomes a no-op"
     * class of bug — any app that puts per-request logic inside a
     * `require_once`'d file needs this.
     *
     * Safe in ALL modes (sync, coroutine, hybrid). Implemented by ext-zealphp
     * Stage 7: `zealphp_include_isolation(true)` installs a ZEND_INCLUDE_OR_EVAL
     * opcode hook that, for any `require_once`/`include_once` of a file NOT in
     * the boot snapshot, drops it from EG(included_files) inline so it
     * re-executes — bootstrap (snapshotted) files stay cached. This needs ZERO
     * per-request cleanup (it replaces the older per-request
     * `zealphp_process_state_clean(1)` files-wipe). Requires a snapshot to have
     * been taken (`zealphp_process_state_snapshot()` in onWorkerStart); the
     * framework takes it automatically when this flag is on.
     */
    public static bool $include_isolation = false;

    /**
     * Per-coroutine `$GLOBALS` isolation via ext-zealphp's
     * `zealphp_coroutine_globals()`. When enabled, each coroutine gets its
     * own snapshot of `EG(symbol_table)` swapped in on yield/resume — so
     * `$GLOBALS['app_state']` and `global $foo;` writes never race across
     * concurrent coroutines.
     *
     * Closes the last architectural gap in Mode 4/5 — user-defined globals
     * (which were previously process-wide) now isolate alongside super-
     * globals, constants, ini settings, and static properties.
     *
     * Tradeoff: each coroutine maintains its own `$GLOBALS` deep-copy at
     * yield boundary — O(N keys) extra memory per active coroutine. No
     * function/class table impact — autoloaders keep working as before.
     *
     * Requires ext-zealphp 0.3.6+ with `zealphp_coroutine_globals` function.
     * Set via `App::coroutineGlobalsIsolation(true)` BEFORE `App::run()`.
     */
    public static bool $coroutine_globals_isolation = false;

    /**
     * Keep user-defined `$GLOBALS` across requests within the same worker.
     *
     * Default `false`: at the end of every request the session manager
     * calls `zealphp_globals_clean()` to drop user-defined entries back
     * to the parent baseline. This matches FPM's "fresh process per
     * request" semantic at the request boundary.
     *
     * When `true`: that cleanup is SKIPPED. `$GLOBALS['wp_object_cache']`,
     * `$GLOBALS['wp_did_header']`, `$GLOBALS['wpdb']`, and similar
     * process-persistent state stays alive across requests within the
     * worker's lifetime — matching how WordPress, Drupal 7/8, MediaWiki,
     * Magento, and other globals-heavy legacy apps were designed to
     * work under long-running mod_php / single-process SAPI. The same
     * semantic FrankenPHP's worker mode provides.
     *
     * When to use:
     *   - WordPress, Drupal, MediaWiki, Magento — apps with explicit
     *     process-persistent globals
     *   - Any procedural PHP app where `$wp_did_header`-style boot
     *     sentinels gate the bootstrap chain
     *
     * Sharp edge to know about:
     *   - Apps that mistakenly store REQUEST-SCOPED data in `$GLOBALS`
     *     (e.g., `$GLOBALS['current_user_id'] = $_SESSION['uid']`) will
     *     observe cross-request bleed. This is a pre-existing bad
     *     pattern that's also unsafe under FPM with opcache + persistent
     *     connections. The fix is to use `$_SERVER`, session, or a
     *     per-request DI container — not `$GLOBALS`.
     *
     * Bounded by worker recycle: OpenSwoole's `max_request` cap (typical
     * 10,000–50,000) gives the same eventual "fresh process" reset that
     * FPM does, just at the worker level instead of per-request.
     *
     * Set via `App::keepGlobals(true)` BEFORE `App::run()`.
     */
    public static bool $keep_globals = false;

    /**
     * Stage 3 — silent-redeclare opcode hooks. When enabled, ext-zealphp's
     * `ZEND_DECLARE_FUNCTION` / `ZEND_DECLARE_CLASS` / `_DELAYED` opcode
     * handlers check if the target symbol already exists in
     * `EG(function_table)` / `CG(class_table)`. If it does, the opcode is
     * silently skipped instead of throwing `E_COMPILE_ERROR`
     * ("Cannot redeclare …"). First declaration wins — matches what FPM
     * gets "for free" by forking a fresh process per request.
     *
     * Closes the dominant Mode 3/4/5 failure mode on the 32-app sweep:
     * conditional `function foo() {}` / `class Bar {}` in legacy code that
     * re-runs on every request. Top-level (file-scope) function declarations
     * are still compile-time-bound by Zend and not covered by this hook;
     * those need OPcache enabled OR Mode 1 Pool.
     *
     * Requires ext-zealphp 0.3.8+. Set via `App::silentRedeclare(true)`
     * BEFORE `App::run()`. Off by default to keep existing semantics.
     */
    public static bool $silent_redeclare = false;

    /**
     * Stage 8 — true-global-scope request include (coroutine-legacy). When on
     * (and ext-zealphp exposes `zealphp_require_global()`), `App::include()` runs
     * the target file at TRUE global scope so a bare file-scope `$x = ...` — and
     * every transitive `require_once` — binds to `$GLOBALS` instead of the
     * `executeFile()` method frame. This is what lets unmodified `require_once`-
     * bootstrap apps (WordPress's `$menu`/`$submenu`/`$_wp_submenu_nopriv`, built
     * bare at file scope in `wp-admin/includes/menu.php`) render in
     * **coroutine-legacy** in-process mode, where the request entry runs inside a
     * PHP method and those vars would otherwise be method-local.
     *
     * Only effective under coroutine-legacy (`$silent_redeclare`): global-scope
     * includes need the per-coroutine globals isolation stack, else file-scope
     * globals leak across coroutines. **Off by default** — it changes include
     * scope (the included file does NOT see `executeFile()`'s injected `$g` /
     * route params as locals), so enable it only for legacy apps that read
     * request state through superglobals, not via ZealPHP's `$g`. `null` follows
     * the `ZEALPHP_GLOBAL_INCLUDE` env var (default off). Set via
     * `App::globalScopeInclude(true)` BEFORE `App::run()`. Canonical reference:
     * `docs/architecture/2026-06-02-stage8-global-scope-include.md`.
     */
    public static ?bool $global_scope_include = null;

    /**
     * Stage 5 — per-coroutine FUNCTION-local `static $x` isolation via
     * ext-zealphp's `zealphp_coroutine_statics()`. This is the LAST
     * request-state primitive that previously leaked across coroutines
     * (everything else — superglobals, class statics, `$GLOBALS`, constants,
     * `ini_set`, `putenv` — is already isolated). When enabled, the on_yield
     * hook snapshots every instantiated function/method's live static table
     * per coroutine and restores THIS coroutine's values on resume — the same
     * snapshot/restore model already proven for class statics. Cooperative
     * scheduling makes it correct: a coroutine writes its statics after its own
     * restore and reads them before its next yield, so values never bleed.
     *
     * Verified: 0 leaks across 240 requests at peak-40 concurrency, both
     * opcache on and off, no crash (`tests/Integration/TrustBarIsolationTest`
     * keeps `fn_static` in the hard contract).
     *
     * DEFAULT ON in `coroutine-legacy` (v0.3.10): a touched-set registry —
     * populated by a ZEND_BIND_STATIC opcode hook as functions first
     * instantiate their statics — means the per-yield snapshot iterates ONLY
     * the functions that actually use statics, not every declared function.
     * Cost is therefore decoupled from total function count: ~1.9µs/yield at
     * 50 static-using functions, FLAT from 500 to 8000 total functions (the
     * pre-registry full-table walk was ~0.16ms/yield at 1200 functions and
     * scaled with the total — that version halved throughput at scale). Cost
     * now scales only with the (small) number of static-USING functions — the
     * irreducible per-coroutine snapshot. Closures + eval/top-level code are
     * excluded from the registry (their op_arrays have per-instance lifetime;
     * this matches exactly what the snapshot already covered — no regression).
     *
     * Opt out with env `ZEALPHP_FN_STATICS_DISABLE=1` (or
     * `App::coroutineStaticsIsolation(false)` before `App::run()`) for raw
     * throughput on apps that don't depend on per-request function statics.
     *
     * Requires ext-zealphp 0.3.10+ with `zealphp_coroutine_statics`.
     */
    public static bool $coroutine_statics_isolation = false;

    /**
     * Per-coroutine WORKING-DIRECTORY isolation (#323). `chdir()` is a
     * process-level syscall, so under coroutine concurrency one request's
     * `chdir()` (or the framework's own `executeFile()` chdir-to-script-dir)
     * changes the CWD of every concurrently-running peer — racy relative
     * includes / `fopen` across the whole worker. When ON (and ext-zealphp
     * 0.3.35+ is loaded), the scheduler hooks save each coroutine's cwd on
     * yield (re-parking the worker baseline so peers start clean) and restore
     * it on resume — `chdir()` becomes per-coroutine, like PHP-FPM's
     * per-process CWD. Auto-enabled by `App::mode('coroutine-legacy')`
     * (opt out with env `ZEALPHP_CWD_ISOLATION_DISABLE=1`); off by default
     * elsewhere (coroutines that never chdir cost one getcwd+strcmp per yield
     * when on, zero when off).
     */
    public static bool $coroutine_cwd_isolation = false;

    /**
     * Per-coroutine LOCALE isolation — setlocale() is process-global (string
     * casing, number/date formatting), so one request's locale change leaks
     * into every concurrently-running peer mid-request. When ON (ext-zealphp
     * 0.3.38+), the scheduler hooks save each coroutine's locale on yield
     * (re-parking the worker baseline captured at enable time — a boot-time
     * setlocale() before App::run() IS the baseline) and restore it on
     * resume. Auto-enabled by `App::mode('coroutine-legacy')` (opt out with
     * env `ZEALPHP_LOCALE_ISOLATION_DISABLE=1`); off by default elsewhere.
     */
    public static bool $coroutine_locale_isolation = false;

    /**
     * Per-coroutine UMASK isolation — umask() is process-global file-mode
     * state; one request's umask(0077) changes every peer's file creation
     * mid-request. Same stage shape as locale/CWD (ext-zealphp 0.3.38+;
     * the umask read+re-park is a single syscall). Auto-enabled by
     * `App::mode('coroutine-legacy')` (opt out with env
     * `ZEALPHP_UMASK_ISOLATION_DISABLE=1`); off by default elsewhere.
     */
    public static bool $coroutine_umask_isolation = false;

    /**
     * Per-coroutine date_default_timezone_set() isolation — the default
     * timezone is process-global; WordPress-class apps set it per request
     * (core boot reads the site option), so one request's timezone leaks
     * into every concurrently-running peer (measured 179/250 at 49-way
     * concurrency). Same stage shape as locale/umask (ext-zealphp 0.3.45+,
     * via the engine's own getter/setter pair). Auto-enabled by
     * `App::mode('coroutine-legacy')` (opt out with env
     * `ZEALPHP_TZ_ISOLATION_DISABLE=1`); off by default elsewhere.
     */
    public static bool $coroutine_tz_isolation = false;

    /**
     * Per-coroutine mb_internal_encoding() isolation — the mbstring current
     * internal encoding is process-global; legacy code sets it before string
     * work (measured 173/250 leaks at 49-way concurrency). ext-zealphp
     * 0.3.45+; auto-refuses when mbstring is absent. Auto-enabled by
     * `App::mode('coroutine-legacy')` (opt out with env
     * `ZEALPHP_MBENC_ISOLATION_DISABLE=1`); off by default elsewhere.
     */
    public static bool $coroutine_mbenc_isolation = false;

    /**
     * Per-coroutine libxml_use_internal_errors() FLAG isolation — the libxml
     * error-buffering flag is process-global (measured 128/250 leaks).
     * ext-zealphp 0.3.45+. Fidelity note: collected errors are preserved
     * within a slice (parse + libxml_get_errors with no yield between — the
     * dominant pattern) but not across an I/O yield (php-src's own disable
     * semantic frees the list on re-park). Auto-enabled by
     * `App::mode('coroutine-legacy')` (opt out with env
     * `ZEALPHP_LIBXML_ISOLATION_DISABLE=1`); off by default elsewhere.
     */
    public static bool $coroutine_libxml_isolation = false;

    /**
     * Set true at the top of `App::run()` so the four lifecycle setters
     * (`superglobals`, `processIsolation`, `enableCoroutine`, `hookAll`)
     * can refuse mutations made AFTER the server has booted.
     *
     * Why: those four knobs decide the SessionManager class, the
     * `enable_coroutine` server setting, and `OpenSwoole\Runtime::HOOK_ALL`
     * — all of which are frozen at `run()` boot. But the static-property
     * backing stores are re-read PER-REQUEST in `executeFile()` and
     * `App::include()`. Mutating them mid-game leaves the framework in a
     * Schrödinger state — coroutines still active, but superglobals reads
     * now say "I'm in CGI mode". Concurrent coroutines race on `$_GET`/
     * `$_POST`/`$_SESSION`. `validateLifecycleCombination()` only fires
     * at boot so it doesn't catch this. The guard closes that footgun.
     */
    public static bool $run_has_started = false;
    /**
     * The PSR-15 middleware stack handler, built during `App::run()` from
     * the registered middleware list. `null` before `run()`. Generally
     * read via the public `App::middleware()` accessor; this property is
     * public for advanced introspection (e.g. /healthz dumps).
     */
    public static ?StackHandler $middleware_stack = null;
    /**
     * Middleware queued via `App::addMiddleware()` BEFORE `App::run()`.
     * Reversed at boot and fed to OpenSwoole's StackHandler (whose `add()`
     * prepends and whose `handle()` runs index 0 first) so the **first**
     * middleware you add wraps outermost and runs first; `ResponseMiddleware`
     * (the router) always runs innermost. Public for apps that want to
     * inspect / mutate the stack at boot time.
     *
     * @var array<int, MiddlewareInterface>
     */
    public static array $middleware_wait_stack = [];
    /**
     * Named middleware registry — Traefik's "named & shared" middleware and
     * Laravel's route-middleware aliases. Maps a short name to either a
     * ready `MiddlewareInterface` instance or a factory `callable(...$args)`
     * that returns one. Populated by `App::middlewareAlias()` at boot;
     * resolved to instances once at `App::run()` (single-coroutine, so the
     * hot path never does a registry lookup or instantiation). Reused across
     * routes — middleware objects MUST be stateless (request state lives in
     * `$g`/`RequestContext`, never on the middleware) because one instance is
     * shared by every concurrent coroutine that uses the alias.
     *
     * @var array<string, MiddlewareInterface|callable>
     */
    public static array $middleware_aliases = [];
    /**
     * Path-scoped middleware registry — `App::when($path, $middleware)`. Each
     * entry scopes a chain to a URL path prefix (or a `#...#` PCRE), and runs
     * for EVERY request whose normalized path matches — route or api alike,
     * since api endpoints are just `/api/...` URLs on the same stack. Stored in
     * registration order (first registered = outermost). Raw specs here;
     * resolved to instances at `App::run()` into `$when_middleware_compiled`.
     *
     * @var list<array{type:string, key:string, spec:list<MiddlewareInterface|string>}>
     */
    public static array $when_middleware = [];
    /**
     * Boot-compiled `App::when` chains (alias→instance), read-only at request
     * time so the hot path never does a registry lookup or `new`.
     *
     * @var list<array{type:string, key:string, chain:list<MiddlewareInterface>}>
     */
    public static array $when_middleware_compiled = [];
    /**
     * Per-normalized-path memo of the flattened matching `when` chain (the
     * registry is immutable after boot, so this is a write-once-per-path cache;
     * concurrent same-path writes are idempotent). Capped at `WHEN_MEMO_MAX`
     * entries so an attacker spraying distinct paths can't grow it without
     * bound — past the cap, paths simply recompute the (cheap) prefix scan.
     *
     * @var array<string, list<MiddlewareInterface>>
     */
    public static array $when_middleware_memo = [];
    /** Upper bound on the `App::when` per-path memo (memory-exhaustion guard). */
    private const WHEN_MEMO_MAX = 4096;
    /**
     * True only while `App::reloadRoutes()` is re-including `route/*.php` files.
     * Infrastructure-registration calls that route files also make at boot
     * (`Store::make`, `App::ws` excluded as it is idempotent, `App::onWorkerStart`,
     * `App::addProcess`, `App::subscribe`, `App::onSignal`) check this flag and
     * skip re-wiring — they were wired once at boot and a reload only swaps the
     * route table, never the worker's timers/processes/subscribers.
     */
    public static bool $reloading = false;
    /**
     * Dev route hot-reload toggle. When true, each worker polls `route/*.php`
     * mtimes and calls `reloadRoutes()` on change (no process restart). `null`
     * resolves to the `ZEALPHP_DEV` env var at `run()`. OFF in production
     * (the route table stays master-loaded + COW-shared). Set via `App::devReload()`.
     */
    public static ?bool $dev_reload = null;
    /**
     * When `true` (default), URLs ending in `.php` get a 403. The framework
     * encourages extensionless URLs as the canonical public surface (matches
     * Apache `RewriteRule \.php$ - [F]` parity). Set `false` to allow direct
     * `*.php` routing — useful when porting an existing app that links to
     * `/foo.php` from external sources.
     */
    public static bool $ignore_php_ext = true;
    /**
     * Log warnings when a ZealAPI filename collides with an HTTP method
     * keyword (`get.php` defining `$get`) or when a filename-matched handler
     * shadows per-method handlers in the same file. Default ON so new apps
     * surface mistakes; set to `false` (or `'api_warn_collisions' => false`
     * in the `run()` config) for legacy codebases that knowingly use method
     * names as filenames.
     */
    public static bool $api_warn_collisions = true;
    /**
     * #347 — the `404 {"error":"method_not_found"}` envelope for a ZealAPI
     * handler that returns `null` with NO output, NO explicit status and NO
     * streaming. **Mode-aware (corrected rule):** it applies ONLY to
     * **per-method** dispatch (`$get`/`$post`/…) — a method handler that ran
     * and produced nothing. A **filename-match** handler (`$list`, serving all
     * methods) returning null is an intentional **empty 200** (native-PHP
     * parity — an empty-set / infinite-scroll tail), never a 404. A method
     * with no handler at all already 405s before this point. Default ON.
     * Escape hatches: `return '';`, set a status, or this `false` (disables the
     * per-method 404 for pure-native APIs).
     */
    public static bool $api_null_not_found = true;
    /**
     * Toggle the uopz override of the exec family (backtick / `shell_exec`
     * / `exec` / `system` / `passthru`) so they yield via OpenSwoole's
     * coroutine scheduler instead of blocking the worker.
     *
     * `null` (default) resolves to "on when coroutine mode" (`!$superglobals`)
     * at `App::run()` — matches the production-safe expectation. Set via
     * `App::hookExec(bool)` for an explicit override.
     */
    public static ?bool $hook_exec = null;
    /**
     * Toggle the ext-zealphp interception of `exit()`/`die()` so a userland
     * exit inside a coroutine throws `ZealPHP\HaltException` (which extends
     * `\Error`, so the ubiquitous `try { … exit; } catch (\Exception)`
     * legacy-router idiom cannot swallow the normal exit and turn it into a
     * 500 — issue ext#47; FreshRSS/DokuWiki/CodeIgniter). The framework's
     * halt-aware sites flush the buffered output as the body. `null`
     * (default) resolves to "on when the coroutine scheduler is active"
     * (`enableCoroutine` effective) at `App::run()`; a non-null value forces
     * it. Requires ext-zealphp 0.3.48+ (`zealphp_exit_hook`); a no-op
     * otherwise. Env opt-out: `ZEALPHP_EXIT_HOOK_DISABLE=1`.
     */
    public static ?bool $hook_exit = null;
    /**
     * Enable the legacy CGI request handler for `public/*.php` paths.
     * Resolved from `$process_isolation` at `App::run()`; you generally
     * don't set this directly. See `App::processIsolation()` and the
     * Lifecycle-modes matrix in CLAUDE.md.
     */
    public static bool $coproc_implicit_request_handler = false;
    /**
     * Per-include CGI process-isolation override. `null` means "follow
     * `$superglobals`" (true → CGI subprocess via `cgi_worker.php`; false →
     * in-process via `executeFile()`), which preserves today's default
     * coupling. Set via `App::processIsolation(bool)` — see that method for
     * the trade-offs. `App::run()` resolves this into the backing
     * `$coproc_implicit_request_handler` flag right before the server starts.
     */
    public static ?bool $process_isolation = null;
    /**
     * How a process-isolated legacy include is dispatched, when
     * `processIsolation()` is on:
     *
     *   `'pool'` (default) — native FCGI-style worker pool. Each OpenSwoole
     *                      worker spawns `$cgi_pool_size` persistent PHP
     *                      subprocesses (FPM `pm.max_children` parity); each
     *                      subprocess provides mod_php-style isolation per
     *                      request (clean global scope — top-level `$x = ...`
     *                      is visible via `global $x`, so unmodified
     *                      WordPress/Drupal work). Parent dispatches via
     *                      `Coroutine\Channel` — thousands of coroutines fan
     *                      out across the pool without blocking the worker.
     *                      Subprocess recycle after `$cgi_pool_max_requests`
     *                      (FPM `pm.max_requests` parity).
     *
     *   `'proc'` (legacy fallback) — `proc_open()` spawns a FRESH PHP per
     *                      request (`src/cgi_worker.php`). Same isolation as
     *                      `'pool'` but pays cold-PHP startup + autoload
     *                      EVERY request (~tens of ms). Kept as a fallback
     *                      for environments where the pool can't be used
     *                      (e.g. uopz unavailable in the subprocess, or
     *                      audit / compliance requiring zero pre-warm).
     *
     *   `'fork'` (experimental) — Apache MPM prefork. A long-lived fork-master
     *                      (`src/fork_master.php`) forks a FRESH child per
     *                      request that runs the include at TRUE global scope
     *                      (~1 ms fork cost), captures the response, then
     *                      hard-exits. Same fresh-process correctness as
     *                      `'proc'` (no class-redeclare) but at fork cost
     *                      instead of `proc_open` cold start. Requires
     *                      pcntl + posix; bounds live children via
     *                      `App::$cgi_fork_max_concurrent` (503 when full).
     *
     *   `'fcgi'` (deployment mode) — forward to an external FastCGI backend
     *                      (php-fpm, hhvm, roadrunner) via the FCGI binary
     *                      protocol over TCP or Unix socket. Target address
     *                      via `App::fcgiAddress()`. Use when ZealPHP fronts
     *                      an existing FPM pool you don't want to retire.
     *
     * Set via `App::cgiMode('pool'|'proc'|'fork'|'fcgi')`. Default `'pool'`.
     */
    public static string $cgi_mode = 'pool';
    /**
     * FastCGI backend address used when `App::cgiMode() === 'fcgi'`.
     * Format: `"host:port"` for TCP (e.g. `"127.0.0.1:9000"`) or
     *         `"unix:/path/to/php-fpm.sock"` for a Unix-domain socket.
     * Set via `App::fcgiAddress()`. Default is the standard `php-fpm` TCP listener.
     */
    public static string $fcgi_address = '127.0.0.1:9000';
    /**
     * Subprocess count for `cgiMode('pool')` — the native FCGI-style worker
     * pool. Each OpenSwoole worker process spawns this many persistent PHP
     * subprocesses on first dispatch (lazy). FPM `pm.max_children` parity:
     * sets the per-worker concurrency cap. Default 4 — balances spawn cost
     * with concurrency for typical web workloads.
     */
    public static int $cgi_pool_size = 4;
    /**
     * Per-subprocess recycle threshold for `cgiMode('pool')`. After this many
     * requests, the subprocess exits cleanly and the pool spawns a fresh
     * replacement — FPM `pm.max_requests` parity, bounds memory leak from
     * long-running plugin code. Set to 1 to recycle every request (true
     * fresh-process semantics; same isolation as `cgiMode('proc')` but with
     * the pool managing spawn-cost amortisation).
     */
    public static int $cgi_pool_max_requests = 500;
    /**
     * Optional strict env allowlist for `cgiMode('pool')` subprocesses
     * (`WorkerPool::filterSubprocessEnv`). Empty (default) = pass the parent
     * environment through to the subprocess (legacy-app compatibility) MINUS
     * the request-controlled `HTTP_PROXY` (httpoxy). Set via
     * `App::cgiPoolEnvAllowlist([...])` to a list of exact names / `PREFIX*`
     * globs to restrict what secrets the long-lived subprocess inherits;
     * `ZEALPHP_POOL_MAX_REQUESTS` is always passed. Per-request CGI vars travel
     * over the IPC frame, not this env, so a strict allowlist doesn't lose them.
     *
     * @var list<string>
     */
    public static array $cgi_pool_env_allowlist = [];
    /**
     * True once `cgiPoolMaxRequests()` set the recycle count explicitly. The
     * `mode()` presets consult this so a `mode('legacy-cgi')` default (recycle=1)
     * never clobbers an explicit user choice, regardless of call order.
     */
    public static bool $cgi_pool_max_requests_set = false;
    /**
     * "Explicitly set by a fluent setter" flags for the env-overridable CGI
     * knobs. `App::resolveCgiEnv()` applies a `ZEALPHP_CGI_*` value only when
     * the matching flag is false — so explicit code config always wins over the
     * environment, which in turn wins over the hardcoded default.
     */
    public static bool $cgi_mode_set = false;
    public static bool $cgi_pool_size_set = false;
    public static bool $cgi_timeout_set = false;
    public static bool $fcgi_address_set = false;
    public static bool $cgi_fork_max_concurrent_set = false;
    /**
     * Whether `cgi_worker.php` (proc-mode subprocess entry) loads Composer's
     * `vendor/autoload.php` on startup. Default `false` — restores the pre-
     * v0.2.20 behaviour where the subprocess runs at true global scope with
     * NO ZealPHP framework loaded, suitable for unmodified WordPress / Drupal.
     *
     * **Why off by default:** the autoloader load costs ~30 ms per subprocess
     * spawn (measured on Ryzen 9 7900X / PHP 8.3). For WordPress's
     * `wp_cron()` self-call pattern (a non-blocking HTTP POST to `/wp-cron.php`
     * with a `timeout` of 0.01 s), that 30 ms upfront cost causes the wp-cron
     * POSTs to queue at the parent faster than workers can drain them —
     * eventually deadlocking the pool. Issue #18 (the v0.2.41 WP-on-proc
     * regression vs v0.2.0).
     *
     * **When to set `true`:** your `public/*.php` files need to call
     * `\ZealPHP\App`, the Apache shims, or any framework class. Modern apps
     * built ON ZealPHP, NOT migrated TO it. Most legacy apps (WordPress,
     * Drupal, Joomla, plain PHP) ship their own bootstrap and don't need it.
     */
    public static bool $cgi_subprocess_autoload = false;
    /**
     * Per-worker WorkerPool singleton for `cgiMode('pool')`. Lazy-spawned on
     * first dispatch in this OpenSwoole worker. Held here (not on a Store)
     * because each OpenSwoole worker owns its own subprocess pool — proc
     * resources don't share across workers.
     */
    public static ?\ZealPHP\CGI\WorkerPool $cgi_pool_instance = null;
    /**
     * Per-worker ForkPool singleton for `cgiMode('fork')` — the fork-master
     * subprocess. Lazy-spawned on first dispatch in this OpenSwoole worker
     * (same per-worker ownership rationale as $cgi_pool_instance).
     */
    public static ?\ZealPHP\CGI\ForkPool $cgi_fork_instance = null;
    /**
     * Live-child concurrency cap for `cgiMode('fork')` — the fork-master refuses
     * to fork past this many simultaneous children (fork-bomb guard + backpressure).
     * Defaults to 16; this is a per-request fork ceiling, NOT the pre-spawned
     * process count `cgi_pool_size` (which would wrongly throttle to 4 under a
     * coroutine worker handling many concurrent requests).
     */
    public static int $cgi_fork_max_concurrent = 16;
    /**
     * Per-extension CGI backend registry. Apache `AddHandler`/`ProxyPassMatch`
     * + nginx `fastcgi_pass`-per-location parity.
     *
     * Shape: `[ '.ext' => ['mode' => 'proc'|'fork'|'fcgi', ...options] ]`
     *
     * Default: empty — unregistered extensions (including `.php`) fall through to
     * `App::$cgi_mode` (which defaults to `'proc'`, preserving existing behaviour).
     * Register additional extensions with `App::registerCgiBackend()`.
     *
     * @var array<string, array{mode:string, interpreter?:string|null, address?:string, fcgi_params?:array<string,string>, exec_paths?:array<int,string>}>
     */
    public static array $cgi_backends = [];
    /**
     * ScriptAlias-style CGI path registry (Apache `ScriptAlias` parity). Maps a
     * normalised URL prefix (leading slash, no trailing slash) to a backend
     * config. Any file served under a registered prefix is treated as
     * executable regardless of its extension.
     *
     * @var array<string, array{mode:string, interpreter?:string|null, address?:string, fcgi_params?:array<string,string>}>
     */
    public static array $cgi_script_aliases = [];
    /**
     * Named CGI backend aliases for the per-route `backend:` option, registered
     * via `App::cgiBackendAlias()`. Maps an alias name to a normalised backend
     * config (the same shape `resolveCgiBackend()` returns, minus `exec_paths`).
     * Resolved at route registration so `backend: 'wp-fork'` becomes a concrete
     * dispatch strategy with zero per-request lookup. Mirrors the
     * `$middleware_aliases` precedent.
     *
     * @var array<string, array{mode:string, interpreter?:string, address?:string, fcgi_params?:array<string,string>}>
     */
    public static array $cgi_backend_aliases = [];
    /**
     * OpenSwoole `enable_coroutine` server-setting override. `null` means
     * "follow `!$superglobals`" (true → coroutine-per-request, false → one
     * synchronous request at a time per worker). Set via
     * `App::enableCoroutine(bool)`. Combining true with `$superglobals=true`
     * is unsafe — process-wide `$_GET`/`$_POST`/`$_SESSION` will race across
     * concurrent coroutines; the helper warns at `run()` time.
     */
    public static ?bool $enable_coroutine_override = null;
    /**
     * `OpenSwoole\Runtime::enableCoroutine($flags)` override. Same shape
     * as `App::hookAll()` input: `null` → follow `!$superglobals` (`HOOK_ALL` when
     * coroutine mode, `0` in superglobals mode); `true` → `HOOK_ALL`; `false`
     * → `0`; `int` → explicit bitmask. PDO is intentionally NOT hooked in
     * OpenSwoole 22.1 / 26.2 regardless of this flag.
     * @var bool|int|null
     */
    public static $hook_all_override = null;
    /** Apache `DirectorySlash` equivalent — redirect `/foo` → `/foo/` when foo is a directory. */
    public static bool $directory_slash = true;
    /**
     * Apache `DirectoryIndex` — file names tried in order when a directory is requested.
     * @var array<int, string>
     */
    public static array $directory_index = ['index.php', 'index.html', 'index.htm'];
    /** Apache `PATH_INFO` — when `/script.php/extra/path`, expose `/extra/path` as `PATH_INFO`. */
    public static bool $path_info = true;
    /**
     * Apache `AllowEncodedSlashes` — when false (default, matching Apache), a
     * request whose RAW (pre-decode) path contains an encoded slash (`%2F`/`%2f`)
     * is refused with 404 before route matching. Apache's `unescape_url()`
     * forbids `AP_SLASHES` by default; we mirror that. Set true to permit
     * encoded slashes (they are then decoded to `/` like any other octet).
     */
    public static bool $allow_encoded_slashes = false;
    /**
     * Static handler URL-prefix whitelist. Empty = serve any path under `document_root` (Apache default).
     * @var array<int, string>
     */
    public static array $static_handler_locations = [];
    /** Block any path containing a dotfile component (`.git`, `.env`, `.htaccess`, etc.). Apache convention. */
    public static bool $block_dotfiles = true;
    /**
     * Apache `DocumentRoot` equivalent. Relative values (the default) are
     * resolved against `App::$cwd`; absolute values are used as-is. Drives
     * `App::include()` path resolution and the implicit `/{file}/{dir/uri}` routes.
     */
    public static string $document_root = 'public';
    /**
     * Apache `TraceEnable` — defaults to OFF for security. When false (default)
     * `ResponseMiddleware` refuses HTTP `TRACE` with `405` regardless of any matching
     * route definition. Set to true only if you know you need `TRACE`.
     */
    public static bool $trace_enabled = false;
    /**
     * Apache `AddDefaultCharset`. Stored here for consumers (e.g. a future
     * `CharsetMiddleware`) that want a server-wide default charset to append
     * to text-ish `Content-Type` headers.
     */
    public static string $default_charset = 'utf-8';
    /**
     * Apache `DefaultType` / PHP `default_mimetype`. The Content-Type applied by
     * CharsetMiddleware to a response that doesn't set one itself (mod_php sends
     * `text/html` by default). Set to '' to leave untyped responses untouched.
     */
    public static string $default_mimetype = 'text/html';
    /**
     * Apache `ServerTokens`. Controls how much detail the `X-Powered-By`
     * response header advertises:
     *   `'Full'`  (default) → `ZealPHP + OpenSwoole`
     *   `'Prod'` / `'Major'` / `'Minor'` / `'Min'` / `'OS'` → `ZealPHP`
     *   `'None'`  (or `''`)   → header omitted entirely (info-leak hardening)
     * Set via `App::serverTokens()` before `App::init()`.
     */
    public static string $server_tokens = 'Full';
    /**
     * Apache `FileETag`. When false, `ETagMiddleware` emits no `ETag` header
     * and never returns `304` (equivalent to `FileETag None`). Default true.
     * Set via `App::fileETag()` before `App::init()`.
     */
    public static bool $file_etag = true;
    /**
     * mod_php-parity SAPI identity for the `php_sapi_name()` override. Default `null`
     * returns the real `PHP_SAPI` (`"cli"`) — no behavior change. Set to a web SAPI
     * string (e.g. `'apache2handler'`, `'fpm-fcgi'`) so legacy code branching on
     * `php_sapi_name()` takes its web path. The `PHP_SAPI` *constant* is unaffected
     * (uopz cannot redefine it). Configure via `App::sapiName()` before `App::init()`.
     */
    public static ?string $sapi_name = null;
    /**
     * Whether ZealPHP's per-request session lifecycle runs. Default true: the
     * `SessionManager` / `CoSessionManager` OnRequest wrapper reads the `PHPSESSID`
     * cookie, calls `zeal_session_start()`, optionally emits the `Set-Cookie`
     * header, and closes the session at request end. Set to false when
     * another framework (e.g. Symfony's `NativeSessionStorage` via the
     * zealphp-symfony bridge) owns the session lifecycle — ZealPHP then skips
     * the session-specific work but still does request-context setup
     * (`$g->openswoole_request`, `$g->zealphp_response`, error-stack reset, etc.).
     *
     * The underlying `zeal_session_*` uopz-overridden functions remain
     * installed and callable from user code either way; this toggle only
     * controls whether the `SessionManager` wrapper drives the lifecycle
     * automatically for every request.
     */
    public static bool $session_lifecycle = true;

    /**
     * legacy-cgi only: eagerly mint a session id + Set-Cookie on a FIRST-time
     * visitor (no incoming PHPSESSID) BEFORE the CGI subprocess runs (#108).
     *
     * Default **false** for `session.auto_start=0` / mod_php parity (#355): a
     * CGI script that never calls `session_start()` must emit NO `Set-Cookie`
     * and leave the request-side `$_COOKIE` untouched. With this off,
     * `Dispatcher::mintCgiSession()` only forwards an id the client ALREADY
     * sent (returning visitor); it never injects an unsolicited id into
     * `$_COOKIE` or the response.
     *
     * Set **true** only for legacy `require_once`-bootstrap apps in `legacy-cgi`
     * that depend on a first-visit session cookie being present before the
     * subprocess emits one (the subprocess can't — PHP's session module sends
     * the cookie via the C-internal `php_setcookie()`, which the CLI SAPI
     * discards, so without an eager host mint a session-using app would loop
     * with an empty PHPSESSID). The escape hatch for #108; off honours #355.
     */
    public static bool $cgi_session_auto_start = false;

    /**
     * Session TTL in seconds. Default 7200 (2 hours — modern-app reasonable;
     * PHP's stock 1440 / 24 min is too short for typical workflows).
     * Set via `App::sessionTtl(3600)` BEFORE `App::run()`.
     */
    public static int $session_ttl = 7200;

    /**
     * Maximum concurrent sessions in OpenSwoole\Table when using
     * TableSessionHandler. Default 65536 (64K) — accommodates medium-scale
     * deployments without re-tuning. Each row costs `$session_data_size +
     * ~64 bytes` of shared memory (one allocation per OpenSwoole server,
     * NOT per worker). Default config = 64K × 16KB ≈ 1 GB shared memory.
     * Bump for high-traffic; sessions beyond the cap fall through to file
     * backing (still functional, just slower).
     */
    public static int $session_max_rows = 65536;

    /**
     * Maximum serialized session size in bytes when using TableSessionHandler.
     * Default 16384 (16 KB) — fits most modern sessions including OAuth
     * tokens, cart state, user preferences. Larger sessions overflow to
     * file backing only.
     */
    public static int $session_data_size = 16384;

    /**
     * File-backing directory for session storage. Default
     * `/var/lib/php/sessions` (matches PHP's default). Used by
     * FileSessionHandler and TableSessionHandler's file backing layer.
     */
    public static string $session_save_path = '/var/lib/php/sessions';

    /**
     * Session storage backend. One of:
     *   - `null` (default) — the framework inline **file** path in ALL modes
     *       (flock read-merge-write under `$session_save_path`). #295: deliberately
     *       NOT auto-promoted to TableSessionHandler; the unconfigured default is
     *       file-backed everywhere. Opt into a concurrent-safe backend explicitly.
     *   - `'table'` — TableSessionHandler (concurrent-safe, in-memory + file backing).
     *   - `'file'`  — FileSessionHandler (simple, key-level merge).
     *   - `'redis'` — RedisSessionHandler (cross-node, WATCH/MULTI).
     *   - SessionHandlerInterface instance — bring your own.
     *
     * Resolved via `App::resolveActiveSessionHandler()`.
     * Set via `App::sessionHandler('table')` BEFORE `App::run()`.
     *
     * @var string|\SessionHandlerInterface|null
     */
    public static string|\SessionHandlerInterface|null $session_handler = null;

    /**
     * Auth-hook callbacks consulted by `ZealAPI::isAuthenticated()`,
     * `::isAdmin()`, and `::getUsername()` so the framework's built-in
     * file-based API layer can delegate auth questions to whatever auth
     * system the app uses (Symfony Security, Auth0, the SelfMadeNinja stack,
     * a custom `$_SESSION['user']` check, etc.) without subclassing or
     * monkey-patching `ZealAPI` itself.
     *
     * Set via the fluent setters `App::authChecker()`, `App::adminChecker()`,
     * `App::usernameProvider()`. Defaults: `null` → `ZealAPI` returns the safe
     * fail-closed values (`false`, `false`, `null`). See the issue #13
     * discussion and `/learn/api` for usage.
     *
     * SECURITY (#244): on any privilege change (login / logout / role change)
     * call `session_regenerate_id(true)` to defeat session fixation. Strict mode
     * (`App::$session_strict_mode`, default on) blocks an attacker from
     * *planting* an id; regenerate-on-auth blocks *reusing* a pre-auth id. The
     * framework can't force this — it doesn't know when your app authenticates.
     *
     * @var callable|null
     */
    public static $auth_checker = null;
    /**
     * Callback consulted by `ZealAPI::isAdmin()`. Signature: `fn(): bool`.
     * Default `null` → `isAdmin()` returns `false` (fail-closed). Set via `App::adminChecker()`.
     *
     * @var callable|null
     */
    public static $admin_checker = null;
    /**
     * Callback consulted by `ZealAPI::getUsername()`. Signature: `fn(): ?string`.
     * Default `null` → `getUsername()` returns `null`. Set via `App::usernameProvider()`.
     *
     * @var callable|null
     */
    public static $username_provider = null;
    /**
     * Apache `RewriteCond %{REQUEST_FILENAME} !-d` + `RewriteRule ^(.+)/$ /$1 [R=301,L]`.
     * When true, non-directory URIs ending in `/` are `301`-redirected to the no-slash
     * form. Inverse of `$directory_slash`. Default false (keeps current behaviour).
     */
    public static bool $strip_trailing_slash = false;
    /**
     * PHP `session.use_strict_mode` parity (#244). When true (the default —
     * security-first) a CLIENT-SUPPLIED session id (from a `PHPSESSID` cookie or
     * query param) whose backing store loads an EMPTY session is treated as
     * untrusted: the session managers mint a fresh server-generated id and switch
     * the client to it. This defeats session FIXATION — an attacker who plants a
     * known id into the victim's browser can no longer have it promoted to an
     * authenticated session, because the framework rotates any unrecognised id
     * before the victim ever authenticates under it. A well-formed id that DOES
     * resolve to a non-empty stored session is preserved unchanged.
     *
     * CAVEAT — storage topology: the empty-session signal is only meaningful when
     * the id's store is visible to the node handling the request. That holds for
     * single-node (`TableSessionHandler`, the coroutine-mode default) and for
     * shared storage (`Redis`/`Tiered`-backed sessions). A MULTI-NODE deployment
     * using the per-server `TableSessionHandler` WITHOUT sticky load-balancing or
     * shared session storage is already broken (sessions don't persist across
     * nodes); with strict mode on it will ALSO rotate the id on every cross-node
     * hop. Such setups should switch to Redis-backed sessions (so every node sees
     * the same store) or opt out via `App::sessionStrictMode(false)`. Set via the
     * fluent setter `App::sessionStrictMode()`.
     */
    public static bool $session_strict_mode = true;
    /**
     * Apache `ServerAdmin webmaster@example.com`. When set, the framework's default
     * `500`/error page mentions this contact. `null` disables the contact line.
     */
    public static ?string $server_admin = null;
    /**
     * Apache `ServerName www.example.com:443`. The canonical host the server
     * advertises in absolute redirects (and other absolute URL builders) when
     * `$use_canonical_name` is true. Include scheme-port if relevant; the raw
     * value is returned as-is by `App::canonicalHost()`.
     */
    public static ?string $canonical_name = null;
    /**
     * Apache `UseCanonicalName On|Off`. When true and `$canonical_name` is set,
     * `App::canonicalHost()` returns the canonical name; otherwise it returns the
     * request `Host` header. Default false (Apache's default since 2.0).
     */
    public static bool $use_canonical_name = false;
    /**
     * Apache `HostnameLookups On|Off`. When true, the framework populates
     * `$g->server['REMOTE_HOST']` via `gethostbyaddr($g->server['REMOTE_ADDR'])`
     * on each request. **WARNING**: this performs a blocking reverse-DNS lookup
     * per request (mitigated by OpenSwoole's coroutine hook converting it to a
     * non-blocking async resolve, but still a measurable per-request cost). Off
     * by default — Apache's own default since 1.3.
     */
    public static bool $hostname_lookups = false;
    /**
     * ZealPHP config keys recognized in the `run()` $settings array.
     * Each maps to a static fluent setter; extracted before OpenSwoole
     * sees the array. Add new framework-level knobs here.
     *
     * @var array<string, string>
     */
    private static array $configMap = [
        'superglobals'          => 'superglobals',
        'process_isolation'     => 'processIsolation',
        'hook_exec'             => 'hookExec',
        'document_root'         => 'documentRoot',
        'trace_enabled'         => 'traceEnabled',
        'ignore_php_ext'        => 'ignorePhpExt',
        'default_charset'       => 'defaultCharset',
        'strip_trailing_slash'  => 'stripTrailingSlash',
        'server_admin'          => 'serverAdmin',
        'api_warn_collisions'   => 'apiWarnCollisions',
        'api_null_not_found'    => 'apiNullNotFound',
        'directory_slash'       => 'directorySlash',
        'hostname_lookups'      => 'hostnameLookups',
    ];
    /**
     * Maximum seconds to wait for a CGI subprocess (`proc` mode) to produce
     * its metadata line on stderr. After this deadline the child receives
     * `SIGTERM`; if it does not exit within 5 s it receives `SIGKILL`. Matches
     * Apache's `CGIScriptTimeout` directive. Default 60 s.
     */
    public static int $cgi_timeout = 60;
    /**
     * CIDR list of proxy IPs whose `X-Forwarded-For` / `X-Real-IP` headers
     * `App::clientIp()` will trust. Empty (the default) means no proxies trusted
     * — `App::clientIp()` always returns `REMOTE_ADDR`. Critical for production
     * deploys behind Traefik/Caddy/nginx; without it rate limiters and access
     * logs see the proxy IP instead of the real client.
     *
     * Supports IPv4 (`10.0.0.0/8`, `192.168.1.42`) and IPv6 (`2001:db8::/32`,
     * `::1`). A bare IP without `/prefix` is treated as `/32` (v4) or `/128` (v6).
     *
     * @var array<int, string>
     */
    public static array $trusted_proxies = [];
    /**
     * Apache `LogFormat "..."`. Format string used by `access_log()` to render
     * each request line. Tokens (Apache `mod_log_config` subset):
     *
     *   `%h`          Remote host/IP (uses `App::clientIp()` when `$trusted_proxies` set)
     *   `%l`          Remote logname (always `-` — RFC 1413 ident is dead)
     *   `%u`          Remote user (session username if set, else `-`)
     *   `%t`          Time `[17/May/2026:07:30:00 +0000]`
     *   `%r`          First line of request `"GET /foo HTTP/1.1"`
     *   `%>s`         Final response status
     *   `%b`          Response body bytes (`-` when zero, CLF convention)
     *   `%B`          Response body bytes (`0` when zero)
     *   `%D`          Request duration in microseconds
     *   `%T`          Request duration in seconds
     *   `%{NAME}i`    Value of request header NAME (e.g. `%{Referer}i`)
     *   `%{NAME}o`    Value of response header NAME
     *   `%{NAME}e`    Value of `$g->server[NAME]` (env)
     *   `%m`          Request method
     *   `%U`          URL path (no query string)
     *   `%q`          Query string (prefixed with `?` if present)
     *   `%H`          Request protocol (`"HTTP/1.1"`)
     *   `%v`          Server name (from `Host` header)
     *
     * Default is Apache's NCSA combined format (the prior hardcoded ZealPHP
     * output — preserving behaviour for existing log parsers). Switch to the
     * shorter Common Log Format via:
     *   `App::accessLogFormat('%h %l %u %t "%r" %>s %b');`
     */
    public static string $access_log_format = '%h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i"';
    /**
     * Parsed format spec cache (token list). Filled lazily by `formatAccessLogLine()`
     * the first time it sees a given format string. Resets when `accessLogFormat()`
     * is reassigned via the fluent setter.
     *
     * @var array<int, array{kind:string, arg?:string}>|null
     */
    private static ?array $access_log_format_compiled = null;
    /**
     * Apache `LimitRequestFields` — maximum number of request header fields a
     * single request may carry. Enforced at the PHP application layer: requests
     * carrying more than this many headers are rejected with `400` before route
     * dispatch. Set to `0` to disable the check (unlimited). Default `100` matches
     * Apache's compiled-in default.
     */
    public static int $limit_request_fields = 100;
    /**
     * Apache `LimitRequestFieldSize` — maximum byte length of a single request
     * header line. **NOT enforced by ZealPHP.** OpenSwoole's C-layer HTTP parser
     * owns all wire-level framing; ZealPHP only sees the already-parsed
     * `$request->header` array. The `http_header_buffer_size` option was
     * explicitly NOT passed to OpenSwoole (its option validator rejects it at
     * boot — see `App::run()` ~line 3748). Changing this value has no effect on
     * the actual per-header byte limit, which is governed by OpenSwoole's global
     * header-buffer size (~8 KiB default). This property is retained for
     * documentation and future compatibility only.
     */
    public static int $limit_request_field_size = 8190;
    /**
     * Apache `LimitRequestLine` — maximum byte length of the HTTP request line
     * (method + URI + protocol). **NOT enforced by ZealPHP.** OpenSwoole's C
     * parser reads the request line before any PHP code runs; there is no
     * per-request-line cap that ZealPHP can apply after the fact. OpenSwoole's
     * global `http_header_buffer_size` governs this limit at the wire level.
     * This property is retained for documentation and future compatibility only.
     */
    public static int $limit_request_line = 8190;
    /** @var array<string, mixed>|null */
    private static ?array $fallback_handler = null;
    /** Initial `error_reporting` level captured at boot — referenced by the per-coroutine override. */
    public static int $initial_error_reporting = E_ALL;
    /**
     * Status -> custom error handler registry (key 0 = catch-all).
     * @var array<int, array{handler:callable, param_map:array<int, array{name:string, has_default:bool, default:mixed}>, raw:bool}>
     */
    private static array $error_handlers = [];
    /**
     * IANA-registered HTTP status reason phrases (RFC 9110 §15).
     * Source: https://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml
     * (registry snapshot 2025-09-15). Phrases match the IANA "Description"
     * column verbatim — pinned exhaustively by `tests/Unit/IanaStatusConformanceTest`.
     *
     * Documented deviations:
     *   - `418` 'I'm a teapot' — IANA lists "(Unused)"; kept as the RFC 2324 /
     *     widely-recognised extension phrase.
     *   - `306` and `418` are the only reserved/"(Unused)" codes; all other entries
     *     are IANA-assigned. `104` (temporary registration) is intentionally omitted.
     *
     * Universal return contract: handlers may return any 100-599 status — see
     * `template/pages/responses.php#status-range` (canonical).
     */
    private const REASON_PHRASES = [
        // 1xx Informational
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',
        // 2xx Success
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        // 3xx Redirection
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        // 4xx Client Errors
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => "I'm a teapot",
        421 => 'Misdirected Request',
        422 => 'Unprocessable Content',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        // 5xx Server Errors
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    /**
     * Methods ZealPHP recognises. A request whose method is outside this set
     * gets `501 Not Implemented` (Apache: `M_INVALID` → `HTTP_NOT_IMPLEMENTED`,
     * `server/protocol.c:1253`). Standard RFC 9110 methods plus the common
     * WebDAV verbs Apache registers in `ap_method_registry_init()`. A recognised
     * method that has no matching route still flows through to `404`/`405`/fallback.
     *
     * @var array<int, string>
     */
    public const KNOWN_METHODS = [
        'GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'TRACE', 'PATCH',
        'CONNECT',
        // WebDAV (RFC 4918 / 3253) — registered by Apache's method registry.
        'PROPFIND', 'PROPPATCH', 'MKCOL', 'COPY', 'MOVE', 'LOCK', 'UNLOCK',
        'VERSION-CONTROL', 'REPORT', 'CHECKOUT', 'CHECKIN', 'UNCHECKOUT',
        'MKWORKSPACE', 'UPDATE', 'LABEL', 'MERGE', 'BASELINE-CONTROL',
        'MKACTIVITY', 'ORDERPATCH', 'ACL', 'SEARCH',
    ];

    /**
     * Coerce a handler's int return value to a valid HTTP status code.
     * Per the universal return contract, ints must be in 100-599 (RFC 7230).
     * Out-of-range values are coerced to `500` with a warning logged via `elog()`
     * so the bug surfaces in the debug log instead of silently downgrading.
     * Matches Apache HTTP server's behavior (out-of-range → `500`).
     */
    public static function coerceStatusCode(int $status): int
    {
        if ($status >= 100 && $status < 600) {
            return $status;
        }
        \ZealPHP\elog(
            "Invalid HTTP status code returned: {$status}. Coercing to 500. "
            . "(Universal return contract allows 100-599. "
            . "See template/pages/responses.php#status-range.)"
        );
        return 500;
    }

    /**
     * Whether a status code MUST be sent without a message body (RFC 7230
     * §3.3.2 / RFC 9110 §6.4.1): every 1xx informational response, plus
     * `204 No Content` and `304 Not Modified`. For these, a server must emit
     * neither a body nor a `Content-Length` / `Content-Type` header — a
     * non-empty body is a framing violation that some clients treat as the
     * start of the next response. The emit chokepoint uses this to drop any
     * body a handler accidentally produced (#290).
     */
    public static function statusForbidsBody(int $status): bool
    {
        return ($status >= 100 && $status < 200) || $status === 204 || $status === 304;
    }

    /**
     * Look up an IANA reason phrase for the given status code. Used by
     * `emitStatus()` to pass an explicit reason to OpenSwoole's two-arg
     * `$response->status($code, $reason)` — required because the native
     * one-arg form silently rejects codes missing from its internal C
     * list (notably `451`, even on ext 26.x), and the request emits
     * `HTTP 200` instead.
     */
    public static function reasonPhrase(int $status): string
    {
        return self::REASON_PHRASES[$status] ?? '';
    }

    /**
     * Set the response status via OpenSwoole's two-arg form so codes its
     * native list doesn't recognise still emit correctly on the wire.
     * Empty reason → defer to OpenSwoole's default (which has its own
     * built-in phrasing for the common codes).
     */
    public static function emitStatus(\OpenSwoole\HTTP\Response $response, int $status): void
    {
        // #370 — ALWAYS use the two-arg form. OpenSwoole's single-arg
        // status($code) silently flattens any code outside its internal C-side
        // whitelist to 200 OK (it even hits IANA 451 in a raw probe); the
        // two-arg status($code, $reason) emits any in-range code intact. The
        // universal return contract documents 100–599 as "emit as-is", so a
        // non-IANA in-range code (299, nginx 444/499, 599, …) must reach the
        // wire as its numeric code — only the reason phrase is unknown. We
        // synthesize a NON-EMPTY placeholder ('Status N') for those because
        // OpenSwoole ALSO downgrades status($code, '') (empty reason) to 200.
        $reason = self::reasonPhrase($status);
        $response->status($status, $reason !== '' ? $reason : 'Status ' . $status);
    }

    /**
     * Emit the EFFECTIVE response status and return the code that reached the
     * wire. Resolves the raw `header("HTTP/x.x <code> <reason>")` override
     * (#327): when the request carries `RequestContext::$raw_status_code`,
     * that code is emitted — with its verbatim reason when one was given,
     * else the IANA phrase — exactly as Apache mod_php forwards an explicit
     * status line. Without an override this is `emitStatus()` on the PSR
     * status. Callers use the returned code for body-forbidding rules and
     * access logging so they agree with the wire.
     */
    public static function emitEffectiveStatus(\OpenSwoole\HTTP\Response $response, int $psrStatus): int
    {
        $g = RequestContext::instance();
        $raw = $g->raw_status_code;
        if ($raw === null) {
            self::emitStatus($response, $psrStatus);
            return $psrStatus;
        }
        $reason = $g->raw_status_reason ?? self::reasonPhrase($raw);
        if ($reason !== '') {
            $response->status($raw, $reason);
        } else {
            $response->status($raw);
        }
        return $raw;
    }

    /**
     * Stream a `\Generator` response chunk-by-chunk to the live OpenSwoole
     * response (SSR streaming), returning an empty placeholder `Response` once
     * done. Shared by the route dispatcher (`dispatchMatched`) and ZealAPI's
     * `runHandlerWithContract` so both stream identically. HEAD sends headers
     * only (no body). Assumes the caller has already discarded its output buffer.
     *
     * @param \Generator<mixed> $object
     */
    public static function emitGeneratorStream(\Generator $object, string $method): Response
    {
        $g = RequestContext::instance();
        $streamStatus = $g->status ?? 200;
        // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
        App::emitEffectiveStatus($g->openswoole_response, $streamStatus);
        // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
        $g->zealphp_response->header('Accept-Ranges', 'none');
        // HEAD: send headers only, never the streamed body (Apache strips
        // content buckets via ctx->final_header_only). Streaming length is
        // unknown/chunked, so no Content-Length is emitted.
        if ($method === 'HEAD') {
            // RFC 7231 §4.3.2: a HEAD response carries the same header fields a
            // GET would (only the body is omitted). flush() BEFORE end() so the
            // queued headers/cookies — Accept-Ranges, any $response->header()/
            // ->cookie() — actually reach the wire instead of being dropped (#418).
            // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
            $g->zealphp_response->flush();
            // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
            $g->openswoole_response->end();
            return (new Response('', $streamStatus));
        }
        // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
        $g->zealphp_response->flush();
        foreach ($object as $chunk) {
            // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
            if (!$g->openswoole_response->isWritable()) break;
            // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
            $g->openswoole_response->write((string)$chunk);
            // #354 — yield to the scheduler ONLY when inside a coroutine.
            // In mixed mode (enable_coroutine=false) the handler runs outside
            // any coroutine, so Coroutine::sleep(0) throws "API must be called
            // in the coroutine" → uncaught → worker exits status=255 with a
            // truncated, unterminated chunked stream. write() already flushes
            // each chunk; the yield is only a concurrency nicety, and there are
            // no peer coroutines to yield to when enable_coroutine is off.
            if (\OpenSwoole\Coroutine::getCid() > 0) {
                \OpenSwoole\Coroutine::sleep(0);
            }
        }
        // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
        if ($g->openswoole_response->isWritable()) {
            // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
            $g->openswoole_response->end();
        }
        return (new Response('', $streamStatus));
    }

    /**
     * Private constructor — use `App::init()` to obtain the singleton instance.
     *
     * Performs one-time per-process setup: validates that ext-zealphp or uopz is
     * loaded, reads `/etc/environment` into `$_ENV`, captures the initial
     * `error_reporting()` level, installs the process-level native error and
     * exception handlers (which delegate to the per-coroutine `RequestContext`
     * stack), primes `PhpInfo::primeModuleText()`, and calls
     * `registerAllOverrides()` to replace PHP built-ins with ZealPHP's
     * coroutine-safe equivalents.
     *
     * @param string $host Bind address (e.g. `'0.0.0.0'`).
     * @param int    $port TCP port.
     * @param string $cwd  Project root — stored in `App::$cwd`.
     * @throws \Exception when neither ext-zealphp nor uopz is loaded.
     */
    private function __construct(string $host = '0.0.0.0', int $port = 8080, string $cwd = __DIR__)
    {
        if (!extension_loaded('zealphp') && !extension_loaded('uopz')) {
            throw new \Exception(
                "ext-zealphp or uopz is required for ZealPHP. "
                . "Install: 'pie install zealphp/ext' (recommended) "
                . "or build from source: 'cd ext/zealphp && phpize && ./configure && make && sudo make install'. "
                . "Then add extension=zealphp.so to php.ini."
            );
        }
        $this->host = $host;
        $this->port = $port;
        self::$cwd = $cwd;

        //TODO: $_ENV - read from /etc/environment, make this optional?
        $_ENV = [];
        if (file_exists('/etc/environment')) {
            $env = file_get_contents('/etc/environment');
            if ($env === false) { $env = ''; }
            $env = explode("\n", $env);
            foreach ($env as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) {
                    continue;
                }
                list($key, $value) = explode('=', $line, 2);
                $_ENV[$key] = $value;
            }
        }

        // Capture initial error_reporting BEFORE uopz overrides it (else our override
        // would self-recurse trying to read the "native" default).
        self::$initial_error_reporting = \error_reporting();

        // Install ONE process-level native error/exception handler before uopz
        // overrides. After uopz takes over set_error_handler / set_exception_handler,
        // user-space calls store handlers in G (per-coroutine). Real PHP errors
        // raised by the engine still go through THIS native dispatcher, which
        // reads the current coroutine's G stack — giving per-coroutine isolation.
        \set_error_handler(static function (int $severity, string $message, string $file, int $line) {
            // Re-entry guard. If this handler itself raises an error (e.g.,
            // RequestContext::instance() throws, the user callable below
            // re-enters our handler via a nested error), keep returning
            // false so PHP falls back to its native handler instead of
            // recursing through us until the stack blows. The flag lives
            // per-coroutine in RequestContext — set on entry, cleared on
            // exit via a try/finally that survives user-callable throws. */
            try {
                $g = \ZealPHP\RequestContext::instance();
            } catch (\Throwable $e) {
                return false;
            }
            if (!empty($g->_error_handler_in_flight)) {
                return false;
            }
            $g->_error_handler_in_flight = true;
            try {
                $level = $g->error_reporting_level ?? \ZealPHP\App::$initial_error_reporting;
                if (!($severity & $level)) {
                    return true; // suppressed by error_reporting
                }
                $stack = $g->error_handlers_stack;
                if (!empty($stack)) {
                    $top = $stack[count($stack) - 1];
                    [$callable, $levels] = $top;
                    if ($severity & $levels) {
                        try {
                            return (bool)$callable($severity, $message, $file, $line);
                        } catch (\Throwable $e) {
                            // Avoid loops; let PHP default handle if user handler explodes.
                            return false;
                        }
                    }
                }
                return false; // PHP default handler
            } finally {
                $g->_error_handler_in_flight = false;
            }
        });

        \set_exception_handler(static function (\Throwable $e) {
            try {
                $g = \ZealPHP\RequestContext::instance();
            } catch (\Throwable $ce) {
                return; // RequestContext unavailable — let PHP default handle
            }
            if (!empty($g->_exception_handler_in_flight)) {
                return;
            }
            $g->_exception_handler_in_flight = true;
            try {
                $stack = $g->exception_handlers_stack;
                if (!empty($stack)) {
                    try {
                        $stack[count($stack) - 1]($e);
                    } catch (\Throwable $e2) {
                        // swallow
                    }
                }
            } finally {
                $g->_exception_handler_in_flight = false;
            }
        });

        // Capture native phpinfo(INFO_MODULES) text ONCE before overriding phpinfo,
        // so PhpInfo can surface extension-specific detail without recursing into
        // its own override. \phpinfo here is still the original built-in.
        \ob_start();
        \phpinfo(INFO_MODULES);
        \ZealPHP\Diagnostics\PhpInfo::primeModuleText((string) \ob_get_clean());

        self::registerAllOverrides();
    }

    /**
     * Initializes the application.
     *
     * @param string $host The host address to bind to. Defaults to `'0.0.0.0'`.
     * @param int    $port The port number to bind to. Defaults to `8080`.
     * @param string $cwd  The current working directory. Defaults to the directory of the script.
     *
     * @return App
     */
    public static function init($host = '0.0.0.0', $port = 8080, $cwd=null): App
    {
        // ZEALPHP_STORE_BACKEND=redis flips Store + Counter to the Redis backend
        // BEFORE app.php's Store::make() calls run. This was previously in
        // App::run(), which fired AFTER app.php had already made its tables
        // on the (then-default) Table backend — and the flip threw those
        // schemas away. Now the env-var resolves at init() time so make() lands
        // on the right backend the first time. HOOK_ALL hasn't been enabled
        // yet at this point (the enableCoroutine call is below), so the
        // defaultBackend() call is lazy + safe.
        $envKind = getenv('ZEALPHP_STORE_BACKEND');
        if (is_string($envKind) && $envKind !== '') {
            \ZealPHP\Store::defaultBackend($envKind);
            \ZealPHP\Counter::defaultBackend($envKind === 'redis' ? 'redis' : 'atomic');
        }

        // CGI subprocess-pool env overrides (ZEALPHP_CGI_* / ZEALPHP_FCGI_ADDRESS).
        // Applied here in the master before fork; explicit fluent setters still win
        // (resolveCgiEnv only fills knobs that weren't set in code).
        self::resolveCgiEnv();

        if ($cwd === null) {
            $php_self = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 1)[0]['file'] ?? '';
            $file_name = '/'.basename($php_self);
            $cwd = dirname($php_self);
            self::$default_php_self = $file_name;
        }
        if (self::$middleware_stack === null) {
            $stack = (new StackHandler())->add(new ResponseMiddleware());
            assert($stack instanceof StackHandler);
            self::$middleware_stack = $stack;
        }
        if(!App::$superglobals){
            co::set(['hook_flags'=> \OpenSwoole\Runtime::HOOK_ALL]);
            \OpenSwoole\Runtime::enableCoroutine(\OpenSwoole\Runtime::HOOK_ALL);
        }
        if (self::$instance == null) {
            self::$instance = new App($host, $port, $cwd);
        } else {
            elog("App already initialized", "warn");
        }
        return self::$instance;
    }

    /**
     * Throw if a lifecycle setter is being called after `App::run()` has
     * started. The Session-manager class, OpenSwoole's `enable_coroutine`
     * flag, and `HOOK_ALL` are all frozen at boot — mid-game mutation of
     * `$superglobals` etc. leaves the framework in a partial state that
     * races on `$_GET`/`$_POST`/`$_SESSION`. Boot-only is the contract.
     *
     * Called from `superglobals()`, `processIsolation()`, `enableCoroutine()`,
     * and `hookAll()` setters when they receive a write (not a read).
     *
     * @throws \RuntimeException when called after `App::run()` has started.
     */
    private static function refuseAfterRun(string $setter): void
    {
        if (self::$run_has_started) {
            throw new \RuntimeException(
                "ZealPHP lifecycle: $setter() must be called BEFORE App::run(). "
                . 'These four knobs (superglobals, processIsolation, enableCoroutine, hookAll) '
                . 'decide the SessionManager class, the enable_coroutine server setting, and '
                . 'HOOK_ALL — all frozen at run() boot. Mutating them after the server is '
                . 'serving requests leaves the framework in a Schrödinger state (coroutines '
                . 'still active, but per-request handlers now read the new superglobals value) '
                . 'and races on $_GET / $_POST / $_SESSION. Configure these once in app.php '
                . 'before App::run() and leave them alone.'
            );
        }
    }

    /**
     * Install a uopz or ext-zealphp override for a named PHP built-in function,
     * routing calls to the given ZealPHP replacement. Uses `zealphp_override()`
     * when ext-zealphp is loaded (preferred), falling back to `uopz_set_return()`.
     *
     * @param callable-string $callable Fully-qualified ZealPHP replacement function name.
     */
    private static function overrideBuiltin(string $name, string $callable): void
    {
        $cb = \Closure::fromCallable($callable);
        if (\extension_loaded('zealphp') && \function_exists('zealphp_override')) {
            (\zealphp_override(...))($name, $cb);
        } elseif (\function_exists('uopz_set_return')) {
            \uopz_set_return($name, $cb, true);
        }
    }

    /** Guard preventing `registerAllOverrides()` from installing uopz/zealphp built-in overrides more than once per process. */
    private static bool $overridesRegistered = false;

    /**
     * Install all ZealPHP uopz/ext-zealphp built-in overrides in one shot.
     * Idempotent — guarded by `$overridesRegistered` so re-entrant calls
     * (e.g. from tests that re-construct `App`) are no-ops. Covers response
     * headers (`header()`, `setcookie()`, etc.), session functions
     * (`session_start()` family), output control (`flush()`, `ob_*`),
     * error handling (`set_error_handler()`, `error_log()`), and more.
     * Called once from `__construct()`.
     */
    private static function registerAllOverrides(): void
    {
        if (self::$overridesRegistered) {
            return;
        }
        self::$overridesRegistered = true;

        // #338 — fatal→500 guard. MUST use PHP's NATIVE
        // register_shutdown_function and MUST run before the override below
        // replaces it globally (uopz/ext-zealphp overrides intercept even
        // fully-qualified calls). Registered once in the master pre-fork;
        // forked workers inherit the registration (BG(user_shutdown_function
        // _names) copies on fork) and run it during THEIR engine shutdown —
        // including the shutdown a fatal error triggers.
        \register_shutdown_function([self::class, 'fatalResponseGuard']);

        // Response
        self::overrideBuiltin('header', '\ZealPHP\header');
        self::overrideBuiltin('header_remove', '\ZealPHP\header_remove');
        self::overrideBuiltin('headers_list', '\ZealPHP\headers_list');
        self::overrideBuiltin('headers_sent', '\ZealPHP\headers_sent');
        self::overrideBuiltin('setcookie', '\ZealPHP\setcookie');
        self::overrideBuiltin('setrawcookie', '\ZealPHP\setrawcookie');
        self::overrideBuiltin('http_response_code', '\ZealPHP\http_response_code');
        // Output control
        self::overrideBuiltin('flush', '\ZealPHP\flush');
        self::overrideBuiltin('ob_flush', '\ZealPHP\ob_flush');
        self::overrideBuiltin('ob_end_flush', '\ZealPHP\ob_end_flush');
        self::overrideBuiltin('ob_implicit_flush', '\ZealPHP\ob_implicit_flush');
        self::overrideBuiltin('output_add_rewrite_var', '\ZealPHP\output_add_rewrite_var');
        self::overrideBuiltin('output_reset_rewrite_vars', '\ZealPHP\output_reset_rewrite_vars');
        // Process/connection
        self::overrideBuiltin('set_time_limit', '\ZealPHP\set_time_limit');
        self::overrideBuiltin('ignore_user_abort', '\ZealPHP\ignore_user_abort');
        self::overrideBuiltin('connection_status', '\ZealPHP\connection_status');
        self::overrideBuiltin('connection_aborted', '\ZealPHP\connection_aborted');
        self::overrideBuiltin('register_shutdown_function', '\ZealPHP\register_shutdown_function');
        // File upload
        self::overrideBuiltin('is_uploaded_file', '\ZealPHP\is_uploaded_file');
        self::overrideBuiltin('move_uploaded_file', '\ZealPHP\move_uploaded_file');
        // Info
        self::overrideBuiltin('phpinfo', '\ZealPHP\phpinfo');
        self::overrideBuiltin('php_sapi_name', '\ZealPHP\php_sapi_name');
        // Input filtering
        self::overrideBuiltin('filter_input', '\ZealPHP\filter_input');
        self::overrideBuiltin('filter_input_array', '\ZealPHP\filter_input_array');
        self::overrideBuiltin('header_register_callback', '\ZealPHP\header_register_callback');
        // Error handling
        self::overrideBuiltin('error_log', '\ZealPHP\error_log');
        self::overrideBuiltin('error_reporting', '\ZealPHP\error_reporting');
        self::overrideBuiltin('set_error_handler', '\ZealPHP\set_error_handler');
        self::overrideBuiltin('restore_error_handler', '\ZealPHP\restore_error_handler');
        self::overrideBuiltin('set_exception_handler', '\ZealPHP\set_exception_handler');
        self::overrideBuiltin('restore_exception_handler', '\ZealPHP\restore_exception_handler');
        // Session (Apache-only built-ins registered via src/apache_shims.php)
        self::overrideBuiltin('session_start', '\ZealPHP\Session\zeal_session_start');
        self::overrideBuiltin('session_id', '\ZealPHP\Session\zeal_session_id');
        self::overrideBuiltin('session_status', '\ZealPHP\Session\zeal_session_status');
        self::overrideBuiltin('session_name', '\ZealPHP\Session\zeal_session_name');
        self::overrideBuiltin('session_write_close', '\ZealPHP\Session\zeal_session_write_close');
        self::overrideBuiltin('session_destroy', '\ZealPHP\Session\zeal_session_destroy');
        self::overrideBuiltin('session_unset', '\ZealPHP\Session\zeal_session_unset');
        self::overrideBuiltin('session_regenerate_id', '\ZealPHP\Session\zeal_session_regenerate_id');
        self::overrideBuiltin('session_get_cookie_params', '\ZealPHP\Session\zeal_session_get_cookie_params');
        self::overrideBuiltin('session_set_cookie_params', '\ZealPHP\Session\zeal_session_set_cookie_params');
        self::overrideBuiltin('session_cache_limiter', '\ZealPHP\Session\zeal_session_cache_limiter');
        self::overrideBuiltin('session_cache_expire', '\ZealPHP\Session\zeal_session_cache_expire');
        self::overrideBuiltin('session_commit', '\ZealPHP\Session\zeal_session_commit');
        self::overrideBuiltin('session_abort', '\ZealPHP\Session\zeal_session_abort');
        self::overrideBuiltin('session_encode', '\ZealPHP\Session\zeal_session_encode');
        self::overrideBuiltin('session_decode', '\ZealPHP\Session\zeal_session_decode');
        self::overrideBuiltin('session_save_path', '\ZealPHP\Session\zeal_session_save_path');
        self::overrideBuiltin('session_module_name', '\ZealPHP\Session\zeal_session_module_name');
        // #295 — without this override, native session_set_save_handler() registers
        // with PHP's session module, which the zeal_session_* overrides never consult,
        // so a custom handler (Redis) was silently ignored and sessions fell back to
        // the inline file path. Route it to $g->session_params['handler'] + App::$session_handler.
        self::overrideBuiltin('session_set_save_handler', '\ZealPHP\Session\zeal_session_set_save_handler');
    }

    /**
     * Toggle the superglobals-mode lifecycle. See `App::$superglobals` for the full
     * semantics. Must be called BEFORE `App::run()` — the method calls `refuseAfterRun()`
     * and throws `\RuntimeException` if the server is already serving requests.
     */
    public static function superglobals(bool $enable = true): void
    {
        self::refuseAfterRun('App::superglobals');
        self::$superglobals = $enable;
    }

    /**
     * Toggle the coroutine-safe exec family hook (backtick / shell_exec / exec /
     * system / passthru). Pass null (or no arg) to read the current value; pass a
     * non-null value to set and return it. null = auto = follow coroutine mode
     * (resolves to `self::$superglobals === false`) at run() time; overriding
     * these built-ins routes them through coroutine-safe equivalents.
     */
    public static function hookExec(?bool $value = null): ?bool
    {
        if ($value !== null) {
            self::$hook_exec = $value;
        }
        return self::$hook_exec;
    }

    /**
     * Toggle the ext-zealphp `exit()`/`die()` → `ZealPHP\HaltException`
     * interception (ext#47). Pass null (or no arg) to read; non-null to set.
     * null = auto = follow the coroutine scheduler (on when `enableCoroutine`
     * is effective) at `App::run()`. See `App::$hook_exit`.
     */
    public static function hookExit(?bool $value = null): ?bool
    {
        if ($value !== null) {
            self::$hook_exit = $value;
        }
        return self::$hook_exit;
    }

    // -----------------------------------------------------------------------
    // Fluent configuration accessors. The convention: pass null (or no arg)
    // to read the current value; pass a non-null value to set and return it.
    // Backing static properties stay public for BC — these methods are the
    // documented API surface and what the converter bot is taught to emit.
    // -----------------------------------------------------------------------

    /**
     * Whether URLs ending in `.php` are blocked with `403`. Default `true` (Apache
     * `RewriteRule \.php$ - [F]` parity). Set `false` to allow direct `*.php` routing.
     * No-arg call returns the current value.
     */
    public static function ignorePhpExt(?bool $on = null): bool
    {
        if ($on !== null) self::$ignore_php_ext = $on;
        return self::$ignore_php_ext;
    }

    /**
     * Whether ZealAPI logs a warning when a filename collides with an HTTP method keyword
     * (e.g. `get.php` defining `$get`) or a filename-matched handler shadows per-method
     * handlers. Default `true`. No-arg call returns the current value.
     */
    public static function apiWarnCollisions(?bool $on = null): bool
    {
        if ($on !== null) self::$api_warn_collisions = $on;
        return self::$api_warn_collisions;
    }

    /**
     * #347 — whether a ZealAPI handler returning `null` with no output, no
     * explicit status and no streaming yields the Apache-parity
     * `404 {"error":"method_not_found"}` envelope instead of `200` + empty
     * body. Default `true`. No-arg call returns the current value.
     */
    public static function apiNullNotFound(?bool $on = null): bool
    {
        if ($on !== null) self::$api_null_not_found = $on;
        return self::$api_null_not_found;
    }

    /**
     * Apache `DirectorySlash` — redirect `/foo` → `/foo/` when `foo` is a directory.
     * Default `true`. No-arg call returns the current value.
     */
    public static function directorySlash(?bool $on = null): bool
    {
        if ($on !== null) self::$directory_slash = $on;
        return self::$directory_slash;
    }

    /**
     * @param array<int, string>|null $files
     * @return array<int, string>
     */
    public static function directoryIndex(?array $files = null): array
    {
        if ($files !== null) self::$directory_index = $files;
        return self::$directory_index;
    }

    /**
     * Apache `PATH_INFO` — expose the path suffix after a script name as `PATH_INFO`
     * in `$_SERVER` (e.g. `/script.php/extra/path` → `PATH_INFO=/extra/path`).
     * Default `true`. No-arg call returns the current value.
     */
    public static function pathInfo(?bool $on = null): bool
    {
        if ($on !== null) self::$path_info = $on;
        return self::$path_info;
    }

    /**
     * URL-prefix whitelist for static-file serving. Empty array (default) allows
     * any path under `document_root`. When non-empty, only paths whose prefix
     * matches one of the listed strings are served as static files; others fall
     * through to route matching. No-arg call returns the current list.
     *
     * @param array<int, string>|null $prefixes
     * @return array<int, string>
     */
    public static function staticHandlerLocations(?array $prefixes = null): array
    {
        if ($prefixes !== null) self::$static_handler_locations = $prefixes;
        return self::$static_handler_locations;
    }

    /**
     * Compose `$_REQUEST` from the GET and POST bags per PHP's default
     * `request_order='GP'` (#356).
     *
     * PHP merges the sources left-to-right with LATER sources overwriting
     * earlier ones, so for `'GP'` (GET first, POST second) a key present in both
     * takes the POST value — the form-submission-overrides-querystring
     * convention. PHP's `+` array-union keeps the LEFT operand on a collision,
     * so the POST-wins composition is `$post + $get`. COOKIE is deliberately
     * excluded (matches PHP's `'GP'`, which omits C). The single source of truth
     * for both the OnRequest populate and the CGI-context request builder.
     *
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function composeRequestArray(array $get, array $post): array
    {
        return $post + $get;
    }

    /**
     * The built-in default `static_handler_locations` — DIRECTORY entries only,
     * every one trailing-slash terminated so OpenSwoole's raw string-prefix
     * match is segment-bounded (a bare `/js` would steal `/json`).
     *
     * #367 — FILE entries (`/favicon.ico`, `/robots.txt`) are deliberately
     * EXCLUDED: a file can't take a trailing slash, so OpenSwoole's prefix match
     * over-reaches (`/favicon.ico` steals `/favicon.icoX`, shadowing a user route
     * like `/robots.txt-generator`). favicon.ico + robots.txt are served as
     * ordinary `public/` files by the framework's implicit file routes instead.
     * Used as the default when the app hasn't set `App::staticHandlerLocations()`.
     *
     * @return list<string>
     */
    public static function defaultStaticHandlerLocations(): array
    {
        return ['/css/', '/js/', '/img/', '/images/', '/fonts/', '/assets/', '/static/'];
    }

    /**
     * Block any request whose path contains a dotfile component (`.git`, `.env`,
     * `.htaccess`, etc.) with `403`. Default `true` — matches Apache's convention
     * of not serving hidden files. No-arg call returns the current value.
     */
    public static function blockDotfiles(?bool $on = null): bool
    {
        if ($on !== null) self::$block_dotfiles = $on;
        return self::$block_dotfiles;
    }

    /**
     * Whether framework error pages render the captured exception and stack
     * trace inline. Secure-by-default (#412): a one-arg call sets the value
     * explicitly (and wins forever after); a no-arg call returns the resolved
     * value — when never set explicitly, `null` falls back to the `ZEALPHP_DEV`
     * env var, so production (env unset) returns `false` and never leaks traces.
     */
    public static function displayErrors(?bool $on = null): bool
    {
        if ($on !== null) self::$display_errors = $on;
        if (self::$display_errors !== null) {
            return self::$display_errors;
        }
        $env = getenv('ZEALPHP_DEV');
        return $env !== false && $env !== '' && $env !== '0';
    }

    /**
     * Apache `DocumentRoot` equivalent. Relative path → resolved against `cwd`;
     * absolute path → used as-is. Drives `App::include()` resolution and the
     * implicit-route file lookups.
     */
    public static function documentRoot(?string $path = null): string
    {
        if ($path !== null) self::$document_root = $path;
        return self::$document_root;
    }

    /** Apache `TraceEnable`. Default OFF for security (XST attack vector). */
    public static function traceEnabled(?bool $on = null): bool
    {
        if ($on !== null) self::$trace_enabled = $on;
        return self::$trace_enabled;
    }

    /** Apache `AddDefaultCharset`. Server-wide default. */
    public static function defaultCharset(?string $charset = null): string
    {
        if ($charset !== null) self::$default_charset = $charset;
        return self::$default_charset;
    }

    /**
     * Apache `DefaultType` / PHP `default_mimetype`. The `Content-Type`
     * `CharsetMiddleware` applies to responses that don't set one. Pass `''` to
     * disable. No-arg call returns the current value.
     */
    public static function defaultMimeType(?string $type = null): string
    {
        if ($type !== null) self::$default_mimetype = $type;
        return self::$default_mimetype;
    }

    /**
     * Apache `ServerTokens`. Controls the `X-Powered-By` header detail.
     * No-arg call returns the current setting. See `App::$server_tokens`.
     */
    public static function serverTokens(?string $tokens = null): string
    {
        if ($tokens !== null) self::$server_tokens = $tokens;
        return self::$server_tokens;
    }

    /**
     * Apache `FileETag`. false ⇒ `ETagMiddleware` emits no `ETag` and never `304`s
     * (`FileETag None`). No-arg call returns the current value.
     */
    public static function fileETag(?bool $enabled = null): bool
    {
        if ($enabled !== null) self::$file_etag = $enabled;
        return self::$file_etag;
    }

    /**
     * Resolve the `X-Powered-By` header value for the current `ServerTokens`
     * setting, or null when the header should be omitted. Consumed at the
     * response-emission boundary; exposed for introspection/testing.
     */
    public static function poweredByHeader(): ?string
    {
        return match (strtolower(self::$server_tokens)) {
            'none', '' => null,
            'full'     => 'ZealPHP + OpenSwoole',
            default    => 'ZealPHP',
        };
    }

    /**
     * mod_php-parity SAPI name reported by the `php_sapi_name()` override.
     * No-arg call returns the current setting (`null` = report real `PHP_SAPI`);
     * one-arg call opts in to a web SAPI string for legacy-app compatibility.
     */
    public static function sapiName(?string $name = null): ?string
    {
        if ($name !== null) self::$sapi_name = $name;
        return self::$sapi_name;
    }

    /**
     * Detect whether the request arrived over TLS, for deriving `REQUEST_SCHEME` /
     * `HTTPS` in the `$_SERVER` builder. Mirrors the session-cookie secure detection
     * (`src/Session/utils.php`): a direct `HTTPS=on`, an `X-Forwarded-Proto: https` from
     * a proxy, or `SERVER_PORT 443`.
     *
     * @param array<string, mixed> $srv
     */
    /**
     * Whether the request arrived over HTTPS. `X-Forwarded-Proto` is honoured
     * ONLY when the immediate peer (`REMOTE_ADDR`) is a configured trusted
     * proxy — parity with `App::clientIp()`. Public so the session layer
     * (`zeal_session_start`'s Secure-cookie auto-detect) shares this one gated
     * source of truth instead of trusting the header from any client.
     *
     * @param array<string, mixed> $srv
     */
    public static function requestIsHttps(array $srv): bool
    {
        $https = $srv['HTTPS'] ?? '';
        if (is_scalar($https) && strtolower((string)$https) === 'on') {
            return true;
        }
        // X-Forwarded-Proto is only honoured when the immediate peer
        // (REMOTE_ADDR) is a configured trusted proxy — parity with
        // App::clientIp()'s X-Forwarded-For gating. Trusting it from ANY client
        // lets an attacker flip the framework's HTTPS determination with one
        // header (Secure-cookie on a plaintext listener → browser drops it;
        // legacy code branching on $_SERVER['HTTPS'] fooled). With no
        // $trusted_proxies configured the header is ignored entirely.
        $proto = $srv['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (is_scalar($proto) && strtolower((string)$proto) === 'https') {
            $remoteRaw = $srv['REMOTE_ADDR'] ?? '';
            $remote = is_scalar($remoteRaw) ? (string)$remoteRaw : '';
            if ($remote !== '' && self::$trusted_proxies !== [] && self::peerInTrustedProxies($remote)) {
                return true;
            }
        }
        $port = $srv['SERVER_PORT'] ?? '';
        return is_scalar($port) && (string)$port === '443';
    }

    /**
     * The static CGI/SAPI server vars mod_php exposes even OUTSIDE a request
     * (PHP_SELF, SCRIPT_NAME, SCRIPT_FILENAME, REQUEST_URI, DOCUMENT_ROOT).
     * Seeded into `$g->server` at worker start so app bootstrap that reads them
     * at class-load — before the first request populates the real per-request
     * values via {@see buildServerVars()} — doesn't hit "Undefined array key"
     * (#270). buildServerVars() overlays the real per-request values on top.
     *
     * @return array<string, string>
     */
    private static function baseServerVars(): array
    {
        $docRoot = self::resolveDocumentRoot();
        $phpSelf = (string)(self::$default_php_self ?? '');
        if ($phpSelf === '') {
            $phpSelf = '/app.php';
        }
        return [
            'REQUEST_METHOD'  => 'GET',
            'REQUEST_URI'     => '/',
            'SCRIPT_NAME'     => $phpSelf,
            'PHP_SELF'        => $phpSelf,
            'DOCUMENT_ROOT'   => $docRoot,
            'SCRIPT_FILENAME' => $docRoot . $phpSelf,
            'SERVER_SOFTWARE' => 'ZealPHP/dev (' . php_uname('s') . ') PHP/' . phpversion(),
            'SERVER_NAME'     => site_host(),
        ];
    }

    /**
     * Transpose OpenSwoole's `$request->files` into PHP/mod_php-canonical
     * `$_FILES` (issue #304).
     *
     * OpenSwoole delivers a repeated/array file field (`files[]`) in
     * **index-major** shape — `['files' => [0 => ['name'=>…,'tmp_name'=>…,…],
     * 1 => […]]]` — while PHP's RFC 1867 parser publishes the **field-major**
     * shape every PHP app codes against:
     * `['files' => ['name'=>[0=>…,1=>…], 'type'=>[…], 'tmp_name'=>[…],
     * 'error'=>[…], 'size'=>[…], 'full_path'=>[…]]]`. A SINGLE file field stays
     * flat but gains the PHP 8.1+ `full_path` key (defaulting to `name` when
     * OpenSwoole doesn't surface it). Nested names (`doc[main]`) are transposed
     * recursively so the per-key sub-array mirrors the field structure.
     *
     * Pure function — no side effects; safe to unit-test directly.
     *
     * @param array<array-key, mixed> $files OpenSwoole's `$request->files`.
     * @return array<string, mixed> Field-major `$_FILES`-shaped tree.
     */
    public static function normalizeUploadedFiles(array $files): array
    {
        /** @var array<string, mixed> $out */
        $out = [];
        foreach ($files as $field => $entry) {
            $field = (string)$field;
            if (!is_array($entry)) {
                // Not a file struct — pass through untouched.
                $out[$field] = $entry;
                continue;
            }
            if (self::fileEntryIsSingle($entry)) {
                $out[$field] = self::normalizeSingleFileEntry($entry);
                continue;
            }
            // Array/nested field — index-major list of file structs (or nested
            // sub-arrays of them). Transpose to field-major.
            $out[$field] = self::transposeFileGroup($entry);
        }
        return $out;
    }

    /**
     * True when `$entry` is a single PHP file struct (has a scalar/array
     * `tmp_name` AND `error` directly on it), rather than an index/name-keyed
     * group of nested file structs.
     *
     * @param array<array-key, mixed> $entry
     */
    private static function fileEntryIsSingle(array $entry): bool
    {
        return array_key_exists('tmp_name', $entry) && array_key_exists('error', $entry);
    }

    /**
     * Normalise one flat OpenSwoole file struct to PHP canonical shape, adding
     * the PHP 8.1+ `full_path` key when absent (value = `name`).
     *
     * @param array<array-key, mixed> $entry
     * @return array<string, mixed>
     */
    private static function normalizeSingleFileEntry(array $entry): array
    {
        $name = $entry['name'] ?? '';
        /** @var array<string, mixed> $out */
        $out = [
            'name'     => $name,
            'type'     => $entry['type'] ?? '',
            'tmp_name' => $entry['tmp_name'] ?? '',
            'error'    => $entry['error'] ?? UPLOAD_ERR_OK,
            'size'     => $entry['size'] ?? 0,
        ];
        $out['full_path'] = array_key_exists('full_path', $entry) ? $entry['full_path'] : $name;
        return $out;
    }

    /**
     * Transpose an index-major group of file structs (the OpenSwoole shape for
     * `files[]` / `doc[main]`) into PHP's field-major layout. Recurses through
     * nested name groups so `['main' => <struct>, 'thumb' => <struct>]` yields
     * `['name'=>['main'=>…,'thumb'=>…], 'tmp_name'=>[…], …]`.
     *
     * @param array<array-key, mixed> $group Index/name-keyed file structs.
     * @return array<string, array<array-key, mixed>>
     */
    private static function transposeFileGroup(array $group): array
    {
        $keys = ['name', 'type', 'tmp_name', 'error', 'size', 'full_path'];
        /** @var array<string, array<array-key, mixed>> $out */
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = [];
        }
        foreach ($group as $idx => $child) {
            if (!is_array($child)) {
                continue;
            }
            if (self::fileEntryIsSingle($child)) {
                $norm = self::normalizeSingleFileEntry($child);
                foreach ($keys as $k) {
                    $out[$k][$idx] = $norm[$k];
                }
            } else {
                // A deeper nesting level — recurse and graft the per-key trees.
                $nested = self::transposeFileGroup($child);
                foreach ($keys as $k) {
                    $out[$k][$idx] = $nested[$k];
                }
            }
        }
        return $out;
    }

    /**
     * Parse a raw `Cookie:` header through PHP's cookie treat-data semantics
     * (issue #305) — the same `php_default_treat_data` routine PHP applies to
     * the query string, but with the cookie value-decoding rule:
     *
     *  - Pairs split on `;`; each pair split on its FIRST `=`. The name is
     *    left-trimmed; the value is everything after the first `=`.
     *  - Cookie NAMES get legacy mangling — leading whitespace stripped, `.`
     *    and space → `_`, and `name[]` / `name[k]` build nested arrays.
     *  - Cookie VALUES are `%XX`-decoded per RFC 6265 ONLY — a literal `+` is
     *    NOT turned into a space (that `+`→space rule is form-urlencoded-only).
     *
     * Implementation re-uses PHP's `parse_str()` for the bracket-nesting +
     * name-mangling, protecting each value's `+` / `&` / `=` so the assembled
     * query survives the urldecode parse_str applies.
     *
     * Pure function — no side effects; safe to unit-test directly.
     *
     * @return array<string, mixed> PHP-canonical `$_COOKIE` tree.
     */
    public static function parseCookieHeader(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $assembled = [];
        foreach (explode(';', $raw) as $pair) {
            if ($pair === '' || !str_contains($pair, '=')) {
                // A bare `; flag` cookie segment with no `=` has no value to
                // bind; PHP skips it.
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            $name = ltrim($name);
            if ($name === '') {
                continue;
            }
            // Protect the value: parse_str urldecodes (turning `+`→space and
            // honouring `&`/`=` as separators). Pre-encode those three so the
            // value round-trips verbatim except for the cookie's own %XX, which
            // parse_str's urldecode resolves — matching RFC 6265 value decoding.
            $safeValue = str_replace(['+', '&', '='], ['%2B', '%26', '%3D'], $value);
            $assembled[] = $name . '=' . $safeValue;
        }
        if ($assembled === []) {
            return [];
        }
        /** @var array<array-key, mixed> $parsed */
        $parsed = [];
        parse_str(implode('&', $assembled), $parsed);
        // Cookie names are always strings; normalise the key type so the
        // declared array<string, mixed> contract holds (parse_str's signature
        // is array-key-typed because numeric-string keys collapse to int).
        $out = [];
        foreach ($parsed as $name => $value) {
            $out[(string)$name] = $value;
        }
        return $out;
    }

    /**
     * mod_php-canonical cookie map for a request (issue #305).
     *
     * `App::run()` sets OpenSwoole's `http_parse_cookie => false` so OpenSwoole
     * no longer parses cookies itself (its parser diverges from PHP — no array
     * syntax, no `.`→`_` mangling, and a wrong `+`→space value decode). Instead
     * it leaves the raw `Cookie:` header in `$request->header['cookie']` and its
     * own `$request->cookie` empty. This parses that raw header through
     * {@see parseCookieHeader()} and WRITES THE RESULT BACK onto
     * `$request->cookie`, so every consumer — the superglobal populate, both
     * session managers' `PHPSESSID` lookup, WebSocket `onOpen`, and user handlers
     * reading `$request->cookie` — sees the SAME PHP-canonical map.
     *
     * Falls back to OpenSwoole's pre-parsed `$request->cookie` when there's no
     * raw header (e.g. `http_parse_cookie` left on, or no Cookie sent). The
     * write-back is idempotent — re-parsing the unchanged header is a no-op.
     *
     * @return array<string, mixed>
     */
    public static function requestCookieMap(\OpenSwoole\Http\Request $request): array
    {
        $header = $request->header;
        if (is_array($header)
            && isset($header['cookie'])
            && is_string($header['cookie'])
            && $header['cookie'] !== ''
        ) {
            $parsed = self::parseCookieHeader($header['cookie']);
            $request->cookie = $parsed; // write-back so $request->cookie readers agree
            return $parsed;
        }
        $existing = $request->cookie;
        if (!is_array($existing)) {
            return [];
        }
        // Normalise keys to string so the array<string, mixed> contract holds
        // (OpenSwoole types $request->cookie as mixed; cookie names are strings).
        $out = [];
        foreach ($existing as $name => $value) {
            $out[(string)$name] = $value;
        }
        return $out;
    }

    /**
     * Re-assert a session id the session manager already rotated, over a
     * freshly-parsed request-cookie map (#371, CWE-384 session fixation).
     *
     * The session manager (`CoSessionManager`/`SessionManager`) runs BEFORE
     * the OnRequest superglobal populate. On a forged / strict-mode-rejected
     * id it mints a fresh server id and records it in
     * `session_params['session_id']` (+ emits the rotated `Set-Cookie`). The
     * populate then re-parses the RAW request cookie — which still carries the
     * **forged** id — and writes it into `$g->cookie`, so a handler's
     * `session_start()` → `zeal_session_id()` would read the forged id and
     * persist the session under the attacker's value. This re-asserts the
     * manager's rotated id into the cookie map whenever it differs from the
     * raw value (the only thing that makes them differ is a manager rotation,
     * including the first-visit mint where the raw cookie is absent).
     *
     * @param array<string, mixed> $cookie freshly-parsed request cookie map
     * @return array<string, mixed>
     */
    public static function reassertRotatedSessionId(array $cookie): array
    {
        $g = RequestContext::instance();
        $params = $g->session_params;
        $name = $params['name'] ?? 'PHPSESSID';
        $rotated = $params['session_id'] ?? null;
        if (is_string($name) && $name !== '' && is_string($rotated) && $rotated !== ''
            && ($cookie[$name] ?? null) !== $rotated
        ) {
            $cookie[$name] = $rotated;
        }
        return $cookie;
    }

    /**
     * Synthesize the mod_php request-surface `$_SERVER` vars that OpenSwoole's
     * raw `$request->server` omits or gets wrong (issue #306 + #307). Pure
     * transform of an already-built server array — operates on the
     * upper-cased keys `buildServerVars()` produces, so it is unit-testable in
     * isolation:
     *
     *  - `QUERY_STRING`: always present; `''` when the request has no query.
     *  - `REQUEST_URI`: full mod_php value — the original request target including
     *    the query string (#306). OpenSwoole delivers a path-only request_uri, so
     *    the query is re-appended here; the dispatch layer matches routes on a
     *    parse_url(PATH) of it, so carrying the query is safe.
     *  - `CONTENT_TYPE` / `CONTENT_LENGTH`: mirrored from the request body
     *    headers (`HTTP_CONTENT_TYPE` / `HTTP_CONTENT_LENGTH`) when present.
     *  - HTTP Basic/Digest auth (#307): `Authorization: Basic <b64>` decodes to
     *    `PHP_AUTH_USER` / `PHP_AUTH_PW`; `Digest` publishes `PHP_AUTH_DIGEST`.
     *    `AUTH_TYPE` is deliberately NOT set — mod_php only publishes it when an
     *    Apache auth module handles the request. `HTTP_AUTHORIZATION` is kept
     *    (Bearer flows rely on it).
     *
     * PATH_INFO / PHP_SELF are NOT computed here — they depend on the matched
     * `.php` script and are set where the script resolves (`App::include()` /
     * the ResponseMiddleware PATH_INFO rewrite).
     *
     * @param array<string, bool|float|int|string|null> $srv
     * @return array<string, bool|float|int|string|null>
     */
    public static function synthesizeRequestServerVars(array $srv): array
    {
        // QUERY_STRING — always defined; default to '' when absent (mod_php
        // always publishes it).
        $query = isset($srv['QUERY_STRING']) ? (string)$srv['QUERY_STRING'] : '';
        $srv['QUERY_STRING'] = $query;

        // REQUEST_URI — full mod_php value = original request target incl. the
        // query string (#306). OpenSwoole delivers a path-only request_uri plus a
        // separate query_string, so append `?QUERY_STRING` when there's a query and
        // REQUEST_URI doesn't already carry one (the strpos guard makes this a
        // no-op if a future/other OpenSwoole build embeds the query). The dispatch
        // layer matches routes on a parse_url(PATH) of REQUEST_URI, so carrying the
        // query here is safe — see ResponseMiddleware::matchAndDispatch().
        if ($query !== ''
            && isset($srv['REQUEST_URI'])
            && is_string($srv['REQUEST_URI'])
            && $srv['REQUEST_URI'] !== ''
            && strpos($srv['REQUEST_URI'], '?') === false
        ) {
            $srv['REQUEST_URI'] = $srv['REQUEST_URI'] . '?' . $query;
        }

        // SERVER_PORT / REMOTE_PORT — CGI/1.1 meta-variables are strings
        // (RFC 3875 §4.1): mod_php publishes e.g. '443', but OpenSwoole's
        // $request->server carries both as PHP ints, so the common strict
        // `$_SERVER['SERVER_PORT'] === '443'` comparison silently fails
        // (#306). Cast both to the mod_php string form. REQUEST_TIME(_FLOAT)
        // stay int/float — mod_php itself publishes those numerically.
        if (isset($srv['SERVER_PORT'])) {
            $srv['SERVER_PORT'] = (string)$srv['SERVER_PORT'];
        }
        if (isset($srv['REMOTE_PORT'])) {
            $srv['REMOTE_PORT'] = (string)$srv['REMOTE_PORT'];
        }

        // CONTENT_TYPE / CONTENT_LENGTH — bare CGI vars mod_php sets from the
        // body headers (in addition to the HTTP_* copies buildServerVars made).
        if (isset($srv['HTTP_CONTENT_TYPE']) && !isset($srv['CONTENT_TYPE'])) {
            $srv['CONTENT_TYPE'] = $srv['HTTP_CONTENT_TYPE'];
        }
        if (isset($srv['HTTP_CONTENT_LENGTH']) && !isset($srv['CONTENT_LENGTH'])) {
            $srv['CONTENT_LENGTH'] = $srv['HTTP_CONTENT_LENGTH'];
        }

        // HTTP Basic / Digest auth → PHP SAPI vars (#307). AUTH_TYPE is NOT set:
        // mod_php only publishes it when an Apache auth module handles the
        // request, so with the credentials merely carried in the Authorization
        // header (no auth module) Apache leaves AUTH_TYPE unset — match that.
        if (isset($srv['HTTP_AUTHORIZATION'])) {
            $auth = (string)$srv['HTTP_AUTHORIZATION'];
            if (stripos($auth, 'Basic ') === 0) {
                $decoded = base64_decode(substr($auth, 6), true);
                if (is_string($decoded) && str_contains($decoded, ':')) {
                    [$user, $pw] = explode(':', $decoded, 2);
                    $srv['PHP_AUTH_USER'] = $user;
                    $srv['PHP_AUTH_PW']   = $pw;
                }
            } elseif (stripos($auth, 'Digest ') === 0) {
                $srv['PHP_AUTH_DIGEST'] = substr($auth, 7);
            }
        }

        return $srv;
    }

    /**
     * Build the per-request `$_SERVER` array from an OpenSwoole request —
     * mod_php parity. Merges `$request->server` (upper-cased keys), the
     * `HTTP_*` header vars, and the constant CGI keys mod_php always provides
     * (GATEWAY_INTERFACE, REQUEST_SCHEME, SCRIPT_FILENAME, SERVER_SOFTWARE, …).
     *
     * Single source of truth for `$_SERVER`: shared by the OnRequest populate
     * path AND `rebindRequestInput()`, so both produce a byte-identical server
     * array. Pure function of the request — no side effects, safe to call
     * repeatedly within one request (the coroutine-legacy re-assert does).
     *
     * @return array<string, bool|float|int|string|null>
     */
    private static function buildServerVars(\ZealPHP\HTTP\Request $request): array
    {
        /** @var string|null $serverSoftware */
        static $serverSoftware = null;
        if ($serverSoftware === null) {
            $serverSoftware = 'ZealPHP/dev (' . php_uname('s') . ') PHP/' . phpversion();
        }

        // Build $_SERVER — upper-case OpenSwoole's lower-cased keys. OpenSwoole's
        // $request->server is typed mixed-valued; CGI server vars are always
        // scalars, so coerce defensively to keep $_SERVER scalar|null (no nested
        // arrays/objects) — matching RequestContext::$server's contract.
        /** @var array<string, bool|float|int|string|null> $srv */
        $srv = [];
        if ($request->server) {
            foreach ($request->server as $sk => $sv) {
                // #306 — OpenSwoole's `path_info` is the WHOLE request path, but
                // mod_php's PATH_INFO is ONLY the suffix after the script (unset
                // when none). Don't let it pass through as $_SERVER['PATH_INFO'];
                // the ResponseMiddleware `.php/extra` rewrite sets it correctly
                // for the path-info case.
                if ($sk === 'path_info') {
                    continue;
                }
                $srv[strtoupper($sk)] = is_scalar($sv) || $sv === null ? $sv : null;
            }
        }
        // SERVER_ADDR — mod_php always publishes the accepting interface IP;
        // OpenSwoole's $request->server has no equivalent key, so synthesize
        // it from the bound listen address (#306). A wildcard bind
        // (0.0.0.0 / ::) resolves to the host's primary address once per
        // worker (OpenSwoole doesn't expose the connection's local address);
        // a hostname bind resolves the same way. Best-effort fallback is the
        // loopback so the key is never absent (Undefined-array-key in vhost/
        // multi-homed app code).
        if (!isset($srv['SERVER_ADDR'])) {
            /** @var string|null $serverAddr */
            static $serverAddr = null;
            if ($serverAddr === null) {
                $bind = self::$instance !== null ? self::$instance->host : '';
                if ($bind === '' || $bind === '0.0.0.0' || $bind === '::') {
                    $bind = (string)gethostname();
                }
                if (filter_var($bind, FILTER_VALIDATE_IP)) {
                    $resolved = $bind;
                } else {
                    // A first via the system resolver (/etc/hosts-aware;
                    // returns the input unchanged on failure), then AAAA so
                    // an IPv6-only host still resolves.
                    $resolved = gethostbyname($bind);
                    if (!filter_var($resolved, FILTER_VALIDATE_IP)) {
                        $aaaa = dns_get_record($bind, DNS_AAAA);
                        if (is_array($aaaa) && isset($aaaa[0]['ipv6']) && is_string($aaaa[0]['ipv6'])) {
                            $resolved = $aaaa[0]['ipv6'];
                        }
                    }
                }
                $serverAddr = filter_var($resolved, FILTER_VALIDATE_IP) ? $resolved : '127.0.0.1';
            }
            $srv['SERVER_ADDR'] = $serverAddr;
        }
        if ($request->header) {
            foreach ($request->header as $key => $value) {
                $srv['HTTP_' . strtr(strtoupper($key), '-', '_')] = $value;
            }
        }
        $srv += [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SCRIPT_NAME' => '/app.php',
            'SERVER_NAME' => $srv['HTTP_HOST'] ?? site_host(),
            'DOCUMENT_ROOT' => self::resolveDocumentRoot(),
            'PHP_SELF' => App::$default_php_self,
            'SERVER_SOFTWARE' => $serverSoftware,
        ];
        if (!isset($srv['SCRIPT_FILENAME'])) {
            // Values are scalar|null (server vars coerced above); ?? '' removes
            // null, so a direct (string) cast is safe.
            $srv['SCRIPT_FILENAME'] = (string)($srv['DOCUMENT_ROOT'] ?? '')
                . (string)($srv['PHP_SELF'] ?? '');
        }

        // Apache mod_unique_id parity (#274): a FRESH per-request correlation id.
        // Under coroutine concurrency a class-load `$_SERVER['UNIQUE_ID'] = …`
        // assignment is worker-wide — every request on that worker shares one id,
        // breaking log correlation. Minting it here gives each request its own.
        // Kept as-is if an upstream (Apache mod_unique_id / a proxy) already set one.
        if (!isset($srv['UNIQUE_ID'])) {
            $srv['UNIQUE_ID'] = bin2hex(random_bytes(12));
        }

        if ($srv['REQUEST_METHOD'] === 'POST' && isset($srv['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
            $srv['REQUEST_METHOD'] = (string)$srv['HTTP_X_HTTP_METHOD_OVERRIDE'];
        }
        // Apache HostnameLookups: populate REMOTE_HOST via reverse DNS when
        // explicitly enabled. WARNING — blocking call (OpenSwoole's coroutine
        // hook converts gethostbyaddr() to non-blocking, but it's still a
        // measurable per-request cost). Off by default since Apache 1.3.
        if (App::$hostname_lookups && isset($srv['REMOTE_ADDR'])) {
            $remote = (string)$srv['REMOTE_ADDR'];
            if ($remote !== '') {
                $host = @gethostbyaddr($remote);
                if ($host !== false && $host !== $remote) {
                    $srv['REMOTE_HOST'] = $host;
                }
            }
        }
        // mod_php parity: keys OpenSwoole's $request->server doesn't provide.
        // GATEWAY_INTERFACE is the CGI/1.1 constant mod_php always sets;
        // REQUEST_SCHEME + HTTPS are derived from the request (honoring
        // X-Forwarded-Proto behind a trusted proxy). HTTPS is only set under
        // TLS, matching mod_php (the key is absent on plain HTTP).
        $srv += ['GATEWAY_INTERFACE' => 'CGI/1.1'];
        if (self::requestIsHttps($srv)) {
            $srv['REQUEST_SCHEME'] = 'https';
            $srv['HTTPS'] = $srv['HTTPS'] ?? 'on';
        } else {
            $srv['REQUEST_SCHEME'] = $srv['REQUEST_SCHEME'] ?? 'http';
        }

        // mod_php request-surface synthesis (#306 + #307): QUERY_STRING default,
        // REQUEST_URI query re-append, bare CONTENT_TYPE/CONTENT_LENGTH, and
        // HTTP Basic/Digest auth decode into PHP_AUTH_USER/PW. Pure transform —
        // PATH_INFO/PHP_SELF stay script-dependent (set at include/dispatch).
        $srv = self::synthesizeRequestServerVars($srv);
        return $srv;
    }

    /**
     * Re-establish the request-input superglobals ($_GET / $_POST / $_COOKIE /
     * $_SERVER / $_FILES / $_REQUEST) FROM the per-coroutine OpenSwoole request,
     * in the coroutine that is about to read them. Called right before every
     * handler / included-file dispatch in coroutine-legacy mode.
     *
     * WHY THIS EXISTS — coroutine-legacy populates these as PROCESS-GLOBAL
     * arrays in the OnRequest closure (so unmodified `$_GET['x']` code works).
     * ext-zealphp's scheduler snapshots/restores them per coroutine across
     * yields, but the snapshot is keyed by an OpenSwoole coroutine id that
     * COLLIDES for requests multiplexed onto one shared connection coroutine
     * (they all observe cid=2). Under request overlap, request B's populate
     * overwrites request A's process-global $_GET before A's handler reads it
     * → cross-request misroute (a response built for B answered to A).
     *
     * The per-coroutine `RequestContext` ($g) and its `zealphp_request` ARE
     * reliably isolated — stored via `OpenSwoole\Coroutine::getContext()`, not
     * the colliding cid. Re-deriving the superglobals from `$g->zealphp_request`
     * immediately before dispatch — with NO intervening yield — pins them to
     * THIS request regardless of what a concurrent coroutine wrote to the
     * process globals.
     *
     * Uses ext-zealphp's `zealphp_request_input_set`, which writes BOTH
     * EG(symbol_table) AND PG(http_globals) so the auto-global JIT (which reads
     * PG) observes the corrected values too. No-op when coroutine-legacy
     * isolation is off or the ext primitive is unavailable (the populate in the
     * OnRequest closure already covers the non-overlapping single-worker case).
     *
     * @param array<string, mixed>|null $serverOverlay Per-file `$_SERVER` keys
     *        (PHP_SELF / SCRIPT_NAME / SCRIPT_FILENAME) the caller set for an
     *        included file — these WIN over the request-derived rebuild so the
     *        included file sees its own canonical values, not the route's. Pass
     *        null at the route-dispatch boundary (pristine request `$_SERVER`).
     */
    public static function rebindRequestInput(RequestContext $g, ?array $serverOverlay = null): void
    {
        if (!self::$coroutine_isolated_superglobals) {
            return;
        }
        if (!\function_exists('zealphp_request_input_set')) {
            return;
        }
        $req = $g->zealphp_request;
        if (!$req instanceof \ZealPHP\HTTP\Request) {
            return;
        }
        // ext#43 — the CURRENT live superglobals win over the request-derived
        // rebuild. A route callback mutating `$g->get` (= the live $_GET alias)
        // before App::include() must have those mutations visible inside the
        // included file — Apache/mod_php semantics ($_GET['x'] = ...; include)
        // and MODE_COROUTINE behaviour. The original always-rebuild defended
        // against the colliding-cid era where the live process globals could
        // hold a PEER's data at this point; post-#40/#42 (ptr-keyed snapshot
        // lanes) the live state inside a request coroutine is provably its own
        // (0 leaks at 60+-way concurrency), so rebuilding from the pristine
        // request only LOST legitimate mutations (labs: $g->get['username']
        // written by the /profile route → empty in profile.php → infinite
        // redirect loop). Request-derived values remain the fallback when the
        // live array is empty (genuinely empty either way, or an edge where
        // the populate never ran — the rebuild restores canonical values).
        $curGet = $g->get;
        /** @var array<string, mixed> $get */
        $get = $curGet !== [] ? $curGet : ($req->get ?? []);
        $curPost = $g->post;
        /** @var array<string, mixed> $post */
        $post = $curPost !== [] ? $curPost : ($req->post ?? []);
        $curCookie = $g->cookie;
        /** @var array<string, mixed> $cookie */
        $cookie = $curCookie !== [] ? $curCookie : self::requestCookieMap($req); // #305 — PHP-canonical $_COOKIE
        $curFiles = $g->files;
        /** @var array<string, mixed> $files */
        $files = $curFiles !== [] ? $curFiles : ($req->files ?? []);
        $curServer = $g->server;
        $server = $curServer !== [] ? $curServer : self::buildServerVars($req);
        if ($serverOverlay !== null) {
            // Overlay wins: preserve per-include PHP_SELF / SCRIPT_NAME /
            // SCRIPT_FILENAME over the live/request-derived values.
            $server = $serverOverlay + $server;
        }
        // #356 — POST-wins precedence (PHP request_order='GP'); see
        // composeRequestArray() for the rationale.
        $request = self::composeRequestArray($get, $post);
        (\zealphp_request_input_set(...))($get, $post, $cookie, $server, $files, $request);
    }

    /**
     * Toggle ZealPHP's per-request session lifecycle. When disabled, the
     * `SessionManager` / `CoSessionManager` OnRequest wrapper skips
     * `session_start` / cookie emission / session write-close — request-context
     * init (`openswoole_request`, `zealphp_response`, error-stack reset) still
     * runs unconditionally. Use this when another framework (e.g. Symfony's
     * `NativeSessionStorage` via the zealphp-symfony bridge) owns sessions and
     * you don't want ZealPHP racing it for the `PHPSESSID` cookie. The
     * `zeal_session_*` uopz overrides remain installed and callable from user
     * code either way.
     */
    public static function sessionLifecycle(?bool $enabled = null): bool
    {
        if ($enabled !== null) self::$session_lifecycle = $enabled;
        return self::$session_lifecycle;
    }

    /**
     * Register a callback that `ZealAPI::isAuthenticated()` consults.
     * Signature: `fn(): bool`. The callback decides whether the current
     * request is authenticated — typically by reading `$_SESSION`,
     * `$g->session`, or your own auth state.
     *
     * Without this hook, `ZealAPI::isAuthenticated()` returns `false`
     * (fail-closed default), so any API endpoint guarded by
     * `requirePostAuth()` rejects every request. Fixes the gap surfaced
     * in [issue #13](https://github.com/sibidharan/zealphp/issues/13).
     *
     * Pass `null` (or omit the argument and rely on the existing value)
     * to read the current checker. Pass a callable to install one.
     *
     * Example:
     *
     * ```php
     * App::authChecker(fn() => !empty($_SESSION['user_id']));
     * App::authChecker(fn() => MyAuth::status() === MyAuth::LOGGED_IN);
     * ```
     *
     * @param callable|null $fn
     */
    public static function authChecker(?callable $fn = null): ?callable
    {
        if (func_num_args() > 0) self::$auth_checker = $fn;
        return self::$auth_checker;
    }

    /**
     * Register a callback that `ZealAPI::isAdmin()` consults.
     * Same shape as `authChecker()` — `fn(): bool`, default null.
     *
     * @param callable|null $fn
     */
    public static function adminChecker(?callable $fn = null): ?callable
    {
        if (func_num_args() > 0) self::$admin_checker = $fn;
        return self::$admin_checker;
    }

    /**
     * Register a callback that `ZealAPI::getUsername()` consults.
     * Signature: `fn(): ?string`. Default null → `getUsername()` returns
     * null.
     *
     * @param callable|null $fn
     */
    public static function usernameProvider(?callable $fn = null): ?callable
    {
        if (func_num_args() > 0) self::$username_provider = $fn;
        return self::$username_provider;
    }

    /**
     * Per-include CGI process isolation (Apache mod_php-style fresh process
     * per file). When true (the default in superglobals mode),
     * `App::include()` dispatches each `.php` file through `cgi_worker.php` via
     * `proc_open()` — global state (defined classes, constants, `ini_set`,
     * output handlers) is contained inside the subprocess. When false,
     * runs in-process via `executeFile()` — saves the ~30-50ms `proc_open` +
     * PHP startup + autoloader cost per call, but every include shares the
     * worker's PHP arena.
     *
     * Set to false when the legacy code is well-behaved enough to coexist
     * in a shared worker (Symfony, Laravel, modern PHP apps). Keep true
     * for unmodified WordPress / Drupal where `define()`-heavy plugins assume
     * a fresh process per request.
     *
     * `null` (default) means "follow `App::$superglobals`" — preserves the
     * historical pairing so callers that don't touch this knob see no
     * behaviour change. `App::run()` resolves `null` into the backing
     * `$coproc_implicit_request_handler` bool right before the server starts.
     */
    public static function processIsolation(?bool $on = null): bool
    {
        if (func_num_args() > 0) {
            self::refuseAfterRun('App::processIsolation');
            self::$process_isolation = $on;
        }
        return self::$process_isolation ?? self::$superglobals;
    }

    /**
     * Select how a process-isolated legacy include is dispatched:
     *   `'pool'` (default) — pre-spawned PHP worker pool, mod_php-style isolation, ~1-3 ms warm.
     *   `'proc'`           — fresh PHP per request via `proc_open` (~30-50 ms cold; full compat).
     *   `'fork'`           — Apache MPM prefork: a fork-master forks a FRESH child per request at
     *                        TRUE global scope (~1 ms). Unmodified-WordPress correctness (no class
     *                        redeclare) at fork cost, not proc cold-start. EXPERIMENTAL (pcntl+posix).
     *   `'fcgi'`           — forward to a FastCGI backend via `App::$fcgi_address` (no child process).
     * See `App::$cgi_mode` for the full trade-off. No-arg call returns the current mode.
     * Only takes effect when `processIsolation()` is on.
     */
    public static function cgiMode(CgiMode|string|null $mode = null): string
    {
        if ($mode !== null) {
            $mode = CgiMode::coerce($mode)->value;
            self::$cgi_mode = $mode;
            self::$cgi_mode_set = true;
        }
        return self::$cgi_mode;
    }

    /** Isolation strategy constants — canonical user-facing surface for App::isolation(). */
    public const ISOLATION_COROUTINE = 'coroutine';
    public const ISOLATION_CGI_POOL  = 'cgi-pool';
    public const ISOLATION_CGI_PROC  = 'cgi-proc';
    public const ISOLATION_CGI_FCGI  = 'cgi-fcgi';
    public const ISOLATION_NONE      = 'none';

    /** High-level mode presets — canonical user-facing surface for App::mode(). */
    public const MODE_LEGACY_CGI       = 'legacy-cgi';
    public const MODE_COROUTINE        = 'coroutine';
    public const MODE_COROUTINE_LEGACY = 'coroutine-legacy';
    public const MODE_MIXED            = 'mixed';

    /**
     * The single knob that says HOW a request is isolated — folds the
     * (processIsolation × enableCoroutine × hookAll × cgiMode) cross-product
     * into one intention-revealing value. Pure sugar over the existing fluent
     * setters (they all keep working unchanged); accepts the `App::ISOLATION_*`
     * constant, an `Isolation` enum case, or a bare string ("no strong").
     *
     *   App::isolation(App::ISOLATION_COROUTINE);  // canonical
     *   App::isolation(Isolation::CgiProc);         // enum
     *   App::isolation('cgi-pool');                 // bare string (BC)
     *
     * Mapping (each just calls the existing setters, so no forcing rule ever fires):
     *   coroutine → processIsolation(false) + enableCoroutine(true)  + hookAll(true)
     *   cgi-pool  → processIsolation(true)  + cgiMode('pool') + enableCoroutine(false) + hookAll(false)
     *   cgi-proc  → processIsolation(true)  + cgiMode('proc') + enableCoroutine(false) + hookAll(false)
     *   cgi-fcgi  → processIsolation(true)  + cgiMode('fcgi') + enableCoroutine(false) + hookAll(false)
     *   none      → processIsolation(false) + enableCoroutine(false) + hookAll(false)
     *
     * No-arg call returns the currently-resolved isolation as an `App::ISOLATION_*`
     * string (derived from the resolved processIsolation/enableCoroutine knobs, so
     * the default — process for superglobals(true), coroutine for superglobals(false)
     * — is reported faithfully).
     */
    public static function isolation(Isolation|string|null $mode = null): string
    {
        if ($mode !== null) {
            $iso = Isolation::coerce($mode);
            if ($iso->isProcess()) {
                self::processIsolation(true);
                self::cgiMode($iso->cgiMode() ?? CgiMode::Pool);
                self::enableCoroutine(false);
                self::hookAll(false);
            } elseif ($iso === Isolation::Coroutine) {
                self::processIsolation(false);
                self::enableCoroutine(true);
                self::hookAll(true);
            } else { // None
                self::processIsolation(false);
                self::enableCoroutine(false);
                self::hookAll(false);
            }
        }
        // Derive the effective isolation from the resolved knobs.
        if (self::processIsolation()) {
            return 'cgi-' . self::$cgi_mode;
        }
        return self::enableCoroutine() ? self::ISOLATION_COROUTINE : self::ISOLATION_NONE;
    }

    /**
     * High-level mode preset — sets BOTH axes (`superglobals` + `isolation`) in
     * one call. Sugar over the fine-grained setters; accepts an `App::MODE_*`
     * constant or a bare string ("no strong"). All the individual setters remain
     * available to override afterwards.
     *
     *   legacy-cgi       → superglobals(true)  + isolation(cgi-pool)            [unmodified WordPress/Drupal]
     *   coroutine        → superglobals(false) + isolation(coroutine)          [modern ZealPHP apps — default shape]
     *   coroutine-legacy → superglobals(true)  + isolation(coroutine)          [legacy code, concurrent — Mode 4]
     *                      + silentRedeclare + includeIsolation + coroutineGlobalsIsolation + coroutineStaticsIsolation
     *                      (re-included/re-declared code survives; $GLOBALS AND function-local static $x isolate per coroutine)
     *   mixed            → superglobals(true)  + isolation(none)               [Symfony / Laravel bridge — sequential]
     */
    public static function mode(string $mode): void
    {
        self::refuseAfterRun('App::mode');
        switch ($mode) {
            case self::MODE_LEGACY_CGI:
                self::superglobals(true);
                self::isolation(Isolation::CgiPool);
                // Fresh subprocess per request by default (mod_php prefork
                // parity). Unmodified WordPress/Drupal re-run unguarded
                // top-level define()/class declarations on every request, so a
                // REUSED pool subprocess hits "Cannot redeclare class" (issue
                // #167). Apps with re-entrant boot can opt into reuse via
                // App::cgiPoolMaxRequests(N) (any order — see the flag).
                if (!self::$cgi_pool_max_requests_set) {
                    self::$cgi_pool_max_requests = 1;
                }
                break;
            case self::MODE_COROUTINE:
                self::superglobals(false);
                self::isolation(Isolation::Coroutine);
                break;
            case self::MODE_COROUTINE_LEGACY:
                self::superglobals(true);
                self::isolation(Isolation::Coroutine);
                self::silentRedeclare(true);
                self::includeIsolation(true);
                self::coroutineGlobalsIsolation(true);  // isolate $wp/$wpdb-style globals per coroutine
                // Stage 5: isolate function-local `static $x` per coroutine.
                // Default ON now that the touched-set registry decouples cost
                // from total function count — per-yield overhead scales with
                // the (small) number of static-USING functions, not all
                // functions (~1.9µs/yield at 50 static fns, flat from 500 to
                // 8000 total). This is the "old PHP just works" guarantee: the
                // LAST request-state primitive is isolated by default. Opt out
                // with ZEALPHP_FN_STATICS_DISABLE=1 for raw throughput on apps
                // that don't rely on per-request function statics.
                self::coroutineStaticsIsolation((string) \getenv('ZEALPHP_FN_STATICS_DISABLE') !== '1');
                // #323: isolate the process CWD per coroutine, so a request's
                // chdir() (and executeFile()'s own chdir-to-script-dir) can't
                // leak into concurrently-running peers — the PHP-FPM mental
                // model for the working directory. Opt out with
                // ZEALPHP_CWD_ISOLATION_DISABLE=1.
                self::coroutineCwdIsolation((string) \getenv('ZEALPHP_CWD_ISOLATION_DISABLE') !== '1');
                // setlocale()/umask() are the same chdir-class process-global
                // state — isolate them per coroutine too (ext-zealphp 0.3.38+).
                self::coroutineLocaleIsolation((string) \getenv('ZEALPHP_LOCALE_ISOLATION_DISABLE') !== '1');
                self::coroutineUmaskIsolation((string) \getenv('ZEALPHP_UMASK_ISOLATION_DISABLE') !== '1');
                // date_default_timezone_set() / mb_internal_encoding() /
                // libxml_use_internal_errors() — the remaining function-backed
                // process-global settings legacy apps change per request
                // (ext-zealphp 0.3.45+).
                self::coroutineTimezoneIsolation((string) \getenv('ZEALPHP_TZ_ISOLATION_DISABLE') !== '1');
                self::coroutineMbencIsolation((string) \getenv('ZEALPHP_MBENC_ISOLATION_DISABLE') !== '1');
                self::coroutineLibxmlIsolation((string) \getenv('ZEALPHP_LIBXML_ISOLATION_DISABLE') !== '1');
                break;
            case self::MODE_MIXED:
                self::superglobals(true);
                self::isolation(Isolation::None);
                break;
            default:
                throw new \InvalidArgumentException(
                    "Unknown App::mode('$mode') — use App::MODE_LEGACY_CGI, MODE_COROUTINE, "
                    . "MODE_COROUTINE_LEGACY, or MODE_MIXED."
                );
        }
    }

    /**
     * Worker count for `cgiMode('pool')` — the native FCGI-style subprocess
     * pool. FPM `pm.max_children` parity. Default 4. Set BEFORE `App::run()`.
     */
    public static function cgiPoolSize(?int $size = null): int
    {
        if ($size !== null) {
            self::$cgi_pool_size = max(1, $size);
            self::$cgi_pool_size_set = true;
        }
        return self::$cgi_pool_size;
    }

    /**
     * Strict environment allowlist for `cgiMode('pool')` subprocesses — exact
     * names and/or `PREFIX*` globs. A no-arg call returns the current list; a
     * one-arg call sets it. Empty (default) passes the parent env through
     * (legacy-app compatibility) minus the httpoxy `HTTP_PROXY` var; a non-empty
     * list restricts the subprocess to matching vars only (the pool IPC var is
     * always passed). Set BEFORE `App::run()`.
     *
     * @param list<string>|null $names
     * @return list<string>
     */
    public static function cgiPoolEnvAllowlist(?array $names = null): array
    {
        if ($names !== null) {
            self::$cgi_pool_env_allowlist = $names;
        }
        return self::$cgi_pool_env_allowlist;
    }

    /**
     * Per-subprocess request count before recycle for `cgiMode('pool')`.
     * FPM `pm.max_requests` parity. Default 500. Set to 1 for fresh-process
     * semantics every request (slower; same isolation as `cgiMode('proc')`).
     */
    public static function cgiPoolMaxRequests(?int $n = null): int
    {
        if ($n !== null) {
            self::$cgi_pool_max_requests = max(1, $n);
            self::$cgi_pool_max_requests_set = true;
        }
        return self::$cgi_pool_max_requests;
    }

    /**
     * Whether `cgi_worker.php` (proc-mode subprocess entry) loads Composer's
     * `vendor/autoload.php` on startup. Default `false` — restores pre-v0.2.20
     * behaviour suitable for unmodified WordPress / Drupal / Joomla / plain
     * PHP. Set to `true` when your `public/*.php` files explicitly need
     * `\ZealPHP\App` or framework classes inside the CGI subprocess.
     *
     * See the `$cgi_subprocess_autoload` property docblock for the WordPress
     * wp-cron deadlock rationale (issue #18, v0.2.41 regression fix).
     */
    public static function cgiSubprocessAutoload(?bool $on = null): bool
    {
        if ($on !== null) {
            self::$cgi_subprocess_autoload = $on;
        }
        return self::$cgi_subprocess_autoload;
    }

    /**
     * FastCGI backend address for `App::cgiMode('fcgi')` dispatch.
     * Accepts `"host:port"` (TCP) or `"unix:/path/to/fpm.sock"` (Unix socket).
     * No-arg call returns the current address; with-arg sets and returns it.
     * Must be configured before `App::run()` — changing it mid-request has no effect.
     */
    public static function fcgiAddress(?string $address = null): string
    {
        if ($address !== null) {
            self::$fcgi_address = $address;
            self::$fcgi_address_set = true;
        }
        return self::$fcgi_address;
    }

    /**
     * Max seconds to wait for a `proc`-mode CGI subprocess to emit its metadata
     * line before SIGTERM/SIGKILL. Apache `CGIScriptTimeout` parity. Default 60.
     * No-arg returns the current value; with-arg sets and returns it. Env:
     * `ZEALPHP_CGI_TIMEOUT` (applied at boot unless set explicitly here).
     */
    public static function cgiTimeout(?int $seconds = null): int
    {
        if ($seconds !== null) {
            self::$cgi_timeout = max(1, $seconds);
            self::$cgi_timeout_set = true;
        }
        return self::$cgi_timeout;
    }

    /**
     * Max concurrent `cgiMode('fork')` children — a per-request fork ceiling,
     * NOT the pre-spawned `cgiPoolSize()`. Default 16. No-arg returns the
     * current value; with-arg sets and returns it. Env:
     * `ZEALPHP_CGI_FORK_MAX_CONCURRENT` (applied at boot unless set explicitly).
     */
    public static function cgiForkMaxConcurrent(?int $n = null): int
    {
        if ($n !== null) {
            self::$cgi_fork_max_concurrent = max(1, $n);
            self::$cgi_fork_max_concurrent_set = true;
        }
        return self::$cgi_fork_max_concurrent;
    }

    /**
     * Resolve the CGI subprocess-pool config from the environment at boot.
     *
     * Each variable is applied only when its knob was NOT set explicitly via the
     * fluent setter, so precedence is: explicit code config > environment >
     * hardcoded default — symmetric with `ZEALPHP_WORKERS` → `worker_num`.
     * Called once from `App::run()` in the master before workers fork; the
     * resolved values COW-inherit into every worker and the CGI pools they
     * lazily spawn. The testable seam (unit-tested without booting a server).
     *
     * | Env var                         | Knob                    | Setter |
     * |---------------------------------|-------------------------|--------|
     * | `ZEALPHP_CGI_MODE`              | `cgi_mode`              | `cgiMode()` |
     * | `ZEALPHP_CGI_WORKERS`          | `cgi_pool_size`         | `cgiPoolSize()` |
     * | `ZEALPHP_CGI_MAX_REQUESTS`     | `cgi_pool_max_requests` | `cgiPoolMaxRequests()` |
     * | `ZEALPHP_CGI_TIMEOUT`          | `cgi_timeout`           | `cgiTimeout()` |
     * | `ZEALPHP_FCGI_ADDRESS`        | `fcgi_address`          | `fcgiAddress()` |
     * | `ZEALPHP_CGI_FORK_MAX_CONCURRENT` | `cgi_fork_max_concurrent` | `cgiForkMaxConcurrent()` |
     */
    public static function resolveCgiEnv(): void
    {
        if (!self::$cgi_mode_set) {
            $v = getenv('ZEALPHP_CGI_MODE');
            if (is_string($v) && $v !== '') {
                try {
                    self::$cgi_mode = CgiMode::coerce($v)->value;
                } catch (\Throwable) {
                    // invalid ZEALPHP_CGI_MODE — keep the current/default mode
                }
            }
        }
        $intEnv = static function (string $name, int $min): ?int {
            $v = getenv($name);
            return (is_string($v) && $v !== '' && is_numeric($v) && (int) $v >= $min) ? (int) $v : null;
        };
        if (!self::$cgi_pool_size_set && ($n = $intEnv('ZEALPHP_CGI_WORKERS', 1)) !== null) {
            self::$cgi_pool_size = $n;
        }
        if (!self::$cgi_pool_max_requests_set && ($n = $intEnv('ZEALPHP_CGI_MAX_REQUESTS', 1)) !== null) {
            self::$cgi_pool_max_requests = $n;
        }
        if (!self::$cgi_timeout_set && ($n = $intEnv('ZEALPHP_CGI_TIMEOUT', 1)) !== null) {
            self::$cgi_timeout = $n;
        }
        if (!self::$cgi_fork_max_concurrent_set && ($n = $intEnv('ZEALPHP_CGI_FORK_MAX_CONCURRENT', 1)) !== null) {
            self::$cgi_fork_max_concurrent = $n;
        }
        if (!self::$fcgi_address_set) {
            $v = getenv('ZEALPHP_FCGI_ADDRESS');
            if (is_string($v) && $v !== '') {
                self::$fcgi_address = $v;
            }
        }
    }

    /**
     * Register a per-extension CGI backend. Apache `AddHandler`/`ProxyPassMatch`
     * + nginx `fastcgi_pass`-per-location parity.
     *
     * @param string $extension  File extension including the dot, e.g. `'.py'`, `'.pl'`.
     * @param array<string, mixed> $config
     *   `'mode'`        — `'pool'` | `'proc'` | `'fcgi'` (required)
     *   `'interpreter'` — full path to interpreter binary (`proc` mode only; `null` = direct exec via shebang)
     *   `'exec_paths'`  — URL path prefixes (e.g. `'/cgi-bin'`), NOT filesystem paths, where this extension may execute. Files outside these prefixes return 403 (Apache `Options +ExecCGI` parity). Passing a filesystem path (one that exists as a directory) throws `\InvalidArgumentException` — exec_paths are matched against the request URL, never the disk path.
     *   `'address'`     — FastCGI backend address, `"host:port"` or `"unix:/path"` (`fcgi` mode only)
     *   `'fcgi_params'` — extra FCGI params merged into the CGI env after `buildCgiEnv()` (`fcgi` mode only)
     *
     * @throws \InvalidArgumentException on invalid mode, fork-on-non-PHP, missing fcgi address, or an `exec_paths` entry that is a filesystem path rather than a URL prefix.
     */
    public static function registerCgiBackend(string $extension, array $config): void
    {
        $mode = is_string($config['mode'] ?? null) ? (string)$config['mode'] : '';
        if ($mode !== 'proc' && $mode !== 'fcgi' && $mode !== 'pool' && $mode !== 'fork') {
            throw new \InvalidArgumentException(
                "App::registerCgiBackend() mode must be 'pool', 'proc', 'fork', or 'fcgi'; got '{$mode}'."
            );
        }
        if (($mode === 'pool' || $mode === 'fork') && $extension !== '.php') {
            throw new \InvalidArgumentException(
                "{$mode} mode requires a PHP target; use 'fcgi' (warm external pool, language-agnostic) or 'proc' for {$extension}"
            );
        }
        if ($mode === 'fcgi' && empty($config['address'])) {
            throw new \InvalidArgumentException(
                "App::registerCgiBackend() fcgi mode requires an 'address' (host:port or unix:/path) for {$extension}."
            );
        }
        $entry = ['mode' => $mode];
        $interpreter = $config['interpreter'] ?? null;
        if ($interpreter !== null && is_string($interpreter)) {
            $entry['interpreter'] = $interpreter;
        }
        $address = $config['address'] ?? null;
        if ($address !== null && is_string($address)) {
            $entry['address'] = $address;
        }
        $fcgiParams = $config['fcgi_params'] ?? null;
        if (is_array($fcgiParams)) {
            /** @var array<string, string> $fcgiParams */
            $entry['fcgi_params'] = $fcgiParams;
        }
        if (isset($config['exec_paths']) && is_array($config['exec_paths'])) {
            $execPaths = array_values(array_filter($config['exec_paths'], 'is_string'));
            foreach ($execPaths as $p) {
                self::assertUrlPrefix($p, "App::registerCgiBackend() 'exec_paths'");
            }
            $entry['exec_paths'] = $execPaths;
        }
        self::$cgi_backends[$extension] = $entry;
    }

    /**
     * Reset the CGI backend + ScriptAlias registries. Test-support helper —
     * lets unit tests start from a clean registry without process recycling.
     */
    public static function resetCgiBackends(): void
    {
        self::$cgi_backends = [];
        self::$cgi_script_aliases = [];
        self::$cgi_backend_aliases = [];
    }

    /**
     * The four CGI dispatch strategies a per-route `backend:` may name — the
     * CGI-ISOLATION family. Excludes the COROUTINE-SCHEDULER family
     * (`coroutine`/`coroutine-legacy`/`legacy-cgi`/`mixed`), which is a
     * process-wide lifecycle decision frozen at `$server->start()` and cannot
     * be chosen per route. See `normalizeBackendConfig()`'s guard.
     */
    private const ROUTE_BACKEND_MODES = ['pool', 'proc', 'fork', 'fcgi'];

    /**
     * Lifecycle modes a user might mistakenly pass as a route backend. Rejected
     * with a message pointing at the separate-process model.
     */
    private const LIFECYCLE_MODE_NAMES = ['coroutine', 'coroutine-legacy', 'legacy-cgi', 'mixed'];

    /**
     * Register a named CGI backend alias for the per-route `backend:` option —
     * the `App::middlewareAlias()` of the CGI dispatch world. Lets a route say
     * `backend: 'wp-fork'` instead of repeating an inline config.
     *
     * ```php
     * App::cgiBackendAlias('wp-fork', 'fork');                         // bare mode
     * App::cgiBackendAlias('py', ['mode' => 'proc', 'interpreter' => '/usr/bin/python3']);
     * App::cgiBackendAlias('fpm', ['mode' => 'fcgi', 'address' => 'unix:/run/php-fpm.sock']);
     * ```
     *
     * Register aliases BEFORE the routes that reference them (e.g. in `app.php`
     * before `route/*.php` load — the natural order). The config is resolved +
     * validated here, so a bad mode / missing `fcgi` address / a lifecycle-mode
     * name throws at registration, not at request time.
     *
     * @param string $name Alias name. Must be non-empty and not collide with a reserved mode (`pool`/`proc`/`fork`/`fcgi`).
     * @param array{mode:string, interpreter?:string, address?:string, fcgi_params?:array<string,string>}|string $config A bare mode string, or an inline backend config array.
     * @throws \InvalidArgumentException on a reserved/empty name, a non-mode string config, an invalid mode, a lifecycle-mode name, or `fcgi` without an `address`.
     */
    public static function cgiBackendAlias(string $name, array|string $config): void
    {
        $name = trim($name);
        if ($name === '' || in_array($name, self::ROUTE_BACKEND_MODES, true)) {
            throw new \InvalidArgumentException(
                "App::cgiBackendAlias() name must be non-empty and not a reserved mode name "
                . "('pool'|'proc'|'fork'|'fcgi'); got '{$name}'."
            );
        }
        if (is_string($config)) {
            if (!in_array($config, self::ROUTE_BACKEND_MODES, true)) {
                throw new \InvalidArgumentException(
                    "App::cgiBackendAlias('{$name}', '{$config}'): a string config must be a bare mode "
                    . "('pool'|'proc'|'fork'|'fcgi'); use an array for interpreter/address/fcgi_params."
                );
            }
            $config = ['mode' => $config];
        }
        self::$cgi_backend_aliases[$name] = self::normalizeBackendConfig($config);
    }

    /**
     * Normalise + validate one inline backend config array into the canonical
     * shape (`mode` + the optional `interpreter`/`address`/`fcgi_params`). The
     * single validation point for `cgiBackendAlias()` and `resolveBackendSpec()`.
     *
     * @param array<array-key, mixed> $config
     * @return array{mode:string, interpreter?:string, address?:string, fcgi_params?:array<string,string>}
     * @throws \InvalidArgumentException on a lifecycle-mode name, an invalid mode, or `fcgi` without an `address`.
     */
    private static function normalizeBackendConfig(array $config): array
    {
        $mode = is_string($config['mode'] ?? null) ? (string) $config['mode'] : '';
        if (in_array($mode, self::LIFECYCLE_MODE_NAMES, true)) {
            throw new \InvalidArgumentException(self::lifecycleBackendMessage($mode));
        }
        if (!in_array($mode, self::ROUTE_BACKEND_MODES, true)) {
            throw new \InvalidArgumentException(
                "Route backend mode must be 'pool', 'proc', 'fork', or 'fcgi'; got '{$mode}'."
            );
        }
        if ($mode === 'fcgi' && empty($config['address'])) {
            throw new \InvalidArgumentException(
                "Route backend 'fcgi' mode requires an 'address' (host:port or unix:/path)."
            );
        }
        $entry = ['mode' => $mode];
        $interpreter = $config['interpreter'] ?? null;
        if (is_string($interpreter)) {
            $entry['interpreter'] = $interpreter;
        }
        $address = $config['address'] ?? null;
        if (is_string($address)) {
            $entry['address'] = $address;
        }
        $fcgiParams = $config['fcgi_params'] ?? null;
        if (is_array($fcgiParams)) {
            /** @var array<string, string> $fcgiParams */
            $entry['fcgi_params'] = $fcgiParams;
        }
        return $entry;
    }

    /**
     * Resolve a route `backend:` spec — a bare mode string, a registered alias
     * name, or an inline config array — into a concrete backend config. `null`
     * passes through (no backend). Called at route registration; the dispatch
     * hot path never re-resolves.
     *
     * @param array<array-key, mixed>|string $spec
     * @return array{mode:string, interpreter?:string, address?:string, fcgi_params?:array<string,string>}|null
     * @throws \InvalidArgumentException on a lifecycle-mode name, an unknown alias, or an invalid inline config.
     */
    private static function resolveBackendSpec(array|string $spec): ?array
    {
        if (is_string($spec)) {
            $s = trim($spec);
            if ($s === '') {
                return null;
            }
            if (in_array($s, self::ROUTE_BACKEND_MODES, true)) {
                return self::normalizeBackendConfig(['mode' => $s]);
            }
            if (in_array($s, self::LIFECYCLE_MODE_NAMES, true)) {
                throw new \InvalidArgumentException(self::lifecycleBackendMessage($s));
            }
            if (!isset(self::$cgi_backend_aliases[$s])) {
                throw new \InvalidArgumentException(
                    "Unknown route backend alias '{$s}'. Register it with "
                    . "App::cgiBackendAlias('{$s}', [...]) BEFORE the route, or use a bare mode "
                    . "('pool'|'proc'|'fork'|'fcgi') or an inline config array."
                );
            }
            return self::$cgi_backend_aliases[$s];
        }
        return self::normalizeBackendConfig($spec);
    }

    /**
     * The error shown when a route `backend:` (or a `cgiBackendAlias`) is given
     * a process-wide lifecycle mode instead of a CGI dispatch strategy.
     */
    private static function lifecycleBackendMessage(string $mode): string
    {
        return "Route backend '{$mode}' is a process-wide lifecycle mode, not a per-route CGI backend. "
            . "coroutine / coroutine-legacy / legacy-cgi / mixed are frozen at server start "
            . "(OpenSwoole's enable_coroutine + Runtime::enableCoroutine(HOOK_ALL) are process-global), "
            . "so they cannot vary per route — run separate processes per port behind a proxy to mix them. "
            . "Valid route backends: 'pool', 'proc', 'fork', 'fcgi', an inline config array, "
            . "or a registered App::cgiBackendAlias() name.";
    }

    /**
     * Combine the two ways a route can declare a backend — the `'backend'` key
     * in `$options` and the `backend:` named argument — and resolve to a
     * concrete config. The named argument wins when both are present. Returns
     * `null` (the fast path: no per-route backend) when neither is set.
     *
     * @param array<int|string, mixed> $options
     * @param array<string, mixed>|string|null $backendArg
     * @return array{mode:string, interpreter?:string, address?:string, fcgi_params?:array<string,string>}|null
     */
    private static function routeBackendSpec(array $options, array|string|null $backendArg): ?array
    {
        if ($backendArg !== null) {
            return self::resolveBackendSpec($backendArg);
        }
        $fromOptions = $options['backend'] ?? null;
        if (is_array($fromOptions) || is_string($fromOptions)) {
            return self::resolveBackendSpec($fromOptions);
        }
        return null;
    }

    /**
     * Apply the per-request route backend override (set by the matched route's
     * `backend:` option in `ResponseMiddleware::dispatchRoute()`) over a
     * `resolveCgiBackend()` result. A route that names a backend is itself the
     * ExecCGI authorisation for its `App::include()`, so `mayExecute` is forced
     * `true`. No override → the resolved backend passes through unchanged.
     *
     * @param array{backend: array{mode:string, interpreter?:string|null, address?:string, fcgi_params?:array<string,string>, exec_paths?:array<int,string>}, mayExecute: bool} $cgi
     * @return array{backend: array{mode:string, interpreter?:string|null, address?:string, fcgi_params?:array<string,string>, exec_paths?:array<int,string>}, mayExecute: bool}
     */
    private static function applyRouteBackend(array $cgi): array
    {
        $override = RequestContext::instance()->cgi_backend_override;
        if (is_array($override)) {
            return ['backend' => $override, 'mayExecute' => true];
        }
        return $cgi;
    }

    /**
     * Register a ScriptAlias-style executable URL prefix (Apache `ScriptAlias`
     * parity). Any file served under `$urlPrefix` is treated as executable,
     * regardless of its extension or whether a per-extension backend exists.
     *
     * @param string $urlPrefix  URL path prefix (e.g. `'/cgi-bin'`), NOT a filesystem path. Passing a filesystem path (one that exists as a directory) throws `\InvalidArgumentException`.
     * @param array<string, mixed> $config
     *   `'mode'`        — `'proc'` | `'fork'` | `'fcgi'` (defaults to `'proc'`)
     *   `'interpreter'` — full path to interpreter binary (`proc` mode)
     *   `'address'`     — FastCGI backend address (`fcgi` mode)
     *   `'fcgi_params'` — extra FCGI params (`fcgi` mode)
     *
     * @throws \InvalidArgumentException when `$urlPrefix` is a filesystem path rather than a URL prefix.
     */
    public static function cgiScriptAlias(string $urlPrefix, array $config): void
    {
        self::assertUrlPrefix($urlPrefix, 'App::cgiScriptAlias() $urlPrefix');
        $mode = is_string($config['mode'] ?? null) ? $config['mode'] : 'proc';
        $entry = ['mode' => $mode];
        $interpreter = $config['interpreter'] ?? null;
        if ($interpreter !== null && is_string($interpreter)) {
            $entry['interpreter'] = $interpreter;
        }
        $address = $config['address'] ?? null;
        if ($address !== null && is_string($address)) {
            $entry['address'] = $address;
        }
        $fcgiParams = $config['fcgi_params'] ?? null;
        if (is_array($fcgiParams)) {
            /** @var array<string, string> $fcgiParams */
            $entry['fcgi_params'] = $fcgiParams;
        }
        self::$cgi_script_aliases['/' . trim($urlPrefix, '/')] = $entry;
    }

    /**
     * Resolve the CGI backend config + execution permission for a given path.
     *
     * Resolution order (Apache parity):
     *  1. ScriptAlias prefixes (`$cgi_script_aliases`) — any file under a
     *     registered URL prefix is executable (`mayExecute = true`).
     *  2. Per-extension registry (`$cgi_backends`) — the backend is returned,
     *     but `mayExecute` is true only if the URL falls under one of the
     *     backend's `exec_paths` (ExecCGI scope).
     *  3. Unregistered — falls back to `['mode' => App::$cgi_mode]` with
     *     `mayExecute = false`.
     *
     * @return array{backend: array{mode:string, interpreter?:string|null, address?:string, fcgi_params?:array<string,string>, exec_paths?:array<int,string>}, mayExecute: bool}
     */
    public static function resolveCgiBackend(string $absPath, string $urlPath = ''): array
    {
        $url = '/' . ltrim($urlPath, '/');
        $ext = '.' . strtolower(pathinfo($absPath, PATHINFO_EXTENSION));

        // Per-extension registry is the source of truth for *which interpreter*
        // — it carries the explicit interpreter / address / fcgi_params. Aliases
        // are deliberately generic (no interpreter), so when both apply the
        // per-extension config wins; the alias still supplies the ExecCGI scope.
        $backend = null;
        $may     = false;
        if (isset(self::$cgi_backends[$ext])) {
            $backend = self::$cgi_backends[$ext];
            foreach (($backend['exec_paths'] ?? []) as $p) {
                if (self::pathUnderPrefix($url, '/' . trim((string)$p, '/'))) {
                    $may = true;
                    break;
                }
            }
        }

        // Script aliases broaden ExecCGI scope (Apache `ScriptAlias` parity), and
        // supply a fallback backend config when no per-extension entry matched.
        if (!$may || $backend === null) {
            foreach (self::$cgi_script_aliases as $prefix => $cfg) {
                if (self::pathUnderPrefix($url, $prefix)) {
                    $may = true;
                    if ($backend === null) {
                        $backend = $cfg;
                    }
                    break;
                }
            }
        }

        if ($backend === null) {
            return ['backend' => ['mode' => self::$cgi_mode], 'mayExecute' => false];
        }
        return ['backend' => $backend, 'mayExecute' => $may];
    }

    /**
     * Boundary-safe URL-prefix test. `$url` is "under" `$prefix` only when it
     * equals the prefix exactly or begins with `$prefix . '/'` — so `/cgi-bins`
     * does NOT match the `/cgi-bin` scope.
     */
    private static function pathUnderPrefix(string $url, string $prefix): bool
    {
        return $url === $prefix || str_starts_with($url, rtrim($prefix, '/') . '/');
    }

    /**
     * Validate that an ExecCGI scope value is a URL path prefix, not a
     * filesystem path. `exec_paths` / `cgiScriptAlias` prefixes are matched
     * against the request URL (`resolveCgiBackend()` → `pathUnderPrefix()`),
     * so a filesystem path (e.g. `'/var/www/cgi-bin'`) can never match the
     * incoming URL and silently yields a bare 403 (GitHub #155). Fail fast at
     * registration instead: reject anything that is not absolute (`/`-rooted)
     * or that resolves to an existing directory on disk. A real URL prefix
     * like `'/cgi-bin'` is not a directory on a normal host, so correct
     * configs pass untouched.
     *
     * @throws \InvalidArgumentException when `$value` looks like a filesystem path.
     */
    private static function assertUrlPrefix(string $value, string $label): void
    {
        if ($value === '' || $value[0] !== '/') {
            throw new \InvalidArgumentException(
                "{$label} must be a URL path prefix starting with '/' (e.g. '/cgi-bin'), "
                . "not a filesystem path; got '{$value}'."
            );
        }
        if (is_dir($value)) {
            throw new \InvalidArgumentException(
                "{$label} must be a URL path prefix (e.g. '/cgi-bin'), not a filesystem path; "
                . "got '{$value}', which is an existing directory on disk. These prefixes are "
                . "matched against the request URL, never the disk path — a filesystem path can "
                . "never match and would silently return 403."
            );
        }
    }

    /**
     * Emit a diagnostic when a request matched a registered CGI extension but
     * fell outside its `exec_paths` scope (ExecCGI off → bare 403). Without
     * this, a misconfigured `exec_paths` (e.g. a filesystem path that can never
     * match the URL — GitHub #155) surfaces only as an opaque 403. Logs only
     * for files whose extension HAS a registered backend, so unrelated
     * unregistered-extension 403s stay quiet.
     */
    private static function logExecScopeMiss(string $url, string $absPath): void
    {
        $ext = '.' . strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        if (!isset(self::$cgi_backends[$ext])) {
            return;
        }
        $scopes = self::$cgi_backends[$ext]['exec_paths'] ?? [];
        $scopeStr = $scopes === [] ? '(none configured)' : implode(', ', $scopes);
        elog(
            "{$url} matched a registered CGI extension ({$ext}) but is outside its exec_paths "
            . "scope [{$scopeStr}] (ExecCGI off) — returning 403. exec_paths are URL path "
            . "prefixes (e.g. '/cgi-bin'), not filesystem paths.",
            'warn'
        );
    }

    /**
     * Coroutine-safe command execution.
     *
     * Inside an OpenSwoole coroutine (`Coroutine::getCid() >= 0`) this yields
     * to the scheduler via `Coroutine\System::exec()` instead of blocking the
     * worker. Outside a coroutine (boot / CLI) it falls back to the blocking
     * `App::rawExec()` implementation. Either way the return shape is the same.
     *
     * @param string     $cmd     Shell command to run.
     * @param float|null $timeout Coroutine-mode timeout in seconds (`null` = no timeout).
     *
     * @return array{output:string, code:int, signal:int}
     */
    public static function exec(string $cmd, ?float $timeout = null): array
    {
        if (\OpenSwoole\Coroutine::getCid() >= 0) {
            // The ide-helper stub types System::exec()'s 2nd arg as bool and its
            // return as a bare array; the real ext-openswoole 26.2 takes a float
            // timeout (-1 = none) and returns {output, code, signal}. See the
            // documented ignore in phpstan.neon.
            $r = \OpenSwoole\Coroutine\System::exec($cmd, $timeout ?? -1);
            if (is_array($r)) {
                $output = $r['output'] ?? '';
                $code = $r['code'] ?? 0;
                $signal = $r['signal'] ?? 0;
                return [
                    'output' => is_scalar($output) ? (string) $output : '',
                    'code' => is_numeric($code) ? (int) $code : 0,
                    'signal' => is_numeric($signal) ? (int) $signal : 0,
                ];
            }
            return ['output' => '', 'code' => 1, 'signal' => 0];
        }
        return ['output' => (string) self::rawExec($cmd), 'code' => 0, 'signal' => 0];
    }

    /**
     * Raw blocking command execution via `proc_open`.
     *
     * Deliberately uses `proc_open` — NOT `shell_exec`/`exec`/`system`/
     * `passthru`/`popen` — because those builtins are uopz-overridden by the
     * CGI layer; routing through `proc_open` keeps this escape hatch
     * recursion-safe regardless of those overrides.
     *
     * @return string|null Captured stdout, or `null` if the process failed to start.
     */
    public static function rawExec(string $cmd): ?string
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        /** @var array<int, resource> $pipes */
        $pipes = [];
        $p = @\proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($p)) {
            return null;
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        \proc_close($p);
        return $out === false ? '' : $out;
    }

    /**
     * OpenSwoole's `enable_coroutine` server setting — whether each inbound
     * HTTP request is auto-wrapped in its own coroutine. When false,
     * requests run synchronously one at a time per worker (a worker
     * handling request N blocks any other inbound request until N
     * completes). When true, requests can yield on hooked I/O and other
     * requests dispatched on the same worker make progress.
     *
     * Default coupling is `!App::$superglobals` — running coroutines in
     * superglobals mode races the process-wide `$_GET`/`$_POST`/`$_SESSION`
     * arrays across concurrent requests, the original bug ZealPHP's
     * per-coroutine `$g` context was designed to avoid. **Setting this to
     * true while `$superglobals=true` is REFUSED — `App::run()` throws
     * `RuntimeException` at boot (v0.2.27+).**
     *
     * `null` follows the default coupling.
     */
    public static function enableCoroutine(?bool $on = null): bool
    {
        if (func_num_args() > 0) {
            self::refuseAfterRun('App::enableCoroutine');
            self::$enable_coroutine_override = $on;
        }
        if (self::$enable_coroutine_override !== null) {
            return self::$enable_coroutine_override;
        }
        // superglobals(true) defaults to coroutines OFF — SessionManager
        // uses native session_set_save_handler() which is not coroutine-safe.
        // ext-zealphp makes $_GET/$_SESSION per-coroutine safe, but the
        // session lifecycle integration needs work before auto-enabling.
        // Users can explicitly set App::enableCoroutine(true) to opt in.
        return !self::$superglobals;
    }

    /**
     * `OpenSwoole\Runtime::enableCoroutine($flags)` — process-wide PHP
     * I/O hooks that make blocking calls (`fopen`, `fread`, `curl`, `mysqli`,
     * etc.) yield to the coroutine scheduler instead of blocking the
     * worker. PDO is intentionally NOT hooked in OpenSwoole 22.1 / 26.2
     * regardless of this flag — Doctrine queries always block.
     *
     * Default coupling is `!App::$superglobals` (`HOOK_ALL` when coroutine
     * mode, `0` when superglobals mode). Hooked I/O in superglobals mode is
     * **unsafe** — yields can expose process-wide superglobal mutations
     * to other concurrent coroutines. `App::run()` throws `RuntimeException`
     * at boot for that combination (v0.2.27+).
     *
     * Accepts:
     *  - `null`  → follow default coupling
     *  - `true`  → `HOOK_ALL`
     *  - `false` → `0` (no hooks)
     *  - `int`   → explicit flag bitmask (`HOOK_TCP | HOOK_FILE | ...`)
     *
     * Returns the resolved int flag bitmask currently in effect.
     *
     * @param bool|int|null $on
     */
    public static function hookAll($on = null): int
    {
        if (func_num_args() > 0) {
            self::refuseAfterRun('App::hookAll');
            self::$hook_all_override = $on;
        }
        $v = self::$hook_all_override;
        if ($v === null) {
            return self::$superglobals ? 0 : \OpenSwoole\Runtime::HOOK_ALL;
        }
        if ($v === true)  return \OpenSwoole\Runtime::HOOK_ALL;
        if ($v === false) return 0;
        return (int) $v;
    }

    /**
     * Session TTL in seconds. Default 1440 (PHP's default). See $session_ttl.
     */
    public static function sessionTtl(?int $seconds = null): int
    {
        if ($seconds !== null) self::$session_ttl = max(1, $seconds);
        return self::$session_ttl;
    }

    /**
     * Max concurrent sessions in TableSessionHandler's OpenSwoole\Table.
     * See $session_max_rows. Must be set BEFORE register() / App::run().
     */
    public static function sessionMaxRows(?int $rows = null): int
    {
        if ($rows !== null) self::$session_max_rows = max(16, $rows);
        return self::$session_max_rows;
    }

    /**
     * Max serialized session size in bytes for TableSessionHandler.
     * See $session_data_size. Must be set BEFORE register() / App::run().
     */
    public static function sessionDataSize(?int $bytes = null): int
    {
        if ($bytes !== null) self::$session_data_size = max(1024, $bytes);
        return self::$session_data_size;
    }

    /**
     * File-backing directory for session storage. Default /var/lib/php/sessions.
     * Must be set BEFORE register() / App::run().
     */
    public static function sessionSavePath(?string $path = null): string
    {
        if ($path !== null) self::$session_save_path = $path;
        return self::$session_save_path;
    }

    /**
     * Session storage backend selector. See $session_handler docblock.
     * Pass 'table', 'file', 'redis', or a SessionHandlerInterface instance.
     * `null` (default) = the framework inline file path in ALL modes — #295: NOT
     * auto-promoted to TableSessionHandler. Honored by both CoSessionManager and
     * (since #295) SessionManager.
     */
    public static function sessionHandler(string|\SessionHandlerInterface|null $handler = null): string|\SessionHandlerInterface|null
    {
        if (\func_num_args() > 0) {
            self::$session_handler = $handler;
            // Re-resolve on next access (a mid-run change must take effect).
            self::$session_handler_resolved = false;
            self::$resolved_session_handler = null;
        }
        return self::$session_handler;
    }

    /** Memoised resolution of {@see $session_handler} (see resolveActiveSessionHandler). */
    private static ?\SessionHandlerInterface $resolved_session_handler = null;

    /** Whether {@see $resolved_session_handler} has been computed yet. */
    private static bool $session_handler_resolved = false;

    /**
     * Resolve the configured session handler instance — the single source of truth
     * the `zeal_session_*` overrides and both session managers consult.
     *
     * `$session_handler` is a string alias / instance / null. This memoises the
     * resolution (one handler instance per worker) and returns it.
     *
     * **`null` preserves the framework file path** (the historical observable
     * default) — it is deliberately NOT promoted to `TableSessionHandler` here, so
     * a session-wiring fix never silently changes the durability of an app that
     * didn't configure a handler (#295). A non-null alias/instance is honoured:
     * `'redis'`/`'table'`/`'file'`/instance all wire through.
     *
     * Returns `null` when unconfigured (callers keep their inline-file fallback).
     */
    public static function resolveActiveSessionHandler(): ?\SessionHandlerInterface
    {
        if (self::$session_handler_resolved) {
            return self::$resolved_session_handler;
        }
        self::$session_handler_resolved = true;
        $h = self::$session_handler;
        if ($h instanceof \SessionHandlerInterface) {
            return self::$resolved_session_handler = $h;
        }
        // String alias → instance. null / unknown → null (inline file path).
        self::$resolved_session_handler = match ($h) {
            'file'  => new \ZealPHP\Session\Handler\FileSessionHandler(),
            'table' => \ZealPHP\Session\Handler\TableSessionHandler::register(),
            'redis' => new \ZealPHP\Session\Handler\RedisSessionHandler(),
            default => null,
        };
        return self::$resolved_session_handler;
    }

    /**
     * Per-coroutine `$GLOBALS` isolation. See $coroutine_globals_isolation
     * docblock. Requires ext-zealphp 0.3.6+.
     */
    public static function coroutineGlobalsIsolation(?bool $on = null): bool
    {
        if ($on !== null) self::$coroutine_globals_isolation = $on;
        return self::$coroutine_globals_isolation;
    }

    /**
     * Re-capture the per-coroutine `$GLOBALS` baseline from the current symbol table.
     *
     * Under coroutine-legacy, ext-zealphp snapshots the parent `$GLOBALS` baseline
     * once — when `coroutineGlobalsIsolation` activates. Boot-time `$GLOBALS` writes
     * that happen AFTER that (e.g. an app bootstrap include such as `load.php` at
     * worker start) aren't in the baseline, so they'd be visible only to the FIRST
     * request coroutine and vanish for every subsequent one once a yield resets
     * `$GLOBALS` to the stale baseline (#26). The framework calls this once after the
     * `onWorkerStart` hooks complete (where such bootstraps run) to fold those writes
     * into the baseline; apps that populate `$GLOBALS` later in boot can call it
     * explicitly afterwards.
     *
     * No-op returning `false` when ext-zealphp lacks the function (< 0.3.33) or
     * per-coroutine `$GLOBALS` isolation isn't active — always safe to call.
     *
     * @return bool True if the baseline was refreshed.
     */
    public static function refreshGlobalsBaseline(): bool
    {
        if (!\function_exists('zealphp_globals_baseline_refresh')) {
            return false;
        }
        return (bool) \zealphp_globals_baseline_refresh();
    }

    /**
     * Keep `$GLOBALS` across requests within the worker. See $keep_globals
     * docblock for the full semantics + when to use it.
     */
    public static function keepGlobals(?bool $on = null): bool
    {
        if ($on !== null) self::$keep_globals = $on;
        return self::$keep_globals;
    }

    /**
     * One-time boot-time advisory for `coroutineGlobalsIsolation(true)`.
     *
     * Stage 2 COW (ext-zealphp v0.3.7+): shared parent snapshot taken once,
     * per-coroutine state is just (deltas + tombstones) for keys the coro
     * actually wrote or unset. Memory: O(parent) once + O(deltas) per coro,
     * not O(N keys) per coro.
     *
     * Stage 1 estimate is left in the message for context — most apps stay
     * well under any threshold with Stage 2 unless they routinely write
     * thousands of unique global keys per coroutine.
     *
     * The advisory runs only at App::run() boot, never per-request.
     */
    public static function coroutineGlobalsMemoryAdvisory(): void
    {
        $entryCount = count($GLOBALS);
        // Subtract well-known superglobal slots (handled separately).
        $userEntryCount = max(0, $entryCount - 7);

        $workers = self::$worker_num > 0 ? self::$worker_num : 1;
        $coroutinesPerWorker = 32;
        $totalCoroutines = $workers * $coroutinesPerWorker;

        // Stage 2 parent snapshot: one-time, shared.
        $parentBytes = $userEntryCount * 2048;
        // Per-coroutine deltas: typical write touches <20% of keys.
        $deltaBytesPerCoro = (int) ($userEntryCount * 0.2 * 2048);
        $projectedBytes = $parentBytes + ($deltaBytesPerCoro * $totalCoroutines);
        $projectedMB = (int) ($projectedBytes / (1024 * 1024));

        $thresholdMB = 256;
        $stageVer = \function_exists('zealphp_coroutine_globals') ? '0.3.7+ (Stage 2 COW)' : 'unavailable';

        if ($projectedMB >= $thresholdMB) {
            elog(
                "[advisory] coroutineGlobalsIsolation ({$stageVer}) projection: "
                . "~{$projectedMB} MB at peak (parent ~" . (int) ($parentBytes / 1024)
                . " KB + deltas ~" . (int) ($deltaBytesPerCoro / 1024)
                . " KB × {$totalCoroutines} coroutines). If your app holds "
                . "very large arrays in \$GLOBALS, consider moving them to "
                . "\$g (per-coroutine RequestContext) or Store (cross-worker "
                . "shared memory) instead.",
                'warn'
            );
        } else {
            elog(
                "[info] coroutineGlobalsIsolation active ({$stageVer}). "
                . "Projected peak memory: ~{$projectedMB} MB "
                . "({$userEntryCount} user globals, parent shared + per-coro deltas).",
                'info'
            );
        }
    }

    /**
     * Boot-time advisory for opcache + coroutine-legacy (Stage 7).
     *
     * Under coroutine-legacy, Stage 7 re-executes `require_once`'d files each
     * request. With opcache enabled, a WARM cache hit replays a file's early-bound
     * class declaration WITHOUT recompiling — so silent-redeclare's compile-time
     * first-wins (the Stage 4 CG-table swap) never fires, and opcache's load
     * re-binds an already-declared class -> "Cannot redeclare class" on request 2+
     * (e.g. WordPress's `WP_Block_Parser_Block`). Delayed (`extends`/`implements`)
     * classes go through the runtime DECLARE opcode and ARE caught; simple
     * early-bound classes are the gap.
     *
     * The primary fix is `opcache.dups_fix=1` — opcache then keeps the first
     * definition instead of fataling. It MUST be set in php.ini (or `-d`): opcache
     * reads `ignore_dups` once at its own startup and caches it, so `ini_set()` at
     * runtime (and therefore any auto-enable from this extension) is too late.
     * `dups_fix` covers the CLASS case on stock opcache; the FUNCTION case is a
     * php-src inconsistency (opcache's function-table copy ignores the directive) —
     * for that, run a patched opcache (the ZealPHP Docker image ships one) or, on
     * stock PHP, exclude the document-root via `opcache.blacklist_filename` so the
     * app's files recompile per request (framework + vendor stay cached). Returns
     * the advisory string, or null when N/A. Suppress with ZEALPHP_OPCACHE_ADVISORY=0.
     */
    /**
     * #423 — boot advisory: `App::keepGlobals(true)` is NOT honored in
     * coroutine-legacy. The per-coroutine `$GLOBALS` isolation path
     * (`zealphp_coroutine_globals_request_end()`) resets `$GLOBALS` every
     * request unconditionally — it's coupled to the object-global drain that
     * releases `$wpdb`-class destructors in coroutine context (memory-safety
     * critical), so it can't be skipped for `keep_globals` without an ext-side
     * change. `keep_globals` is consulted only on the `function_isolation`
     * reset path, which coroutine-legacy leaves off. Surface the mismatch at
     * boot rather than silently ignoring the knob. Returns the advisory string,
     * or null when N/A.
     *
     * @internal — public for tests; not part of the user-facing API.
     */
    public static function keepGlobalsCoroutineLegacyBootCheck(): ?string
    {
        if (self::$keep_globals
            && self::$coroutine_globals_isolation
            && !self::$function_isolation
        ) {
            return 'App::keepGlobals(true) is not honored in coroutine-legacy: per-coroutine $GLOBALS '
                . 'isolation resets $GLOBALS every request (it is coupled to the object-global drain that '
                . 'safely releases I/O destructors in coroutine context). Use a Store/Cache backend for a '
                . 'worker-lifetime cache, or run in mixed mode where keepGlobals takes effect.';
        }
        return null;
    }

    public static function opcacheLegacyBootCheck(): ?string
    {
        if (!self::$silent_redeclare) {
            return null;  // only coroutine-legacy re-executes require_once'd files
        }
        if (\getenv('ZEALPHP_OPCACHE_ADVISORY') === '0') {
            return null;
        }
        if (!\function_exists('opcache_get_status')) {
            return null;
        }
        $status = @\opcache_get_status(false);
        if (!\is_array($status) || empty($status['opcache_enabled'])) {
            return null;  // opcache not active for this SAPI — nothing to warn about
        }
        $dupsFix = (bool) \filter_var(\ini_get('opcache.dups_fix'), \FILTER_VALIDATE_BOOL);
        $docRoot = \rtrim((string) self::$document_root, '/');
        return self::opcacheLegacyAdvisory($dupsFix, $docRoot);
    }

    /**
     * Build the opcache + coroutine-legacy boot advisory string. Split out of
     * `opcacheLegacyBootCheck()` as a pure (opcache-independent) seam so both
     * `dups_fix` branches are unit-testable without opcache enabled in the SAPI.
     *
     * @param bool   $dupsFix whether `opcache.dups_fix` is on (the CLASS case is then handled)
     * @param string $docRoot document-root (already `rtrim`'d), used in the blacklist hint
     */
    public static function opcacheLegacyAdvisory(bool $dupsFix, string $docRoot): string
    {
        if (!$dupsFix) {
            return "[advisory] opcache is ENABLED in coroutine-legacy mode but opcache.dups_fix is "
                . "OFF. Stage 7 re-executes require_once'd files per request, so opcache re-copies a "
                . "cached file's classes/functions into a table that already has them -> "
                . "\"Cannot redeclare\" on request 2+. Set opcache.dups_fix=1 in php.ini (it cannot be "
                . "set at runtime — opcache reads it at startup) to fix the CLASS case. FUNCTIONS also "
                . "need a patched opcache (the ZealPHP Docker image ships one) or, on stock PHP, exclude "
                . "your document-root via opcache.blacklist_filename (put \"" . $docRoot . "/\" in a file "
                . "and point opcache.blacklist_filename at it). Suppress with ZEALPHP_OPCACHE_ADVISORY=0.";
        }
        return "[advisory] opcache + coroutine-legacy with opcache.dups_fix=1 — class redeclares are "
            . "handled. If you still see \"Cannot redeclare function\", your opcache is unpatched: use "
            . "the patched opcache from the ZealPHP Docker image, or exclude your document-root via "
            . "opcache.blacklist_filename (\"" . $docRoot . "/\"). Suppress with ZEALPHP_OPCACHE_ADVISORY=0.";
    }

    /**
     * Per-request function/class/include isolation. Opt-in — see $function_isolation docblock.
     */
    public static function functionIsolation(?bool $on = null): bool
    {
        if ($on !== null) {
            self::$function_isolation = $on;
        }
        return self::$function_isolation;
    }

    /**
     * Per-request require_once cache reset. See $include_isolation docblock.
     */
    public static function includeIsolation(?bool $on = null): bool
    {
        if ($on !== null) {
            self::$include_isolation = $on;
        }
        return self::$include_isolation;
    }

    /**
     * Per-request define() isolation. Opt-in — see $define_isolation docblock.
     */
    public static function defineIsolation(?bool $on = null): bool
    {
        if ($on !== null) {
            self::$define_isolation = $on;
        }
        return self::$define_isolation;
    }

    /**
     * Stage 3 silent-redeclare. Opt-in — see $silent_redeclare docblock.
     * When called, also flips the ext-zealphp C-level flag immediately if
     * the function is available; the App::run() boot wiring re-asserts the
     * flag for boot-after-set ordering.
     */
    public static function silentRedeclare(?bool $on = null): bool
    {
        if ($on !== null) {
            self::$silent_redeclare = $on;
            if (\function_exists('zealphp_silent_redeclare')) {
                (\zealphp_silent_redeclare(...))($on);
            }
        }
        return self::$silent_redeclare;
    }

    /**
     * True when the per-request state RESETS — `zealphp_reset_request_rtcaches()`
     * / `zealphp_reset_request_statics()` / `zealphp_reset_request_class_statics()`,
     * run in the session-manager `finally` block — are SAFE to execute.
     *
     * Those resets restore user symbols to their boot template each request
     * (the PHP-FPM "fresh process per request" contract). They are safe ONLY
     * when the boot snapshot (`zealphp_process_state_snapshot()`, taken in
     * `onWorkerStart`) exists to EXEMPT framework class statics — `App::$routes`,
     * the middleware stack, `Store`/`Counter` backends, session handlers. That
     * snapshot is gated on include- or function-isolation (App::run() boot
     * wiring), so it is taken under `coroutine-legacy` (which enables
     * `includeIsolation(true)`) but NOT under a bare `silentRedeclare(true)`
     * that enables neither isolation.
     *
     * Issue #227: gating the resets on `$silent_redeclare` ALONE let them fire
     * under bare `silentRedeclare(true)` with no exempting snapshot, so the
     * reset zeroed `App::$middleware_stack` (→ "handle() on null" on request
     * 2+) and could heap-corrupt other framework statics. Requiring an active
     * isolation (hence a snapshot) keeps the resets scoped to `coroutine-legacy`
     * — exactly where they are intended and safe. Bare `silentRedeclare(true)`
     * (the declare-opcode hook alone, for cron-worker redeclares) gets no
     * resets, matching its documented "just the redeclare hook" contract.
     */
    public static function perRequestStateResetsActive(): bool
    {
        return self::$silent_redeclare
            && (self::$function_isolation || self::$include_isolation);
    }

    /**
     * Stage 8 global-scope request include (coroutine-legacy). A no-arg call
     * returns the current setting; a one-arg call sets it. See the
     * `$global_scope_include` docblock for the contract. Set BEFORE `App::run()`.
     */
    public static function globalScopeInclude(?bool $on = null): bool
    {
        if ($on !== null) {
            self::$global_scope_include = $on;
        }
        if (self::$global_scope_include !== null) {
            return self::$global_scope_include;
        }
        return \getenv('ZEALPHP_GLOBAL_INCLUDE') === '1';
    }

    /**
     * Whether THIS request's `App::include()` should run at true global scope:
     * the gate is on AND we're in coroutine-legacy (so the per-coroutine globals
     * isolation stack is active). The ext-capability check (`function_exists`) is
     * done inline at the `executeFile()` call site. Policy-only helper.
     */
    private static function globalScopeIncludeEffective(): bool
    {
        return self::globalScopeInclude() && self::$silent_redeclare;
    }

    /**
     * Stage 5 per-coroutine function-static isolation. Opt-in — see
     * $coroutine_statics_isolation docblock for the perf tradeoff. The ext
     * C-level flag is asserted at App::run() boot wiring (alongside the other
     * isolation knobs) so the scheduler hooks are guaranteed installed first;
     * setting it here only records the intent.
     */
    public static function coroutineStaticsIsolation(?bool $on = null): bool
    {
        if ($on !== null) {
            self::$coroutine_statics_isolation = $on;
        }
        return self::$coroutine_statics_isolation;
    }

    /**
     * Per-coroutine CWD isolation (#323) — see the $coroutine_cwd_isolation
     * docblock. The ext C-level flag is asserted at App::run() boot wiring
     * (alongside the other isolation knobs) so the scheduler hooks are
     * guaranteed installed and the worker baseline is captured pre-fork;
     * setting it here only records the intent.
     */
    public static function coroutineCwdIsolation(?bool $on = null): bool
    {
        if ($on !== null) {
            self::$coroutine_cwd_isolation = $on;
        }
        return self::$coroutine_cwd_isolation;
    }

    /**
     * Per-coroutine locale isolation — see the $coroutine_locale_isolation
     * docblock. Asserted at App::run() boot wiring (pre-fork, so a boot-time
     * setlocale() becomes the baseline); setting here records the intent.
     */
    public static function coroutineLocaleIsolation(?bool $on = null): bool
    {
        if ($on !== null) {
            self::$coroutine_locale_isolation = $on;
        }
        return self::$coroutine_locale_isolation;
    }

    /**
     * Per-coroutine umask isolation — see the $coroutine_umask_isolation
     * docblock. Asserted at App::run() boot wiring (pre-fork baseline).
     */
    public static function coroutineUmaskIsolation(?bool $on = null): bool
    {
        if ($on !== null) {
            self::$coroutine_umask_isolation = $on;
        }
        return self::$coroutine_umask_isolation;
    }

    /**
     * Per-coroutine default-timezone isolation — see the
     * $coroutine_tz_isolation docblock. Asserted at App::run() boot wiring.
     */
    public static function coroutineTimezoneIsolation(?bool $on = null): bool
    {
        if ($on !== null) {
            self::$coroutine_tz_isolation = $on;
        }
        return self::$coroutine_tz_isolation;
    }

    /**
     * Per-coroutine mb-internal-encoding isolation — see the
     * $coroutine_mbenc_isolation docblock. Asserted at App::run() boot wiring.
     */
    public static function coroutineMbencIsolation(?bool $on = null): bool
    {
        if ($on !== null) {
            self::$coroutine_mbenc_isolation = $on;
        }
        return self::$coroutine_mbenc_isolation;
    }

    /**
     * Per-coroutine libxml error-flag isolation — see the
     * $coroutine_libxml_isolation docblock. Asserted at App::run() boot wiring.
     */
    public static function coroutineLibxmlIsolation(?bool $on = null): bool
    {
        if ($on !== null) {
            self::$coroutine_libxml_isolation = $on;
        }
        return self::$coroutine_libxml_isolation;
    }

    /**
     * Validate lifecycle mode combinations at boot.
     *
     * With ext-zealphp loaded, `superglobals(true) + enableCoroutine(true)`
     * is now SAFE — the extension saves/restores $_GET/$_POST/$_SESSION
     * per coroutine via zealphp_superglobals_save/restore(). This unlocks
     * the "full superglobals + full coroutines" mode: legacy code using
     * $_GET/$_SESSION just works, AND you get concurrent coroutine I/O.
     *
     * Without ext-zealphp (uopz fallback), the old constraint applies:
     * superglobals + coroutines would race process-wide arrays.
     *
     * @throws \RuntimeException When an unsafe combination is used without
     *   ext-zealphp to make it safe.
     */
    private static function validateLifecycleCombination(bool $sg, int $hookFlags, bool $enableCo): void
    {
        $hasZealphpExt = \extension_loaded('zealphp');

        if (!$sg && !$enableCo) {
            throw new \RuntimeException(
                'ZealPHP lifecycle: App::superglobals(false) + App::enableCoroutine(false) is not supported. '
                . 'Coroutine mode (superglobals=false) uses CoSessionManager which requires the coroutine '
                . 'scheduler for per-request RequestContext isolation. Either enable coroutines '
                . '(App::enableCoroutine(true), the default for superglobals=false) or switch to '
                . 'superglobals mode (App::superglobals(true)) for sequential operation.'
            );
        }
        if ($sg && $enableCo && !$hasZealphpExt) {
            throw new \RuntimeException(
                'ZealPHP lifecycle: App::superglobals(true) + App::enableCoroutine(true) requires '
                . 'ext-zealphp for per-coroutine superglobal isolation. Install: '
                . "'pie install zealphp/ext'. Without it, concurrent coroutines would "
                . 'race $_GET/$_POST/$_SESSION (process-wide PHP arrays). '
                . 'Alternative: use App::superglobals(false) for coroutine concurrency without ext-zealphp.'
            );
        }
        if ($sg && $hookFlags !== 0 && !$hasZealphpExt) {
            throw new \RuntimeException(
                'ZealPHP lifecycle: App::superglobals(true) + App::hookAll(non-zero) requires '
                . 'ext-zealphp for per-coroutine superglobal isolation. Install: '
                . "'pie install zealphp/ext'. Without it, hooked I/O can yield mid-request, "
                . 'exposing process-wide superglobal mutations to other coroutines.'
            );
        }
    }

    /**
     * Apache `RewriteCond %{REQUEST_FILENAME} !-d; RewriteRule ^(.+)/$ /$1 [R=301,L]`.
     * Inverse of `directorySlash()`. When true, non-directory URIs ending in `/`
     * `301`-redirect to the no-slash form. Default off.
     */
    public static function stripTrailingSlash(?bool $on = null): bool
    {
        if ($on !== null) self::$strip_trailing_slash = $on;
        return self::$strip_trailing_slash;
    }

    /**
     * PHP `session.use_strict_mode` parity (#244). When on (the default), a
     * client-supplied session id that loads an empty session is rotated to a
     * fresh server-generated id, defeating session fixation. Pass `false` to
     * accept client-supplied ids verbatim (only safe for multi-node setups
     * without shared/sticky session storage — see `App::$session_strict_mode`).
     * No-arg call returns the current value.
     */
    public static function sessionStrictMode(?bool $on = null): bool
    {
        if ($on !== null) self::$session_strict_mode = $on;
        return self::$session_strict_mode;
    }

    /**
     * Apache `ServerAdmin`. Contact email/identifier embedded in the framework's
     * default error pages. Pass `null` (or `''`) to clear.
     */
    public static function serverAdmin(?string $admin = null): ?string
    {
        if (func_num_args() > 0) {
            self::$server_admin = ($admin === '' ? null : $admin);
        }
        return self::$server_admin;
    }

    /**
     * Apache `ServerName`. Canonical host advertised in absolute redirects
     * when `useCanonicalName()` is on. Pass `null`/`''` to clear.
     */
    public static function canonicalName(?string $name = null): ?string
    {
        if (func_num_args() > 0) {
            self::$canonical_name = ($name === '' ? null : $name);
        }
        return self::$canonical_name;
    }

    /** Apache `UseCanonicalName`. See `$use_canonical_name` docblock. */
    public static function useCanonicalName(?bool $on = null): bool
    {
        if ($on !== null) self::$use_canonical_name = $on;
        return self::$use_canonical_name;
    }

    /** Apache `HostnameLookups`. Default `false` — blocking DNS is a perf cost. */
    public static function hostnameLookups(?bool $on = null): bool
    {
        if ($on !== null) self::$hostname_lookups = $on;
        return self::$hostname_lookups;
    }

    /**
     * Trusted proxy CIDRs consulted by `App::clientIp()`.
     *
     * @param  array<int, string>|null $cidrs
     * @return array<int, string>
     */
    public static function trustedProxies(?array $cidrs = null): array
    {
        if ($cidrs !== null) self::$trusted_proxies = array_values($cidrs);
        return self::$trusted_proxies;
    }

    /** Apache `LogFormat`. Resets the compiled-spec cache on set. */
    public static function accessLogFormat(?string $format = null): string
    {
        if ($format !== null) {
            self::$access_log_format = $format;
            self::$access_log_format_compiled = null;
        }
        return self::$access_log_format;
    }

    /** Apache `LimitRequestFields`. */
    public static function limitRequestFields(?int $n = null): int
    {
        if ($n !== null) self::$limit_request_fields = max(0, $n);
        return self::$limit_request_fields;
    }

    /** Apache `LimitRequestFieldSize`. Maps to OpenSwoole `http_header_buffer_size`. */
    public static function limitRequestFieldSize(?int $n = null): int
    {
        if ($n !== null) self::$limit_request_field_size = max(0, $n);
        return self::$limit_request_field_size;
    }

    /** Apache `LimitRequestLine`. Advisory; OpenSwoole's header buffer covers it. */
    public static function limitRequestLine(?int $n = null): int
    {
        if ($n !== null) self::$limit_request_line = max(0, $n);
        return self::$limit_request_line;
    }

    /**
     * Resolve the real client IP for the current request, honouring the
     * `$trusted_proxies` allow-list. Behaviour:
     *
     *   1. Read `REMOTE_ADDR` from `$g->server` (the direct peer).
     *   2. If `REMOTE_ADDR` is NOT in any `trusted_proxies` CIDR, return it as-is.
     *      The peer is untrusted, so any `X-Forwarded-*` header it sent is a lie.
     *   3. If `REMOTE_ADDR` IS in a trusted CIDR, walk `X-Forwarded-For` right-to-left
     *      (Apache `mod_remoteip` semantics) and return the rightmost IP that is
     *      NOT in `trusted_proxies` — that's the real client. If every entry is
     *      trusted, fall back to the leftmost address.
     *   4. If `X-Forwarded-For` is absent but `X-Real-IP` is present (and the peer
     *      is trusted), return `X-Real-IP`.
     *
     * Returns the empty string when no IP can be determined (`REMOTE_ADDR` missing
     * entirely — only happens for non-request contexts like CLI invocation).
     */
    public static function clientIp(): string
    {
        $g = \ZealPHP\RequestContext::instance();
        $remote = (string)($g->server['REMOTE_ADDR'] ?? '');
        if ($remote === '') {
            return '';
        }
        if (empty(self::$trusted_proxies) || !self::peerInTrustedProxies($remote)) {
            return $remote;
        }

        $forwarded = (string)($g->server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $chain = array_map('trim', explode(',', $forwarded));
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                $ip = $chain[$i];
                if ($ip === '') continue;
                if (!self::peerInTrustedProxies($ip)) {
                    return $ip;
                }
            }
            // Every hop in the chain is trusted — we cannot identify an external
            // client from a fully-trusted header. The leftmost entry is the
            // forgeable one (a client prepends it; real proxies append on the
            // right), so promoting it would trust attacker-controlled input.
            // Fall back to the address observed on the socket, matching Apache
            // mod_remoteip / nginx realip semantics.
            return $remote;
        }

        $realIp = (string)($g->server['HTTP_X_REAL_IP'] ?? '');
        if ($realIp !== '') {
            return $realIp;
        }
        return $remote;
    }

    /**
     * Match `$ip` against every entry in `App::$trusted_proxies`. Wrapper so the
     * CIDR walk lives in one place; callers pass user-controlled input here so
     * the per-entry guard inside `cidrContains()` is the only validation needed.
     */
    private static function peerInTrustedProxies(string $ip): bool
    {
        foreach (self::$trusted_proxies as $cidr) {
            if (self::cidrContains((string)$cidr, $ip)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does `$ip` fall within `$cidr`? Supports IPv4 and IPv6. A bare IP without
     * `/prefix` is treated as a single-host range (`/32` v4, `/128` v6). Returns
     * `false` on any parse failure rather than throwing — defensive for header-
     * sourced input.
     */
    /**
     * Collapse an IPv4-mapped IPv6 address (`::ffff:a.b.c.d`) to its IPv4 form
     * so an IPv4 CIDR matches a mapped peer (#433). Mirrors
     * `IpAccessMiddleware::normalizeIp` byte-for-byte. Non-mapped input is
     * returned unchanged.
     */
    private static function collapseMappedIp(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16
            && str_starts_with($bin, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            $v4 = @inet_ntop(substr($bin, 12));
            if ($v4 !== false) {
                return $v4;
            }
        }
        return $ip;
    }

    private static function cidrContains(string $cidr, string $ip): bool
    {
        if ($cidr === '' || $ip === '') return false;

        $slash = strpos($cidr, '/');
        $net   = $slash === false ? $cidr : substr($cidr, 0, $slash);
        if ($slash === false) {
            $bits = null;
        } else {
            $prefix = substr($cidr, $slash + 1);
            // Fail CLOSED on a malformed prefix. `(int)"abc"` and `(int)""` both
            // yield 0, and a `/0` mask matches every address — so `10.0.0.0/abc`
            // or a bare `10.0.0.0/` would silently trust/allow the whole internet.
            if ($prefix === '' || !ctype_digit($prefix)) {
                return false;
            }
            $bits = (int)$prefix;
        }

        // Collapse IPv4-mapped IPv6 (`::ffff:a.b.c.d`) to its IPv4 form on BOTH
        // sides before packing (#433). A dual-stack listener (or a proxy hop)
        // presents IPv4 peers in the mapped 16-byte form; without this an IPv4
        // CIDR (4-byte) could never match the peer, so a trusted proxy looked
        // untrusted and `clientIp()`'s reverse walk mis-attributed the client.
        // Mirrors IpAccessMiddleware::normalizeIp so the two matchers agree.
        $net = self::collapseMappedIp($net);
        $ip  = self::collapseMappedIp($ip);

        $netPacked = @inet_pton($net);
        $ipPacked  = @inet_pton($ip);
        if ($netPacked === false || $ipPacked === false) return false;
        // Different address families (v4 vs v6) never match.
        if (strlen($netPacked) !== strlen($ipPacked)) return false;

        $maxBits = strlen($netPacked) * 8;
        if ($bits === null) $bits = $maxBits;
        if ($bits < 0 || $bits > $maxBits) return false;
        if ($bits === 0) return true;  // 0.0.0.0/0 or ::/0 matches everything

        $fullBytes = intdiv($bits, 8);
        $remBits   = $bits % 8;
        if ($fullBytes > 0 && substr($netPacked, 0, $fullBytes) !== substr($ipPacked, 0, $fullBytes)) {
            return false;
        }
        if ($remBits === 0) return true;
        $mask = chr((0xFF << (8 - $remBits)) & 0xFF);
        return (ord($netPacked[$fullBytes]) & ord($mask))
             === (ord($ipPacked[$fullBytes])  & ord($mask));
    }

    /**
     * Canonical host for absolute URL building. Returns `$canonical_name` when
     * `useCanonicalName()` is on AND `$canonical_name` is set; otherwise returns
     * the request `Host` header (falling back to `SERVER_NAME`, then `''`). Used
     * by absolute-redirect builders that need to decide between the configured
     * server name and the client-provided `Host`.
     */
    public static function canonicalHost(): string
    {
        if (self::$use_canonical_name && self::$canonical_name !== null && self::$canonical_name !== '') {
            return self::$canonical_name;
        }
        $g = \ZealPHP\RequestContext::instance();
        $host = (string)($g->server['HTTP_HOST'] ?? $g->server['SERVER_NAME'] ?? '');
        return $host;
    }

    /**
     * Render one access-log line for the current request using `App::$access_log_format`.
     * Called by `ZealPHP\access_log()` — direct callers are rare but the helper is
     * public so user code (e.g. a custom logger middleware) can reuse it.
     *
     * The format spec is compiled to a token list on first use and cached on
     * `App::$access_log_format_compiled`; `accessLogFormat()` clears the cache when
     * the format string is changed.
     *
     * @param int        $status   Final HTTP status code (after handler + middleware)
     * @param int        $length   Response body byte count (`0` OK; `%b` emits `'-'` per CLF)
     * @param float|null $durationSec Request duration in seconds; pass `null` when unknown
     */
    public static function formatAccessLogLine(int $status, int $length, ?float $durationSec = null): string
    {
        $tokens = self::$access_log_format_compiled;
        if ($tokens === null) {
            $tokens = self::compileAccessLogFormat(self::$access_log_format);
            self::$access_log_format_compiled = $tokens;
        }

        $g = \ZealPHP\RequestContext::instance();
        $out = '';
        foreach ($tokens as $tok) {
            $out .= self::renderAccessLogToken($tok, $g, $status, $length, $durationSec);
        }
        // Log-injection defence (Apache mod_log_config parity): escape CR/LF/NUL
        // so an attacker-controlled token (REQUEST_URI, Referer, User-Agent, …)
        // cannot inject a forged physical log line. Compiled format literals are
        // single-line (spaces/quotes/brackets), so this only neutralises smuggled
        // control characters; tab/space field separators are preserved.
        return strtr($out, ["\r" => '\\r', "\n" => '\\n', "\0" => '\\0']);
    }

    /**
     * Compile an Apache `LogFormat` string into a flat token list. Supported
     * directive families (Apache `mod_log_config` subset):
     *   `%h %l %u %t %r %s %>s %b %B %D %T %m %U %q %H %v`
     *   `%{NAME}i  %{NAME}o  %{NAME}e`
     * Unknown directives are passed through verbatim (Apache compatibility:
     * `mod_log_config` logs `'-'` for unknown but compatibility matters less than
     * surfacing typos to the operator).
     *
     * @return array<int, array{kind:string, arg?:string}>
     */
    private static function compileAccessLogFormat(string $format): array
    {
        $tokens = [];
        $len = strlen($format);
        $literal = '';
        $i = 0;
        while ($i < $len) {
            $ch = $format[$i];
            if ($ch !== '%') {
                $literal .= $ch;
                $i++;
                continue;
            }
            if ($literal !== '') {
                $tokens[] = ['kind' => 'lit', 'arg' => $literal];
                $literal = '';
            }
            // Lookahead — skip the '%'
            $i++;
            if ($i >= $len) {
                $literal .= '%';
                break;
            }
            // %{NAME}i / %{NAME}o / %{NAME}e
            if ($format[$i] === '{') {
                $closeBrace = strpos($format, '}', $i + 1);
                if ($closeBrace === false || $closeBrace + 1 >= $len) {
                    $literal .= '%' . substr($format, $i);
                    $i = $len;
                    continue;
                }
                $name = substr($format, $i + 1, $closeBrace - $i - 1);
                $kindChar = $format[$closeBrace + 1];
                $kindMap = ['i' => 'header_in', 'o' => 'header_out', 'e' => 'env'];
                if (isset($kindMap[$kindChar])) {
                    $tokens[] = ['kind' => $kindMap[$kindChar], 'arg' => $name];
                } else {
                    $tokens[] = ['kind' => 'lit', 'arg' => '%{' . $name . '}' . $kindChar];
                }
                $i = $closeBrace + 2;
                continue;
            }
            // %>s — Apache's "final status after internal redirects". For us
            // it's identical to %s; we accept both and emit the same value.
            if ($format[$i] === '>' && $i + 1 < $len && $format[$i + 1] === 's') {
                $tokens[] = ['kind' => 'status'];
                $i += 2;
                continue;
            }
            $code = $format[$i];
            $i++;
            switch ($code) {
                case 'h': $tokens[] = ['kind' => 'host']; break;
                case 'a': $tokens[] = ['kind' => 'host']; break;   // %a == remote IP
                case 'l': $tokens[] = ['kind' => 'lit', 'arg' => '-']; break;
                case 'u': $tokens[] = ['kind' => 'user']; break;
                case 't': $tokens[] = ['kind' => 'time']; break;
                case 'r': $tokens[] = ['kind' => 'request']; break;
                case 's': $tokens[] = ['kind' => 'status']; break;
                case 'b': $tokens[] = ['kind' => 'bytes_clf']; break;
                case 'B': $tokens[] = ['kind' => 'bytes']; break;
                case 'D': $tokens[] = ['kind' => 'duration_us']; break;
                case 'T': $tokens[] = ['kind' => 'duration_s']; break;
                case 'm': $tokens[] = ['kind' => 'method']; break;
                case 'U': $tokens[] = ['kind' => 'url_path']; break;
                case 'q': $tokens[] = ['kind' => 'query']; break;
                case 'H': $tokens[] = ['kind' => 'protocol']; break;
                case 'v': $tokens[] = ['kind' => 'server_name']; break;
                case '%': $tokens[] = ['kind' => 'lit', 'arg' => '%']; break;
                default:
                    $tokens[] = ['kind' => 'lit', 'arg' => '%' . $code];
            }
        }
        if ($literal !== '') {
            $tokens[] = ['kind' => 'lit', 'arg' => $literal];
        }
        return $tokens;
    }

    /**
     * Render one compiled access-log token. Kept separate from the tokenizer
     * so the hot path (per-request) only does the table-lookup half; the
     * tokenize path runs once per format-string change.
     *
     * @param array{kind:string, arg?:string} $token
     */
    private static function renderAccessLogToken(array $token, \ZealPHP\RequestContext $g, int $status, int $length, ?float $durationSec): string
    {
        switch ($token['kind']) {
            case 'lit':
                return (string)($token['arg'] ?? '');
            case 'host':
                $ip = self::clientIp();
                return $ip !== '' ? $ip : '-';
            case 'user':
                $user = $g->session['username'] ?? $g->server['REMOTE_USER'] ?? null;
                return is_scalar($user) && (string)$user !== '' ? (string)$user : '-';
            case 'time':
                // Apache CLF timestamp: [day/month/year:hour:minute:second zone]
                // The Server gets the same per-second cache key the legacy
                // access_log() used, but format includes timezone.
                return '[' . date('d/M/Y:H:i:s O') . ']';
            case 'request':
                $method = (string)($g->server['REQUEST_METHOD'] ?? '-');
                $uri    = (string)($g->server['REQUEST_URI'] ?? '-');
                $proto  = (string)($g->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
                return "{$method} {$uri} {$proto}";
            case 'status':
                return (string)$status;
            case 'bytes_clf':
                return $length > 0 ? (string)$length : '-';
            case 'bytes':
                return (string)$length;
            case 'duration_us':
                return $durationSec === null ? '-' : (string)(int)round($durationSec * 1_000_000);
            case 'duration_s':
                return $durationSec === null ? '-' : (string)(int)round($durationSec);
            case 'method':
                return (string)($g->server['REQUEST_METHOD'] ?? '-');
            case 'url_path':
                $path = parse_url((string)($g->server['REQUEST_URI'] ?? ''), PHP_URL_PATH);
                return is_string($path) && $path !== '' ? $path : '-';
            case 'query':
                $qs = parse_url((string)($g->server['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
                return is_string($qs) && $qs !== '' ? '?' . $qs : '';
            case 'protocol':
                return (string)($g->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
            case 'server_name':
                return (string)($g->server['HTTP_HOST'] ?? $g->server['SERVER_NAME'] ?? '-');
            case 'header_in':
                $name = (string)($token['arg'] ?? '');
                if ($name === '') return '-';
                $key = 'HTTP_' . strtr(strtoupper($name), '-', '_');
                $val = $g->server[$key] ?? null;
                return is_scalar($val) && (string)$val !== '' ? (string)$val : '-';
            case 'header_out':
                $name = (string)($token['arg'] ?? '');
                if ($name === '' || $g->zealphp_response === null) return '-';
                foreach ($g->zealphp_response->headersList as [$k, $v]) {
                    if (strcasecmp((string)$k, $name) === 0) {
                        return (string)$v;
                    }
                }
                return '-';
            case 'env':
                $name = (string)($token['arg'] ?? '');
                if ($name === '') return '-';
                $val = $g->server[$name] ?? null;
                return is_scalar($val) && (string)$val !== '' ? (string)$val : '-';
        }
        return '';
    }

    /**
     * Like `App::include()` but returns `null` instead of `403` when the requested
     * file does not exist under the document root. Use for "try this file,
     * fall through to something else if missing" patterns:
     *
     *   `$app->route('/{slug}', function($slug) use ($app) {`
     *       `$result = App::tryInclude("/articles/{$slug}.php");`
     *       `if ($result === null) return App::tryInclude("/legacy/{$slug}.php") ?? 404;`
     *       `return $result;`
     *   `});`
     *
     * Security gating (dotfile/document-root checks) still applies — paths
     * that exist but fail the security check return `403` just like `include()`.
     * Only the "file missing" branch is rewritten to `null`.
     *
     * @param array<string, mixed> $args
     */
    public static function tryInclude(string $publicPath, array $args = []): mixed
    {
        $rel    = ltrim($publicPath, '/');
        $docAbs = self::resolveDocumentRoot();
        $absPath = realpath($docAbs . '/' . $rel);

        if ($absPath === false || !is_file($absPath)) {
            return null;
        }
        return self::include($publicPath, $args);
    }

    public static function instance(): ?App
    {
        return self::$instance;
    }

    /**
     * @return array<int, array{path:string,pattern:string,methods:array<int|string,string>,handler:callable|null,param_map:array<int,array<string, mixed>>,raw:bool,middleware:list<\Psr\Http\Server\MiddlewareInterface|string>,backend?:array{mode:string,interpreter?:string,address?:string,fcgi_params?:array<string,string>}|null}>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @return array<string, array<int, array{path:string,pattern:string,methods:array<int|string,string>,handler:callable|null,param_map:array<int,array<string, mixed>>,raw:bool,middleware:list<\Psr\Http\Server\MiddlewareInterface|string>,backend?:array{mode:string,interpreter?:string,address?:string,fcgi_params?:array<string,string>}|null}>>
     */
    public function routesByMethod(): array
    {
        return $this->routes_by_method;
    }

    /**
     * @return array<string, array<string, array{path:string,pattern:string,methods:array<int|string,string>,handler:callable|null,param_map:array<int,array<string, mixed>>,raw:bool,middleware:list<\Psr\Http\Server\MiddlewareInterface|string>,backend?:array{mode:string,interpreter?:string,address?:string,fcgi_params?:array<string,string>}|null}>>
     */
    public function routesByExactMethod(): array
    {
        return $this->routes_by_exact_method;
    }

    protected function isExactRoutePath(string $path): bool
    {
        return preg_match('/[\\\\^$.|?*+()[\\]{}]/', $path) === 0;
    }

    /**
     * Introspect the routing + middleware topology — the data behind the
     * "middleware visualizer" (think Traefik's dashboard for your own routes).
     * For each registered route it reports the HTTP methods, path, the resolved
     * per-route middleware chain (outer → inner), and a handler label; plus the
     * global middleware chain (which wraps every route) and the registered
     * named aliases.
     *
     * Works before OR after `App::run()`: after boot each route's middleware is
     * resolved to instances (class short-names); before boot, alias strings are
     * shown verbatim. The global chain lists `App::$middleware_wait_stack` in
     * execution order (first-added = outermost), with the router innermost.
     *
     * Also reports the `App::when` path-scoped chains (`when`) — each `{scope,
     * middleware}` pair, in registration order (first = outermost). These wrap
     * every matching request (route or `/api/*`), so they are not tied to a
     * single route row.
     *
     * @return array{
     *   global: list<string>,
     *   aliases: list<string>,
     *   when: list<array{scope: string, middleware: list<string>}>,
     *   routes: list<array{methods: list<string>, path: string, middleware: list<string>, handler: string}>
     * }
     */
    public function describeRoutes(): array
    {
        $global = [];
        foreach (self::$middleware_wait_stack as $mw) {
            $global[] = self::middlewareDisplayName($mw);
        }
        $global[] = 'ResponseMiddleware (router)';

        // App::when path scopes — prefer the boot-compiled instances; fall back
        // to the raw specs when called before App::run().
        $when = [];
        if (self::$when_middleware_compiled !== []) {
            foreach (self::$when_middleware_compiled as $entry) {
                $names = [];
                foreach ($entry['chain'] as $mw) {
                    $names[] = self::middlewareDisplayName($mw);
                }
                $when[] = ['scope' => $entry['key'], 'middleware' => $names];
            }
        } else {
            foreach (self::$when_middleware as $entry) {
                $names = [];
                foreach ($entry['spec'] as $mw) {
                    $names[] = self::middlewareDisplayName($mw);
                }
                $when[] = ['scope' => $entry['key'], 'middleware' => $names];
            }
        }

        $routes = [];
        foreach ($this->routes as $route) {
            $chain = [];
            foreach ($route['middleware'] as $mw) {
                $chain[] = self::middlewareDisplayName($mw);
            }
            $backend = $route['backend'] ?? null;
            $routes[] = [
                'methods'    => array_values($route['methods']),
                'path'       => $route['path'],
                'middleware' => $chain,
                'backend'    => is_array($backend) ? $backend : null,
                'handler'    => self::handlerDisplayName($route['handler']),
            ];
        }

        return [
            'global'  => $global,
            'aliases' => array_keys(self::$middleware_aliases),
            'when'    => $when,
            'routes'  => $routes,
        ];
    }

    /**
     * Human-readable label for a middleware spec entry — the class short-name
     * for an instance, the alias string (verbatim) for an unresolved reference.
     */
    private static function middlewareDisplayName(mixed $mw): string
    {
        if ($mw instanceof \Psr\Http\Server\MiddlewareInterface) {
            $class = $mw::class;
            $pos = strrpos($class, '\\');
            return $pos === false ? $class : substr($class, $pos + 1);
        }
        if (is_string($mw)) {
            return $mw;
        }
        return 'middleware';
    }

    /**
     * Human-readable label for a route handler — `Class::method` for an array
     * callable, otherwise `Closure`/`function`.
     */
    private static function handlerDisplayName(mixed $handler): string
    {
        if (is_array($handler) && count($handler) === 2) {
            $obj = $handler[0];
            $cls = is_object($obj) ? $obj::class : (is_string($obj) ? $obj : 'callable');
            return $cls . '::' . (is_string($handler[1]) ? $handler[1] : 'method');
        }
        if (is_string($handler)) {
            return $handler . '()';
        }
        if ($handler instanceof \Closure) {
            return 'Closure';
        }
        return $handler === null ? '—' : 'callable';
    }

    /**
     * Register a WebSocket endpoint. Returns void — the OpenSwoole
     * `WebSocket\Server` is owned by the framework lifecycle, not the
     * route registration. To push to a client you have **two** ways to
     * reach the server object:
     *
     * 1. **Inside any callback** — the first argument IS the server:
     *
     *    ```php
     *    $app->ws('/ws/chat',
     *        onMessage: function (\OpenSwoole\WebSocket\Server $server, $frame) {
     *            $server->push($frame->fd, "echo: {$frame->data}");
     *        },
     *    );
     *    ```
     *
     * 2. **From anywhere else** (route handlers, App::subscribe handlers,
     *    sidecar processes, etc.) — call `App::getServer()`:
     *
     *    ```php
     *    App::subscribe('chat:broadcast', function (string $payload) {
     *        $server = App::getServer();
     *        if ($server instanceof \OpenSwoole\WebSocket\Server) {
     *            foreach (yourLocalFds() as $fd) {
     *                if ($server->isEstablished($fd)) {
     *                    $server->push($fd, $payload);
     *                }
     *            }
     *        }
     *    });
     *    ```
     *
     * For cluster-wide messaging across multiple ZealPHP processes,
     * use the higher-level `WSRouter::sendToClient($clientId, $payload)`
     * + `WSRouter::room($name)->push($msg)` — they handle the
     * server-lookup + cross-node routing for you.
     *
     * @param string        $path      URI path, e.g. `'/ws/chat'`
     * @param callable      $onMessage `function(\OpenSwoole\WebSocket\Server $server, OpenSwoole\WebSocket\Frame $frame, G $g)` — called for each message
     * @param callable|null $onOpen    `function(\OpenSwoole\WebSocket\Server $server, OpenSwoole\Http\Request $request, G $g)` — called on connect
     * @param callable|null $onClose   `function(\OpenSwoole\WebSocket\Server $server, int $fd, G $g)`     — called on disconnect
     */
    public function ws(string $path, callable $onMessage, ?callable $onOpen = null, ?callable $onClose = null): void
    {
        $this->ws_routes[$path] = [
            'message' => $onMessage,
            'open'    => $onOpen,
            'close'   => $onClose,
        ];
    }

    /**
     * @return array<string, array{message: callable, open: callable|null, close: callable|null}>
     */
    public function wsRoutes(): array
    {
        return $this->ws_routes;
    }

    // -----------------------------------------------------------------------
    // Timer helpers (must be called inside a coroutine context: workerStart,
    // request handler, or onWorkerStart callback)
    // -----------------------------------------------------------------------

    /**
     * Dispatch payload for the `$server->on('task', …)` callback.
     *
     * OpenSwoole 22.x calls task handlers with TWO different signatures
     * depending on `task_enable_coroutine`:
     *
     *   true  → ($server, OpenSwoole\Server\Task $task)        // 2-arg
     *   false → ($server, $id, $worker_id, $data)              // 4-arg
     *
     * Our default is `task_enable_coroutine => true`, so the 2-arg form
     * is the production hot path; apps that opt back out get the 4-arg
     * form. Accepting both shapes here means neither a user override
     * nor an OpenSwoole minor-version shift can throw `ArgumentCountError`
     * mid-worker. See issue #103.
     *
     * @param  list<mixed> $rest  Variadic args excluding $server.
     * @return array{task: array<mixed>, result: mixed}|false
     */
    public static function dispatchTaskCallback(array $rest): array|false
    {
        if (count($rest) === 1 && is_object($rest[0])) {
            // Coroutine task path: $rest[0] is OpenSwoole\Server\Task
            $data = $rest[0]->data ?? [];
        } elseif (count($rest) === 3) {
            // Legacy 4-arg path: ($id, $worker_id, $data)
            $data = $rest[2];
        } else {
            elog('Task callback received unexpected arity ' . count($rest), 'error');
            return false;
        }
        if (!is_array($data)) {
            elog('Task payload not an array; dropping', 'error');
            return false;
        }
        $handler = $data['handler'] ?? '';
        if (!is_string($handler) || $handler === '') {
            elog('Task payload missing string "handler"; dropping', 'error');
            return false;
        }
        $args = $data['args'] ?? [];
        if (!is_array($args)) {
            elog('Task payload "args" must be an array; dropping', 'error');
            return false;
        }
        $_func = basename($handler);
        if (file_exists(self::$cwd . $handler . '.php')) {
            include self::$cwd . $handler . '.php';
            /** @var callable $fn */
            $fn = $$_func;
            $result = $fn(...$args);
            unset($$_func);
        } else {
            elog("Task handler not found: $handler", 'error');
            $result = false;
        }
        elog((string) json_encode([$data, $result]), 'task');
        return [
            'task'   => $data,
            'result' => $result,
        ];
    }

    /**
     * #42 — make the CURRENT (child) coroutine inherit the spawning request's
     * context. Two layers: `RequestContext::instance()` walks the
     * parent-coroutine chain automatically (so `$g` is the request's), and —
     * in coroutine-legacy, where `$g->server` et al. are live aliases of the
     * process superglobals — `zealphp_superglobals_adopt()` (ext-zealphp
     * 0.3.43+) gives this coroutine its OWN superglobal snapshot lane: its
     * first yield CAPTURES the live view (the spawning request's state)
     * without clearing it, so `$_SERVER`/`$_GET`/… survive the child's own
     * yields and the parent is never stolen from. Safe no-op outside a
     * coroutine or without the ext function. Called automatically by
     * `App::go()` / `App::parallel()` / `App::parallelLimit()`.
     */
    public static function adoptRequestContext(): void
    {
        if (self::$coroutine_isolated_superglobals
            && \function_exists('zealphp_superglobals_adopt')
        ) {
            (\zealphp_superglobals_adopt(...))();
        }
        // Touch the context so the parent-chain adoption happens eagerly at
        // spawn time (deterministic) rather than lazily at the first $g read.
        RequestContext::instance();
    }

    /**
     * Request-aware `go()` — spawns a child coroutine that INHERITS the
     * current request's context (`$g` + the live superglobals, #42). Use
     * inside handlers instead of raw `go()` whenever the child reads
     * `$g->server` / `$_SERVER` / `$_GET` etc. Returns the child's coroutine
     * id, or false if creation failed.
     */
    public static function go(callable $fn, mixed ...$args): int|false
    {
        return \OpenSwoole\Coroutine::create(function () use ($fn, $args): void {
            self::adoptRequestContext();
            $fn(...$args);
        });
    }

    /**
     * Fork-join helper — runs every closure in `$tasks` in its own
     * coroutine in parallel and returns the results in input order.
     *
     * Call inside a coroutine context (request handler, onWorkerStart,
     * etc) — outside one, the call is wrapped in `Coroutine::run()` so
     * sync-mode callers also work. Each task gets its own coroutine via
     * `go()`; the caller blocks on a `WaitGroup` until all finish.
     *
     * Exceptions in tasks propagate as `null` in the result slot AND
     * the exception is re-thrown via `array_walk` from the caller's
     * coroutine — failing fast on the first error.
     *
     * @template T
     * @param  list<callable(): T> $tasks
     * @return list<T|null>
     */
    public static function parallel(array $tasks): array
    {
        if ($tasks === []) { return []; }
        if (\OpenSwoole\Coroutine::getCid() < 0) {
            // Outside any coroutine. In a reactor worker (mixed / legacy-cgi,
            // enable_coroutine off) a nested Coroutine::run() NEVER returns and
            // deadlocks the worker (#429); run the tasks sequentially instead —
            // correct results in input order, just without the concurrency
            // those modes can't provide anyway. Only a standalone CLI / unit-
            // test process (no server) can safely start a scheduler here.
            if (self::$server !== null) {
                return self::runTasksSequentially($tasks);
            }
            // Coroutine::run swallows uncaught throws — capture + rethrow
            // outside so callers see the same exception semantics they'd
            // get from inside a request coroutine.
            $out = [];
            $err = null;
            \OpenSwoole\Coroutine::run(function () use ($tasks, &$out, &$err): void {
                try { $out = self::parallel($tasks); }
                catch (\Throwable $e) { $err = $e; }
            });
            if ($err !== null) { throw $err; }
            return $out;
        }
        $n       = count($tasks);
        $done    = new \OpenSwoole\Coroutine\Channel($n);
        /** @var array<int, T|null> $results */
        $results = array_fill(0, $n, null);
        /** @var array<int, \Throwable> $errors */
        $errors  = [];
        foreach ($tasks as $i => $task) {
            \OpenSwoole\Coroutine::create(function () use ($task, $i, &$results, &$errors, $done): void {
                self::adoptRequestContext(); // #42 — inherit the request's superglobal lane
                try { $results[$i] = $task(); }
                catch (\Throwable $e) { $errors[$i] = $e; }
                finally { $done->push(true); }
            });
        }
        // Block until every spawned cor reports done.
        for ($i = 0; $i < $n; $i++) { $done->pop(); }
        if ($errors !== []) {
            // Surface the first failure to the caller; the rest are discarded.
            throw reset($errors);
        }
        return array_values($results);
    }

    /**
     * Sequential fallback for `parallel()` when no coroutine scheduler is
     * available (reactor worker in mixed / legacy-cgi — #429). Runs each task
     * in input order; the first throw propagates immediately, matching
     * `parallel()`'s fail-fast-on-first-error contract.
     *
     * @template T
     * @param  list<callable(): T> $tasks
     * @return list<T>
     */
    private static function runTasksSequentially(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $task) {
            $results[] = $task();
        }
        return $results;
    }

    /**
     * Bounded fan-out — runs `$fn` over each item with at most
     * `$concurrency` in-flight coroutines at a time. Results keyed by
     * the input's original keys.
     *
     * @template K of array-key
     * @template V
     * @template R
     * @param  array<K, V>            $items
     * @param  callable(V, K=): R     $fn
     * @return array<K, R|null>
     */
    public static function parallelLimit(array $items, callable $fn, int $concurrency = 10): array
    {
        if ($items === []) { return []; }
        if ($concurrency < 1) {
            throw new \InvalidArgumentException('parallelLimit: $concurrency must be >= 1');
        }
        if (\OpenSwoole\Coroutine::getCid() < 0) {
            // Reactor worker (mixed / legacy-cgi): nesting Coroutine::run()
            // deadlocks the worker (#429) — degrade to sequential mapping
            // (correct results, no concurrency). Standalone CLI starts a real
            // scheduler.
            if (self::$server !== null) {
                $results = [];
                foreach ($items as $k => $item) { $results[$k] = $fn($item, $k); }
                return $results;
            }
            $out = [];
            $err = null;
            \OpenSwoole\Coroutine::run(function () use ($items, $fn, $concurrency, &$out, &$err): void {
                try { $out = self::parallelLimit($items, $fn, $concurrency); }
                catch (\Throwable $e) { $err = $e; }
            });
            if ($err !== null) { throw $err; }
            return $out;
        }
        $sem  = new \OpenSwoole\Coroutine\Channel($concurrency);
        for ($i = 0; $i < $concurrency; $i++) { $sem->push(true); }
        $n    = count($items);
        $done = new \OpenSwoole\Coroutine\Channel($n);
        $results = [];
        foreach (array_keys($items) as $k) { $results[$k] = null; }
        $errors = [];
        foreach ($items as $k => $item) {
            \OpenSwoole\Coroutine::create(function () use ($k, $item, $fn, &$results, &$errors, $sem, $done): void {
                self::adoptRequestContext(); // #42 — inherit the request's superglobal lane
                $sem->pop();
                try { $results[$k] = $fn($item, $k); }
                catch (\Throwable $e) { $errors[$k] = $e; }
                finally {
                    $sem->push(true);
                    $done->push(true);
                }
            });
        }
        for ($i = 0; $i < $n; $i++) { $done->pop(); }
        if ($errors !== []) { throw reset($errors); }
        return $results;
    }

    /**
     * Register a signal handler. Fires in the master process by
     * default; pass `$workerOnly=true` to fire only inside workers.
     * Multiple handlers per signal allowed (called in registration order).
     *
     * Built on `OpenSwoole\Process::signal()`. Common use cases:
     *
     *   - `SIGHUP`  → config reload
     *   - `SIGUSR1` → stats dump
     *   - `SIGUSR2` → debug snapshot
     *
     * ```php
     * App::onSignal(SIGHUP, function (): void {
     *     // reload routing or config
     * });
     * ```
     *
     * Must be called BEFORE `App::run()`.
     */
    public static function onSignal(int $signal, callable $handler, bool $workerOnly = false): void
    {
        if (self::$reloading) { return; } // route hot-reload re-include — keep boot registration
        self::$signalHandlers[$signal][] = ['handler' => $handler, 'worker_only' => $workerOnly];
    }

    /**
     * Internal: wire registered signal handlers into the OpenSwoole
     * process lifecycle. Called from `App::run()` (master) and via
     * `onWorkerStart` (workers).
     */
    public static function applySignalHandlersFor(string $context): void
    {
        foreach (self::$signalHandlers as $signal => $handlers) {
            foreach ($handlers as $entry) {
                $workerOnly = (bool)$entry['worker_only'];
                if ($workerOnly && $context !== 'worker') { continue; }
                if (!$workerOnly && $context !== 'master') { continue; }
                $cb = $entry['handler'];
                \OpenSwoole\Process::signal($signal, function () use ($cb): void {
                    try { $cb(); }
                    catch (\Throwable $e) {
                        error_log('App::onSignal handler threw: ' . $e->getMessage());
                    }
                });
            }
        }
    }

    /**
     * Register a long-running sidecar process — runs alongside the HTTP/WS
     * server, managed by the OpenSwoole master (same fate-sharing: dies when
     * the server stops, respawned on graceful reload). Different from task
     * workers (which are queue consumers) and worker hooks (which run inside
     * HTTP workers); these are independent processes for background work
     * like log shippers, file watchers, scheduled-job runners, OAuth token
     * refreshers, etc.
     *
     * ```php
     * App::addProcess('log-shipper', function (\OpenSwoole\Process $p): void {
     *     while ($line = fgets(STDIN)) {
     *         shipToS3($line);
     *     }
     * }, workers: 1, coroutine: true);
     * ```
     *
     * The `$callable` receives the `OpenSwoole\Process` instance (call
     * `$p->exit()` to shut down cleanly; respawn happens automatically when
     * configured).
     *
     * `$workers` spawns N independent copies under the same name (suffixed
     * with index `0..N-1` internally). `$coroutine` enables OpenSwoole's
     * coroutine runtime inside the sidecar — `true` by default so the same
     * `usleep` / curl / PDO yield semantics work that the HTTP workers get.
     *
     * Must be called BEFORE `App::run()` so registrations are visible at
     * server-build time.
     *
     * Mirrors `$server->addProcess()` from the OpenSwoole API.
     */
    public static function addProcess(string $name, callable $callable, int $workers = 1, bool $coroutine = true): void
    {
        if (self::$reloading) { return; } // route hot-reload re-include — sidecar already added at boot
        if ($workers < 1) {
            throw new \InvalidArgumentException('addProcess: $workers must be >= 1');
        }
        if (isset(self::$processHandlers[$name])) {
            throw new \InvalidArgumentException("addProcess: process name '$name' already registered");
        }
        self::$processHandlers[$name] = [
            'callable' => $callable,
            'workers'  => $workers,
            'coroutine'=> $coroutine,
        ];
    }

    /**
     * BC alias for `addProcess()`. The on*-prefixed name was a misnomer
     * (this method REGISTERS a process — it isn't an event). New code
     * should call `App::addProcess()`; pairs symmetrically with
     * OpenSwoole's `$server->addProcess()` API.
     */
    public static function onProcess(string $name, callable $callable, int $workers = 1, bool $coroutine = true): void
    {
        self::addProcess($name, $callable, $workers, $coroutine);
    }

    /**
     * Internal: wire registered sidecar processes into the OpenSwoole
     * server via $server->addProcess(). Called from App::run() after
     * the server is constructed but before start().
     */
    private static function wireProcessHandlers(): void
    {
        if (self::$processBootWired) { return; }
        self::$processBootWired = true;
        $server = self::$server;
        if ($server === null) { return; }
        foreach (self::$processHandlers as $name => $cfg) {
            $cb       = $cfg['callable'];
            $workers  = $cfg['workers'];
            $useCo    = $cfg['coroutine'];
            for ($i = 0; $i < $workers; $i++) {
                $procName = $workers > 1 ? "{$name}-{$i}" : $name;
                // Process constructor's enable_coroutine=true initializes
                // coroutine state in the PARENT process — which triggers the
                // eventLoop early and breaks $server->start(). We set it
                // false and run the user callable inside Coroutine::run
                // INSIDE the child instead, getting the same effect without
                // contaminating the master's event-loop state.
                $process = new \OpenSwoole\Process(
                    function (\OpenSwoole\Process $p) use ($cb, $procName, $useCo): void {
                        @cli_set_process_title("zealphp:{$procName}");
                        if ($useCo) {
                            \OpenSwoole\Coroutine::run(function () use ($cb, $p, $procName): void {
                                try { $cb($p); }
                                catch (\Throwable $e) {
                                    error_log("App::addProcess({$procName}) threw: " . $e->getMessage());
                                }
                            });
                        } else {
                            try { $cb($p); }
                            catch (\Throwable $e) {
                                error_log("App::addProcess({$procName}) threw: " . $e->getMessage());
                            }
                        }
                    },
                    /* redirect_stdin_stdout */ false,
                    /* pipe_type */            0,
                    /* enable_coroutine */     false,
                );
                $server->addProcess($process);
            }
        }
    }

    /**
     * Aggregated framework health snapshot — backends, pool, workers,
     * memory, uptime, plus per-subsystem counters (X-4). Designed for
     * `/healthz` middleware exposure + Prometheus exposition (see
     * `App::onSchedule` v0.3.0 P1.10 plan).
     *
     * Subsystems are queried defensively — each one is wrapped in a
     * try/catch so a single subsystem's failure (e.g. WSRouter not
     * initialised yet) doesn't take down /healthz.
     *
     * @return array<string, mixed>
     */
    public static function stats(): array
    {
        $bootTs = self::$bootedAt ?? null;
        return [
            'workers' => [
                'http' => self::$worker_num,
                'task' => self::$task_worker_num,
            ],
            'store'      => self::safeStats(fn(): array => \ZealPHP\Store::stats()),
            'cache'      => self::safeStats(fn(): array => \ZealPHP\Cache::stats()),
            'ws_router'  => self::safeStats(fn(): array => \ZealPHP\WSRouter::stats()->snapshot()),
            'memory'     => [
                'usage_bytes' => memory_get_usage(true),
                'peak_bytes'  => memory_get_peak_usage(true),
            ],
            'uptime_sec' => $bootTs !== null ? max(0, time() - $bootTs) : 0,
            'php'        => PHP_VERSION,
            'backends'   => self::safeStats(fn(): array => [
                'store_kind'   => self::backendKind(\ZealPHP\Store::defaultBackend()),
                'counter_kind' => self::backendKind(\ZealPHP\Counter::defaultBackend()),
            ]),
        ];
    }

    /**
     * @param  callable(): array<string, mixed> $fn
     * @return array<string, mixed>
     */
    private static function safeStats(callable $fn): array
    {
        try { return $fn(); } catch (\Throwable $e) { return ['_error' => $e->getMessage()]; }
    }

    private static function backendKind(object $backend): string
    {
        $cls = $backend::class;
        $base = strrchr($cls, '\\');
        return $base === false ? $cls : substr($base, 1);
    }

    /** Recurring timer: calls `$fn` every `$ms` milliseconds in this worker. */
    public static function tick(int $ms, callable $fn): int
    {
        return \OpenSwoole\Timer::tick($ms, $fn);
    }

    /** One-shot timer: calls `$fn` once after `$ms` milliseconds. */
    public static function after(int $ms, callable $fn): int
    {
        return \OpenSwoole\Timer::after($ms, $fn);
    }

    /** Cancel a timer returned by `tick()` or `after()`. */
    public static function clearTimer(int $id): void
    {
        \OpenSwoole\Timer::clear($id);
    }

    /**
     * Register a callback to run inside every worker's `workerStart` event.
     * Use this to start per-worker timers, warm caches, open connections, etc.
     * Called as: `$fn($server, $workerId)`
     */
    public static function onWorkerStart(callable $fn): void
    {
        if (self::$reloading) { return; } // route hot-reload re-include — hook already registered at boot
        self::$workerStartHooks[] = $fn;
    }

    /**
     * Schedule the deterministic session garbage collector on a worker-0 timer.
     *
     * ZealPHP replaced PHP's probabilistic per-request session GC, so without
     * this nothing ever reclaims expired sessions: default-storage `sess_*`
     * files accumulate until inodes exhaust, and a leaked `PHPSESSID` stays
     * replayable forever. Mirrors `Cache::registerGc()` — one worker (id 0)
     * runs the sweep so N workers don't N-times-duplicate it. No-op when the
     * session lifecycle is disabled (e.g. a Symfony/Laravel bridge owns
     * sessions). Interval is env-tunable via `ZEALPHP_SESSION_GC_INTERVAL`
     * (milliseconds, default 10 min); the max-lifetime is `App::$session_ttl`.
     * Called from `App::run()` before `$server->start()`.
     */
    private static function registerSessionGc(): void
    {
        if (!self::$session_lifecycle) {
            return;
        }
        $intervalMs = (int) (getenv('ZEALPHP_SESSION_GC_INTERVAL') ?: 600000);
        if ($intervalMs < 1000) {
            $intervalMs = 600000;
        }
        $maxLifetime = max(1, self::$session_ttl);
        self::onWorkerStart(function ($server, $workerId) use ($intervalMs, $maxLifetime): void {
            if ($workerId !== 0) {
                return;
            }
            self::tick($intervalMs, static function () use ($maxLifetime): void {
                \ZealPHP\Session\zeal_session_gc($maxLifetime);
            });
        });
    }

    /**
     * Coroutine-aware autoload serializer — the HAZARD-2 correctness fix for
     * coroutine-legacy mode.
     *
     * THE RACE: under `silentRedeclare` the ext isolates `EG(in_autoload)` per
     * coroutine, so two concurrent coroutines that both reference an as-yet-
     * unloaded class each enter autoload, each compile it, and the first-wins
     * merge orphans the loser class-entry. A loser-CE object can then escape to
     * `new`, while the boot-compiled closure's type-hint cached the winner CE →
     * `TypeError: …must be of type X, X given` → uncaught fatal → the worker
     * dies → OpenSwoole respawns it → the fresh worker re-races its first
     * concurrent batch → crash → an endless respawn cascade. (ASAN/Valgrind both
     * confirm this is memory-safe — it is a class-identity correctness bug, not
     * corruption.)
     *
     * THE FIX: serialize autoload of any given class name so EXACTLY ONE
     * coroutine compiles it; the rest wait and resolve to the single winner.
     * The per-class gate channels live in a `use`-captured object — object
     * property mutations bypass `coroutineGlobalsIsolation`/static isolation
     * (which is why a `$GLOBALS`- or `static`-backed gate does NOT work here:
     * each coroutine would see its own isolated copy and the gate would never
     * actually be shared).
     *
     * Installed per worker via `onWorkerStart`: it captures the autoloaders the
     * app registered at bootstrap, unregisters them, and re-registers ONE
     * wrapper that runs them under the gate. Generic — no Composer-specific API.
     * Idempotent via a class-static flag (class statics are process-global; only
     * function-local `static $x` is per-coroutine isolated).
     */
    private static function installCoroutineAutoloadSerializer(): void
    {
        if (self::$autoloadSerializerInstalled) {
            return;
        }
        self::$autoloadSerializerInstalled = true;

        $existing = \spl_autoload_functions() ?: [];
        foreach ($existing as $fn) {
            \spl_autoload_unregister($fn);
        }

        // Shared per-worker gate registry. A typed anonymous-class instance:
        // all coroutines see the SAME object (captured by handle), and its
        // property mutations are NOT subject to per-coroutine $GLOBALS/static
        // isolation (an object's storage is neither a superglobal nor a
        // function-local static). A $GLOBALS- or static-backed registry does
        // NOT work here — each coroutine would get its own isolated copy.
        $gates = new class {
            /** @var array<string, \OpenSwoole\Coroutine\Channel> class (lc) → gate */
            public array $ch = [];
            /** @var array<string, int> class (lc) → cid currently loading it */
            public array $owner = [];
        };

        $runChain = static function (string $class) use ($existing): bool {
            foreach ($existing as $fn) {
                $fn($class);
                if (self::symbolDefined($class)) {
                    return true;
                }
            }
            return self::symbolDefined($class);
        };

        // spl_autoload_register callbacks are void — the engine re-checks the
        // class table after the callback runs. We use $runChain purely for its
        // side effect (defining the symbol); control flow is early returns.
        \spl_autoload_register(static function (string $class) use ($runChain, $gates): void {
            if (self::symbolDefined($class)) {
                return;
            }
            // Outside a coroutine (master/boot): no channels — load directly.
            if (!\class_exists(\OpenSwoole\Coroutine::class) || \OpenSwoole\Coroutine::getCid() < 0) {
                $runChain($class);
                return;
            }
            $cid = \OpenSwoole\Coroutine::getCid();
            if (isset($gates->ch[$class])) {
                // RE-ENTRANCY GUARD: if THIS coroutine already owns the gate for
                // $class, a nested autoload of it fired mid-load (e.g. a warning
                // during the include routed through the error handler, which
                // referenced $class again). We must NOT wait on our own gate —
                // that self-blocks and recurses until the stack blows. Fall back
                // to whatever is defined so far; the outer load completes + wakes.
                if (($gates->owner[$class] ?? -1) === $cid) {
                    return;
                }
                // Another coroutine is loading it — wait, then re-signal the next.
                $gates->ch[$class]->pop(5.0);
                $gates->ch[$class]->push(true);
                if (self::symbolDefined($class)) {
                    return;
                }
                // Wait timed out and the class still isn't defined (the loader is
                // pathologically slow or died). Don't fail — load it ourselves as
                // a last resort. A duplicate compile here is memory-safe: the
                // ext's first-wins merge orphans the loser CE during autoload
                // (ext-zealphp 0.3.20), so correctness/safety hold either way.
                $runChain($class);
                return;
            }
            // We are the first — claim the gate (atomic: nothing yields between
            // the isset() check above and this assignment), load, then wake.
            $ch = new \OpenSwoole\Coroutine\Channel(1);
            $gates->ch[$class] = $ch;
            $gates->owner[$class] = $cid;
            try {
                $runChain($class);
            } finally {
                unset($gates->owner[$class]);
                $ch->push(true);
            }
        }, true, true);
    }

    /**
     * Warm the per-request framework classes at worker start so the first
     * concurrent request wave never autoloads them under coroutine overlap.
     * The OnRequest closure and CoSessionManager instantiate the Request /
     * Response wrappers and LazyServerRequest per request; cold concurrent
     * autoload of these races to a transient "class not found" Fatal that hangs
     * the client. Loaded here (worker start = single coroutine), they are
     * defined before any request coroutine runs. See the onWorkerStart
     * registration in run() for the full failure mode.
     */
    private static function preloadRequestPathClasses(): void
    {
        /** @var list<class-string> $classes */
        $classes = [
            // OpenSwoole PSR-7 message stack — every class the response /
            // request emission path (new Response(), LazyServerRequest, the
            // middleware StackHandler) instantiates per request.
            \OpenSwoole\Core\Psr\Message::class,
            \OpenSwoole\Core\Psr\Stream::class,
            \OpenSwoole\Core\Psr\Response::class,
            \OpenSwoole\Core\Psr\Request::class,
            \OpenSwoole\Core\Psr\ServerRequest::class,
            \OpenSwoole\Core\Psr\Uri::class,
            \OpenSwoole\Core\Psr\UploadedFile::class,
            \OpenSwoole\Core\Psr\Middleware\StackHandler::class,
            // Framework request/response wrappers.
            \ZealPHP\HTTP\Request::class,
            \ZealPHP\HTTP\Response::class,
            \ZealPHP\HTTP\LazyServerRequest::class,
        ];
        foreach ($classes as $c) {
            \class_exists($c);
        }
        // User-registered hot-path classes (controllers, services, any class
        // lazily instantiated under request concurrency). class_exists() with
        // autoload triggers the loader once here, in the single-coroutine worker
        // start — never on the concurrent cold path.
        foreach (self::$preload_classes as $c) {
            self::warmClass($c);
        }
    }

    /**
     * Bulk warming (whole Composer classmap + registered directory trees) —
     * runs in the MASTER process before `$server->start()`, NOT in a worker
     * coroutine. This is load-bearing: warming hundreds/thousands of arbitrary
     * classes inside the coroutine `onWorkerStart` is unsafe — a class with
     * load-time I/O (or anything the runtime hooks coroutinize) YIELDS, and the
     * worker then accepts requests MID-WARMUP → cold concurrent compile → the
     * duplicate-CE / unlinked race we are trying to avoid (empirically: a
     * classmap warm in onWorkerStart reintroduced HAZARD-2 TypeErrors). The
     * master has no coroutine scheduler, so every load is blocking + atomic; the
     * warmed (linked) classes are then COW-forked into every worker. Same model
     * as PHP's `opcache.preload`. No-op unless `preloadClassmap()`/`preloadDir()`
     * registered something.
     */
    private static function warmBulkPreloads(): void
    {
        foreach (self::$preload_dirs as $dir) {
            self::warmDir($dir);
        }
        if (self::$preload_classmap) {
            self::warmComposerClassmap();
        }
    }

    /**
     * Trigger one symbol's autoload+link, swallowing load errors (a class with
     * an unmet dependency must not abort worker start). class_exists() fires the
     * registered autoloader for classes/interfaces/traits/enums alike.
     */
    private static function warmClass(string $name): void
    {
        try {
            \class_exists($name);
        } catch (\Throwable $e) {
            // A symbol that can't load standalone (missing parent/ext) — skip it;
            // it simply won't be warmed. elog at debug so it's diagnosable.
            elog('[preload] skip ' . $name . ': ' . $e->getMessage(), 'debug');
        }
    }

    /**
     * Register classes to compile at worker start so they are never cold-
     * autoloaded under request concurrency (coroutine-legacy mode). Call BEFORE
     * `App::run()`. Idempotent; duplicates are harmless. See `App::$preload_classes`
     * for the full rationale and the failure mode it prevents.
     *
     * @param class-string ...$classes
     */
    public static function preloadClasses(string ...$classes): void
    {
        foreach ($classes as $c) {
            self::$preload_classes[] = $c;
        }
    }

    /**
     * Opt into warming the ENTIRE Composer classmap at worker start — the
     * structural fix so a user app's own classes (autoloaded on demand inside
     * handlers) are born LINKED, never compiled on the concurrent cold path.
     * Call BEFORE `App::run()`. Requires `composer dump-autoload --optimize` for
     * the classmap to be complete. See `App::$preload_classmap`.
     */
    public static function preloadClassmap(bool $enable = true): void
    {
        self::$preload_classmap = $enable;
    }

    /**
     * Register a source directory to warm at worker start: every class /
     * interface / trait / enum declared under `$dir` (recursively) is
     * autoloaded+linked single-coroutine before request concurrency. Use this
     * for PSR-4 apps WITHOUT an optimized classmap, or any app whose own
     * autoloader (not Composer's classmap) resolves these symbols. Call BEFORE
     * `App::run()`. The symbol must still be resolvable by a registered
     * autoloader — a pure `require_once` legacy app (no autoloader) won't warm
     * this way; run such apps in `legacy-cgi` mode (no coroutine race) instead.
     */
    public static function preloadDir(string $dir): void
    {
        self::$preload_dirs[] = $dir;
    }

    /**
     * Warm every class Composer's registered loaders know about. Iterates the
     * classmap of each registered `Composer\Autoload\ClassLoader` and triggers
     * its autoload (single-coroutine at worker start → whole hierarchy linked).
     */
    private static function warmComposerClassmap(): void
    {
        if (!\class_exists(\Composer\Autoload\ClassLoader::class, false)) {
            elog('[preload] preloadClassmap: Composer ClassLoader unavailable — skipped', 'debug');
            return;
        }
        $count = 0;
        foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
            foreach (array_keys($loader->getClassMap()) as $class) {
                self::warmClass((string)$class);
                $count++;
            }
        }
        elog('[preload] preloadClassmap warmed ' . $count . ' classmap symbols', 'debug');
    }

    /**
     * Warm every PHP-declared symbol under a directory tree. Reads each `.php`
     * file and extracts its `namespace` + `class|interface|trait|enum` names via
     * the tokenizer (no file is executed), then triggers each symbol's autoload.
     */
    private static function warmDir(string $dir): void
    {
        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            elog('[preload] preloadDir: not a directory: ' . $dir, 'debug');
            return;
        }
        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            foreach (self::symbolsInFile($file->getPathname()) as $symbol) {
                self::warmClass($symbol);
                $count++;
            }
        }
        elog('[preload] preloadDir(' . $real . ') warmed ' . $count . ' symbols', 'debug');
    }

    /**
     * Extract fully-qualified class/interface/trait/enum names declared in a PHP
     * file via the tokenizer — without executing it. Single namespace per file
     * is the common case; multiple namespaces are handled.
     *
     * @return list<string>
     */
    private static function symbolsInFile(string $path): array
    {
        $src = @file_get_contents($path);
        if ($src === false || $src === '') {
            return [];
        }
        $tokens = \PhpToken::tokenize($src);
        $symbols = [];
        $namespace = '';
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $id = $tokens[$i]->id;
            if ($id === T_NAMESPACE) {
                $ns = '';
                for ($j = $i + 1; $j < $n; $j++) {
                    $t = $tokens[$j];
                    if ($t->id === T_NAME_QUALIFIED || $t->id === T_STRING || $t->id === T_NS_SEPARATOR) {
                        $ns .= $t->text;
                    } elseif ($t->text === '{' || $t->text === ';') {
                        break;
                    }
                }
                $namespace = trim($ns, '\\');
            } elseif ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || $id === T_ENUM) {
                // Skip `::class`, anonymous classes, and `class` used as a method/const name.
                $prev = $i > 0 ? $tokens[$i - 1] : null;
                if ($prev && $prev->id === T_DOUBLE_COLON) {
                    continue;
                }
                for ($j = $i + 1; $j < $n; $j++) {
                    $t = $tokens[$j];
                    if ($t->id === T_WHITESPACE) {
                        continue;
                    }
                    if ($t->id === T_STRING) {
                        $symbols[] = $namespace !== '' ? $namespace . '\\' . $t->text : $t->text;
                    }
                    break;
                }
            }
        }
        return $symbols;
    }

    /**
     * True if $name is already defined as a class, interface, or trait
     * (autoload disabled). Extracted so the autoload serializer can re-test
     * "is it loaded now?" after a concurrent load.
     *
     * @phpstan-impure — reads the process-global class/interface/trait tables,
     * which a CONCURRENT coroutine's autoload mutates between calls; the result
     * is NOT constant across repeated calls in the same scope (that is the whole
     * point of the post-wait re-check in the serializer).
     */
    private static function symbolDefined(string $name): bool
    {
        return \class_exists($name, false)
            || \interface_exists($name, false)
            || \trait_exists($name, false);
    }

    /**
     * Register a per-worker shutdown hook. Runs inside the worker process when
     * it exits (`max_request` recycle, graceful shutdown, or reload), BEFORE the
     * process terminates — the reliable place to flush per-worker state
     * (counters, buffered I/O, coverage dumps). Unlike `register_shutdown_function`,
     * this fires on OpenSwoole's signal-driven worker stop.
     * Called as: `$fn($server, $workerId)`
     */
    public static function onWorkerStop(callable $fn): void
    {
        self::$workerStopHooks[] = $fn;
    }

    /**
     * Fire-and-forget Redis pub/sub publish.
     *
     * **Scope = the entire cluster.** When the Store backend is Redis,
     * EVERY app instance on EVERY host that has `App::subscribe`'d to
     * the channel receives the message — that's Redis pub/sub's native
     * PUBLISH semantics. There's no "this server only" mode; route by
     * channel name if you need per-server delivery (e.g. the
     * `ws:server:<id>` pattern `WSRouter::sendToClient` uses).
     *
     * Returns the receiver count Redis itself reported (typically
     * `subscribed workers × cluster instances`). A return of 0 means no
     * subscriber was listening at publish time. Throws `StoreException`
     * on the Table backend (no pub/sub semantics; Table is single-server
     * shared memory).
     *
     * Pairs symmetrically with `App::subscribe` — "App publishes, App
     * subscribes" — so the framework's pub/sub surface reads as one
     * coherent API. Thin delegate to `Store::publish` (the lower-level
     * primitive that owns the Redis I/O wire); use whichever shape reads
     * better in your code.
     *
     * ```php
     * // Cross-cluster broadcast — every subscribed worker on every host
     * // wakes up with this payload:
     * $count = App::publish('chat:42', json_encode(['user' => 'alice', 'msg' => 'hi']));
     * ```
     */
    public static function publish(string $channel, string $payload): int
    {
        return \ZealPHP\Store::publish($channel, $payload);
    }

    /**
     * Reliable publish via Redis Streams (XADD) — at-least-once delivery
     * via consumer groups. Returns the Redis-generated message id.
     *
     * Symmetric pair with `App::subscribeReliable`. Thin delegate to
     * `Store::publishReliable`. Throws `StoreException` on Table backend.
     */
    public static function publishReliable(string $stream, string $payload, ?int $maxLen = null): string
    {
        return \ZealPHP\Store::publishReliable($stream, $payload, $maxLen);
    }

    /**
     * Register a Redis pub/sub handler — runs once for every message
     * `App::publish` (or any other Redis client) sends on the channel.
     *
     * **Cluster-wide delivery.** Once subscribed, THIS worker receives
     * every message any app instance on any host publishes to the
     * channel via the shared Redis. That's how cross-server WebSocket
     * routing, federated chat rooms, and cluster-wide cache
     * invalidation all work in ZealPHP — one process publishes, every
     * subscribed process across the cluster picks it up.
     *
     * Channels containing `*` are PSUBSCRIBE patterns (Redis glob);
     * everything else is SUBSCRIBE exact. Multiple handlers per channel
     * are allowed and all fire on each message.
     *
     * Handler signature: `function(string $payload, string $channel, ?string $pattern): void`.
     * Each invocation runs in its own `go()` so a slow handler can't
     * block the next message. Throws inside the handler are caught +
     * logged via `elog`.
     *
     * Pairs symmetrically with `App::publish` ("App publishes, App
     * subscribes"). The companion `App::publish` is a thin delegate to
     * `Store::publish` — use whichever side of the layering reads better.
     *
     * MUST be called BEFORE App::run() — calling after worker start is
     * a documented no-op with an elog warning.
     *
     * Requires the Redis backend (Store::defaultBackend('redis') OR
     * ZEALPHP_STORE_BACKEND=redis env). If the backend is still Table
     * at worker-start time, the subscriber is not spawned and a warning
     * is logged (Table backend is single-server shared memory — no
     * pub/sub semantics).
     */
    public static function subscribe(string $channelOrPattern, callable $handler): void
    {
        if (self::$reloading) { return; } // route hot-reload re-include — subscriber already wired at boot
        self::$pubsubRegistry[$channelOrPattern][] = $handler;
        self::wirePubSubBoot();
    }

    /**
     * Unregister handlers for a channel/pattern. With $handler null,
     * removes every registered handler for that channel. Returns the
     * count removed.
     */
    public static function unsubscribe(string $channelOrPattern, ?callable $handler = null): int
    {
        if (!isset(self::$pubsubRegistry[$channelOrPattern])) { return 0; }
        if ($handler === null) {
            $n = count(self::$pubsubRegistry[$channelOrPattern]);
            unset(self::$pubsubRegistry[$channelOrPattern]);
            return $n;
        }
        $before = self::$pubsubRegistry[$channelOrPattern];
        self::$pubsubRegistry[$channelOrPattern] = array_values(array_filter(
            $before,
            fn(callable $h): bool => $h !== $handler,
        ));
        return count($before) - count(self::$pubsubRegistry[$channelOrPattern]);
    }

    /**
     * BC alias for `subscribe()`. The original on*-prefixed name was a
     * misnomer — the act IS subscribing, not an event. New code should
     * call `App::subscribe()` directly; pairs symmetrically with
     * `Store::publish()`.
     */
    public static function onPubSub(string $channelOrPattern, callable $handler): void
    {
        self::subscribe($channelOrPattern, $handler);
    }

    /** BC alias for `unsubscribe()`. See `onPubSub` docblock. */
    public static function offPubSub(string $channelOrPattern, ?callable $handler = null): int
    {
        return self::unsubscribe($channelOrPattern, $handler);
    }

    /**
     * Register a Redis Streams consumer-group handler. At-least-once
     * delivery via XREADGROUP. Handler signature:
     *   `function(string $payload, string $messageId, string $stream, array $fields): bool`
     * Return true to XACK (message removed from pending). Return false
     * OR throw to leave pending (retried on consumer recovery).
     *
     * Default group name derives from canonicalHost() so all servers
     * in a cluster share one group → round-robin load balancing across
     * machines and workers.
     *
     * Same backend requirement as subscribe().
     */
    public static function subscribeReliable(
        string $stream,
        callable $handler,
        ?string $group = null,
        int $blockMs = 1000,
        int $batchSize = 16,
    ): void {
        if (self::$reloading) { return; } // route hot-reload re-include — consumer already wired at boot
        $group ??= 'zealphp-' . substr(sha1((string) (self::$canonical_name ?? gethostname())), 0, 8);
        self::$reliableRegistry[$stream][] = [
            'group' => $group, 'handler' => $handler,
            'blockMs' => $blockMs, 'batchSize' => $batchSize,
        ];
        self::wirePubSubBoot();
    }

    /** BC alias for `subscribeReliable()`. See `onPubSub` docblock. */
    public static function onReliableMessage(
        string $stream,
        callable $handler,
        ?string $group = null,
        int $blockMs = 1000,
        int $batchSize = 16,
    ): void {
        self::subscribeReliable($stream, $handler, $group, $blockMs, $batchSize);
    }

    /**
     * Boot-time backpressure advisory (pure testable seam over the resolved
     * server settings). Returns a warning when the per-worker coroutine ceiling
     * has been raised into OpenSwoole's effectively-unbounded range (>= 100000)
     * — which removes the front-door shed path, so a load burst propagates to
     * the first bounded downstream resource (Redis pool, DB, memory) as a cliff
     * instead of OpenSwoole rejecting the over-limit coroutine. Returns null
     * when a bounded ceiling is in effect (the default, or any operator value
     * below the unbounded threshold).
     *
     * @param array<string, mixed> $effectiveSettings
     * @internal — public for tests; not part of the user-facing API.
     */
    public static function backpressureBootAdvisory(array $effectiveSettings): ?string
    {
        $maxCo = $effectiveSettings['max_coroutine'] ?? null;
        if (!is_int($maxCo) || $maxCo < 100000) {
            return null;
        }
        $workers = is_int($effectiveSettings['worker_num'] ?? null)
            ? (int) $effectiveSettings['worker_num']
            : 0;
        return sprintf(
            '[backpressure] max_coroutine=%d per worker%s is in OpenSwoole\'s '
            . 'effectively-unbounded range: a load burst has no front-door shed '
            . 'path and will propagate to the first bounded downstream resource '
            . '(Redis pool, DB connections, memory) as a cliff rather than being '
            . 'rejected at the door. Lower it (framework default is %d) and/or add '
            . 'ConcurrencyLimitMiddleware for graceful 503s under overload.',
            $maxCo,
            $workers > 0 ? sprintf(' (x%d workers)', $workers) : '',
            self::DEFAULT_MAX_COROUTINE
        );
    }

    /**
     * H6 + H7 — boot-time self-checks for the Redis backend.
     *
     * Returns an array of warning strings to surface via error_log.
     * Empty array means everything is fine. Extracted into its own
     * method so the Redis-misconfiguration surface is unit-testable.
     *
     *  H6 — eager ping. If the Redis backend is active, PING the pool
     *       once at boot; surface a warning when it fails so the user
     *       sees the misconfiguration BEFORE the first request (instead
     *       of after a 5s acquire timeout deep inside a worker handler).
     *
     *  H7 — phpredis + HOOK_ALL=0 deadlock guard. phpredis SUBSCRIBE
     *       blocks the worker without HOOK_ALL because the underlying
     *       socket read is C-side. Detect the unsafe combo at boot and
     *       tell the user how to fix it.
     *
     * @return list<string>
     * @internal — public for tests; not part of the user-facing API.
     */
    public static function redisBootChecks(): array
    {
        $warnings = [];
        $backend = \ZealPHP\Store::defaultBackend();
        if (!($backend instanceof \ZealPHP\Store\RedisBackend)) {
            return $warnings;
        }
        // H6 — eager ping. Boot-time runs may already have HOOK_ALL active
        // (the framework's default in coroutine mode), in which case
        // phpredis's hooked connect() requires a coroutine context. Wrap
        // in Coroutine::run so the ping always has one.
        try {
            $ok = null;
            $err = null;
            \OpenSwoole\Coroutine::run(function () use ($backend, &$ok, &$err) {
                try { $ok = $backend->ping(); }
                catch (\Throwable $e) { $err = $e; }
            });
            if ($err !== null) {
                $warnings[] = 'Store(H6): Redis backend ping FAILED at boot: ' . $err->getMessage();
            } elseif ($ok === false) {
                $warnings[] = 'Store(H6): Redis backend ping returned false at boot — workers may fail on first request';
            }
        } catch (\Throwable $e) {
            $warnings[] = 'Store(H6): Redis backend ping FAILED at boot: ' . $e->getMessage();
        }
        // H7 — HOOK_ALL=0 subscriber-deadlock guard (#419). A blocking
        // SUBSCRIBE/XREADGROUP runner can't run concurrently with request
        // serving without HOOK_ALL — true for BOTH phpredis (C-side read) and
        // predis (stream read hooked only under HOOK_ALL). wirePubSubBoot()
        // skips the runner in this case; this advisory surfaces it at boot.
        $hasSubscribers = self::$pubsubRegistry !== [] || self::$reliableRegistry !== [];
        if ($hasSubscribers && self::hookAll() === 0) {
            $warnings[] = 'Store(H7): pub/sub subscriber runners require HOOK_ALL (coroutine mode) to run ' .
                'concurrently with request serving — with HOOK_ALL off (mixed / legacy-cgi) a blocking ' .
                'SUBSCRIBE/XREADGROUP would deadlock the worker, so the runner is NOT spawned. Run ' .
                'subscribers in coroutine mode, or in a dedicated sidecar via App::addProcess().';
        }
        return $warnings;
    }

    /**
     * H7 detection: would a phpredis SUBSCRIBE/PSUBSCRIBE block the whole worker?
     *
     * phpredis's subscribe is a C-level blocking read that is only coroutinized
     * under `OpenSwoole\Runtime::HOOK_ALL`. With HOOK_ALL off, a subscriber
     * runner on phpredis would park the worker's single event loop forever.
     * predis (pure-PHP socket) yields via the stream hooks regardless. Returns
     * true when the resolved driver is phpredis AND HOOK_ALL is disabled — the
     * signal for wirePubSubBoot() to force `['prefer' => 'predis']` on the runner.
     */
    private static function phpredisSubscribeWouldBlock(): bool
    {
        if (self::hookAll() !== 0) { return false; }
        $preferEnv = strtolower((string) getenv('ZEALPHP_REDIS_PREFER'));
        return $preferEnv === 'phpredis'
            || (($preferEnv === '' || $preferEnv === 'auto') && extension_loaded('redis'));
    }

    /**
     * One-time hook into onWorkerStart that builds + starts the
     * RedisPubSub and RedisStreams runners based on what's in the
     * registries. Re-callable; only wires once.
     */
    private static function wirePubSubBoot(): void
    {
        if (self::$pubsubBootWired) { return; }
        self::$pubsubBootWired = true;

        self::onWorkerStart(function () {
            $backend = \ZealPHP\Store::defaultBackend();
            if (!($backend instanceof \ZealPHP\Store\RedisBackend)) {
                if (self::$pubsubRegistry !== [] || self::$reliableRegistry !== []) {
                    error_log('App::onPubSub / onReliableMessage handlers are registered but the Store backend is not redis — runners NOT spawned. Set ZEALPHP_STORE_BACKEND=redis or Store::defaultBackend(\'redis\').');
                }
                return;
            }
            // #419 — a blocking SUBSCRIBE / XREADGROUP runner can only run
            // CONCURRENTLY with request serving when its socket read yields the
            // coroutine, which requires HOOK_ALL. With HOOK_ALL off (mixed /
            // legacy-cgi) NEITHER phpredis (C-side read) NOR predis
            // (stream_socket_client + fread, hooked only under HOOK_ALL) yields,
            // so the runner would park the worker's single event loop and starve
            // every route. Skip it and tell the user how to run subscribers.
            if (self::hookAll() === 0) {
                error_log('App::onPubSub / onReliableMessage: subscriber runners need HOOK_ALL '
                    . '(coroutine mode) to run concurrently with request serving — with HOOK_ALL off '
                    . '(mixed / legacy-cgi) a blocking SUBSCRIBE/XREADGROUP would deadlock the worker, so '
                    . 'the runner is NOT spawned. Run subscribers in coroutine mode, or in a dedicated '
                    . 'sidecar process via App::addProcess().');
                return;
            }
            $url    = $backend->url();
            $prefix = $backend->prefix();
            // H7: if phpredis would block the worker under HOOK_ALL=0, force the
            // subscriber runner onto the predis driver (pure-PHP socket yields
            // regardless of HOOK_ALL) instead of letting it deadlock. The
            // boot-time advisory in redisBootChecks() reports the auto-switch.
            $subscriberOpts = self::phpredisSubscribeWouldBlock() ? ['prefer' => 'predis'] : [];

            if (self::$pubsubRegistry !== []) {
                $pubsub = new \ZealPHP\Store\RedisPubSub($url, $prefix, 0, $subscriberOpts);
                foreach (self::$pubsubRegistry as $channel => $handlers) {
                    foreach ($handlers as $h) { $pubsub->register((string) $channel, $h); }
                }
                $pubsub->start();
                self::onWorkerStop(function () use ($pubsub) { $pubsub->stop(); });
            }
            if (self::$reliableRegistry !== []) {
                $streams = new \ZealPHP\Store\RedisStreams($url, null, $subscriberOpts);
                foreach (self::$reliableRegistry as $stream => $entries) {
                    foreach ($entries as $entry) {
                        $streams->register((string) $stream, $entry['group'], $entry['handler'], $entry['blockMs'], $entry['batchSize']);
                    }
                }
                $streams->start();
                self::onWorkerStop(function () use ($streams) { $streams->stop(); });
            }
        });
    }

    /**
     * Normalize a methods array (any shape) into a list of uppercase strings.
     *
     * @param array<mixed> $methods
     * @return array<int, string>
     */
    private static function normalizeMethods(array $methods): array
    {
        $out = [];
        foreach ($methods as $m) {
            if (is_string($m)) {
                $out[] = strtoupper($m);
            }
        }
        return $out;
    }

    /**
     * @param callable|array{0:object|string,1:string} $handler
     * @return array<int, array{name:string, has_default:bool, default:mixed}>
     */
    private function buildParamMap($handler): array
    {
        try {
            if (is_array($handler)) {
                // $handler is array{0:object|string,1:string} (see @param),
                // so PHPStan narrows the elements to ReflectionMethod's
                // expected types directly — no runtime assert() needed.
                $reflection = new \ReflectionMethod($handler[0], $handler[1]);
            } else {
                $reflection = new \ReflectionFunction(\Closure::fromCallable($handler));
            }
            $map = [];
            foreach ($reflection->getParameters() as $param) {
                $pname = $param->getName();
                $map[] = [
                    'name'        => $pname,
                    'has_default' => $param->isDefaultValueAvailable(),
                    'default'     => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                ];
            }
            return $map;
        } catch (\ReflectionException $e) {
            return [];
        }
    }

    // Prevent the instance from being cloned.
    private function __clone()
    {
    }

    // Prevent from being unserialized.
    public function __wakeup()
    {
    }

    /**
     * Return the underlying OpenSwoole server. Use this when you need to
     * push to a WebSocket client from a context that didn't receive
     * `$server` as a callback argument — e.g. from an `App::subscribe`
     * pub/sub handler, an `App::tick` timer, or a sidecar process
     * registered via `App::addProcess`.
     *
     * Returns `WebSocket\Server` when any `App::ws()` route was registered
     * (the framework upgrades from `Http\Server` automatically), `Http\Server`
     * for pure HTTP apps, or `null` BEFORE `App::run()` constructs it.
     *
     * ```php
     * $server = App::getServer();
     * if ($server instanceof \OpenSwoole\WebSocket\Server && $server->isEstablished($fd)) {
     *     $server->push($fd, $payload);
     * }
     * ```
     *
     * For cluster-wide pushes prefer `WSRouter::sendToClient($clientId, $payload)`
     * — it owns the cross-node routing fabric so you don't have to thread
     * `$server` references around your code.
     *
     * @return \OpenSwoole\WebSocket\Server|\OpenSwoole\Http\Server|null
     */
    public static function getServer()
    {
        return self::$server;
    }

    public static function display_errors(bool $display_errors = true): void
    {
        self::$display_errors = $display_errors;
    }

    
    /**
     * @param callable|array<int|string, mixed> $handler
     * @param array<string, mixed> $options
     * @param array<int, \Psr\Http\Server\MiddlewareInterface|string> $middleware
     * @param array<string, mixed>|string|null $backend
     */
    public function get(string $path, callable|array $handler, array $options = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        $this->route($path, $options, $handler, ['GET'], $raw, $middleware, $backend);
    }

    /**
     * @param callable|array<int|string, mixed> $handler
     * @param array<string, mixed> $options
     * @param array<int, \Psr\Http\Server\MiddlewareInterface|string> $middleware
     * @param array<string, mixed>|string|null $backend
     */
    public function post(string $path, callable|array $handler, array $options = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        $this->route($path, $options, $handler, ['POST'], $raw, $middleware, $backend);
    }

    /**
     * @param callable|array<int|string, mixed> $handler
     * @param array<string, mixed> $options
     * @param array<int, \Psr\Http\Server\MiddlewareInterface|string> $middleware
     * @param array<string, mixed>|string|null $backend
     */
    public function put(string $path, callable|array $handler, array $options = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        $this->route($path, $options, $handler, ['PUT'], $raw, $middleware, $backend);
    }

    /**
     * @param callable|array<int|string, mixed> $handler
     * @param array<string, mixed> $options
     * @param array<int, \Psr\Http\Server\MiddlewareInterface|string> $middleware
     * @param array<string, mixed>|string|null $backend
     */
    public function patch(string $path, callable|array $handler, array $options = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        $this->route($path, $options, $handler, ['PATCH'], $raw, $middleware, $backend);
    }

    /**
     * @param callable|array<int|string, mixed> $handler
     * @param array<string, mixed> $options
     * @param array<int, \Psr\Http\Server\MiddlewareInterface|string> $middleware
     * @param array<string, mixed>|string|null $backend
     */
    public function delete(string $path, callable|array $handler, array $options = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        $this->route($path, $options, $handler, ['DELETE'], $raw, $middleware, $backend);
    }

    /**
     * @param callable|array<int|string, mixed> $handler
     * @param array<string, mixed> $options
     * @param array<int, \Psr\Http\Server\MiddlewareInterface|string> $middleware
     * @param array<string, mixed>|string|null $backend
     */
    public function options(string $path, callable|array $handler, array $options = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        $this->route($path, $options, $handler, ['OPTIONS'], $raw, $middleware, $backend);
    }

    /**
     * @param callable|array<int|string, mixed> $handler
     * @param array<string, mixed> $options
     * @param array<int, \Psr\Http\Server\MiddlewareInterface|string> $middleware
     * @param array<string, mixed>|string|null $backend
     */
    public function any(string $path, callable|array $handler, array $options = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        $this->route($path, $options, $handler, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $raw, $middleware, $backend);
    }

    /**
     * Registers a route with the application.
     *
     * @param string $path The URL path pattern for the route. Flask-like `{param}` syntax can be used for named parameters.
     * @param array $options Optional settings for the route, such as HTTP methods.
     *                       - `'methods'` (array): HTTP methods allowed for this route. Defaults to `['GET']`.
     * @param callable|array<int|string, mixed>|null $handler The callback function to handle the route.
     *
     * If only two arguments are provided, the second argument is assumed to be the handler, and no options are set.
     *
     * The route pattern is converted to a named regex group for parameter matching.
     *
     * Example usage:
     *
     * ```php
     * $app->route('/user/{id}', ['methods' => ['GET', 'POST']], function($id) {
     *     // Handler code here
     * });
     * ```
     *
     * @param array<string, mixed>|callable $options
     * @param callable|array<int|string, mixed>|null $handler
     * @param list<string> $methods Named-arg form of $options['methods'] (HTTP verbs); merged into $options.
     * @param bool $raw Named-arg form of $options['raw'] (skip output buffering).
     * @param array<int,\Psr\Http\Server\MiddlewareInterface|string> $middleware Named-arg form of $options['middleware'] — per-route PSR-15 middleware (instances or registered aliases); combined with $options['middleware'].
     * @param array<string,mixed>|string|null $backend Per-route CGI dispatch backend for this route's `App::include()` — a bare mode (`'pool'`/`'proc'`/`'fork'`/`'fcgi'`), a registered `App::cgiBackendAlias()` name, or an inline config array (`['mode'=>'proc','interpreter'=>'/usr/bin/python3']`). Named-arg form of `$options['backend']` (named arg wins). Rejects lifecycle-mode names (coroutine/coroutine-legacy/...) — those are process-wide, not per-route.
     */
    public function route(string $path, $options = [], $handler = null, array $methods = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        // If only two arguments are provided, assume second is handler and no options.
        // But it's good that we clearly specify all three arguments in usage.
        if (is_callable($options) && $handler === null) {
            $handler = $options;
            $options = [];
        }
        assert(is_array($options));

        // Named-argument convenience: `route('/p', handler: $fn, methods: ['GET','POST'])`.
        // The `methods:` / `raw:` named args augment (and override) the $options
        // array, so the array form (`['methods' => [...], 'raw' => true]`) and the
        // named form are interchangeable — and compose. The `$methods` parameter is
        // merged into $options here, then re-resolved into the local below.
        if ($methods !== []) {
            $options['methods'] = $methods;
        }
        if ($raw) {
            $options['raw'] = true;
        }

        // Default methods to GET if not specified
        $methods = $options['methods'] ?? ['GET'];
        assert(is_array($methods));

        // Convert flask-like {param} to named regex group
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = "#^" . $pattern . "$#";

        assert(is_callable($handler));
        $this->routes[] = [
            'path'       => $path,
            'pattern'    => $pattern,
            'methods'    => self::normalizeMethods($methods),
            'handler'    => $handler,
            'param_map'  => $this->buildParamMap($handler),
            'raw'        => (bool)($options['raw'] ?? false),
            'middleware' => self::routeMiddlewareSpec($options, $middleware),
            'backend'    => self::routeBackendSpec($options, $backend),
        ];
    }

    /**
     * nsRoute: Define a route under a specific namespace.
     * e.g. $app->nsRoute('api', '/users', ['methods' => ['GET']], fn() => "User list");
     * This will create a route at /api/users
     *
     * @param array<string, mixed>|callable $options
     * @param callable|array<int|string, mixed>|null $handler
     * @param list<string> $methods Named-arg form of $options['methods'] (HTTP verbs); merged into $options.
     * @param bool $raw Named-arg form of $options['raw'] (skip output buffering).
     * @param array<int,\Psr\Http\Server\MiddlewareInterface|string> $middleware Named-arg form of $options['middleware'] — per-route PSR-15 middleware (instances or registered aliases); combined with $options['middleware'].
     * @param array<string,mixed>|string|null $backend Per-route CGI dispatch backend for this route's `App::include()` — a bare mode (`'pool'`/`'proc'`/`'fork'`/`'fcgi'`), a registered `App::cgiBackendAlias()` name, or an inline config array (`['mode'=>'proc','interpreter'=>'/usr/bin/python3']`). Named-arg form of `$options['backend']` (named arg wins). Rejects lifecycle-mode names (coroutine/coroutine-legacy/...) — those are process-wide, not per-route.
     */
    public function nsRoute(string $namespace, string $path, $options = [], $handler = null, array $methods = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        // If only two arguments are provided, assume second is handler and no options.
        if (is_callable($options) && $handler === null) {
            $handler = $options;
            $options = [];
        }
        assert(is_array($options));

        // Named-arg convenience: `methods:` / `raw:` augment the $options array.
        if ($methods !== []) {
            $options['methods'] = $methods;
        }
        if ($raw) {
            $options['raw'] = true;
        }

        // Prepend the namespace prefix to the path
        $namespace = trim($namespace, '/');
        $path = '/' . $namespace . '/' . ltrim($path, '/');

        // Default methods to GET if not specified
        $methods = $options['methods'] ?? ['GET'];
        assert(is_array($methods));

        // Convert {param} style placeholders (no change from route)
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = "#^" . $pattern . "$#";

        assert(is_callable($handler));
        $this->routes[] = [
            'path'       => $path,
            'pattern'    => $pattern,
            'methods'    => self::normalizeMethods($methods),
            'handler'    => $handler,
            'param_map'  => $this->buildParamMap($handler),
            'raw'        => (bool)($options['raw'] ?? false),
            'middleware' => self::routeMiddlewareSpec($options, $middleware),
            'backend'    => self::routeBackendSpec($options, $backend),
        ];
    }

    /**
     * nsPathRoute: Define a route under a namespace but allow the last parameter to capture everything (including slashes).
     * Here we assume the route is something like $app->nsPathRoute('api', ...)
     * and the actual route will be `/api/{path}` with {path} capturing all trailing segments.
     * 
     * Example:
     *
     * ```php
     * $app->nsPathRoute('api', ['methods' => ['GET']], function($path) {
     *     return "Full path under /api: $path";
     * });
     * ```
     *
     * Accessing /api/devices/set_pref will set $path = "devices/set_pref".
     *
     * @param array<string, mixed>|callable $options
     * @param callable|array<int|string, mixed>|null $handler
     * @param list<string> $methods Named-arg form of $options['methods'] (HTTP verbs); merged into $options.
     * @param bool $raw Named-arg form of $options['raw'] (skip output buffering).
     * @param array<int,\Psr\Http\Server\MiddlewareInterface|string> $middleware Named-arg form of $options['middleware'] — per-route PSR-15 middleware (instances or registered aliases); combined with $options['middleware'].
     * @param array<string,mixed>|string|null $backend Per-route CGI dispatch backend for this route's `App::include()` — a bare mode (`'pool'`/`'proc'`/`'fork'`/`'fcgi'`), a registered `App::cgiBackendAlias()` name, or an inline config array (`['mode'=>'proc','interpreter'=>'/usr/bin/python3']`). Named-arg form of `$options['backend']` (named arg wins). Rejects lifecycle-mode names (coroutine/coroutine-legacy/...) — those are process-wide, not per-route.
     */
    public function nsPathRoute(string $namespace, string $path, $options = [], $handler = null, array $methods = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        // If only two arguments are provided, assume second is handler and no options.
        if (is_callable($options) && $handler === null) {
            $handler = $options;
            $options = [];
        }
        assert(is_array($options));

        // Named-arg convenience: `methods:` / `raw:` augment the $options array.
        if ($methods !== []) {
            $options['methods'] = $methods;
        }
        if ($raw) {
            $options['raw'] = true;
        }

        // Prepend the namespace prefix to the path
        $namespace = trim($namespace, '/');
        $path = '/' . $namespace . '/' . ltrim($path, '/');

        // Default methods to GET if not specified
        $methods = $options['methods'] ?? ['GET'];
        assert(is_array($methods));
    
        // Find all parameters
        preg_match_all('/\{([^}]+)\}/', $path, $paramMatches);
        $paramsFound = $paramMatches[1];
        $lastParam = end($paramsFound);
    
        // Replace parameters: all but last use [^/]+, last one uses .+
        $pattern = preg_replace_callback('/\{([^}]+)\}/', function($m) use ($lastParam) {
            $paramName = $m[1];
            if ($paramName === $lastParam) {
                // Last parameter is catch-all, match everything remaining
                return '(?P<' . $paramName . '>.+)';
            } else {
                // Intermediate parameters match a single segment only
                return '(?P<' . $paramName . '>[^/]+)';
            }
        }, $path);
    
        $pattern = "#^" . $pattern . "$#";

        assert(is_callable($handler));
        $this->routes[] = [
            'path'       => $path,
            'pattern'    => $pattern,
            'methods'    => self::normalizeMethods($methods),
            'handler'    => $handler,
            'param_map'  => $this->buildParamMap($handler),
            'raw'        => (bool)($options['raw'] ?? false),
            'middleware' => self::routeMiddlewareSpec($options, $middleware),
            'backend'    => self::routeBackendSpec($options, $backend),
        ];
    }


    /**
     * patternRoute: Allow full control of the pattern without {param} placeholders.
     * Here, the user provides a fully formed regex pattern (without anchors) and we anchor it internally.
     * e.g. $app->patternRoute('/api/(.*)', ['methods'=>['GET']], fn() => "Pattern matched!");
     * This will match any route starting with /api/.
     * 
     * TODO: Allow users to provide variable names for the regex groups.
     *
     * @param array<string, mixed>|callable $options
     * @param callable|array<int|string, mixed>|null $handler
     * @param list<string> $methods Named-arg form of $options['methods'] (HTTP verbs); merged into $options.
     * @param bool $raw Named-arg form of $options['raw'] (skip output buffering).
     * @param array<int,\Psr\Http\Server\MiddlewareInterface|string> $middleware Named-arg form of $options['middleware'] — per-route PSR-15 middleware (instances or registered aliases); combined with $options['middleware'].
     * @param array<string,mixed>|string|null $backend Per-route CGI dispatch backend for this route's `App::include()` — a bare mode (`'pool'`/`'proc'`/`'fork'`/`'fcgi'`), a registered `App::cgiBackendAlias()` name, or an inline config array (`['mode'=>'proc','interpreter'=>'/usr/bin/python3']`). Named-arg form of `$options['backend']` (named arg wins). Rejects lifecycle-mode names (coroutine/coroutine-legacy/...) — those are process-wide, not per-route.
     */
    public function patternRoute(string $regex, $options = [], $handler = null, array $methods = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        // If only two arguments are provided
        if (is_callable($options) && $handler === null) {
            $handler = $options;
            $options = [];
        }
        assert(is_array($options));

        // Named-arg convenience: `methods:` / `raw:` augment the $options array.
        if ($methods !== []) {
            $options['methods'] = $methods;
        }
        if ($raw) {
            $options['raw'] = true;
        }

        $methods = $options['methods'] ?? ['GET'];
        assert(is_array($methods));

        // Ensure the pattern is properly anchored if not already
        if (substr($regex, 0, 1) !== '#') {
            $regex = "#^" . $regex . "$#";
        }

        assert(is_callable($handler));
        $this->routes[] = [
            'path'       => $regex,
            'pattern'    => $regex,
            'methods'    => self::normalizeMethods($methods),
            'handler'    => $handler,
            'param_map'  => $this->buildParamMap($handler),
            'raw'        => (bool)($options['raw'] ?? false),
            'middleware' => self::routeMiddlewareSpec($options, $middleware),
            'backend'    => self::routeBackendSpec($options, $backend),
        ];
    }

    /**
     * Parses the given CSS file.
     *
     * @param string $file The path to the CSS file to be parsed.
     * @return array<string, array<string, string>> The parsed CSS rules as an associative array.
     */
    public static function parseCss(string $file): array
    {
        $css = file_get_contents($file);
        if ($css === false) { $css = ''; }
        preg_match_all('/(?ims)([a-z0-9\s\.\:#_\-@,]+)\{([^\}]*)\}/', $css, $arr);
        $result = array();
        foreach ($arr[0] as $i => $x) {
            $selector = trim($arr[1][$i]);
            $rules = explode(';', trim($arr[2][$i]));
            $rules_arr = array();
            foreach ($rules as $strRule) {
                if (!empty($strRule)) {
                    $rule = explode(":", $strRule);
                    $rules_arr[trim($rule[0])] = trim($rule[1]);
                }
            }

            $selectors = explode(',', trim($selector));
            foreach ($selectors as $strSel) {
                $result[$strSel] = $rules_arr;
            }
        }
        return $result;
    }

    // -----------------------------------------------------------------------
    // File execution family — shared core + four public surfaces.
    //
    // The universal return contract: see template/pages/responses.php
    // (canonical) and .claude/CLAUDE.md "Return value conventions" (mirror).
    // Keep all three in lock-step on any change.
    //
    //   render()           → template name, BC echo on void+echo, full contract otherwise
    //   renderToString()   → template name, coerces every return shape to string
    //   renderStream()     → template name, coerces every return shape to Generator
    //   include()          → public-relative path, full contract, never echoes
    //
    // All four share self::executeFile() — they only differ in path resolution
    // and how they coerce the core's return value.
    // -----------------------------------------------------------------------

    /**
     * Run a PHP file with the framework's universal return contract.
     *
     * Captures buffered output, then maps the included file's result:
     *   void+echo                        → buffered string
     *   return 404; (int)                → int
     *   return ['ok' => true]; (array)   → array
     *   return "html"; (string)          → string (concatenated with prior echo)
     *   echo "shell"; return "body";     → "shellbody"
     *   return (function(){yield…})();   → Generator (prefixed with echo, if any)
     *   return function($req){yield…};   → Closure (param-injected at call site,
     *                                       then re-applied to result)
     *
     * Throws bubble up to the caller — output buffer is dropped on throw so
     * partial echo doesn't leak into the next response.
     *
     * @param string $absPath  Already resolved absolute path
     * @param array<string,mixed> $args  Extracted into the file's scope
     * @return mixed
     */
    private static function executeFile(string $absPath, array $args): mixed
    {
        $g = RequestContext::instance();

        // ── Fragment-mode setup (htmx-essay-style template fragments).
        // If $args['fragment'] names a region, App::fragment() helpers inside
        // the template extract it; everything else short-circuits via
        // HaltException. Save+restore the state slot so nested executeFile()
        // calls (e.g. App::render() inside a template) compose cleanly.
        $fragmentName = (isset($args['fragment']) && is_string($args['fragment']))
            ? $args['fragment']
            : null;
        $previousFragmentState = $g->memo['_fragment'] ?? null;
        if ($fragmentName !== null) {
            $g->memo['_fragment'] = [
                'wanted'  => $fragmentName,
                'matched' => false,
                'result'  => null,
            ];
        }

        // Mode 4: $_SESSION reference is established in zeal_session_start()
        // ($_SESSION = &$g->session) so writes go directly to $g->session.
        //
        // Re-establish the request-input superglobals from THIS coroutine's
        // request before the included file reads them. An included file may have
        // cached the auto-global from a prior coroutine's EG, and the
        // process-global populate in the OnRequest closure can be overwritten by
        // a concurrent overlapping request (the colliding-cid snapshot key).
        // rebindRequestInput() pins $_GET/$_POST/$_COOKIE/$_SERVER/$_FILES/
        // $_REQUEST to this request via ext-zealphp's EG+PG dual-write. No-op
        // outside coroutine-legacy.
        if (self::$coroutine_isolated_superglobals) {
            // Preserve the per-include $_SERVER overrides (PHP_SELF / SCRIPT_NAME
            // / SCRIPT_FILENAME) the caller set into $g->server immediately
            // before this call (no intervening yield → reliably this request's),
            // so the request-derived rebuild below doesn't reset them to the
            // route's values.
            $serverOverlay = null;
            $curServer = $g->server;
            foreach (['PHP_SELF', 'SCRIPT_NAME', 'SCRIPT_FILENAME'] as $sk) {
                if (array_key_exists($sk, $curServer)) {
                    $serverOverlay ??= [];
                    $serverOverlay[$sk] = $curServer[$sk];
                }
            }
            self::rebindRequestInput($g, $serverOverlay);
        }

        // Apache/mod_php and php-cli run a script with CWD = the script's own
        // directory, so legacy apps using relative includes (`require './global.php'`,
        // `require 'conf/constants.php'`) resolve them against that directory. The
        // in-process executeFile() path otherwise leaves CWD at the framework root
        // (/app), so those relative requires fail — the dominant 50-app-sweep blocker
        // in coroutine/sync modes (mybb, cacti, vanilla, …). chdir to the script's
        // directory for the duration of the include, restore immediately after.
        // chdir is process-global (#323): if the include YIELDS (HOOK_ALL I/O),
        // a peer coroutine would otherwise run with this script's CWD. Per-
        // coroutine CWD stability across yields is provided by ext-zealphp
        // 0.3.35+'s zealphp_cwd_isolation() stage — enabled via
        // App::coroutineCwdIsolation() (auto-on in coroutine-legacy). Without
        // the ext, sync modes are safe (no concurrency) but plain coroutine
        // mode keeps the documented race for yielding includes.
        $prevCwd = \getcwd();
        $scriptDir = \dirname($absPath);
        $didChdir = ($scriptDir !== '' && $scriptDir !== '.') ? @\chdir($scriptDir) : false;

        $obBase = ob_get_level();
        ob_start();
        // OB floor for the \ZealPHP\ob_end_flush()/ob_flush() overrides: the
        // app's OWN buffers live ABOVE this level and must keep NATIVE nested
        // semantics (pop content into the parent buffer). Without the floor,
        // a framework-level app calling a plain nested ob_end_flush() at the
        // end of its bootstrap (CodeIgniter 4's Boot::bootWeb()) hit the
        // streaming shim's discard path and served 200 with a 0-byte body.
        $prevObFloor = $g->_ob_floor ?? null;
        $g->_ob_floor = $obBase + 1;
        $result = null;
        try {
            try {
                $args['g'] = $g;
                extract($args, EXTR_SKIP);
                if (self::globalScopeIncludeEffective() && \function_exists('zealphp_require_global')) {
                    // Stage 8: run the file at TRUE global scope so bare file-scope
                    // vars (WordPress $menu/$submenu/$_wp_submenu_nopriv) become real
                    // $GLOBALS. The included file does NOT see the extracted $g /
                    // route params (they stay in this frame) — by contract this mode
                    // is for legacy apps that read request state via superglobals.
                    $result = \zealphp_require_global($absPath);
                } else {
                    $result = include $absPath;
                }
            } finally {
                if ($didChdir && $prevCwd !== false) {
                    @\chdir($prevCwd);
                }
            }
        } catch (HaltException $e) {
            // Clean halt — preserves buffered output as the body (PR #10).
            $haltState = self::getFragmentState();
            if ($haltState !== null && $haltState['matched'] && $haltState['result'] !== null) {
                $result = $haltState['result'];
            } else {
                // ext#47: a real exit()/die() intercepted by ext-zealphp rides
                // this class with the exit argument in ->status (fragments and
                // bare halts carry null). Mirror the ExitException mapping
                // below: collapse nested OB levels into ours, echo a string
                // status (mod_php parity), map int 100–599 to the HTTP status.
                // NB: \ob_end_flush — global-qualified. Unqualified resolves to
                // \ZealPHP\ob_end_flush() (the streaming uopz shim in utils.php),
                // which flush()es-then-DISCARDS the buffer instead of stacking
                // it down — the inner buffers' content would be lost.
                // Collapse nested OB levels into ours by POP-AND-ECHO, not
                // ob_end_flush: that builtin is overridden at boot
                // (registerAllOverrides) by the streaming shim
                // \ZealPHP\ob_end_flush(), which flush()es-then-DISCARDS the
                // buffer — and namespace qualification cannot escape an
                // engine-level internal-function override, so flushing here
                // silently lost the app's own ob_start() content. ob_get_clean
                // / ob_get_level are NOT overridden; the merge preserves wire
                // order and strictly decreases the level (false → stop; never
                // spins on a non-removable test-harness buffer).
                while (ob_get_level() > $obBase + 1) {
                    $innerBuf = @\ob_get_clean();
                    if ($innerBuf === false) {
                        break;
                    }
                    echo $innerBuf;
                }
                $haltStatus = $e->getStatus();
                if (is_int($haltStatus) && $haltStatus >= 100 && $haltStatus <= 599) {
                    $result = $haltStatus;
                } else {
                    if (is_string($haltStatus) && $haltStatus !== '') {
                        echo $haltStatus;
                    }
                    $result = 1;
                }
            }
        } catch (\Throwable $e) {
            // PHP 8.4+: exit()/die() throw \ExitException instead of
            // terminating the process. Treat as clean halt — worker survives.
            // @codeCoverageIgnoreStart — ExitException only exists on PHP 8.4+ / OpenSwoole
            if (($e instanceof \OpenSwoole\ExitException || $e::class === 'ExitException')
                && method_exists($e, 'getStatus')) {
                $status = $e->getStatus();
                // Collapse nested OB levels (apps like Adminer push extra buffers
                // before exit()). Flush inner buffers into the one ob_start()
                // at the top of executeFile() created ($obBase + 1).
                // \ob_end_flush global-qualified — see the HaltException branch
                // above (the \ZealPHP shim would discard, not stack down).
                // Pop-and-echo merge — see the HaltException branch above for
                // why ob_end_flush (overridden by the discarding streaming
                // shim) must not be used here.
                while (ob_get_level() > $obBase + 1) {
                    $innerBuf = @\ob_get_clean();
                    if ($innerBuf === false) {
                        break;
                    }
                    echo $innerBuf;
                }
                if (is_int($status) && $status >= 100 && $status <= 599) {
                    $result = $status;
                } elseif (is_string($status) && $status !== '') {
                    echo $status;
                    $result = 1;
                } else {
                    $result = 1;
                }
            // @codeCoverageIgnoreEnd
            } else {
                @ob_end_clean();
                $g->_ob_floor = $prevObFloor;
                self::restoreFragmentState($previousFragmentState);
                throw $e;
            }
        }
        $output = ob_get_clean();
        $g->_ob_floor = $prevObFloor;
        if ($output === false) {
            $output = '';
        }

        // Fragment-mode post-flight: requested but no App::fragment('X', ...)
        // block matched. The template ran to completion and we now have the
        // full-page output — definitely not what the caller asked for. Per
        // the universal return contract, surface a 404.
        $postState = self::getFragmentState();
        $fragmentMatched = ($postState !== null && $postState['matched']);
        self::restoreFragmentState($previousFragmentState);
        if ($fragmentName !== null && !$fragmentMatched) {
            return 404;
        }

        if ($result instanceof \Closure) {
            $params = self::resolveClosureParams($result, $args, $absPath);
            $invoked = $result(...$params);
            // The closure may yield a Generator, return a scalar, or return
            // another Closure. Re-thread through the same coercion logic so
            // the wire shape matches whatever the closure produced.
            if ($invoked instanceof \Generator) {
                return $output !== '' ? self::prependToStreamable($output, $invoked) : $invoked;
            }
            // Closure returning a scalar — surface the value directly; if the
            // file also echoed pre-return, concat for the "echo shell, return
            // body" idiom (only meaningful when both are strings).
            if (is_string($invoked) && $output !== '') {
                return $output . $invoked;
            }
            if ($invoked === null || $invoked === 1) {
                return $output !== '' ? $output : null;
            }
            return $invoked;
        }

        if ($result instanceof \Generator) {
            return $output !== '' ? self::prependToStreamable($output, $result) : $result;
        }

        // PHP's `include` returns int(1) when the file has no explicit `return`.
        // `return;` (void) yields null. Both should surface buffered output.
        if ($result === 1 || ($result === null && $output !== '')) {
            return $output !== '' ? $output : null;
        }

        // Explicit string return: if the file also echoed, preserve wire order.
        if (is_string($result) && $output !== '') {
            return $output . $result;
        }

        return $result;
    }

    /**
     * Resolve a template-file name to an absolute path.
     *
     * Lookup rules mirror the historical render() behaviour:
     *   - Leading slash ("/foo") = absolute lookup from $dir root
     *   - When the current request's PHP_SELF basename is a sub-directory
     *     under $dir, prefer "$dir/{basename}/$tpl.php"
     *   - Otherwise fall back to "$dir/$tpl.php"
     */
    private static function resolveTemplatePath(string $tpl, string $dir): string
    {
        $currentFile  = self::getCurrentFile(null);
        $templateDir  = self::$cwd . "/$dir";
        $rootLookup   = strpos($tpl, '/') === 0;

        if ($rootLookup) {
            $candidate = $templateDir . $tpl . '.php';
        } else if (!empty($currentFile) && is_dir("$templateDir/" . $currentFile)) {
            $candidate = "$templateDir/" . $currentFile . '/' . $tpl . '.php';
        } else {
            $candidate = "$templateDir/" . $tpl . '.php';
        }

        $resolved = realpath($candidate);
        if (!$resolved || !file_exists($resolved) || strpos($resolved, self::$cwd) !== 0) {
            $bt = debug_backtrace();
            $caller = array_shift($bt);
            throw new TemplateUnavailableException(
                "The template $candidate does not exist in file "
                . str_replace(self::$cwd, '', $caller['file'] ?? '') . ":" . ($caller['line'] ?? '')
            );
        }
        return $resolved;
    }

    /**
     * Resolve a Closure's parameters by name from $args, using each parameter's
     * default value when the name is absent. Reflection is cached per file path
     * so repeated calls (e.g. streaming templates yielded in a loop) pay only
     * one reflection cost per worker.
     *
     * @param array<string,mixed> $args
     * @return array<int,mixed>
     */
    private static function resolveClosureParams(\Closure $fn, array $args, string $cacheKey): array
    {
        /** @var array<string, array<int, array{name: string, default: mixed}>> $cache */
        static $cache = [];
        if (!isset($cache[$cacheKey])) {
            $ref = new \ReflectionFunction($fn);
            $cache[$cacheKey] = array_map(
                static fn(\ReflectionParameter $p): array => [
                    'name'    => $p->getName(),
                    'default' => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null,
                ],
                $ref->getParameters()
            );
        }
        $out = [];
        foreach ($cache[$cacheKey] as $p) {
            $name = $p['name'];
            // Short aliases: $req → request, $res → response — only when the
            // caller didn't pass an explicit 'req'/'res' arg but DID provide the
            // long name. Keeps $req/$res working in any param-injected closure,
            // mirroring the route/api handler injection.
            if ($name === 'req' && !array_key_exists('req', $args) && array_key_exists('request', $args)) {
                $out[] = $args['request'];
            } elseif ($name === 'res' && !array_key_exists('res', $args) && array_key_exists('response', $args)) {
                $out[] = $args['response'];
            } else {
                $out[] = $args[$name] ?? $p['default'];
            }
        }
        return $out;
    }

    /**
     * Combine a pre-yield buffered chunk with a Generator so the wire order
     * is "echo first, then stream". Returns a new Generator that yields the
     * buffered chunk before delegating to the original.
     */
    private static function prependToStreamable(string $prefix, \Generator $gen): \Generator
    {
        yield $prefix;
        yield from $gen;
    }

    /**
     * Coerce an executeFile() result to a string. Generators are consumed and
     * concatenated; arrays/objects are JSON-encoded; null becomes ''.
     */
    private static function coerceToString(mixed $result): string
    {
        if ($result === null) return '';
        if (is_string($result)) return $result;
        if (is_int($result) || is_float($result) || is_bool($result)) return (string)$result;
        if ($result instanceof \Generator) {
            $buf = '';
            foreach ($result as $chunk) {
                if (is_string($chunk)) {
                    $buf .= $chunk;
                } elseif (is_scalar($chunk) || $chunk === null) {
                    $buf .= (string)$chunk;
                } elseif (is_object($chunk) && method_exists($chunk, '__toString')) {
                    $buf .= (string)$chunk;
                }
                // else: skip non-stringifiable yields (array/object without __toString)
            }
            return $buf;
        }
        if (is_array($result) || is_object($result)) {
            return (string)json_encode($result);
        }
        return '';
    }

    /**
     * Coerce an executeFile() result to a Generator. Strings/scalars yield
     * once; Generators yield-from; null yields nothing.
     */
    private static function coerceToStream(mixed $result): \Generator
    {
        if ($result === null) {
            return;
        }
        if ($result instanceof \Generator) {
            yield from $result;
            return;
        }
        if (is_array($result) || is_object($result)) {
            yield (string)json_encode($result);
            return;
        }
        if (is_string($result) || is_int($result) || is_float($result) || is_bool($result)) {
            yield (string)$result;
        }
    }

    /**
     * Render a template with the provided data.
     *
     * Templates are looked up under ./template/ in the current working dir;
     * PHP_SELF is consulted as a sub-directory prefix unless $tpl starts with `/`.
     *
     * **Return contract**: see executeFile(). Templates may return int / array /
     * string / Generator / Closure to participate in the universal contract.
     *
     * **Backwards compatibility**: legacy callers expect render() to echo. When
     * the template has no explicit `return` (the historical pattern in every
     * public/*.php) the captured output is echoed back. Explicit non-void
     * returns flow through to the caller unchanged.
     *
     * @see App::executeFile() (private core) and the sibling methods (renderToString / renderStream / include).
     *
     * @param array<string, mixed> $__args
     */
    public static function render(string $__template_file = 'index', array $__args = [], string $__default_template_dir = 'template'): mixed
    {
        $path = self::resolveTemplatePath($__template_file, $__default_template_dir);
        $result = self::executeFile($path, $__args);
        // BC: void-context callers (every App::render('_master', ...) call in
        // public/*.php) expect echo. If executeFile() returned a string (the
        // "file only echoed, no explicit return" case OR an explicit string
        // return) emit it now. Explicit non-string returns pass through so
        // route handlers can `return App::render(...)` and get the universal
        // contract applied at the response boundary.
        if (is_string($result)) {
            echo $result;
        }
        return $result;
    }

    /**
     * htmx-aware render: return a fragment (partial) for an htmx request, the
     * full page otherwise — a thin selector over {@see App::render()} that
     * keeps the universal return contract and streaming intact (it does NOT
     * touch `executeFile()`; it only chooses what to render).
     *
     * The htmx "one URL, two responses" pattern, in one call:
     *  - **htmx request** (`HX-Request: true`) → render only the named region
     *    (via the `App::fragment()` mechanism), so the response is just the
     *    HTML that swaps in.
     *  - **normal request** → render the whole page shell.
     *
     * Fragment selection for an htmx request:
     *  1. If `$fragmentName` is passed, that region is rendered.
     *  2. Otherwise the framework derives the region from the request: the
     *     `HX-Target` element id (a leading `#` is stripped), falling back to
     *     `HX-Trigger-Name`. If neither is present, the template is rendered
     *     with no `fragment` key — i.e. its bare partial output.
     *
     * Called outside a request (no current `zealphp_request`), it falls back
     * to the full-page path so server-side renders never break.
     *
     * Two common shapes:
     *
     * Same template, a `App::fragment('results', …)` region inside it:
     * ```php
     * // /search → full page; htmx (hx-target="#results") → just #results
     * $app->route('/search', fn() =>
     *     App::renderHtmx('search', ['q' => $q, 'hits' => $hits]));
     * ```
     *
     * A bare partial template for htmx + a separate full-page shell:
     * ```php
     * $app->route('/widget', fn() =>
     *     App::renderHtmx('widget/partial', ['w' => $w],
     *         fullPageTemplate: 'widget/page'));
     * ```
     *
     * @param array<string, mixed> $args              Template args (param-injected as usual).
     * @param string|null          $fragmentName      Region to extract for htmx; null → derive from HX-Target / HX-Trigger-Name.
     * @param string|null          $fullPageTemplate  Template for non-htmx requests; null → `$template`.
     * @return mixed The {@see App::render()} return value, riding the universal contract.
     */
    public static function renderHtmx(string $template, array $args = [], ?string $fragmentName = null, ?string $fullPageTemplate = null): mixed
    {
        $req = RequestContext::instance()->zealphp_request;

        // No request in scope (CLI render, warmup, etc.) → full page.
        if (!$req instanceof \ZealPHP\HTTP\Request || !$req->isHtmx()) {
            return self::render($fullPageTemplate ?? $template, $args);
        }

        // Explicit fragment wins.
        if ($fragmentName !== null) {
            return self::render($template, $args + ['fragment' => $fragmentName]);
        }

        // Derive the fragment from the request: HX-Target (strip a leading
        // '#'), else HX-Trigger-Name. If neither is present, render the bare
        // partial with no fragment key.
        $target = $req->htmxTarget();
        $derived = $target !== null ? ltrim($target, '#') : $req->htmxTriggerName();
        if ($derived !== null && $derived !== '') {
            return self::render($template, $args + ['fragment' => $derived]);
        }

        return self::render($template, $args);
    }

    /**
     * Render a template and return the result as a string. Generators are
     * consumed; Closures are invoked with param injection; arrays/objects
     * are JSON-encoded.
     *
     * @see App::executeFile() (private core) and the sibling methods (render / renderStream / include).
     *
     * @param array<string, mixed> $__args
     */
    public static function renderToString(string $__template_file = 'index', array $__args = [], string $__default_template_dir = 'template'): string
    {
        $path = self::resolveTemplatePath($__template_file, $__default_template_dir);
        $result = self::executeFile($path, $__args);
        return self::coerceToString($result);
    }

    /**
     * Render a template as a Generator. Streaming templates (return-a-Closure
     * or return-a-Generator) yield directly; echo-style templates yield their
     * buffered output once.
     *
     * Compose multiple template streams with `yield from`:
     *
     * ```php
     * return (function() {
     *     yield from App::renderStream('shell-open', ['title' => 'Users']);
     *     yield from App::renderStream('users/list', ['users' => $users]);
     *     yield from App::renderStream('shell-close');
     * })();
     * ```
     *
     * @see App::executeFile() (private core) and the sibling methods (render / renderToString / include).
     *
     * @param array<string, mixed> $__args
     */
    public static function renderStream(string $__template_file = 'index', array $__args = [], string $__default_template_dir = 'template'): \Generator
    {
        $path = self::resolveTemplatePath($__template_file, $__default_template_dir);
        $result = self::executeFile($path, $__args);
        yield from self::coerceToStream($result);
    }

    /**
     * Declare a named region inside a template — the htmx-essay "template
     * fragment" pattern. The same template renders the full page when called
     * via `App::render('page', $args)`, and just the named region when called
     * via `App::render('page', ['fragment' => $name] + $args)`. One file,
     * two responses — no separate partial file required.
     *
     * Three behaviours depending on the parent render's fragment selector:
     *  - selector is null (normal full-page render) → `$fn()` runs inline,
     *    its echo flows into the surrounding template, its return value is
     *    discarded (the parent render's return owns the universal contract).
     *  - selector matches $name → the page-shell buffer is cleared, `$fn()`
     *    runs, its return is captured, then `HaltException` short-circuits
     *    the rest of the template. `executeFile()` propagates the return
     *    so the closure can `return 404;` / `return ['k'=>'v'];` / yield a
     *    Generator just like a route handler.
     *  - selector is set but does not match $name → skipped silently.
     *
     * Same return contract as every other entry point: int=status,
     * array=JSON, string=HTML, Generator=stream, Closure=invoked-and-recursed,
     * null=use buffered output. See `template/pages/responses.php#return-contract`.
     *
     * Example — htmx-style row swap:
     * ```php
     * // template/contacts/list.php
     * <ul>
     *   <?php foreach ($contacts as $contact): ?>
     *     <?php App::fragment("contact-{$contact->id}", function() use ($contact) { ?>
     *       <li id="contact-<?= $contact->id ?>"><?= htmlspecialchars($contact->name) ?></li>
     *     <?php }); ?>
     *   <?php endforeach; ?>
     * </ul>
     * ```
     *
     * Full page: `App::render('contacts/list', ['contacts' => $all])`.
     * Single row (htmx swap response, same template): `App::render('contacts/list', ['contacts' => $all, 'fragment' => "contact-{$id}"])`.
     */
    public static function fragment(string $name, callable $fn): void
    {
        $state = self::getFragmentState();

        // Not in fragment-extraction mode — render the region inline as part
        // of the full page. The callable's return value is discarded; the
        // parent App::render() / App::include() owns the universal contract.
        if ($state === null) {
            $fn();
            return;
        }

        if ($state['wanted'] !== $name) {
            // Fragment-extraction mode, but not this region. Skip silently.
            return;
        }

        // Match. Clear the page-shell buffer so only this fragment's output
        // survives, run the callable, capture its return value, and throw
        // HaltException to short-circuit the rest of the template.
        // `executeFile()` catches the throw, surfaces the captured return as
        // the response's $result, and emits the buffered (fragment-only)
        // echo as the response body — same universal-return-contract path
        // every other entry point uses.
        ob_clean();
        $state['matched'] = true;
        $result = $fn();
        if ($result !== null) {
            $state['result'] = $result;
        }
        // Write the state back as a fresh array. PHPStan can't track nested-
        // key writes through $g->memo['_fragment']['matched'] because $g->memo
        // is typed as array<string, mixed>; assigning the whole sub-array
        // keeps the offset-access checker happy.
        $g = RequestContext::instance();
        $g->memo['_fragment'] = $state;
        throw new HaltException("fragment {$name} captured");
    }

    /**
     * Read and narrow the current fragment-extraction state from $g->memo.
     * `$g->memo` is `array<string, mixed>` so PHPStan can't see the shape of
     * `$g->memo['_fragment']` without help — this helper does the narrowing
     * once and returns a typed array (or null when no fragment mode is set).
     *
     * @return array{wanted: string, matched: bool, result: mixed}|null
     */
    private static function getFragmentState(): ?array
    {
        $g = RequestContext::instance();
        /** @var mixed $state */
        $state = $g->memo['_fragment'] ?? null;
        if (!is_array($state)) {
            return null;
        }
        /** @var mixed $wantedRaw */
        $wantedRaw = $state['wanted'] ?? null;
        if (!is_string($wantedRaw)) {
            return null;
        }
        return [
            'wanted'  => $wantedRaw,
            'matched' => (bool)($state['matched'] ?? false),
            'result'  => $state['result'] ?? null,
        ];
    }

    /**
     * Restore `$g->memo['_fragment']` to its prior state. Called by
     * `executeFile()` to undo fragment-mode setup for nested renders and on
     * error paths. `null` means "no fragment mode was active before" — drop
     * the slot entirely so the next App::fragment() call falls into the
     * normal inline-render branch.
     *
     * @param mixed $previous
     */
    private static function restoreFragmentState($previous): void
    {
        $g = RequestContext::instance();
        if ($previous === null) {
            unset($g->memo['_fragment']);
        } else {
            $g->memo['_fragment'] = $previous;
        }
    }

    
    /**
     * Returns the current executing script name without extenstion
     * @return String
     */
    public static function getCurrentFile(?string $file = null): string
    {
        $g = RequestContext::instance();
        if ($file == null) {
            return basename((string)($g->server['PHP_SELF'] ?? ''), '.php');
        } else {
            return basename($file, '.php');
        }
    }

    
    /**
     * Boundary-aware containment test: is $candidate the same path as $root, or
     * a descendant of it?
     *
     * Both arguments are expected to already be canonical (realpath'd) absolute
     * paths — this is the pure decision the symlink-escape guard hangs on, kept
     * separate so it can be unit-tested without a filesystem.
     *
     * A plain `strpos($candidate, $root) === 0` prefix match is unsafe: docroot
     * `/var/www/public` would wrongly accept the sibling `/var/www/public-data`
     * (shared string prefix, different directory). We require either an exact
     * match or that $candidate begins with $root followed by the directory
     * separator, so only true descendants pass.
     *
     * @param string $candidate Canonical absolute path under test.
     * @param string $root       Canonical absolute document-root path (no trailing slash).
     */
    public static function pathWithinRoot(string $candidate, string $root): bool
    {
        if ($candidate === '' || $root === '') {
            return false;
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if ($candidate === $root) {
            return true; // the docroot itself
        }
        return str_starts_with($candidate, $root . DIRECTORY_SEPARATOR);
    }

    /**
     * Checks if the given file path is safe to serve/execute from the document
     * root. Apache `ap_directory_walk` / `resolve_symlink` parity:
     *
     *  - Symlink escape (CRITICAL): we canonicalize BOTH the file and the
     *    document root with realpath() and require boundary-aware containment.
     *    realpath() follows every symlink to its target, so a link inside
     *    docroot pointing outside (e.g. /etc/passwd) resolves to a path that
     *    fails the containment check and is refused. Apache refuses such links
     *    at the C level unless `Options +FollowSymLinks` is set; ZealPHP refuses
     *    them unconditionally on the PHP-served path.
     *  - Non-regular files: device nodes, FIFOs and sockets are refused
     *    (Apache request.c:1286-1292 — only REG/DIR pass the directory walk).
     *  - Dotfile segments (.git, .env, .htaccess, …) are refused when
     *    App::$block_dotfiles is on.
     *
     * Honest limitation: this guard only covers the PHP-served path
     * (App::include() / serveDirectory() / the implicit file routes). Assets
     * under the OpenSwoole built-in static handler prefixes (static_handler_
     * locations — /css/, /js/, …) are served by OpenSwoole's C-level handler
     * before any PHP runs and have no FollowSymLinks guard; keep those
     * directories symlink-free in production, or disable enable_static_handler
     * and route assets through PHP so this check applies.
     *
     * @param mixed $abs_file The candidate file path. Callers pass a realpath()
     *                         result (string|false) or a raw path; the value is
     *                         validated and re-canonicalized here.
     * @return bool Returns true if the file is a regular file within the
     *              document root, false otherwise.
     */
    public function includeCheck($abs_file){
        if (!is_string($abs_file) || $abs_file === '') {
            return false;
        }
        // Canonicalize both sides so symlinks are resolved to their real target
        // before the containment test. realpath() returns false for a path that
        // does not exist or is unreadable — refuse those too.
        $realRoot = realpath(self::resolveDocumentRoot());
        $realFile = realpath($abs_file);
        if ($realRoot === false || $realFile === false) {
            return false;
        }
        if (!self::pathWithinRoot($realFile, $realRoot)) {
            return false; // outside the document root (covers symlink escape)
        }
        // Apache refuses non-regular files (devices/pipes/sockets) — only
        // regular files and directories survive the directory walk. Directories
        // are handled by serveDirectory(); here we require a regular file.
        if (!is_file($realFile)) {
            return false;
        }
        if (self::$block_dotfiles) {
            $relative = substr($realFile, strlen($realRoot));
            $segments = explode(DIRECTORY_SEPARATOR, ltrim($relative, DIRECTORY_SEPARATOR));
            // #359 — `.well-known/` is a registered public convention (RFC 8615):
            // ACME HTTP-01 challenge tokens + security.txt (RFC 9116) live there.
            // The route guard exempts it via negative lookahead; mirror that here
            // so the exemption isn't dead code. Only the literal `.well-known`
            // SEGMENT is exempt (and only as the first path segment, matching the
            // registered-convention scope) — every OTHER dot-segment, including
            // an additional dotfile nested under it (`.well-known/.env`), and a
            // decoy like `.well-knownx`, stays blocked.
            foreach ($segments as $i => $segment) {
                if ($i === 0 && $segment === '.well-known') {
                    continue; // the registered well-known root segment
                }
                if ($segment !== '' && $segment[0] === '.') {
                    return false; // dotfile (.git, .env, .htaccess, etc.)
                }
            }
        }
        return true;
    }

    /**
     * ENOTDIR detection for Apache parity (request.c:1244-1250 — "deny rather
     * than assume not found"). When a path component that should be a directory
     * is actually a regular file (e.g. /home.php/extra), Apache returns 403, not
     * 404, deliberately refusing to leak whether the deeper path exists.
     *
     * realpath() collapses both ENOENT and ENOTDIR to false, so we walk the
     * uncanonicalized path: if any non-final ancestor exists and is NOT a
     * directory, the request hit ENOTDIR. Symlinks are followed by is_dir()/
     * is_file(), matching the kernel's traversal.
     *
     * @param string $absPath The non-canonical absolute path the request mapped to.
     */
    public static function isEnotdir(string $absPath): bool
    {
        $absPath = rtrim($absPath, DIRECTORY_SEPARATOR);
        $parent  = dirname($absPath);
        while ($parent !== '' && $parent !== DIRECTORY_SEPARATOR && $parent !== '.') {
            if (file_exists($parent)) {
                // First existing ancestor: if it's a file (not a dir), the
                // remaining segments could never resolve — that's ENOTDIR.
                return !is_dir($parent);
            }
            $next = dirname($parent);
            if ($next === $parent) {
                break;
            }
            $parent = $next;
        }
        return false;
    }

    /**
     * Apache DirectorySlash + DirectoryIndex behavior.
     *
     * If the request hit a directory under public/, optionally 301-redirect
     * to the trailing-slash form, then walk App::$directory_index until a
     * file is found. .php files run via includeFile(); others are served
     * via sendFile() (so Range/ETag work).
     *
     * Returns: \Generator for streaming, int for status code, null when the
     * route was handled inline (response already emitted), or false to
     * indicate the directory has no servable index.
     *
     * @return mixed Generator|int|string|array|object|Closure|null|false — whatever
     *               App::include() returns for .php indexes, false when no index
     *               matched, null when a slash-redirect or sendFile was emitted.
     */
    public function serveDirectory(string $relDir, string $urlPrefix): mixed
    {
        $g = RequestContext::instance();

        if (self::$directory_slash) {
            $requestPath = parse_url((string)($g->server['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '';
            if ($requestPath !== '' && substr((string)$requestPath, -1) !== '/') {
                $newUrl = $requestPath . '/';
                $qs = parse_url((string)($g->server['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
                if ($qs) $newUrl .= '?' . $qs;
                // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                $g->zealphp_response->redirect($newUrl, 301);
                $g->_streaming = true;
                return null;
            }
        }

        $base = self::resolveDocumentRoot() . '/' . $relDir;
        foreach (self::$directory_index as $indexFile) {
            $abs = realpath($base . '/' . $indexFile);
            if (!$abs || !file_exists($abs)) continue;
            if (!$this->includeCheck($abs)) continue;

            $relPath = '/' . trim($urlPrefix, '/') . '/' . $indexFile;
            if (substr($indexFile, -4) === '.php') {
                // App::include() owns the $_SERVER preamble + the contract.
                return App::include($relPath);
            }
            $g->server['PHP_SELF']        = $relPath;
            $g->server['SCRIPT_NAME']     = $relPath;
            $g->server['SCRIPT_FILENAME'] = $abs;
            // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
            $g->zealphp_response->sendFile($abs);
            $g->_streaming = true;
            return null;
        }
        return false;
    }

    /**
     * Run a public/ file with Apache document-root parity and the framework's
     * universal return contract.
     *
     * Path resolution: $publicPath is relative to App::$document_root (defaults
     * to "public"). Leading slash optional — '/article.php' and 'article.php'
     * both resolve to public/article.php. Same convention as a URL path.
     *
     * Security: includeCheck() rejects paths outside the document root and
     * dotfile segments (when App::$block_dotfiles is on); refused paths return
     * int(403) so ResponseMiddleware can render the right status.
     *
     * Apache parity: $g->server['PHP_SELF'], SCRIPT_NAME, SCRIPT_FILENAME are
     * auto-populated before include so the file sees canonical $_SERVER values
     * — callers no longer need the 3-line preamble.
     *
     * In superglobals mode (legacy apps) dispatches via cgiSubprocess(); in
     * coroutine mode runs in-process via executeFile(). Return value is the
     * same shape in both modes (the subprocess metadata channel carries it).
     *
     * @see App::executeFile() (private core) and the sibling methods (render / renderToString / renderStream).
     *
     * @param array<string,mixed> $args  Extracted into the file's scope (coroutine mode only)
     */
    public static function include(string $publicPath, array $args = []): mixed
    {
        $rel    = ltrim($publicPath, '/');
        $docAbs = self::resolveDocumentRoot();
        $absPath = realpath($docAbs . '/' . $rel);

        $app = self::instance();
        if (!$app || $absPath === false || !$app->includeCheck($absPath)) {
            return 403;
        }

        $g = RequestContext::instance();
        $scriptName = '/' . $rel;
        $g->server['SCRIPT_NAME']     = $scriptName;
        $g->server['SCRIPT_FILENAME'] = $absPath;
        // #306 — PHP_SELF = SCRIPT_NAME . PATH_INFO. When the ResponseMiddleware
        // `.php/extra` rewrite already set a PATH_INFO suffix for this request,
        // honour it; otherwise PHP_SELF == SCRIPT_NAME (no path-info).
        $pathInfo = isset($g->server['PATH_INFO']) ? (string)$g->server['PATH_INFO'] : '';
        $g->server['PHP_SELF']        = $pathInfo !== '' ? $scriptName . $pathInfo : $scriptName;

        // A matched route's `backend:` option (request-scoped, set by
        // ResponseMiddleware::dispatchRoute) overrides the path-resolved backend
        // for THIS request's include — and authorises execution for it.
        $cgi   = self::applyRouteBackend(self::resolveCgiBackend($absPath, '/' . $rel));
        $isPhp = str_ends_with(strtolower($absPath), '.php');

        if (!$isPhp) {
            if ($cgi['mayExecute']) {
                $b = $cgi['backend'];
                return match ($b['mode']) {
                    'fcgi'  => \ZealPHP\CGI\Dispatcher::cgiFcgi($absPath, $b['address'] ?? null, $b['fcgi_params'] ?? []),
                    'pool'  => \ZealPHP\CGI\Dispatcher::cgiPool($absPath),
                    'proc'  => \ZealPHP\CGI\Dispatcher::cgiSubprocess($absPath, $b['interpreter'] ?? null),
                    'fork'  => \ZealPHP\CGI\Dispatcher::cgiFork($absPath),
                    default => \ZealPHP\CGI\Dispatcher::cgiPool($absPath),  // default = 'pool'
                };
            }
            // SECURITY: a non-PHP file that is NOT in an exec scope must NOT be
            // PHP-parsed (executeFile would treat it as PHP) and must NOT have its
            // source served (Apache's ExecCGI-off leaks script source — we refuse).
            self::logExecScopeMiss('/' . $rel, $absPath);
            return 403;
        }

        // .php from here on:
        if (self::$coproc_implicit_request_handler) {
            // Legacy isolation path — byte-identical to before. .php is always
            // executable in isolation mode (no ExecCGI gate applied to .php), and
            // the dispatch honors the resolved backend mode: a registered .php
            // backend (fork/fcgi, e.g. PHP-FPM) or the global $cgi_mode fallback
            // routes accordingly; default 'proc' → cgiSubprocess(.., null) →
            // cgi_worker.php for uopz header/cookie capture.
            $b = $cgi['backend'];
            return match ($b['mode']) {
                'fcgi'  => \ZealPHP\CGI\Dispatcher::cgiFcgi($absPath, $b['address'] ?? null, $b['fcgi_params'] ?? []),
                'pool'  => \ZealPHP\CGI\Dispatcher::cgiPool($absPath),
                'proc'  => \ZealPHP\CGI\Dispatcher::cgiSubprocess($absPath, $b['interpreter'] ?? null),
                'fork'  => \ZealPHP\CGI\Dispatcher::cgiFork($absPath),
                default => \ZealPHP\CGI\Dispatcher::cgiPool($absPath),  // default = 'pool'
            };
        }
        return self::executeFile($absPath, $args);       // coroutine-mode fast path (unchanged)
    }

    /**
     * @deprecated since 0.2.18 — use App::include() with a public-relative path.
     *
     * Legacy alias kept for the WordPress showcase and existing user scaffolds.
     * Accepts an absolute path. For paths under the document root, delegates
     * to App::include() (security check + $_SERVER preamble apply). For paths
     * outside (e.g. test fixtures, embedded utilities), passes straight to the
     * shared core so the return contract applies but no security gate fires —
     * matching the historical includeFile() behaviour.
     */
    public static function includeFile(string $path): mixed
    {
        $docAbs = self::resolveDocumentRoot();
        if (strpos($path, $docAbs) === 0) {
            $rel = substr($path, strlen($docAbs));
            return self::include($rel, []);
        }
        // Outside the document root — preserve legacy "trust the caller"
        // semantics while still applying the universal return contract. A
        // route `backend:` override still applies (same request-scoped slot).
        $cgi   = self::applyRouteBackend(self::resolveCgiBackend($path, $path));
        $isPhp = str_ends_with(strtolower($path), '.php');

        if (!$isPhp) {
            if ($cgi['mayExecute']) {
                $b = $cgi['backend'];
                return match ($b['mode']) {
                    'fcgi'  => \ZealPHP\CGI\Dispatcher::cgiFcgi($path, $b['address'] ?? null, $b['fcgi_params'] ?? []),
                    'pool'  => \ZealPHP\CGI\Dispatcher::cgiPool($path),
                    'proc'  => \ZealPHP\CGI\Dispatcher::cgiSubprocess($path, $b['interpreter'] ?? null),
                    'fork'  => \ZealPHP\CGI\Dispatcher::cgiFork($path),  // #428 — was missing; fork silently fell to pool
                    default => \ZealPHP\CGI\Dispatcher::cgiPool($path),
                };
            }
            // SECURITY: a non-PHP file outside an exec scope must NOT be PHP-parsed
            // by executeFile() nor have its source served. Refuse it.
            self::logExecScopeMiss($path, $path);
            return 403;
        }

        // .php from here on:
        if (self::$coproc_implicit_request_handler) {
            // Legacy isolation path — byte-identical to before: .php is always
            // executable in isolation mode and dispatch honors the resolved
            // backend mode (registered .php backend or global $cgi_mode fallback).
            $b = $cgi['backend'];
            return match ($b['mode']) {
                'fcgi'  => \ZealPHP\CGI\Dispatcher::cgiFcgi($path, $b['address'] ?? null, $b['fcgi_params'] ?? []),
                'pool'  => \ZealPHP\CGI\Dispatcher::cgiPool($path),
                'proc'  => \ZealPHP\CGI\Dispatcher::cgiSubprocess($path, $b['interpreter'] ?? null),
                'fork'  => \ZealPHP\CGI\Dispatcher::cgiFork($path),  // #428 — was missing; fork silently fell to pool
                default => \ZealPHP\CGI\Dispatcher::cgiPool($path),
            };
        }
        return self::executeFile($path, []);
    }

    /**
     * Resolve App::$document_root to an absolute path. Relative values are
     * treated as ${App::$cwd}/$document_root; absolute values pass through.
     */
    public static function resolveDocumentRoot(): string
    {
        $root = self::$document_root;
        if ($root !== '' && $root[0] === '/') {
            return rtrim($root, '/');
        }
        return self::$cwd . '/' . rtrim($root, '/');
    }

    /**
     * Percent-decode a path repeatedly until it stops changing.
     *
     * Apache normalises before each access check, so a double-encoded payload
     * like `%252e%252e` (which decodes once to `%2e%2e`, then again to `..`)
     * is caught. A single `rawurldecode()` only peels one layer — leaving the
     * traversal sequence intact after the first decode. Decoding until stable
     * closes that gap. The iteration count is capped so a pathological input
     * (`%2525252525…`) can't spin the CPU; once the cap is hit we return the
     * partially-decoded form and let the caller's traversal/null-byte checks
     * run against it (any surviving `..`/`%` is treated conservatively).
     */
    public static function decodeUntilStable(string $path, int $maxIterations = 10): string
    {
        for ($i = 0; $i < $maxIterations; $i++) {
            $next = rawurldecode($path);
            if ($next === $path) {
                return $path;
            }
            $path = $next;
        }
        return $path;
    }

    /**
     * Normalise a request path the way Apache's `ap_normalize_path()` does
     * (`server/util.c`): collapse runs of `//` to a single `/` (MergeSlashes,
     * on by default), drop `/./` segments, and unwind `/segment/../` back over
     * the preceding segment. A `..` that would climb above root is dropped
     * (clamped at `/`), matching Apache's behaviour for the routing path.
     *
     * Operates on an already percent-decoded, query-stripped path. Returns a
     * path that always starts with `/` for absolute inputs; a `*` (OPTIONS
     * asterisk-form) or empty input is returned unchanged.
     */
    public static function normalizeRequestPath(string $path): string
    {
        if ($path === '' || $path === '*') {
            return $path;
        }

        $absolute = $path[0] === '/';
        $segments = explode('/', $path);
        /** @var array<int, string> $out */
        $out = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                // Empty segment = duplicate slash; '.' = current dir. Drop both.
                continue;
            }
            if ($segment === '..') {
                // Unwind one real segment; clamp at root rather than escaping.
                if ($out !== [] && end($out) !== '..') {
                    array_pop($out);
                } elseif (!$absolute) {
                    $out[] = '..';
                }
                continue;
            }
            $out[] = $segment;
        }

        $normalized = implode('/', $out);
        if ($absolute) {
            $normalized = '/' . $normalized;
        }
        // Preserve a single trailing slash when the original ended in one and
        // the result is more than just root, so DirectorySlash handling and
        // strip-trailing-slash logic see the same shape they did before.
        if ($normalized !== '/' && substr($path, -1) === '/' && substr($normalized, -1) !== '/') {
            $normalized .= '/';
        }
        return $normalized === '' ? '/' : $normalized;
    }

    /**
     * Build the OS-level environment array passed to the CGI subprocess.
     *
     * Thin public delegating shim — the implementation moved to
     * {@see \ZealPHP\CGI\Dispatcher::buildCgiEnv()} (Phase 2 refactor).
     * Kept on App for BC so external callers/tests reach it via App::buildCgiEnv().
     *
     * @param array<string, mixed> $server  $g->server (OpenSwoole-populated)
     * @param string               $ctx     JSON-encoded ZEALPHP_REQUEST_CONTEXT
     * @return array<string, string>
     */
    public static function buildCgiEnv(array $server, string $ctx): array
    {
        return \ZealPHP\CGI\Dispatcher::buildCgiEnv($server, $ctx);
    }

    /**
     * True when the CGI subprocess is the sole owner of the per-request
     * session lifecycle — `superglobals(true)` + `processIsolation(true)`.
     *
     * Issue #108 — when both the host (SessionManager) AND the subprocess
     * (native PHP session_start in cgi_worker / pool_worker) drive session
     * I/O on the same file, the host's `session_write_close()` in the
     * `finally` block races the subprocess's exit-time flush. The host's
     * stale in-memory $_SESSION wins and overwrites everything the
     * subprocess wrote. The fix is to let the subprocess own the session
     * fully in this lifecycle — the host SessionManager skips session_start,
     * cookie emission, and session_write_close. The subprocess's native
     * session machinery handles all three (it captures its own Set-Cookie
     * via uopz; cgiPool / cgiSubprocess / cgiFcgi thread the captured
     * cookies back into the outbound response).
     *
     * Other lifecycle combos are unaffected:
     *   - Coroutine mode (`superglobals(false)`): CoSessionManager runs, no
     *     subprocess involved, no race.
     *   - Mixed-mode (`superglobals(true)` + `processIsolation(false)`):
     *     host runs everything in-process — host SessionManager IS the
     *     session owner. No subprocess race.
     */
    public static function cgiOwnsSessions(): bool
    {
        return self::$superglobals === true && self::processIsolation() === true;
    }

    /**
     * Parse a raw CGI/1.1 interpreter response (RFC 3875) into status, headers
     * and body — pure, side-effect-free string handling.
     *
     * Thin public delegating shim — the implementation moved to
     * {@see \ZealPHP\CGI\Dispatcher::parseCgiResponse()} (Phase 2 refactor).
     * Kept on App for BC so external callers/tests reach it via App::parseCgiResponse().
     *
     * @return array{status: int|null, headers: list<array{0:string,1:string}>, body: string}
     */
    public static function parseCgiResponse(string $raw): array
    {
        return \ZealPHP\CGI\Dispatcher::parseCgiResponse($raw);
    }

    /**
     * Register a fallback handler for unmatched routes (like Apache's RewriteRule . /index.php [L]).
     */
    public function setFallback(callable $handler): void
    {
        self::$fallback_handler = [
            'handler'   => $handler,
            'param_map' => $this->buildParamMap($handler),
            'raw'       => false,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getFallback(): ?array
    {
        return self::$fallback_handler;
    }

    public function addMiddleware(\Psr\Http\Server\MiddlewareInterface $middleware): void
    {
        self::$middleware_wait_stack[] = $middleware;
    }

    /**
     * Register a named, reusable middleware — the "named & shared" middleware
     * vocabulary from Traefik, the route-middleware alias from Laravel.
     *
     * The alias can then be referenced by name in the `middleware:` route
     * option (or a route group) instead of constructing the instance inline:
     *
     * ```php
     * App::middlewareAlias('auth',       fn() => new BasicAuthMiddleware($verifier));
     * App::middlewareAlias('admin-only', new IpAccessMiddleware(['allow' => ['10.0.0.0/8']]));
     * App::middlewareAlias('throttle',   fn($n = '60') => new RateLimitMiddleware(limit: (int)$n));
     *
     * $app->route('/admin/users', middleware: ['auth', 'admin-only', 'throttle:120'],
     *     handler: fn() => User::all());
     * ```
     *
     * Pass either a ready `MiddlewareInterface` instance (reused as-is) or a
     * factory `callable` that returns one. Factories run **once** at
     * `App::run()` (boot, single-coroutine — safe), and the resulting instance
     * is shared across every request that uses the alias. A parameterised
     * reference `'throttle:120'` calls the factory with the comma-split args
     * (`fn('120')`), mirroring Laravel's `throttle:60,1`.
     *
     * Middleware instances MUST be stateless — one object serves all concurrent
     * coroutines; per-request state belongs in `$g` (`RequestContext`), never on
     * the middleware object.
     */
    public static function middlewareAlias(string $name, \Psr\Http\Server\MiddlewareInterface|callable $factory): void
    {
        self::$middleware_aliases[$name] = $factory;
    }

    /**
     * Scope a middleware chain to a URL **path** — the centralized,
     * "think like Traefik" way to apply middleware to a slice of the site,
     * **including the ZealAPI layer**. Because every request (route or
     * `api/**` file) flows through the same stack and `api/admin/x` is just the
     * URL `/api/admin/x`, one mechanism covers everything — there is no separate
     * "api middleware".
     *
     * ```php
     * App::middlewareAlias('auth', fn() => new BasicAuthMiddleware($verifier));
     *
     * App::when('/',           ['request-id']);          // every request
     * App::when('/admin',      ['auth', 'admin-only']);  // /admin and /admin/*
     * App::when('/api/admin',  ['auth']);                // api/admin/*.php endpoints
     * App::when('/api/admin/users/delete', ['audit']);   // a single api endpoint
     * App::when('#^/api/v\d+/#', new CorsMiddleware());  // a PCRE scope
     * ```
     *
     * **Scope syntax:** a literal **path prefix** by default (matched on segment
     * boundaries — `/admin` matches `/admin` and `/admin/x` but NOT
     * `/administrators`); a **PCRE** when the string starts with `#`.
     * `'/'` (or an empty string) matches everything. Regex scopes are matched
     * **unanchored** (`preg_match`), so a guard intended for a subtree MUST
     * anchor it — `'#^/admin(/|$)#'`, not `'#/admin#'` (the latter also matches
     * `/x/badmin`). Prefer a literal prefix unless you genuinely need a regex.
     *
     * **Accepts** a `MiddlewareInterface` instance, a registered alias string
     * (incl. parameterised `'throttle:120'`), or a list mixing both.
     *
     * **Ordering:** runs inside the request lifecycle after path normalization
     * and after CORS/OPTIONS handling (so a `when` auth guard never blocks a
     * preflight), wrapping route match + dispatch. Multiple `when()` registrations
     * compose in **registration order — first registered is outermost**. The full
     * per-request order is: global `addMiddleware` → `App::when` → the route's own
     * `middleware:` (or an api file's in-file `$middleware`) → handler; the
     * response unwinds in reverse. A middleware that returns without calling the
     * handler short-circuits.
     *
     * **Stateless contract:** the resolved instance is shared across every
     * concurrent request in scope — keep per-request state in `$g`
     * (`RequestContext`), never on the middleware object.
     *
     * Resolution (alias→instance) happens once at `App::run()`; the hot path only
     * does a cheap, memoized path-prefix scan.
     *
     * @param MiddlewareInterface|string|array<int,MiddlewareInterface|string> $middleware
     */
    public static function when(string $pathPrefixOrRegex, \Psr\Http\Server\MiddlewareInterface|string|array $middleware): void
    {
        if ($pathPrefixOrRegex !== '' && $pathPrefixOrRegex[0] === '#') {
            $type = 'regex';
            $key  = $pathPrefixOrRegex;
        } else {
            $type = 'prefix';
            $trimmed = trim($pathPrefixOrRegex, '/');
            $key = $trimmed === '' ? '/' : '/' . $trimmed;
        }
        self::$when_middleware[] = [
            'type' => $type,
            'key'  => $key,
            'spec' => self::normalizeMiddlewareSpec($middleware),
        ];
    }

    /**
     * Select the `App::when` middleware chain for a normalized request path —
     * every matching scope's instances flattened in registration order
     * (outermost first). Memoized per path; the registry is immutable after
     * boot, so this never recomputes for a repeated path. Returns `[]` (the
     * fast path) when nothing is registered or nothing matches.
     *
     * @return list<MiddlewareInterface>
     */
    public static function resolveWhenMiddleware(string $normPath): array
    {
        if (self::$when_middleware_compiled === []) {
            return [];
        }
        if (array_key_exists($normPath, self::$when_middleware_memo)) {
            return self::$when_middleware_memo[$normPath];
        }
        $flat = [];
        foreach (self::$when_middleware_compiled as $entry) {
            if (self::whenScopeMatches($entry['type'], $entry['key'], $normPath)) {
                foreach ($entry['chain'] as $mw) {
                    $flat[] = $mw;
                }
            }
        }
        // Bounded cache: stop inserting past the cap (still correct — a miss
        // just recomputes the cheap scan). Defends against path-spray memory DoS.
        if (count(self::$when_middleware_memo) < self::WHEN_MEMO_MAX) {
            self::$when_middleware_memo[$normPath] = $flat;
        }
        return $flat;
    }

    /**
     * Whether an `App::when` scope matches a normalized path. Prefixes match on
     * segment boundaries (the trailing `/` stops `/admin` matching
     * `/administrators`); `'/'` matches all; regex scopes use `preg_match`.
     */
    private static function whenScopeMatches(string $type, string $key, string $normPath): bool
    {
        if ($type === 'regex') {
            return preg_match($key, $normPath) === 1;
        }
        return $key === '/' || $normPath === $key || str_starts_with($normPath, $key . '/');
    }

    /**
     * Validate + flatten a per-route middleware spec into a list, WITHOUT
     * resolving aliases (resolution is deferred to `App::run()` so an alias may
     * be registered after the route that references it). A single instance or
     * alias string is wrapped into a one-element list.
     *
     * @param mixed $spec
     * @return list<\Psr\Http\Server\MiddlewareInterface|string>
     */
    public static function normalizeMiddlewareSpec($spec): array
    {
        if ($spec instanceof \Psr\Http\Server\MiddlewareInterface || is_string($spec)) {
            $spec = [$spec];
        }
        if (!is_array($spec)) {
            throw new \InvalidArgumentException(
                'Route middleware must be a MiddlewareInterface, an alias string, or a list of them.'
            );
        }
        $out = [];
        foreach ($spec as $entry) {
            if (!($entry instanceof \Psr\Http\Server\MiddlewareInterface) && !is_string($entry)) {
                throw new \InvalidArgumentException(
                    'Each route middleware must be a MiddlewareInterface instance or an alias string.'
                );
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Resolve a normalized middleware spec (instances + alias strings) to a
     * flat list of `MiddlewareInterface` instances. Called once per route at
     * `App::run()` boot time, so the dispatch hot path never does a registry
     * lookup or `new`.
     *
     * @param list<\Psr\Http\Server\MiddlewareInterface|string> $spec
     * @return list<\Psr\Http\Server\MiddlewareInterface>
     */
    public static function compileMiddlewareChain(array $spec): array
    {
        $out = [];
        foreach ($spec as $entry) {
            $out[] = self::resolveMiddleware($entry);
        }
        return $out;
    }

    /**
     * Resolve one spec entry to an instance. An instance passes through; an
     * alias string is looked up in the registry (`name` or `name:arg1,arg2`).
     */
    private static function resolveMiddleware(\Psr\Http\Server\MiddlewareInterface|string $entry): \Psr\Http\Server\MiddlewareInterface
    {
        if ($entry instanceof \Psr\Http\Server\MiddlewareInterface) {
            return $entry;
        }
        $name = $entry;
        $args = [];
        if (str_contains($entry, ':')) {
            [$name, $argString] = explode(':', $entry, 2);
            $args = $argString === '' ? [] : explode(',', $argString);
        }
        if (!isset(self::$middleware_aliases[$name])) {
            throw new \InvalidArgumentException(
                "Unknown middleware alias '{$name}'. Register it first with "
                . "App::middlewareAlias('{$name}', fn() => new SomeMiddleware())."
            );
        }
        $factory = self::$middleware_aliases[$name];
        if ($factory instanceof \Psr\Http\Server\MiddlewareInterface) {
            return $factory;
        }
        $resolved = $factory(...$args);
        if (!$resolved instanceof \Psr\Http\Server\MiddlewareInterface) {
            throw new \InvalidArgumentException(
                "Middleware alias '{$name}' factory must return a Psr\\Http\\Server\\MiddlewareInterface."
            );
        }
        return $resolved;
    }

    /**
     * Route group — apply a shared URL prefix and/or a shared middleware chain
     * to many routes at once (Traefik chains, Slim/Laravel route groups).
     *
     * ```php
     * $app->group('/admin', ['auth', 'admin-only'], function ($g) {
     *     $g->route('/users',    fn() => User::all());
     *     $g->route('/settings', fn() => Settings::get());
     * });
     * ```
     *
     * The group middleware wraps **outside** any route-level middleware, which
     * wrap outside the handler. Groups nest — an inner `$g->group()` composes
     * its prefix and middleware onto the outer group's. The callback receives a
     * `RouteGroup` whose `route()/nsRoute()/nsPathRoute()/patternRoute()/group()`
     * mirror `App`'s, transparently prepending the prefix and prepending the
     * shared middleware.
     *
     * `$middleware` may be omitted: `$app->group('/admin', fn ($g) => ...)`.
     *
     * @param array<int, \Psr\Http\Server\MiddlewareInterface|string>|callable $middleware
     */
    public function group(string $prefix, array|callable $middleware = [], ?callable $registrar = null): void
    {
        if (is_callable($middleware) && $registrar === null) {
            $registrar = $middleware;
            $middleware = [];
        }
        if ($registrar === null) {
            throw new \InvalidArgumentException('App::group() requires a registrar callback.');
        }
        $group = new RouteGroup($this, $prefix, self::normalizeMiddlewareSpec($middleware));
        $registrar($group);
    }

    /**
     * Combine the two ways a route can declare middleware — the `'middleware'`
     * key of the `$options` array and the `middleware:` named argument — into a
     * single normalized spec. When both are present the array-option entries
     * run first (outermost). Stored on the route as the raw spec; resolved to
     * instances at `App::run()`.
     *
     * @param array<int|string,mixed> $options
     * @param array<int,\Psr\Http\Server\MiddlewareInterface|string> $middlewareArg
     * @return list<\Psr\Http\Server\MiddlewareInterface|string>
     */
    private static function routeMiddlewareSpec(array $options, array $middlewareArg): array
    {
        $fromOptions = isset($options['middleware']) ? self::normalizeMiddlewareSpec($options['middleware']) : [];
        $fromArg = $middlewareArg !== [] ? self::normalizeMiddlewareSpec($middlewareArg) : [];
        return array_merge($fromOptions, $fromArg);
    }

    private function invokeFallbackOrNotFound(): \Psr\Http\Message\ResponseInterface
    {
        // Dispatch the fallback as a real route so its body — whether echoed,
        // returned as string/array/Generator/Response — is preserved instead of
        // being discarded by the outer route's int-return path in dispatchRoute.
        if (self::$fallback_handler !== null) {
            $method = (string)(RequestContext::instance()->server['REQUEST_METHOD'] ?? 'GET');
            return (new ResponseMiddleware())->dispatchRoute(self::$fallback_handler, [], $method);
        }
        return $this->renderError(404);
    }

    /**
     * Register a custom error page handler — Apache's `ErrorDocument` equivalent.
     *
     * Status-specific:  $app->setErrorHandler(404, fn() => App::render('404'));
     * Catch-all:        $app->setErrorHandler(fn($status) => ...);
     *
     * Handler signature supports param injection by name — any of:
     *   function() | function($status) | function($exception) |
     *   function($status, $exception, $request, $response)
     */
    public function setErrorHandler(int|callable $statusOrHandler, ?callable $handler = null): void
    {
        if (is_callable($statusOrHandler) && $handler === null) {
            $cb = $statusOrHandler;
            $status = 0; // catch-all
        } else {
            assert(is_int($statusOrHandler));
            $status = $statusOrHandler;
            $cb = $handler;
        }
        if (!is_callable($cb)) {
            throw new \InvalidArgumentException('setErrorHandler requires a callable');
        }
        self::$error_handlers[$status] = [
            'handler'   => $cb,
            'param_map' => $this->buildParamMap($cb),
            'raw'       => false,
        ];
    }

    /**
     * @return array{handler:callable, param_map:array<int, array{name:string, has_default:bool, default:mixed}>, raw:bool}|null
     */
    public static function getErrorHandler(int $status): ?array
    {
        return self::$error_handlers[$status] ?? self::$error_handlers[0] ?? null;
    }

    /**
     * Clear previously-accumulated response headers from a handler that then
     * failed, keeping only the headers that Apache preserves across an error
     * response (ap_send_error_response: apr_table_clear(r->headers_out) then
     * re-instate headers required by HTTP protocol for specific status codes).
     *
     * Apache parity (http_protocol.c:1246-1292):
     *   Location        — preserved from err_headers_out for redirect chains.
     *   WWW-Authenticate — preserved for 401 (mod_auth sets it in err_headers_out,
     *                      http_request.c:604).
     *   Allow           — Apache re-adds Allow for 405/501 inside ap_send_error_response
     *                     after the table clear (http_protocol.c:1289-1292). We preserve
     *                     any Allow header the framework set before calling renderError()
     *                     (e.g. the 405 dispatch path) rather than clearing + re-adding.
     *
     * Called at the top of renderError() so the policy applies to both custom
     * handler dispatch and the default error body paths.
     */
    private function clearHandlerHeaders(int $status): void
    {
        $g = RequestContext::instance();
        if ($g->zealphp_response === null) {
            return;
        }
        // Always-preserved headers (Apache err_headers_out equivalents):
        //   location         — redirect chains
        //   allow            — RFC 9110 §15.5.6: required on 405/501; Apache re-adds
        //                      after the clear, so we preserve rather than wipe + re-add
        $preserveNames = ['location', 'allow'];
        // WWW-Authenticate is only meaningful on 401; preserve it there only so a
        // handler that happened to set it for a different status can't leak it.
        if ($status === 401) {
            $preserveNames[] = 'www-authenticate';
        }
        $g->zealphp_response->headersList = array_values(
            array_filter(
                $g->zealphp_response->headersList,
                static function (array $pair) use ($preserveNames): bool {
                    return in_array(strtolower($pair[0]), $preserveNames, true);
                }
            )
        );
    }

    /**
     * Render the response for an error status. Dispatches a user-registered
     * handler if one exists (status-specific takes precedence over catch-all);
     * otherwise returns the framework's default body (HTML or JSON per Accept).
     *
     * Handler exceptions are caught and logged — falls back to default body
     * so a buggy 500 handler can't infinite-loop.
     */
    public function renderError(int $status, ?\Throwable $exception = null): \Psr\Http\Message\ResponseInterface
    {
        $g = RequestContext::instance();
        // Apache ap_send_error_response parity: clear headers the failed handler
        // accumulated before emitting the error body. Preserves Location (redirect
        // chains) and, for 401 only, WWW-Authenticate (Basic/Digest challenge).
        $this->clearHandlerHeaders($status);
        // Recursion guard — if a user-registered error handler itself triggers
        // an error, the nested call falls straight through to the default page
        // instead of looping back into the same handler.
        if ($g->error_render_depth >= 1) {
            return $this->defaultErrorResponse($status, $exception);
        }
        $route = self::getErrorHandler($status);
        if ($route !== null) {
            $g->error_status    = $status;
            $g->error_exception = $exception;
            // Seed g->status with the error status so a handler that returns array/string
            // produces a response with the right HTTP status (the handler can still
            // override via http_response_code() before returning).
            $g->status = $status;
            $g->error_render_depth = $g->error_render_depth + 1;
            try {
                $method = (string)($g->server['REQUEST_METHOD'] ?? 'GET');
                return (new ResponseMiddleware())->dispatchRoute(
                    $route,
                    ['status' => $status, 'exception' => $exception],
                    $method
                );
            } catch (\Throwable $e) {
                elog("Error handler for $status itself threw: " . $e->getMessage(), 'error');
                // fall through to default
            } finally {
                $g->error_render_depth = max(0, $g->error_render_depth - 1);
            }
        }
        return $this->defaultErrorResponse($status, $exception);
    }

    /**
     * Default error body. Honors `Accept: application/json` for JSON envelope,
     * otherwise emits HTML. Stack trace included only when App::$display_errors.
     */
    private function defaultErrorResponse(int $status, ?\Throwable $exception): \Psr\Http\Message\ResponseInterface
    {
        $g = RequestContext::instance();
        $reason = self::REASON_PHRASES[$status] ?? '';
        // HEAD strips the body on error responses too (Apache ap_send_error_response
        // honours r->header_only). Content-Length still reflects the body that a
        // GET would have produced, so we compute the body then drop it for HEAD.
        $isHead = (string)($g->server['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
        $accept = strtolower((string)($g->server['HTTP_ACCEPT'] ?? ''));
        $wantsJson = $accept !== ''
            && str_contains($accept, 'application/json')
            && !str_contains($accept, 'text/html');

        if ($wantsJson) {
            $errorPayload = [
                'status'  => $status,
                'message' => $reason,
                'trace'   => ($exception && self::displayErrors()) ? jTraceEx($exception) : null,
            ];
            // Apache ServerAdmin parity: surface the configured contact in
            // machine-readable error responses too, so API clients can route
            // bug reports without scraping HTML.
            if (self::$server_admin !== null && self::$server_admin !== '') {
                $errorPayload['contact'] = self::$server_admin;
            }
            $body = (string)json_encode(['error' => $errorPayload], JSON_UNESCAPED_SLASHES);
            // HEAD strips the body but keeps Content-Length for the entity a GET
            // would have produced — emitted via the buffered header list (the
            // same path the normal HEAD dispatch branches use).
            if ($isHead) {
                response_add_header('Content-Length', (string)strlen($body));
            }
            $resp = (new Response($isHead ? '' : $body))
                ->withStatus($status)
                ->withHeader('Content-Type', 'application/json');
            assert($resp instanceof \Psr\Http\Message\ResponseInterface);
            return $resp;
        }

        $body = "<pre>{$status} {$reason}</pre>";
        if ($exception && self::displayErrors()) {
            $body .= "\n<pre>" . htmlspecialchars(jTraceEx($exception)) . "</pre>";
        }
        // Apache ServerAdmin parity: default error pages show a contact line
        // when one is configured. Mirrors mod_core's behaviour and the
        // <address> block Apache appends when ServerSignature is on.
        if (self::$server_admin !== null && self::$server_admin !== '') {
            $body .= "\n<address>Contact: " . htmlspecialchars(self::$server_admin) . "</address>";
        }
        if ($isHead) {
            response_add_header('Content-Length', (string)strlen($body));
            return (new Response(''))->withStatus($status);
        }
        return (new Response($body))->withStatus($status);
    }

    /**
     * Runs the ZealPHP application.
     *
     * @param array|null $settings Optional settings to override the default OpenSwoole Server Configuration settings.
     *
     * Default settings:
     * - enable_static_handler: bool (default: true)
     * - document_root: string (default: self::$cwd . '/public')
     * - enable_coroutine: bool (default: true)
     * - pid_file: string (default: '/tmp/zealphp_{port}.pid')
     *
     * CLI usage:
     *   php app.php [start|stop|status] [-p port] [-H host] [-w workers] [-d] [--task-workers N] [--pid-file path] [--dev]
     *
     * ZealPHP-specific keys (e.g. `'api_warn_collisions' => false`) are
     * extracted and applied via static setters before the rest passes to
     * OpenSwoole. See `$configMap` for the full list.
     *
     * @param ?array<string, mixed> $settings
     */
    /**
     * Include the `route/*.php` files (the file-based route definitions).
     * Re-runnable by `reloadRoutes()`; at boot it runs once.
     */
    private function includeRouteFiles(): void
    {
        $route_files = glob(self::$cwd . "/route/*.php") ?: [];
        foreach ($route_files as $route_file) {
            elog("Including route file: " . str_replace(App::$cwd, '', $route_file));
            include $route_file;
        }
    }

    /**
     * Resolve per-route + `App::when` middleware specs (alias → instance) and
     * (re)build the method-indexed dispatch table. Idempotent — it resets the
     * indexes first, so `reloadRoutes()` can call it to rebuild from scratch; at
     * boot it runs exactly once.
     */
    private function compileRouteTable(): void
    {
        foreach ($this->routes as $i => $route) {
            if ($route['middleware'] !== []) {
                $this->routes[$i]['middleware'] = self::compileMiddlewareChain($route['middleware']);
            }
        }

        self::$when_middleware_compiled = [];
        foreach (self::$when_middleware as $entry) {
            self::$when_middleware_compiled[] = [
                'type'  => $entry['type'],
                'key'   => $entry['key'],
                'chain' => self::compileMiddlewareChain($entry['spec']),
            ];
        }
        self::$when_middleware_memo = [];

        $this->routes_by_method = [];
        $this->routes_by_exact_method = [];
        foreach ($this->routes as $route) {
            foreach ($route['methods'] as $m) {
                $this->routes_by_method[$m][] = $route;
                /** @phpstan-ignore-next-line isset on always-present key kept defensively */
                if (isset($route['path']) && $this->isExactRoutePath($route['path'])) {
                    $this->routes_by_exact_method[$m][$route['path']] = $route;
                }
            }
        }
    }

    /**
     * **Hot-reload the route table from `route/*.php` WITHOUT restarting the
     * worker process.** Restores the app.php-defined baseline (explicit routes +
     * the alias / `App::when` registries), re-includes the route files (picking
     * up edits — opcache is invalidated for them first), re-appends the
     * framework's implicit routes in priority order, and rebuilds the dispatch
     * table. Returns the new route count.
     *
     * Scope (be precise): only route DEFINITIONS reload. **`app.php` lifecycle
     * config — mode/superglobals/worker-counts/the global middleware stack — is
     * frozen at boot by OpenSwoole and is NOT affected.** Infrastructure a route
     * file wires at boot (`Store::make`, `App::subscribe`, `App::onWorkerStart`,
     * `App::addProcess`, `App::onSignal`, timers) is NOT re-run: those calls
     * detect `App::$reloading` and keep their boot registration; only `route()`,
     * `App::when()`, `App::middlewareAlias()`, and `App::ws()` take effect.
     *
     * Typically driven by the dev mtime-watcher (`App::devReload(true)`); also
     * callable directly for a programmatic/CLI reload.
     */
    public function reloadRoutes(): int
    {
        $baseline = $this->route_baseline;
        if ($baseline === null) {
            return count($this->routes); // run() hasn't built the table yet
        }
        // SAFETY: re-including a route file that declares a top-level `function`
        // would FATAL with "Cannot redeclare ..." in coroutine mode (no
        // silent-redeclare), crashing the worker. Detect that and REFUSE the
        // reload — keep the live table intact — rather than crash. Function-free
        // route files (the documented "no functions in route/" rule) hot-reload
        // fine; `App::mode('coroutine-legacy')` tolerates redeclaration.
        $blocker = self::routeFileWithTopLevelFunction();
        if ($blocker !== null) {
            elog(
                "Route hot-reload skipped: '" . $blocker . "' declares a top-level function, "
                . "which can't be safely re-included in coroutine mode. Move helpers into a "
                . "src/ class (PSR-4) or use App::mode('coroutine-legacy'). Route table unchanged.",
                "warn"
            );
            return count($this->routes);
        }
        self::$reloading = true;
        try {
            // Restore the app.php-defined registries to baseline so a removed
            // alias/scope/route in a route file actually disappears on reload.
            self::$when_middleware          = $baseline['when'];
            self::$middleware_aliases       = $baseline['aliases'];
            self::$cgi_backend_aliases      = $baseline['backend_aliases'] ?? [];
            self::$when_middleware_compiled = [];
            self::$when_middleware_memo     = [];

            // Invalidate opcache for the route files so the re-include sees edits
            // (no-op if opcache is off or validate_timestamps already handles it).
            if (function_exists('opcache_invalidate')) {
                foreach (glob(self::$cwd . "/route/*.php") ?: [] as $rf) {
                    @opcache_invalidate($rf, true);
                }
            }

            // Rebuild in priority order: app.php explicit → re-included route
            // files → the framework's implicit routes.
            $this->routes = $baseline['routes'];
            $this->includeRouteFiles();
            foreach ($baseline['implicit'] as $implicitRoute) {
                $this->routes[] = $implicitRoute;
            }
            $this->compileRouteTable();
        } finally {
            self::$reloading = false;
        }
        elog("Routes hot-reloaded: " . count($this->routes) . " routes", "info");
        return count($this->routes);
    }

    /**
     * The first `route/*.php` file that declares a top-level `function` (which
     * cannot be re-included without a redeclaration fatal), or null if every
     * route file is function-free and therefore hot-reloadable. The path is
     * cwd-relative for logging. A `$x = function(){}` closure has no name and is
     * not matched; a class method carrying a visibility keyword isn't either.
     */
    private static function routeFileWithTopLevelFunction(): ?string
    {
        foreach (glob(self::$cwd . "/route/*.php") ?: [] as $f) {
            $src = @file_get_contents($f);
            if ($src !== false && preg_match('/^\s*function\s+\w+\s*\(/m', $src) === 1) {
                return str_replace(self::$cwd, '', $f);
            }
        }
        return null;
    }

    /**
     * Enable/disable dev route hot-reload. When on, each worker polls the
     * `route/*.php` mtimes and calls `reloadRoutes()` on change — "save file →
     * routes update" with no process restart. A no-arg call returns the resolved
     * value; `null` (the default) falls back to the `ZEALPHP_DEV` env var. OFF in
     * production, where the route table stays master-loaded + COW-shared.
     *
     * Heads-up: route-file re-includes pick up edits only if opcache lets them —
     * set `opcache.validate_timestamps=1` (`revalidate_freq=0`) in dev, or rely
     * on the per-reload `opcache_invalidate()`.
     */
    public static function devReload(?bool $enabled = null): bool
    {
        if ($enabled !== null) {
            self::$dev_reload = $enabled;
        }
        if (self::$dev_reload !== null) {
            return self::$dev_reload;
        }
        $env = getenv('ZEALPHP_DEV');
        return $env !== false && $env !== '' && $env !== '0';
    }

    /**
     * @param ?array<string, mixed> $settings
     */
    public function run(?array $settings = null): void
    {
        // Extract ZealPHP-specific config keys and apply them via static
        // setters before OpenSwoole sees the array. This lets callers pass
        // everything in one place:
        //   $app->run(['worker_num' => 16, 'api_warn_collisions' => false]);
        if (is_array($settings)) {
            foreach (self::$configMap as $key => $setter) {
                if (array_key_exists($key, $settings)) {
                    self::$setter($settings[$key]);
                    unset($settings[$key]);
                }
            }
        }

        // Flip the lifecycle-setter guard ON. Any further attempt to set
        // superglobals / processIsolation / enableCoroutine / hookAll
        // throws RuntimeException — those four knobs are frozen at boot
        // (SessionManager class, OpenSwoole enable_coroutine, HOOK_ALL)
        // and mid-game mutation leaves the framework in a half-coroutine
        // half-superglobals race state. See refuseAfterRun() docblock.
        self::$run_has_started = true;

        // (env-var → backend flip moved to App::init() so app.php's
        // Store::make() calls land on the resolved backend the first time.
        // See the commit "fix github_stars boot-order".)

        // H6 + H7 — boot-time self-checks. Surface misconfigurations BEFORE
        // workers fork so the message is visible in master logs (and not
        // hidden behind a 5s acquire timeout in some worker minutes later).
        foreach (self::redisBootChecks() as $warning) {
            error_log($warning);
        }

        // opcache + coroutine-legacy advisory — Stage 7 re-execution of
        // require_once'd files clashes with a warm opcache cache for early-bound
        // class declarations ("Cannot redeclare class" on request 2+). Surface
        // the doc-root blacklist recipe at boot. See opcacheLegacyBootCheck().
        if (($opcacheAdvisory = self::opcacheLegacyBootCheck()) !== null) {
            error_log($opcacheAdvisory);
        }

        // #423 — warn when keepGlobals(true) is set but ineffective in
        // coroutine-legacy (the per-coroutine $GLOBALS reset is unconditional).
        if (($keepGlobalsAdvisory = self::keepGlobalsCoroutineLegacyBootCheck()) !== null) {
            error_log($keepGlobalsAdvisory);
        }

        // Capture boot timestamp + resolved worker counts for App::stats().
        self::$bootedAt = time();
        $resolvedWorkers = isset($settings['worker_num']) && is_int($settings['worker_num']) ? $settings['worker_num'] : 0;
        $resolvedTask    = isset($settings['task_worker_num']) && is_int($settings['task_worker_num']) ? $settings['task_worker_num'] : 0;
        self::$worker_num = $resolvedWorkers;
        self::$task_worker_num = $resolvedTask;

        // Master-side signal handlers must register AFTER the server's event
        // loop is up — otherwise `Process::signal()` initializes the loop
        // early and `$server->start()` then fails with "eventLoop has
        // already been created". The actual registration happens inside
        // `$server->on('start', …)` below; here we just mark intent.

        // Reset onProcess wiring flag — wireProcessHandlers actually runs
        // later in run() once $server is built; track here to make the
        // ordering explicit.
        self::$processBootWired = false;

        $cliOverrides = \ZealPHP\CLI::parseCliArgs();
        if (isset($cliOverrides['_host'])) {
            // @phpstan-ignore-next-line — cliOverrides is array<string, mixed>; _host coerced to string at boundary
            $this->host = (string)$cliOverrides['_host'];
            unset($cliOverrides['_host']);
        }
        if (isset($cliOverrides['_port'])) {
            // @phpstan-ignore-next-line — cliOverrides is array<string, mixed>; _port coerced to int at boundary
            $this->port = (int)$cliOverrides['_port'];
            unset($cliOverrides['_port']);
            if (is_array($settings) && isset($settings['pid_file'])) {
                $settings['pid_file'] = preg_replace(
                    '/zealphp_\d+\.pid$/',
                    "zealphp_{$this->port}.pid",
                    // @phpstan-ignore-next-line — settings is array<string, mixed>; pid_file coerced to string at boundary
                    (string)$settings['pid_file']
                );
            }
        }
        if (!empty($cliOverrides)) {
            $settings = array_merge($settings ?? [], $cliOverrides);
        }

        // Resolve the three lifecycle knobs through their fluent setters.
        // Each defaults to "follow App::$superglobals" (null backing → the
        // historical pairing), so callers that never touch the new
        // App::processIsolation() / App::enableCoroutine() / App::hookAll()
        // methods see exactly today's behaviour. Callers that want the
        // "Symfony mixed-mode" combo (superglobals=true + processIsolation=
        // false + enable_coroutine=false + hook_all=0) set the knobs
        // independently before App::run().
        App::$coproc_implicit_request_handler = App::processIsolation();
        $hookFlags = App::hookAll();
        $enableCoroutine = App::enableCoroutine();

        // processIsolation + hookAll: hooked fread/fwrite on CGI subprocess
        // pipes yields the coroutine, but the subprocess is a separate OS
        // process outside the coroutine scheduler → deadlock. Force hooks off
        // for CGI modes; coroutines still work (each request gets a coroutine),
        // just without I/O hooking.
        // processIsolation + enableCoroutine: CGI subprocess dispatch uses
        // blocking pipe I/O incompatible with coroutine scheduling. When
        // sg=true, force both ec=false + hookAll=0 (falls back to sync Mode
        // 5/9). When sg=false, ec=false would be rejected by validation
        // (sg=F+ec=F requires coroutines for per-request $g isolation), so
        // force pi=false instead (falls back to in-process Mode 1).
        if (App::processIsolation() && $enableCoroutine) {
            if (App::$superglobals) {
                elog('[lifecycle] processIsolation + enableCoroutine: forcing ec=false + hookAll=0 '
                    . '— CGI pipe I/O incompatible with coroutines. Workers run synchronously (Mode 5/9).', 'warn');
                $enableCoroutine = false;
                $hookFlags = 0;
                // #424 — write the effective value back so the public getter
                // App::enableCoroutine() reports the downgrade (workers run
                // synchronously, cid=-1) instead of the originally-requested
                // true. Mirrors the sg=false branch flipping $process_isolation.
                App::$enable_coroutine_override = false;
            } else {
                elog('[lifecycle] processIsolation + enableCoroutine(sg=false): forcing pi=false '
                    . '— CGI pipe I/O incompatible with coroutines and sg=false requires ec=true. '
                    . 'Files run in-process (Mode 1).', 'warn');
                App::$process_isolation = false;
                App::$coproc_implicit_request_handler = false;
            }
        }

        // silentRedeclare + enableCoroutine + HOOK_FILE: the compile-yield
        // hazard (gdb-confirmed on the 50-app sweep, phpMyAdmin's Symfony-DI
        // bootstrap). silentRedeclare installs the zend_compile_file hook, which
        // swaps the process-global CG(function_table)/CG(class_table) to scratch
        // for the duration of each compile. HOOK_FILE coroutinizes the source-
        // file read INSIDE that compile — so the coroutine can yield mid-compile
        // while CG is swapped and a zend_try bailout frame is live. That switch
        // corrupts engine state: a worker SIGSEGV under OPcache, a lost-wakeup
        // hang without it. Dropping HOOK_FILE (compile-time file read runs
        // blocking, cannot yield) makes the compile atomic and removes the whole
        // class. Network / socket / sleep hooks stay on, so coroutine concurrency
        // for I/O-bound work is unaffected; only file I/O becomes synchronous —
        // an acceptable trade in legacy mode, and the documented safe shape.
        // Toggling the file wrapper per-compile from the extension was tried and
        // is WORSE (the mid-request enable/disable_hook has side effects), so the
        // fix lives here, at the one place hook flags are frozen. Opt back in
        // with ZEALPHP_ALLOW_COMPILE_HOOK_FILE=1 if you accept the risk.
        if (self::$silent_redeclare && $enableCoroutine
            && ($hookFlags & \OpenSwoole\Runtime::HOOK_FILE)
            && (string) \getenv('ZEALPHP_ALLOW_COMPILE_HOOK_FILE') !== '1'
        ) {
            $hookFlags &= ~\OpenSwoole\Runtime::HOOK_FILE;
            elog('[lifecycle] silentRedeclare + enableCoroutine: dropping HOOK_FILE '
                . '— coroutinizing the compile-time file read while the CG-swap '
                . 'compile hook is active yields mid-compile and corrupts the '
                . 'engine (SIGSEGV/hang). File I/O is synchronous; other hooks stay '
                . 'on. Override with ZEALPHP_ALLOW_COMPILE_HOOK_FILE=1.', 'warn');
        }

        // Surface combinations that are syntactically allowed but race
        // process-wide superglobals against concurrent coroutines / hooked
        // I/O. We warn rather than refuse — see App::hookAll() docblock.
        self::validateLifecycleCombination(App::$superglobals, $hookFlags, $enableCoroutine);

        $this->activateIsolationRuntime($enableCoroutine, $hookFlags);

        // Transparent coroutine-safe exec family. Overriding `shell_exec` ALSO
        // intercepts the backtick operator (`` `cmd` `` compiles to a
        // shell_exec() call), so legacy/user code becomes coroutine-safe with
        // no source changes. Gated by the $hook_exec property / App::hookExec()
        // setter; null resolves to coroutine mode (superglobals===false) here,
        // and a non-null value forces it on/off. proc_open/popen are
        // intentionally NOT overridden — App::rawExec()/cgiSubprocess() rely on
        // proc_open, so routing through it keeps the fallback recursion-safe.
        $hookExec = self::$hook_exec ?? (self::$superglobals === false);
        if ($hookExec) {
            self::overrideBuiltin('shell_exec', '\ZealPHP\zeal_shell_exec');
            self::overrideBuiltin('system',     '\ZealPHP\zeal_system');
            self::overrideBuiltin('passthru',   '\ZealPHP\zeal_passthru');
            self::overrideBuiltin('exec',       '\ZealPHP\zeal_exec');
            // proc_open intentionally NOT overridden — App::rawExec()/cgiSubprocess() rely on it.
        }

        // exit()/die() → ZealPHP\HaltException under coroutines (ext#47). A
        // userland exit inside a coroutine otherwise throws OpenSwoole's
        // ExitException (extends \Exception), which legacy routers'
        // `try { … exit; } catch (\Exception)` swallow → 500. HaltException
        // (extends \Error) dodges those catch-blocks and the framework's
        // halt-aware sites flush the buffered output as the body. Default
        // follows the coroutine scheduler; force via App::hookExit(); env
        // kill-switch ZEALPHP_EXIT_HOOK_DISABLE. The class MUST be loaded
        // before the hook fires — the ext deliberately does NOT autoload
        // from an exit site — so warm it here, pre-fork.
        $hookExit = self::$hook_exit ?? $enableCoroutine;
        if ($hookExit
            && !env_flag('ZEALPHP_EXIT_HOOK_DISABLE', false)
            && \function_exists('zealphp_exit_hook')
        ) {
            \class_exists(\ZealPHP\HaltException::class);   // force autoload, pre-fork
            (\zealphp_exit_hook(...))(true);
        }

        if ($hookFlags !== 0) {
            co::set(['hook_flags' => $hookFlags]);
            // Two-arg form (enable, flags). Single-arg with an int as $enable
            // also works at runtime — PHP truthiness coerces non-zero int to
            // true and OpenSwoole's C side reads the int as the flag bitmask
            // — but the IDE stub declares the first arg as strict bool, so
            // PHPStan flags it. Two-arg form is the canonical OpenSwoole API
            // and matches every stub version.
            \OpenSwoole\Runtime::enableCoroutine(true, $hookFlags);
        }
        // Use the same path resolution as the stop/status CLI commands so that
        // `php app.php stop` finds the PID file the server just wrote. Without
        // this, the server writes /tmp/zealphp_PORT.pid (flat) but stop looks
        // under /tmp/zealphp/zealphp_PORT.pid (subdir) — they disagree.
        $defaultPidFile = \ZealPHP\CLI::resolvePidFile(['port' => $this->port]);
        $default_settings = [
            'enable_static_handler' => true,
            'document_root' => self::resolveDocumentRoot(),
            // Restrict OpenSwoole's built-in static handler to the listed URL prefixes
            // (Apache equivalent: serving only safe subtrees). Leave empty to serve all
            // — including dotfiles — like Apache default. Default whitelist below is
            // safe for typical web apps; override via $app->run(['static_handler_locations' => [...]]).
            //
            // IMPORTANT: directory entries MUST end with `/`. OpenSwoole does raw
            // string-prefix matching, so a bare `/js` entry silently intercepts
            // user routes like `/json` (a real bug we shipped in 0.2.x — found
            // when /json on the docs site returned OpenSwoole's default 404
            // instead of routing into the framework). Trailing slash forces
            // segment-boundary matching.
            //
            // #367 — FILE entries (no trailing slash) can't use that workaround:
            // OpenSwoole has no exact-match mode, so a bare `/favicon.ico` /
            // `/robots.txt` prefix-matches `/favicon.icoX` / `/robots.txtABC`
            // and steals a user route like `/robots.txt-generator`. So the
            // default list ships DIRECTORY entries only (all segment-bounded);
            // `favicon.ico` + `robots.txt` are served as ordinary public/ files
            // by the framework's implicit file routes. A user who wants the
            // native static handler for them can still add them via
            // App::staticHandlerLocations() (accepting the documented
            // prefix-match caveat).
            //
            // KNOWN OpenSwoole native-handler RFC divergences (#360, upstream
            // openswoole/ext-openswoole#392 + #393 — confirmed pure-OpenSwoole,
            // NOT a ZealPHP bug; the C handler answers before PHP runs, so the
            // framework cannot guard them from PHP without disabling the handler):
            //   1. `%00` path truncation — `GET /css/site.css%00.png` serves
            //      `/css/site.css` (200) instead of 400. Stays inside the doc
            //      root (no traversal), but defeats suffix/extension decisions.
            //   2. HEAD returns the full body — RFC 9110 §9.3.2 requires no body
            //      on HEAD; the native handler ships every byte.
            // For security-sensitive assets, route them through the PHP
            // `Response::sendFile()` path instead (it 400s `%00`, strips HEAD
            // bodies per #358, and computes a proper conditional/range surface),
            // or scope `static_handler_locations` to non-sensitive subtrees.
            'static_handler_locations' => self::$static_handler_locations !== []
                ? self::$static_handler_locations
                : self::defaultStaticHandlerLocations(),
            'enable_coroutine' => $enableCoroutine,
            'hook_flags' => $hookFlags,
            // Worker count default — cgroup-quota-aware so a CPU-limited
            // container does NOT inherit OpenSwoole's host-core-count default
            // (which spawns one full PHP arena per host core → OOM on a 4-vCPU
            // pod running on a 48-core node). Overridable via ZEALPHP_WORKERS
            // env / -w CLI / $app->run(['worker_num' => N]); the passed value
            // wins because $settings is array_merge'd OVER $default_settings.
            'worker_num' => \ZealPHP\default_worker_count(4),
            // Per-worker coroutine ceiling — front-door backpressure. Without
            // it a burst spawns up to OpenSwoole's ~100k coroutines/worker and
            // there is no shed path, so the first bounded downstream resource
            // (Redis pool, DB, memory) fails as a cliff. See DEFAULT_MAX_COROUTINE
            // for the rationale + how to raise it for streaming/WS workloads.
            'max_coroutine' => (int)(getenv('ZEALPHP_MAX_COROUTINE') ?: self::DEFAULT_MAX_COROUTINE),
            // Runtime compression is owned by OpenSwoole. Do not also register
            // CompressionMiddleware unless this setting is disabled.
            'http_compression' => true,
            // #305 — ZealPHP owns cookie parsing (mod_php parity). OpenSwoole's
            // built-in cookie parser diverges from PHP's treat-data (no array
            // syntax, no `.`→`_` name-mangling, wrong `+`→space value decode), so
            // we disable it: OpenSwoole then leaves the raw `Cookie:` header in
            // $request->header['cookie'] and App::requestCookieMap() parses it
            // PHP-canonically (writing the result back onto $request->cookie so
            // every consumer agrees). Override only if you intentionally want
            // OpenSwoole's parser back AND nothing relies on $_COOKIE parity.
            'http_parse_cookie' => false,
            'pid_file' => $defaultPidFile,
            // Worker recycling — bounds memory growth from leaks accumulated
            // in long-running workers (static caches, closure captures, leaky
            // extensions). After this many requests a worker exits cleanly and
            // is respawned with a fresh PHP arena. Set 0 to disable. Override
            // via ZEALPHP_MAX_REQUEST env var or $app->run(['max_request' => N]).
            'max_request' => (int)(getenv('ZEALPHP_MAX_REQUEST') ?: 100000),
            'task_worker_num' => 0,
            'task_enable_coroutine' => true,
            // Suppress NOTICE-level messages from OpenSwoole internals (e.g. ERRNO 1005
            // "session does not exist" when SSE/WS clients disconnect mid-stream).
            // Pass 'log_level' => 0 in $app->run() settings to restore full debug output.
            'log_level' => 4,  // 0=DEBUG 1=TRACE 2=INFO 3=NOTICE 4=WARNING 5=ERROR 6=NONE
            // Apache LimitRequestFieldSize / LimitRequestLine parity:
            // App::$limit_request_field_size / $limit_request_line are kept as
            // ADVISORY properties — OpenSwoole 22.x does not expose a public
            // server option matching Apache's per-header byte limit. The
            // earlier attempt to publish them as 'http_header_buffer_size'
            // was rejected by OpenSwoole's option validator at boot
            // (server option not recognised). If you need a hard cap, run
            // ZealPHP behind a front proxy (Caddy/nginx) that enforces it.
        ];
        // @phpstan-ignore-next-line — settings is array<string, mixed>; pid_file coerced to string at boundary
        $pidFile = (string)($settings['pid_file'] ?? $default_settings['pid_file']);
        if (file_exists($pidFile)) {
            $existingPid = (int)trim((string)file_get_contents($pidFile));
            // processIsZealphp() guards against recycled PIDs falsely
            // reporting "already running" when the original daemon is gone.
            if ($existingPid > 0 && @posix_kill($existingPid, 0) && \ZealPHP\CLI::processIsZealphp($existingPid)) {
                echo "ZealPHP is already running (pid {$existingPid}, port {$this->port})\n";
                echo "Use 'php app.php stop' to stop, or 'php app.php restart' to restart\n";
                exit(0);
            }
            @unlink($pidFile);
        }
        // Catch the orphan case the pid-file check above can't see: pid file
        // missing or stale, but a previous daemon is still bound to the port.
        // Without this, OpenSwoole's bind would fail silently and the user
        // would see "could not confirm" with no actionable explanation.
        \ZealPHP\CLI::claimOrphanIfAny($this->port);

        self::$server = $server = new \OpenSwoole\WebSocket\Server($this->host, $this->port);
        if ($settings == null){
            $effective_settings = $default_settings;
        } else {
            $effective_settings = array_merge($default_settings, $settings);
            // Re-assert the resolved enable_coroutine value AFTER user
            // settings merge — otherwise a stray `enable_coroutine` key in
            // the user-passed settings array would silently override the
            // App::enableCoroutine() decision and the lifecycle warnings
            // would be a lie.
            $effective_settings['enable_coroutine'] = $enableCoroutine;
        }
        $server->set($effective_settings);

        // Backpressure advisory — warn if the operator raised max_coroutine back
        // into OpenSwoole's effectively-unbounded range (removing the front-door
        // shed path). Logged once at boot, visible in master logs before fork.
        if (($bpAdvisory = self::backpressureBootAdvisory($effective_settings)) !== null) {
            error_log($bpAdvisory);
        }

        // Deterministic session GC on a worker-0 timer — ZealPHP replaced PHP's
        // probabilistic per-request GC, so without this sess_* files / handler
        // entries accumulate forever and leaked PHPSESSIDs never expire.
        self::registerSessionGc();

        // #295 — resolve the configured session handler ONCE in the master, BEFORE
        // the worker fork. Memoises the instance (every worker shares it) and,
        // critically, lets a configured TableSessionHandler allocate its
        // OpenSwoole\Table here so it is shared-memory across workers rather than a
        // per-worker table created lazily on first request. No-op when unconfigured
        // (null) — the file default is preserved.
        self::resolveActiveSessionHandler();

        # Snapshot the app.php-defined baseline (explicit routes + the alias /
        # App::when registries) BEFORE the file-based + implicit routes load, so
        # App::reloadRoutes() can restore it and rebuild from route/*.php without
        # restarting the worker.
        $baselineExplicitRoutes = $this->routes;
        $baselineWhen           = self::$when_middleware;
        $baselineAliases        = self::$middleware_aliases;
        $baselineBackendAliases = self::$cgi_backend_aliases;

        # Include all files in route directory and its sub directories
        $this->includeRouteFiles();
        $baselineFileEndCount = count($this->routes);

        $this->registerImplicitRoutes();

        // Complete the reload baseline now that the implicit routes are
        // registered: keep them as data (closures survive) so reloadRoutes()
        // re-appends them after the re-included route files, in priority order.
        $this->route_baseline = [
            'routes'          => $baselineExplicitRoutes,
            'implicit'        => array_slice($this->routes, $baselineFileEndCount),
            'when'            => $baselineWhen,
            'aliases'         => $baselineAliases,
            'backend_aliases' => $baselineBackendAliases,
        ];

        $this->registerTaskHandlers($server, $effective_settings);

        // When coroutines are enabled, always use CoSessionManager — it uses
        // per-coroutine RequestContext and overridden zeal_session_start(),
        // both coroutine-safe. SessionManager uses PHP's native session_start()
        // + session_set_save_handler() which race under concurrent coroutines.
        // sg(true)+ec(true) with ext-zealphp needs CoSessionManager (#134).
        $SessionManager = ($enableCoroutine || !self::$superglobals)
            ? 'ZealPHP\Session\CoSessionManager'
            : 'ZealPHP\Session\SessionManager';

        self::buildMiddlewareStack();

        $this->registerOnRequest($server, $SessionManager);

        // Resolve per-route + App::when middleware (alias → instance) and build
        // the method-indexed dispatch table. Extracted so App::reloadRoutes()
        // can rebuild it; at boot this runs exactly once.
        $this->compileRouteTable();

        $this->registerWorkerStart($server);

        $this->registerWorkerStop($server);

        $this->registerWebSocketHandlers($server);

        // Wire registered sidecar processes via $server->addProcess() so they
        // share fate with the server (managed by master; respawn on reload).
        // Must run BEFORE $server->start() — after start(), addProcess silently
        // no-ops because the master is already in its event loop.
        self::$server = $server;
        self::wireProcessHandlers();

        // Master-side on-start callback. Fires in the master once the server is
        // bound + listening. Two jobs:
        //   1. Foreground (no -d): print a console banner so a plain
        //      `php app.php` confirms it started + where to reach it. The
        //      daemon path (-d) stays silent here — its "Started (pid …)"
        //      confirmation is printed by the forked CLI parent (src/CLI.php),
        //      and a daemonized master's stdout is detached from the terminal.
        //   2. Wire master signal handlers — the event loop is alive at this
        //      point, so Process::signal() doesn't pre-initialize it.
        $foreground   = empty($effective_settings['daemonize']) && PHP_SAPI === 'cli';
        $haveSignals  = self::$signalHandlers !== [];
        if ($foreground || $haveSignals) {
            $displayHost   = in_array($this->host, ['0.0.0.0', '', '::'], true) ? 'localhost' : $this->host;
            $bannerPort    = $this->port;
            $bannerRoutes  = count($this->routes);
            $bannerWorkersRaw = $effective_settings['worker_num'] ?? 0;
            $bannerWorkers    = is_numeric($bannerWorkersRaw) ? (int) $bannerWorkersRaw : 0;
            $server->on('start', function () use (
                $foreground, $haveSignals, $displayHost, $bannerPort, $bannerRoutes, $bannerWorkers
            ) {
                if ($foreground) {
                    fwrite(STDOUT, sprintf(
                        "ZealPHP running at http://%s:%d  (%d routes%s)  —  press Ctrl+C to stop\n",
                        $displayHost,
                        $bannerPort,
                        $bannerRoutes,
                        $bannerWorkers > 0 ? ", {$bannerWorkers} workers" : ''
                    ));
                }
                if ($haveSignals) {
                    self::applySignalHandlersFor('master');
                }
            });
        }

        // Master-side request-path preload (coroutine modes): warm the framework
        // Request/Response/PSR stack HERE, in the master before fork, NOT only in
        // the onWorkerStart hook below. onWorkerStart runs in a coroutine, and
        // under HOOK_ALL loading these classes triggers a file include that does
        // I/O and YIELDS — letting the worker accept a request mid-warmup → cold
        // concurrent compile → duplicate class entry → the OnRequest closure throws
        // "Argument #1 ($request) must be of type ZealPHP\HTTP\Request,
        // ZealPHP\HTTP\Request given" (a different CE), killing the worker in a
        // respawn cascade (reproduced under unmodified WordPress + `ab -c20`). The
        // master has no scheduler, so every load here is blocking + atomic; workers
        // inherit the linked classes via copy-on-write fork and the onWorkerStart
        // warm below becomes a no-op (class_exists on an already-defined class does
        // not autoload, so it cannot yield). Gated to coroutine modes — sequential
        // worker modes have no cold-concurrent wave to race.
        if ($enableCoroutine) {
            self::preloadRequestPathClasses();
        }

        // Master-side bulk class preload (opt-in via preloadClassmap()/
        // preloadDir()): warm large/arbitrary class sets HERE — in the master,
        // before fork, where no coroutine scheduler exists — so a class with
        // load-time I/O can't yield and let a worker accept requests mid-warmup
        // (which reintroduces the cold-compile race). Workers inherit the linked
        // classes via copy-on-write fork.
        self::warmBulkPreloads();

        elog("ZealPHP server running at http://{$this->host}:{$this->port} with ".count($this->routes)." routes");
        $server->start();
    }

    /**
     * Activate the ext-zealphp per-coroutine isolation runtime stack
     * (superglobals / define / $GLOBALS / silent-redeclare / function-static /
     * include isolation) and register the matching onWorkerStart hooks.
     *
     * Extracted verbatim from App::run() (Phase 3 decomposition) — must run at
     * the same point and read/write the same static state. Reads the two
     * resolved lifecycle locals (enableCoroutine, hookFlags) passed in.
     */
    private function activateIsolationRuntime(bool $enableCoroutine, int $hookFlags): void
    {
        // Activate per-coroutine superglobal isolation when ext-zealphp is
        // loaded AND superglobals mode is on with coroutines. ext-zealphp
        // v0.3.2+ chains its callbacks with OpenSwoole's PHPCoroutine hooks
        // (EG/CG context switch) so PHP state remains consistent.
        // @codeCoverageIgnoreStart — requires running OpenSwoole server
        if (App::$superglobals && $enableCoroutine
            && \extension_loaded('zealphp')
            && \function_exists('zealphp_coroutine_superglobals')
        ) {
            (\zealphp_coroutine_superglobals(...))((bool) true);
            self::$coroutine_isolated_superglobals = true;

            // Per-coroutine putenv/getenv: capture the boot environment with the
            // REAL getenv (before overriding to avoid recursion), then route
            // putenv/getenv through the request-scoped $g store so concurrent
            // requests no longer race the process environment. See
            // \ZealPHP\putenv / \ZealPHP\getenv.
            self::$boot_env = \getenv();
            self::overrideBuiltin('putenv', '\ZealPHP\zeal_putenv');
            self::overrideBuiltin('getenv', '\ZealPHP\zeal_getenv');
        }

        // Per-request define() isolation — opt-in via App::defineIsolation(true).
        // When enabled, ext-zealphp's define_hook intercepts define() calls,
        // tracks request-scoped constants, and constants_clear() removes them
        // at request end. Per-coroutine snapshot/restore on yield/resume
        // handles concurrent coroutines. Boot-time constants survive.
        // NOT auto-activated: require_once apps break (see docblock).
        if (self::$define_isolation
            && \extension_loaded('zealphp')
            && \function_exists('zealphp_define_hook')
        ) {
            (\zealphp_define_hook(...))((bool) true);

            // Coupling guard (50-app sweep, phpMyAdmin `AUTOLOAD_FILE` 500):
            // define-isolation clears request-scoped constants at request end.
            // A constant defined at the TOP LEVEL of the per-request entry
            // script re-defines fine next request (the entry runs every
            // request). But a constant defined INSIDE a require_once'd file
            // (phpMyAdmin's `libraries/constants.php` → AUTOLOAD_FILE /
            // ROOT_PATH-derived constants) only re-defines if Stage 7
            // (includeIsolation) re-executes that file — otherwise the
            // require_once is a no-op on request 2+ and the constant is gone
            // → "Undefined constant" 500. Clearing constants is only sound
            // when the files that define them re-execute. coroutine-legacy
            // turns both on together; warn loudly for the hand-rolled combo.
            if (!self::$include_isolation) {
                elog(
                    "[warn] defineIsolation(true) without includeIsolation(true): "
                    . "constants defined inside require_once'd files are CLEARED "
                    . "each request but their definer files won't re-execute, so "
                    . "they vanish on request 2+ (Undefined-constant 500). Enable "
                    . "App::includeIsolation(true) (App::mode('coroutine-legacy') "
                    . "does this for you).",
                    'warn'
                );
            }
        }

        // Env-var rollback: ZEALPHP_GLOBALS_ISOLATION_DISABLE=1 disables
        // the Stage 2 COW path even when App::coroutineGlobalsIsolation(true)
        // was called. Use this as an emergency rollback if a Stage 2 edge
        // case surfaces in production — the framework falls back to the
        // pre-v0.3.6 behaviour ($GLOBALS shared process-wide). See the
        // migration ladder in docs/architecture/application-server-sapi.md.
        $isolationDisabledViaEnv = (string) \getenv('ZEALPHP_GLOBALS_ISOLATION_DISABLE') === '1';
        if (self::$coroutine_globals_isolation
            && !$isolationDisabledViaEnv
            && \extension_loaded('zealphp')
            && \function_exists('zealphp_coroutine_globals')
        ) {
            (\zealphp_coroutine_globals(...))((bool) true);
            self::coroutineGlobalsMemoryAdvisory();
        } elseif (self::$coroutine_globals_isolation && $isolationDisabledViaEnv) {
            elog(
                "[warn] coroutineGlobalsIsolation(true) called but disabled "
                . "via ZEALPHP_GLOBALS_ISOLATION_DISABLE=1 env var. \$GLOBALS "
                . "is shared process-wide across coroutines.",
                'warn'
            );
        }

        // Stage 3: silent-redeclare opcode hooks. Closes the dominant Mode
        // 3/4/5 failure: conditional `function foo() {}` / `class Bar {}` in
        // legacy code re-running on each request and tripping
        // E_COMPILE_ERROR ("Cannot redeclare …"). With this on, the second
        // declare is a silent no-op (first wins — matches FPM's fresh-proc
        // semantic). Requires ext-zealphp 0.3.8+.
        if (self::$silent_redeclare
            && \extension_loaded('zealphp')
            && \function_exists('zealphp_silent_redeclare')
        ) {
            (\zealphp_silent_redeclare(...))((bool) true);
        }

        // Stage 5: per-coroutine function-local static isolation. Default-ON
        // in coroutine-legacy (the mode() preset enables it; env opt-out
        // ZEALPHP_FN_STATICS_DISABLE=1). A ZEND_BIND_STATIC touched-set registry
        // keeps the per-yield snapshot proportional to static-USING functions,
        // not total functions — so cost is decoupled from declared-function
        // count. Activated before fork so the flag + scheduler hooks are
        // inherited by workers. Closes the last request-state primitive that
        // leaked across coroutines. See the $coroutine_statics_isolation docblock.
        if (self::$coroutine_statics_isolation
            && \extension_loaded('zealphp')
            && \function_exists('zealphp_coroutine_statics')
        ) {
            (\zealphp_coroutine_statics(...))((bool) true);
        } elseif (self::$coroutine_statics_isolation) {
            elog(
                "[warn] coroutineStaticsIsolation(true) requires ext-zealphp "
                . "0.3.9+ with zealphp_coroutine_statics — function-local "
                . "static \$x will NOT be isolated per coroutine.",
                'warn'
            );
        }

        // #323: per-coroutine CWD isolation. Asserted here (master, pre-fork)
        // so the ext captures the worker BASELINE cwd while the process still
        // sits at the app root — workers inherit both the installed scheduler
        // hooks and the baseline via fork. After this, a request's chdir()
        // (incl. executeFile()'s chdir-to-script-dir) is saved/restored per
        // coroutine instead of leaking process-wide.
        if (self::$coroutine_cwd_isolation
            && \extension_loaded('zealphp')
            && \function_exists('zealphp_cwd_isolation')
        ) {
            (\zealphp_cwd_isolation(...))((bool) true);
        } elseif (self::$coroutine_cwd_isolation) {
            elog(
                "[warn] coroutineCwdIsolation(true) requires ext-zealphp "
                . "0.3.35+ with zealphp_cwd_isolation — a request's chdir() "
                . "will leak across concurrently-running coroutines (#323).",
                'warn'
            );
        }

        // setlocale()/umask() — the same chdir-class process-global state,
        // asserted pre-fork for the same baseline reason: a boot-time
        // setlocale()/umask() before run() becomes the worker baseline.
        if (self::$coroutine_locale_isolation
            && \extension_loaded('zealphp')
            && \function_exists('zealphp_locale_isolation')
        ) {
            (\zealphp_locale_isolation(...))((bool) true);
        } elseif (self::$coroutine_locale_isolation) {
            elog(
                "[warn] coroutineLocaleIsolation(true) requires ext-zealphp "
                . "0.3.38+ with zealphp_locale_isolation — a request's "
                . "setlocale() will leak across concurrently-running coroutines.",
                'warn'
            );
        }
        if (self::$coroutine_umask_isolation
            && \extension_loaded('zealphp')
            && \function_exists('zealphp_umask_isolation')
        ) {
            (\zealphp_umask_isolation(...))((bool) true);
        } elseif (self::$coroutine_umask_isolation) {
            elog(
                "[warn] coroutineUmaskIsolation(true) requires ext-zealphp "
                . "0.3.38+ with zealphp_umask_isolation — a request's umask() "
                . "will leak across concurrently-running coroutines.",
                'warn'
            );
        }

        // Function-backed process-global settings (ext-zealphp 0.3.45+):
        // default timezone, mb internal encoding, libxml error flag. Same
        // pre-fork baseline rule: a boot-time date_default_timezone_set() /
        // mb_internal_encoding() before run() becomes the worker baseline.
        if (self::$coroutine_tz_isolation
            && \extension_loaded('zealphp')
            && \function_exists('zealphp_timezone_isolation')
        ) {
            (\zealphp_timezone_isolation(...))((bool) true);
        } elseif (self::$coroutine_tz_isolation) {
            elog(
                "[warn] coroutineTimezoneIsolation(true) requires ext-zealphp "
                . "0.3.45+ with zealphp_timezone_isolation — a request's "
                . "date_default_timezone_set() will leak across "
                . "concurrently-running coroutines.",
                'warn'
            );
        }
        if (self::$coroutine_mbenc_isolation
            && \extension_loaded('zealphp')
            && \function_exists('zealphp_mbenc_isolation')
        ) {
            (\zealphp_mbenc_isolation(...))((bool) true);
        } elseif (self::$coroutine_mbenc_isolation) {
            elog(
                "[warn] coroutineMbencIsolation(true) requires ext-zealphp "
                . "0.3.45+ with zealphp_mbenc_isolation — a request's "
                . "mb_internal_encoding() will leak across "
                . "concurrently-running coroutines.",
                'warn'
            );
        }
        if (self::$coroutine_libxml_isolation
            && \extension_loaded('zealphp')
            && \function_exists('zealphp_libxml_isolation')
        ) {
            (\zealphp_libxml_isolation(...))((bool) true);
        } elseif (self::$coroutine_libxml_isolation) {
            elog(
                "[warn] coroutineLibxmlIsolation(true) requires ext-zealphp "
                . "0.3.45+ with zealphp_libxml_isolation — a request's "
                . "libxml_use_internal_errors() will leak across "
                . "concurrently-running coroutines.",
                'warn'
            );
        }

        // Stage 7: smart require_once via opcode hook. Enable BEFORE the
        // snapshot so the handler is active when workers start processing.
        // The opcode handler converts require_once → require for files NOT
        // in the snapshot — zero per-request cleanup needed.
        if (self::$include_isolation
            && \extension_loaded('zealphp')
            && \function_exists('zealphp_include_isolation')
        ) {
            (\zealphp_include_isolation(...))((bool) true);
        }

        if ((self::$function_isolation || self::$include_isolation)
            && \extension_loaded('zealphp')
            && \function_exists('zealphp_process_state_snapshot')
        ) {
            self::onWorkerStart(function () {
                if (\class_exists(\ZealPHP\Counter::class, false)) {
                    \ZealPHP\Counter::defaultBackend();
                }
                (\zealphp_process_state_snapshot(...))();
                if (self::$function_isolation && \function_exists('zealphp_globals_snapshot')) {
                    (\zealphp_globals_snapshot(...))();
                }
            });
        }

        // Coroutine $GLOBALS isolation — re-capture the per-coroutine parent
        // baseline AFTER the user's onWorkerStart bootstrap has run, so
        // bootstrap-time globals (WordPress $wp/$wpdb, Drupal $databases, etc.)
        // become part of the baseline every request-coroutine inherits — instead
        // of being reset out of existence (the "wp() / $wp is null" class of bug).
        //
        // run() registers this hook AFTER the user's app.php onWorkerStart calls,
        // and $workerStartHooks fires FIFO, so this runs last — once the app's
        // globals are in place. The (false)+(true) toggle clears the early
        // pre-fork baseline (captured at the gate above, before any bootstrap)
        // and re-snapshots the now-bootstrapped $GLOBALS. Deltas are empty at
        // worker start (no requests yet), so the clear is a no-op.
        if (self::$coroutine_globals_isolation
            && !$isolationDisabledViaEnv
            && \extension_loaded('zealphp')
            && \function_exists('zealphp_coroutine_globals')
        ) {
            self::onWorkerStart(function () {
                (\zealphp_coroutine_globals(...))((bool) false);
                (\zealphp_coroutine_globals(...))((bool) true);
            });
        }

        if (self::$function_isolation
            && \extension_loaded('zealphp')
            && \function_exists('zealphp_process_state_snapshot')
        ) {
            self::onWorkerStart(function () {
                if (\class_exists(\ZealPHP\Counter::class, false)) {
                    \ZealPHP\Counter::defaultBackend();
                }
                (\zealphp_process_state_snapshot(...))();
            });
        }

        // Warm the per-request framework classes the OnRequest closure +
        // CoSessionManager instantiate (the Request / Response wrappers and
        // LazyServerRequest). They are otherwise cold until the first request,
        // so the first CONCURRENT request wave autoloads them under coroutine
        // overlap — racing the loader to a transient "class not found" Fatal in
        // the onRequest coroutine that sends NO response and hangs the client
        // (observed: ~30% of a 40-wide cold burst hang for the full request
        // timeout). Worker start is single-coroutine, so warming them here is
        // race-free; every request coroutine then finds them already defined.
        // The autoload serializer below remains the safety net for app/user
        // classes; the framework's own hot-path classes are warmed up front so
        // they never reach it.
        if ($enableCoroutine) {
            self::onWorkerStart(function () {
                self::preloadRequestPathClasses();
            });
        }

        // HAZARD-2 correctness fix: serialize concurrent same-class autoload.
        // Under silentRedeclare the ext isolates EG(in_autoload) per coroutine,
        // so concurrent coroutines can each compile the same not-yet-loaded
        // class; the first-wins merge orphans the loser CE, a loser-CE object
        // escapes to `new`, and the type-hint (cached to the winner CE) throws
        // "must be of type X, X given" → fatal → worker death → respawn cascade.
        // The serializer gates autoload per class name so exactly one coroutine
        // compiles each class. Registered LAST so it wraps the final autoloader
        // set (after any app/bootstrap onWorkerStart autoloader registration).
        if (self::$silent_redeclare && $enableCoroutine) {
            self::onWorkerStart(function () {
                self::installCoroutineAutoloadSerializer();
            });
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Register the implicit framework routes (api dispatch, .php-ext block,
     * dotfile block, index, CGI extension/ScriptAlias URL parity, and the
     * public file/directory catch-alls).
     *
     * Extracted verbatim from App::run() (Phase 3). Registers in the SAME order
     * via $this->route()/nsPathRoute()/patternRoute(), so the route-priority
     * ordering — and the route_baseline array_slice() that follows in run() —
     * are unchanged.
     */
    private function registerImplicitRoutes(): void
    {
        # Implicit route for including APIs.
        # The two-segment route is registered FIRST so that /api/users/list
        # matches with module=users, request=list (a single segment passing
        # the security regex), instead of being captured by the one-segment
        # catch-all as request="users/list" — which contains a slash and
        # would fail validation with a misleading "invalid_request" error.
        $this->nsPathRoute('api', "{module}/{rquest}", [
            'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH']
        ], function(string $module, string $rquest, $response, $request){
            $api = new ZealAPI($request, $response, self::$cwd);
            try {
                return $api->processApi($module, $rquest);
            } catch (\Exception $e){
                $api->die($e);
            }
        });

        $this->nsPathRoute('api', "{rquest}", [
            'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH']
        ], function(string $rquest, $response, $request){
            $api = new ZealAPI($request, $response, self::$cwd);
            try {
                return $api->processApi("", $rquest);
            } catch (\Exception $e){
                $api->die($e);
            }
        });

        # Implicit route for ignoring PHP extensions

        if(App::$ignore_php_ext){
            $this->patternRoute('/.*\.php', ['methods' => ['GET', 'POST']], function($response) {
                $app = App::instance();
                assert($app !== null);
                // Apache parity (#25): a `.php` file that exists on disk but is
                // blocked from direct access is 403 Forbidden; a `.php` URL with
                // no backing file is 404 Not Found — "doesn't exist" must not
                // masquerade as "no permission".
                $g = \ZealPHP\RequestContext::instance();
                $reqPath = parse_url((string)($g->server['REQUEST_URI'] ?? ''), PHP_URL_PATH);
                $reqPath = is_string($reqPath) ? rawurldecode($reqPath) : '';
                $docRoot = App::resolveDocumentRoot();
                $abs = realpath($docRoot . '/' . ltrim($reqPath, '/'));
                $exists = $abs !== false && is_file($abs) && str_starts_with($abs, $docRoot . '/');
                return $app->renderError($exists ? 403 : 404);
            });
        }

        # Block URLs targeting dotfile segments (.git/, .env, .htaccess, …).
        # `.well-known/` is allowed — it's a registered convention (RFC 8615).
        # #368 — match a dot-segment in ANY position (final `.env` OR a
        # dot-directory `.git/config`), so both return a uniform 403 instead of
        # 403-vs-404 (the old `[^/]*$`-anchored pattern only caught final-segment
        # dotfiles, leaking which kind of dotpath was requested). The lookahead
        # exempts ONLY the exact `.well-known` segment (`(?:/|$)` boundary), so a
        # decoy `.well-knownx` stays blocked.
        if (App::$block_dotfiles) {
            $this->patternRoute('#(^|/)\.(?!well-known(?:/|$))[^/]*(/|$)#', [
                'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH', 'HEAD']
            ], function($response) {
                $app = App::instance();
                assert($app !== null);
                return $app->renderError(403);
            });
        }
        // $this->patternRoute('/.*\.php', ['methods' => ['GET', 'POST']], function($response) {
        //     echo("<pre>403 Forbidden</pre>");
        //     return(403);
        // });

        # Implicit route for index.php

        $this->route('/',[
            'methods' => ['GET', 'POST']
        ], function($response){
            $docRoot = self::resolveDocumentRoot();
            if (file_exists($docRoot . '/index.php')) {
                // App::include() owns includeCheck() + the $_SERVER preamble.
                return App::include('/index.php');
            }
            return $this->invokeFallbackOrNotFound();
        });

        # Implicit URL parity for registered CGI extensions (e.g. .py, .pl, .cgi).
        # GET /cgi-bin/report.py → App::include('/cgi-bin/report.py'). App::include()
        # applies the ExecCGI gate, so a registered-extension URL OUTSIDE an exec
        # scope returns 403 (no source leak) — no extra guard needed here. The
        # method shape matches the .php implicit routes below (GET/POST).
        #
        # These MUST be registered BEFORE the generic /{file} and /{dir}/{uri}
        # routes: those carry an OPTIONAL `(\.php)?` suffix, so their {uri} param
        # would otherwise greedily capture `hello.py` and try to serve a
        # `hello.py.php` file (404) — stealing the request from the CGI backend.
        # Earlier registration = higher priority, so the extension-specific route
        # wins for `.py`/`.pl`/etc. while everything else still falls through.
        foreach (array_keys(self::$cgi_backends) as $ext) {
            if ($ext === '.php') { continue; }
            $e = preg_quote(ltrim($ext, '.'), '#');
            $this->route('/{cgifile}\.' . $e . '/?', [
                'methods' => ['GET', 'POST']
            ], function (string $cgifile) use ($ext) {
                return App::include('/' . $cgifile . $ext);
            });
            $this->nsPathRoute('{cgidir}', '{cgiuri}\.' . $e . '/?', [
                'methods' => ['GET', 'POST']
            ], function (string $cgidir, string $cgiuri) use ($ext) {
                return App::include('/' . $cgidir . '/' . $cgiuri . $ext);
            });
        }

        # ScriptAlias prefixes (App::cgiScriptAlias) — implicit URL routing for any
        # file under the prefix regardless of extension (Apache `ScriptAlias` parity).
        # Mirrors the per-extension loop above but matches by URL PREFIX. Without
        # this, a ScriptAlias-only setup (no per-extension backend) was reachable
        # via App::include() but had no automatic URL route — the follow-up
        # documented in .claude/CLAUDE.md:380. App::include() applies the ExecCGI
        # gate via resolveCgiBackend(), so this just wires the URL → file path.
        foreach (array_keys(self::$cgi_script_aliases) as $prefix) {
            if ($prefix === '' || $prefix === '/') { continue; }
            $aliasRegex = '#^' . preg_quote($prefix, '#') . '/(?P<rest>.+?)/?$#';
            $this->patternRoute($aliasRegex, ['methods' => ['GET', 'POST']], function (string $rest) use ($prefix) {
                return App::include($prefix . '/' . $rest);
            });
        }

        # Global route for all files in the root of the public directory
        $this->route(App::$ignore_php_ext ? '/{file}/?' : '/{file}(\.php)?/?', [
            'methods' => ['GET', 'POST']
        ], function(string $file, $response){
            # if file ends with .php remove it
            if (substr($file, -4) == '.php') {
                $file = substr($file, 0, -4);
            }
            $docRoot  = self::resolveDocumentRoot();
            $abs_file = realpath($docRoot . '/' . $file . '.php');
            if ($abs_file !== false && file_exists($abs_file)) {
                return App::include('/' . $file . '.php');
            } else if (is_dir($docRoot . '/' . $file)) {
                $result = $this->serveDirectory($file, $file);
                if ($result === false) {
                    return $this->invokeFallbackOrNotFound();
                }
                return $result;
            }
            // Apache parity: a path component that is a file rather than a
            // directory (ENOTDIR) is 403, not 404 — deny rather than leak.
            if (self::isEnotdir($docRoot . '/' . $file)) {
                return 403;
            }
            return $this->invokeFallbackOrNotFound();
        });

        # Global route for all directories and sub directories in the public directory
        $this->nsPathRoute('{dir}', App::$ignore_php_ext ? '{uri}/?' : '{uri}(\.php)?/?', [
            'methods' => ['GET', 'POST']
        ], function(string $dir, string $uri, $response){
            elog("Directory: $dir, URI: $uri");
            # if uri ends with .php remove it
            if (substr($uri, -4) == '.php') {
                $uri = substr($uri, 0, -4);
            }
            $docRoot  = self::resolveDocumentRoot();
            $abs_file = realpath($docRoot . '/' . $dir . '/' . $uri . '.php');
            if ($abs_file !== false && file_exists($abs_file)) {
                return App::include('/' . $dir . '/' . $uri . '.php');
            } else if (is_dir($docRoot . '/' . $dir . '/' . $uri)) {
                $result = $this->serveDirectory($dir.'/'.$uri, $dir.'/'.$uri);
                if ($result === false) {
                    return $this->invokeFallbackOrNotFound();
                }
                return $result;
            }
            // Apache parity: ENOTDIR (a path component is a file) is 403, not 404.
            if (self::isEnotdir($docRoot . '/' . $dir . '/' . $uri)) {
                return 403;
            }
            return $this->invokeFallbackOrNotFound();
        });
    }

    /**
     * Register the task + finish OpenSwoole event handlers when task workers
     * are configured. Extracted verbatim from App::run() (Phase 3) — runs at the
     * same point, gated on the same effective_settings['task_worker_num'].
     *
     * @param \OpenSwoole\WebSocket\Server $server
     * @param array<string, mixed> $effective_settings
     */
    private function registerTaskHandlers(\OpenSwoole\WebSocket\Server $server, array $effective_settings): void
    {
        if (($effective_settings['task_worker_num'] ?? 0) > 0) {
            // OpenSwoole 22.x dispatches task callbacks with TWO different
            // signatures depending on settings:
            //
            //   task_enable_coroutine = true  → function($server, Task $task)
            //   task_enable_coroutine = false → function($server, $id, $rid, $data)
            //
            // Our default is `task_enable_coroutine => true`, so the 2-arg
            // form is the production hot path; the 4-arg form is kept for
            // apps that opt back out. The variadic adapter below tolerates
            // both, so neither user override nor an OpenSwoole minor-version
            // shift can cause `ArgumentCountError` mid-worker. See issue #103.
            $server->on('task', function ($server, ...$rest) {
                return App::dispatchTaskCallback(array_values($rest));
            });

            $server->on('finish', function ($server, $task_id, $data) {
                elog((string)json_encode($data), "task_task");
            });
        }
    }

    /**
     * Reverse + add the queued middleware-wait-stack onto the live PSR-15
     * StackHandler. Extracted verbatim from App::run() (Phase 3) — runs at the
     * same point, mutating self::$middleware_stack in the same first-registered-
     * outermost order.
     */
    private static function buildMiddlewareStack(): void
    {
        assert(self::$middleware_stack !== null);
        foreach (array_reverse(self::$middleware_wait_stack) as $middleware) {
            elog("Registering middleware: ".get_class($middleware));
            $newStack = self::$middleware_stack->add($middleware);
            assert($newStack instanceof StackHandler);
            self::$middleware_stack = $newStack;
        }
    }

    /**
     * #338 — in-flight raw responses, per worker process. A worker-killing
     * FATAL (E_COMPILE_ERROR / E_ERROR / …) never reaches the normal emit
     * path, so the client connection — held open by the master's reactor —
     * would hang until the CLIENT's timeout (HTTP 000). Apache/mod_php
     * answers 500. The session managers track every request's raw
     * OpenSwoole response here and release it on normal completion; the
     * native shutdown guard below answers whatever is left when a fatal
     * tears the worker down. Coroutine mode can hold several entries at
     * once — one fatal kills them all, so all of them get the 500.
     *
     * @var array<int, \OpenSwoole\Http\Response>
     */
    private static array $fatal_guard_inflight = [];

    /** #338 — track an in-flight raw response; returns the release key. */
    public static function fatalGuardTrack(\OpenSwoole\Http\Response $response): int
    {
        $id = \spl_object_id($response);
        self::$fatal_guard_inflight[$id] = $response;
        return $id;
    }

    /** #338 — release a response tracked by fatalGuardTrack(). */
    public static function fatalGuardRelease(int $id): void
    {
        unset(self::$fatal_guard_inflight[$id]);
    }

    /**
     * #338 — native shutdown callback (registered in registerAllOverrides()
     * BEFORE the register_shutdown_function override installs). On a fatal,
     * answers every in-flight connection with a minimal 500 — mod_php
     * parity — instead of leaving clients to time out, and surfaces the
     * fatal in the PHP error log (debug.log misses engine fatals; that
     * silence is what made ext-zealphp#36 a multi-day hunt).
     *
     * Uses only engine-native calls: during shutdown the coroutine
     * scheduler (and thus elog()'s async channel) may be unavailable.
     */
    public static function fatalResponseGuard(): void
    {
        if (self::$fatal_guard_inflight === []) {
            return;
        }
        $e = \error_get_last();
        if ($e === null || !\in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        \error_log(sprintf(
            'ZealPHP fatal-guard: answering %d in-flight request(s) with 500 after fatal: %s in %s:%d',
            \count(self::$fatal_guard_inflight),
            $e['message'],
            $e['file'],
            $e['line']
        ));

        $admin = self::$server_admin !== null && self::$server_admin !== ''
            ? '<p>Please contact the server administrator at ' . \htmlspecialchars(self::$server_admin, ENT_QUOTES) . '.</p>'
            : '';
        $body = '<!DOCTYPE html><html><head><title>500 Internal Server Error</title></head><body>'
            . '<h1>Internal Server Error</h1>'
            . '<p>The server encountered an internal error and was unable to complete your request.</p>'
            . $admin . '</body></html>';

        foreach (self::$fatal_guard_inflight as $id => $response) {
            unset(self::$fatal_guard_inflight[$id]);
            try {
                if (!$response->isWritable()) {
                    continue;
                }
                $response->status(500, 'Internal Server Error');
                $response->header('Content-Type', 'text/html; charset=utf-8');
                $response->end($body);
            } catch (\Throwable $t) {
                // The connection may already be torn down mid-shutdown; a
                // failed 500 still beats a silent hang — close so the client
                // gets an immediate reset instead of a timeout.
                try {
                    $response->close();
                } catch (\Throwable) {
                    // nothing more we can do from a dying worker
                }
            }
        }
    }

    /**
     * Register the OnRequest event handler — the per-request entry point that
     * populates RequestContext, runs the middleware stack, fires shutdown
     * functions, and emits the response. Extracted verbatim from App::run()
     * (Phase 3) — runs at the same point; the only captured locals are $server
     * and the resolved session-manager class name.
     *
     * @param \OpenSwoole\WebSocket\Server $server
     * @param class-string<\ZealPHP\Session\CoSessionManager>|class-string<\ZealPHP\Session\SessionManager> $sessionManager
     */
    private function registerOnRequest(\OpenSwoole\WebSocket\Server $server, string $sessionManager): void
    {
        // #227 (defense-in-depth): capture the boot-assembled middleware stack
        // in the request closure's `use` clause. The primary fix is the reset
        // gate (App::perRequestStateResetsActive()) — but if a per-request
        // class-static reset ever zeroes App::$middleware_stack anyway (e.g. an
        // ext-zealphp build whose boot-snapshot exemption misses it under
        // coroutine-legacy on PHP 8.4), re-reading the class static here would
        // hand back null and every request would 500 with "handle() on null".
        // A closure `use` capture lives on the closure object — outside every
        // per-request reset / isolation category (class statics, function
        // statics, $GLOBALS, superglobals) — so it survives. registerOnRequest()
        // runs AFTER buildMiddlewareStack(), so this is the fully assembled chain.
        $bootMiddleware = self::$middleware_stack;
        $server->on("request",new $sessionManager(function(\ZealPHP\HTTP\Request $request, \ZealPHP\HTTP\Response $response) use ($bootMiddleware) {
            $g = RequestContext::instance();

            $g->status = 200;
            /** @var array<string, mixed> $get */
            $get = $request->get ?? [];
            /** @var array<string, mixed> $post */
            $post = $request->post ?? [];
            // #305 — PHP-canonical $_COOKIE from the raw Cookie header (OpenSwoole's
            // own parser is disabled via http_parse_cookie=false). Idempotent: the
            // session manager already parsed + wrote it back to $request->cookie.
            /** @var array<string, mixed> $cookie */
            $cookie = self::requestCookieMap($request);
            // #371 — a session id the manager already rotated (forged /
            // strict-mode-rejected) must not be clobbered back to the forged
            // value by re-parsing the raw request cookie. Re-assert the
            // manager's rotated id so zeal_session_id() reads it.
            $cookie = self::reassertRotatedSessionId($cookie);
            // #304 — transpose OpenSwoole's index-major $_FILES to PHP-canonical
            // field-major + add the PHP 8.1+ full_path key.
            /** @var array<string, mixed> $files */
            $files = self::normalizeUploadedFiles($request->files ?? []);
            $g->get = $get;
            $g->post = $post;
            // #356 — POST-wins precedence (PHP request_order='GP'); see
            // composeRequestArray() for the rationale.
            $g->request = self::composeRequestArray($get, $post);
            $g->cookie = $cookie;
            $g->files = $files;

            // $_SERVER — built by the shared buildServerVars() so the OnRequest
            // populate and the coroutine-legacy rebindRequestInput() re-assert
            // produce a byte-identical server array (single source of truth).
            $srvFinal = self::buildServerVars($request);
            $g->server = $srvFinal;

            // v0.2.27 — superglobals(true) mode populates PHP's $_GET, $_POST,
            // $_COOKIE, $_FILES, $_SERVER, $_REQUEST from the OpenSwoole request.
            // Restores v0.1.x behaviour that was lost when G switched to declared
            // properties (commit 900c18a). Legacy code using $_GET['foo'] works
            // without rewriting it as $g->get['foo'], which is the entire point
            // of the `$superglobals = true` flag. Race-safe under the documented
            // superglobals(true) + enableCoroutine(false) pairing; the unsafe
            // combination is already flagged at App::run() boot time. $_SESSION
            // is intentionally NOT touched here — the session manager owns its
            // own write path (file load + uopz session_start).
            if (App::$superglobals) {
                $GLOBALS['_GET']     = $get;
                $GLOBALS['_POST']    = $post;
                $GLOBALS['_COOKIE']  = $cookie;
                $GLOBALS['_FILES']   = $files;
                $GLOBALS['_SERVER']  = $srvFinal;
                $GLOBALS['_REQUEST'] = $g->request;
                // $_SESSION is NOT set here — zeal_session_start() binds
                // $_SESSION = &$g->session via reference in Mode 4.
                // v0.2.30 (issue #17) — make $g->get/post/cookie/files/server/
                // request LIVE ALIASES of the superglobals, not per-request
                // snapshots. A declared `public array $get` is accessed
                // directly and shadows __get/__set, so a $_GET mutation after
                // dispatch wasn't visible through $g->get (and vice versa).
                // unset() the declared typed slots so reads/writes route
                // through RequestContext::__get()/__set(), which proxy to
                // $GLOBALS['_GET'] etc. by reference — the same live-alias
                // mechanism the session manager already applies to
                // $g->session. In superglobals mode the two names are now
                // genuinely the same array.
                unset($g->get, $g->post, $g->cookie, $g->files, $g->server, $g->request);

                // #332 — claim live-superglobal OWNERSHIP for this request
                // coroutine (ext-zealphp 0.3.36+). Without it, a `go()` child
                // spawned by a handler/middleware (the async-log /
                // fire-and-forget pattern) that yields would snapshot-and-clear
                // the request's live superglobals under the CHILD's key — the
                // request continued with EMPTY $_SERVER/$_SESSION (empty
                // REQUEST_METHOD → 501 dispatch; session writes lost). With
                // ownership claimed, only THIS coroutine's yields save+clear.
                if (self::$coroutine_isolated_superglobals
                    && \function_exists('zealphp_superglobals_owner')
                ) {
                    (\zealphp_superglobals_owner(...))();
                }
            }

            $serverRequest  = new \ZealPHP\HTTP\LazyServerRequest($request->parent);

            try {
                // #227: prefer the registration-time capture (immune to a
                // per-request class-static reset zeroing the static); fall back
                // to the self-healing accessor (rebuilds from the wait stack),
                // then fail loudly with a diagnosable message instead of the
                // cryptic "Call to a member function handle() on null".
                $mw = $bootMiddleware ?? App::middleware();
                if ($mw === null) {
                    throw new \RuntimeException(
                        'ZealPHP: middleware stack unavailable at request time. '
                        . 'App::run() did not assemble it, or a per-request '
                        . 'state reset zeroed App::$middleware_stack with no '
                        . 'recoverable wait stack (issue #227). Upgrade '
                        . 'ext-zealphp to >= 0.3.25 if running coroutine-legacy '
                        . 'on PHP 8.4.'
                    );
                }
                $serverResponse = $mw->handle($serverRequest);

                // Per-request shutdown functions (Apache mod_php parity). Run AFTER
                // middleware returns but BEFORE emit, so a shutdown function can
                // still echo/header()/http_response_code() into the final response.
                $shutdown = $g->shutdown_functions;
                if (!empty($shutdown)) {
                    ob_start();
                    $beforeStatus = $g->status;
                    foreach ($shutdown as [$fn, $args]) {
                        try { $fn(...$args); } catch (\Throwable $e) {
                            elog("shutdown function threw: ".$e->getMessage(), 'error');
                        }
                    }
                    $g->shutdown_functions = [];
                    $extra = ob_get_clean();
                    if ($extra !== false && $extra !== '') {
                        $combined = (string)$serverResponse->getBody() . $extra;
                        $bodyRes = fopen('php://temp', 'r+');
                        if ($bodyRes !== false) {
                            fwrite($bodyRes, $combined);
                            rewind($bodyRes);
                            $serverResponse = $serverResponse->withBody(
                                new \OpenSwoole\Core\Psr\Stream($bodyRes)
                            );
                        }
                    }
                    if ($g->status !== null && $g->status !== $beforeStatus
                        && $g->status !== $serverResponse->getStatusCode()) {
                        $serverResponse = $serverResponse->withStatus($g->status);
                    }
                }

                // Effective wire status — overwritten by emitEffectiveStatus()
                // on the writable path when a raw status-line override (#327)
                // applies, so the access log below always matches the wire.
                $emitStatus = $serverResponse->getStatusCode();

                if ($response->parent->isWritable()) {
                    // mod_php header_register_callback() — fire once just before
                    // headers flush so header() calls inside it still land.
                    $headerCb = $g->memo['_header_callback'] ?? null;
                    if (is_callable($headerCb)) {
                        unset($g->memo['_header_callback']);
                        try {
                            $headerCb();
                        } catch (\Throwable $e) {
                            elog("header_register_callback threw: " . $e->getMessage(), 'error');
                        }
                    }
                    $response->flush();
                    // Apache ServerTokens parity — value/omission per App::$server_tokens.
                    $poweredBy = self::poweredByHeader();
                    if ($poweredBy !== null) {
                        $response->parent->header('X-Powered-By', $poweredBy);
                    }
                    // Threaded emit — use App::emitEffectiveStatus() instead of
                    // vendor Response::emit()'s one-arg status() call, so codes
                    // like 451 (missing from OpenSwoole's native C list) emit
                    // correctly AND a raw `header("HTTP/x.x …")` status line
                    // passes through verbatim (#327). Body/header transcription
                    // mirrors vendor.
                    $emitStatus = App::emitEffectiveStatus($response->parent, $emitStatus);
                    // RFC 7230 §3.3.2: 1xx / 204 / 304 MUST carry no body and no
                    // Content-Length / Content-Type. Drop both even if a handler
                    // produced a body (e.g. http_response_code(204); echo "x";) so
                    // the wire stays conformant and clients don't mis-frame (#290).
                    $forbidsBody = App::statusForbidsBody($emitStatus);
                    foreach ($serverResponse->getHeaders() as $hName => $hValues) {
                        if ($forbidsBody
                            && (strcasecmp($hName, 'Content-Length') === 0
                                || strcasecmp($hName, 'Content-Type') === 0)) {
                            continue;
                        }
                        foreach ($hValues as $hValue) {
                            $response->parent->header($hName, $hValue);
                        }
                    }
                    if ($forbidsBody) {
                        // The strip above only filters the PSR-side headers —
                        // the native end() then re-injects its engine defaults
                        // (`Content-Type: text/html` + `Content-Length: 0`) at
                        // the C level, where no header() call can reach them:
                        // a null value restores the default and ''/false emits
                        // a malformed bare `Content-Type:`. So for 1xx/204/304
                        // serialize the head ourselves and send it raw on the
                        // detached connection — the wire-frame carries neither
                        // (#290, RFC 9110 §6.4.1 / RFC 7230 §3.3.2). Keep-alive
                        // follows the request's Connection/protocol semantics.
                        $reqHeader = is_array($request->parent->header) ? $request->parent->header : [];
                        $reqServer = is_array($request->parent->server) ? $request->parent->server : [];
                        $connTok = $reqHeader['connection'] ?? '';
                        $connTok = is_string($connTok) ? strtolower($connTok) : '';
                        $proto = $reqServer['server_protocol'] ?? 'HTTP/1.1';
                        $proto = is_string($proto) ? $proto : 'HTTP/1.1';
                        $keepAlive = $connTok === 'keep-alive'
                            || ($connTok !== 'close' && $proto === 'HTTP/1.1');
                        $reason = $g->raw_status_reason ?? self::reasonPhrase($emitStatus);
                        // Status line mirrors the request protocol (an HTTP/1.0
                        // client gets an HTTP/1.0 status line, like Apache).
                        $head = ($proto === 'HTTP/1.0' ? 'HTTP/1.0' : 'HTTP/1.1')
                            . ' ' . $emitStatus . ' '
                            . ($reason !== '' ? $reason : 'Status ' . $emitStatus) . "\r\n";
                        $haveDate = false;
                        $nativeHeaders = is_array($response->parent->header) ? $response->parent->header : [];
                        foreach ($nativeHeaders as $hName => $hValues) {
                            $hName = (string)$hName;
                            // Body-framing headers can't appear on a body-less
                            // status (Transfer-Encoding/Trailer included);
                            // Connection is decided below from the request.
                            if (strcasecmp($hName, 'Connection') === 0
                                || strcasecmp($hName, 'Content-Length') === 0
                                || strcasecmp($hName, 'Content-Type') === 0
                                || strcasecmp($hName, 'Transfer-Encoding') === 0
                                || strcasecmp($hName, 'Trailer') === 0) {
                                continue;
                            }
                            // Raw-wire serialization: enforce the RFC 9110
                            // token grammar on names and reject CR/LF-bearing
                            // values (response-splitting guard).
                            if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $hName)) {
                                continue;
                            }
                            $haveDate = $haveDate || strcasecmp($hName, 'Date') === 0;
                            foreach (is_array($hValues) ? $hValues : [$hValues] as $hv) {
                                if (is_scalar($hv) && !preg_match('/[\r\n]/', (string)$hv)) {
                                    $head .= $hName . ': ' . (string)$hv . "\r\n";
                                }
                            }
                        }
                        $nativeCookies = is_array($response->parent->cookie) ? $response->parent->cookie : [];
                        foreach ($nativeCookies as $setCookie) {
                            if (is_string($setCookie) && !preg_match('/[\r\n]/', $setCookie)) {
                                $head .= 'Set-Cookie: ' . $setCookie . "\r\n";
                            }
                        }
                        if (!$haveDate) {
                            $head .= 'Date: ' . gmdate('D, d M Y H:i:s') . " GMT\r\n";
                        }
                        $head .= 'Connection: ' . ($keepAlive ? 'keep-alive' : 'close') . "\r\n\r\n";
                        $fd = $response->parent->fd;
                        if (is_int($fd) && $fd > 0 && self::$server !== null) {
                            $response->parent->detach();
                            self::$server->send($fd, $head);
                            if (!$keepAlive) {
                                self::$server->close($fd);
                            }
                        } else {
                            // Prerequisites missing (no usable fd / no server
                            // instance, e.g. a unit-test double): fall back to
                            // the native end() — engine-default CT/CL reattach
                            // there, but the client is never left hanging.
                            $response->parent->end();
                        }
                    } else {
                        $body = $serverResponse->getBody();
                        $body->rewind();
                        $chunkSize = \OpenSwoole\Core\Psr\Response::CHUNK_SIZE;
                        if ($body->getSize() > $chunkSize) {
                            while (!$body->eof()) {
                                $response->parent->write($body->read($chunkSize));
                            }
                            $response->parent->end();
                        } else {
                            $response->parent->end($body->getContents());
                        }
                    }
                }
                access_log($emitStatus, 0);
            } catch (\Throwable|\OpenSwoole\ExitException $e) {
                if ($e instanceof \OpenSwoole\ExitException
                    || ($e::class === 'ExitException' && method_exists($e, 'getStatus'))
                    || $e instanceof \ZealPHP\HaltException
                ) {
                    $exitStatus = $e->getStatus();
                    $body = is_string($exitStatus) ? $exitStatus : '';
                    $code = (is_int($exitStatus) && $exitStatus >= 100 && $exitStatus <= 599) ? $exitStatus : ($g->status ?? 200);
                    if ($response->parent->isWritable()) {
                        App::emitStatus($response->parent, $code);
                        $response->parent->end($body);
                    }
                    access_log($code, strlen($body));
                    return;
                }
                elog(jTraceEx($e), "error");
                if ($response->parent->isWritable()) {
                    // Render via App::renderError so a user-registered 500 handler
                    // (Apache ErrorDocument equivalent) runs even at the top level.
                    try {
                        $app = App::instance();
                        assert($app !== null);
                        $errResp = $app->renderError(500, $e);
                        App::emitStatus($response->parent, $errResp->getStatusCode());
                        foreach ($errResp->getHeaders() as $name => $values) {
                            foreach ($values as $value) {
                                $response->parent->header($name, $value);
                            }
                        }
                        $g->status = $errResp->getStatusCode();
                        $response->parent->end((string)$errResp->getBody());
                    } catch (\Throwable $e2) {
                        App::emitStatus($response->parent, 500);
                        $g->status = 500;
                        $body = App::displayErrors()
                            ? "<pre>".jTraceEx($e)."</pre>"
                            : "<pre>500 Internal Server Error</pre>";
                        $response->parent->end($body);
                    }
                }
            }
        }));
    }

    /**
     * Register the workerStart event handler — re-registers the php:// stream
     * wrapper, fires user onWorkerStart hooks, and wires dev route hot-reload.
     * Extracted verbatim from App::run() (Phase 3); closes over $this for the
     * dev-reload tick.
     *
     * @param \OpenSwoole\WebSocket\Server $server
     */
    private function registerWorkerStart(\OpenSwoole\WebSocket\Server $server): void
    {
        // Register the php:// stream wrapper once per worker process instead of per-request
        // and invoke any user-registered onWorkerStart hooks (timers, warmup, etc.)
        $server->on('workerStart', function($server, $workerId) {
            @stream_wrapper_unregister("php");
            stream_wrapper_register("php", \ZealPHP\IOStreamWrapper::class);
            self::$workerStartedAt = microtime(true);
            // #270 — seed the static CGI/SAPI server vars (PHP_SELF, SCRIPT_NAME,
            // SCRIPT_FILENAME, REQUEST_URI, DOCUMENT_ROOT) so app bootstrap that
            // reads $g->server / $_SERVER at worker start — BEFORE the first
            // request populates them per-request via buildServerVars() — doesn't
            // hit "Undefined array key". Existing keys win, and the per-request
            // handler overlays the real values; this is mod_php parity (the SAPI
            // exposes these even outside a request). Especially relevant in
            // coroutine-legacy, where the per-coroutine $_SERVER isolation gives
            // the worker-start coroutine a fresh empty $_SERVER.
            $g    = RequestContext::instance();
            // Existing keys win (left operand of +), so a stale value is never
            // clobbered; the per-request handler overlays real values later.
            $g->server = $g->server + self::baseServerVars();
            foreach (self::$workerStartHooks as $hook) {
                $hook($server, $workerId);
            }

            // #311 — wire worker-scoped signal handlers. The master applies its
            // handlers in the 'start' event (App::run()); worker-scoped handlers
            // registered via App::onSignal(..., workerOnly: true) must be applied
            // HERE, in-process per worker, or they silently never register.
            if (self::$signalHandlers !== []) {
                self::applySignalHandlersFor('worker');
            }

            // #26 — fold any boot-time $GLOBALS writes made during the onWorkerStart
            // hooks (app bootstrap includes like load.php) into the per-coroutine
            // baseline, so every request coroutine sees them — not just the first.
            // No-op unless coroutine-legacy $GLOBALS isolation is active (ext 0.3.33+).
            self::refreshGlobalsBaseline();

            // Dev route hot-reload: each worker polls route/*.php mtimes and
            // rebuilds its route table in-place on change — "save file → routes
            // update" with no process restart. OFF in production (App::devReload
            // resolves false unless explicitly enabled / ZEALPHP_DEV is set).
            if (App::devReload()) {
                $app = $this;
                $mtime = static function (): int {
                    $max = 0;
                    foreach (glob(self::$cwd . "/route/*.php") ?: [] as $f) {
                        $m = @filemtime($f);
                        if ($m !== false && $m > $max) { $max = $m; }
                    }
                    return $max;
                };
                $last = $mtime();
                $wid = is_numeric($workerId) ? (int) $workerId : 0;
                App::tick(1000, function () use (&$last, $mtime, $app, $wid) {
                    $cur = $mtime();
                    if ($cur !== $last) {
                        $last = $cur;
                        $n = $app->reloadRoutes();
                        elog("dev hot-reload (worker " . $wid . "): " . $n . " routes", "info");
                    }
                });
                if ($workerId === 0) {
                    elog(
                        "ZealPHP dev route hot-reload ON — polling route/*.php. "
                        . "Set opcache.validate_timestamps=1 (or disable opcache) in dev "
                        . "so route-file edits are seen.",
                        "info"
                    );
                }
            }
        });
    }

    /**
     * Register the workerStop event handler — fires user onWorkerStop hooks then
     * logs worker-recycle observability. Extracted verbatim from App::run()
     * (Phase 3).
     *
     * @param \OpenSwoole\WebSocket\Server $server
     */
    private function registerWorkerStop(\OpenSwoole\WebSocket\Server $server): void
    {
        // Worker recycle observability — fires when a worker exits (max_request
        // hit, graceful shutdown, or admin reload). Logs the request count,
        // peak RSS, and uptime so the max_request backstop is visible in prod
        // logs. Set ZEALPHP_RECYCLE_LOG=0 to silence.
        $server->on('workerStop', function($server, $workerId) {
            // User-registered per-worker shutdown hooks run first, before the
            // recycle-log line — so a hook can flush state even if logging is off.
            foreach (self::$workerStopHooks as $hook) {
                try {
                    $hook($server, $workerId);
                } catch (\Throwable $e) {
                    // A failing shutdown hook must not abort worker teardown.
                    \ZealPHP\elog('[workerStop hook] ' . $e->getMessage(), 'warn');
                }
            }
            if (\ZealPHP\env_flag('ZEALPHP_RECYCLE_LOG', true) === false) {
                return;
            }
            // @phpstan-ignore-next-line — $server is typed mixed by OpenSwoole event-handler signature; method_exists guards the call
            $stats = method_exists($server, 'stats') ? @$server->stats() : [];
            assert(is_array($stats));
            $rawReqCount = $stats['worker_request_count'] ?? $stats['request_count'] ?? 0;
            $reqCount = is_numeric($rawReqCount) ? (int)$rawReqCount : 0;
            $peakMb = round(memory_get_peak_usage(true) / 1048576, 1);
            $uptime = self::$workerStartedAt > 0
                ? round(microtime(true) - self::$workerStartedAt, 1)
                : 0.0;
            $workerIdInt = is_numeric($workerId) ? (int)$workerId : 0;
            \ZealPHP\elog(sprintf(
                '[recycle] worker %d exited after %d requests, peak RSS %s MB, uptime %ss',
                $workerIdInt,
                $reqCount,
                $peakMb,
                $uptime
            ), 'info');
        });
    }

    /**
     * Register the WebSocket open/message/close/shutdown event handlers, sharing
     * the per-worker fd → ws-path map across the closures. Extracted verbatim
     * from App::run() (Phase 3); the $wsFdMap is local to these four closures.
     *
     * @param \OpenSwoole\WebSocket\Server $server
     */
    private function registerWebSocketHandlers(\OpenSwoole\WebSocket\Server $server): void
    {
        // fd → ws path map, shared across WebSocket event closures
        /** @var array<int, string> $wsFdMap */
        $wsFdMap = [];

        $server->on('open', function(\OpenSwoole\WebSocket\Server $server, \OpenSwoole\Http\Request $request) use (&$wsFdMap) {
            $serverArr = $request->server ?? [];
            assert(is_array($serverArr));
            $rawPath = $serverArr['path_info'] ?? '/';
            $path = is_string($rawPath) ? $rawPath : '/';
            $fd = $request->fd;
            assert(is_int($fd));
            $wsFdMap[$fd] = $path;
            $g     = RequestContext::instance();

            // Initialize session from the upgrade request's cookie so
            // WebSocket onOpen handlers can read $g->session just like
            // HTTP handlers do via CoSessionManager.
            $sessionName = function_exists('ZealPHP\\Session\\zeal_session_name')
                ? \ZealPHP\Session\zeal_session_name()
                : 'PHPSESSID';
            // #305 — parse the upgrade request's raw Cookie header (OpenSwoole's
            // own parser is off via http_parse_cookie=false) for the PHPSESSID.
            $wsCookies = self::requestCookieMap($request);
            if (isset($wsCookies[$sessionName])) {
                $rawSid = $wsCookies[$sessionName];
                if (is_string($rawSid)) {
                    $g->cookie[$sessionName] = $rawSid;
                    \ZealPHP\Session\zeal_session_id($rawSid);
                    \ZealPHP\Session\zeal_session_start();
                    $g->_session_started = true;
                }
            }

            $app = App::instance();
            assert($app !== null);
            $route = $app->wsRoutes()[$path] ?? null;
            if ($route !== null && $route['open'] !== null) {
                ($route['open'])($server, $request, $g);
            }

            // Write-close the session after onOpen so the file isn't locked
            if ($g->_session_started ?? false) {
                \ZealPHP\Session\zeal_session_write_close();
            }
        });

        $server->on('message', function(\OpenSwoole\WebSocket\Server $server, \OpenSwoole\WebSocket\Frame $frame) use (&$wsFdMap) {
            // Skip control frames: PING(9), PONG(10), CONTINUATION(0)
            // Only dispatch TEXT(1) and BINARY(2) to route handlers
            $op = $frame->opcode;
            if ($op !== \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_TEXT &&
                $op !== \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_BINARY) {
                return;
            }
            $fd = $frame->fd;
            assert(is_int($fd));
            $path  = $wsFdMap[$fd] ?? null;
            $g     = RequestContext::instance();
            $app = App::instance();
            assert($app !== null);
            $route = $path ? ($app->wsRoutes()[$path] ?? null) : null;
            if ($route !== null) {
                ($route['message'])($server, $frame, $g);
            }
        });

        $server->on('close', function(\OpenSwoole\WebSocket\Server $server, int $fd) use (&$wsFdMap) {
            $path  = $wsFdMap[$fd] ?? null;
            unset($wsFdMap[$fd]);
            $g     = RequestContext::instance();
            $app = App::instance();
            assert($app !== null);
            $route = $path ? ($app->wsRoutes()[$path] ?? null) : null;
            if ($route !== null && $route['close'] !== null) {
                ($route['close'])($server, $fd, $g);
            }
        });

        // Graceful shutdown: send WebSocket CLOSE frame 1001 (Going Away) to all connections
        $server->on('shutdown', function(\OpenSwoole\WebSocket\Server $server) use (&$wsFdMap) {
            foreach (array_keys($wsFdMap) as $fd) {
                $fdInt = (int)$fd;
                if ($server->isEstablished($fdInt)) {
                    $server->disconnect($fdInt, 1001, 'Server shutting down');
                }
            }
        });
    }

    /**
     * The assembled PSR-15 middleware stack (built at boot from
     * `App::$middleware_wait_stack` by `buildMiddlewareStack()`).
     *
     * Self-heals: if `App::$middleware_stack` reads back null but middleware
     * was queued via `addMiddleware()`, the stack is re-assembled on the fly
     * from the wait stack — base `ResponseMiddleware` (the router) then each
     * queued middleware in first-registered-outermost order, identical to
     * `buildMiddlewareStack()`. This is a defence-in-depth backstop: if a
     * per-request class-static reset ever zeroes `App::$middleware_stack` after
     * boot without an exempting snapshot (issue #227 — the root fix is the
     * reset gate `perRequestStateResetsActive()`), the accessor still returns a
     * working stack instead of null. The rebuilt stack is
     * deliberately NOT written back to the static — under isolation the write
     * would not persist to the next coroutine, and in normal modes a null here
     * means "called before init()", which the rebuild already satisfies
     * without masking that ordering mistake. In the normal post-`run()` path
     * the static is non-null, so this branch never runs (zero overhead, no
     * behavioural change).
     */
    public static function middleware(): ?StackHandler
    {
        if (self::$middleware_stack === null && self::$middleware_wait_stack !== []) {
            $stack = (new StackHandler())->add(new ResponseMiddleware());
            assert($stack instanceof StackHandler);
            foreach (array_reverse(self::$middleware_wait_stack) as $middleware) {
                $rebuilt = $stack->add($middleware);
                assert($rebuilt instanceof StackHandler);
                $stack = $rebuilt;
            }
            return $stack;
        }
        return self::$middleware_stack;
    }
}
