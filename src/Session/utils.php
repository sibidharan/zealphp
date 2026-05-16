<?php
namespace ZealPHP\Session;

use ZealPHP\RequestContext;

/**
 * Start a new session or resume existing one
 */
function zeal_session_start(): bool
{
    $g = RequestContext::instance();

    // Ensure session parameters are initialized
    if (!isset($g->session_params['save_path'])) {
        $g->session_params['save_path'] = '/var/lib/php/sessions';
    }
    if (!isset($g->session_params['name'])) {
        $g->session_params['name'] = 'PHPSESSID';
    }
    if (!isset($g->session_params['cookie_params'])) {
        $isHttps = (
            ($g->server['HTTPS'] ?? '') === 'on' ||
            ($g->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ||
            ($g->server['SERVER_PORT'] ?? '') === '443'
        );
        $envSecure = getenv('ZEALPHP_SESSION_SECURE');
        $secure = ($envSecure !== false) ? filter_var($envSecure, FILTER_VALIDATE_BOOLEAN) : $isHttps;

        $g->session_params['cookie_params'] = [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    // Ensure session save path exists (cached per path — directory never disappears mid-run)
    static $verified_paths = [];
    // @phpstan-ignore-next-line — session_params is array<string, mixed>; save_path coerced to string at boundary
    $save_path = (string)$g->session_params['save_path'];
    if (!isset($verified_paths[$save_path])) {
        if (!is_dir($save_path)) {
            mkdir($save_path, 0700, true);
        }
        $verified_paths[$save_path] = true;
    }

    // Get session ID from cookie or generate a new one
    $session_id = zeal_session_id();

    // Read session data from file. Defensive against three failure modes that
    // would otherwise crash the worker (TypeError: cannot assign false to
    // typed array property):
    //   1. Empty file — unserialize('') returns false
    //   2. Corrupted / truncated file (interrupted write) — unserialize returns false
    //   3. TOCTOU race: file_exists returned true but file_get_contents fails
    // All three reduce to "treat as no session data" rather than crashing.
    $session_data = [];
    $session_file = $g->session_params['save_path'] . '/sess_' . $session_id;
    if (file_exists($session_file)) {
        $contents = @file_get_contents($session_file);
        if (is_string($contents) && $contents !== '') {
            $decoded = @unserialize($contents, ['allowed_classes' => false]);
            if (is_array($decoded)) {
                $session_data = $decoded;
            }
        }
    }
    $g->session = $session_data;

    return true;
}


/**
 * Get or set the session ID
 *
 * @param string|null $id
 * @return string|false
 */
function zeal_session_id($id = null)
{
    $g = RequestContext::instance();

    if (!isset($g->session_params['name'])) {
        $g->session_params['name'] = 'PHPSESSID';
    }

    // @phpstan-ignore-next-line — session_params is array<string, mixed>; name coerced to string at boundary
    $session_name = (string)$g->session_params['name'];

    if ($id === null) {
        // Get session ID from cookie or generate new one
        if (isset($g->cookie[$session_name])) {
            // @phpstan-ignore-next-line — cookie is array<string, mixed>; session id coerced to string at boundary
            return (string)$g->cookie[$session_name];
        } else {
            $new_id = session_create_id();
            $g->cookie[$session_name] = $new_id;
            return $new_id;
        }
    } else {
        // Set session ID
        $g->cookie[$session_name] = $id;
        return $id;
    }
}

function zeal_session_status(): int {
    $g = RequestContext::instance();
    if(isset($g->session)){
        return PHP_SESSION_ACTIVE;
    }else{
        return PHP_SESSION_NONE;
    }
}


/**
 * Get or set the session name
 *
 * @param string|null $name
 * @return string
 */
function zeal_session_name($name = null)
{
    $g = RequestContext::instance();

    if ($name === null) {
        // @phpstan-ignore-next-line — session_params is array<string, mixed>; name coerced to string at boundary
        return (string)($g->session_params['name'] ?? 'PHPSESSID');
    } else {
        $g->session_params['name'] = $name;
        return $name;
    }
}

/**
 * Write session data and close the session
 */
function zeal_session_write_close(): bool
{
    $g = RequestContext::instance();

    if (isset($g->session)) {
        // Get session ID
        $session_id = zeal_session_id();

        // Write session data to file
        $session_file = $g->session_params['save_path'] . '/sess_' . $session_id;
        file_put_contents($session_file, serialize($g->session));

        // Unset session data in $g
        unset($g->session);
    }
    return true;
}

/**
 * Destroy the session
 */
function zeal_session_destroy(): bool
{
    $g = RequestContext::instance();

    // Get session ID
    $session_id = zeal_session_id();

    // Delete session file
    $session_file = $g->session_params['save_path'] . '/sess_' . $session_id;
    if (file_exists($session_file)) {
        unlink($session_file);
    }

    // Unset session data and cookie
    unset($g->session);
    unset($g->cookie[$g->session_params['name']]);

    return true;
}

/**
 * Unset all session variables
 */
function zeal_session_unset(): void
{
    $g = RequestContext::instance();
    $g->session = [];
}

/**
 * Regenerate session ID
 *
 * @param bool $delete_old_session
 */
function zeal_session_regenerate_id($delete_old_session = false): bool
{
    $g = RequestContext::instance();

    // Get old session ID
    $old_session_id = zeal_session_id();

    // Generate new session ID
    $new_session_id = bin2hex(random_bytes(32));
    zeal_session_id($new_session_id);

    // Rename session file if keeping old session data
    $old_session_file = $g->session_params['save_path'] . '/sess_' . $old_session_id;
    $new_session_file = $g->session_params['save_path'] . '/sess_' . $new_session_id;

    if (file_exists($old_session_file)) {
        if ($delete_old_session) {
            unlink($old_session_file);
        } else {
            rename($old_session_file, $new_session_file);
        }
    }

    return true;
}

/**
 * Get session cookie parameters
 *
 * @return array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite?: string}
 */
function zeal_session_get_cookie_params(): array
{
    $g = RequestContext::instance();
    $params = $g->session_params['cookie_params'] ?? null;
    if (is_array($params)) {
        // @phpstan-ignore-next-line — session_params is array<string, mixed>; runtime invariant: cookie_params matches shape
        return $params;
    }
    return [
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/**
 * Set session cookie parameters
 *
 * @param int    $lifetime
 * @param string $path
 * @param string $domain
 * @param bool   $secure
 * @param bool   $httponly
 */
function zeal_session_set_cookie_params($lifetime, $path = '/', $domain = '', $secure = false, $httponly = false): void
{
    $g = RequestContext::instance();
    $g->session_params['cookie_params'] = compact('lifetime', 'path', 'domain', 'secure', 'httponly');
}

/**
 * @param string|null $cache_limiter
 */
function zeal_session_cache_limiter($cache_limiter = null): string
{
    $g = RequestContext::instance();

    if ($cache_limiter === null) {
        return $g->cache_limiter ?? 'nocache';
    } else {
        $g->cache_limiter = $cache_limiter;
        return $cache_limiter;
    }
}

function zeal_session_commit(): bool
{
    return zeal_session_write_close();
}

/**
 * @param int|null $cache_expire
 */
function zeal_session_cache_expire($cache_expire = null): int
{
    $g = RequestContext::instance();

    if ($cache_expire === null) {
        return $g->cache_expire ?? 180;
    } else {
        $g->cache_expire = $cache_expire;
        return $cache_expire;
    }
}

function zeal_session_abort(): bool
{
    $g = RequestContext::instance();

    // Discard session changes
    if (isset($g->session)) {
        // Get session ID
        $session_id = zeal_session_id();

        // Read session data from file (same defensive handling as zeal_session_start —
        // empty/corrupted files must not crash the worker).
        $session_file = $g->session_params['save_path'] . '/sess_' . $session_id;
        if (file_exists($session_file)) {
            $session_data = [];
            $contents = @file_get_contents($session_file);
            if (is_string($contents) && $contents !== '') {
                $decoded = @unserialize($contents, ['allowed_classes' => false]);
                if (is_array($decoded)) {
                    $session_data = $decoded;
                }
            }
            $g->session = $session_data;
        } else {
            $g->session = [];
        }
    }

    return true;
}

function zeal_session_encode(): string
{
    return serialize(RequestContext::instance()->session);
}

/**
 * @param string $data
 */
function zeal_session_decode($data): bool
{
    // Defensive: unserialize() returns false on malformed input, which would
    // TypeError on assignment to the typed array property. Match PHP native
    // session_decode signature — returns bool, true only on successful decode.
    if (!is_string($data) || $data === '') {
        return false;
    }
    $decoded = @unserialize($data, ['allowed_classes' => false]);
    if (!is_array($decoded)) {
        return false;
    }
    RequestContext::instance()->session = $decoded;
    return true;
}

/**
 * @param string $prefix
 * @return string|false
 */
function zeal_session_create_id($prefix = '')
{
    return session_create_id($prefix);
}

/**
 * @param string|null $path
 * @return string
 */
function zeal_session_save_path($path = null)
{
    $g = RequestContext::instance();

    if ($path === null) {
        // @phpstan-ignore-next-line — session_params is array<string, mixed>; save_path coerced to string at boundary
        return (string)($g->session_params['save_path'] ?? '/var/lib/php/sessions');
    } else {
        $g->session_params['save_path'] = $path;
        return $path;
    }
}

/**
 * @param string|null $module
 */
function zeal_session_module_name($module = null): string
{
    $g = RequestContext::instance();

    if ($module === null) {
        return $g->session_module_name ?? 'files';
    } else {
        $g->session_module_name = $module;
        return $module;
    }
}
