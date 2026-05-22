<?php
use OpenSwoole\Coroutine as Co;
use OpenSwoole\Runtime;
require __DIR__ . '/../../vendor/autoload.php';
Runtime::enableCoroutine(Runtime::HOOK_ALL);
Co::run(function () {
    $progressed = 0;
    Co::create(function () use (&$progressed) {        // concurrency probe
        for ($i = 0; $i < 20; $i++) { Co::sleep(0.05); $progressed++; }
    });
    $py = trim((string)shell_exec('command -v python3')) ?: '/usr/bin/python3';
    $cmd = $py . ' ' . escapeshellarg(__DIR__ . '/cgi.py');
    $p = proc_open($cmd, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, __DIR__);
    fwrite($pipes[0], 'POSTDATA'); fclose($pipes[0]);
    $chunks = [];
    $inBody = false;  // CGI: header block ends at first blank line, body follows
    while (!feof($pipes[1])) {
        $line = fgets($pipes[1]);
        if ($line === false) continue;
        $t = trim($line);
        if (!$inBody) { if ($t === '') $inBody = true; continue; }
        if ($t !== '') $chunks[] = $t;
    }
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    echo "probe_progressed_during_subprocess=$progressed (expect >0 if non-blocking)\n";
    echo "got_post=" . (strpos(implode("\n",$chunks),"POSTDATA")!==false ? 'YES':'NO') . "\n";
    echo "chunk_count=" . count($chunks) . "\n";
});
