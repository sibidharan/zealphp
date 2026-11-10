<?php
// Sequential event loops on the io_uring reactor: does creating and tearing
// down a reactor leak rings (fds) until creation fails?
// php -d extension=<io_uring openswoole.so> repro-loop.php [iterations]
OpenSwoole\Util::setAio(['reactor_type' => OPENSWOOLE_IO_URING]);
$n = (int) ($argv[1] ?? 3000);
$rings = static fn(): int => count(array_filter(
    glob('/proc/self/fd/*') ?: [],
    static fn(string $fd): bool => str_contains((string) @readlink($fd), 'io_uring')
));
$fail = 0;
for ($i = 1; $i <= $n; $i++) {
    try {
        OpenSwoole\Coroutine::run(static function (): void { OpenSwoole\Coroutine::usleep(100); });
    } catch (Throwable $e) {
        $fail++;
        if ($fail === 1) echo "first failure at iteration $i: ", $e->getMessage(), "\n";
    }
    if ($i === 1 || $i % 100 === 0) echo "iteration $i: open io_uring fds=", $rings(), " failures=$fail\n";
}
