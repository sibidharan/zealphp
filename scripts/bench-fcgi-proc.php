<?php

declare(strict_types=1);

/**
 * BENCH — Measures per-request latency of cgiMode('proc').
 *
 * `proc` mode spawns a fresh PHP CLI subprocess via proc_open() for every
 * request. There is no pool — each request pays the full PHP interpreter
 * cold-start cost. This benchmark uses the same fixture as bench-fcgi-pool.php
 * (`echo "ok";`) so the two are directly comparable.
 *
 * Usage:
 *   php scripts/bench-fcgi-proc.php [N=200]
 *
 * 200 iterations is the default — `proc` runs at ~50 req/s so 200 = ~4 s.
 * Don't crank N too high; if you want pool semantics, run bench-fcgi-pool.php.
 *
 * Companion script: scripts/bench-fcgi-pool.php (same fixture, warm pool).
 * Compare to verify the 200-300× gap that justifies pool as the default.
 */

$n = (int) ($argv[1] ?? 200);

$tmp = sys_get_temp_dir() . '/zealphp-bench-proc-' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($tmp, "<?php echo 'ok';");

echo "ZealPHP cgiMode(proc) — fresh proc_open per request\n";
echo "──────────────────────────────────────────────────\n";
echo "requests:  $n\n";
echo "fixture:   $tmp\n\n";

$latencies = [];
$errors    = 0;

$wallStart = microtime(true);
for ($i = 0; $i < $n; $i++) {
    $t0   = microtime(true);
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['php', $tmp], $desc, $pipes);
    if (!is_resource($proc)) {
        $errors++;
        continue;
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $dt = (microtime(true) - $t0) * 1000.0;
    $latencies[] = $dt;
    if ($out !== 'ok') {
        $errors++;
    }
}
$wallEnd = microtime(true);
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
printf("wall time:         %.0f ms\n", $wall_ms);
printf("throughput:        %.0f req/s\n", $n / ($wall_ms / 1000.0));
echo  "─ latency ─\n";
printf("  avg:             %.2f ms\n", $sum / $count);
printf("  min:             %.2f ms\n", $latencies[0]);
printf("  p50:             %.2f ms\n", $p(50.0));
printf("  p90:             %.2f ms\n", $p(90.0));
printf("  p99:             %.2f ms\n", $p(99.0));
printf("  max:             %.2f ms\n", $latencies[$count - 1]);

echo "\nCompare to scripts/bench-fcgi-pool.php — the warm-pool ratio is\n";
echo "the headline justification for cgiMode('pool') being the v0.2.41 default.\n";
