<?php

declare(strict_types=1);

/**
 * #285 spike — `RedisSessionHandler` save-handler ops outside a request coroutine.
 *
 * Under `App::superglobals(true)` WITHOUT `enableCoroutine(true)` the `onRequest`
 * handler is NOT auto-wrapped in a coroutine, yet `HOOK_ALL` still hooks `\Redis`.
 * The PHP session save-handler chain (`session_start()` -> `open()` -> `read()`,
 * later `session_write_close()` -> `write()` -> `close()`) then runs every hooked
 * `\Redis` call with `getCid() == -1`, fataling "API must be called in the
 * coroutine". #271 made the *constructor* lazy; #285 is the open()/read() sequel.
 *
 * PHPUnit can't enable `HOOK_ALL` process-wide, so this is the canonical
 * validation (same pattern as `scripts/spike-phpredis-subscribe.php`). It asserts:
 *   1. OLD behaviour — a bare `\Redis->connect()` outside a coroutine fatals
 *      (documented; not executed here so the spike can continue).
 *   2. NEW behaviour — a full open -> write -> read -> destroy cycle on the real
 *      handler works OUTSIDE a coroutine (the io() wrapper runs each op inside
 *      `Coroutine::run()` on the persistent $fallback connection).
 *   3. No regression — the same cycle works INSIDE a coroutine (per-coroutine
 *      socket, issue #16), and N concurrent coroutines never cross frames.
 *
 * Run: `php scripts/spike-session-handler-nocoroutine.php`
 * Needs ext-redis + a Redis reachable at 127.0.0.1:6379 (override with REDIS_HOST
 * / REDIS_PORT env). Validated on PHP 8.4 + OpenSwoole 26.2.0 + phpredis 6.3.
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Runtime;
use ZealPHP\Session\Handler\RedisSessionHandler;

if (!extension_loaded('redis')) {
    fwrite(STDERR, "SKIP: ext-redis not loaded.\n");
    exit(0);
}

$host = getenv('REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_PORT') ?: 6379);

// Reproduce the production runtime: superglobals(true)-style — HOOK_ALL on, but NO
// per-request coroutine wrapper. The save-handler chain therefore runs at cid == -1.
Runtime::enableCoroutine(Runtime::HOOK_ALL);

// Reachability probe (must itself run in a coroutine — connect is hooked).
$reachable = false;
Coroutine::run(function () use ($host, $port, &$reachable): void {
    try {
        $probe = new \Redis();
        if (@$probe->connect($host, $port, 0.5)) {
            $probe->ping();
            $probe->close();
            $reachable = true;
        }
    } catch (\Throwable) {
        $reachable = false;
    }
});
if (!$reachable) {
    fwrite(STDERR, "SKIP: Redis not reachable at {$host}:{$port}.\n");
    exit(0);
}

$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

// (2) NEW handler — full cycle OUTSIDE any coroutine (the #285 scenario).
echo "Outside a coroutine (cid=" . Coroutine::getCid() . "):\n";
$sid = 't285_' . bin2hex(random_bytes(4));
$h = new RedisSessionHandler($host, $port, 'SPIKE285:', 60);
$check('open() does not fatal', $h->open('', 'PHPSESSID') === true);
$check('write() round-trips', $h->write($sid, 'user_id|i:42;') === true);
$check('read() returns the written value', $h->read($sid) === 'user_id|i:42;');
$check('destroy() succeeds', $h->destroy($sid) === true);
$check('read-after-destroy is empty', $h->read($sid) === '');

// (3a) NEW handler — same cycle INSIDE a coroutine (per-coroutine path, issue #16).
echo "Inside a coroutine:\n";
Coroutine::run(function () use ($host, $port, $check): void {
    $sid = 't285c_' . bin2hex(random_bytes(4));
    $h = new RedisSessionHandler($host, $port, 'SPIKE285:', 60);
    $check('in-coroutine open()', $h->open('', 'PHPSESSID') === true);
    $h->write($sid, 'x|i:7;');
    $check('in-coroutine write+read', $h->read($sid) === 'x|i:7;');
    $h->destroy($sid);
});

// (3b) Concurrency — one shared handler, N coroutines, distinct sessions, zero cross-talk.
echo "Concurrent (shared handler, 30 coroutines):\n";
$n = 30;
$correct = 0;
$h2 = new RedisSessionHandler($host, $port, 'SPIKE285C:', 60);
Coroutine::run(function () use ($h2, $n, &$correct): void {
    $wg = new Channel($n);
    for ($i = 0; $i < $n; $i++) {
        go(function () use ($h2, $i, $wg, &$correct): void {
            $sid = "conc_$i";
            $val = "v|i:$i;";
            $h2->open('', 'PHPSESSID');
            $h2->write($sid, $val);
            usleep(10000); // hooked under HOOK_ALL — yields, maximising interleave
            if ($h2->read($sid) === $val) {
                $correct++;
            }
            $h2->destroy($sid);
            $wg->push(1);
        });
    }
    for ($i = 0; $i < $n; $i++) {
        $wg->pop(5.0);
    }
});
$check("all $n coroutines read back their OWN value", $correct === $n);

echo $fail === 0 ? "\nPASS — #285 handler is coroutine-safe in every context.\n" : "\nFAIL ($fail).\n";
exit($fail === 0 ? 0 : 1);
