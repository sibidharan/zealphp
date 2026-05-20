<?php
/**
 * Exercise the demo WebSocket endpoints so their onOpen/onMessage/onClose
 * dispatch closures are covered by the instrumented server. Coverage-only —
 * connects, sends a frame, reads, closes. Used by scripts/coverage_full.sh.
 *
 * Usage: php scripts/ws_exercise.php <port>
 */
declare(strict_types=1);

$port = isset($argv[1]) ? (int) $argv[1] : 8080;

$paths = [
    '/ws/echo',
    '/ws/broadcast',
    '/ws/ticker',
    '/ws/rooms?room=cov&uid=probe',
    '/ws/auth?token=secret',
    '/ws/auth',
    '/ws/binary',
];

\OpenSwoole\Coroutine::run(function () use ($port, $paths) {
    foreach ($paths as $path) {
        \OpenSwoole\Coroutine::create(function () use ($port, $path) {
            $cli = new \OpenSwoole\Coroutine\Http\Client('127.0.0.1', $port);
            $cli->set(['timeout' => 2.0]);
            try {
                if ($cli->upgrade($path)) {
                    $cli->push('{"type":"ping","data":"coverage"}');
                    $cli->recv(0.5);
                    $cli->push('hello');
                    $cli->recv(0.5);
                }
            } catch (\Throwable $e) {
                // coverage-only — a failed handshake still exercises onOpen
            } finally {
                $cli->close();
            }
        });
    }
});
echo "ws exercise done\n";
