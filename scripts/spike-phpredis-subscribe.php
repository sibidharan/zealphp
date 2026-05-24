<?php

declare(strict_types=1);

/**
 * Phase 3 phpredis spike — re-runs the predis-side validation script
 * against the phpredis driver via RedisClient + ZEALPHP_REDIS_PREFER=phpredis.
 * Verifies the C-side SUBSCRIBE loop yields under HOOK_ALL inside a
 * dedicated coroutine.
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Runtime;
use ZealPHP\Store\PubSubStopException;
use ZealPHP\Store\RedisClient;

const REDIS_URL    = 'redis://127.0.0.1:16379/0';
const CHANNEL      = 'spike:phpredis-phase3';
const OPS_PER_COR  = 50;
const N_OP_CORS    = 8;
const N_PUBLISHES  = 10;
const PUBLISH_GAP  = 0.05;

Runtime::enableCoroutine(Runtime::HOOK_ALL);

Coroutine::run(function (): void {
    $started   = microtime(true);
    $opsDone   = 0;
    $opsTimes  = [];
    $rxTimes   = [];
    $rxCount   = 0;
    $subDone   = false;

    $opts = ['prefer' => 'phpredis'];

    go(function () use (&$rxTimes, &$rxCount, &$subDone, $opts): void {
        $sub = new RedisClient(REDIS_URL, $opts);
        echo "subscriber driver: " . $sub->driverName() . PHP_EOL;
        try {
            $sub->subscribe([CHANNEL], [], function (string $payload) use (&$rxTimes, &$rxCount): void {
                $sentAt = (float) $payload;
                $rxTimes[] = (microtime(true) - $sentAt) * 1000.0;
                $rxCount++;
                if ($rxCount >= N_PUBLISHES) {
                    throw new PubSubStopException();
                }
            });
        } catch (PubSubStopException) { /* normal */ }
        $subDone = true;
    });

    $opsDoneChan = new Channel(N_OP_CORS);
    for ($i = 0; $i < N_OP_CORS; $i++) {
        go(function () use ($i, &$opsDone, &$opsTimes, $opsDoneChan, $opts): void {
            $client = new RedisClient(REDIS_URL, $opts);
            $startCor = microtime(true);
            for ($j = 0; $j < OPS_PER_COR; $j++) {
                $client->hset("spike:ops:cor$i", ["j$j" => (string) $j]);
                $client->hgetall("spike:ops:cor$i");
                $opsDone++;
            }
            $client->del("spike:ops:cor$i");
            $opsTimes[$i] = (microtime(true) - $startCor) * 1000.0;
            $client->close();
            $opsDoneChan->push($i);
        });
    }

    go(function () use ($opts): void {
        $pub = new RedisClient(REDIS_URL, $opts);
        for ($k = 0; $k < N_PUBLISHES; $k++) {
            (new Channel(1))->pop(PUBLISH_GAP);
            $pub->publish(CHANNEL, (string) microtime(true));
        }
        $pub->close();
    });

    for ($i = 0; $i < N_OP_CORS; $i++) { $opsDoneChan->pop(30.0); }

    $waitStart = microtime(true);
    while (!$subDone && (microtime(true) - $waitStart) < 5.0) {
        (new Channel(1))->pop(0.05);
    }

    $totalMs = (microtime(true) - $started) * 1000.0;

    echo "\n=== Phase 3 phpredis spike (subscribe under HOOK_ALL) ===\n";
    echo "Wall clock: " . number_format($totalMs, 1) . " ms\n";
    echo "Total ops:  $opsDone (expected " . (N_OP_CORS * OPS_PER_COR) . ")\n";
    echo "Throughput: " . number_format($opsDone / ($totalMs / 1000), 0) . " ops/sec\n\n";

    sort($opsTimes);
    echo "Per-op-cor wall time (ms):  min=" . number_format(reset($opsTimes), 1) . "  median=" . number_format($opsTimes[(int) (count($opsTimes) / 2)], 1) . "  max=" . number_format(end($opsTimes), 1) . PHP_EOL;

    echo "Messages received: $rxCount / " . N_PUBLISHES . "\n";
    if ($rxCount > 0) {
        sort($rxTimes);
        echo "PUBLISH receive latency (ms):  min=" . number_format(reset($rxTimes), 2) . "  median=" . number_format($rxTimes[(int) (count($rxTimes) / 2)], 2) . "  max=" . number_format(end($rxTimes), 2) . PHP_EOL;
    }

    $allOpsRan   = $opsDone === N_OP_CORS * OPS_PER_COR;
    $allMsgsRcvd = $rxCount === N_PUBLISHES;
    $opsMedian   = $opsTimes[(int) (count($opsTimes) / 2)] ?? PHP_INT_MAX;
    $opsHealthy  = $opsMedian < 1000;
    $latHealthy  = ($rxCount > 0 && $rxTimes[(int) (count($rxTimes) / 2)] < 50);

    echo "\n=== VERDICT ===\n";
    echo "[" . ($allOpsRan   ? 'PASS' : 'FAIL') . "] All worker ops completed (" . $opsDone . "/" . (N_OP_CORS * OPS_PER_COR) . ")\n";
    echo "[" . ($allMsgsRcvd ? 'PASS' : 'FAIL') . "] Subscriber received all " . N_PUBLISHES . " messages\n";
    echo "[" . ($opsHealthy  ? 'PASS' : 'FAIL') . "] Median per-cor wall time < 1s\n";
    echo "[" . ($latHealthy  ? 'PASS' : 'FAIL') . "] Receive latency healthy (<50ms median)\n";

    if ($allOpsRan && $allMsgsRcvd && $opsHealthy && $latHealthy) {
        echo "\n-> phpredis SUBSCRIBE yields under HOOK_ALL. Phase 3 design ships on phpredis cleanly.\n";
    } else {
        echo "\n-> phpredis SUBSCRIBE does NOT integrate cleanly under HOOK_ALL; keep ZEALPHP_REDIS_PREFER=predis as production recommendation.\n";
    }
});
