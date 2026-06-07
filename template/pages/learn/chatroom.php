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
      'Per-room fan-out via a shared <code>Store</code> table &mdash; works across all workers on one server',
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
      In <a href="/learn/websocket">Lesson 19</a> you built a real-time counter that broadcast +1 to
      every open tab &mdash; it worked while you were connected, but a reload wiped the state. In
      <a href="/learn/tictactoe">Lesson 21</a> you shared per-board state through
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
      <code>ZealPHP\Learn\Chatroom</code> (already in <code>vendor/</code> via the framework):
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
      <code>join</code>, <code>message</code>, <code>leave</code>. The handler keeps the fd&rarr;room map in a
      <strong>shared <code>Store</code> table</strong> &mdash; not a worker-local array &mdash; so any
      worker can fan out to any connection. (OpenSwoole runs multiple workers by default; a worker-local
      array is invisible to every other worker, silently breaking cross-worker fan-out.)
    </p>
<pre><code class="language-php">use ZealPHP\Learn\Chatroom;   // model + fan-out helper live in a src/ class
use ZealPHP\Store;

// Cluster-wide fd map — must be created BEFORE App::run() forks workers.
// Route files load at boot time, so this is the right place.
Store::make('chatroom_fds', 4096, [
    'room'     =&gt; [Store::TYPE_STRING, 64],
    'username' =&gt; [Store::TYPE_STRING, 64],
]);

$app-&gt;ws('/ws/learn/chatroom', function ($server, $frame) {
    $msg  = json_decode((string) $frame-&gt;data, true);
    if (!is_array($msg)) { return; }
    $type = is_string($msg['type'] ?? null) ? $msg['type'] : '';

    if ($type === 'join') {
        $room     = is_string($msg['room'] ?? null)     ? $msg['room']     : 'general';
        $username = is_string($msg['username'] ?? null) ? $msg['username'] : 'anonymous';

        // Record membership in shared memory (visible to all workers).
        Store::set('chatroom_fds', (string) $frame-&gt;fd, [
            'room'     =&gt; $room,
            'username' =&gt; $username,
        ]);

        // Send history to the joining client only.
        $server-&gt;push($frame-&gt;fd, (string) json_encode([
            'type'  =&gt; 'history',
            'room'  =&gt; $room,
            'items' =&gt; Chatroom::recent($room, 50),
        ]));

        // Persist + broadcast a system "X joined" line to everyone in the room.
        $sys = Chatroom::saveMessage($room, $username, "joined #{$room}", 'system');
        Chatroom::broadcast_to_room($server, $room, ['type' =&gt; 'message', 'message' =&gt; $sys]);
        return;
    }

    if ($type === 'message') {
        $meta = Store::get('chatroom_fds', (string) $frame-&gt;fd);
        if (!is_array($meta)) { return; }
        $body = is_string($msg['body'] ?? null) ? $msg['body'] : '';
        if (trim($body) === '') { return; }
        $row = Chatroom::saveMessage((string) $meta['room'], (string) $meta['username'], $body);
        Chatroom::broadcast_to_room($server, (string) $meta['room'], ['type' =&gt; 'message', 'message' =&gt; $row]);
        return;
    }
}, onClose: function ($server, $fd) {
    $meta = Store::get('chatroom_fds', (string) $fd);
    Store::del('chatroom_fds', (string) $fd);
    if (!is_array($meta)) { return; }
    $sys = Chatroom::saveMessage((string) $meta['room'], (string) $meta['username'], "left #{$meta['room']}", 'system');
    Chatroom::broadcast_to_room($server, (string) $meta['room'], ['type' =&gt; 'message', 'message' =&gt; $sys]);
});</code></pre>
    <p>
      The fan-out helper isn&rsquo;t a top-level function in the route file &mdash; route files stay thin.
      It lives as a <code>public static function</code> on the same <code>ZealPHP\Learn\Chatroom</code>
      model class (autoloaded via PSR-4), so the handler calls it as
      <code>Chatroom::broadcast_to_room(...)</code>:
    </p>
<pre><code class="language-php">// src/Learn/Chatroom.php — helpers live in a src/ class, not the route file.
final class Chatroom
{
    // ... saveMessage() / recent() / listRooms() above ...

    /**
     * Fan-out: iterate the cluster-wide fd map and push to every fd in the room.
     * Works across workers (any worker can $server-&gt;push any fd) and across the
     * cluster when the Store backend is Redis — federated chat for free.
     *
     * @param array&lt;string, mixed&gt; $payload
     */
    public static function broadcast_to_room($server, string $room, array $payload, int $excludeFd = 0): void
    {
        $data = (string) json_encode($payload);
        foreach (Store::iterate('chatroom_fds') as $fd =&gt; $info) {
            if (($info['room'] ?? null) !== $room) { continue; }
            $fdInt = (int) $fd;
            if ($fdInt === $excludeFd) { continue; }
            if ($server-&gt;isEstablished($fdInt)) {
                $server-&gt;push($fdInt, $data);
            }
        }
    }
}</code></pre>
    <p>
      <strong>What just happened.</strong> The key insight is <code>Store::make</code> before
      <code>App::run()</code>: the table lives in <code>OpenSwoole\Table</code> shared memory, so every
      worker sees every fd&rsquo;s room membership. <code>Chatroom::broadcast_to_room()</code> iterates the
      whole table and pushes to matching fds &mdash; any worker can push to any fd. ~70 lines of PHP total.
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

    <h2 id="typing">Typing indicator &mdash; ephemeral presence in 3 small parts</h2>
    <p>
      &ldquo;<em>alice is typing&hellip;</em>&rdquo; needs three pieces. None of them touch SQLite &mdash;
      typing is <strong>presence</strong>, not history. It lives only while the WebSocket is open.
    </p>

    <h3>1. Client: debounced send on input</h3>
    <p>
      Each keystroke schedules a <code>typing: 'on'</code> frame (sent once, deduped); after 2.5 s
      of inactivity OR an empty input OR a sent message, send <code>typing: 'off'</code>.
    </p>
<pre><code class="language-javascript">// Single 'on' burst, refreshed every keystroke; 'off' on idle / empty / send.
const TYPING_IDLE_MS = 2500;
let lastSent = 'off';
let idleTimer = null;

function sendTyping(state) {
    if (lastSent === state) return;        // dedup repeats
    lastSent = state;
    ws.send(JSON.stringify({ type: 'typing', state }));
}

body.addEventListener('input', () =&gt; {
    if (body.value.length === 0) { sendTyping('off'); clearTimeout(idleTimer); return; }
    sendTyping('on');
    clearTimeout(idleTimer);
    idleTimer = setTimeout(() =&gt; sendTyping('off'), TYPING_IDLE_MS);
});
form.addEventListener('submit', () =&gt; { sendTyping('off'); clearTimeout(idleTimer); /* …send msg… */ });</code></pre>

    <h3>2. Server: ephemeral fan-out (no SQLite, skip sender)</h3>
    <p>
      Treat <code>typing</code> like a message except: <strong>don&rsquo;t persist</strong>, and
      <strong>don&rsquo;t echo to the sender</strong>. The <code>excludeFd</code> parameter on
      <code>Chatroom::broadcast_to_room()</code> does the latter.
    </p>
<pre><code class="language-php">if ($type === 'typing') {
    $meta = Store::get('chatroom_fds', (string) $frame-&gt;fd);
    if (!is_array($meta)) { return; }
    $state = ($msg['state'] ?? '') === 'on' ? 'on' : 'off';
    Chatroom::broadcast_to_room(
        $server,
        (string) $meta['room'],
        ['type' =&gt; 'typing', 'user' =&gt; (string) $meta['username'], 'state' =&gt; $state],
        excludeFd: (int) $frame-&gt;fd,   // skip the sender's own echo
    );
    return;
}</code></pre>

    <h3>3. Client: per-user state map + auto-clear timeout</h3>
    <p>
      Track a per-user typing flag with a watchdog timeout (4 s) so a dropped <code>off</code> frame
      doesn&rsquo;t leave &ldquo;alice is typing&hellip;&rdquo; on the screen forever. Render comma-joined names.
    </p>
<pre><code class="language-javascript">const TYPING_TIMEOUT_MS = 4000;
const typingUsers = Object.create(null);

function handleTypingEvent(user, state) {
    if (!user || user === selfUsername) return;     // ignore self-echoes (server already skipped)
    clearTimeout(typingUsers[user]);
    if (state === 'on') {
        typingUsers[user] = setTimeout(() =&gt; { delete typingUsers[user]; renderTyping(); }, TYPING_TIMEOUT_MS);
    } else {
        delete typingUsers[user];
    }
    renderTyping();
}

function renderTyping() {
    const names = Object.keys(typingUsers);
    typingIndicator.textContent =
        names.length === 0 ? '' :
        names.length === 1 ? `${names[0]} is typing…` :
        names.length === 2 ? `${names[0]} and ${names[1]} are typing…` :
        `${names.length} people are typing…`;
}</code></pre>
    <p>
      <strong>Why this scales.</strong> The whole thing runs on the existing WebSocket &mdash; no extra
      connection, no extra Redis key, no extra SQLite row. Typing events <em>are</em> the only
      cluster-wide thing that&rsquo;s OK to lose (a dropped &ldquo;off&rdquo; clears on the 4-second
      watchdog), so we don&rsquo;t need at-least-once delivery. Goes federated automatically on the
      Redis backend &mdash; same one-line swap as message broadcast.
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
        <pre><code class="language-php">// Default: OpenSwoole\Table — in-process shared memory.
Store::make('chatroom_fds', 4096, [...]);
// onMessage handler:
Store::set('chatroom_fds', (string) $frame-&gt;fd, ['room' =&gt; $room, ...]);
Chatroom::broadcast_to_room($server, $room, $payload);</code></pre>
        <p>Shared memory table; all workers on one server see every fd. Zero extra infrastructure.</p>
      </div>
      <div>
        <h3 class="store-col-bad">Multi-server (Lesson 23)</h3>
        <pre><code class="language-php">Store::defaultBackend(Store::BACKEND_REDIS);
WSRouter::init();
// onMessage handler:
$room = WSRouter::room('chat.' . $name);  // room names allow [A-Za-z0-9_.-] — no ':' (#247)
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
        (join/message/leave), one shared <code>Store</code> table, one fan-out helper. That&rsquo;s the entire pattern.</li>
      <li><strong>Federation is one swap.</strong> Same code, switch the Store backend from
        <code>Store::BACKEND_TABLE</code> (shared memory) to <code>Store::BACKEND_REDIS</code> and use
        <code>WSRouter::room()</code> for pub/sub fan-out. <a href="/learn/cross-server-chat">Lesson 23</a> covers this.</li>
    </ul>

    <p>
      Source on disk: model at <code>src/Learn/Chatroom.php</code>, WS handler at
      <code>route/learn_chatroom.php</code>. Live entrypoint at <code>/api/learn/chatroom/lobby</code> &mdash;
      explore the room list, hit <code>/api/learn/chatroom/recent</code> for any room&rsquo;s history,
      open the WebSocket at <code>/ws/learn/chatroom</code> to chat.
    </p>
  </article>
</div>
