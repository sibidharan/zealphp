<?php
/**
 * SSE via ZealAPI — GET /api/stream/events
 *
 * Standard ZealAPI closure pattern: assign a closure to a variable named
 * after the file ($events for events.php) and take $request/$response via
 * parameter injection — same convention as $get/$post/etc. handler files.
 *
 * Usage:
 *   curl -N http://localhost:8080/api/stream/events
 *   EventSource: new EventSource('/api/stream/events')
 */

use OpenSwoole\Coroutine as co;

${basename(__FILE__, '.php')} = function ($request, $response) {
    $response->sse(function ($emit) {
        $emit(json_encode(['status' => 'connected', 'ts' => time()]), 'open');

        for ($i = 1; $i <= 10; $i++) {
            co::sleep(1);
            $emit(json_encode([
                'tick'    => $i,
                'ts'      => time(),
                'memory'  => round(memory_get_usage() / 1024) . ' KB',
            ]), 'tick', (string) $i);
        }

        $emit(json_encode(['status' => 'done']), 'done');
    });
};
