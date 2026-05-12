<?php
/**
 * stream() Callback Example
 *
 * $response->stream(callable $fn) gives you a $write(string $chunk) closure.
 * Headers are flushed before $fn is called, so the browser starts receiving
 * data immediately.  Use co::sleep() or channel ops between writes to yield
 * control back to the event loop.
 *
 * curl -N --no-buffer http://localhost:8080/examples/stream
 */

use OpenSwoole\Coroutine as co;
use OpenSwoole\Coroutine\Channel;

$response->stream(function($write) {
    $write(<<<HTML
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <title>ZealPHP stream() Example</title>
      <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; line-height: 1.8; }
        .badge { display:inline-block; background:#fff0d0; color:#630; border-radius:4px; padding:2px 8px; font-size:.8rem; }
        span.word { opacity: 0; animation: fadein .3s forwards; }
        @keyframes fadein { to { opacity:1; } }
      </style>
    </head>
    <body>
      <h1>ZealPHP SSR Streaming <span class="badge">stream()</span></h1>
      <p>Words stream in one at a time with a 200 ms gap between each:</p>
      <p>
    HTML);

    // Stream words one by one — each write() reaches the browser immediately
    $words = explode(' ', 'The quick brown fox jumps over the lazy dog and demonstrates true SSR streaming in ZealPHP powered by OpenSwoole coroutines.');
    foreach ($words as $i => $word) {
        $delay = $i * 0.2;   // stagger: word i arrives at i*200ms
        co::sleep(0.2);
        $write("<span class=\"word\" style=\"animation-delay:{$delay}s\">$word </span>");
    }

    $write('</p>');

    // Parallel fetch demo inside stream()
    $write('<hr><h2>Parallel fetch inside stream()</h2>');

    $ch = new Channel(3);
    go(fn() => $ch->push(['label' => 'Fast (0.5s)',   'delay' => 0.5]));
    go(fn() => $ch->push(['label' => 'Medium (1.0s)', 'delay' => 1.0]));
    go(fn() => $ch->push(['label' => 'Slow (1.5s)',   'delay' => 1.5]));

    // simulate the actual delays
    foreach ([[0.5, 'Fast'], [1.0, 'Medium'], [1.5, 'Slow']] as [$sleep, $label]) {
        co::sleep($sleep);
    }

    for ($i = 0; $i < 3; $i++) {
        $item = $ch->pop();
        $write("<p>✓ <strong>{$item['label']}</strong> resolved</p>");
    }

    $write('<p><em>Stream complete.</em></p></body></html>');
});
