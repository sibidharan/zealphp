<?php use ZealPHP\App; $active = $active ?? 'learn/streaming'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 11,
      'title'    => 'Streaming Done Right',
      'subtitle' => 'Streaming is the difference between handing someone a 1 GB file and turning on a faucet.',
      'prev'     => ['slug' => 'learn/middleware', 'title' => 'Middleware: The Wrap'],
      'next'     => ['slug' => 'learn/store',      'title' => 'Sharing State'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'The four streaming patterns ZealPHP supports — and when to reach for each',
      'Why streaming feels different in a persistent-process runtime',
      'How to wire up a Generator handler for HTML-on-the-fly',
      'When to use Server-Sent Events vs WebSocket vs plain chunked streaming',
    ]]); ?>

    <h2>Why streaming matters more here</h2>
    <p>
      On Apache or PHP-FPM, streaming usually means "flush the output buffer and hope php-fpm
      doesn’t hold it." It works, sort of. The catch: the worker is locked to your request
      until the script finishes. Streaming costs you a worker slot for as long as the stream lives.
    </p>
    <p>
      In ZealPHP’s coroutine mode, a streaming request lives in a coroutine. The worker can
      handle hundreds of other requests in parallel while your stream slowly emits chunks. <em>The
      cost of holding a stream open dropped by an order of magnitude.</em> That changes which
      problems are worth solving with streaming.
    </p>

    <h2>The four patterns</h2>
    <table class="cmp-table">
      <thead><tr><th>Pattern</th><th>How you write it</th><th>Use when</th></tr></thead>
      <tbody>
        <tr>
          <td><strong>Generator yield</strong></td>
          <td><code>return (function() { yield $chunk; })();</code></td>
          <td>SSR streaming HTML — emit shell, then sections as data resolves</td>
        </tr>
        <tr>
          <td><code>$response-&gt;stream()</code></td>
          <td><code>$response-&gt;stream(fn($write) =&gt; $write($chunk));</code></td>
          <td>Fine-grained control — manual flushing, conditional emit</td>
        </tr>
        <tr>
          <td><code>$response-&gt;sse()</code></td>
          <td><code>$response-&gt;sse(fn($emit) =&gt; $emit($data, $event, $id));</code></td>
          <td>Server-Sent Events — JS <code>EventSource</code> for real-time push</td>
        </tr>
        <tr>
          <td><code>App::renderStream()</code></td>
          <td><code>yield from App::renderStream('feed', ['posts' =&gt; $p]);</code></td>
          <td>Stream from a template file — compose with other streams</td>
        </tr>
      </tbody>
    </table>

    <h2>Pattern 1: Generator yield (the default)</h2>
    <p>The simplest streaming primitive in PHP is the Generator. Return one, and ZealPHP streams it.</p>
    <pre><code class="language-php">$app-&gt;route('/feed', function () {
    return (function () {
        yield '&lt;!doctype html&gt;&lt;html&gt;&lt;body&gt;';
        yield '&lt;h1&gt;Latest posts&lt;/h1&gt;&lt;ul&gt;';
        foreach (Post::recent() as $p) {
            // Each iteration sends the &lt;li&gt; immediately
            yield "&lt;li&gt;{$p-&gt;title}&lt;/li&gt;";
        }
        yield '&lt;/ul&gt;&lt;/body&gt;&lt;/html&gt;';
    })();
});</code></pre>
    <p>
      The browser sees the <code>&lt;h1&gt;</code> as soon as your first <code>yield</code> runs —
      before <code>Post::recent()</code> even finishes. This is the React Server Components pattern,
      eight years before React did it.
    </p>

    <h2>Pattern 2: $response-&gt;stream() — manual control</h2>
    <p>
      Use this when you need to decide <em>when</em> to flush, not just <em>what</em> to yield.
    </p>
    <pre><code class="language-php">$app-&gt;route('/big-export', function ($response) {
    return $response-&gt;stream(function ($write) {
        $write("id,name,total\n");
        foreach (Order::cursor() as $row) {
            $write("{$row-&gt;id},{$row-&gt;name},{$row-&gt;total}\n");
            // $write returns false if the client disconnected — abort cleanly
        }
    });
});</code></pre>
    <p>
      <code>$write</code> returns <code>false</code> when the client hangs up. Check it and abort
      your loop — otherwise you keep paginating through a million rows for nobody.
    </p>

    <h2>Pattern 3: $response-&gt;sse() — Server-Sent Events</h2>
    <p>
      SSE is HTTP’s native push protocol: text-only, one-way (server → client), auto-reconnects
      in the browser. Use it for live notifications, AI-streamed responses, progress bars.
    </p>
    <pre><code class="language-php">$app-&gt;route('/ai/chat', function ($response, $request) {
    $prompt = $request-&gt;post['prompt'] ?? '';
    return $response-&gt;sse(function ($emit) use ($prompt) {
        foreach (OpenAI::stream($prompt) as $token) {
            $emit($token, 'token');
        }
        $emit('done', 'end');
    });
});</code></pre>
    <p>
      The framework formats the SSE wire protocol (<code>data:</code>, <code>event:</code>,
      <code>id:</code> lines). On the client, <code>new EventSource('/ai/chat')</code> hooks in.
      See lesson 19 (AI Chat) for the full setup with a real chat UI.
    </p>

    <h2>Pattern 4: App::renderStream() — compose templates</h2>
    <p>
      Streaming templates let you split a long page into chunks that each yield independently:
    </p>
    <pre><code class="language-php">// template/feed/stream.php
&lt;?php return function ($posts) {
    yield '&lt;section&gt;';
    foreach ($posts as $p) {
        yield "&lt;article&gt;&lt;h2&gt;{$p-&gt;title}&lt;/h2&gt;&lt;/article&gt;";
    }
    yield '&lt;/section&gt;';
};

// route handler — compose multiple streaming templates
$app-&gt;route('/feed', function () {
    return (function () {
        yield from App::renderStream('shell-open', ['title' =&gt; 'Feed']);
        yield from App::renderStream('feed/stream', ['posts' =&gt; Post::recent()]);
        yield from App::renderStream('shell-close');
    })();
});</code></pre>
    <p>
      Templates that <code>return function($var) { yield ...; };</code> get parameter injection —
      same convention as route handlers. You declare what the template needs; the framework wires it.
    </p>

    <h2>Same primitive, any routing style</h2>
    <p>
      <code>$response-&gt;sse()</code> is just a method on the response wrapper. It doesn&rsquo;t care
      <em>where</em> your handler lives. The same SSE code works identically whether you put it in
      <code>route/</code>, <code>api/</code>, or <code>public/</code> &mdash; only the way you reach
      the response object differs.
    </p>
    <table class="cmp-table">
      <thead><tr><th>Routing style</th><th>How <code>$response</code> reaches your code</th><th>Demo URL</th></tr></thead>
      <tbody>
        <tr>
          <td><code>route/streaming.php</code></td>
          <td>Parameter injection — declare <code>$response</code> on the handler signature</td>
          <td><a href="/stream/events">/stream/events</a></td>
        </tr>
        <tr>
          <td><code>api/stream/events.php</code></td>
          <td>Parameter injection — same convention, same names</td>
          <td><a href="/api/stream/events">/api/stream/events</a></td>
        </tr>
        <tr>
          <td><code>public/learn/sse-demo.php</code></td>
          <td><code>RequestContext::instance()-&gt;zealphp_response</code> (no handler signature in public files)</td>
          <td><a href="/learn/sse-demo">/learn/sse-demo</a></td>
        </tr>
      </tbody>
    </table>
    <p>The body is identical in all three:</p>
    <pre><code class="language-php">// route/ — $app-&gt;route('/stream/events', function ($response) { ... });
// api/  — ${basename(__FILE__, '.php')} = function ($request, $response) { ... };
// public/ — $response = RequestContext::instance()-&gt;zealphp_response;

$response-&gt;sse(function ($emit) {
    $emit(json_encode(['status' =&gt; 'connected']), 'open');
    for ($i = 1; $i &lt;= 5; $i++) {
        co::sleep(1);
        $emit(json_encode(['tick' =&gt; $i]), 'tick', (string)$i);
    }
    $emit(json_encode(['status' =&gt; 'done']), 'done');
});</code></pre>
    <p>
      All three demos are live on this site &mdash; open any of them in a terminal with
      <code>curl -N</code> and watch the tick events arrive once a second. Same wire format, same
      browser <code>EventSource</code> behavior, same coroutine isolation. <strong>The routing style
      is a filing decision, not a feature constraint.</strong>
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'warn',
      'title'   => 'Always use coroutine mode for streaming',
      'body'    => '<p>Everything above assumes <code>App::superglobals(false)</code> &mdash; the default for new apps. Coroutine mode is what makes streaming cheap: a long-lived stream lives in a coroutine, and the worker can handle hundreds of other requests in parallel. In <code>superglobals(true)</code> mode, streams in <code>public/</code> files run through a CGI subprocess (the parent worker forwards stdout chunks via pipes), and the worker is pinned to that one client for the stream&rsquo;s lifetime &mdash; same as Apache+php-fpm. <strong>The CGI bridge exists for unmodified WordPress/Drupal compatibility, not as a recommended streaming path.</strong> Keep <code>superglobals(false)</code> on, use coroutines, and your SSE endpoints scale to thousands of concurrent streams per worker.</p>',
    ]); ?>

    <h2>What about WebSocket?</h2>
    <p>
      Streaming is one-way (server → client). When you need <em>both</em> directions —
      client typing into chat, server pushing replies — WebSocket is the right tool. Covered in
      lesson 20 (Real-Time Sync). Rule of thumb: <strong>SSE for push-only, WebSocket for two-way.</strong>
      Don’t reach for WebSocket when SSE will do; the operational cost is real.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Don\'t mix streaming with output buffering',
      'body'    => '<p>If you <code>yield</code> from a handler, don’t also <code>echo</code> — the framework flushes headers when it sees a Generator return, but <code>echo</code> output would arrive after the streamed chunks. Same goes for <code>App::render()</code> mid-handler: use <code>renderToString()</code> if you want a string, or <code>renderStream()</code> if you want to yield template output. Mixing modes is the only way streaming bites you.</p>',
    ]); ?>

    <h2>Try it live</h2>
    <ul>
      <li><a href="/demo/view/streaming/ssr" target="_blank">SSR generator yield</a> — click Run, watch chunks arrive progressively</li>
      <li><a href="/demo/view/streaming/stream" target="_blank">$response-&gt;stream() word-by-word</a> — text streams a word at a time</li>
      <li><a href="/demo/view/streaming/sse" target="_blank">Server-Sent Events</a> — EventSource connects, 10 ticks at 1/sec</li>
      <li><a href="/streaming">/streaming</a> — the docs page with the same demos inline</li>
    </ul>

    <?php App::render('/components/_concept_check', [
      'id'       => 'stream1',
      'question' => 'You want to send live progress updates from a long task to the browser, one-way. The client never sends anything back. Which pattern fits best?',
      'correct'  => 'b',
      'explain'  => 'Server-Sent Events (SSE) is the right tool for one-way push. It’s lighter than WebSocket (no upgrade handshake, auto-reconnect built into the browser’s EventSource), uses plain HTTP, and works through every proxy that handles long-lived HTTP. Reach for WebSocket only when the client needs to send back.',
      'options'  => [
        'a' => 'WebSocket — both directions just in case.',
        'b' => 'Server-Sent Events (<code>$response-&gt;sse()</code>) — one-way push over HTTP.',
        'c' => 'Generator <code>yield</code> — just stream HTML chunks.',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Coroutine mode makes streaming cheap — one stream doesn’t monopolize a worker.',
      'Four patterns: Generator yield (HTML SSR), <code>stream()</code> (manual flush), <code>sse()</code> (Server-Sent Events), <code>renderStream()</code> (template composition).',
      'Generator return is the simplest path — works for any HTML-on-the-fly use case.',
      'Use SSE for push-only, WebSocket only when client → server is also needed.',
      'Don’t mix <code>yield</code> with <code>echo</code> in the same handler — pick one mode.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/middleware"
         hx-get="/api/learn/page?slug=learn/middleware" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/middleware">← Middleware: The Wrap</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/store"
         hx-get="/api/learn/page?slug=learn/store" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/store">Sharing State →</a>
    </div>
  </article>
</div>
