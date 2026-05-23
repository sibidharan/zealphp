<?php use ZealPHP\App; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => 'learn/cross-server-chat']); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 23,
      'title'    => 'Cross-Server Chat',
      'subtitle' => 'Take a single-server WebSocket chat and scale it to N OpenSwoole servers. The marquee v0.2.39 feature, hands-on.',
      'prev'     => ['slug' => 'learn/chatroom', 'title' => 'Multi-Room Group Chat'],
      'next'     => ['slug' => 'learn/routing',   'title' => 'Routes & APIs'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Why <code>$fd</code> is process-local and what that means for scaling beyond one box',
      'How <code>Store::publish</code> + <code>App::subscribe</code> form a cross-server routing fabric',
      'The <code>ws_owner</code> pattern: a shared map of client &rarr; server &rarr; fd',
      'How the <code>WSRouter</code> helper bundles the whole pattern in five calls',
    ]]); ?>

    <h2 id="problem">The single-server chat hits a wall</h2>
    <p>
      In <a href="/learn/chatroom">Lesson 22</a> you built a multi-room chat on one <code>php app.php</code>
      instance. Every connected browser talks to the same OpenSwoole process; a message sent by user A
      lands in worker N and is pushed to user B&rsquo;s <code>$fd</code> right there. No coordination needed.
    </p>
    <p>
      Now you put two <code>php app.php</code> instances behind a load balancer &mdash; one on
      <code>:8080</code>, one on <code>:9090</code>. User A connects to the first, user B to the second.
      A sends &ldquo;hi&rdquo;. B never sees it. Why?
    </p>
    <pre><code class="language-text">       Browser A &harr; LB &rarr; [server :8080] worker 0  fd=12   user A
       Browser B &harr; LB &rarr; [server :9090] worker 1  fd=12   user B   &larr; different process!

  A sends &ldquo;hi&rdquo;. Server :8080 worker 0 receives it and tries
  $server-&gt;push($fd_of_B, &quot;hi&quot;)  &mdash; but $fd_of_B doesn&rsquo;t exist on :8080.
  The message dies in worker 0&rsquo;s memory. B never sees it.</code></pre>

    <h2 id="mental-model">The mental model: <code>$fd</code> is process-local</h2>
    <p>
      <code>$fd</code> is a per-process integer handle into OpenSwoole&rsquo;s worker-local connection table.
      Worker A&rsquo;s <code>$fd=12</code> is unrelated to worker B&rsquo;s <code>$fd=12</code> on the same
      process, and on a <em>different OpenSwoole process</em> the integer is meaningless &mdash;
      <code>$server-&gt;push($fd_from_other_process, "hi")</code> silently drops the message.
    </p>
    <p>
      Only the worker that <em>accepted the connection</em> on the <em>process that owns the listening
      socket</em> can push to that <code>$fd</code>. Everyone else has to route through it.
    </p>
    <p>
      That routing is what Lessons 22 build: a shared map saying &ldquo;client X is owned by server Y&rdquo;,
      plus a messaging fabric so any server can ask Y to push.
    </p>

    <h2 id="primitives">The three primitives</h2>
    <p>Three pieces, all already in ZealPHP &mdash; no extra packages, no custom infra.</p>

    <h3>1. A shared Store backend</h3>
    <p>
      The default <code>Store</code> uses <code>OpenSwoole\Table</code> &mdash; process-local shared memory.
      That&rsquo;s fast (nanoseconds) but stops at the process boundary. Flip to Redis with one line:
    </p>
<pre><code class="language-php">use ZealPHP\Store;

// In app.php, BEFORE $app-&gt;run() or App::run():
Store::defaultBackend(Store::BACKEND_REDIS);
// Or via env: ZEALPHP_STORE_BACKEND=redis ZEALPHP_REDIS_URL=redis://cache:6379</code></pre>
    <p>
      Every existing <code>Store::make()</code>, <code>Store::set()</code>, <code>Store::get()</code> call
      keeps working unchanged &mdash; now backed by Redis (or Valkey) and visible to every worker on every
      node. See <a href="/store#redis">/store#redis</a> for the full backend doc.
    </p>

    <h3>2. An ownership map</h3>
    <p>
      Each connected client needs to be discoverable from any server. A Store table keyed by
      <code>client_id</code> with two columns &mdash; the server identity and the worker-local fd:
    </p>
<pre><code class="language-php">Store::make('ws_owner', 4096, [
    'server' =&gt; [Store::TYPE_STRING, 64],   // e.g. "host-7:31415"
    'fd'     =&gt; [Store::TYPE_INT],
]);</code></pre>
    <p>
      &ldquo;Who owns Alice?&rdquo; becomes one Redis HGET. &ldquo;Alice disconnected&rdquo; becomes one
      Redis DEL. Cross-node consistency for free.
    </p>

    <h3>3. A per-server inbox</h3>
    <p>
      Each server identifies itself (hostname + pid is enough) and subscribes to a channel keyed on
      that identity. Messages published to <code>ws:server:$myId</code> only get delivered to <em>this</em>
      server &mdash; no broadcast storms, no filtering.
    </p>
<pre><code class="language-php">$myId = gethostname() . ':' . getmypid();

App::subscribe("ws:server:{$myId}", function (string $payload): void {
    $msg = json_decode($payload, true);
    // ... deliver locally to $msg['fd'] ...
});</code></pre>
    <p>
      <code>App::subscribe</code> spawns a dedicated subscriber coroutine per worker at
      <code>onWorkerStart</code>. Cleanup on shutdown is automatic. See
      <a href="/pubsub#quickstart">/pubsub#quickstart</a>.
    </p>

    <h2 id="build">Build it &mdash; four small deltas</h2>
    <p>
      Start from the <a href="/learn/chatroom">Lesson 22</a> chat. The four changes below are isolated &mdash;
      you can apply them one at a time and rerun your chat between each step.
    </p>

    <h3>Step 1: Switch the Store backend</h3>
<pre><code class="language-php">// app.php, near the top
use ZealPHP\Store;

Store::defaultBackend(Store::BACKEND_REDIS);

// Existing Store tables (chat history, presence, &hellip;) keep working unchanged.</code></pre>

    <h3>Step 2: Claim ownership on connect, release on disconnect</h3>
<pre><code class="language-php">$myId = gethostname() . ':' . getmypid();

// One-time: declare the ownership table BEFORE App::run()
Store::make('ws_owner', 4096, [
    'server' =&gt; [Store::TYPE_STRING, 64],
    'fd'     =&gt; [Store::TYPE_INT],
]);

App::ws('/chat',
    onMessage: function ($server, $frame) { /* handle inbound */ },

    onOpen: function ($server, $request) use ($myId) {
        $clientId = $request-&gt;get['user'] ?? bin2hex(random_bytes(8));
        Store::set('ws_owner', $clientId, [
            'server' =&gt; $myId,
            'fd'     =&gt; $request-&gt;fd,
        ]);
    },

    onClose: function ($server, $fd) use ($myId) {
        // Find which client owned this fd and drop the row.
        foreach (Store::iterate('ws_owner') as $clientId =&gt; $row) {
            if ($row['server'] === $myId &amp;&amp; (int)$row['fd'] === $fd) {
                Store::del('ws_owner', $clientId);
                break;
            }
        }
    }
);</code></pre>

    <h3>Step 3: Subscribe to your own inbox</h3>
<pre><code class="language-php">App::subscribe("ws:server:{$myId}", function (string $payload) use (&amp;$server): void {
    $msg = json_decode($payload, true);
    $fd  = (int)($msg['fd'] ?? 0);
    if ($fd &gt; 0 &amp;&amp; $server-&gt;isEstablished($fd)) {
        $server-&gt;push($fd, $msg['data']);
    }
});</code></pre>
    <p>
      <code>isEstablished</code> guards against the race where the client dropped between the publish
      and the local delivery &mdash; <code>push</code> on a dead fd is a no-op, but the guard avoids the
      stderr log noise.
    </p>

    <h3>Step 4: Send via lookup + publish, not direct push</h3>
<pre><code class="language-php">function sendToClient(string $clientId, string $data): bool {
    $owner = Store::get('ws_owner', $clientId);
    if (!$owner) {
        return false;   // client not connected anywhere we know about
    }
    Store::publish("ws:server:{$owner['server']}", json_encode([
        'fd'   =&gt; $owner['fd'],
        'data' =&gt; $data,
    ]));
    return true;
}

// Use it everywhere you previously called $server-&gt;push():
sendToClient('alice', json_encode(['from' =&gt; 'bob', 'text' =&gt; 'hi']));</code></pre>
    <p>
      That&rsquo;s the entire change. Local message? The owning server is you &mdash; one Redis publish,
      one local subscriber dispatch, one local push. Remote? Same code path, the publish crosses the
      network instead.
    </p>

    <h2 id="try-it-live">Try it live: two ports, one Redis</h2>
    <p>
      Set the Store backend via env, start two instances on different ports, open two browser tabs
      &mdash; one per port &mdash; and watch messages cross.
    </p>
<pre><code class="language-bash"># Terminal 1
ZEALPHP_STORE_BACKEND=redis \
ZEALPHP_REDIS_URL=redis://127.0.0.1:6379 \
php app.php start -p 8080

# Terminal 2
ZEALPHP_STORE_BACKEND=redis \
ZEALPHP_REDIS_URL=redis://127.0.0.1:6379 \
php app.php start -p 9090

# Browser
open http://localhost:8080/chat?user=alice
open http://localhost:9090/chat?user=bob

# Alice types "hi bob" — Bob sees it (delivered via Redis).
# Stop the :8080 instance — Bob stays connected to :9090 (his fd belongs there).
# Alice reconnects to :9090, the ws_owner row updates, sending resumes.</code></pre>
    <p>
      Don&rsquo;t have Redis running locally? Valkey (Redis-compatible) is a drop-in: <code>docker run
      -p 6379:6379 valkey/valkey:8</code>. The ZealPHP test suite uses Valkey on <code>:16379</code> &mdash;
      either is fine.
    </p>

    <h2 id="wsrouter">The <code>WSRouter</code> shortcut</h2>
    <p>
      Steps 2&ndash;4 above are the same five lines for every app that wants this pattern. The framework
      bundles them into <code>ZealPHP\WSRouter</code>:
    </p>
<pre><code class="language-php">use ZealPHP\WSRouter;

// app.php (before App::run())
// Defaults are demo-grade: 4,096 owner rows + 16,384 room-member rows. Bump
// inline for production (HARD CAPS on the Table backend; informational on Redis):
WSRouter::init(
    ownerCapacity:       200_000,    // max concurrent WS connections cluster-wide
    roomMembersCapacity: 1_000_000,  // max (room × member) pairs cluster-wide
    slowConsumerBytes:   4 * 1024 * 1024,  // per-fd send-queue drop threshold
);
// Or call WSRouter::initOptions(...) BEFORE init() — same setters, different shape.

App::ws('/chat',
    onMessage: function ($server, $frame) { /* &hellip; */ },
    onOpen:    function ($server, $request) {
                   WSRouter::own($request-&gt;get['user'], $request-&gt;fd);
               },
    onClose:   function ($server, $fd) {
                   // optional — release() looks up by client id if you tracked one
               },
);

// Anywhere:
WSRouter::sendToClient('alice', json_encode(['from' =&gt; 'bob', 'text' =&gt; 'hi']));
WSRouter::broadcast('chat:room:42', json_encode(['hello' =&gt; 'everyone']));</code></pre>
    <p>
      Same machinery, one boot call, two per-connection calls, two send helpers. Use the helper for
      new code; the four-step manual build above is for <em>understanding</em> what the helper is doing.
    </p>

    <h2 id="rooms">Beyond two servers &mdash; first-class rooms</h2>
    <p>
      Direct send (<code>sendToClient</code>) routes to <em>one</em> client. For &ldquo;every member of room
      42&rdquo; (chat rooms, presence, live leaderboards), the framework ships a <strong>first-class
      Room object</strong> &mdash; cluster-wide membership, presence events, fan-out broadcast, and a
      handler registry, all behind 4 verbs:
    </p>

    <h3 id="rooms-identity">First: how is the user identified?</h3>
    <p>
      The framework <strong>doesn&rsquo;t impose a user-identity scheme</strong> &mdash; you supply a
      stable string <code>$clientId</code> (session ID, user ID, email, JWT subject) and it&rsquo;s used
      consistently across the routing fabric. The typical wiring inside <code>onOpen</code>:
    </p>
<pre><code class="language-php">App::ws('/chat',
    onOpen: function ($server, $request) {
        // 1. Identify the user — pick ONE pattern that suits your app:
        //    a) Cookie session (Apache/PHP-mod parity, default in ZealPHP):
        $sessionId = $request-&gt;cookie['PHPSESSID'] ?? null;
        $username  = $_SESSION['username'] ?? 'guest';   // or via $g-&gt;session
        //    b) Query param (demos / quick tests): $request-&gt;get['user']
        //    c) JWT in `Sec-WebSocket-Protocol` header / Authorization: parse + verify
        if ($username === 'guest') { $server-&gt;disconnect($request-&gt;fd, WSRouter::CLOSE_AUTH_REQUIRED); return; }

        // 2. Register cluster-wide ownership using THAT identifier.
        //    $clientId is the value you'll thread through every send below.
        WSRouter::own($username, $request-&gt;fd);

        // 3. Join whatever rooms the user belongs to.
        WSRouter::room('chat:room:42')-&gt;join($username);
    },
    onClose: function ($server, $fd) {
        // Find which user owned this fd + release on disconnect:
        // (a `WSRouter::releaseByFd($fd)` helper is on the roadmap; for now
        //  apps store the reverse-map in $g-&gt;openswoole_request data.)
    },
);</code></pre>
    <p>
      Use <code>WSRouter::CLOSE_AUTH_REQUIRED</code> (4001) / <code>CLOSE_AUTH_INVALID</code> (4002) /
      <code>CLOSE_FORBIDDEN</code> (4003) when refusing the upgrade &mdash; clients can react to those
      codes specifically. The full set lives at <code>WSRouter::CLOSE_*</code>.
    </p>

    <h3>The 4 Room verbs (post-identity)</h3>
<pre><code class="language-php">use ZealPHP\WSRouter;

// In your handlers, $username is the SAME identifier you passed to WSRouter::own():
$room = WSRouter::room('chat:room:42');
$room-&gt;join($username);                 // SADD-equivalent + presence event broadcast cluster-wide

// From anywhere on any server. Payload schema is APP-DEFINED — the
// framework doesn't enforce a 'from' key. Common convention:
$room-&gt;push(
    ['from' =&gt; $username, 'text' =&gt; 'lunch!', 'ts' =&gt; time()],
    fromClientId: $username,            // optional — wires WS-4 per-client rate limit
);
$room-&gt;size();                                         // cluster-wide member count (SCARD)
$room-&gt;members();                                      // cluster-wide roster (SSCAN-drained)
$room-&gt;membersPaged($cursor, 100);                     // paginated roster for very large rooms

// Optional: handlers on EACH server that receive the broadcast + fan out
// to each server's locally-owned fds. Registered ONCE at boot.
$room-&gt;onMessage(function (array $msg, string $room) {
    // $msg is the decoded payload; push to your local fds here, e.g.
    // foreach (yourLocalFdsFor($room) as $fd) { $server-&gt;push($fd, json_encode($msg)); }
});
$room-&gt;onPresence(function (array $event, string $room) {
    // $event = ['type' =&gt; 'join'|'leave', 'client_id' =&gt; '...', 'ts' =&gt; ...]
    // — the 'client_id' here IS the value you passed to join().
});

// Cleanup:
$room-&gt;leave($username);</code></pre>
    <p>
      <strong>How it federates.</strong> A single <code>PSUBSCRIBE ws:room:*</code> pattern subscriber
      per worker covers every room you ever create &mdash; no per-room subscriber explosion. Membership
      lives in the cluster-wide <code>ws_room_members</code> Store table; size + roster lookups go via
      a per-room Redis SET (O(1) <code>SCARD</code>; paginated <code>SSCAN</code> for very large rooms).
      Server-side enforcement: filling a capped membership table throws <code>WS\CapacityException</code>
      with an actionable bump hint &mdash; the framework refuses to silently drop joins.
    </p>
    <p>
      <strong>The lower-level primitives</strong> (<code>WSRouter::broadcast($channel, $payload)</code>
      + <code>App::subscribe('your:channel:*', fn ...)</code>) are still available for cases that don&rsquo;t
      fit the Room shape &mdash; presence-only feeds, cross-app event buses, telemetry. They&rsquo;re what
      <code>WSRouter::room()</code> uses under the hood.
    </p>
    <p>
      No fan-out service to deploy &mdash; Redis pub/sub does it. For at-least-once delivery (audit
      logs, payments, work queues), swap <code>Store::publish</code> for <code>Store::publishReliable</code>
      and <code>App::subscribe</code> for <code>App::subscribeReliable</code> &mdash; same shape, backed by
      Redis Streams with consumer groups. See <a href="/pubsub#reliable">/pubsub#reliable</a>.
    </p>

    <h2 id="production">Production notes</h2>
    <ul>
      <li>
        <strong>Capacity defaults are demo-grade.</strong> <code>WSRouter::init()</code> creates
        <code>ws_owner</code> sized for <strong>4,096 rows</strong> (max concurrent WS connections
        cluster-wide) and <code>ws_room_members</code> sized for <strong>16,384 rows</strong>
        (max <code>(room × member)</code> pairs). On the Table backend these are HARD CAPS
        allocated at master fork — bump inline for production:
<pre><code class="language-php">WSRouter::init(
    ownerCapacity:        200_000,
    roomMembersCapacity:  1_000_000,
    slowConsumerBytes:    4 * 1024 * 1024,   // per-fd backpressure threshold
);</code></pre>
        On the Redis backend these are informational only (Redis is global KV with no per-table cap);
        set Redis-server <code>maxmemory</code> + <code>maxmemory-policy allkeys-lru</code> for
        cluster-wide bound there. Filling a capped table throws <code>WS\CapacityException</code>
        with an actionable hint — the framework refuses to silently drop owners.
      </li>
      <li>
        <strong>Driver choice.</strong> Both phpredis (preferred when <code>ext-redis</code> is loaded) and
        predis SUBSCRIBE loops yield correctly under <code>HOOK_ALL</code>. phpredis is ~2&times; faster on
        hot CRUD; pick it when you can. The only nuance: phpredis SUBSCRIBE blocks the worker
        <em>without</em> HOOK_ALL &mdash; HOOK_ALL is on by default in coroutine mode, so this only matters
        if you&rsquo;ve disabled it explicitly. See <a href="/store#phpredis-pubsub-caveat">/store#phpredis-pubsub-caveat</a>.
      </li>
      <li>
        <strong>Sticky load balancer?</strong> Doesn&rsquo;t matter &mdash; the routing fabric is keyed on
        <code>client_id</code>, not source IP. Sticky LBs reduce <code>ws_owner</code> churn (the same
        client always lands on the same server); non-sticky LBs just write more rows on reconnect. Pick
        whichever fits your infra.
      </li>
      <li>
        <strong>Owner-row staleness.</strong> If a server dies hard, its <code>ws_owner</code> rows linger
        until the clients reconnect (which they will &mdash; the LB sends them elsewhere). Add a TTL on
        the table (<code>Store::make('ws_owner', &hellip;, ['mode' =&gt; 'ttl', 'ttl' =&gt; 3600])</code>) for
        automatic cleanup, or wire <code>App::onWorkerStop</code> to release this worker&rsquo;s rows on
        graceful shutdown.
      </li>
      <li>
        <strong>Cross-region?</strong> Redis pub/sub is in-cluster; for multi-region you want a Redis
        replica in each region with publishing pinned to a primary, or a dedicated message bus
        (NATS, Kafka). Same routing pattern, different transport.
      </li>
    </ul>

    <h2 id="takeaways">Key takeaways</h2>
    <ul>
      <li><code>$fd</code> is process-local. Cross-server WebSocket delivery needs a routing fabric &mdash;
        you can&rsquo;t just push from a worker that didn&rsquo;t accept the connection.</li>
      <li><code>Store::publish</code> is fire-and-forget pub/sub (cache invalidation, WS routing, presence).
        <code>Store::publishReliable</code> is at-least-once via Redis Streams (orders, audit, work queues).</li>
      <li><code>WSRouter</code> bundles the &ldquo;owner of fd pushes; everyone else publishes&rdquo; pattern
        in five calls. Use the helper for new code; the manual four-step build is the mental model.</li>
      <li>One <code>Store::defaultBackend(Store::BACKEND_REDIS)</code> line takes the WHOLE app from
        single-box to multi-node &mdash; sessions, cache, counters, ws routing, the lot.</li>
    </ul>

    <p>
      Want the portfolio-page deep dive? See <a href="/pubsub">/pubsub</a> for the API reference + live
      demo, <a href="/ws#scaling">/ws#scaling</a> for the cross-server scaling story, and
      <a href="/store#pubsub">/store#pubsub</a> for the receiver-count semantics.
    </p>
  </article>
</div>
