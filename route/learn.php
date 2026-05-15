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
    header('Content-Type: text/html; charset=utf-8');
    return App::renderToString('/components/_callout', [
        'variant' => $isRight ? 'success' : 'warn',
        'title'   => $isRight ? 'Correct!' : 'Not quite',
        'body'    => '<p>' . htmlspecialchars($explain) . '</p>',
    ]);
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
