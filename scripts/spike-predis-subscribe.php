<?php

declare(strict_types=1);

/**
 * Phase 3 spike — does predis SUBSCRIBE yield under HOOK_ALL inside a
 * dedicated coroutine, allowing other coroutines on the same worker to
 * keep making progress?
 *
 * The Phase 3 design depends on this. SUBSCRIBE monopolizes a connection
 * (the predis client sits in a read-loop). If under HOOK_ALL the read
 * yields when no data is available, other coroutines run normally and the
 * design ships unchanged. If it blocks the whole worker, the subscriber
 * connection has to fall back to OpenSwoole\Coroutine\Redis (when built)
 * or be redesigned.
 *
 * Setup: one dedicated subscriber coroutine + N concurrent ops coroutines
 * hitting the pool. A separate publisher fires PUBLISHes at fixed
 * intervals. We measure (a) how many ops the worker cors complete while
 * the subscriber is active, and (b) the publish-to-receive latency.
 *
 * Run with: php scripts/spike-predis-subscribe.php
 * Requires: valkey-server up on 127.0.0.1:16379 (make valkey-up).
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Runtime;
use Predis\Client as PredisClient;

const REDIS_URL    = 'redis://127.0.0.1:16379/0';
const CHANNEL      = 'spike:phase3';
const OPS_PER_COR  = 50;
const N_OP_CORS    = 8;
const N_PUBLISHES  = 10;
const PUBLISH_GAP  = 0.05;  // seconds between publishes

Runtime::enableCoroutine(Runtime::HOOK_ALL);

Coroutine::run(function (): void {
    $started   = microtime(true);
    $opsDone   = 0;
    $opsTimes  = [];          // per-cor wall-clock to finish 50 ops
    $rxTimes   = [];          // publish-to-receive latency per message
    $rxCount   = 0;
    $subDone   = false;

    // Dedicated subscriber coroutine: monopolizes ONE predis connection,
    // sits in subscribe(), receives messages, records latency, unsubs at end.
    go(function () use (&$rxTimes, &$rxCount, &$subDone): void {
        $sub = new PredisClient(REDIS_URL, ['read_write_timeout' => -1]);
        $stop = false;
        // predis 2.x: pubSubLoop returns a consumer; iterating it reads frames
        $loop = $sub->pubSubLoop();
        $loop->subscribe(CHANNEL);
        foreach ($loop as $msg) {
            if ($msg->kind === 'message') {
                $sentAt = (float) $msg->payload;
                $rxTimes[] = (microtime(true) - $sentAt) * 1000.0;  // ms
                $rxCount++;
                if ($rxCount >= N_PUBLISHES) {
                    $loop->unsubscribe();
                    break;
                }
            }
        }
        $subDone = true;
    });

    // N "request worker" coroutines: each does OPS_PER_COR predis ops via
    // its own client. Measures whether the subscriber starves them.
    $opsDoneChan = new Channel(N_OP_CORS);
    for ($i = 0; $i < N_OP_CORS; $i++) {
        go(function () use ($i, &$opsDone, &$opsTimes, $opsDoneChan): void {
            $client = new PredisClient(REDIS_URL);
            $startCor = microtime(true);
            for ($j = 0; $j < OPS_PER_COR; $j++) {
                $client->hset("spike:ops:cor$i", "j$j", (string) $j);
                $client->hgetall("spike:ops:cor$i");
                $opsDone++;
            }
            $client->del("spike:ops:cor$i");
            $opsTimes[$i] = (microtime(true) - $startCor) * 1000.0;
            $client->disconnect();
            $opsDoneChan->push($i);
        });
    }

    // Publisher coroutine: fires N timed PUBLISHes with a sleep gap so we
    // can measure receive latency without flooding.
    go(function (): void {
        $pub = new PredisClient(REDIS_URL);
        for ($k = 0; $k < N_PUBLISHES; $k++) {
            // Yield ~PUBLISH_GAP sec without blocking the worker.
            (new Channel(1))->pop(PUBLISH_GAP);
            $pub->publish(CHANNEL, (string) microtime(true));
        }
        $pub->disconnect();
    });

    // Wait for all op cors to finish.
    for ($i = 0; $i < N_OP_CORS; $i++) { $opsDoneChan->pop(30.0); }

    // Wait for subscriber to receive everything (with timeout).
    $waitStart = microtime(true);
    while (!$subDone && (microtime(true) - $waitStart) < 5.0) {
        (new Channel(1))->pop(0.05);
    }

    $totalMs = (microtime(true) - $started) * 1000.0;

    // ── Report ────────────────────────────────────────────────────────
    echo "=== Phase 3 spike (predis SUBSCRIBE under HOOK_ALL) ===\n";
    echo "Coroutines: 1 subscriber + N_OP_CORS op-workers + 1 publisher\n";
    echo "Each op-worker: OPS_PER_COR HSET+HGETALL ops (" . (OPS_PER_COR * 2) . " round-trips)\n";
    echo "Publisher: N_PUBLISHES PUBLISH @ " . (PUBLISH_GAP * 1000) . "ms gap\n\n";

    echo "Wall clock: " . number_format($totalMs, 1) . " ms\n";
    echo "Total ops:  $opsDone (expected " . (N_OP_CORS * OPS_PER_COR) . ")\n";
    echo "Throughput: " . number_format($opsDone / ($totalMs / 1000), 0) . " ops/sec aggregate\n\n";

    echo "Per-op-cor wall time (ms):\n";
    sort($opsTimes);
    foreach ($opsTimes as $i => $t) {
        echo "  cor #$i: " . number_format($t, 1) . " ms\n";
    }
    $opsMedian = $opsTimes[(int) (count($opsTimes) / 2)];
    echo "  median: " . number_format($opsMedian, 1) . " ms\n\n";

    echo "Messages received: $rxCount / " . N_PUBLISHES . "\n";
    if ($rxCount > 0) {
        sort($rxTimes);
        $latMedian = $rxTimes[(int) (count($rxTimes) / 2)];
        $latP95    = $rxTimes[(int) (count($rxTimes) * 0.95)] ?? end($rxTimes);
        echo "PUBLISH → receive latency:\n";
        echo "  min:    " . number_format($rxTimes[0], 2) . " ms\n";
        echo "  median: " . number_format($latMedian, 2) . " ms\n";
        echo "  p95:    " . number_format($latP95, 2) . " ms\n";
        echo "  max:    " . number_format(end($rxTimes), 2) . " ms\n";
    }

    echo "\n=== VERDICT ===\n";
    $allOpsRan      = $opsDone === N_OP_CORS * OPS_PER_COR;
    $allMsgsRcvd    = $rxCount === N_PUBLISHES;
    $opsNotStarved  = $opsMedian < 1000;       // 50 ops should finish well under 1s
    $latencyHealthy = ($rxCount === 0) ? false : ($rxTimes[(int) (count($rxTimes) / 2)] < 50);

    echo "[" . ($allOpsRan      ? 'PASS' : 'FAIL') . "] All worker ops completed (no starvation)\n";
    echo "[" . ($allMsgsRcvd    ? 'PASS' : 'FAIL') . "] Subscriber received all " . N_PUBLISHES . " messages\n";
    echo "[" . ($opsNotStarved  ? 'PASS' : 'FAIL') . "] Median per-cor wall time < 1s (= cors actually ran concurrently)\n";
    echo "[" . ($latencyHealthy ? 'PASS' : 'FAIL') . "] Receive latency healthy (<50ms median)\n";

    if ($allOpsRan && $allMsgsRcvd && $opsNotStarved && $latencyHealthy) {
        echo "\n→ predis SUBSCRIBE yields under HOOK_ALL. The Phase 3 dedicated-subscriber-cor design is viable on predis without falling back to OpenSwoole\\Coroutine\\Redis.\n";
    } else {
        echo "\n→ predis SUBSCRIBE does NOT play well under HOOK_ALL — needs OpenSwoole\\Coroutine\\Redis (or a phpredis re-run) for the subscriber connection.\n";
    }
});
