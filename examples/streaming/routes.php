<?php
/**
 * ZealPHP SSR Streaming Examples
 * Auto-loaded from route/ — all routes live under /examples/*
 *
 * Endpoints:
 *   GET /examples/generator-ssr      — Generator-based SSR streaming
 *   GET /examples/stream             — stream() callback
 *   GET /examples/sse                — Server-Sent Events (curl -N)
 *   GET /examples/sse-client         — Browser page for the SSE demo
 *   GET /examples/render-to-string   — renderToString() with streaming
 */

use OpenSwoole\Coroutine as co;
use OpenSwoole\Coroutine\Channel;
use ZealPHP\App;
use ZealPHP\G;

// ---------------------------------------------------------------------------
// 1. Generator SSR
//    Route handler is a plain generator function.  ZealPHP streams each
//    yielded string directly to the client as it becomes available.
// ---------------------------------------------------------------------------
$app->route('/examples/generator-ssr', function() {
    return include __DIR__ . '/generator_ssr.php';
});

// ---------------------------------------------------------------------------
// 2. stream() callback
//    Direct access to the write closure — useful when you need fine-grained
//    control over what goes on the wire.
// ---------------------------------------------------------------------------
$app->route('/examples/stream', function($response) {
    include __DIR__ . '/stream_callback.php';
});

// ---------------------------------------------------------------------------
// 3. Server-Sent Events
// ---------------------------------------------------------------------------
$app->route('/examples/sse', function($response) {
    include __DIR__ . '/sse_events.php';
});

// ---------------------------------------------------------------------------
// 4. SSE browser client (static HTML page)
// ---------------------------------------------------------------------------
$app->route('/examples/sse-client', function() {
    header('Content-Type: text/html');
    echo file_get_contents(__DIR__ . '/sse_client.html');
});

// ---------------------------------------------------------------------------
// 5. renderToString() + streaming
// ---------------------------------------------------------------------------
$app->route('/examples/render-to-string', function($response) {
    return include __DIR__ . '/render_to_string.php';
});
