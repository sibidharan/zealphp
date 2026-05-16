<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use ZealPHP\App;
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

App::superglobals(false);

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

$app->run($settings);
