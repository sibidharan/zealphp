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
    $response->redirect('/learn/notes', 302);
});

$app->route('/api/learn/logout', ['methods' => ['POST', 'GET']], function($request, $response) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION = [];
    session_destroy();
    $response->redirect('/learn/notes', 302);
});
