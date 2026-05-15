<?php
namespace ZealPHP\Learn;

use ZealPHP\G;

class Auth
{
    public static function validateUsername(string $u): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]{3,64}$/', $u);
    }

    public static function validatePassword(string $p): bool
    {
        $len = strlen($p);
        return $len >= 8 && $len <= 256;
    }

    public static function register(\PDO $db, string $username, string $password): ?int
    {
        if (!self::validateUsername($username) || !self::validatePassword($password)) return null;
        try {
            $stmt = $db->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), time()]);
            return (int) $db->lastInsertId();
        } catch (\PDOException $e) {
            return null;
        }
    }

    public static function login(\PDO $db, string $username, string $password): ?int
    {
        $row = $db->prepare('SELECT id, password_hash FROM users WHERE username = ?');
        $row->execute([$username]);
        $user = $row->fetch();
        if (!$user) return null;
        if (!password_verify($password, $user['password_hash'])) return null;
        return (int) $user['id'];
    }

    public static function currentUser(): ?array
    {
        $g = G::instance();
        if (!empty($g->session['user_id'])) {
            $userId = (int) $g->session['user_id'];
            $db = DB::open();
            $stmt = $db->prepare('SELECT id, username FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row) {
                unset($g->session['user_id'], $g->session['username']);
                return null;
            }
            return ['user_id' => (int) $row['id'], 'username' => (string) $row['username']];
        }
        return null;
    }

    public static function readCredentials($g): ?array
    {
        $ct = $g->server['HTTP_CONTENT_TYPE'] ?? $g->server['CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') !== false) {
            $body = json_decode($g->zealphp_request->parent->getContent(), true);
            if (!is_array($body)) return null;
            $u = (string) ($body['username'] ?? '');
            $p = (string) ($body['password'] ?? '');
        } else {
            $u = (string) ($g->post['username'] ?? '');
            $p = (string) ($g->post['password'] ?? '');
        }
        if ($u === '' || $p === '') return null;
        return ['username' => $u, 'password' => $p];
    }

    public static function rateLimit(string $table, string $ip, int $limit, int $window): bool
    {
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
}
