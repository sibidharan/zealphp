<?php
// route/learn.php — /learn API endpoints + SQLite + auth helpers.
// Helpers are declared at file scope so unit tests can require_once this file.

use ZealPHP\App;
use ZealPHP\G;

if (!function_exists('learn_db_path')) {
    function learn_db_path(): string {
        $configured = getenv('ZEALPHP_LEARN_DB_PATH');
        if ($configured === false || $configured === '') $configured = 'storage/learn.db';
        if ($configured[0] !== '/') {
            $root = defined('ZEALPHP_ROOT') ? ZEALPHP_ROOT : __DIR__ . '/..';
            $configured = $root . '/' . $configured;
        }
        $dir = dirname($configured);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $configured;
    }
}

if (!function_exists('learn_db_open')) {
    function learn_db_open(): \PDO {
        static $cache = [];
        $path = learn_db_path();
        if (isset($cache[$path])) return $cache[$path];
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->query('PRAGMA journal_mode = WAL');
        $pdo->query('PRAGMA foreign_keys = ON');
        $pdo->query('PRAGMA busy_timeout = 2000');
        $pdo->query("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, created_at INTEGER NOT NULL)");
        $pdo->query("CREATE TABLE IF NOT EXISTS notes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, title TEXT NOT NULL, body TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)");
        $pdo->query("CREATE INDEX IF NOT EXISTS idx_notes_user_updated ON notes(user_id, updated_at DESC)");
        $pdo->query("CREATE TABLE IF NOT EXISTS chat_history (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, thread_id TEXT NOT NULL, role TEXT NOT NULL, items_json TEXT NOT NULL, created_at INTEGER NOT NULL)");
        $pdo->query("CREATE INDEX IF NOT EXISTS idx_chat_user_thread_time ON chat_history(user_id, thread_id, created_at)");
        $cache[$path] = $pdo;
        return $pdo;
    }
}

if (!function_exists('learn_validate_username')) {
    function learn_validate_username(string $u): bool {
        return (bool)preg_match('/^[A-Za-z0-9_]{3,64}$/', $u);
    }
}

if (!function_exists('learn_validate_password')) {
    function learn_validate_password(string $p): bool {
        $len = strlen($p);
        return $len >= 8 && $len <= 256;
    }
}

if (!function_exists('learn_register_user')) {
    function learn_register_user(\PDO $db, string $username, string $password): ?int {
        if (!learn_validate_username($username) || !learn_validate_password($password)) return null;
        try {
            $stmt = $db->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), time()]);
            return (int)$db->lastInsertId();
        } catch (\PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('learn_login_user')) {
    function learn_login_user(\PDO $db, string $username, string $password): ?int {
        $row = $db->prepare('SELECT id, password_hash FROM users WHERE username = ?');
        $row->execute([$username]);
        $user = $row->fetch();
        if (!$user) return null;
        if (!password_verify($password, $user['password_hash'])) return null;
        return (int)$user['id'];
    }
}

if (!function_exists('learn_notes_create')) {
    function learn_notes_create(\PDO $db, int $userId, string $title, string $body): ?int {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) return null;
        if (strlen($body) > 4096) return null;
        $max = (int)(getenv('ZEALPHP_LEARN_MAX_NOTES') ?: 256);
        $cnt = $db->prepare('SELECT COUNT(*) FROM notes WHERE user_id = ?');
        $cnt->execute([$userId]);
        if ((int)$cnt->fetchColumn() >= $max) return null;
        $now = time();
        $stmt = $db->prepare('INSERT INTO notes (user_id, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $title, $body, $now, $now]);
        return (int)$db->lastInsertId();
    }
}

if (!function_exists('learn_notes_list')) {
    function learn_notes_list(\PDO $db, int $userId): array {
        $stmt = $db->prepare('SELECT id, title, body, created_at, updated_at FROM notes WHERE user_id = ? ORDER BY updated_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('learn_notes_read')) {
    function learn_notes_read(\PDO $db, int $userId, int $noteId): ?array {
        $stmt = $db->prepare('SELECT id, title, body, created_at, updated_at FROM notes WHERE id = ? AND user_id = ?');
        $stmt->execute([$noteId, $userId]);
        $r = $stmt->fetch();
        return $r ?: null;
    }
}

if (!function_exists('learn_notes_update')) {
    function learn_notes_update(\PDO $db, int $userId, int $noteId, ?string $title, ?string $body): bool {
        $existing = learn_notes_read($db, $userId, $noteId);
        if (!$existing) return false;
        $newTitle = $title ?? $existing['title'];
        $newBody  = $body  ?? $existing['body'];
        $newTitle = trim($newTitle);
        if ($newTitle === '' || mb_strlen($newTitle) > 200) return false;
        if (strlen($newBody) > 4096) return false;
        $stmt = $db->prepare('UPDATE notes SET title = ?, body = ?, updated_at = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$newTitle, $newBody, time(), $noteId, $userId]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('learn_notes_delete')) {
    function learn_notes_delete(\PDO $db, int $userId, int $noteId): bool {
        $stmt = $db->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?');
        $stmt->execute([$noteId, $userId]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('learn_notes_search')) {
    function learn_notes_search(\PDO $db, int $userId, string $query, int $limit = 10): array {
        $q = '%' . $query . '%';
        $stmt = $db->prepare('SELECT id, title, body, updated_at FROM notes WHERE user_id = ? AND (title LIKE ? OR body LIKE ?) ORDER BY updated_at DESC LIMIT ?');
        $stmt->execute([$userId, $q, $q, $limit]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('learn_chat_history_append')) {
    function learn_chat_history_append(\PDO $db, int $userId, string $threadId, string $role, array $items): int {
        $stmt = $db->prepare('INSERT INTO chat_history (user_id, thread_id, role, items_json, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $threadId, $role, json_encode($items, JSON_UNESCAPED_UNICODE), time()]);
        return (int)$db->lastInsertId();
    }
}

if (!function_exists('learn_chat_history_for_thread')) {
    function learn_chat_history_for_thread(\PDO $db, int $userId, string $threadId): array {
        $stmt = $db->prepare('SELECT id, role, items_json, created_at FROM chat_history WHERE user_id = ? AND thread_id = ? ORDER BY created_at ASC, id ASC');
        $stmt->execute([$userId, $threadId]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('learn_chat_history_threads')) {
    function learn_chat_history_threads(\PDO $db, int $userId, int $limit = 10): array {
        $stmt = $db->prepare('SELECT thread_id, MAX(created_at) AS last_at, COUNT(*) AS turns FROM chat_history WHERE user_id = ? GROUP BY thread_id ORDER BY last_at DESC LIMIT ?');
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }
}

// ── Endpoint registrations (skipped in unit-test context) ────────────
if (defined('ZEALPHP_LEARN_TESTING') || !class_exists('ZealPHP\\App', false)) return;

$app = App::instance();

// ── WebSocket cross-tab notes sync (fd → user_id mapping) ────────────
\ZealPHP\Store::make('learn_ws_clients', 4096, [
    'user_id' => [\OpenSwoole\Table::TYPE_INT, 8],
]);

$app->ws('/ws/learn',
    onMessage: function($server, $frame) {
        if (($frame->data ?? '') === 'ping') $server->push($frame->fd, 'pong');
    },
    onOpen: function($server, $request) {
        session_start();
        $userId = (int)(G::instance()->session['user_id'] ?? 0);
        if (!$userId) { $server->disconnect($request->fd, 1008, 'auth_required'); return; }
        \ZealPHP\Store::set('learn_ws_clients', (string)$request->fd, ['user_id' => $userId]);
    },
    onClose: function($server, $fd) {
        \ZealPHP\Store::del('learn_ws_clients', (string)$fd);
    },
);

function learn_ws_broadcast(int $userId, array $payload): void {
    $server = \ZealPHP\App::getServer();
    if (!$server) return;
    $json = json_encode($payload);
    foreach (\ZealPHP\Store::table('learn_ws_clients') as $fd => $row) {
        if ((int)($row['user_id'] ?? 0) === $userId) {
            try { @$server->push((int)$fd, $json); } catch (\Throwable $e) {}
        }
    }
}

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

function learn_rate_limit(string $table, string $ip, int $limit, int $window): bool {
    $now = time();
    $existing = \ZealPHP\Store::get($table, $ip);
    if ($existing && $now < $existing['reset']) {
        if ($existing['count'] >= $limit) return false;
        \ZealPHP\Store::incr($table, $ip, 'count', 1);
        return true;
    }
    \ZealPHP\Store::set($table, $ip, ['ip' => $ip, 'count' => 1, 'reset' => $now + $window]);
    return true;
}

function learn_read_credentials($g): ?array {
    $ct = $g->server['HTTP_CONTENT_TYPE'] ?? $g->server['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $body = json_decode($g->zealphp_request->parent->getContent(), true);
        if (!is_array($body)) return null;
        $u = (string)($body['username'] ?? '');
        $p = (string)($body['password'] ?? '');
    } else {
        $u = (string)($g->post['username'] ?? '');
        $p = (string)($g->post['password'] ?? '');
    }
    if ($u === '' || $p === '') return null;
    return ['username' => $u, 'password' => $p];
}

function learn_current_user(): ?array {
    $g = G::instance();
    // CoSessionManager populates $g->session from the session file when
    // the request has a PHPSESSID cookie. Just read it directly.
    if (!empty($g->session['user_id'])) {
        return ['user_id' => (int)$g->session['user_id'], 'username' => (string)($g->session['username'] ?? '')];
    }
    return null;
}

$app->route('/api/learn/register', ['methods' => ['POST']], function($request, $response) {
    $g = G::instance();
    $ip = $g->server['REMOTE_ADDR'] ?? 'unknown';
    if (!learn_rate_limit('learn_register_rl', $ip, 5, 300)) {
        http_response_code(429); header('Content-Type: application/json');
        return ['error' => 'rate_limit'];
    }
    $creds = learn_read_credentials($g);
    if (!$creds) { http_response_code(422); header('Content-Type: application/json'); return ['error' => 'validation_failed']; }
    if (!learn_validate_username($creds['username'])) { http_response_code(422); header('Content-Type: application/json'); return ['error' => 'invalid_username']; }
    if (!learn_validate_password($creds['password'])) { http_response_code(422); header('Content-Type: application/json'); return ['error' => 'invalid_password']; }

    $db = learn_db_open();
    $userId = learn_register_user($db, $creds['username'], $creds['password']);
    if ($userId === null) { http_response_code(409); header('Content-Type: application/json'); return ['error' => 'username_taken']; }

    session_start();
    $g = G::instance();
    $g->session['user_id'] = $userId;
    $g->session['username'] = $creds['username'];
    // ZealPHP's CoSessionManager only sends Set-Cookie + writes the
    // session file when the request already had a PHPSESSID cookie.
    // For a brand-new session we have to emit the cookie AND force
    // the session file write before the request ends.
    setcookie('PHPSESSID', session_id(), 0, '/', '', false, true);
    session_write_close();
    // JSON POST → return JSON (fetch with redirect:manual won't process
    // Set-Cookie from a 302). Form POST → 302 redirect (browser follows it).
    $ct = $g->server['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        header('Content-Type: application/json');
        return ['user_id' => $userId, 'username' => $creds['username']];
    }
    $response->redirect('/learn/notes', 302);
});

$app->route('/api/learn/login', ['methods' => ['POST']], function($request, $response) {
    $g = G::instance();
    $ip = $g->server['REMOTE_ADDR'] ?? 'unknown';
    if (!learn_rate_limit('learn_login_rl', $ip, 10, 300)) {
        http_response_code(429); header('Content-Type: application/json');
        return ['error' => 'rate_limit'];
    }
    $creds = learn_read_credentials($g);
    if (!$creds) { http_response_code(422); header('Content-Type: application/json'); return ['error' => 'validation_failed']; }

    $db = learn_db_open();
    $userId = learn_login_user($db, $creds['username'], $creds['password']);
    if ($userId === null) { http_response_code(401); header('Content-Type: application/json'); return ['error' => 'invalid_credentials']; }

    session_start();
    $g = G::instance();
    $g->session['user_id'] = $userId;
    $g->session['username'] = $creds['username'];
    setcookie('PHPSESSID', session_id(), 0, '/', '', false, true);
    session_write_close();
    $ct = $g->server['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        header('Content-Type: application/json');
        return ['user_id' => $userId, 'username' => $creds['username']];
    }
    $response->redirect('/learn/notes', 302);
});

$app->route('/api/learn/logout', ['methods' => ['POST', 'GET']], function($request, $response) {
    session_start();
    $g = G::instance();
    $g->session = [];
    session_destroy();
    $response->redirect('/learn/notes', 302);
});

// ── Notes CRUD endpoints ─────────────────────────────────────────────
$app->route('/api/learn/notes', ['methods' => ['GET']], function($request, $response) {
    $u = learn_current_user();
    if (!$u) { http_response_code(401); header('Content-Type: application/json'); return ['error' => 'auth_required']; }
    $db = learn_db_open();
    $notes = learn_notes_list($db, $u['user_id']);
    header('Content-Type: text/html; charset=utf-8');
    return (function() use ($notes) {
        if (empty($notes)) { yield '<p class="notes-empty">No notes yet. Add one above.</p>'; return; }
        foreach ($notes as $n) {
            yield App::renderToString('/components/_note_card', $n);
        }
    })();
});

$app->route('/api/learn/notes', ['methods' => ['POST']], function($request, $response) {
    $u = learn_current_user();
    if (!$u) { http_response_code(401); header('Content-Type: application/json'); return ['error' => 'auth_required']; }
    $g = G::instance();
    $ct = $g->server['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $body = json_decode($g->zealphp_request->parent->getContent(), true) ?: [];
    } else {
        $body = $g->post;
    }
    $title = (string)($body['title'] ?? '');
    $bodyText = (string)($body['body'] ?? '');
    $db = learn_db_open();
    $id = learn_notes_create($db, $u['user_id'], $title, $bodyText);
    if ($id === null) { http_response_code(422); header('Content-Type: application/json'); return ['error' => 'validation_failed']; }
    learn_ws_broadcast($u['user_id'], ['type' => 'note_changed', 'op' => 'create', 'id' => $id]);
    $note = learn_notes_read($db, $u['user_id'], $id);
    header('Content-Type: text/html; charset=utf-8');
    return App::renderToString('/components/_note_card', $note);
});

$app->route('/api/learn/notes/{id}', ['methods' => ['POST']], function($request, $response, $id) {
    $u = learn_current_user();
    if (!$u) { http_response_code(401); header('Content-Type: application/json'); return ['error' => 'auth_required']; }
    $g = G::instance();
    $body = json_decode($g->zealphp_request->parent->getContent(), true) ?: $g->post;
    $db = learn_db_open();
    $ok = learn_notes_update($db, $u['user_id'], (int)$id, $body['title'] ?? null, $body['body'] ?? null);
    if (!$ok) { http_response_code(404); header('Content-Type: application/json'); return ['error' => 'not_found']; }
    learn_ws_broadcast($u['user_id'], ['type' => 'note_changed', 'op' => 'update', 'id' => (int)$id]);
    $note = learn_notes_read($db, $u['user_id'], (int)$id);
    header('Content-Type: text/html; charset=utf-8');
    return App::renderToString('/components/_note_card', $note);
});

$app->route('/api/learn/notes/{id}', ['methods' => ['DELETE']], function($request, $response, $id) {
    $u = learn_current_user();
    if (!$u) { http_response_code(401); header('Content-Type: application/json'); return ['error' => 'auth_required']; }
    $db = learn_db_open();
    $ok = learn_notes_delete($db, $u['user_id'], (int)$id);
    if (!$ok) { http_response_code(404); return ''; }
    learn_ws_broadcast($u['user_id'], ['type' => 'note_changed', 'op' => 'delete', 'id' => (int)$id]);
    return '';
});

// ── Lesson 7 counter (htmx demo) ─────────────────────────────────────
$app->route('/api/learn/demo/incr', ['methods' => ['POST', 'GET']], function($request, $response) {
    session_start();
    $g = G::instance();
    $g->session['demo_counter'] = (int)($g->session['demo_counter'] ?? 0) + 1;
    header('Content-Type: text/html; charset=utf-8');
    return App::renderToString('/components/_counter_button', ['n' => $g->session['demo_counter']]);
});

// ── Lesson 4 render-method demos ─────────────────────────────────────
// All three produce visually-similar output. The teaching is in the
// HTTP behavior: render() / renderToString() return all at once,
// renderStream() flushes chunks as the Generator yields.

// Tiny HTML shell so the demo pages render correctly when opened directly
// in a browser tab (charset declared, learn.css linked).
function learn_demo_shell(string $title, string $body): string {
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

$app->route('/api/learn/demo/render', ['methods' => ['GET']], function() {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Render-Method: App::render');
    $card = App::renderToString('/components/_demo_clock', ['label' => 'render() — echoed', 'now' => microtime(true)]);
    return learn_demo_shell('App::render() demo', '<section class="render-demo"><h4>One-shot echo</h4>' . $card . '</section>');
});

$app->route('/api/learn/demo/render-to-string', ['methods' => ['GET']], function() {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Render-Method: App::renderToString');
    $card = App::renderToString('/components/_demo_clock', [
        'label' => 'renderToString() — composed',
        'now'   => microtime(true),
    ]);
    return learn_demo_shell('App::renderToString() demo', '<section class="render-demo"><h4>Composed wrapper</h4>' . $card . '</section>');
});

$app->route('/api/learn/demo/render-stream', ['methods' => ['GET']], function() {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Render-Method: App::renderStream');
    return (function() {
        // Stream a full HTML page so the browser parses charset early
        // and rows visibly arrive over ~1.25s.
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
  <nav><a href="/learn/components">← Back to Lesson 4</a> · <strong>App::renderStream() demo</strong> · streaming 5 rows over ~1.25s</nav>
  <section class="render-demo"><h4>Streamed rows</h4>
HTML;
        for ($i = 1; $i <= 5; $i++) {
            usleep(250000);
            yield from App::renderStream('/components/_demo_clock', [
                'label' => "renderStream() — row {$i}",
                'now'   => microtime(true),
            ]);
        }
        yield "</section></body></html>";
    })();
});

// ── Lesson 10: parallel vs sequential timing demo ────────────────────
$app->route('/api/learn/demo/timing', ['methods' => ['GET']], function() {
    $g = G::instance();
    $mode = $g->get['mode'] ?? 'parallel';
    $work = function() { usleep(100000); };
    $start = microtime(true);
    if ($mode === 'sequential') {
        $work(); $work(); $work();
    } else {
        $ch = new \OpenSwoole\Coroutine\Channel(3);
        for ($i = 0; $i < 3; $i++) {
            go(function() use ($work, $ch) { $work(); $ch->push(true); });
        }
        for ($i = 0; $i < 3; $i++) $ch->pop();
    }
    $elapsed = (int)round((microtime(true) - $start) * 1000);
    header('Content-Type: application/json');
    return ['mode' => $mode, 'elapsed_ms' => $elapsed];
});

// ── Chat status now lives at api/learn/chat_status.php (ZealAPI file)
// ── Chat rate limit ─────────────────────────────────────────────────
\ZealPHP\Store::make('learn_chat_rl', 1024, [
    'ip'    => [\OpenSwoole\Table::TYPE_STRING, 45],
    'count' => [\OpenSwoole\Table::TYPE_INT, 4],
    'reset' => [\OpenSwoole\Table::TYPE_INT, 4],
]);

$app->route('/api/learn/chat', ['methods' => ['POST']], function($request, $response) {
    $u = learn_current_user();
    if (!$u) { http_response_code(401); header('Content-Type: application/json'); return ['error' => 'auth_required']; }
    $g = G::instance();
    $ip = $g->server['REMOTE_ADDR'] ?? 'unknown';
    $limit = (int)(getenv('ZEALPHP_LEARN_RATE_LIMIT') ?: 30);
    if (!learn_rate_limit('learn_chat_rl', $ip, $limit, 3600)) {
        $response->sse(function($emit) {
            $emit(json_encode(['error' => 'rate_limit']), 'error');
            $emit(json_encode(['done' => true]), 'done');
        });
        return;
    }
    $body = json_decode($g->zealphp_request->parent->getContent(), true) ?: [];
    $message  = trim((string)($body['message'] ?? ''));
    $threadId = (string)($body['thread_id'] ?? bin2hex(random_bytes(8)));
    if ($message === '' || strlen($message) > 2000) {
        $response->sse(function($emit) use ($threadId) {
            $emit(json_encode(['thread_id' => $threadId]), 'thread');
            $emit(json_encode(['error' => 'invalid_message']), 'error');
            $emit(json_encode(['done' => true]), 'done');
        });
        return;
    }
    $key = (string)(getenv('OPENAI_API_KEY') ?: '');
    if ($key === '') learn_chat_mock($response, $u, $message, $threadId);
    else learn_chat_real($response, $u, $message, $threadId, $key);
});

function learn_chat_mock($response, array $user, string $message, string $threadId): void {
    $db = learn_db_open();
    $userId = $user['user_id'];
    $msgLower = strtolower($message);

    // Persist the user turn immediately so a refresh shows it even if the assistant fails.
    learn_chat_history_append($db, $userId, $threadId, 'user', [['type' => 'text', 'html' => '<p>' . htmlspecialchars($message) . '</p>']]);

    $response->sse(function($emit) use ($db, $userId, $message, $msgLower, $threadId) {
        // Accumulator: every $emit also pushes items into $items so we can persist
        // the full assistant turn at end-of-stream.
        $items = []; $textBuf = '';
        $flushText = function() use (&$items, &$textBuf) {
            if ($textBuf !== '') { $items[] = ['type' => 'text', 'html' => $textBuf]; $textBuf = ''; }
        };
        $sse = function(string $data, string $event) use ($emit, &$items, &$textBuf, $flushText) {
            $emit($data, $event);
            $payload = json_decode($data, true) ?: [];
            if ($event === 'token') {
                $textBuf .= (string)($payload['token'] ?? '');
            } elseif ($event === 'tool_call') {
                $flushText();
                $items[] = ['type' => 'tool', 'id' => $payload['id'] ?? '?', 'name' => $payload['name'] ?? '?', 'status' => 'running', 'args' => '', 'result' => ''];
            } elseif ($event === 'tool_args') {
                foreach ($items as &$it) if ($it['type'] === 'tool' && $it['id'] === ($payload['id'] ?? '')) { $it['args'] .= (string)($payload['delta'] ?? ''); break; }
            } elseif ($event === 'tool_done') {
                foreach ($items as &$it) if ($it['type'] === 'tool' && $it['id'] === ($payload['id'] ?? '')) { $it['status'] = $payload['status'] ?? 'ok'; $it['result'] = (string)($payload['result_preview'] ?? ''); break; }
            }
        };

        $sse(json_encode(['thread_id' => $threadId]), 'thread');

        if (preg_match('/(list|show all|what\'?s in)/i', $msgLower)) {
            $sse(json_encode(['id' => 'm1', 'name' => 'list_notes', 'phase' => 'start']), 'tool_call');
            usleep(120000);
            $notes = learn_notes_list($db, $userId);
            $sse(json_encode(['id' => 'm1', 'status' => 'ok', 'result_preview' => count($notes) . ' notes']), 'tool_done');
            if (empty($notes)) {
                $sse(json_encode(['token' => '<p>No notes yet. Try "create a note titled buy milk".</p>']), 'token');
            } else {
                $html = '<ul>' . implode('', array_map(fn($n) => '<li>' . htmlspecialchars($n['title']) . ' — id ' . (int)$n['id'] . '</li>', $notes)) . '</ul>';
                $sse(json_encode(['token' => '<p>Here are your notes:</p>' . $html]), 'token');
            }
        } elseif (preg_match('/(create|add)(\s+a)?\s+note(\s+(titled|called|saying))?\s+["\']?(.+?)["\']?$/i', $message, $m)) {
            $title = trim($m[5] ?? 'untitled');
            $sse(json_encode(['token' => '<p>Got it, creating that note.</p>']), 'token');
            $sse(json_encode(['id' => 'm2', 'name' => 'create_note', 'phase' => 'start']), 'tool_call');
            $json = json_encode(['title' => $title, 'body' => '']);
            foreach (str_split($json, 12) as $chunk) {
                $sse(json_encode(['id' => 'm2', 'delta' => $chunk]), 'tool_args');
                usleep(40000);
            }
            $newId = learn_notes_create($db, $userId, $title, '');
            $sse(json_encode(['id' => 'm2', 'status' => $newId ? 'ok' : 'error', 'result_preview' => $newId ? "id: $newId" : 'failed']), 'tool_done');
            $sse(json_encode([]), 'notes_changed');
            $sse(json_encode(['token' => "<p>Created note <strong>" . htmlspecialchars($title) . "</strong>.</p>"]), 'token');
        } elseif (preg_match('/delete\s+(?:note\s+)?["\']?(.+?)["\']?$/i', $message, $m)) {
            $needle = trim($m[1]);
            $notes = learn_notes_list($db, $userId);
            $hit = null;
            foreach ($notes as $n) if (stripos($n['title'], $needle) !== false) { $hit = $n; break; }
            if (!$hit) {
                $sse(json_encode(['token' => "<p>I couldn't find a note matching <em>" . htmlspecialchars($needle) . "</em>.</p>"]), 'token');
            } else {
                $sse(json_encode(['id' => 'm3', 'name' => 'delete_note', 'phase' => 'start']), 'tool_call');
                learn_notes_delete($db, $userId, (int)$hit['id']);
                $sse(json_encode(['id' => 'm3', 'status' => 'ok', 'result_preview' => 'deleted id ' . $hit['id']]), 'tool_done');
                $sse(json_encode([]), 'notes_changed');
                $sse(json_encode(['token' => "<p>Deleted note <strong>" . htmlspecialchars($hit['title']) . "</strong>.</p>"]), 'token');
            }
        } elseif (preg_match('/(search|find)\s+(.+)/i', $message, $m)) {
            $q = trim($m[2]);
            $sse(json_encode(['id' => 'm4', 'name' => 'search_notes', 'phase' => 'start']), 'tool_call');
            $hits = learn_notes_search($db, $userId, $q);
            $sse(json_encode(['id' => 'm4', 'status' => 'ok', 'result_preview' => count($hits) . ' hits']), 'tool_done');
            if (empty($hits)) $sse(json_encode(['token' => "<p>No notes match <em>" . htmlspecialchars($q) . "</em>.</p>"]), 'token');
            else $sse(json_encode(['token' => '<ul>' . implode('', array_map(fn($n) => '<li>' . htmlspecialchars($n['title']) . '</li>', $hits)) . '</ul>']), 'token');
        } else {
            $sse(json_encode(['token' => '<p>Mock mode is active — set <code>OPENAI_API_KEY</code> for the real model. Try: <em>create a note titled buy milk</em>, <em>list notes</em>, <em>delete buy milk</em>, <em>search groceries</em>.</p>']), 'token');
        }

        $flushText();
        learn_chat_history_append($db, $userId, $threadId, 'assistant', $items);
        $emit(json_encode(['done' => true]), 'done');
    });
}

function learn_chat_real($response, array $user, string $message, string $threadId, string $apiKey): void {
    $db = learn_db_open();
    $notes = learn_notes_list($db, $user['user_id']);
    $recent = array_slice(array_map(fn($n) => $n['title'], $notes), 0, 5);

    learn_chat_history_append($db, $user['user_id'], $threadId, 'user', [['type' => 'text', 'html' => '<p>' . htmlspecialchars($message) . '</p>']]);

    $payload = [
        'message'   => $message,
        'thread_id' => $threadId,
        'db_path'   => learn_db_path(),
        'user_id'   => $user['user_id'],
        'profile'   => [
            'username'           => $user['username'],
            'note_count'         => count($notes),
            'recent_note_titles' => $recent,
        ],
    ];
    $b64 = base64_encode(json_encode($payload));
    $agentPath = (defined('ZEALPHP_ROOT') ? ZEALPHP_ROOT : __DIR__ . '/..') . '/examples/agents/notes_agent.py';

    $response->sse(function($emit) use ($apiKey, $b64, $agentPath, $threadId, $db, $user) {
        $env = [];
        $gServer = G::instance()->server ?? [];
        foreach ($gServer as $k => $v) { if (is_string($v)) $env[$k] = $v; }
        $env['OPENAI_API_KEY'] = $apiKey;
        $env['ZEALPHP_LEARN_AI_MODEL'] = (string)(getenv('ZEALPHP_LEARN_AI_MODEL') ?: 'gpt-4.1-mini');
        $env['HOME'] = getenv('HOME') ?: '/tmp';
        $env['PATH'] = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
        $cmd = getenv('HOME') . '/.local/bin/uv run ' . escapeshellarg($agentPath) . ' ' . escapeshellarg($b64);

        $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
        $proc = proc_open($cmd, $desc, $pipes, null, $env);
        if (!is_resource($proc)) {
            $emit(json_encode(['thread_id' => $threadId]), 'thread');
            $emit(json_encode(['error' => 'agent_unavailable']), 'error');
            $emit(json_encode(['done' => true]), 'done');
            return;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);

        $items = []; $textBuf = '';
        $flush = function() use (&$items, &$textBuf) { if ($textBuf !== '') { $items[] = ['type' => 'text', 'html' => $textBuf]; $textBuf = ''; } };
        $reemit = function(string $data, string $event) use ($emit, &$items, &$textBuf, $flush) {
            $emit($data, $event);
            $payload = json_decode($data, true) ?: [];
            if ($event === 'token') {
                $textBuf .= (string)($payload['token'] ?? '');
            } elseif ($event === 'tool_call') {
                $flush();
                $items[] = ['type' => 'tool', 'id' => $payload['id'] ?? '?', 'name' => $payload['name'] ?? '?', 'status' => 'running', 'args' => '', 'result' => ''];
            } elseif ($event === 'tool_args') {
                foreach ($items as &$it) if ($it['type'] === 'tool' && $it['id'] === ($payload['id'] ?? '')) { $it['args'] .= (string)($payload['delta'] ?? ''); break; }
            } elseif ($event === 'tool_done') {
                foreach ($items as &$it) if ($it['type'] === 'tool' && $it['id'] === ($payload['id'] ?? '')) { $it['status'] = $payload['status'] ?? 'ok'; $it['result'] = (string)($payload['result_preview'] ?? ''); break; }
            }
        };

        $buffer = ''; $currentEvent = null;
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 4096);
            if ($chunk === false || $chunk === '') { usleep(40000); continue; }
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = rtrim($line, "\r");
                if (str_starts_with($line, 'event: ')) $currentEvent = trim(substr($line, 7));
                elseif (str_starts_with($line, 'data: ')) $reemit(substr($line, 6), $currentEvent ?: 'token');
            }
        }
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0 && empty($items)) {
            $emit(json_encode(['error' => 'agent_error: ' . trim(substr($stderr ?: '', 0, 200))]), 'error');
        }

        $flush();
        learn_chat_history_append($db, $user['user_id'], $threadId, 'assistant', $items);
    });
}
