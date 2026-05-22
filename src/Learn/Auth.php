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
     * Resolve the post-auth destination: the page the user acted from
     * (htmx's `HX-Current-URL`, then `Referer`), so login keeps you in
     * context instead of always dumping you on /learn/notes. Only a
     * same-site absolute path is honoured (leading "/" but not the
     * protocol-relative "//"); anything else falls back to $default. Pure
     * + side-effect-free so it's unit-testable.
     */
    public static function resolveAuthRedirect(?string $hxCurrentUrl, ?string $referer, string $default = '/learn/notes'): string
    {
        foreach ([$hxCurrentUrl, $referer] as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $path = (string) (parse_url($candidate, PHP_URL_PATH) ?: '');
            if ($path !== '' && $path[0] === '/' && !str_starts_with($path, '//')) {
                return $path;
            }
        }
        return $default;
    }

    /**
     * Emit the post-auth response for the shared learn login/register/logout
     * flow.
     *
     * For an htmx request it sends `HX-Location` — an in-place content swap,
     * NOT a navigation: htmx fetches the destination, selects its
     * `.lesson-content`, and swaps it into the current one. No full page
     * reload, scroll position kept, and the `hx-preserve`d sidebar is left
     * untouched. (The earlier `HX-Redirect` did a full client-side reload —
     * correct layout, but it reset scroll and re-fetched the whole page; and
     * a bare `302 Location` is even worse: the XHR follows it transparently,
     * so htmx swapped the redirected page into the form's tiny feedback div
     * and dropped the sidebar — the originally reported bug.)
     *
     * Non-htmx (no-JS) posts still get a normal `302 Location`.
     */
    public static function redirectAfterAuth(RequestContext $g, string $default = '/learn/notes'): void
    {
        $hxUrl   = $g->server['HTTP_HX_CURRENT_URL'] ?? null;
        $referer = $g->server['HTTP_REFERER'] ?? null;
        $target  = self::resolveAuthRedirect(
            is_string($hxUrl) ? $hxUrl : null,
            is_string($referer) ? $referer : null,
            $default
        );

        if (!empty($g->server['HTTP_HX_REQUEST'])) {
            header('HX-Location: ' . (string) json_encode([
                'path'   => $target,
                'target' => '.lesson-content',
                'select' => '.lesson-content',
                // `show:none` keeps htmx from scrolling on the swap, so the
                // page doesn't jump to the top — the reader stays where they
                // were (e.g. at the login panel, now showing the logged-in UI).
                'swap'   => 'outerHTML show:none',
            ]));
            http_response_code(200);
        } else {
            header('Location: ' . $target);
            http_response_code(302);
        }
    }
}
