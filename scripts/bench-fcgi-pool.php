<?php

declare(strict_types=1);

/**
 * SPIKE BENCH — Measures per-request latency of the native FCGI-style worker pool.
 *
 * Usage:
 *   php scripts/bench-fcgi-pool.php [N=1000] [size=4] [maxReq=500]
 *
 * Target acceptance: p50 < 5 ms, p99 < 15 ms on a fresh box.
 * Compares against fork mode's published 814 req/s (≈ 1.2 ms per request).
 *
 * The benchmark writes a single trivial fixture file (`echo "ok";`) then
 * dispatches N requests through a pool of `size` workers. No HTTP, no
 * framework — just the pool itself. This isolates the pool's per-request
 * overhead from everything else.
 *
 * Run from repo root:
 *   php scripts/bench-fcgi-pool.php 2000 8
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use ZealPHP\CGI\WorkerPool;

$n      = (int) ($argv[1] ?? 1000);
$size   = (int) ($argv[2] ?? 4);
$maxReq = (int) ($argv[3] ?? 500);

$tmp = sys_get_temp_dir() . '/zealphp-bench-pool-' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($tmp, "<?php echo 'ok';");

echo "ZealPHP FCGI worker pool — spike bench\n";
echo "─────────────────────────────────────\n";
echo "requests:  $n\n";
echo "pool size: $size\n";
echo "max/worker: $maxReq\n";
echo "fixture:   $tmp\n\n";

$pool = new WorkerPool(size: $size, maxRequestsPerWorker: $maxReq);

$latencies = [];
$errors    = 0;

$wallStart = microtime(true);
for ($i = 0; $i < $n; $i++) {
    $t0   = microtime(true);
    $resp = $pool->dispatch(['file' => $tmp]);
    $dt   = (microtime(true) - $t0) * 1000.0; // ms
    $latencies[] = $dt;
    if (($resp['body'] ?? '') !== 'ok') {
        $errors++;
    }
}
$wallEnd = microtime(true);

$pool->close();
@unlink($tmp);

sort($latencies);
$count = count($latencies);
$p = static function (float $pct) use ($latencies, $count): float {
    $idx = (int) floor(($pct / 100.0) * ($count - 1));
    return $latencies[$idx];
};

$sum     = array_sum($latencies);
$wall_ms = ($wallEnd - $wallStart) * 1000.0;

printf("requests handled:  %d  (errors: %d)\n", $count, $errors);
printf("wall time:         %.1f ms\n", $wall_ms);
printf("throughput:        %.0f req/s\n", $n / ($wall_ms / 1000.0));
echo  "─ latency ─\n";
printf("  avg:             %.3f ms\n", $sum / $count);
printf("  min:             %.3f ms\n", $latencies[0]);
printf("  p50:             %.3f ms\n", $p(50.0));
printf("  p90:             %.3f ms\n", $p(90.0));
printf("  p99:             %.3f ms\n", $p(99.0));
printf("  max:             %.3f ms\n", $latencies[$count - 1]);

echo "\nTarget: p50 < 5ms, p99 < 15ms.\n";
if ($p(50.0) < 5.0 && $p(99.0) < 15.0) {
    echo "PASS — spike validates the architecture.\n";
    exit(0);
}
echo "TARGET MISS — investigate before promoting to production.\n";
exit(1);
