<?php

declare(strict_types=1);

/**
 * Behavioural fixture server for CoroutineLegacyBehaviorTest.
 *
 * Unlike the trust-bar fixture (which asserts per-primitive ISOLATION FLAGS),
 * this server exposes endpoints that reproduce the BUGS coroutine-legacy mode
 * actually shipped — so the test can assert correct end-to-end BEHAVIOUR under
 * real concurrency:
 *
 *   /inputs        request-input cross-client MISROUTE — each concurrent request
 *                  carries distinct $_GET/$_POST/$_COOKIE, yields mid-handler,
 *                  then re-reads them; the response must reflect ITS OWN inputs.
 *   /global-yield  global-assign-across-yield (the `global $wpdb; $wpdb = new
 *                  wpdb()` pattern, ctor yields on connect) — the assignment must
 *                  survive the yield and stay this request's value.
 *   /cold          cold class-with-inheritance instantiated under the first
 *                  concurrent wave — preloaded at boot, so it must be LINKED and
 *                  never 500 with "Class not found"/duplicate-CE.
 *   /contract      the universal return contract (int=status, array=JSON,
 *                  string=body, Generator=stream) under coroutine-legacy.
 *
 * Run as:  php -d extension=<zealphp.so> <this> <port> [workers]
 */
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use ZealPHP\App;

$port    = (int)($argv[1] ?? 9830);
$workers = (int)($argv[2] ?? 2);

// ── Cold-autoload corpus: a base + N children with inheritance, declared in
// their own files, resolved by an spl autoloader (composer-style on-demand).
// preloadDir() warms them in the MASTER before fork, so they fork in LINKED —
// the documented fix for the cold-concurrent-autoload unlink race.
$coldDir = sys_get_temp_dir() . '/zcl_behavior_' . getmypid();
@mkdir($coldDir);
file_put_contents($coldDir . '/ColdBase.php',
    "<?php\nnamespace ZCL;\nclass ColdBase { public function kind() { return 'base'; } }\n");
for ($i = 0; $i < 64; $i++) {
    file_put_contents($coldDir . "/ColdChild$i.php",
        "<?php\nnamespace ZCL;\nclass ColdChild$i extends ColdBase { public function who() { return 'child$i'; } }\n");
}
spl_autoload_register(function (string $class) use ($coldDir): void {
    if (str_starts_with($class, 'ZCL\\')) {
        $f = $coldDir . '/' . substr($class, 4) . '.php';
        if (is_file($f)) {
            require $f;
        }
    }
});

// A constructor that YIELDS — mirrors wpdb connecting to MySQL under HOOK_ALL.
class YieldingCtor
{
    public string $value = 'UNSET';
    public function __construct(string $x)
    {
        $ch = new OpenSwoole\Coroutine\Channel(1);
        OpenSwoole\Timer::after(20, fn() => $ch->push(1));
        $ch->pop(2.0);            // yield mid-construction
        $this->value = $x;        // the assignment the global must keep
    }
}

App::mode(App::MODE_COROUTINE_LEGACY);
App::defineIsolation(true);
App::preloadDir($coldDir);                       // warm the cold corpus at boot
App::preloadClasses(YieldingCtor::class);        // exercise the explicit-class warm path

$app = App::init('127.0.0.1', $port);

/** Yield the current coroutine so concurrent requests genuinely interleave. */
$interleave = function (int $ms = 30): void {
    $ch = new OpenSwoole\Coroutine\Channel(1);
    OpenSwoole\Timer::after($ms, fn() => $ch->push(1));
    $ch->pop(3.0);
};

// ── /inputs — request-input misroute regression. Distinct GET/POST/COOKIE per
// request, yield, then re-read the REAL superglobals.
$app->route('/inputs', ['methods' => ['GET', 'POST']], function ($request) use ($interleave) {
    $interleave(30);
    return [
        'get'    => $_GET['v'] ?? null,
        'post'   => $_POST['v'] ?? null,
        'cookie' => $_COOKIE['v'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    ];
});

// ── /global-yield — global $x = new <ctor-that-yields>; must survive the yield.
$app->route('/global-yield', function ($request) use ($interleave) {
    $g = \ZealPHP\G::instance();
    $x = (string)($g->get['x'] ?? 'NONE');
    $extra = (string)($g->get['extra'] ?? '0') === '1';
    global $zcl_global;
    $zcl_global = new YieldingCtor($x);   // ctor yields; assignment must land
    if ($extra) {
        $interleave(15);                   // optional extra yield AFTER the assign
    }
    return ['gv' => ($zcl_global->value ?? 'LOST'), 'x' => $x];
});

// ── /cold — cold class-with-inheritance under concurrency (preloaded -> linked).
$app->route('/cold', function ($request) use ($interleave) {
    $g = \ZealPHP\G::instance();
    $n = (int)($g->get['n'] ?? 0);
    $interleave(20);
    $cls = 'ZCL\\ColdChild' . $n;
    $o = new $cls();
    return ['who' => $o->who(), 'kind' => $o->kind()];
});

// ── /contract — universal return contract under coroutine-legacy.
$app->route('/contract', function ($request) {
    $g = \ZealPHP\G::instance();
    $kind = (string)($g->get['kind'] ?? 'json');
    $x = (string)($g->get['x'] ?? 'v');
    return match ($kind) {
        'int'  => 404,
        'str'  => "BODY:$x",
        'gen'  => (function () use ($x) {
            yield "chunk1:$x;";
            yield "chunk2:$x;";
        })(),
        default => ['x' => $x, 'ok' => true],
    };
});

$app->route('/ping', fn() => ['ok' => true]);
$app->run(['worker_num' => $workers, 'task_worker_num' => 0, 'log_level' => 5]);
