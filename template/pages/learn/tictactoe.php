<?php use ZealPHP\App;
$user = \ZealPHP\Learn\Auth::currentUser();
$active = $active ?? 'learn/tictactoe';
?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 21,
      'title'    => 'Multiplayer Tic-Tac-Toe',
      'subtitle' => 'Two players, one room ID, real-time gameplay over WebSocket. The Build-the-App capstone.',
      'prev'     => ['slug' => 'learn/ai-chat', 'title' => 'AI Chat'],
      'next'     => ['slug' => 'learn/routing',  'title' => 'Routes & APIs'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Pair two players by room ID — the same pattern any multiplayer lobby uses',
      'Store shared game state in <code>OpenSwoole\\Table</code> for cross-worker access',
      'Validate moves server-side: who, when, where — never trust the client',
      'Fan-out broadcasts to spectators (unlimited) while keeping seats limited to two',
      'Handle disconnects, reconnects, and an alternating starter across rounds',
    ]]); ?>

    <h2 id="step-setup">1. Setup — what you&rsquo;re building</h2>
    <p>
      A multiplayer tic-tac-toe game where two players in the same browser, in different browsers, or
      on different continents enter the <strong>same room ID</strong> and play live. Every move flows
      through one WebSocket; every connected tab in that room sees the move within milliseconds. Extra
      tabs join as <strong>viewers</strong> and watch the game without taking a seat — like a stream
      with chat-style fan-out.
    </p>
    <p>
      You&rsquo;ll need to <a href="/learn/auth">log in</a> first — the server identifies players by
      their session so it can show display names and stop strangers from hijacking a seat. The
      multiplayer mechanics work the same with or without auth; we just pick auth here because Notes
      and Chat already require it and we&rsquo;re reusing the same login flow.
    </p>

    <?php if (!$user): ?>
      <section class="auth-card">
        <h2>Sign in to play</h2>
        <p>Pick any username and password. The game uses your username as your display name.</p>
        <form hx-post="/api/learn/login" hx-target="#ttt-auth-fb-login" hx-swap="innerHTML">
          <input type="text" name="username" placeholder="username" autocomplete="username" required minlength="3" maxlength="64">
          <input type="password" name="password" placeholder="password (8+ chars)" autocomplete="current-password" required minlength="8">
          <button type="submit">Log in</button>
          <div id="ttt-auth-fb-login"></div>
        </form>
        <details class="lttt-details">
          <summary>New here? Register</summary>
          <form hx-post="/api/learn/register" hx-target="#ttt-auth-fb-reg" hx-swap="innerHTML" class="lttt-reg-form">
            <input type="text" name="username" placeholder="new username" required minlength="3" maxlength="64">
            <input type="password" name="password" placeholder="new password" required minlength="8">
            <button type="submit" class="auth-toggle">Register</button>
            <div id="ttt-auth-fb-reg"></div>
          </form>
        </details>
      </section>
    <?php else: ?>
      <p>Logged in as <strong><?= htmlspecialchars($user['username']) ?></strong>. Pick a room ID below — share it with whoever you want to play.</p>
    <?php endif; ?>

    <h2 id="step-state">2. Game state — two Store tables</h2>
    <p>
      OpenSwoole&rsquo;s <code>Table</code> is shared memory: every worker process can read and write
      the same row by key. Two tables here:
    </p>
    <ul>
      <li><strong><code>ws_tictactoe_rooms</code></strong> — one row per active room. Holds the board,
        whose turn it is, the winner (if any), the two players&rsquo; fds, names, and the
        alternating starter for the next round.</li>
      <li><strong><code>ws_tictactoe_clients</code></strong> — one row per connected fd. Stores the
        room the fd is in and the symbol it owns (X, O, or S for spectator). The broadcaster uses this
        to filter which fds should receive a state push.</li>
    </ul>
    <p>
      Why two tables? The room state is one logical entity that any player or viewer reads from;
      the client table is the join key — &ldquo;who is in this room and what role do they have?&rdquo;
      It&rsquo;s the same shape <code>/ws/session-counter</code> uses to broadcast only to fds in the
      same session — we just swap <code>session_id</code> for <code>room</code>.
    </p>
    <pre><code class="language-php">// route/learn.php — boot scope, before $app->run()
Store::make('ws_tictactoe_rooms', 1024, [
    'board'   => [Table::TYPE_STRING, 9],   // '_________' 9 chars: '_' | 'X' | 'O'
    'turn'    => [Table::TYPE_STRING, 2],   // 'X' | 'O' | '' when finished
    'winner'  => [Table::TYPE_STRING, 8],   // '' | 'X' | 'O' | 'draw'
    'px_fd'   => [Table::TYPE_INT,    8],   // 0 = X seat empty
    'po_fd'   => [Table::TYPE_INT,    8],
    'px_name' => [Table::TYPE_STRING, 32],
    'po_name' => [Table::TYPE_STRING, 32],
    'starter' => [Table::TYPE_STRING, 2],   // alternates each new round
    'rounds'  => [Table::TYPE_INT,    4],
]);
Store::make('ws_tictactoe_clients', 4096, [
    'room'   => [Table::TYPE_STRING, 32],
    'name'   => [Table::TYPE_STRING, 32],
    'symbol' => [Table::TYPE_STRING, 2],
    'joined' => [Table::TYPE_INT,    8],
]);</code></pre>
    <p>
      The board is a 9-character string (<code>"_________"</code>, then
      <code>"X___O____"</code>, etc.) — easier to log and serialize than nested arrays, fits in
      a tiny fixed-size column, and you can <code>substr_replace</code> a cell in one call.
    </p>

    <h2 id="step-pairing">3. WebSocket pairing — assigning seats</h2>
    <p>
      The endpoint <code>/ws/tictactoe?room=alpha-1</code> uses the same lifecycle pattern you learned
      in the WebSocket lesson — <code>onOpen</code>, <code>onMessage</code>, <code>onClose</code>.
      <code>onOpen</code> does two things: authenticate (read the session via
      <code>G::instance()-&gt;session</code>) and pick a seat:
    </p>
    <pre><code class="language-php">onOpen: function ($server, $request) {
    $g        = G::instance();
    $userId   = (int)    ($g->session['user_id']  ?? 0);
    $username = (string) ($g->session['username'] ?? '');
    if (!$userId || $username === '') {
        $server->disconnect($request->fd, 1008, 'auth_required'); return;
    }
    $room = ttt_sanitize_room((string)($request->get['room'] ?? ''));
    if ($room === '') { $server->disconnect($request->fd, 1008, 'no_room'); return; }
    $viewMode = ((string)($request->get['view'] ?? '')) === '1';

    $row = Store::get('ws_tictactoe_rooms', $room) ?? seed_new_room($room);

    $symbol = 'S';                                       // default: spectator
    if (!$viewMode) {
        if ((int)$row['px_fd'] === 0)      { $symbol = 'X'; claim_x_seat($room, $request->fd, $username); }
        elseif ((int)$row['po_fd'] === 0)  { $symbol = 'O'; claim_o_seat($room, $request->fd, $username); }
    }
    Store::set('ws_tictactoe_clients', (string)$request->fd, compact('room','username','symbol') + ['joined' => time()]);
    $server->push($request->fd, json_encode(['type'=>'welcome', 'symbol'=>$symbol, 'room'=>$room]));
    ttt_broadcast_state($room);
}</code></pre>
    <p>
      First fd in a room takes X; second takes O; everyone after gets <code>'S'</code> (spectator).
      The optional <code>?view=1</code> query string forces spectator mode even when a seat is open —
      useful for casting the game without participating. The result: at most two players, unlimited
      viewers. That&rsquo;s what the user-message constraint &ldquo;only 2 people can connect, plus
      viewer mode that fans out&rdquo; reduces to in code.
    </p>

    <h2 id="step-moves">4. Player moves — never trust the client</h2>
    <p>
      Every player action arrives over the socket as a JSON message. The server validates everything:
      who you are, whose turn it is, whether the cell is empty, whether the game is over. The client
      JS can be tampered with or replaced entirely — the server is the only source of truth.
    </p>
    <pre><code class="language-php">onMessage: function ($server, $frame) {
    $me  = Store::get('ws_tictactoe_clients', (string)$frame->fd);  if (!$me) return;
    $msg = json_decode($frame->data, true);                          if (!is_array($msg)) return;
    $row = Store::get('ws_tictactoe_rooms', $me['room']);            if (!$row) return;

    if ($msg['type'] === 'move') {
        if ($me['symbol'] === 'S')              return;   // spectator: no moves
        if ($row['winner'] !== '')              return;   // game already over
        if ($me['symbol'] !== $row['turn'])     return;   // wrong turn
        $cell = (int)($msg['cell'] ?? -1);
        if ($cell < 0 || $cell > 8)             return;
        $board = $row['board'];
        if ($board[$cell] !== '_')              return;   // cell occupied

        $board[$cell] = $me['symbol'];
        [$winSymbol, $winLine] = ttt_detect_winner($board);

        $update = ['board' => $board];
        if ($winSymbol)                  $update += ['winner'=>$winSymbol, 'turn'=>''];
        elseif (!str_contains($board, '_')) $update += ['winner'=>'draw', 'turn'=>''];
        else                             $update += ['turn'=> $row['turn']==='X' ? 'O' : 'X'];

        Store::set('ws_tictactoe_rooms', $me['room'], $update);
        ttt_broadcast_state_with($me['room'], $winLine ? ['win_line'=>$winLine] : []);
    }
}</code></pre>
    <p>
      Win detection is eight three-in-a-row checks — three rows, three columns, two diagonals. Cheap
      to compute on every move, no need for clever incremental algorithms at this scale.
    </p>
    <pre><code class="language-php">function ttt_detect_winner(string $board): array {
    $lines = [[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
    foreach ($lines as [$a, $b, $c]) {
        $s = $board[$a];
        if ($s !== '_' && $s === $board[$b] && $s === $board[$c]) return [$s, [$a, $b, $c]];
    }
    return [null, null];
}</code></pre>

    <h2 id="step-broadcast">5. Broadcasting — fan-out to the room</h2>
    <p>
      After every state mutation, the server pushes the new state to every fd in the room. The
      iteration is the same loop you saw in the WebSocket lesson — the only addition is filtering by
      <code>room</code>:
    </p>
    <pre><code class="language-php">function ttt_broadcast_state(string $room): void {
    $server = App::getServer();
    $row    = Store::get('ws_tictactoe_rooms', $room);  if (!$row) return;
    $payload = json_encode([
        'type'    => 'state',
        'board'   => $row['board'],
        'turn'    => $row['turn'],
        'winner'  => $row['winner'],
        'rounds'  => (int)$row['rounds'],
        'players' => [
            'X' => ['name' => $row['px_name'], 'connected' => (int)$row['px_fd'] > 0],
            'O' => ['name' => $row['po_name'], 'connected' => (int)$row['po_fd'] > 0],
        ],
        'viewers' => count_viewers($room),
    ]);
    foreach (Store::table('ws_tictactoe_clients') as $fd =&gt; $c) {
        if ($c['room'] !== $room) continue;
        $fd = (int)$fd;
        if ($server->isEstablished($fd)) $server->push($fd, $payload);
    }
}</code></pre>
    <p>
      Players and viewers both receive the same payload — they all need the board, the turn, the
      players&rsquo; names, and the winner. The client decides what to <em>do</em> with the state:
      players can click cells, viewers see a disabled board, the active player&rsquo;s tab shows
      &ldquo;your turn.&rdquo;
    </p>

    <h2 id="step-reconnect">6. Disconnects, viewers, and reconnects</h2>
    <p>
      When a player&rsquo;s tab closes, <code>onClose</code> runs. The fd is removed from the clients
      table, and if it held a player seat, that seat is freed (the name stays so the same user can
      reclaim it on reconnect):
    </p>
    <pre><code class="language-php">onClose: function ($server, $fd) {
    $me = Store::get('ws_tictactoe_clients', (string)$fd);
    Store::del('ws_tictactoe_clients', (string)$fd);
    if (!$me) return;
    $row = Store::get('ws_tictactoe_rooms', $me['room']);
    if (!$row) return;
    $update = [];
    if ((int)$row['px_fd'] === $fd) $update['px_fd'] = 0;
    if ((int)$row['po_fd'] === $fd) $update['po_fd'] = 0;
    if ($update) Store::set('ws_tictactoe_rooms', $me['room'], $update);
    ttt_broadcast_state($me['room']);  // tell the room someone left
}</code></pre>
    <p>
      Reset (<code>{"type":"reset"}</code>) is also a socket message: only seated players can send
      it; spectators see the button hidden in the UI <em>and</em> the server rejects the message if
      it somehow gets through. The reset flips <code>starter</code> so X and O take turns going
      first across rounds — a small fairness detail, easy to miss until you play three games in a
      row and realize X always opens.
    </p>

    <h2 id="step-score">7. Keeping score — extend the row, not the schema</h2>
    <p>
      Players want to know <em>how many rounds X has won versus O</em> across the session. The
      naive instinct is to spin up a new table for it — but the scoreboard is just three more
      counters that live for the lifetime of the room. They belong in the SAME
      <code>ws_tictactoe_rooms</code> row we already have. Three new fixed-width columns:
    </p>
    <pre><code class="language-php">// route/learn.php — extending the existing Store::make call from step 2
Store::make('ws_tictactoe_rooms', 1024, [
    // …existing columns…
    'x_wins' =&gt; [Table::TYPE_INT, 4],
    'o_wins' =&gt; [Table::TYPE_INT, 4],
    'draws'  =&gt; [Table::TYPE_INT, 4],
]);</code></pre>

    <h3>Mutate where you already mutate</h3>
    <p>
      The win/draw branches of <code>onMessage</code> already write to the room row when a game
      ends. Bumping the scoreboard in the SAME <code>Store::set</code> call means the counters
      can never disagree with the <code>winner</code> field — it&rsquo;s one critical section,
      one round-trip to shared memory:
    </p>
    <pre><code class="language-php">if ($winSymbol !== null) {
    $update['winner'] = $winSymbol;
    $update['turn']   = '';
    $update['rounds'] = (int) $rowRoom['rounds'] + 1;
    // Bump the matching counter in the SAME update — atomic with the
    // winner field, no chance of a "we say X won but the score doesn't
    // reflect it" desync.
    if ($winSymbol === 'X') $update['x_wins'] = (int) $rowRoom['x_wins'] + 1;
    else                    $update['o_wins'] = (int) $rowRoom['o_wins'] + 1;
} elseif (!str_contains($board, '_')) {
    $update['winner'] = 'draw';
    $update['turn']   = '';
    $update['rounds'] = (int) $rowRoom['rounds'] + 1;
    $update['draws']  = (int) $rowRoom['draws']  + 1;
}
Store::set('ws_tictactoe_rooms', $room, $update);   // one write</code></pre>

    <h3>Broadcast for free</h3>
    <p>
      The scoreboard rides on the same state-broadcast machinery that already carries the board,
      the turn, the winner, and the player names. Adding it to the JSON payload makes every tab
      in the room receive the new score the moment the game ends — no separate message type, no
      separate <code>onMessage</code> branch on the client:
    </p>
    <pre><code class="language-php">// inside ttt_broadcast_state(), the payload gets one extra key
'score' =&gt; [
    'X'    =&gt; (int) $row['x_wins'],
    'O'    =&gt; (int) $row['o_wins'],
    'draw' =&gt; (int) $row['draws'],
],</code></pre>

    <h3>Resetting the score</h3>
    <p>
      A second socket message, <code>{"type":"reset_score"}</code>, zeroes the three counters
      and re-broadcasts. Same authorization guard as the board reset — spectators are rejected,
      seated players are allowed:
    </p>
    <pre><code class="language-php">if ($type === 'reset_score') {
    if (($me['symbol'] ?? '') === 'S') return;
    Store::set('ws_tictactoe_rooms', $room, [
        'x_wins' =&gt; 0, 'o_wins' =&gt; 0, 'draws' =&gt; 0, 'rounds' =&gt; 0,
    ]);
    ttt_broadcast_state($room);
}</code></pre>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'When NOT to use Store for scoring',
      'body'    => '<p>This scoreboard lives in shared memory: it survives across rounds in the same room and across both players&rsquo; reconnections, but it&rsquo;s <strong>gone when the server restarts</strong> and it doesn&rsquo;t cross rooms. If you wanted a <em>persistent</em> leaderboard ("alice has won 1,247 games across all rooms ever"), you&rsquo;d use SQLite the same way the <a href="/learn/notes">Notes lesson</a> does: write to disk on every win, query for the top-N on render. Store is the right tool for ephemeral match-state; SQLite is the right tool for durable history.</p>',
    ]); ?>

    <h2 id="step-tryit">8. Try it — two tabs, one room</h2>
    <?php if (!$user): ?>
      <?php App::render('/components/_callout', [
        'variant' => 'warn',
        'title'   => 'Log in to play',
        'body'    => '<p><a href="/learn/auth">Register or log in</a> first, then come back here.</p>',
      ]); ?>
    <?php else: ?>
      <?php App::render('/components/_tictactoe_widget', ['user' => $user]); ?>
      <a class="lesson-popout-cta" href="/demo/view/tictactoe/play" target="_blank" rel="noopener">
        Open the game in a new tab ↗
      </a>
      <p class="lttt-note">
        Open this page in a second tab (or use the popout) and join the same room ID — the second
        tab gets the O seat. Click a cell in either tab; both update live. Open a third tab and
        you&rsquo;ll join as a viewer, watching the game without playing.
      </p>
    <?php endif; ?>

    <?php App::render('/components/_deepdive', [
      'title' => 'Why no HTTP POST for moves?',
      'body'  => '<p>The natural design instinct is <code>POST /api/tictactoe/move</code> with htmx. It works — but the server then has to authenticate <em>each request</em> against the player&rsquo;s session and check that they&rsquo;re seated in the room they claim, which is two extra lookups per move. By sending moves over the existing socket, the server already knows which fd sent the frame, which room that fd is in, and what symbol it holds. No re-auth, no extra round-trip on session lookups, and the move-validation flow becomes one straight path.</p><p>Trade-off: you give up the htmx flow (request/response). For a multiplayer game where you want low-latency two-way comms anyway, the socket is the better fit. For something like a turn-based form submission, HTTP is still simpler.</p>',
    ]); ?>

    <?php App::render('/components/_concept_check', [
      'id'       => 'ttt1',
      'question' => 'A third tab joins room "alpha-1" while two players are already seated. What happens?',
      'correct'  => 'b',
      'explain'  => 'The first two fds got the X and O seats. The third fd finds both seats taken (px_fd != 0 and po_fd != 0), so onOpen assigns symbol = "S". The client table records that fd as a spectator; ttt_broadcast_state still pushes to it like any other fd in the room, so the third tab sees the live game. <code>?view=1</code> on the URL forces this even when seats are open — useful for casting.',
      'options'  => [
        'a' => 'The connection is rejected because the room is full',
        'b' => 'They join as a viewer — same fan-out, no ability to move or reset',
        'c' => 'They replace one of the existing players',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Two Store tables: one for room state, one for per-fd bookkeeping — same idiom as <code>/ws/session-counter</code> and <code>/ws/store-demo</code>',
      'Seats are limited to two (X, O) by checking <code>px_fd</code> and <code>po_fd</code> at connect time; spectators are unlimited',
      'All game-changing messages go through the socket — the server trusts fd→symbol mapping, not client-supplied tokens',
      'Win detection is eight three-in-a-row line checks; payload includes <code>win_line</code> so the client highlights the winning cells',
      '<code>onClose</code> frees the seat but keeps the name, so a reconnecting player picks up where they left off',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/ai-chat"
         hx-get="/api/learn/page?slug=learn/ai-chat" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/ai-chat">← AI Chat</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/routing"
         hx-get="/api/learn/page?slug=learn/routing" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/routing">Routes & APIs →</a>
    </div>
  </article>
</div>
