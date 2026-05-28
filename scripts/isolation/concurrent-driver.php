<?php

declare(strict_types=1);

/**
 * Fires N concurrent /probe?x=reqI against a probe-server.php instance and
 * prints the per-primitive isolation verdict. Usage: php concurrent-driver.php <port> [n]
 */
$port = (int)($argv[1] ?? 9820);
$n    = (int)($argv[2] ?? 40);
$mh = curl_multi_init();
$h = [];
for ($i = 0; $i < $n; $i++) {
    $ch = curl_init("http://127.0.0.1:$port/probe?x=req$i");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_multi_add_handle($mh, $ch);
    $h[$i] = ['ch' => $ch, 'x' => "req$i"];
}
do { $s = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 0.2); } while ($run > 0 && $s == CURLM_OK);

$keys = ['$_GET','$_POST','$_REQUEST','$_COOKIE','$_FILES','$_SERVER','$_SESSION',
         'class_static','$GLOBALS','constant','ini_set','bootstrap','fn_static','putenv',
         'resp_header','resp_cookie'];
$leak = array_fill_keys($keys, 0);
$ok = $err = $peak = 0;
foreach ($h as $i => $e) {
    $raw = (string) curl_multi_getcontent($e['ch']);
    curl_multi_remove_handle($mh, $e['ch']);
    [$hdr, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $d = json_decode($body, true);
    if (!is_array($d) || !isset($d['iso'])) { $err++; continue; }
    $peak = max($peak, (int) ($d['maxc'] ?? 0));
    foreach ($d['iso'] as $k => $v) { if (!$v) $leak[$k]++; }
    if (!preg_match('/^X-TB:\s*' . preg_quote($e['x'], '/') . '\s*$/mi', $hdr)) $leak['resp_header']++;
    if (!preg_match('/^Set-Cookie:\s*tbc=' . preg_quote($e['x'], '/') . '/mi', $hdr)) $leak['resp_cookie']++;
    $ok++;
}
curl_multi_close($mh);
echo "peak_concurrency=$peak  ok=$ok  errors=$err  / $n\n";
foreach ($keys as $k) printf("  %-16s %s\n", $k, $leak[$k] === 0 ? 'ISOLATED' : "LEAKS($leak[$k])");
