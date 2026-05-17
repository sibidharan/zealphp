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

$app->route('/api/learn/demo/counter-bump', ['methods' => ['POST']], function () use ($wsCounterDemo) {
    $new = $wsCounterDemo->increment();
    ws_counter_demo_broadcast((int) $new);
    return ['value' => (int) $new];
});

$app->route('/api/learn/demo/counter-reset', ['methods' => ['POST']], function () use ($wsCounterDemo) {
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
    // <strong>, <a> in the explanation text. The value round-trips
    // through a form hidden input (browser decodes once before submit),
    // so the raw payload here is what the lesson author wrote.
    // strip_tags with an allow-list keeps the rendering safe against a
    // hand-crafted POST that swaps in <script>.
    $safe = strip_tags($explain, '<code><em><strong><a><br>');
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
    \ZealPHP\Store::set('ws_store_demo_data', 'shared_row', ['n' => 0, 'name' => '', 'who' => '', 'ts' => time()]);
    ws_store_demo_broadcast();
    return ['n' => 0];
});

$app->route('/api/learn/demo/store-write', ['methods' => ['POST']], function () {
    $g = G::instance();
    $name = trim((string) ($g->post['name'] ?? ''));
    $who  = trim((string) ($g->post['who']  ?? 'anonymous'));
    if ($name === '' || strlen($name) > 60) {
        http_response_code(400);
        return ['error' => 'name required (1-60 chars)'];
    }
    $row = \ZealPHP\Store::get('ws_store_demo_data', 'shared_row') ?: ['n' => 0];
    \ZealPHP\Store::set('ws_store_demo_data', 'shared_row', [
        'n'    => (int) ($row['n'] ?? 0),
        'name' => $name,
        'who'  => substr($who, 0, 60),
        'ts'   => time(),
    ]);
    ws_store_demo_broadcast();
    $current = \ZealPHP\Store::get('ws_store_demo_data', 'shared_row');
    return ['ok' => true, 'row' => $current];
});

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
