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

// ── Endpoint registrations (skipped in unit-test context) ────────────
if (defined('ZEALPHP_LEARN_TESTING') || !class_exists('ZealPHP\\App', false)) return;

$app = App::instance();

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
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['user_id'])) return null;
    return ['user_id' => (int)$_SESSION['user_id'], 'username' => (string)($_SESSION['username'] ?? '')];
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

    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $creds['username'];
    // ZealPHP's CoSessionManager only sends Set-Cookie + writes the
    // session file when the request already had a PHPSESSID cookie.
    // For a brand-new session we have to emit the cookie AND force
    // the session file write before the request ends.
    setcookie('PHPSESSID', session_id(), 0, '/', '', false, true);
    session_write_close();
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

    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $creds['username'];
    // ZealPHP's CoSessionManager only sends Set-Cookie + writes the
    // session file when the request already had a PHPSESSID cookie.
    // For a brand-new session we have to emit the cookie AND force
    // the session file write before the request ends.
    setcookie('PHPSESSID', session_id(), 0, '/', '', false, true);
    session_write_close();
    $response->redirect('/learn/notes', 302);
});

$app->route('/api/learn/logout', ['methods' => ['POST', 'GET']], function($request, $response) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION = [];
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
    return '';
});

// ── Lesson 7 counter (htmx demo) ─────────────────────────────────────
$app->route('/api/learn/demo/incr', ['methods' => ['POST', 'GET']], function($request, $response) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['demo_counter'] = (int)($_SESSION['demo_counter'] ?? 0) + 1;
    header('Content-Type: text/html; charset=utf-8');
    return App::renderToString('/components/_counter_button', ['n' => $_SESSION['demo_counter']]);
});

// ── Lesson 4 render-method demos ─────────────────────────────────────
// All three produce visually-similar output. The teaching is in the
// HTTP behavior: render() / renderToString() return all at once,
// renderStream() flushes chunks as the Generator yields.

$app->route('/api/learn/demo/render', ['methods' => ['GET']], function() {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Render-Method: App::render');
    App::render('/components/_demo_clock', ['label' => 'render() — echoed', 'now' => microtime(true)]);
});

$app->route('/api/learn/demo/render-to-string', ['methods' => ['GET']], function() {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Render-Method: App::renderToString');
    $card = App::renderToString('/components/_demo_clock', [
        'label' => 'renderToString() — composed',
        'now'   => microtime(true),
    ]);
    return "<section class=\"render-demo\"><h4>Composed wrapper</h4>{$card}</section>";
});

$app->route('/api/learn/demo/render-stream', ['methods' => ['GET']], function() {
    return (function() {
        yield "<section class=\"render-demo\"><h4>Streamed rows</h4>";
        for ($i = 1; $i <= 5; $i++) {
            usleep(250000);
            yield from App::renderStream('/components/_demo_clock', [
                'label' => "renderStream() — row {$i}",
                'now'   => microtime(true),
            ]);
        }
        yield "</section>";
    })();
});

// ── Chat status (will be replaced by ZealAPI file in M6.5) ───────────
$app->route('/api/learn/chat/status', ['methods' => ['GET']], function() {
    $key = (string)(getenv('OPENAI_API_KEY') ?: '');
    header('Content-Type: application/json');
    return [
        'ai_enabled' => $key !== '',
        'mock_mode'  => $key === '',
        'model'      => $key !== '' ? (getenv('ZEALPHP_LEARN_AI_MODEL') ?: 'gpt-4.1-mini') : 'mock-rules-v1',
    ];
});

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

    $response->sse(function($emit) use ($db, $userId, $message, $msgLower, $threadId) {
        $emit(json_encode(['thread_id' => $threadId]), 'thread');

        if (preg_match('/(list|show all|what\'?s in)/i', $msgLower)) {
            $emit(json_encode(['id' => 'm1', 'name' => 'list_notes', 'phase' => 'start']), 'tool_call');
            usleep(120000);
            $notes = learn_notes_list($db, $userId);
            $emit(json_encode(['id' => 'm1', 'status' => 'ok', 'result_preview' => count($notes) . ' notes']), 'tool_done');
            if (empty($notes)) {
                $emit(json_encode(['token' => '<p>No notes yet. Try "create a note titled buy milk".</p>']), 'token');
            } else {
                $html = '<ul>' . implode('', array_map(fn($n) => '<li>' . htmlspecialchars($n['title']) . ' — id ' . (int)$n['id'] . '</li>', $notes)) . '</ul>';
                $emit(json_encode(['token' => '<p>Here are your notes:</p>' . $html]), 'token');
            }
        } elseif (preg_match('/(create|add)(\s+a)?\s+note(\s+(titled|called|saying))?\s+["\']?(.+?)["\']?$/i', $message, $m)) {
            $title = trim($m[5] ?? 'untitled');
            $emit(json_encode(['token' => '<p>Got it, creating that note.</p>']), 'token');
            $emit(json_encode(['id' => 'm2', 'name' => 'create_note', 'phase' => 'start']), 'tool_call');
            $json = json_encode(['title' => $title, 'body' => '']);
            foreach (str_split($json, 12) as $chunk) {
                $emit(json_encode(['id' => 'm2', 'delta' => $chunk]), 'tool_args');
                usleep(40000);
            }
            $newId = learn_notes_create($db, $userId, $title, '');
            $emit(json_encode(['id' => 'm2', 'status' => $newId ? 'ok' : 'error', 'result_preview' => $newId ? "id: $newId" : 'failed']), 'tool_done');
            $emit(json_encode([]), 'notes_changed');
            $emit(json_encode(['token' => "<p>Created note <strong>" . htmlspecialchars($title) . "</strong>.</p>"]), 'token');
        } elseif (preg_match('/delete\s+(?:note\s+)?["\']?(.+?)["\']?$/i', $message, $m)) {
            $needle = trim($m[1]);
            $notes = learn_notes_list($db, $userId);
            $hit = null;
            foreach ($notes as $n) if (stripos($n['title'], $needle) !== false) { $hit = $n; break; }
            if (!$hit) {
                $emit(json_encode(['token' => "<p>I couldn't find a note matching <em>" . htmlspecialchars($needle) . "</em>.</p>"]), 'token');
            } else {
                $emit(json_encode(['id' => 'm3', 'name' => 'delete_note', 'phase' => 'start']), 'tool_call');
                learn_notes_delete($db, $userId, (int)$hit['id']);
                $emit(json_encode(['id' => 'm3', 'status' => 'ok', 'result_preview' => 'deleted id ' . $hit['id']]), 'tool_done');
                $emit(json_encode([]), 'notes_changed');
                $emit(json_encode(['token' => "<p>Deleted note <strong>" . htmlspecialchars($hit['title']) . "</strong>.</p>"]), 'token');
            }
        } elseif (preg_match('/(search|find)\s+(.+)/i', $message, $m)) {
            $q = trim($m[2]);
            $emit(json_encode(['id' => 'm4', 'name' => 'search_notes', 'phase' => 'start']), 'tool_call');
            $hits = learn_notes_search($db, $userId, $q);
            $emit(json_encode(['id' => 'm4', 'status' => 'ok', 'result_preview' => count($hits) . ' hits']), 'tool_done');
            if (empty($hits)) $emit(json_encode(['token' => "<p>No notes match <em>" . htmlspecialchars($q) . "</em>.</p>"]), 'token');
            else $emit(json_encode(['token' => '<ul>' . implode('', array_map(fn($n) => '<li>' . htmlspecialchars($n['title']) . '</li>', $hits)) . '</ul>']), 'token');
        } else {
            $emit(json_encode(['token' => '<p>Mock mode is active — set <code>OPENAI_API_KEY</code> for the real model. Try: <em>create a note titled buy milk</em>, <em>list notes</em>, <em>delete buy milk</em>, <em>search groceries</em>.</p>']), 'token');
        }
        $emit(json_encode(['done' => true]), 'done');
    });
}

function learn_chat_real($response, array $user, string $message, string $threadId, string $apiKey): void {
    // Filled in by Milestone 7.
    $response->sse(function($emit) use ($threadId) {
        $emit(json_encode(['thread_id' => $threadId]), 'thread');
        $emit(json_encode(['token' => '<p>Real AI not wired yet (Milestone 7).</p>']), 'token');
        $emit(json_encode(['done' => true]), 'done');
    });
}
