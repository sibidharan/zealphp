<?php

declare(strict_types=1);

// Focused 2-phase federation smoke for v0.2.40 WS-2 + Cache C-3 + Counter N-2.
//
// Phase 1 (alice): JOIN clients, INC counter, SET tagged cache. Then exit.
// Phase 2 (bob):   READ size/members from same shared Valkey — should see
//                  alice's clients. Then INVALIDATE the tag — alice's keys
//                  go away (bob has the tag SET).
//
// Run alice first, wait for it to finish, then run bob from the OTHER host.

require __DIR__ . '/../vendor/autoload.php';

use ZealPHP\Cache;
use ZealPHP\Counter;
use ZealPHP\Store;
use ZealPHP\WSRouter;

$phase    = $argv[1] ?? 'alice';
$redisUrl = $argv[2] ?? (getenv('ZEALPHP_REDIS_URL') ?: 'redis://172.30.0.5:16379');

Store::defaultBackend(Store::BACKEND_REDIS, $redisUrl);
Counter::defaultBackend(Counter::BACKEND_REDIS, $redisUrl);
Cache::initForTest("/tmp/zealphp-fed-$phase", 1024);

$passes = 0; $fails = 0;
$check = function (string $label, bool $ok) use (&$passes, &$fails, $phase): void {
    echo "[$phase] " . ($ok ? '✓' : '✗') . " $label\n";
    $ok ? $passes++ : $fails++;
};

OpenSwoole\Runtime::enableCoroutine(true, OpenSwoole\Runtime::HOOK_ALL);
OpenSwoole\Coroutine::run(function () use ($phase, $check, &$passes, &$fails): void {
    WSRouter::init("fed-$phase");

    if ($phase === 'alice') {
        // Clean slate
        Store::sdel(WSRouter::roomMembersSetKey('fed-test'));
        Store::clear(WSRouter::roomTable());
        Store::sdel('__cache_tag:fed-shared-tag');

        // Phase 1: write cluster-wide state
        $r = WSRouter::room('fed-test');
        $r->join('alice-X');
        $r->join('alice-Y');
        $check("phase 1: own size = 2", $r->size() === 2);

        // Tagged cache entries
        Cache::set('fed:alice-doc-1', ['n' => 1], 0, ['fed-shared-tag']);
        Cache::set('fed:alice-doc-2', ['n' => 2], 0, ['fed-shared-tag']);

        // Counter to verify it persists
        $c = new Counter(0, 'fed-shared-counter');
        $c->set(0);
        $c->increment();
        $c->increment();
        $check("phase 1: counter = 2", $c->get() === 2);

        echo "[alice] cluster state written. Now run bob to verify it from the other host.\n";
        return;
    }

    // Phase 2 (bob): verify alice's state is visible
    $r = WSRouter::room('fed-test');
    $size = $r->size();
    $members = $r->members();
    sort($members);
    $check("phase 2: sees alice's clients via SCARD (size = 2)", $size === 2);
    $check("phase 2: members include alice-X + alice-Y", in_array('alice-X', $members, true) && in_array('alice-Y', $members, true));

    // Bob counters — should see alice's writes
    $c = new Counter(0, 'fed-shared-counter');
    $check("phase 2: counter from alice = 2", $c->get() === 2);

    // Now bob joins
    $r->join('bob-Z');
    $check("phase 2: after bob joins, size = 3", $r->size() === 3);

    // Bob invalidates the tag — alice's cache entries vanish cluster-wide
    $dropped = Cache::invalidateTag('fed-shared-tag');
    $check("phase 2: invalidateTag dropped alice's 2 docs", $dropped === 2);

    // Cleanup
    Store::sdel(WSRouter::roomMembersSetKey('fed-test'));
    Store::clear(WSRouter::roomTable());
    $c->reset();

    echo "[bob] cluster state read back. passes=$passes fails=$fails\n";
});
echo "[$phase] DONE — passes=$passes fails=$fails\n";
