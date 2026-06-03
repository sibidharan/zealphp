<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use ZealPHP\App;
use ZealPHP\GithubStars;
use ZealPHP\Middleware\CompressionMiddleware;
use ZealPHP\Middleware\CorsMiddleware;
use ZealPHP\Middleware\ETagMiddleware;
use ZealPHP\Middleware\IniIsolationMiddleware;
use ZealPHP\Middleware\RangeMiddleware;
use ZealPHP\Middleware\SessionStartMiddleware;
use ZealPHP\Store;

use function ZealPHP\bench_mode_enabled;
use function ZealPHP\env_flag;

// Timezone — honors php.ini's date.timezone, or override via ZEALPHP_TZ.
// No hardcoded locale; servers run in different regions.
$tz = getenv('ZEALPHP_TZ');
if ($tz !== false && $tz !== '') {
    date_default_timezone_set($tz);
}

// Asset cache-bust key — drives ?v=… on CSS/JS in template/_head.php.
// Resolution order (best → fallback):
//   1. Git commit (read from .git, no shell): bumps on EVERY commit so any
//      asset change busts caches, and is identical for the same commit across
//      deploys/nodes — so CDN/browser caches stay valid until a real change.
//   2. Newest mtime across public/css + public/js (composer installs ship no
//      .git): covers ALL assets, so JS-only edits bust too — not just one file.
//   3. Boot time: last resort, so a missing source never kills startup.
if (!defined('ZEALPHP_ASSET_VERSION')) {
    $zealAssetVersion = (static function (string $root): string {
        // 1. Git commit, read straight from .git (no shell dependency).
        $head = $root . '/.git/HEAD';
        if (is_file($head)) {
            $ref = trim((string) file_get_contents($head));
            if (str_starts_with($ref, 'ref: ')) {
                $name    = substr($ref, 5);
                $refFile = $root . '/.git/' . $name;
                if (is_file($refFile)) {
                    return substr(trim((string) file_get_contents($refFile)), 0, 12);
                }
                $packed = $root . '/.git/packed-refs';            // packed ref
                if (is_file($packed)) {
                    foreach (file($packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                        if (str_ends_with($line, ' ' . $name)) {
                            return substr($line, 0, 12);
                        }
                    }
                }
            } elseif (strlen($ref) >= 7) {
                return substr($ref, 0, 12);                        // detached HEAD = the SHA
            }
        }
        // 2. Newest mtime across all CSS + JS (catches JS-only edits).
        $newest = 0;
        foreach (['/public/css', '/public/js'] as $dir) {
            if (!is_dir($root . $dir)) {
                continue;
            }
            /** @var \SplFileInfo $f */
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . $dir, \FilesystemIterator::SKIP_DOTS)
            ) as $f) {
                if ($f->isFile()) {
                    $newest = max($newest, $f->getMTime());
                }
            }
        }
        return (string) ($newest > 0 ? $newest : time());          // 3. last resort
    })(__DIR__);

    define('ZEALPHP_ASSET_VERSION', $zealAssetVersion);
}

// Auto-build the API reference at /docs/api/ on first boot. The build
// (PHAR download + phpDocumentor run, ~30-60 s) runs DETACHED via
// scripts/build-api-docs.sh so it NEVER blocks server startup — the
// server listens on :8080 immediately and /docs/api/ serves the
// api-missing fallback until the build lands. Subsequent boots skip
// (the script exits early when public/docs/api/index.html exists).
// Gated by argv so `php app.php stop|status|logs` doesn't trigger it;
// disabled entirely by ZEALPHP_SKIP_DOCS_BUILD=1 (CI sets this — the
// API ref isn't needed to run tests, and an in-flight build competing
// for CPU flakes timing-sensitive integration tests like the WS ticker).
$__cliSub = $argv[1] ?? 'start';
if (PHP_SAPI === 'cli' && !in_array($__cliSub, ['stop', 'status', 'logs', '--help', '-h'], true)) {
    $__docsIndex   = __DIR__ . '/public/docs/api/index.html';
    $__buildScript = __DIR__ . '/scripts/build-api-docs.sh';
    if (!is_file($__docsIndex) && !env_flag('ZEALPHP_SKIP_DOCS_BUILD', false) && is_file($__buildScript)) {
        $__log = sys_get_temp_dir() . '/zealphp-docs-build.log';
        // proc_open with an ARRAY command invokes no shell (no injection
        // surface) and lets the child run detached: we redirect its I/O
        // to a log file and never proc_close() (which would block-wait).
        // The long-lived server process parents it for its ~60 s life.
        $__proc = @proc_open(
            ['bash', $__buildScript],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $__log, 'w'], 2 => ['file', $__log, 'a']],
            $__pipes
        );
        if (is_resource($__proc)) {
            echo "[zealphp] Building API docs in the background; /docs/api/ shows a fallback until ready (log: {$__log}).\n";
        }
    }
    unset($__cliSub, $__docsIndex, $__buildScript, $__log, $__proc, $__pipes);
}

// Build the page-CSS bundle: concatenate public/css/pages/*.css into one
// public/css/pages.css served statically and loaded up front in _head.php.
// Loading page styles eagerly (vs. lazily per page) is what stops hx-boost
// navigation from flashing unstyled content. Regenerated on every boot so
// it can't drift from the per-page sources; trivial cost (~47 KB). The
// /css/ path is owned by OpenSwoole's static handler, so this must be a
// real file — a PHP route never gets a chance to serve it.
if (PHP_SAPI === 'cli' && !in_array($argv[1] ?? 'start', ['stop', 'status', 'logs', '--help', '-h'], true)) {
    $__cssPagesDir = __DIR__ . '/public/css/pages';
    if (is_dir($__cssPagesDir)) {
        $__bundle = '';
        $__cssFiles = glob($__cssPagesDir . '/*.css') ?: [];
        sort($__cssFiles);
        foreach ($__cssFiles as $__cssFile) {
            $__cssBody = file_get_contents($__cssFile);
            if ($__cssBody !== false) {
                $__bundle .= '/* ── ' . basename($__cssFile) . " ── */\n" . $__cssBody . "\n";
            }
        }
        @file_put_contents(__DIR__ . '/public/css/pages.css', $__bundle);
    }
    unset($__cssPagesDir, $__bundle, $__cssFiles, $__cssFile, $__cssBody);
}

// Lifecycle is coroutine-mode by default (the recommended default for new
// apps). Overridable via env so the same demo can be exercised under the
// Mixed-mode and legacy-CGI lifecycles too — used by the coverage harness to
// reach mode-specific server code (SessionManager, the superglobals branches,
// cgi_worker). Production deployments leave these unset → coroutine mode.
App::superglobals(env_flag('ZEALPHP_SUPERGLOBALS', false));
if (($__pi = getenv('ZEALPHP_PROCESS_ISOLATION')) !== false && $__pi !== '') {
    App::processIsolation(env_flag('ZEALPHP_PROCESS_ISOLATION', false));
}
if (($__ec = getenv('ZEALPHP_ENABLE_COROUTINE')) !== false && $__ec !== '') {
    App::enableCoroutine(env_flag('ZEALPHP_ENABLE_COROUTINE', true));
}
if (($__cm = getenv('ZEALPHP_CGI_MODE')) !== false && $__cm !== '') {
    App::cgiMode($__cm);
}

// ─── CGI backends (per-extension, ExecCGI-scoped) ───────────────────
// Demonstrates non-PHP CGI parity in coroutine mode: scripts under the
// /cgi-bin URL scope run via their interpreter; the same extension under
// any other path is refused (no execution, no source leak). Interpreters
// are detected at boot so this works wherever the box has them. At this
// point in boot the exec-override isn't active, so shell_exec is the real
// builtin — fine for one-time interpreter discovery.
$pythonBin = trim((string) shell_exec('command -v python3'));
if ($pythonBin !== '') {
    App::registerCgiBackend('.py', ['mode' => 'proc', 'interpreter' => $pythonBin, 'exec_paths' => ['/cgi-bin']]);
}
$perlBin = trim((string) shell_exec('command -v perl'));
if ($perlBin !== '') {
    App::registerCgiBackend('.pl', ['mode' => 'proc', 'interpreter' => $perlBin, 'exec_paths' => ['/cgi-bin']]);
}

// Apache `ScriptAlias` parity — anything under /cgi-bin/ runs as CGI regardless
// of extension. For files whose extension has a registered backend (.py/.pl
// above), the per-extension interpreter wins; everything else runs via its
// own `#!` shebang (requires the file to be `+x`, like Apache).
App::cgiScriptAlias('/cgi-bin', ['mode' => 'proc']);

$benchMode             = bench_mode_enabled();
$demoMiddleware        = env_flag('ZEALPHP_DEMO_MIDDLEWARE', false);
$compressionMiddleware = env_flag('ZEALPHP_COMPRESSION_MIDDLEWARE', false);
$iniIsolate            = env_flag('ZEALPHP_INI_ISOLATE', false);

$envInt = static function (string $name, int $default, int $min = 1): int {
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    return max($min, (int) $value);
};

$appPort = $envInt('ZEALPHP_PORT', 8080);
$app = App::init(
    getenv('ZEALPHP_HOST') ?: '0.0.0.0',
    $appPort
);

if (!$benchMode) {
    // CorsMiddleware reads ZEALPHP_CORS_ORIGINS (comma-separated) when no
    // explicit origins are passed. Falls back to '*' with a one-time warning
    // — fine for an OSS docs site, NOT for a real API.
    $app->addMiddleware(new CorsMiddleware());           // outermost — preflight + Allow-Origin
    $app->addMiddleware(new ETagMiddleware());           // generates ETag, returns 304 on If-None-Match
    $app->addMiddleware(new RangeMiddleware());          // RFC 7233 Range / 206 Partial Content
    $app->addMiddleware(new SessionStartMiddleware());   // eager session start for first-time visitors
    if ($compressionMiddleware) {
        $app->addMiddleware(new CompressionMiddleware());
    }
    if ($iniIsolate) {
        // Snapshot/restore per-request ini values (date.timezone, error_reporting,
        // display_errors, memory_limit, ...) so user ini_set() can't leak across
        // requests on the same worker. Opt-in via ZEALPHP_INI_ISOLATE=1.
        $app->addMiddleware(new IniIsolationMiddleware());
    }
    if ($demoMiddleware) {
        // Demo trace middleware — loaded only when ZEALPHP_DEMO_MIDDLEWARE=1.
        // Honestly named: they log, they don't auth/validate.
        require_once __DIR__ . '/examples/demo_middleware.php';
        $app->addMiddleware(new \ZealPHP\Demo\RequestLogMiddleware());
        $app->addMiddleware(new \ZealPHP\Demo\QueryDumpMiddleware());
    }
}

// ─── Docs-site routes ───────────────────────────────────────────────

// Public phpinfo for the docs site. Fine on a public docs site; do NOT
// expose on production apps without gating behind a dev-only env check.
$app->route('/phpinfo', function () {
    App::render('phpinfo');
});

// /json — full PSR-15 stack benchmark endpoint (referenced by PERF.md).
// Returns a tiny static payload, not session data. Exercises the same
// PSR-15 stack + array→JSON auto-serialization path as a real API handler.
$app->route('/json', function () {
    return ['ok' => true, 'service' => 'zealphp'];
});

// /raw/bench — lean-runtime benchmark endpoint ('raw' => true skips the PSR stack).
$app->route('/raw/bench', ['raw' => true], function () {
    return 'You requested: bench';
});

// One-line installer: curl -fsSL https://php.zeal.ninja/install.sh | sudo bash
$app->route('/install.sh', function ($response) {
    $response->sendFile(__DIR__ . '/setup.sh');
});

// Bench-environment installer — wraps setup.sh + installs wrk/ab + clones the repo.
$app->route('/bench-install.sh', function ($response) {
    $response->sendFile(__DIR__ . '/bench-install.sh');
});

// Benchmark template — perf comparisons against template rendering.
$app->route('/bench/template', function () {
    App::render('/bench_page', [
        'title' => 'ZealPHP Benchmark',
        'items' => [
            ['name' => 'Routing',    'desc' => 'Flask-style routes'],
            ['name' => 'Streaming',  'desc' => 'SSR via yield'],
            ['name' => 'WebSocket',  'desc' => 'Built-in real-time'],
            ['name' => 'Store',      'desc' => 'Shared memory'],
            ['name' => 'Coroutines', 'desc' => 'go() + Channel'],
        ],
    ]);
});

// ─── Server settings ────────────────────────────────────────────────

$settings = [
    'task_worker_num'  => $envInt('ZEALPHP_TASK_WORKERS', 8, 0),
    'http_compression' => env_flag('ZEALPHP_HTTP_COMPRESSION', !$compressionMiddleware),
];

foreach ([
    'ZEALPHP_WORKERS'       => 'worker_num',
    'ZEALPHP_MAX_CONN'      => 'max_conn',
    'ZEALPHP_MAX_COROUTINE' => 'max_coroutine',
    'ZEALPHP_BACKLOG'       => 'backlog',
    'ZEALPHP_REACTOR_NUM'   => 'reactor_num',
] as $envName => $settingKey) {
    $value = getenv($envName);
    if ($value !== false && $value !== '') {
        $settings[$settingKey] = max(1, (int) $value);
    }
}

// Default worker count when ZEALPHP_WORKERS is unset. OpenSwoole would otherwise
// fall back to swoole_cpu_num() = the HOST cpu count, which over-spawns in a
// cgroup-CPU-limited container (e.g. 24 workers on a 4–6 CPU Docker container)
// and gets OOM-killed. Default to a conservative 4, capped to the cgroup quota.
if (!isset($settings['worker_num'])) {
    $settings['worker_num'] = \ZealPHP\default_worker_count(4);
}

// PID file resolution — explicit env wins; otherwise the shared resolver picks
// the first writable dir (/tmp/zealphp, else a per-user fallback when it is owned
// by another user). Same resolver App::resolvePidFile() uses, so the server's PID
// file and `php app.php stop/status` always agree on the directory.
$logDir  = rtrim((string) (\ZealPHP\resolve_log_dir() ?: sys_get_temp_dir()), '/');
$pidFile = trim((string) (getenv('ZEALPHP_PID_FILE') ?: $logDir . '/zealphp_' . $appPort . '.pid'));
if ($pidFile !== '') {
    $pidDir = dirname($pidFile);
    if ($pidDir !== '.' && !is_dir($pidDir)) {
        @mkdir($pidDir, 0775, true);
    }
    $settings['pid_file'] = $pidFile;
}

$daemonize = env_flag('ZEALPHP_DAEMONIZE', false);
if ($daemonize) {
    $settings['daemonize'] = true;
}

// Server log file — explicit env wins; daemon mode picks a sensible default.
$serverLogFile = trim((string) getenv('ZEALPHP_SERVER_LOG_FILE'));
if ($serverLogFile === '' && $daemonize) {
    $serverLogFile = rtrim($logDir, '/') . '/server.log';
}
if ($serverLogFile !== '') {
    $serverLogDir = dirname($serverLogFile);
    if ($serverLogDir !== '.' && !is_dir($serverLogDir)) {
        @mkdir($serverLogDir, 0775, true);
    }
    $settings['log_file'] = $serverLogFile;
}

// Cross-coroutine signaling Store for error-handling integration tests.
// Created only when the test fixture is present so demo deployments stay clean.
if (file_exists(__DIR__ . '/route/_error_test.php')) {
    Store::make('error_test', 16, [
        'handler_fired'  => [Store::TYPE_INT, 1],
        'handler_cid'    => [Store::TYPE_INT, 8],
        'shutdown_count' => [Store::TYPE_INT, 1],
    ]);
}

// GitHub stargazer-count cache — renders the badge in the nav directly via
// PHP (no client-side fetch, no empty-number flicker on every page load).
// Refreshed every 15 minutes in a background coroutine; first-ever page load
// hits an empty cache and shows just the "★" until the refresh resolves.
GithubStars::register('sibidharan/zealphp');

// ── Coverage instrumentation (test-only; gated, inert in production) ──
// When ZEALPHP_COVERAGE_DIR is set and a coverage driver is active, collect
// src/ line coverage and dump a .cov per process. scripts/coverage_full.sh
// merges these with unit-test coverage so the long-running server loop counts.
// Coverage is started HERE, in the master, just before run() — so run()'s own
// body (event registration, route compilation, server settings) is captured,
// not just request handling. Workers inherit the started coverage via fork
// (copy-on-write) and dump on App::onWorkerStop; the master dumps on normal
// exit (after run() returns on graceful shutdown). All merged. Inert unless
// the env var is set.
if (($__covDir = getenv('ZEALPHP_COVERAGE_DIR')) !== false && $__covDir !== ''
    && class_exists(\SebastianBergmann\CodeCoverage\CodeCoverage::class)) {
    $__cov = null;
    $__filter = new \SebastianBergmann\CodeCoverage\Filter();
    $__rii = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator(__DIR__ . '/src', \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($__rii as $__f) {
        if ($__f->isFile() && $__f->getExtension() === 'php') {
            $__filter->includeFile($__f->getPathname());
        }
    }
    try {
        // Selector picks whatever driver is active (pcov locally, Xdebug in CI
        // under XDEBUG_MODE=coverage). If none, skip silently.
        $__driver = (new \SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($__filter);
        $__cov = new \SebastianBergmann\CodeCoverage\CodeCoverage($__driver, $__filter);
        $__cov->start('zealphp');
    } catch (\Throwable $e) {
        $__cov = null;
    }
    $__covDump = static function (string $tag) use (&$__cov, $__covDir): void {
        if ($__cov === null) {
            return;
        }
        try {
            $__cov->stop();
            $file = rtrim($__covDir, '/') . "/$tag-" . getmypid() . '.cov';
            (new \SebastianBergmann\CodeCoverage\Report\PHP())->process($__cov, $file);
            $__cov = null; // dump once per process
        } catch (\Throwable $e) {
            // never let a coverage dump abort shutdown
        }
    };
    // Worker processes (forked): dump request-handling coverage on stop.
    App::onWorkerStop(function ($server, $workerId) use ($__covDump): void {
        $__covDump("server-w$workerId");
    });
    // Master process: dump on normal exit — run() returns after graceful
    // shutdown, then PHP shutdown functions fire here. Captures run()'s body.
    register_shutdown_function(static function () use ($__covDump): void {
        $__covDump('master');
    });
}

$app->run($settings);
