<?php
namespace ZealPHP\Learn;

use ZealPHP\RequestContext;

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
        if (!is_array($user)) return null;
        $hash = $user['password_hash'] ?? '';
        if (!is_string($hash) || !password_verify($password, $hash)) return null;
        $id = $user['id'] ?? 0;
        return is_numeric($id) ? (int)$id : null;
    }

    /** @return array{user_id: int, username: string}|null */
    public static function currentUser(): ?array
    {
        $g = RequestContext::instance();
        if (!empty($g->session['user_id'])) {
            // @phpstan-ignore-next-line — $g->session is array<string, mixed>; user_id coerced to int at boundary
            $userId = (int) $g->session['user_id'];
            $db = DB::open();
            $stmt = $db->prepare('SELECT id, username FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                unset($g->session['user_id'], $g->session['username']);
                return null;
            }
            $id = $row['id'] ?? 0;
            $username = $row['username'] ?? '';
            return [
                'user_id' => is_numeric($id) ? (int)$id : 0,
                'username' => is_scalar($username) ? (string)$username : '',
            ];
        }
        return null;
    }

    /**
     * @param \ZealPHP\RequestContext $g
     * @return array{username: string, password: string}|null
     */
    public static function readCredentials($g): ?array
    {
        $ct = (string)($g->server['HTTP_CONTENT_TYPE'] ?? $g->server['CONTENT_TYPE'] ?? '');
        if (stripos($ct, 'application/json') !== false) {
            // @phpstan-ignore-next-line — zealphp_request set by CoSessionManager before any request handler runs
            $body = json_decode((string)$g->zealphp_request->parent->getContent(), true);
            if (!is_array($body)) return null;
            $rawU = $body['username'] ?? '';
            $rawP = $body['password'] ?? '';
            $u = is_scalar($rawU) ? (string)$rawU : '';
            $p = is_scalar($rawP) ? (string)$rawP : '';
        } else {
            $rawU = $g->post['username'] ?? '';
            $rawP = $g->post['password'] ?? '';
            $u = is_scalar($rawU) ? (string)$rawU : '';
            $p = is_scalar($rawP) ? (string)$rawP : '';
        }
        if ($u === '' || $p === '') return null;
        return ['username' => $u, 'password' => $p];
    }

    public static function rateLimit(string $table, string $ip, int $limit, int $window): bool
    {
        $now = time();
        $existing = \ZealPHP\Store::get($table, $ip);
        if (is_array($existing)) {
            $reset = $existing['reset'] ?? 0;
            $count = $existing['count'] ?? 0;
            $resetInt = is_numeric($reset) ? (int)$reset : 0;
            $countInt = is_numeric($count) ? (int)$count : 0;
            if ($now < $resetInt) {
                if ($countInt >= $limit) return false;
                \ZealPHP\Store::incr($table, $ip, 'count', 1);
                return true;
            }
        }
        \ZealPHP\Store::set($table, $ip, ['ip' => $ip, 'count' => 1, 'reset' => $now + $window]);
        return true;
    }
}
