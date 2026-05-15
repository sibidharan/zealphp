<?php
namespace ZealPHP\Learn;

class DB
{
    private static array $cache = [];

    public static function path(): string
    {
        $configured = getenv('ZEALPHP_LEARN_DB_PATH');
        if ($configured === false || $configured === '') $configured = 'storage/learn.db';
        if ($configured[0] !== '/') {
            $root = defined('ZEALPHP_ROOT') ? ZEALPHP_ROOT : dirname(__DIR__, 2);
            $configured = $root . '/' . $configured;
        }
        $dir = dirname($configured);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $configured;
    }

    public static function open(): \PDO
    {
        $path = self::path();
        if (isset(self::$cache[$path])) return self::$cache[$path];

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

        self::$cache[$path] = $pdo;
        return $pdo;
    }
}
