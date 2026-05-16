<?php use ZealPHP\App; $active = $active ?? 'learn/websocket'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 11,
      'title'    => 'Real-Time Sync',
      'subtitle' => 'WebSocket — persistent connections for when the server needs to talk first.',
      'prev'     => ['slug' => 'learn/ai-chat', 'title' => 'AI Chat'],
      'next'     => ['slug' => 'learn/routing', 'title' => 'Routes & APIs'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Why HTTP can\'t push updates to the browser',
      'Register a WebSocket route with App::ws()',
      'Authenticate connections and broadcast to specific users',
      'When to use htmx vs SSE vs WebSocket vs Pub/Sub',
    ]]); ?>

    <h2>The problem</h2>
    <p>
      Open your <a href="/learn/notes">notes app</a> in two browser tabs. Add a note in one.
      The other tab shows stale data until you manually refresh.
    </p>
    <p>
      This happens because HTTP is <strong>request-response</strong>: the server only talks when
      the client asks. There's no way for the server to say "hey, something changed" without the
      client explicitly asking "did anything change?"
    </p>

    <pre class="mermaid">graph LR
    subgraph "Tab A"
      A1[Create note] -->|htmx POST| API
    end
    API -->|WS::broadcast| WS[WebSocket Server]
    subgraph "Tab B"
      WS -->|push| B1[note_changed]
      B1 -->|htmx.ajax| B2[Refresh notes list]
      B2 --> B3["Green glow ✓"]
    end
    subgraph "Tab A"
      WS -->|push| A2[note_changed]
      A2 --> A3["Already in DOM — highlight ✓"]
    end
    style API fill:#fffbeb,stroke:#f59e0b
    style WS fill:#f5f3ff,stroke:#a855f7
    style B3 fill:#ecfdf5,stroke:#059669
    style A3 fill:#ecfdf5,stroke:#059669</pre>

    <h2>The mental model</h2>
    <p>
      In Lesson 9, you used SSE to stream AI tokens. SSE is like a <strong>one-way phone call</strong>
      — the server talks, you listen. But SSE can't handle the case where the <em>client</em>
      needs to send messages back, or where the server needs to push updates <em>at any time</em>.
    </p>
    <p>
      WebSocket is like a <strong>walkie-talkie</strong>. Both sides can talk whenever they want.
      The connection stays open. This is why it works for cross-tab sync: when tab A changes something,
      the server pushes an update to every open walkie-talkie belonging to that user.
    </p>

    <h2>The handler</h2>
    <p>A WebSocket route has three callbacks — same file, same framework as HTTP routes:</p>
    <pre><code class="language-php">$app->ws('/ws/learn',
    onMessage: function ($server, $frame) {
        if ($frame->data === 'ping') {
            $server->push($frame->fd, 'pong');
        }
    },
    onOpen: function ($server, $request) {
        $g = G::instance();
        $userId = (int) ($g->session['user_id'] ?? 0);
        if (!$userId) {
            $server->disconnect($request->fd, 1008, 'auth_required');
            return;
        }
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
      'body'    => '<p>ZealPHP reads the <code>PHPSESSID</code> cookie from the HTTP upgrade request and populates <code>$g->session</code> before <code>onOpen</code> fires. You authenticate the same way as in an HTTP handler — no special token flow needed.</p>',
    ]); ?>

    <h2>Broadcasting</h2>
    <p>WebSocket connections live on individual workers. To broadcast to all of a user's tabs, iterate a shared <code>Store</code> table that maps <code>fd → user_id</code>:</p>
    <pre><code class="language-php">// <a href="https://github.com/sibidharan/zealphp/blob/master/src/Learn/WS.php" style="color:#f59e0b">src/Learn/WS.php</a>
public static function broadcast(int $userId, array $payload): void
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
      Call this from any endpoint — HTTP route, SSE stream, task worker. When a note is
      created or deleted, the endpoint calls <code>WS::broadcast($userId, ['type' =&gt; 'note_changed'])</code>,
      and every open tab refreshes its notes list via <code>htmx.ajax()</code>.
    </p>

    <?php App::render('/components/_tryit', [
      'title' => 'Try it now',
      'body'  => '<p>Open <a href="/learn/notes" target="_blank">your notes</a> in two browser tabs. Create a note in one tab — the other tab updates instantly with a green glow. Delete a note — it fades out in both tabs. Then visit <a href="/learn/ai-chat" target="_blank">AI Chat</a> and ask the agent to create a note: watch the Event Log show <span style="background:#3b82f6;color:#fff;padding:0 .3rem;border-radius:3px;font-size:.72rem;font-weight:700">SSE</span> tool events, then the notes panel updates via <span style="background:#a855f7;color:#fff;padding:0 .3rem;border-radius:3px;font-size:.72rem;font-weight:700">WS</span> broadcast.</p>',
    ]); ?>

    <h2>The client</h2>
    <pre><code class="language-javascript">const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
let ws = new WebSocket(proto + '//' + location.host + '/ws/learn');

ws.addEventListener('message', (ev) => {
    const msg = JSON.parse(ev.data);
    if (msg.type === 'note_changed') {
        htmx.ajax('GET', '/api/learn/notes', {
            target: '#notes-list', swap: 'innerHTML'
        });
    }
});

ws.addEventListener('close', (ev) => {
    if (ev.code === 1008) return; // auth rejected — don't retry
    setTimeout(connect, Math.min(delay *= 2, 10000));
});</code></pre>

    <h2>When to use what</h2>

    <table style="width:100%;border-collapse:collapse;margin:1rem 0;font-size:.88rem">
      <thead>
        <tr style="border-bottom:2px solid #e7e5e4;text-align:left">
          <th style="padding:.55rem">Tool</th>
          <th style="padding:.55rem">Direction</th>
          <th style="padding:.55rem">When</th>
        </tr>
      </thead>
      <tbody>
        <tr style="border-bottom:1px solid #f5f5f4"><td style="padding:.55rem"><strong>htmx</strong></td><td style="padding:.55rem">Request → response</td><td style="padding:.55rem">User-initiated actions. 95% of web apps.</td></tr>
        <tr style="border-bottom:1px solid #f5f5f4"><td style="padding:.55rem"><strong>SSE</strong></td><td style="padding:.55rem">Server → client</td><td style="padding:.55rem">Streaming responses: AI tokens, live logs, progress bars.</td></tr>
        <tr style="border-bottom:1px solid #f5f5f4"><td style="padding:.55rem"><strong>WebSocket</strong></td><td style="padding:.55rem">Bidirectional</td><td style="padding:.55rem">Real-time sync: chat, collaborative editing, live dashboards.</td></tr>
        <tr><td style="padding:.55rem"><strong>Pub/Sub</strong></td><td style="padding:.55rem">Server → server</td><td style="padding:.55rem">Multi-server broadcast. Redis or RabbitMQ when you outgrow one box.</td></tr>
      </tbody>
    </table>

    <?php App::render('/components/_deepdive', [
      'title' => 'When you need a message broker',
      'body'  => '<p>ZealPHP\'s WebSocket + <code>Store</code> is a single-server solution. The Store table lives in shared memory across workers on the same process. This breaks when you scale horizontally — multiple processes on different machines. A WebSocket client on server A can\'t receive pushes from server B.</p><p><strong>One server?</strong> <code>App::ws()</code> + <code>Store</code>. Done.<br><strong>Multiple servers?</strong> Add Redis Pub/Sub as the fan-out layer.<br><strong>Durable delivery?</strong> RabbitMQ or Kafka — messages survive restarts.</p>',
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'HTTP can\'t push — WebSocket keeps a persistent bidirectional connection',
      '<code>App::ws()</code> gives you onOpen/onMessage/onClose callbacks',
      '<code>Store</code> tables share state across workers for broadcasting to specific users',
      'Use htmx for user actions, SSE for streaming, WebSocket for real-time push, Pub/Sub for multi-server',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/ai-chat"
         hx-get="/api/learn/page?slug=learn/ai-chat" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/ai-chat">← AI Chat</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/routing"
         hx-get="/api/learn/page?slug=learn/routing" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/routing">Routes &amp; APIs →</a>
    </div>
  </article>
</div>
