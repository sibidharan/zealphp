<?php use ZealPHP\App; $active = $active ?? 'learn/websocket'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 19,
      'title'    => 'Real-Time Sync',
      'subtitle' => 'Open this page in two tabs. Click +1 in one. The other tab counts up too.',
      'prev'     => ['slug' => 'learn/notes', 'title' => 'Personal Notes'],
      'next'     => ['slug' => 'learn/ai-chat', 'title' => 'AI Chat'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Why HTTP can\'t push, and what WebSocket changes',
      'The 4-callback shape: route, onOpen, onMessage, onClose',
      'Broadcasting to many connected clients at once',
      'When to use WebSocket vs SSE vs htmx vs Redis Pub/Sub',
    ]]); ?>

    <h2 id="step-overview">1. Overview — what you&rsquo;re building</h2>
    <p>
      A counter that <strong>updates in every open tab the moment any tab clicks +1</strong>. No
      reloads, no polling, no Redis. The server holds a list of open WebSocket connections; the
      <code>+1</code> button hits a normal HTTP endpoint; that endpoint broadcasts the new value
      back over WebSocket to every connected tab. Try it now — open this URL in another tab first.
    </p>

    <?php App::render('/components/_ws_counter_widget'); ?>

    <p class="lws-subtitle">
      Open <a href="/learn/websocket" target="_blank">/learn/websocket</a> in a second tab. Click +1 here. Watch the second tab count up without reloading.
    </p>

    <h2 id="step-server">2. Server setup — why HTTP can&rsquo;t push</h2>
    <p>
      HTTP is request/response. Client asks, server answers, connection closes. The server cannot
      "speak first" because there&rsquo;s no open socket waiting. To get a new value to a browser
      with plain HTTP, you have to <em>ask repeatedly</em> (polling) — burning bandwidth and
      worker-time even when nothing changed.
    </p>
    <p>
      <strong>WebSocket</strong> upgrades the HTTP connection to a long-lived bidirectional channel.
      The server can push at any time. The client can send at any time. The same TCP socket carries
      both directions. ZealPHP&rsquo;s OpenSwoole engine handles thousands of concurrent WebSocket
      connections per worker — coroutines (<a href="/learn/async">Lesson 24, Async Patterns</a>
      covers them) mean each one costs ~5&nbsp;KB of memory and zero worker-time while idle.
    </p>

    <h3>The four callbacks</h3>
    <p>
      One call to <code>$app-&gt;ws()</code>, three callbacks. The callbacks handle the connection
      lifecycle:
    </p>
    <pre><code class="language-php">// Create the shared counter ONCE, before $app-&gt;run() — it lives across all
// workers and the closures below capture it via use().
$counter = new \ZealPHP\Counter(0);

$app-&gt;ws('/ws/counter-demo',
    onMessage: function ($server, $frame) {
        // Client sent a frame. $frame->data is the payload.
        if ($frame->data === 'ping') $server->push($frame->fd, 'pong');
    },
    onOpen: function ($server, $request) use ($counter) {
        // New connection — store the fd so we can push to it later.
        Store::set('ws_clients', (string)$request->fd, ['connected_at' => time()]);
        // Send the current value to this new tab so it&rsquo;s in sync immediately.
        $server->push($request->fd, json_encode(['value' => $counter->get()]));
    },
    onClose: function ($server, $fd) {
        // Client disconnected — forget the fd.
        Store::del('ws_clients', (string)$fd);
    },
);</code></pre>
    <p>
      <code>$server</code> is the OpenSwoole WebSocket server (same object across all callbacks).
      <code>$request->fd</code> in <code>onOpen</code> is the new socket&rsquo;s file descriptor —
      an integer that&rsquo;s your handle to that specific client until they disconnect. Storing
      every fd in a <code>Store</code> table is how you keep track of "who&rsquo;s open" for
      broadcasting.
    </p>

    <h2 id="step-broadcast">3. Broadcast patterns</h2>
    <p>
      To push a message to every connected client, walk your fd table and call
      <code>$server-&gt;push()</code> on each one:
    </p>
    <pre><code class="language-php">function broadcast_counter(int $value): void {
    $server  = App::getServer();
    $payload = json_encode(['value' => $value]);
    foreach (Store::table('ws_clients') as $fd =&gt; $_) {
        $fd = (int)$fd;
        if ($server-&gt;isEstablished($fd)) $server-&gt;push($fd, $payload);
    }
}

// In the +1 endpoint:
$app-&gt;route('/api/counter/bump', ['methods' =&gt; ['POST']], function () use ($counter) {
    $new = $counter-&gt;increment();
    broadcast_counter($new);
    return ['value' =&gt; $new];
});</code></pre>
    <p>
      <code>isEstablished($fd)</code> guards against the race where a client disconnected but their
      fd is still in your table because <code>onClose</code> hasn&rsquo;t finished cleaning up.
      Pushing to a dead fd raises a warning; <code>isEstablished()</code> avoids it.
    </p>

    <h2 id="step-client">4. Client lifecycle</h2>
    <p>
      In the browser, <code>WebSocket</code> is a one-liner:
    </p>
    <pre><code class="language-javascript">const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
const ws = new WebSocket(proto + '//' + location.host + '/ws/counter-demo');

ws.onopen    = () =&gt; console.log('connected');
ws.onmessage = e  =&gt; {
    const msg = JSON.parse(e.data);
    document.getElementById('count').textContent = msg.value;
};
ws.onclose   = () =&gt; console.log('disconnected — reconnect after a backoff if you want');
ws.send('ping');   // round-trip test</code></pre>
    <p>
      The same <code>ws.send()</code> you can call any time — and the server&rsquo;s
      <code>onMessage</code> picks it up. The browser&rsquo;s <code>WebSocket</code> API has been
      stable since 2011; every major browser ships it. No library needed.
    </p>

    <h3>When to use what</h3>
    <p>
      WebSocket isn&rsquo;t the answer to "make the UI update." Pick by data shape:
    </p>
    <table class="cmp-table">
      <thead><tr><th>Pattern</th><th>Direction</th><th>Best for</th></tr></thead>
      <tbody>
        <tr><td><strong>htmx</strong></td><td>Client &rarr; server (request/response)</td><td>Form posts, click handlers, search-as-you-type. The default.</td></tr>
        <tr><td><strong>SSE</strong> (<a href="/learn/streaming">streaming</a>)</td><td>Server &rarr; client (one-way push)</td><td>AI tokens streaming, log tails, progress bars, notifications.</td></tr>
        <tr><td><strong>WebSocket</strong></td><td>Both ways, low latency</td><td>Chat, multiplayer state, collaborative editing, anything where the client also sends.</td></tr>
        <tr><td><strong>Redis Pub/Sub</strong></td><td>Server &harr; server fan-out</td><td>Multi-server deployments where one box's broadcast needs to reach clients on another box.</td></tr>
      </tbody>
    </table>
    <p>
      Rule of thumb: <strong>SSE for push-only, WebSocket for two-way.</strong> Reach for WebSocket
      only when the client also needs to send back. Don&rsquo;t use WebSocket for one-way push —
      SSE is lighter (auto-reconnect built into <code>EventSource</code>, works through every proxy
      that handles long-lived HTTP, no upgrade handshake).
    </p>

    <h3 id="cross-server-routing">Scaling past one server: Pub/Sub bridge</h3>
    <p>
      Everything above lives in one process. Two ZealPHP servers behind a load balancer don&rsquo;t
      share their <code>ws_clients</code> tables &mdash; a broadcast in process A doesn&rsquo;t
      reach clients connected to process B. The fix is a shared bus that both processes subscribe
      to. ZealPHP v0.2.39 ships this as a first-class primitive: <code>Store::publish</code> +
      <code>App::subscribe</code> on the Redis backend.
    </p>
    <pre><code class="language-php">// app.php — flip Store to Redis once. Counter follows automatically.
Store::defaultBackend(Store::BACKEND_REDIS);

// Each ZealPHP process registers a subscriber at boot. ZealPHP spawns the
// dedicated subscriber coroutine in onWorkerStart for you; handlers run in
// go() per message so a slow handler can't block the next read.
App::subscribe('counter:bump', function (string $payload) {
    $data = json_decode($payload, true);
    broadcast_counter((int) $data['value']);
});

// In the +1 endpoint — publish instead of broadcasting directly.
$app-&gt;route('/api/counter/bump', ['methods' =&gt; ['POST']], function () use ($counter) {
    $new = $counter-&gt;increment();
    Store::publish('counter:bump', json_encode(['value' =&gt; $new]));
    return ['value' =&gt; $new];
});</code></pre>
    <p>
      Now process B&rsquo;s subscriber sees the message and broadcasts to its own local
      <code>ws_clients</code>. Every connected tab sees the update, no matter which process is
      holding their socket. Same idea works for chat fan-out, presence, any cross-process event.
    </p>
    <p>
      <strong>Point-to-point routing</strong> (rather than broadcast): store
      <code>client_id → server_id</code> in the same Redis-backed Store. Each server subscribes to
      its identity channel (<code>ws:server:{ID}</code>). To message client X:
    </p>
    <pre><code class="language-php">// Anywhere — message a specific client by id.
$owner = Store::get('client_locations', $clientId, 'server');
Store::publish("ws:server:$owner", json_encode([
    'client_id' => $clientId,
    'data'      => $payload,
]));

// Each server's subscriber routes to the local fd.
App::subscribe("ws:server:{$myServerId}", function (string $payload) use ($server, $fdMap) {
    $msg = json_decode($payload, true);
    $fd = $fdMap[$msg['client_id']] ?? null;
    if ($fd !== null &amp;&amp; $server-&gt;isEstablished($fd)) {
        $server-&gt;push($fd, $msg['data']);
    }
});</code></pre>
    <p>
      ZealPHP&rsquo;s WebSocket fd is process-local &mdash; only the owning server can push to it.
      Redis is the routing fabric that says &ldquo;hey, owner: here&rsquo;s something to push.&rdquo;
      Sub-millisecond loopback, ~ms cross-region. See
      <a href="/store#pubsub">/store#pubsub</a> for the at-least-once <code>publishReliable</code>
      variant (Redis Streams) when drops aren&rsquo;t acceptable.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Pub/sub driver choice (both validated)',
      'body'    => '<p>Both phpredis (preferred when <code>ext-redis</code> is loaded) and predis SUBSCRIBE loops yield correctly under <code>HOOK_ALL</code> — the production default in coroutine mode. phpredis is ~2× faster on hot CRUD per the v0.2.40 spike; predis works without the extension. See <a href="/store#phpredis-pubsub-caveat">/store#phpredis-pubsub-caveat</a>.</p>',
    ]); ?>

    <?php App::render('/components/_callout', [
      'variant' => 'warn',
      'title'   => 'WebSocket through Nginx',
      'body'    => '<p>If you front ZealPHP with Nginx (recommended for TLS termination), the upgrade handshake needs explicit headers in your <code>location</code> block: <code>proxy_http_version 1.1; proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "upgrade"; proxy_read_timeout 3600s;</code>. Without these, the upgrade request returns 400 and the connection never opens. See the deployment lesson for the full nginx config.</p>',
    ]); ?>

    <?php App::render('/components/_concept_check', [
      'id'       => 'ws1',
      'question' => 'You want every connected client to see live notifications. The client never sends anything back to the server. Which primitive fits best?',
      'correct'  => 'b',
      'explain'  => 'SSE is one-way (server → client) over plain HTTP. EventSource auto-reconnects, works through every proxy, no upgrade handshake. WebSocket is the right tool when the client also needs to send — but for push-only, SSE is lighter.',
      'options'  => [
        'a' => 'WebSocket — both directions just in case.',
        'b' => 'Server-Sent Events (<code>$response-&gt;sse()</code>) — one-way push.',
        'c' => 'Polling with <code>setInterval(() =&gt; fetch(...), 1000)</code>.',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'HTTP is request/response; WebSocket upgrades it to bidirectional long-lived.',
      '<code>$app-&gt;ws($path, onMessage, onOpen, onClose)</code> — three callbacks handle the lifecycle.',
      'Track open fds in <code>Store</code> so you can broadcast; <code>isEstablished($fd)</code> guards against races.',
      'SSE for push-only, WebSocket for two-way. Don\'t use WebSocket when SSE would do.',
      'Scale past one server with <code>Store::publish</code> + <code>App::subscribe</code> on the Redis backend — each process subscribes and re-broadcasts to its local <code>ws_clients</code>. <code>Store::publishReliable</code> for at-least-once via Redis Streams.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/notes"
         hx-get="/api/learn/page?slug=learn/notes" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/notes">← Personal Notes</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/ai-chat"
         hx-get="/api/learn/page?slug=learn/ai-chat" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/ai-chat">AI Chat →</a>
    </div>
  </article>
</div>
