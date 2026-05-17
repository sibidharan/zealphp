<?php
namespace ZealPHP\Session;

use ZealPHP\RequestContext;

/**
 * Decode PHP 'php' session serialize format (key|serialized_value;key|...).
 * Falls back to unserialize() for php_serialize handler format.
 */
function php_session_decode_to_array(string $data): array
{
    $decoded = @unserialize($data, ['allowed_classes' => true]);
    if (is_array($decoded)) {
        return $decoded;
    }
    $result = [];
    $offset = 0;
    $len = strlen($data);
    while ($offset < $len) {
        $pipe = strpos($data, '|', $offset);
        if ($pipe === false) break;
        $key = substr($data, $offset, $pipe - $offset);
        $offset = $pipe + 1;
        $value = @unserialize(substr($data, $offset), ['allowed_classes' => true]);
        if ($value === false && substr($data, $offset, 4) !== 'b:0;') {
            $next = strpos($data, ';', $offset);
            if ($next !== false) {
                $offset = $next + 1;
            } else {
                break;
            }
        } else {
            $serialized = serialize($value);
            $offset += strlen($serialized);
            $result[$key] = $value;
        }
    }
    return $result;
}

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
    /** @var array<string, bool> $verified_paths */
    static $verified_paths = [];
    $rawSavePath = $g->session_params['save_path'];
    $save_path = is_string($rawSavePath) ? $rawSavePath : '';

    $handler = $g->session_params['handler'] ?? null;

    if (!($handler instanceof \SessionHandlerInterface)) {
        if (!isset($verified_paths[$save_path])) {
            if (!is_dir($save_path)) {
                mkdir($save_path, 0700, true);
            }
            $verified_paths[$save_path] = true;
        }
    }

    // Get session ID from cookie or generate a new one
    $session_id = zeal_session_id();

    /** @var array<string, mixed> $session_data */
    $session_data = [];

    if ($handler instanceof \SessionHandlerInterface) {
        $handler->open($save_path, $g->session_params['name'] ?? 'PHPSESSID');
        $contents = $handler->read($session_id);
        if (is_string($contents) && $contents !== '') {
            $session_data = php_session_decode_to_array($contents);
        }
    } else {
        $session_file = $save_path . '/sess_' . $session_id;
        if (file_exists($session_file)) {
            $contents = @file_get_contents($session_file);
            if (is_string($contents) && $contents !== '') {
                $session_data = php_session_decode_to_array($contents);
            }
        }
    }
    // In superglobals mode, $_SESSION is the canonical store. The declared
    // typed property `$g->session` shadows the __get/__set proxy, so any
    // legacy code (and Symfony's NativeSessionStorage) that writes through
    // $_SESSION would never round-trip through $g->session. Mirror to both:
    // $_SESSION is what user/Symfony code reads/writes, $g->session is what
    // coroutine-mode code reads/writes via the typed property.
    $g->session = $session_data;
    if (\ZealPHP\App::$superglobals) {
        $GLOBALS['_SESSION'] = $session_data;
    }

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
    // In superglobals mode the canonical "is session active?" signal is
    // $_SESSION's existence — the declared typed $g->session property is
    // always isset (default []), so checking it would always report ACTIVE
    // and trip Symfony/PSR-7 frameworks that refuse to start an already-
    // active session.
    if (\ZealPHP\App::$superglobals) {
        return (isset($GLOBALS['_SESSION']) && is_array($GLOBALS['_SESSION']))
            ? PHP_SESSION_ACTIVE
            : PHP_SESSION_NONE;
    }
    $g = RequestContext::instance();
    // Coroutine mode: typed slot is unset() by write_close/destroy to mark
    // inactive. PHPStan cannot track unset-on-typed-prop.
    /** @phpstan-ignore-next-line isset.property — runtime tests uninitialized typed slot */
    return isset($g->session) ? PHP_SESSION_ACTIVE : PHP_SESSION_NONE;
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

    // In superglobals mode the canonical session store is $_SESSION (the
    // declared typed property $g->session shadows the __get/__set proxy,
    // so Symfony/legacy code that writes through $_SESSION never reaches
    // $g->session). Persist whichever holds the live data for the mode.
    $superglobals = \ZealPHP\App::$superglobals;
    $hasSession = $superglobals
        ? (isset($GLOBALS['_SESSION']) && is_array($GLOBALS['_SESSION']))
        /** @phpstan-ignore-next-line isset.property — runtime tests uninitialized typed slot */
        : isset($g->session);

    if ($hasSession) {
        $session_id = zeal_session_id();
        $save_path = $g->session_params['save_path'] ?? '';
        assert(is_string($save_path));
        $session_file = $save_path . '/sess_' . $session_id;
        $data = $superglobals ? $GLOBALS['_SESSION'] : $g->session;
        file_put_contents($session_file, serialize($data));

        // Mark inactive — in both modes. Unset the typed slot in coroutine
        // mode; clear the superglobal in superglobals mode so the next
        // request starts from a known-empty state (CoSessionManager /
        // SessionManager clear $_SESSION too, but native routes calling
        // session_write_close() directly need it here as well).
        if ($superglobals) {
            $GLOBALS['_SESSION'] = [];
            unset($GLOBALS['_SESSION']);
        } else {
            unset($g->session);
        }
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
    $save_path = $g->session_params['save_path'] ?? '';
    assert(is_string($save_path));
    $session_file = $save_path . '/sess_' . $session_id;
    if (file_exists($session_file)) {
        unlink($session_file);
    }

    // Unset session data and cookie — mirror in both storages for the same
    // reason as zeal_session_start: typed $g->session and $_SESSION are
    // independent slots in superglobals mode.
    unset($g->session);
    if (\ZealPHP\App::$superglobals) {
        unset($GLOBALS['_SESSION']);
    }
    $session_name = $g->session_params['name'] ?? '';
    assert(is_string($session_name));
    unset($g->cookie[$session_name]);

    return true;
}

/**
 * Unset all session variables
 */
function zeal_session_unset(): void
{
    $g = RequestContext::instance();
    $g->session = [];
    if (\ZealPHP\App::$superglobals) {
        $GLOBALS['_SESSION'] = [];
    }
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
    $save_path = $g->session_params['save_path'] ?? '';
    assert(is_string($save_path));
    $old_session_file = $save_path . '/sess_' . $old_session_id;
    $new_session_file = $save_path . '/sess_' . $new_session_id;

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
function zeal_session_set_cookie_params($lifetime_or_options, $path = '/', $domain = '', $secure = false, $httponly = false): void
{
    $g = RequestContext::instance();
    if (is_array($lifetime_or_options)) {
        $g->session_params['cookie_params'] = array_merge([
            'lifetime' => 0, 'path' => '/', 'domain' => '',
            'secure' => false, 'httponly' => false, 'samesite' => 'Lax',
        ], $lifetime_or_options);
    } else {
        $g->session_params['cookie_params'] = compact('path', 'domain', 'secure', 'httponly');
        $g->session_params['cookie_params']['lifetime'] = $lifetime_or_options;
    }
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
    // isset() runtime-checks uninitialized typed slot (unset() may have been called)
    /** @phpstan-ignore-next-line isset.property — runtime tests uninitialized typed slot */
    if (isset($g->session)) {
        // Get session ID
        $session_id = zeal_session_id();

        // Read session data from file (same defensive handling as zeal_session_start —
        // empty/corrupted files must not crash the worker).
        $save_path = $g->session_params['save_path'] ?? '';
        assert(is_string($save_path));
        $session_file = $save_path . '/sess_' . $session_id;
        if (file_exists($session_file)) {
            /** @var array<string, mixed> $session_data */
            $session_data = [];
            $contents = @file_get_contents($session_file);
            if (is_string($contents) && $contents !== '') {
                $decoded = @unserialize($contents, ['allowed_classes' => false]);
                if (is_array($decoded)) {
                    foreach ($decoded as $k => $v) {
                        if (is_string($k)) {
                            $session_data[$k] = $v;
                        }
                    }
                }
            }
            $g->session = $session_data;
            if (\ZealPHP\App::$superglobals) {
                $GLOBALS['_SESSION'] = $session_data;
            }
        } else {
            $g->session = [];
            if (\ZealPHP\App::$superglobals) {
                $GLOBALS['_SESSION'] = [];
            }
        }
    }

    return true;
}

function zeal_session_encode(): string
{
    // In superglobals mode, $_SESSION is the canonical store (Symfony / legacy
    // code writes here). In coroutine mode, the typed $g->session is.
    $data = \ZealPHP\App::$superglobals
        ? ($GLOBALS['_SESSION'] ?? [])
        : RequestContext::instance()->session;
    return serialize($data);
}

function zeal_session_decode(string $data): bool
{
    // Defensive: unserialize() returns false on malformed input, which would
    // TypeError on assignment to the typed array property. Match PHP native
    // session_decode signature — returns bool, true only on successful decode.
    if ($data === '') {
        return false;
    }
    $decoded = @unserialize($data, ['allowed_classes' => false]);
    if (!is_array($decoded)) {
        return false;
    }
    // Narrow array<mixed,mixed> to array<string,mixed> for typed property assignment.
    /** @var array<string, mixed> $sessionData */
    $sessionData = [];
    foreach ($decoded as $k => $v) {
        if (is_string($k)) {
            $sessionData[$k] = $v;
        }
    }
    RequestContext::instance()->session = $sessionData;
    if (\ZealPHP\App::$superglobals) {
        $GLOBALS['_SESSION'] = $sessionData;
    }
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
