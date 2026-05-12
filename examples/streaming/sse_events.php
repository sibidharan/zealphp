<?php
/**
 * Server-Sent Events (SSE) Example
 *
 * $response->sse(callable $fn) sets the correct headers (text/event-stream,
 * no-cache, X-Accel-Buffering: no) and gives you an $emit() closure:
 *
 *   $emit(string $data, string $event = '', string $id = '')
 *
 * Each $emit() call sends one SSE message on the wire.
 * The browser's EventSource API receives it automatically.
 *
 * Test with curl:
 *   curl -N http://localhost:8080/examples/sse
 *
 * Or open the browser client:
 *   http://localhost:8080/examples/sse-client
 */

use OpenSwoole\Coroutine as co;

$response->sse(function($emit) {
    // Opening handshake
    $emit('ZealPHP SSR streaming started', 'open');

    // Tick every second for 10 seconds
    for ($i = 1; $i <= 10; $i++) {
        co::sleep(1);
        $emit(
            json_encode([
                'tick'    => $i,
                'time'    => date('H:i:s'),
                'message' => "Tick $i of 10",
            ]),
            'tick',   // event name — JS: source.addEventListener('tick', ...)
            (string)$i  // event id — allows resume after reconnect
        );
    }

    // Closing event — JS side can close the EventSource on this
    $emit(json_encode(['message' => 'Stream complete']), 'done');
});
