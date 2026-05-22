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
// Tracks filemtime of the main stylesheet (changes when styling changes); no
// git dependency (composer installs don't ship .git). Falls back to boot time
// so a missing file never kills startup.
if (!defined('ZEALPHP_ASSET_VERSION')) {
    $assetSource = __DIR__ . '/public/css/zealphp.css';
    define(
        'ZEALPHP_ASSET_VERSION',
        (string) (is_file($assetSource) ? filemtime($assetSource) : time())
    );
}

// Auto-build the API reference at /docs/api/ on first boot — downloads the
// phpDocumentor PHAR (33MB) if missing, then runs it against src/. One-time
// cost (~30-60 s including download); subsequent boots skip. Gated by argv
// so `php app.php stop|status|restart|logs` doesn't trigger a build.
$__cliSub = $argv[1] ?? 'start';
if (PHP_SAPI === 'cli' && !in_array($__cliSub, ['stop', 'status', 'logs', '--help', '-h'], true)) {
    $__docsIndex = __DIR__ . '/public/docs/api/index.html';
    if (!is_file($__docsIndex) && !env_flag('ZEALPHP_SKIP_DOCS_BUILD', false)) {
        $__phar = __DIR__ . '/tools/phpdoc.phar';
        if (!is_file($__phar)) {
            echo "[zealphp] First boot: downloading phpDocumentor PHAR (33 MB, one-time)...\n";
            if (!is_dir(__DIR__ . '/tools')) {
                mkdir(__DIR__ . '/tools', 0755, true);
            }
            $__url = 'https://github.com/phpDocumentor/phpDocumentor/releases/latest/download/phpDocumentor.phar';
            $__rc  = 0;
            passthru('curl -fsSL ' . escapeshellarg($__url) . ' -o ' . escapeshellarg($__phar), $__rc);
            if ($__rc !== 0 || !is_file($__phar)) {
                echo "[zealphp] phpDocumentor download failed (curl exit {$__rc}); /docs/api/ will show the fallback page. Set ZEALPHP_SKIP_DOCS_BUILD=1 to silence this.\n";
            }
        }
        if (is_file($__phar)) {
            echo "[zealphp] Building API docs at /docs/api/ (~30 s, one-time)...\n";
            $__cmd = sprintf(
                'php %s -d %s -t %s --title=%s --no-interaction',
                escapeshellarg($__phar),
                escapeshellarg(__DIR__ . '/src'),
                escapeshellarg(__DIR__ . '/public/docs/api'),
                escapeshellarg('ZealPHP API Reference')
            );
            $__rc = 0;
            passthru($__cmd, $__rc);
            if ($__rc !== 0) {
                echo "[zealphp] phpDocumentor build failed (exit {$__rc}); /docs/api/ will show the fallback page.\n";
            }
        }
    }
    unset($__cliSub, $__docsIndex, $__phar, $__url, $__cmd, $__rc);
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
    App::cgiMode($__cm === 'fork' ? 'fork' : 'proc');
}

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

// PID file resolution — explicit env wins; otherwise default under ZEALPHP_LOG_DIR.
$logDir  = trim((string) (getenv('ZEALPHP_LOG_DIR') ?: '/tmp/zealphp'));
$pidFile = trim((string) (getenv('ZEALPHP_PID_FILE') ?: rtrim($logDir, '/') . '/zealphp_' . $appPort . '.pid'));
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
        'handler_fired'  => [\OpenSwoole\Table::TYPE_INT, 1],
        'handler_cid'    => [\OpenSwoole\Table::TYPE_INT, 8],
        'shutdown_count' => [\OpenSwoole\Table::TYPE_INT, 1],
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
