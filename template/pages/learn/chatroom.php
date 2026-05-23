<?php
use ZealPHP\App;
$user = $user ?? \ZealPHP\Learn\Auth::currentUser();
?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => 'learn/chatroom']); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 22,
      'title'    => 'Multi-Room Group Chat',
      'subtitle' => 'A real chat app — multi-room, persistent history, presence — using nothing but PHP and SQLite. No Redis. No Node. No Docker.',
      'prev'     => ['slug' => 'learn/tictactoe',        'title' => 'Tic-Tac-Toe'],
      'next'     => ['slug' => 'learn/cross-server-chat','title' => 'Cross-Server Chat'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'How to model rooms + messages in SQLite (the bundled-with-PHP database)',
      'The WebSocket handler pattern for join &rarr; broadcast &rarr; persist &rarr; replay',
      'Per-room fan-out via worker-local fd maps &mdash; no Redis required on one server',
      'How to upgrade the same chat to N servers by swapping one fan-out helper',
    ]]); ?>

    <h2 id="why">Why this matters</h2>
    <p>
      Real chat apps have <strong>rooms</strong> and <strong>history</strong>. Slack&rsquo;s
      <code>#general</code>, Discord&rsquo;s server channels, your team&rsquo;s WhatsApp groups &mdash; they
      all share the same shape: many rooms, many users per room, messages persist across reloads, presence
      shows who&rsquo;s here right now. Every real chat product is this pattern.
    </p>
    <p>
      In Lesson 19 you built a real-time chat that worked while you were connected; reload the page and
      everything was gone. In <a href="/learn/tictactoe">Lesson 21</a> you shared per-board state through
      <code>OpenSwoole\Table</code>. This lesson puts the two ideas together: <strong>rooms with
      persistent history</strong>. The whole stack is PHP + SQLite. SQLite ships <em>inside PHP</em> &mdash;
      you don&rsquo;t install anything; it&rsquo;s already there.
    </p>

    <div class="callout info">
      <strong>What you&rsquo;ll build today.</strong> A pure-PHP chat that runs on a single
      <code>php app.php</code> process. Users open a tab, pick a username, join a room (<code>#general</code>
      / <code>#engineering</code> / whatever they type), see the room&rsquo;s last 50 messages instantly,
      send new ones, watch other users join + leave in real time. Refresh the page &mdash; history persists.
      The next lesson shows how to scale this chat to N servers by swapping one helper. Same code, federated.
    </div>

    <h2 id="data-model">The data model &mdash; one SQLite table</h2>
    <p>
      ZealPHP&rsquo;s learn lessons already use SQLite (Lesson 18 stores notes). We piggyback on the same
      database file (<code>storage/learn.db</code>) and add one table:
    </p>
<pre><code class="language-sql">CREATE TABLE chatroom_messages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    room       TEXT NOT NULL,
    username   TEXT NOT NULL,
    body       TEXT NOT NULL,
    kind       TEXT NOT NULL DEFAULT 'message',  -- 'message' or 'system' (join/leave)
    created_at INTEGER NOT NULL
);
CREATE INDEX idx_chatroom_room_time ON chatroom_messages(room, created_at);</code></pre>
    <p>
      That&rsquo;s the schema. Rooms aren&rsquo;t a separate table &mdash; a room <em>is</em> just a value
      in the <code>room</code> column. SQLite&rsquo;s composite index gives O(log n) lookup &ldquo;last N
      messages in this room&rdquo;. For a small chat (thousands of rooms, millions of messages) this
      single-table model is plenty.
    </p>

    <h2 id="model">A small model class — three pure-PHP functions</h2>
    <p>
      Everything the chat does to SQLite collapses to three methods. This is
      <code>src/Learn/Chatroom.php</code> shipped with the demo:
    </p>
<pre><code class="language-php">final class Chatroom
{
    public static function saveMessage(string $room, string $user, string $body, string $kind = 'message'): array
    {
        $db = DB::open();
        $now = time();
        $stmt = $db-&gt;prepare(
            'INSERT INTO chatroom_messages (room, username, body, kind, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt-&gt;execute([$room, $user, $body, $kind, $now]);
        return ['id' =&gt; (int)$db-&gt;lastInsertId(), 'room' =&gt; $room, 'username' =&gt; $user,
                'body' =&gt; $body, 'kind' =&gt; $kind, 'created_at' =&gt; $now];
    }

    public static function recent(string $room, int $tail = 50): array
    {
        $db = DB::open();
        $stmt = $db-&gt;prepare(
            'SELECT id, room, username, body, kind, created_at FROM chatroom_messages
             WHERE room = ? ORDER BY id DESC LIMIT ?'
        );
        $stmt-&gt;bindValue(1, $room, PDO::PARAM_STR);
        $stmt-&gt;bindValue(2, $tail, PDO::PARAM_INT);
        $stmt-&gt;execute();
        return array_reverse($stmt-&gt;fetchAll());   // chronological order
    }

    public static function listRooms(): array
    {
        $db = DB::open();
        $stmt = $db-&gt;query(
            'SELECT room, MAX(created_at) AS last_msg_at, COUNT(*) AS count
             FROM chatroom_messages GROUP BY room ORDER BY last_msg_at DESC'
        );
        return $stmt-&gt;fetchAll();
    }
}</code></pre>
    <p>
      That&rsquo;s the whole persistence layer. No ORM, no framework magic &mdash;
      <code>PDO::prepare</code> + parameter binding handles every interaction. Three methods cover the
      three reads/writes a chat needs: <strong>save</strong> a message, <strong>recall</strong> the last N
      in a room, <strong>list</strong> all active rooms.
    </p>

    <h2 id="ws-handler">The WebSocket handler &mdash; the entire interactive layer</h2>
    <p>
      The chat&rsquo;s real-time half lives in one <code>App::ws()</code> registration. Three event types:
      <code>join</code>, <code>message</code>, <code>leave</code>. The handler keeps a worker-local fd map
      to know who&rsquo;s in which room, so it can fan out to just the relevant connections:
    </p>
<pre><code class="language-php">$roomFds = [];     // room → [fd → true]
$fdMeta  = [];     // fd → {room, username}

$app-&gt;ws('/ws/learn/chatroom',
    function ($server, $frame) use (&amp;$roomFds, &amp;$fdMeta) {
        $msg = json_decode($frame-&gt;data, true);

        if ($msg['type'] === 'join') {
            $room = $msg['room'] ?? 'general';
            $user = $msg['username'] ?? 'anonymous';
            $fdMeta[$frame-&gt;fd]       = ['room' =&gt; $room, 'username' =&gt; $user];
            $roomFds[$room][$frame-&gt;fd] = true;

            // Send history to the joining client only.
            $server-&gt;push($frame-&gt;fd, json_encode([
                'type' =&gt; 'history',
                'items' =&gt; Chatroom::recent($room, 50),
            ]));

            // Announce the join to everyone in the room.
            $sys = Chatroom::saveMessage($room, $user, "joined #{$room}", 'system');
            broadcast_to_room($server, $roomFds, $room, ['type' =&gt; 'message', 'message' =&gt; $sys]);

        } elseif ($msg['type'] === 'message') {
            $meta = $fdMeta[$frame-&gt;fd];
            $row = Chatroom::saveMessage($meta['room'], $meta['username'], $msg['body']);
            broadcast_to_room($server, $roomFds, $meta['room'], ['type' =&gt; 'message', 'message' =&gt; $row]);
        }
    },
    onClose: function ($server, $fd) use (&amp;$roomFds, &amp;$fdMeta) {
        if (isset($fdMeta[$fd])) {
            $meta = $fdMeta[$fd];
            unset($roomFds[$meta['room']][$fd], $fdMeta[$fd]);
            $sys = Chatroom::saveMessage($meta['room'], $meta['username'], "left #{$meta['room']}", 'system');
            broadcast_to_room($server, $roomFds, $meta['room'], ['type' =&gt; 'message', 'message' =&gt; $sys]);
        }
    },
);

function broadcast_to_room($server, &amp;$roomFds, string $room, array $payload): void
{
    if (!isset($roomFds[$room])) return;
    $data = json_encode($payload);
    foreach (array_keys($roomFds[$room]) as $fd) {
        if ($server-&gt;isEstablished($fd)) {
            $server-&gt;push($fd, $data);
        }
    }
}</code></pre>
    <p>
      <strong>What just happened.</strong> Five components — a handler, two state arrays, a fan-out
      helper, and a model. ~70 lines of PHP total. A working multi-room chat with persistence.
    </p>

    <h2 id="rest">Tiny REST sidekick &mdash; the lobby + initial paint</h2>
    <p>
      Two GET endpoints power the room list + initial paint (so the page renders quickly even before the
      WS opens):
    </p>
<pre><code class="language-php">$app-&gt;route('/api/learn/chatroom/lobby',
    fn() =&gt; ['ok' =&gt; true, 'rooms' =&gt; Chatroom::listRooms()],
);

$app-&gt;route('/api/learn/chatroom/recent',
    fn($request) =&gt; [
        'ok' =&gt; true,
        'room' =&gt; $request-&gt;get['room'] ?? 'general',
        'items' =&gt; Chatroom::recent($request-&gt;get['room'] ?? 'general', 50),
    ],
);</code></pre>

    <h2 id="ui">The UI &mdash; htmx + vanilla JS</h2>
    <p>
      The front-end is straightforward: a room picker, an input field, a messages list. <code>htmx</code>
      handles initial paint via <code>hx-get</code>; a small JS opens the WS and appends new messages on
      receive. ZealPHP&rsquo;s site uses this same pattern in <code>template/components/_chatroom_widget.php</code>
      &mdash; one file, no build step, no framework. The framework already wires htmx + persistent
      assets across navigations, so this lesson&rsquo;s widget is just markup + a tiny <code>&lt;script&gt;</code>.
    </p>

    <h2 id="try-it">Try it &mdash; right here</h2>
    <p>
      The widget below uses your logged-in <code>username</code> from the same session that powers the
      Personal Notes + Tic-Tac-Toe lessons. Same auth, no separate sign-in. Type a room name (or pick
      one from the lobby), click <strong>Join</strong>, send messages. Open the popout in a second
      tab to chat with yourself; open it in a friend&rsquo;s browser to chat across the network.
    </p>

    <?php if (!$user): ?>
      <?php App::render('/components/_callout', [
        'variant' => 'warn',
        'title'   => 'Log in to chat',
        'body'    => '<p><a href="/learn/auth">Register or log in</a> first, then come back here.</p>',
      ]); ?>
    <?php else: ?>
      <?php App::render('/components/_chatroom_widget', ['user' => $user]); ?>
      <a class="lesson-popout-cta" href="/demo/view/chatroom/widget" target="_blank" rel="noopener">
        Open the chat in a new tab ↗
      </a>
      <p class="lttt-note">
        Reload the page &mdash; history persists. Refresh in three tabs &mdash; everyone sees the same backlog.
        That&rsquo;s SQLite earning its keep. Open the popout in a friend&rsquo;s browser and they&rsquo;ll
        join the same room with their own logged-in username.
      </p>
    <?php endif; ?>

    <h3 id="api">API surface (for hacking)</h3>
    <ul class="store-col-list">
      <li><code>GET /api/learn/chatroom/lobby</code> &mdash; what rooms exist right now</li>
      <li><code>GET /api/learn/chatroom/recent?room=general</code> &mdash; last 50 messages in <code>#general</code></li>
      <li><code>ws://&lt;host&gt;:8080/ws/learn/chatroom</code> &mdash; the WebSocket endpoint (frames described above)</li>
    </ul>

    <h2 id="scaling">Going multi-server &mdash; one swap, federated chat</h2>
    <p>
      Everything above works on ONE <code>php app.php</code> process. To go to N servers, the only thing
      that has to change is the fan-out:
    </p>

    <div class="store-grid-tight">
      <div>
        <h3 class="store-col-good">Single-server (this lesson)</h3>
        <pre><code class="language-php">$roomFds = [];
// onMessage handler:
$roomFds[$room][$fd] = true;
broadcast_to_room($server, $roomFds, $room, $payload);</code></pre>
        <p>Local fd map; push directly. Zero infrastructure.</p>
      </div>
      <div>
        <h3 class="store-col-bad">Multi-server (Lesson 23)</h3>
        <pre><code class="language-php">Store::defaultBackend(Store::BACKEND_REDIS);
WSRouter::init();
// onMessage handler:
$room = WSRouter::room('chat:' . $name);
$room-&gt;join($username);
$room-&gt;push($payload);</code></pre>
        <p>Cluster-wide membership + pub/sub fan-out. Same handler shape; different fabric.</p>
      </div>
    </div>

    <p>
      The chat persists to SQLite either way &mdash; that&rsquo;s the durable layer. Redis only enters
      the picture when you have multiple <code>php app.php</code> processes that need to share live state.
      Pure-PHP-and-SQLite covers a remarkable fraction of real apps; you can postpone Redis until you have
      a reason. <a href="/learn/cross-server-chat">Lesson 23</a> shows the upgrade in detail.
    </p>

    <h2 id="takeaways">Key takeaways</h2>
    <ul>
      <li><strong>Chat is small.</strong> Multi-room with persistence + presence is about 100 lines of PHP
        + one SQLite table. The framework isn&rsquo;t hiding work; it&rsquo;s just modest in scope.</li>
      <li><strong>SQLite is real.</strong> One file on disk, ACID, zero setup. Millions of rows per room
        is fine. Move to Postgres when you need multi-writer; until then, save yourself the operational cost.</li>
      <li><strong>The WebSocket handler is the whole interactive layer.</strong> Three event types
        (join/message/leave), one fan-out helper, two state arrays. That&rsquo;s the entire pattern.</li>
      <li><strong>Federation is one swap.</strong> Same code, switch the fan-out from a local fd map to
        <code>WSRouter::room()</code>. <a href="/learn/cross-server-chat">Lesson 23</a> covers this.</li>
    </ul>

    <p>
      Source on disk: model at <code>src/Learn/Chatroom.php</code>, WS handler at
      <code>route/learn_chatroom.php</code>. Live entrypoint at <code>/api/learn/chatroom/lobby</code> &mdash;
      explore the room list, hit <code>/api/learn/chatroom/recent</code> for any room&rsquo;s history,
      open the WebSocket at <code>/ws/learn/chatroom</code> to chat.
    </p>
  </article>
</div>
