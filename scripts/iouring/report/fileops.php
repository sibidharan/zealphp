<?php
// Finding 1: hooked file I/O in 32 coroutines. Prints time and result only;
// OpenSwoole's own warnings go wherever it writes them.
// php fileops.php [iterations]
$n = (int) ($argv[1] ?? 2000);
$dir = sys_get_temp_dir() . '/iou-' . getmypid();
@mkdir($dir);
OpenSwoole\Runtime::enableCoroutine(OpenSwoole\Runtime::HOOK_ALL);
$t = microtime(true);
$bad = 0;
OpenSwoole\Coroutine::run(function () use ($n, $dir, &$bad): void {
    $done = new OpenSwoole\Coroutine\Channel(32);
    for ($c = 0; $c < 32; $c++) {
        go(function () use ($c, $n, $dir, &$bad, $done): void {
            for ($i = $c; $i < $n; $i += 32) {
                $f = "$dir/f$i";
                $data = str_repeat(chr(65 + $i % 26), 4096);
                file_put_contents($f, $data);
                if (file_get_contents($f) !== $data) $bad++;
                $fh = fopen($f, 'r'); fstat($fh); fread($fh, 100); fclose($fh);
                rename($f, "$f.r"); unlink("$f.r");
            }
            $done->push(1);
        });
    }
    for ($c = 0; $c < 32; $c++) $done->pop();
});
@rmdir($dir);
fprintf(STDERR, "RESULT iterations=%d bad=%d elapsed=%.3fs\n", $n, $bad, microtime(true) - $t);
