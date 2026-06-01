<?php
// route/learn.php — thin route file for the /learn section.
// Business logic lives in src/Learn/ (autoloaded via Composer PSR-4).
// Simple endpoints live in api/learn/ (ZealAPI file-based routing).
// This file registers only: Store tables, WebSocket handler, explicit
// routes with path params, and demo endpoints.

use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Store;
use ZealPHP\Learn\DB;
use ZealPHP\Learn\Auth;
use ZealPHP\Learn\Notes;
use ZealPHP\Learn\Demo;
use ZealPHP\Learn\TicTacToe;
use ZealPHP\Site\DemoHelpers;

$app = App::instance();

// ── Rate-limit Store tables ──────────────────────────────────────────
\ZealPHP\Store::make('learn_login_rl', 1024, [
    'ip'    => [Store::TYPE_STRING, 45],
    'count' => [Store::TYPE_INT, 4],
    'reset' => [Store::TYPE_INT, 4],
]);
\ZealPHP\Store::make('learn_register_rl', 1024, [
    'ip'    => [Store::TYPE_STRING, 45],
    'count' => [Store::TYPE_INT, 4],
    'reset' => [Store::TYPE_INT, 4],
]);
\ZealPHP\Store::make('learn_chat_rl', 1024, [
    'ip'    => [Store::TYPE_STRING, 45],
    'count' => [Store::TYPE_INT, 4],
    'reset' => [Store::TYPE_INT, 4],
]);

// ── WebSocket cross-tab notes sync ───────────────────────────────────
\ZealPHP\Store::make('learn_ws_clients', 4096, [
    'user_id' => [Store::TYPE_INT, 8],
]);

$app->ws('/ws/learn',
    onMessage: function ($server, $frame) {
        if (($frame->data ?? '') === 'ping') $server->push($frame->fd, 'pong');
    },
    onOpen: function ($server, $request) {
        $g = G::instance();
        $userId = (int) ($g->session['user_id'] ?? 0);
        if (!$userId) { $server->disconnect($request->fd, 1008, 'auth_required'); return; }
        \ZealPHP\Store::set('learn_ws_clients', (string) $request->fd, ['user_id' => $userId]);
    },
    onClose: function ($server, $fd) {
        \ZealPHP\Store::del('learn_ws_clients', (string) $fd);
    },
);

// Broadcast helper lives in src/Learn/WS.php (autoloaded). The thin wrapper
// learn_ws_broadcast() now lives on ZealPHP\Learn\Demo so this route file
// stays function-free and hot-reloadable.

// ── Public WebSocket counter demo (for /learn/websocket lesson) ─────
// A single global counter that any open tab can bump; all tabs see the
// updated value over WebSocket. No auth — purely a teaching demo.
\ZealPHP\Store::make('ws_counter_demo_clients', 4096, [
    'connected_at' => [Store::TYPE_INT, 8],
]);
$wsCounterDemo = new \ZealPHP\Counter(0);

$app->ws('/ws/counter-demo',
    onMessage: function ($server, $frame) {
        if (($frame->data ?? '') === 'ping') $server->push($frame->fd, 'pong');
    },
    onOpen: function ($server, $request) use ($wsCounterDemo) {
        \ZealPHP\Store::set('ws_counter_demo_clients', (string) $request->fd, ['connected_at' => time()]);
        // On open, push the current value so the new tab is in sync immediately.
        $server->push($request->fd, json_encode(['value' => $wsCounterDemo->get()]));
    },
    onClose: function ($server, $fd) {
        \ZealPHP\Store::del('ws_counter_demo_clients', (string) $fd);
    },
);

// ── Rate-limit for unauthenticated public demo POSTs ────────────────
// All /api/learn/demo/* mutation endpoints below are reachable without
// auth — they power live widgets on the public docs site. To prevent
// trivial griefing (flooding the counter, spam-writing the store-row
// shown to every connected tab), every mutation runs through
// demo_rate_check() first. Reuses Auth::rateLimit() (same pattern
// Lesson 17 teaches as a security challenge), keyed by REMOTE_ADDR.
// Limit: 30 writes per IP per minute — orders of magnitude above what
// the live widgets need, far below sustained abuse.
\ZealPHP\Store::make('demo_rate_limits', 10000, [
    'ip'    => [Store::TYPE_STRING, 45],   // IPv6 max
    'count' => [Store::TYPE_INT,    4],
    'reset' => [Store::TYPE_INT,    4],
]);

// Demo::demo_rate_check() (in src/Learn/Demo.php) returns null when under the
// limit, or the 429 error payload to `return` directly when rate-limited.

$app->route('/api/learn/demo/counter-bump', ['methods' => ['POST']], function () use ($wsCounterDemo) {
    if ($err = Demo::demo_rate_check()) return $err;
    $new = $wsCounterDemo->increment();
    Demo::ws_counter_demo_broadcast((int) $new);
    return ['value' => (int) $new];
});

$app->route('/api/learn/demo/counter-reset', ['methods' => ['POST']], function () use ($wsCounterDemo) {
    if ($err = Demo::demo_rate_check()) return $err;
    $wsCounterDemo->set(0);
    Demo::ws_counter_demo_broadcast(0);
    return ['value' => 0];
});

// ── Notes routes with path params (can't be ZealAPI files) ───────────

$app->route('/api/learn/notes/search', ['methods' => ['GET']], function () {
    $u = Auth::currentUser();
    if (!$u) { http_response_code(401); return ['error' => 'auth_required']; }
    $g = G::instance();
    $q = trim((string) ($g->get['q'] ?? ''));
    if ($q === '') return [];
    return Notes::search(DB::open(), $u['user_id'], $q);
});

$app->route('/api/learn/notes/{id}', ['methods' => ['GET']], function ($request, $response, $id) {
    $u = Auth::currentUser();
    if (!$u) { http_response_code(401); return ['error' => 'auth_required']; }
    $note = Notes::read(DB::open(), $u['user_id'], (int) $id);
    if (!$note) { http_response_code(404); return ['error' => 'not_found']; }
    return $note;
});

$app->route('/api/learn/notes/{id}', ['methods' => ['POST']], function ($request, $response, $id) {
    $u = Auth::currentUser();
    if (!$u) { http_response_code(401); header('Content-Type: application/json'); return ['error' => 'auth_required']; }
    $g = G::instance();
    $wantsJson = stripos($g->server['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    $body = json_decode($g->zealphp_request->parent->getContent(), true) ?: $g->post;
    $db = DB::open();
    $ok = Notes::update($db, $u['user_id'], (int) $id, $body['title'] ?? null, $body['body'] ?? null);
    if (!$ok) { http_response_code(404); return ['error' => 'not_found']; }
    Demo::learn_ws_broadcast($u['user_id'], ['type' => 'note_changed', 'op' => 'update', 'id' => (int) $id]);
    $note = Notes::read($db, $u['user_id'], (int) $id);
    if ($wantsJson) return $note;
    header('Content-Type: text/html; charset=utf-8');
    return App::renderToString('/components/_note_card', $note);
});

$app->route('/api/learn/notes/{id}', ['methods' => ['DELETE']], function ($request, $response, $id) {
    $u = Auth::currentUser();
    if (!$u) { http_response_code(401); return ['error' => 'auth_required']; }
    $g = G::instance();
    $db = DB::open();
    $ok = Notes::delete($db, $u['user_id'], (int) $id);
    if (!$ok) { http_response_code(404); return ['error' => 'not_found']; }
    Demo::learn_ws_broadcast($u['user_id'], ['type' => 'note_changed', 'op' => 'delete', 'id' => (int) $id]);
    $wantsJson = stripos($g->server['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    return $wantsJson ? ['ok' => true] : '';
});

// ── Demo endpoints ───────────────────────────────────────────────────

$app->route('/api/learn/demo/check', ['methods' => ['POST']], function () {
    $g = G::instance();
    $answer  = trim((string) ($g->post['answer'] ?? ''));
    $correct = trim((string) ($g->post['correct'] ?? ''));
    $explain = trim((string) ($g->post['explain'] ?? ''));
    $isRight = $answer === $correct;
    // Allow a tiny tag whitelist so lesson authors can put <code>, <em>,
    // <strong> in the explanation text. The value round-trips through a
    // form hidden input (browser decodes once before submit), so the
    // raw payload here is what the lesson author wrote — OR what a
    // malicious client crafted in a hand-rolled POST. strip_tags
    // filters tags but does NOT filter attributes, so we deliberately
    // exclude <a> (would allow href="javascript:…") and any tag with
    // an event-handler attribute surface. <code>/<em>/<strong>/<br>
    // are inert — no attributes worth abusing — so they're safe to allow.
    $safe = strip_tags($explain, '<code><em><strong><br>');
    header('Content-Type: text/html; charset=utf-8');
    return App::renderToString('/components/_callout', [
        'variant' => $isRight ? 'success' : 'warn',
        'title'   => $isRight ? 'Correct!' : 'Not quite',
        'body'    => '<p>' . $safe . '</p>',
    ]);
});

// ── Session-counter demo for /learn/sessions ────────────────────────
// Cross-tab live sync within one browser (same PHPSESSID cookie). htmx
// swaps the button for the clicking tab; the WebSocket pushes the new
// button HTML to every other tab open in the same session.
\ZealPHP\Store::make('ws_session_counter_clients', 4096, [
    'session_id' => [Store::TYPE_STRING, 64],
]);

$app->ws('/ws/session-counter',
    onMessage: function ($server, $frame) {
        if (($frame->data ?? '') === 'ping') $server->push($frame->fd, 'pong');
    },
    onOpen: function ($server, $request) {
        $sid = $request->cookie['PHPSESSID'] ?? '';
        if ($sid === '') { $server->disconnect($request->fd, 1008, 'no_session'); return; }
        \ZealPHP\Store::set('ws_session_counter_clients', (string) $request->fd, ['session_id' => $sid]);
    },
    onClose: function ($server, $fd) {
        \ZealPHP\Store::del('ws_session_counter_clients', (string) $fd);
    },
);

$app->route('/api/learn/demo/session-bump', ['methods' => ['POST']], function () {
    $g = G::instance();
    $g->session['lesson_counter'] = (int) ($g->session['lesson_counter'] ?? 0) + 1;
    $html = App::renderToString('/components/_session_counter', [
        'n' => (int) $g->session['lesson_counter'],
    ]);
    $sid = $g->cookie['PHPSESSID'] ?? '';
    if ($sid !== '') Demo::ws_session_counter_broadcast($sid, $html);
    header('Content-Type: text/html; charset=utf-8');
    return $html;
});

// Standalone popup-friendly viewer — open in N windows to see cross-tab sync.
$app->route('/demo/view/sessions/counter', ['methods' => ['GET']], function () {
    $g = G::instance();
    $n = (int) ($g->session['lesson_counter'] ?? 0);
    return DemoHelpers::demo_render(
        'Session-counter cross-tab demo',
        'Click <strong>+1</strong>. Open this URL in a second window — both update live via a WebSocket broadcast scoped to your <code class="demo-inline">PHPSESSID</code>. Foundations &rarr; <a href="/learn/sessions">Sessions</a> explains the mechanism.',
        [
            ['heading' => 'Live counter', 'body' =>
                '<div style="text-align:center;padding:1.25rem 0">' .
                App::renderToString('/components/_session_counter', ['n' => $n]) .
                '<div data-session-counter-status class="ws-counter-status" style="margin-top:.85rem">starting…</div>' .
                '</div>'
            ],
            ['heading' => 'How it works', 'body' =>
                '<pre class="demo-payload">// Client
const ws = new WebSocket(proto + \'//\' + host + \'/ws/session-counter\');
ws.onmessage = e =&gt; document.getElementById(\'session-counter-btn\')
                       ?.outerHTML = e.data;

// Server (excerpt — route/learn.php)
use ZealPHP\\Learn\\Demo;   // broadcast helper lives in a src/ class

$app-&gt;route(\'/api/learn/demo/session-bump\', ...
    $html = App::renderToString(\'/components/_session_counter\', [\'n\' =&gt; $n]);
    Demo::ws_session_counter_broadcast($sid, $html);
    return $html;   // for the clicking tab&rsquo;s htmx swap
);</pre>'],
        ],
        'learn/sessions', 'Sessions'
    );
});

// ── Public WebSocket Store demo (for /demo/view/store/incr + set-get) ──
// A global Store table with one row; any open tab can bump or write to it,
// and every other tab receives the new state over WebSocket. Demonstrates
// Store::incr() and Store::set/get() with the same cross-tab feedback
// loop the websocket-lesson counter uses.
\ZealPHP\Store::make('ws_store_demo_clients', 4096, [
    'connected_at' => [Store::TYPE_INT, 8],
]);
\ZealPHP\Store::make('ws_store_demo_data', 32, [
    'n'    => [Store::TYPE_INT,    8],
    'name' => [Store::TYPE_STRING, 64],
    'who'  => [Store::TYPE_STRING, 64],
    'ts'   => [Store::TYPE_INT,    8],
]);

$app->ws('/ws/store-demo',
    onMessage: function ($server, $frame) {
        if (($frame->data ?? '') === 'ping') $server->push($frame->fd, 'pong');
    },
    onOpen: function ($server, $request) {
        \ZealPHP\Store::set('ws_store_demo_clients', (string) $request->fd, ['connected_at' => time()]);
        // Sync the new tab to current state immediately
        $row = \ZealPHP\Store::get('ws_store_demo_data', 'shared_row') ?: ['n' => 0, 'name' => '', 'who' => '', 'ts' => 0];
        $server->push($request->fd, json_encode($row));
    },
    onClose: function ($server, $fd) {
        \ZealPHP\Store::del('ws_store_demo_clients', (string) $fd);
    },
);

$app->route('/api/learn/demo/store-bump', ['methods' => ['POST']], function () {
    if ($err = Demo::demo_rate_check()) return $err;
    $row = \ZealPHP\Store::get('ws_store_demo_data', 'shared_row');
    if (!$row) {
        // Initialize the row if a bump arrives before any set-get touched it
        \ZealPHP\Store::set('ws_store_demo_data', 'shared_row', ['n' => 0, 'name' => '(unset)', 'who' => '(none)', 'ts' => time()]);
    }
    $new = \ZealPHP\Store::incr('ws_store_demo_data', 'shared_row', 'n', 1);
    \ZealPHP\Store::set('ws_store_demo_data', 'shared_row', ['ts' => time()]);
    Demo::ws_store_demo_broadcast();
    return ['n' => (int) $new];
});

$app->route('/api/learn/demo/store-reset', ['methods' => ['POST']], function () {
    if ($err = Demo::demo_rate_check()) return $err;
    \ZealPHP\Store::set('ws_store_demo_data', 'shared_row', ['n' => 0, 'name' => '', 'who' => '', 'ts' => time()]);
    Demo::ws_store_demo_broadcast();
    return ['n' => 0];
});

$app->route('/api/learn/demo/store-write', ['methods' => ['POST']], function () {
    if ($err = Demo::demo_rate_check()) return $err;
    $g = G::instance();
    // Strip control chars (anything below 0x20 except space) to prevent broken
    // JSON rendering on the client + ANSI/terminal escape tricks in CLI viewers.
    $clean = static fn(string $s): string => preg_replace('/[\x00-\x1f\x7f]+/u', '', $s) ?? '';
    $name = trim($clean((string) ($g->post['name'] ?? '')));
    $who  = trim($clean((string) ($g->post['who']  ?? 'anonymous')));
    if ($name === '' || strlen($name) > 60) {
        http_response_code(400);
        return ['error' => 'name required (1-60 chars, printable)'];
    }
    if ($who === '')      $who = 'anonymous';
    if (strlen($who) > 60) $who = substr($who, 0, 60);
    $row = \ZealPHP\Store::get('ws_store_demo_data', 'shared_row') ?: ['n' => 0];
    \ZealPHP\Store::set('ws_store_demo_data', 'shared_row', [
        'n'    => (int) ($row['n'] ?? 0),
        'name' => $name,
        'who'  => $who,
        'ts'   => time(),
    ]);
    Demo::ws_store_demo_broadcast();
    $current = \ZealPHP\Store::get('ws_store_demo_data', 'shared_row');
    return ['ok' => true, 'row' => $current];
});

// ── Tic-tac-toe multiplayer (Build-the-App capstone) ────────────────
// Two-player game over WebSocket. Login required (display name = username).
// Per-fd seating: first fd in a room gets X, second gets O, rest are
// spectators. Spectators see state via fan-out broadcast but can't move
// or reset. `?view=1` forces spectator even if a seat is free — useful
// for casting/streaming the game without participating.
//
// Two Store tables: one for per-fd bookkeeping, one for shared room state.
// All client→server traffic flows through the socket itself (no HTTP POST
// for moves), so the server can trust fd→symbol mapping without a
// separate auth token.
\ZealPHP\Store::make('ws_tictactoe_clients', 4096, [
    'room'   => [Store::TYPE_STRING, 32],
    'name'   => [Store::TYPE_STRING, 32],
    'symbol' => [Store::TYPE_STRING, 2],   // 'X' | 'O' | 'S'
    'joined' => [Store::TYPE_INT,    8],
]);
\ZealPHP\Store::make('ws_tictactoe_rooms', 1024, [
    'board'   => [Store::TYPE_STRING, 9],
    'turn'    => [Store::TYPE_STRING, 2],
    'winner'  => [Store::TYPE_STRING, 8],
    'px_fd'   => [Store::TYPE_INT,    8],
    'po_fd'   => [Store::TYPE_INT,    8],
    'px_name' => [Store::TYPE_STRING, 32],
    'po_name' => [Store::TYPE_STRING, 32],
    'starter' => [Store::TYPE_STRING, 2],
    'rounds'  => [Store::TYPE_INT,    4],
    // Running scoreboard for the room — wins per symbol + draws. Persists
    // across Reset clicks; only cleared by an explicit {type:'reset_score'}.
    'x_wins'  => [Store::TYPE_INT,    4],
    'o_wins'  => [Store::TYPE_INT,    4],
    'draws'   => [Store::TYPE_INT,    4],
]);

// Tic-tac-toe helpers (ttt_sanitize_room / ttt_detect_winner /
// ttt_broadcast_state / ttt_broadcast_state_with) live on
// ZealPHP\Learn\TicTacToe (src/Learn/TicTacToe.php) so this route file stays
// function-free and hot-reloadable.

$app->ws('/ws/tictactoe',
    onMessage: function ($server, $frame) {
        if (($frame->data ?? '') === 'ping') { $server->push($frame->fd, 'pong'); return; }
        $me = \ZealPHP\Store::get('ws_tictactoe_clients', (string) $frame->fd);
        if (!$me) return;
        $msg = json_decode((string) ($frame->data ?? ''), true);
        if (!is_array($msg)) return;
        $type = (string) ($msg['type'] ?? '');
        $room = (string) ($me['room'] ?? '');
        if ($room === '') return;
        $rowRoom = \ZealPHP\Store::get('ws_tictactoe_rooms', $room);
        if (!$rowRoom) return;

        if ($type === 'move') {
            // Spectators cannot move
            if (($me['symbol'] ?? '') === 'S') return;
            // Game over — no moves until reset
            if (($rowRoom['winner'] ?? '') !== '') return;
            // Wrong turn
            if (($me['symbol'] ?? '') !== ($rowRoom['turn'] ?? '')) return;
            $cell = (int) ($msg['cell'] ?? -1);
            if ($cell < 0 || $cell > 8) return;
            $board = (string) ($rowRoom['board'] ?? '_________');
            if (strlen($board) !== 9 || $board[$cell] !== '_') return;
            $board[$cell] = $me['symbol'];
            [$winSymbol, $winLine] = TicTacToe::ttt_detect_winner($board);
            $update = ['board' => $board, 'last_move_ts' => time()];
            $extras = [];
            if ($winSymbol !== null) {
                $update['winner'] = $winSymbol;
                $update['turn']   = '';
                $update['rounds'] = (int) ($rowRoom['rounds'] ?? 0) + 1;
                // Bump the running scoreboard. Same Store::set call as the
                // rest of the game-state mutation — single critical section,
                // so the score can never disagree with the winner field.
                if ($winSymbol === 'X') {
                    $update['x_wins'] = (int) ($rowRoom['x_wins'] ?? 0) + 1;
                } else {
                    $update['o_wins'] = (int) ($rowRoom['o_wins'] ?? 0) + 1;
                }
                $extras['win_line'] = $winLine;
            } elseif (strpos($board, '_') === false) {
                $update['winner'] = 'draw';
                $update['turn']   = '';
                $update['rounds'] = (int) ($rowRoom['rounds'] ?? 0) + 1;
                $update['draws']  = (int) ($rowRoom['draws'] ?? 0) + 1;
            } else {
                $update['turn'] = ($rowRoom['turn'] === 'X') ? 'O' : 'X';
            }
            \ZealPHP\Store::set('ws_tictactoe_rooms', $room, $update);
            TicTacToe::ttt_broadcast_state_with($room, $extras);
            return;
        }

        if ($type === 'reset') {
            // Only seated players can reset, not spectators
            if (($me['symbol'] ?? '') === 'S') return;
            $starter = ($rowRoom['starter'] ?? 'X') === 'X' ? 'O' : 'X';
            \ZealPHP\Store::set('ws_tictactoe_rooms', $room, [
                'board'   => '_________',
                'turn'    => $starter,
                'winner'  => '',
                'starter' => $starter,
            ]);
            TicTacToe::ttt_broadcast_state($room);
            return;
        }

        if ($type === 'reset_score') {
            // Same guard as reset — spectators can't touch room state.
            // Zeroes the scoreboard but keeps the board, turn, and seat
            // assignments intact.
            if (($me['symbol'] ?? '') === 'S') return;
            \ZealPHP\Store::set('ws_tictactoe_rooms', $room, [
                'x_wins' => 0,
                'o_wins' => 0,
                'draws'  => 0,
                'rounds' => 0,
            ]);
            TicTacToe::ttt_broadcast_state($room);
            return;
        }
    },
    onOpen: function ($server, $request) {
        // Auth: same pattern as /ws/learn — G::instance() exposes the
        // current request's session (populated from PHPSESSID by
        // CoSessionManager before onOpen fires).
        $g = G::instance();
        $userId   = (int) ($g->session['user_id'] ?? 0);
        $username = (string) ($g->session['username'] ?? '');
        if (!$userId || $username === '') {
            $server->disconnect($request->fd, 1008, 'auth_required'); return;
        }
        $room = TicTacToe::ttt_sanitize_room((string) ($request->get['room'] ?? ''));
        if ($room === '') { $server->disconnect($request->fd, 1008, 'no_room'); return; }
        $viewMode = ((string) ($request->get['view'] ?? '')) === '1';

        // Look up or create the room row.
        $row = \ZealPHP\Store::get('ws_tictactoe_rooms', $room);
        if (!$row) {
            \ZealPHP\Store::set('ws_tictactoe_rooms', $room, [
                'board'   => '_________',
                'turn'    => 'X',
                'winner'  => '',
                'px_fd'   => 0,
                'po_fd'   => 0,
                'px_name' => '',
                'po_name' => '',
                'starter' => 'X',
                'rounds'  => 0,
                'x_wins'  => 0,
                'o_wins'  => 0,
                'draws'   => 0,
            ]);
            $row = \ZealPHP\Store::get('ws_tictactoe_rooms', $room);
        }

        // Assign seat. ?view=1 forces spectator regardless of free seats.
        $symbol = 'S';
        if (!$viewMode) {
            if (((int) $row['px_fd']) === 0) {
                $symbol = 'X';
                \ZealPHP\Store::set('ws_tictactoe_rooms', $room, ['px_fd' => $request->fd, 'px_name' => $username]);
            } elseif (((int) $row['po_fd']) === 0) {
                $symbol = 'O';
                \ZealPHP\Store::set('ws_tictactoe_rooms', $room, ['po_fd' => $request->fd, 'po_name' => $username]);
            }
        }
        \ZealPHP\Store::set('ws_tictactoe_clients', (string) $request->fd, [
            'room'   => $room,
            'name'   => $username,
            'symbol' => $symbol,
            'joined' => time(),
        ]);

        // Welcome message tells the client their assigned role + room name.
        $server->push($request->fd, json_encode([
            'type'   => 'welcome',
            'symbol' => $symbol,
            'room'   => $room,
            'name'   => $username,
        ]));
        TicTacToe::ttt_broadcast_state($room);
    },
    onClose: function ($server, $fd) {
        $me = \ZealPHP\Store::get('ws_tictactoe_clients', (string) $fd);
        \ZealPHP\Store::del('ws_tictactoe_clients', (string) $fd);
        if (!$me) return;
        $room = (string) ($me['room'] ?? '');
        if ($room === '') return;
        $row = \ZealPHP\Store::get('ws_tictactoe_rooms', $room);
        if (!$row) return;
        $update = [];
        if (((int) $row['px_fd']) === $fd) $update['px_fd'] = 0;
        if (((int) $row['po_fd']) === $fd) $update['po_fd'] = 0;
        if (!empty($update)) \ZealPHP\Store::set('ws_tictactoe_rooms', $room, $update);
        TicTacToe::ttt_broadcast_state($room);
    },
);

$app->route('/api/learn/demo/greeting', ['methods' => ['GET']], function () {
    $g = G::instance();
    $name = htmlspecialchars(trim((string) ($g->get['name'] ?? 'World')));
    header('Content-Type: text/html; charset=utf-8');
    return Demo::learn_demo_shell('Greeting Demo', '<h2>Hello, ' . $name . '!</h2><p>This page was rendered by ZealPHP at ' . date('H:i:s') . '.</p>');
});

// ── Render method demos (Lesson 4) ──────────────────────────────────

$app->route('/api/learn/demo/incr', ['methods' => ['POST', 'GET']], function () {
    $g = G::instance();
    $g->session['demo_counter'] = (int) ($g->session['demo_counter'] ?? 0) + 1;
    header('Content-Type: text/html; charset=utf-8');
    return App::renderToString('/components/_counter_button', ['n' => $g->session['demo_counter']]);
});

$app->route('/api/learn/demo/timing', ['methods' => ['GET']], function () {
    $g = G::instance();
    $mode = $g->get['mode'] ?? 'parallel';
    $work = function () { usleep(100000); };
    $start = microtime(true);
    if ($mode === 'sequential') {
        $work(); $work(); $work();
    } else {
        $ch = new \OpenSwoole\Coroutine\Channel(3);
        for ($i = 0; $i < 3; $i++) {
            go(function () use ($work, $ch) { $work(); $ch->push(true); });
        }
        for ($i = 0; $i < 3; $i++) $ch->pop();
    }
    $elapsed = (int) round((microtime(true) - $start) * 1000);
    header('Content-Type: application/json');
    return ['mode' => $mode, 'elapsed_ms' => $elapsed];
});

// learn_demo_shell() lives on ZealPHP\Learn\Demo (src/Learn/Demo.php).

$app->route('/api/learn/demo/render', ['methods' => ['GET']], function () {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Render-Method: App::render');
    $card = App::renderToString('/components/_demo_clock', ['label' => 'render() — echoed', 'now' => microtime(true)]);
    return Demo::learn_demo_shell('App::render() demo', '<section class="render-demo"><h4>One-shot echo</h4>' . $card . '</section>');
});

$app->route('/api/learn/demo/render-to-string', ['methods' => ['GET']], function () {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Render-Method: App::renderToString');
    $card = App::renderToString('/components/_demo_clock', [
        'label' => 'renderToString() — composed',
        'now'   => microtime(true),
    ]);
    return Demo::learn_demo_shell('App::renderToString() demo', '<section class="render-demo"><h4>Composed wrapper</h4>' . $card . '</section>');
});

$app->route('/api/learn/demo/render-stream', ['methods' => ['GET']], function () {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Render-Method: App::renderStream');
    return (function () {
        yield <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>App::renderStream() demo · ZealPHP Learn</title>
  <link rel="stylesheet" href="/css/learn.css">
  <style>body { font-family: ui-sans-serif, system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; color: #1c1917; } nav { margin-bottom: 1rem; font-size: .85rem; } nav a { color: #f59e0b; text-decoration: none; margin-right: 1rem; }</style>
</head>
<body>
  <nav><a href="/learn/components">← Back to Lesson 4</a> · <strong>App::renderStream() demo</strong> · streaming 12 rows over ~1.8s</nav>
  <section class="render-demo"><h4>Streamed rows</h4>
HTML;
        for ($i = 1; $i <= 12; $i++) {
            usleep(150000);
            yield from App::renderStream('/components/_demo_clock', [
                'label' => "renderStream() — row {$i}/12",
                'now'   => microtime(true),
            ]);
        }
        yield "</section></body></html>";
    })();
});
