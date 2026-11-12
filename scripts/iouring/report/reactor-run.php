<?php
// Findings 2 and 4: run N event loops on the io_uring reactor.
// php reactor-run.php [loops] [setAio|coroutineSet]
$loops = (int) ($argv[1] ?? 1);
if (($argv[2] ?? 'setAio') === 'coroutineSet') {
    OpenSwoole\Coroutine::set(['reactor_type' => OPENSWOOLE_IO_URING]);
} else {
    OpenSwoole\Util::setAio(['reactor_type' => OPENSWOOLE_IO_URING]);
}
$rings = static fn(): int => count(array_filter(
    glob('/proc/self/fd/*') ?: [],
    static fn(string $fd): bool => str_contains((string) @readlink($fd), 'io_uring')
));
for ($i = 1; $i <= $loops; $i++) {
    OpenSwoole\Coroutine::run(static fn() => OpenSwoole\Coroutine::usleep(100));
    fprintf(STDERR, "RESULT loop %d ok, io_uring fds open after: %d\n", $i, $rings());
}
