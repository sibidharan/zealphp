<?php
/**
 * SSE from a public/ file — proves the "any routing style" claim.
 *
 * Demonstrates that the SSE primitive works identically across all three
 * routing styles (public/, api/, route/). Same code, different file location.
 *
 * GET /learn/sse-demo  → emits 5 tick events, one per second.
 */

use ZealPHP\RequestContext;
use OpenSwoole\Coroutine as co;

$response = RequestContext::instance()->zealphp_response;

$response->sse(function ($emit) {
    $emit(json_encode(['status' => 'connected', 'from' => 'public/']), 'open');
    for ($i = 1; $i <= 5; $i++) {
        co::sleep(1);
        $emit(json_encode(['tick' => $i, 'ts' => time()]), 'tick', (string) $i);
    }
    $emit(json_encode(['status' => 'done']), 'done');
});
