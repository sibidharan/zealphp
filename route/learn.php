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

// Endpoint registrations (added in Task 2.3, 3.x, 6.x). Skipped when unit-testing.
if (class_exists('ZealPHP\\App', false) && !defined('ZEALPHP_LEARN_TESTING') && \ZealPHP\App::instance()) {
    // Filled in below in later tasks.
}
