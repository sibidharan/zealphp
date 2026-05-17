<?php
// route/learn.php — thin route file for the /learn section.
// Business logic lives in src/Learn/ (autoloaded via Composer PSR-4).
// Simple endpoints live in api/learn/ (ZealAPI file-based routing).
// This file registers only: Store tables, WebSocket handler, explicit
// routes with path params, and demo endpoints.

use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Learn\DB;
use ZealPHP\Learn\Auth;
use ZealPHP\Learn\Notes;

$app = App::instance();

// ── Rate-limit Store tables ──────────────────────────────────────────
\ZealPHP\Store::make('learn_login_rl', 1024, [
    'ip'    => [\OpenSwoole\Table::TYPE_STRING, 45],
    'count' => [\OpenSwoole\Table::TYPE_INT, 4],
    'reset' => [\OpenSwoole\Table::TYPE_INT, 4],
]);
\ZealPHP\Store::make('learn_register_rl', 1024, [
    'ip'    => [\OpenSwoole\Table::TYPE_STRING, 45],
    'count' => [\OpenSwoole\Table::TYPE_INT, 4],
    'reset' => [\OpenSwoole\Table::TYPE_INT, 4],
]);
\ZealPHP\Store::make('learn_chat_rl', 1024, [
    'ip'    => [\OpenSwoole\Table::TYPE_STRING, 45],
    'count' => [\OpenSwoole\Table::TYPE_INT, 4],
    'reset' => [\OpenSwoole\Table::TYPE_INT, 4],
]);

// ── WebSocket cross-tab notes sync ───────────────────────────────────
\ZealPHP\Store::make('learn_ws_clients', 4096, [
    'user_id' => [\OpenSwoole\Table::TYPE_INT, 8],
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

// Broadcast helper is now in src/Learn/WS.php (autoloaded).
// Keep a thin wrapper for backward compat with any inline references.
function learn_ws_broadcast(int $userId, array $payload): void
{
    \ZealPHP\Learn\WS::broadcast($userId, $payload);
}

// ── Public WebSocket counter demo (for /learn/websocket lesson) ─────
// A single global counter that any open tab can bump; all tabs see the
// updated value over WebSocket. No auth — purely a teaching demo.
\ZealPHP\Store::make('ws_counter_demo_clients', 4096, [
    'connected_at' => [\OpenSwoole\Table::TYPE_INT, 8],
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

function ws_counter_demo_broadcast(int $value): void
{
    $server = \ZealPHP\App::getServer();
    if (!$server) return;
    $payload = json_encode(['value' => $value]);
    foreach (\ZealPHP\Store::table('ws_counter_demo_clients') ?? [] as $fd => $_) {
        $fd = (int) $fd;
        if ($server->isEstablished($fd)) $server->push($fd, $payload);
    }
}

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
    'ip'    => [\OpenSwoole\Table::TYPE_STRING, 45],   // IPv6 max
    'count' => [\OpenSwoole\Table::TYPE_INT,    4],
    'reset' => [\OpenSwoole\Table::TYPE_INT,    4],
]);

/**
 * Returns null if the caller is under the limit (proceed). Returns an
 * error payload + sets 429 + Retry-After if rate-limited — caller should
 * `return` this directly from its route handler.
 *
 * @return null|array{error: string, limit: int, window: int}
 */
function demo_rate_check(): ?array
{
    $g  = G::instance();
    $ip = (string) ($g->server['REMOTE_ADDR'] ?? 'unknown');
    if (Auth::rateLimit('demo_rate_limits', $ip, 30, 60)) return null;
    http_response_code(429);
    header('Retry-After: 60');
    header('Content-Type: application/json; charset=utf-8');
    return ['error' => 'rate_limit', 'limit' => 30, 'window' => 60];
}

$app->route('/api/learn/demo/counter-bump', ['methods' => ['POST']], function () use ($wsCounterDemo) {
    if ($err = demo_rate_check()) return $err;
    $new = $wsCounterDemo->increment();
    ws_counter_demo_broadcast((int) $new);
    return ['value' => (int) $new];
});

$app->route('/api/learn/demo/counter-reset', ['methods' => ['POST']], function () use ($wsCounterDemo) {
    if ($err = demo_rate_check()) return $err;
    $wsCounterDemo->set(0);
    ws_counter_demo_broadcast(0);
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
    learn_ws_broadcast($u['user_id'], ['type' => 'note_changed', 'op' => 'update', 'id' => (int) $id]);
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
    learn_ws_broadcast($u['user_id'], ['type' => 'note_changed', 'op' => 'delete', 'id' => (int) $id]);
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
    'session_id' => [\OpenSwoole\Table::TYPE_STRING, 64],
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

function ws_session_counter_broadcast(string $sessionId, string $html): void
{
    $server = \ZealPHP\App::getServer();
    if (!$server) return;
    $table = \ZealPHP\Store::table('ws_session_counter_clients');
    if (!$table) return;
    foreach ($table as $fd => $row) {
        if (($row['session_id'] ?? '') !== $sessionId) continue;
        $fd = (int) $fd;
        if ($server->isEstablished($fd)) $server->push($fd, $html);
    }
}

$app->route('/api/learn/demo/session-bump', ['methods' => ['POST']], function () {
    $g = G::instance();
    $g->session['lesson_counter'] = (int) ($g->session['lesson_counter'] ?? 0) + 1;
    $html = App::renderToString('/components/_session_counter', [
        'n' => (int) $g->session['lesson_counter'],
    ]);
    $sid = $g->cookie['PHPSESSID'] ?? '';
    if ($sid !== '') ws_session_counter_broadcast($sid, $html);
    header('Content-Type: text/html; charset=utf-8');
    return $html;
});

// Standalone popup-friendly viewer — open in N windows to see cross-tab sync.
$app->route('/demo/view/sessions/counter', ['methods' => ['GET']], function () {
    $g = G::instance();
    $n = (int) ($g->session['lesson_counter'] ?? 0);
    return demo_render(
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
$app-&gt;route(\'/api/learn/demo/session-bump\', ...
    $html = App::renderToString(\'/components/_session_counter\', [\'n\' =&gt; $n]);
    ws_session_counter_broadcast($sid, $html);
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
    'connected_at' => [\OpenSwoole\Table::TYPE_INT, 8],
]);
\ZealPHP\Store::make('ws_store_demo_data', 32, [
    'n'    => [\OpenSwoole\Table::TYPE_INT,    8],
    'name' => [\OpenSwoole\Table::TYPE_STRING, 64],
    'who'  => [\OpenSwoole\Table::TYPE_STRING, 64],
    'ts'   => [\OpenSwoole\Table::TYPE_INT,    8],
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

function ws_store_demo_broadcast(): void
{
    $server = \ZealPHP\App::getServer();
    if (!$server) return;
    $row = \ZealPHP\Store::get('ws_store_demo_data', 'shared_row') ?: ['n' => 0, 'name' => '', 'who' => '', 'ts' => 0];
    $payload = json_encode($row);
    foreach (\ZealPHP\Store::table('ws_store_demo_clients') ?? [] as $fd => $_) {
        $fd = (int) $fd;
        if ($server->isEstablished($fd)) $server->push($fd, $payload);
    }
}

$app->route('/api/learn/demo/store-bump', ['methods' => ['POST']], function () {
    if ($err = demo_rate_check()) return $err;
    $row = \ZealPHP\Store::get('ws_store_demo_data', 'shared_row');
    if (!$row) {
        // Initialize the row if a bump arrives before any set-get touched it
        \ZealPHP\Store::set('ws_store_demo_data', 'shared_row', ['n' => 0, 'name' => '(unset)', 'who' => '(none)', 'ts' => time()]);
    }
    $new = \ZealPHP\Store::incr('ws_store_demo_data', 'shared_row', 'n', 1);
    \ZealPHP\Store::set('ws_store_demo_data', 'shared_row', ['ts' => time()]);
    ws_store_demo_broadcast();
    return ['n' => (int) $new];
});

$app->route('/api/learn/demo/store-reset', ['methods' => ['POST']], function () {
    if ($err = demo_rate_check()) return $err;
    \ZealPHP\Store::set('ws_store_demo_data', 'shared_row', ['n' => 0, 'name' => '', 'who' => '', 'ts' => time()]);
    ws_store_demo_broadcast();
    return ['n' => 0];
});

$app->route('/api/learn/demo/store-write', ['methods' => ['POST']], function () {
    if ($err = demo_rate_check()) return $err;
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
    ws_store_demo_broadcast();
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
    'room'   => [\OpenSwoole\Table::TYPE_STRING, 32],
    'name'   => [\OpenSwoole\Table::TYPE_STRING, 32],
    'symbol' => [\OpenSwoole\Table::TYPE_STRING, 2],   // 'X' | 'O' | 'S'
    'joined' => [\OpenSwoole\Table::TYPE_INT,    8],
]);
\ZealPHP\Store::make('ws_tictactoe_rooms', 1024, [
    'board'   => [\OpenSwoole\Table::TYPE_STRING, 9],
    'turn'    => [\OpenSwoole\Table::TYPE_STRING, 2],
    'winner'  => [\OpenSwoole\Table::TYPE_STRING, 8],
    'px_fd'   => [\OpenSwoole\Table::TYPE_INT,    8],
    'po_fd'   => [\OpenSwoole\Table::TYPE_INT,    8],
    'px_name' => [\OpenSwoole\Table::TYPE_STRING, 32],
    'po_name' => [\OpenSwoole\Table::TYPE_STRING, 32],
    'starter' => [\OpenSwoole\Table::TYPE_STRING, 2],
    'rounds'  => [\OpenSwoole\Table::TYPE_INT,    4],
]);

function ttt_sanitize_room(string $room): string
{
    $room = strtolower($room);
    $room = preg_replace('/[^a-z0-9-]/', '', $room) ?? '';
    return substr($room, 0, 32);
}

function ttt_detect_winner(string $board): array
{
    $lines = [[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
    foreach ($lines as $line) {
        [$a, $b, $c] = $line;
        $s = $board[$a];
        if ($s !== '_' && $s === $board[$b] && $s === $board[$c]) {
            return [$s, $line];
        }
    }
    return [null, null];
}

function ttt_broadcast_state(string $room): void
{
    $server = \ZealPHP\App::getServer();
    if (!$server) return;
    $row = \ZealPHP\Store::get('ws_tictactoe_rooms', $room);
    if (!$row) return;
    // Count viewers in the room (everyone with symbol 'S')
    $viewers = 0;
    foreach (\ZealPHP\Store::table('ws_tictactoe_clients') ?? [] as $_ => $c) {
        if (($c['room'] ?? '') === $room && ($c['symbol'] ?? '') === 'S') $viewers++;
    }
    $payload = json_encode([
        'type'    => 'state',
        'board'   => $row['board'],
        'turn'    => $row['turn'],
        'winner'  => $row['winner'],
        'rounds'  => (int) $row['rounds'],
        'players' => [
            'X' => ['name' => $row['px_name'], 'connected' => ((int) $row['px_fd']) > 0],
            'O' => ['name' => $row['po_name'], 'connected' => ((int) $row['po_fd']) > 0],
        ],
        'viewers' => $viewers,
    ]);
    foreach (\ZealPHP\Store::table('ws_tictactoe_clients') ?? [] as $fd => $c) {
        if (($c['room'] ?? '') !== $room) continue;
        $fd = (int) $fd;
        if ($server->isEstablished($fd)) $server->push($fd, $payload);
    }
}

function ttt_broadcast_state_with(string $room, array $extras): void
{
    $server = \ZealPHP\App::getServer();
    if (!$server) return;
    $row = \ZealPHP\Store::get('ws_tictactoe_rooms', $room);
    if (!$row) return;
    $viewers = 0;
    foreach (\ZealPHP\Store::table('ws_tictactoe_clients') ?? [] as $_ => $c) {
        if (($c['room'] ?? '') === $room && ($c['symbol'] ?? '') === 'S') $viewers++;
    }
    $payload = json_encode(array_merge([
        'type'    => 'state',
        'board'   => $row['board'],
        'turn'    => $row['turn'],
        'winner'  => $row['winner'],
        'rounds'  => (int) $row['rounds'],
        'players' => [
            'X' => ['name' => $row['px_name'], 'connected' => ((int) $row['px_fd']) > 0],
            'O' => ['name' => $row['po_name'], 'connected' => ((int) $row['po_fd']) > 0],
        ],
        'viewers' => $viewers,
    ], $extras));
    foreach (\ZealPHP\Store::table('ws_tictactoe_clients') ?? [] as $fd => $c) {
        if (($c['room'] ?? '') !== $room) continue;
        $fd = (int) $fd;
        if ($server->isEstablished($fd)) $server->push($fd, $payload);
    }
}

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
            [$winSymbol, $winLine] = ttt_detect_winner($board);
            $update = ['board' => $board, 'last_move_ts' => time()];
            $extras = [];
            if ($winSymbol !== null) {
                $update['winner'] = $winSymbol;
                $update['turn']   = '';
                $update['rounds'] = (int) ($rowRoom['rounds'] ?? 0) + 1;
                $extras['win_line'] = $winLine;
            } elseif (strpos($board, '_') === false) {
                $update['winner'] = 'draw';
                $update['turn']   = '';
                $update['rounds'] = (int) ($rowRoom['rounds'] ?? 0) + 1;
            } else {
                $update['turn'] = ($rowRoom['turn'] === 'X') ? 'O' : 'X';
            }
            \ZealPHP\Store::set('ws_tictactoe_rooms', $room, $update);
            ttt_broadcast_state_with($room, $extras);
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
            ttt_broadcast_state($room);
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
        $room = ttt_sanitize_room((string) ($request->get['room'] ?? ''));
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
        ttt_broadcast_state($room);
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
        ttt_broadcast_state($room);
    },
);

$app->route('/api/learn/demo/greeting', ['methods' => ['GET']], function () {
    $g = G::instance();
    $name = htmlspecialchars(trim((string) ($g->get['name'] ?? 'World')));
    header('Content-Type: text/html; charset=utf-8');
    return learn_demo_shell('Greeting Demo', '<h2>Hello, ' . $name . '!</h2><p>This page was rendered by ZealPHP at ' . date('H:i:s') . '.</p>');
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

function learn_demo_shell(string $title, string $body): string
{
    $titleHtml = htmlspecialchars($title);
    return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$titleHtml} · ZealPHP Learn</title>
  <link rel="stylesheet" href="/css/learn.css">
  <style>body { font-family: ui-sans-serif, system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; color: #1c1917; } nav { margin-bottom: 1rem; font-size: .85rem; } nav a { color: #f59e0b; text-decoration: none; margin-right: 1rem; }</style>
</head>
<body>
  <nav><a href="/learn/components">← Back to Lesson 4</a> · <strong>{$titleHtml}</strong></nav>
  {$body}
</body>
</html>
HTML;
}

$app->route('/api/learn/demo/render', ['methods' => ['GET']], function () {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Render-Method: App::render');
    $card = App::renderToString('/components/_demo_clock', ['label' => 'render() — echoed', 'now' => microtime(true)]);
    return learn_demo_shell('App::render() demo', '<section class="render-demo"><h4>One-shot echo</h4>' . $card . '</section>');
});

$app->route('/api/learn/demo/render-to-string', ['methods' => ['GET']], function () {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Render-Method: App::renderToString');
    $card = App::renderToString('/components/_demo_clock', [
        'label' => 'renderToString() — composed',
        'now'   => microtime(true),
    ]);
    return learn_demo_shell('App::renderToString() demo', '<section class="render-demo"><h4>Composed wrapper</h4>' . $card . '</section>');
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
