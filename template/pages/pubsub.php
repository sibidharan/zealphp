<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Cross-node Pub/Sub &amp; Streams</h1>
<p class="section-desc">Two first-class primitives for cross-worker AND cross-host messaging on top of the pluggable Store/Counter backend. Built on Redis (and Redis Streams for the reliable variant); both arrived in v0.2.39.</p>

<h2 class="store-h2-section" id="why">When you need it</h2>
<ul class="store-col-list">
  <li><strong>Horizontally-scaled WebSocket</strong> &mdash; you have N OpenSwoole servers behind a load balancer; the user clicked &ldquo;send&rdquo; on server A but the recipient is connected to server B. Each <code>$fd</code> is process-local; only the owning server can <code>$server-&gt;push()</code>. Redis is the routing fabric that says &ldquo;hey, owner: here&rsquo;s something to push.&rdquo;</li>
  <li><strong>Cache invalidation</strong> &mdash; one server writes a record, every other server&rsquo;s local L1 cache needs to evict that key. Publish to an invalidation channel, peers subscribe + evict.</li>
  <li><strong>Live broadcasts</strong> &mdash; chat rooms, presence, leaderboards, anywhere &ldquo;every connected client sees this&rdquo; needs to cross process boundaries.</li>
  <li><strong>Work queues / event sourcing</strong> &mdash; need at-least-once delivery? Use <code>Store::publishReliable</code> (Redis Streams) instead of <code>Store::publish</code>. Consumer groups distribute work; ACK confirms processing.</li>
</ul>

<h2 class="store-h2-section" id="quickstart">Quick start</h2>
<p class="store-lead-tight">Three lines of code from zero to working pub/sub.</p>
<div class="code-block">
<pre><code class="language-php">// app.php — before $app-&gt;run()
use ZealPHP\Store;
use ZealPHP\App;

// Step 1: tell Store to use Redis. ZEALPHP_STORE_BACKEND=redis env works too.
Store::defaultBackend(Store::BACKEND_REDIS);

// Step 2: register a subscriber at boot. Runs in EVERY worker.
App::subscribe('hello:world', function (string $payload, string $channel) {
    error_log("[$channel] received: $payload");
});

// Step 3: publish from anywhere — a route, a timer, a CLI script, …
$receivers = Store::publish('hello:world', 'hi from anywhere');
// Returns number of receivers — across ALL workers AND ALL connected servers.</code></pre>
</div>

<h2 class="store-h2-section" id="reliable">Reliable variant &mdash; Redis Streams</h2>
<p class="store-lead-tight">When &ldquo;might drop during a subscriber reconnect&rdquo; isn&rsquo;t acceptable: switch to the Streams primitive. Same shape; ACK semantics; consumer groups distribute work across workers and servers.</p>
<div class="code-block">
<pre><code class="language-php">App::subscribeReliable('orders', function (string $payload, string $id, string $stream): bool {
    $order = json_decode($payload, true);
    $ok = processOrder($order);
    return $ok;  // true → XACK (done); false/throw → leave pending, retried on reconnect
});

// Anywhere:
$messageId = Store::publishReliable('orders', json_encode($order));
// Returns the Redis message ID, e.g. '1779520329297-0' — durable when AOF/RDB is on.</code></pre>
</div>

<table class="store-compare-tbl store-mt-1">
  <thead><tr><th>Primitive</th><th>Latency</th><th>Durability</th><th>Delivery</th><th>When to pick</th></tr></thead>
  <tbody>
    <tr><td><code>Store::publish</code></td><td>~0.5 ms loopback</td><td>None</td><td>Best-effort</td><td>Cache invalidation, WS fan-out, presence, leaderboards.</td></tr>
    <tr><td><code>Store::publishReliable</code></td><td>~1&ndash;2 ms</td><td>AOF/RDB-backed</td><td>At-least-once via consumer groups</td><td>Orders, payments, work queues, audit events.</td></tr>
  </tbody>
</table>

<h2 class="store-h2-section" id="ws-routing">Cross-server WebSocket routing</h2>
<p class="store-lead-tight">The pattern you came here for. <code>$fd</code> is process-local; only the owning server can push to it. Store the <code>client_id &rarr; server_id</code> mapping in shared Redis; each server subscribes to its identity channel; senders look up + PUBLISH to the owner.</p>
<div class="code-block">
<pre><code class="language-php">// app.php — boot
Store::defaultBackend(Store::BACKEND_REDIS);
$myServerId = gethostname() . ':' . getmypid();

// Shared mapping: which server owns each connected client.
Store::make('ws_owner', 4096, ['server' => [Store::TYPE_STRING, 64]]);

// Per-worker local: client_id → local fd (only valid in THIS process)
$localFds = [];

App::ws('/ws', function ($server, $frame) use (&$localFds) {
    // Process inbound from local clients normally.
});

// Each server's subscriber only handles its OWN routed messages.
App::subscribe("ws:server:$myServerId", function (string $payload) use ($server, &$localFds) {
    $msg = json_decode($payload, true);
    $fd  = $localFds[$msg['client_id']] ?? null;
    if ($fd !== null &amp;&amp; $server-&gt;isEstablished($fd)) {
        $server-&gt;push($fd, $msg['data']);
    }
});

// Anywhere: route a message to client X, regardless of which server holds it.
function sendToClient(string $clientId, string $data): void {
    $owner = Store::get('ws_owner', $clientId, 'server');
    if ($owner === null) { return; } // client not connected anywhere
    Store::publish("ws:server:$owner", json_encode([
        'client_id' => $clientId,
        'data'      => $data,
    ]));
}</code></pre>
</div>
<p class="store-lead-tight store-mt-1">Sub-millisecond loopback, ~ms cross-region. Scales symmetrically &mdash; no peer-to-peer state. Every routing decision is one Redis lookup + PUBLISH. Validated end-to-end in the <a href="https://github.com/sibidharan/zealphp/blob/master/docs/superpowers/specs/2026-05-23-phase3-pubsub-spike-result.md" target="_blank">Phase 3 spike</a> (in-process, cross-process two-server, cross-host via wireguard).</p>

<h2 class="store-h2-section" id="demo">Live demo</h2>
<p class="store-lead-tight">Hit the running server. Most useful with <code>ZEALPHP_STORE_BACKEND=redis</code> set; on the default Table backend the pub/sub buttons surface a clean <code>StoreException</code>.</p>
<div class="store-demo-panel">
  <h3>Try it from this tab</h3>
  <p class="store-demo-panel-lead">For the multi-tab routing demo, open a second tab on this page and click <em>Read pubsub log</em> while the first tab publishes &mdash; you&rsquo;ll see the same entries because every worker received the broadcast.</p>
  <div class="store-demo-controls">
    <button class="btn btn-primary btn-sm" type="button" data-action="store-demo-roundtrip">Round-trip Store</button>
    <button class="btn btn-primary btn-sm" type="button" data-action="store-demo-publish">Publish (fire-and-forget)</button>
    <button class="btn btn-primary btn-sm" type="button" data-action="store-demo-publish-reliable">Publish (Streams reliable)</button>
    <button class="btn btn-ghost btn-sm"   type="button" data-action="store-demo-pubsub-log">Read pubsub log</button>
  </div>
  <pre class="demo-json-pane">Click a button above to fire a request. The response JSON will land here.</pre>
  <p class="store-demo-hint">Routes wired in <code>route/demo.php</code>. The 'receivers' field on Publish typically equals your worker count &mdash; each worker has its own subscriber cor.</p>
</div>

<h2 class="store-h2-section">Driver choice (both validated)</h2>
<div class="callout info">
  Both phpredis (preferred when <code>ext-redis</code> is loaded) and predis SUBSCRIBE loops yield correctly under <code>HOOK_ALL</code> &mdash; the production default. phpredis is ~2&times; faster on hot CRUD; predis works without the ext. Pick phpredis when available. See <a href="/store#phpredis-pubsub-caveat">/store#phpredis-pubsub-caveat</a> for the comparison.
</div>

<h2 class="store-h2-section">Further reading</h2>
<ul class="store-col-list">
  <li><a href="/store#pubsub">/store#pubsub</a> &mdash; the API reference + comparison table</li>
  <li><a href="/ws#scaling">/ws#scaling</a> &mdash; cross-server WebSocket routing applied</li>
  <li><a href="/learn/store#step-pubsub">/learn/store#step-pubsub</a> &mdash; longer walkthrough with motivation</li>
  <li><a href="/learn/websocket#cross-server-routing">/learn/websocket#cross-server-routing</a> &mdash; the same pattern as a learn lesson</li>
</ul>

</div>
</section>
