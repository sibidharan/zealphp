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

    /**
     * Increment-and-check sliding window per IP. Returns `false` when the
     * window is full (caller should respond with `429`).
     *
     * Bypasses:
     * - `$limit <= 0` — explicitly disabled (production knob).
     * - Loopback clients (`127.0.0.1` / `::1` / `::ffff:127.0.0.1`) — unless
     *   `ZEALPHP_LEARN_RATE_LIMIT_LOOPBACK=1` is set. Production never sees
     *   loopback traffic (proxied requests carry the real client IP via
     *   `X-Forwarded-For`); the bypass exists so the integration test suite
     *   can run repeatedly without `php app.php restart` between runs.
     *   Opt back in via the env var if you're testing the rate limiter
     *   itself or running `phpunit` against a non-loopback bind.
     */
    public static function rateLimit(string $table, string $ip, int $limit, int $window): bool
    {
        if ($limit <= 0) return true;
        if (self::isLoopback($ip) && getenv('ZEALPHP_LEARN_RATE_LIMIT_LOOPBACK') !== '1') {
            return true;
        }
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

    private static function isLoopback(string $ip): bool
    {
        return $ip === '127.0.0.1'
            || $ip === '::1'
            || $ip === '::ffff:127.0.0.1'
            || str_starts_with($ip, '127.');
    }

    /**
     * Emit the post-auth redirect for the shared learn login/register/logout
     * flow. For an htmx request it sends a clean client-side redirect
     * (`HX-Redirect` + 200). A bare `302 Location` must NOT be used for htmx:
     * the XHR follows the 302 transparently, so htmx never sees the redirect
     * and instead swaps the redirected page into the form's small target —
     * which wrecks the layout (the reported "sidebar disappears on login"
     * bug). Non-htmx (no-JS) posts still get a normal 302.
     *
     * Redirects back to the page the user acted from — htmx's `HX-Current-URL`,
     * then `Referer` — so logging in on /learn/tictactoe stays there instead
     * of always dumping the user on /learn/notes. Only same-site absolute
     * paths are honoured; anything else falls back to $default.
     */
    public static function redirectAfterAuth(RequestContext $g, string $default = '/learn/notes'): void
    {
        $candidate = '';
        foreach (['HTTP_HX_CURRENT_URL', 'HTTP_REFERER'] as $header) {
            $value = $g->server[$header] ?? null;
            if (is_string($value) && $value !== '') {
                $candidate = $value;
                break;
            }
        }

        $path = $candidate !== '' ? (string) (parse_url($candidate, PHP_URL_PATH) ?: '') : '';
        // Same-site absolute path only: leading "/" but not protocol-relative "//".
        $target = ($path !== '' && $path[0] === '/' && !str_starts_with($path, '//'))
            ? $path
            : $default;

        if (!empty($g->server['HTTP_HX_REQUEST'])) {
            header('HX-Redirect: ' . $target);
            http_response_code(200);
        } else {
            header('Location: ' . $target);
            http_response_code(302);
        }
    }
}
