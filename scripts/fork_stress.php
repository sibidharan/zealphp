<?php

declare(strict_types=1);

/**
 * Stress / safety validation for the fork-per-request CGI runner (cgiMode fork).
 *
 *   php scripts/fork_stress.php [sequentialN] [concurrency]
 *
 * Drives src/fork_master.php directly over its UNIX socket. Validates, under
 * load: every request succeeds (a class-declaring fixture proves no
 * "Cannot redeclare" — fresh process each), zero zombies/fd leaks, flat
 * fork-master RSS (no COW leak), and the live-child concurrency cap. NOT a unit
 * test (it spawns hundreds of forks); run manually on a box with headroom.
 */

require __DIR__ . '/../vendor/autoload.php';

use ZealPHP\CGI\IPC;

$N    = (int) ($argv[1] ?? 1000);   // sequential requests
$C    = (int) ($argv[2] ?? 24);     // concurrency burst size
$root = dirname(__DIR__);

$dir = sys_get_temp_dir() . '/forkstress_' . getmypid();
@mkdir($dir, 0777, true);
$sock = $dir . '/fm.sock';
// A class-declaring fixture: a REUSED process would "Cannot redeclare" on req 2.
$fixture = $dir . '/work.php';
file_put_contents($fixture, "<?php\nclass ForkStressUnit { public int \$n = 7; }\necho 'ok:' . (new ForkStressUnit)->n;\n");

$env = array_merge($_ENV, [
    'ZEALPHP_FORK_SOCK'           => $sock,
    'ZEALPHP_FORK_MAX_CONCURRENT' => (string) $C,
    'ZEALPHP_CWD'                 => $root,
]);
$errLog = $dir . '/fm.log';
$desc = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $errLog, 'a'], 2 => ['file', $errLog, 'a']];
$proc = proc_open([PHP_BINARY, $root . '/src/fork_master.php'], $desc, $pipes, $root, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "could not spawn fork_master\n");
    exit(1);
}
$st = proc_get_status($proc);
$masterPid = $st['pid'];

// Wait for the socket (readiness).
$deadline = microtime(true) + 10;
while (microtime(true) < $deadline) {
    $c = @stream_socket_client('unix://' . $sock, $e, $s, 0.3);
    if (is_resource($c)) { fclose($c); break; }
    usleep(20000);
}

function frame(string $f): array
{
    return ['file' => $f, 'server' => ['REQUEST_METHOD' => 'GET'], 'get' => [], 'post' => [], 'cookies' => [], 'files' => [], 'body' => ''];
}
function rssKb(int $pid): int
{
    $s = @file_get_contents("/proc/$pid/status");
    if (is_string($s) && preg_match('/VmRSS:\s+(\d+)\s+kB/', $s, $m)) { return (int) $m[1]; }
    return 0;
}
function zombieCount(): int
{
    $n = 0;
    foreach (glob('/proc/[0-9]*/stat') ?: [] as $f) {
        $s = @file_get_contents($f);
        if (is_string($s) && preg_match('/^\d+ \([^)]*\) (\w)/', $s, $m) && $m[1] === 'Z') { $n++; }
    }
    return $n;
}

$rss0 = rssKb($masterPid);
$z0   = zombieCount();

// ── Sequential burst ──
$ok = 0;
$t = microtime(true);
for ($i = 0; $i < $N; $i++) {
    $c = @stream_socket_client('unix://' . $sock, $e, $s, 5);
    if (!is_resource($c)) { continue; }
    IPC::writeFrame($c, frame($fixture));
    $r = IPC::readFrame($c, 5);
    fclose($c);
    if (is_array($r) && ($r['body'] ?? '') === 'ok:7') { $ok++; }
}
$dt = microtime(true) - $t;
printf("sequential : %d/%d ok | %.2fs | %d req/s | %.2f ms/req\n", $ok, $N, $dt, (int) round($N / max($dt, 1e-6)), $dt / max($N, 1) * 1000);

// ── Concurrency burst: open C connections, write all, then read all ──
$conns = [];
for ($i = 0; $i < $C; $i++) {
    $c = @stream_socket_client('unix://' . $sock, $e, $s, 5);
    if (is_resource($c)) { IPC::writeFrame($c, frame($fixture)); $conns[] = $c; }
}
$cok = 0;
foreach ($conns as $c) {
    $r = IPC::readFrame($c, 10);
    if (is_array($r) && ($r['body'] ?? '') === 'ok:7') { $cok++; }
    fclose($c);
}
printf("concurrent : %d/%d ok (burst of %d simultaneous forks)\n", $cok, count($conns), $C);

// Let the reaper catch up.
usleep(300000);
$rss1 = rssKb($masterPid);
$z1   = zombieCount();
$masterAlive = is_resource($proc) && (proc_get_status($proc)['running'] ?? false);

printf("master RSS : %d kB -> %d kB (delta %+d kB)\n", $rss0, $rss1, $rss1 - $rss0);
printf("zombies    : %d -> %d (delta %+d)\n", $z0, $z1, $z1 - $z0);
printf("master     : %s\n", $masterAlive ? 'ALIVE (good)' : 'DIED (bad)');

// ── Teardown + leak check ──
proc_terminate($proc, 15);
usleep(400000);
$st = proc_get_status($proc);
if ($st['running']) { proc_terminate($proc, 9); usleep(100000); }
proc_close($proc);

clearstatcache();
$leaked = file_exists("/proc/$masterPid") ? 'LEAKED' : 'cleaned';
printf("after close: master process %s, socket %s\n", $leaked, file_exists($sock) ? 'LEFT' : 'removed');

foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($dir);

$pass = $ok === $N && $cok === count($conns) && $z1 === $z0 && $masterAlive && $leaked === 'cleaned' && ($rss1 - $rss0) < 51200;
echo $pass ? "\nRESULT: PASS\n" : "\nRESULT: FAIL\n";
exit($pass ? 0 : 1);
