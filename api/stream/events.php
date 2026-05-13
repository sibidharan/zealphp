<?php
/**
 * SSE via ZealAPI — GET /api/stream/events
 *
 * $this->response is the ZealPHP HTTP\Response wrapper.
 * Calling $this->response->sse() sets the streaming flag so
 * ResponseMiddleware skips output buffering and lets write() go
 * straight to the client.
 *
 * Usage:
 *   curl -N http://localhost:8080/api/stream/events
 *   EventSource: new EventSource('/api/stream/events')
 */

use OpenSwoole\Coroutine as co;

$events = function() {
    $response = $this->response;
    $response->sse(function($emit) {
        $emit(json_encode(['status' => 'connected', 'ts' => time()]), 'open');

        for ($i = 1; $i <= 10; $i++) {
            co::sleep(1);
            $emit(json_encode([
                'tick'    => $i,
                'ts'      => time(),
                'memory'  => round(memory_get_usage() / 1024) . ' KB',
            ]), 'tick', (string)$i);
        }

        $emit(json_encode(['status' => 'done']), 'done');
    });
};
