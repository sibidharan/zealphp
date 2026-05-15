<?php use ZealPHP\App; $active = $active ?? 'learn/websocket'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 10,
      'title'    => 'WebSocket',
      'subtitle' => 'Persistent bidirectional connections — when SSE and htmx aren\'t enough.',
      'prev'     => ['slug' => 'learn/ai-chat', 'title' => 'Add AI Chat'],
      'next'     => ['slug' => 'learn/async', 'title' => 'Async & Coroutines'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Register a WebSocket route with App::ws()',
      'Authenticate WebSocket connections using sessions',
      'Broadcast events to specific users across workers',
      'When WebSocket is right vs. SSE vs. htmx vs. pub/sub (Redis, RabbitMQ)',
    ]]); ?>

    <h2>What you already have</h2>
    <p>
      This tutorial app already uses WebSocket — the cross-tab notes sync you saw on Lesson 8.
      Open <a href="/learn/notes">/learn/notes</a> in two tabs, add a note in one, watch the
      other update. That's <code>App::ws('/ws/learn', ...)</code> running right now.
    </p>

    <h2>The handler shape</h2>
    <p>A WebSocket route looks like a regular route — same file, same framework, three callbacks:</p>
    <pre><code>$app->ws('/ws/learn',
    onMessage: function ($server, $frame) {
        // TEXT or BINARY frames only — PING/PONG handled by the framework.
        if ($frame->data === 'ping') {
            $server->push($frame->fd, 'pong');
        }
    },
    onOpen: function ($server, $request) {
        // $g->session is populated from the upgrade request's cookie.
        $g = G::instance();
        $userId = (int) ($g->session['user_id'] ?? 0);
        if (!$userId) {
            $server->disconnect($request->fd, 1008, 'auth_required');
            return;
        }
        // Track fd → user_id in shared memory so we can broadcast.
        Store::set('learn_ws_clients', (string) $request->fd, [
            'user_id' => $userId,
        ]);
    },
    onClose: function ($server, $fd) {
        Store::del('learn_ws_clients', (string) $fd);
    },
);</code></pre>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Sessions in WebSocket',
      'body'    => '<p>ZealPHP reads the <code>PHPSESSID</code> cookie from the HTTP upgrade request and populates <code>$g->session</code> before <code>onOpen</code> fires. You authenticate the same way you would in an HTTP handler — no special token flow needed.</p>',
    ]); ?>

    <h2>Broadcasting</h2>
    <p>WebSocket connections live on individual workers. To broadcast to all of a user's tabs, iterate over a shared <code>Store</code> table that maps <code>fd → user_id</code>:</p>
    <pre><code>function learn_ws_broadcast(int $userId, array $payload): void
{
    $server = App::getServer();
    $json = json_encode($payload);
    foreach (Store::table('learn_ws_clients') as $fd => $row) {
        if ((int) $row['user_id'] === $userId) {
            $server->push((int) $fd, $json);
        }
    }
}</code></pre>
    <p>
      Call this from any endpoint — HTTP route, SSE stream, task worker. Whenever a note is
      created or deleted, the endpoint calls <code>learn_ws_broadcast($userId, ['type' => 'note_changed'])</code>,
      and every open tab belonging to that user refreshes its notes list via htmx.
    </p>

    <h2>The client</h2>
    <pre><code>const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
let ws = new WebSocket(proto + '//' + location.host + '/ws/learn');

ws.addEventListener('message', (ev) => {
    const msg = JSON.parse(ev.data);
    if (msg.type === 'note_changed') {
        htmx.ajax('GET', '/api/learn/notes', {
            target: '#notes-list', swap: 'innerHTML'
        });
    }
});

// Stop retrying if the server rejects auth
ws.addEventListener('close', (ev) => {
    if (ev.code === 1008) return; // auth_required — don't retry
    setTimeout(connect, Math.min(delay *= 2, 10000));
});</code></pre>

    <h2>When to use what</h2>
    <p>Four tools, four use cases. Pick the lightest one that solves your problem:</p>

    <table style="width:100%;border-collapse:collapse;margin:1rem 0;font-size:.88rem">
      <thead>
        <tr style="border-bottom:2px solid #e7e5e4;text-align:left">
          <th style="padding:.55rem">Tool</th>
          <th style="padding:.55rem">Direction</th>
          <th style="padding:.55rem">When to use</th>
        </tr>
      </thead>
      <tbody>
        <tr style="border-bottom:1px solid #f5f5f4">
          <td style="padding:.55rem"><strong>htmx</strong></td>
          <td style="padding:.55rem">Request → response</td>
          <td style="padding:.55rem">User-initiated actions: submit form, delete item, load fragment. 95% of web apps.</td>
        </tr>
        <tr style="border-bottom:1px solid #f5f5f4">
          <td style="padding:.55rem"><strong>SSE</strong> (<code>$response->sse()</code>)</td>
          <td style="padding:.55rem">Server → client (one-way)</td>
          <td style="padding:.55rem">Streaming responses: AI tokens, live logs, progress bars. Client opens a connection, server pushes events.</td>
        </tr>
        <tr style="border-bottom:1px solid #f5f5f4">
          <td style="padding:.55rem"><strong>WebSocket</strong> (<code>App::ws()</code>)</td>
          <td style="padding:.55rem">Bidirectional</td>
          <td style="padding:.55rem">Real-time sync across tabs/users: chat, collaborative editing, live dashboards, gaming. Connection stays open.</td>
        </tr>
        <tr>
          <td style="padding:.55rem"><strong>Pub/Sub</strong> (Redis, RabbitMQ)</td>
          <td style="padding:.55rem">Server → server</td>
          <td style="padding:.55rem">Multi-server fan-out. When you have >1 ZealPHP process on different machines and need to broadcast across all of them.</td>
        </tr>
      </tbody>
    </table>

    <h2>WebSocket vs. Pub/Sub — when you need Redis or RabbitMQ</h2>
    <p>
      ZealPHP's WebSocket + <code>Store</code> is a <strong>single-server solution</strong>.
      The <code>Store</code> table lives in shared memory across workers on the same process.
      When you call <code>$server->push($fd, $msg)</code>, it works because all workers share
      the same OpenSwoole server instance.
    </p>
    <p>
      This breaks when you scale <strong>horizontally</strong> — multiple ZealPHP processes
      on different machines, behind a load balancer. A WebSocket client connected to server A
      can't receive a push from server B, because they don't share memory.
    </p>

    <?php App::render('/components/_deepdive', [
      'title' => 'When you need a message broker',
      'body'  => <<<HTML
<p>The pattern for multi-server WebSocket:</p>
<ol style="margin:.5rem 0;padding-left:1.3rem;line-height:1.8">
  <li>HTTP handler on server A writes to the database and publishes to <strong>Redis Pub/Sub</strong> (or RabbitMQ, NATS, Kafka — same idea).</li>
  <li>Every ZealPHP server subscribes to the channel. Server B receives the message.</li>
  <li>Server B iterates its local <code>Store</code> table and pushes to its connected clients.</li>
</ol>
<p>You only need this when:</p>
<ul style="margin:.5rem 0;padding-left:1.3rem">
  <li>You run <strong>multiple server instances</strong> behind a load balancer</li>
  <li>You need to broadcast events originating from a <strong>different service</strong> (microservice, cron job, external webhook)</li>
  <li>You need <strong>durable message delivery</strong> (client was offline, should receive the event when it reconnects) — use RabbitMQ or Kafka for that</li>
</ul>
<p>For a single-server app — which covers most projects until you hit thousands of concurrent WebSocket connections — ZealPHP's built-in <code>Store</code> + <code>App::ws()</code> is the entire solution. No Redis, no queue, no extra process.</p>
HTML
    ]); ?>

    <h2>Architecture diagram</h2>
    <pre><code>┌─ Single-server (this tutorial) ──────────────────────────┐
│                                                          │
│  Browser A ──ws──┐                                       │
│  Browser B ──ws──┤── ZealPHP ── Store (shared memory)    │
│  Browser C ──ws──┘      │                                │
│                     push to all fds                      │
│                     matching user_id                     │
└──────────────────────────────────────────────────────────┘

┌─ Multi-server (when you outgrow one box) ────────────────┐
│                                                          │
│  Browser A ──ws──► ZealPHP-1 ──┐                         │
│  Browser B ──ws──► ZealPHP-2 ──┤── Redis Pub/Sub         │
│  Browser C ──ws──► ZealPHP-1 ──┘      │                  │
│                                   subscribe              │
│  Each server pushes to its         on each               │
│  own local Store clients           server                │
└──────────────────────────────────────────────────────────┘</code></pre>

    <?php App::render('/components/_callout', [
      'variant' => 'success',
      'title'   => 'The rule of thumb',
      'body'    => '<p><strong>One server?</strong> <code>App::ws()</code> + <code>Store</code>. Done.<br><strong>Multiple servers?</strong> Add Redis Pub/Sub as the fan-out layer — keep WebSocket for the last mile to the browser.<br><strong>Durable delivery?</strong> RabbitMQ or Kafka — messages survive server restarts and client disconnects.</p>',
    ]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/ai-chat" hx-get="/api/learn/page?slug=learn/ai-chat" hx-target=".learn-layout" hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/ai-chat">← Add AI Chat</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/async" hx-get="/api/learn/page?slug=learn/async" hx-target=".learn-layout" hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/async">Async & Coroutines →</a>
    </div>
  </article>
</div>
