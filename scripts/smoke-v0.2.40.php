<?php

declare(strict_types=1);

// Cross-host smoke for v0.2.40 — exercises every prod-hardening feature
// against a shared Valkey backend. Run on two hosts pointing at the same
// Redis URL; the script auto-detects "alice" vs "bob" via $argv[1].

require __DIR__ . '/../vendor/autoload.php';

use ZealPHP\App;
use ZealPHP\Cache;
use ZealPHP\Counter;
use ZealPHP\Store;
use ZealPHP\WSRouter;

$role     = $argv[1] ?? 'alice';
$redisUrl = $argv[2] ?? (getenv('ZEALPHP_REDIS_URL') ?: 'redis://172.30.0.5:16379');
$secret   = $argv[3] ?? null;   // optional HMAC secret

Store::defaultBackend(Store::BACKEND_REDIS, $redisUrl);
Counter::defaultBackend(Counter::BACKEND_REDIS, $redisUrl);
echo "[$role] Backend: " . Store::defaultBackend()::class . "\n";

OpenSwoole\Runtime::enableCoroutine(true, OpenSwoole\Runtime::HOOK_ALL);
OpenSwoole\Coroutine::run(function () use ($role, $secret): void {
    WSRouter::init("smoke-$role");
    if ($secret !== null) {
        WSRouter::setChannelHmacSecret($secret);
        echo "[$role] HMAC secret set\n";
    }

    $passes = 0; $fails = 0;
    $check = function (string $label, bool $ok) use (&$passes, &$fails, $role): void {
        echo "[$role] " . ($ok ? '✓' : '✗') . " $label\n";
        $ok ? $passes++ : $fails++;
    };

    // ─── N-1 — Counter setIfAbsent preserves existing value ───────────
    $cKey = 'smoke-n1-' . random_int(10000, 99999);
    $c1 = new Counter(0, $cKey);
    $c1->set(42);
    $c2 = new Counter(0, $cKey);
    $check("N-1 setIfAbsent preserves value (42 after re-construct)", $c2->get() === 42);
    $c2->reset();

    // ─── N-2 — bounded increment ──────────────────────────────────────
    $boundKey = 'smoke-n2-' . random_int(10000, 99999);
    $b = new Counter(0, $boundKey);
    $b->reset();
    $b->incrementBounded(1, 3);
    $b->incrementBounded(1, 3);
    $b->incrementBounded(1, 3);
    $r = $b->incrementBounded(1, 3);   // exceeds cap
    $check("N-2 incrementBounded caps at 3 (returns null)", $r === null && $b->get() === 3);
    $b->reset();

    // ─── N-4 — batch mincr ────────────────────────────────────────────
    Counter::mincr(['smoke-n4-a' => 0, 'smoke-n4-b' => 0]);
    $r = Counter::mincr(['smoke-n4-a' => 5, 'smoke-n4-b' => 3]);
    $check("N-4 mincr returns correct counts", isset($r['smoke-n4-a'], $r['smoke-n4-b']) && $r['smoke-n4-a'] >= 5 && $r['smoke-n4-b'] >= 3);

    // ─── S-1 — evalScript ─────────────────────────────────────────────
    $r = Store::evalScript("return ARGV[1]", [], ["pong-$role"]);
    $check("S-1 evalScript round-trip", $r === "pong-$role");

    // ─── S-2 — Store::compareAndSet ───────────────────────────────────
    Store::make('smoke-s2', 100, ['v' => [Store::TYPE_INT, 8]]);
    Store::set('smoke-s2', "k-$role", ['v' => 10]);
    $ok1 = Store::compareAndSet('smoke-s2', "k-$role", 'v', '10', '20');
    $check("S-2 compareAndSet matches and swaps", $ok1 && Store::get('smoke-s2', "k-$role", 'v') === 20);
    $ok2 = Store::compareAndSet('smoke-s2', "k-$role", 'v', '10', '999');
    $check("S-2 compareAndSet refuses stale", !$ok2 && Store::get('smoke-s2', "k-$role", 'v') === 20);

    // ─── S-3 — paginated iterate ──────────────────────────────────────
    Store::clear('smoke-s2');
    for ($i = 0; $i < 25; $i++) {
        Store::set('smoke-s2', "p$i", ['v' => $i]);
    }
    $next = '0'; $total = 0;
    do {
        $page = Store::iteratePaged('smoke-s2', $next, 7);
        $total += count($page['rows']);
        $next = $page['cursor'];
    } while ($next !== '0');
    $check("S-3 paginated walk drained 25 rows", $total === 25);
    Store::clear('smoke-s2');

    // ─── WS-2 — per-room SET (cross-host) ─────────────────────────────
    $room = WSRouter::room('smoke-shared');
    // Clean up any prior smoke state
    if ($role === 'alice') {
        Store::sdel(WSRouter::roomMembersSetKey('smoke-shared'));
        Store::clear(WSRouter::roomTable());
    }
    OpenSwoole\Coroutine::sleep(1);
    $room->join("$role-1");
    $room->join("$role-2");
    OpenSwoole\Coroutine::sleep(2);   // let peer's joins land
    $members = $room->members();
    sort($members);
    $check("WS-2 sees own 2 members joined", in_array("$role-1", $members, true) && in_array("$role-2", $members, true));
    $sz = $room->size();
    echo "[$role] room size = $sz; members = " . implode(',', $members) . "\n";

    // ─── WS-5 — close-code constants ──────────────────────────────────
    $check("WS-5 CLOSE_RATE_LIMITED constant", WSRouter::CLOSE_RATE_LIMITED === 4029);
    $check("WS-5 CLOSE_CAPACITY constant",     WSRouter::CLOSE_CAPACITY === 4013);

    // ─── WS-3 — HMAC sign/verify ──────────────────────────────────────
    if ($secret !== null) {
        $signed = WSRouter::signPayload('hello');
        $verified = WSRouter::verifyPayload($signed);
        $check("WS-3 HMAC round-trip", $verified === 'hello');
        $bad = json_encode(['v' => 1, 'hmac' => 'bad', 'payload' => 'hello']);
        $check("WS-3 HMAC rejects bad", WSRouter::verifyPayload($bad) === null);
    }

    // ─── C-1 — stampede gate ──────────────────────────────────────────
    Cache::initForTest("/tmp/zealphp-smoke-$role", 1024);
    $computes = 0;
    $compute = function () use (&$computes) { $computes++; return "v$computes"; };
    $cacheKey = 'smoke-stampede-' . random_int(1000, 9999);
    Cache::getOrCompute($cacheKey, $compute, 60);
    Cache::getOrCompute($cacheKey, $compute, 60);
    Cache::getOrCompute($cacheKey, $compute, 60);
    $check("C-1 stampede compute count = 1", $computes === 1);

    // ─── C-3 — tag invalidation ───────────────────────────────────────
    Cache::set("c3-$role:p", ['a' => 1], 0, ["c3-tag-$role"]);
    Cache::set("c3-$role:q", ['b' => 2], 0, ["c3-tag-$role"]);
    $dropped = Cache::invalidateTag("c3-tag-$role");
    $check("C-3 invalidateTag dropped 2", $dropped === 2);
    $check("C-3 keys gone after invalidate", Cache::get("c3-$role:p") === null);

    // ─── X-4 — App::stats ─────────────────────────────────────────────
    $s = App::stats();
    $check("X-4 stats keys present", isset($s['workers'], $s['store'], $s['cache'], $s['ws_router'], $s['memory'], $s['backends']));
    $check("X-4 store_kind = RedisBackend", ($s['backends']['store_kind'] ?? '') === 'RedisBackend');

    echo "\n[$role] SMOKE COMPLETE — passes=$passes fails=$fails\n";
    if ($role === 'bob') {
        Store::sdel(WSRouter::roomMembersSetKey('smoke-shared'));
        Store::clear(WSRouter::roomTable());
    }
});
