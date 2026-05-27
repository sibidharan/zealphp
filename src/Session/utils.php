<?php
namespace ZealPHP\Session;

use ZealPHP\RequestContext;

/**
 * Decode PHP 'php' session serialize format (key|serialized_value;key|...).
 * Falls back to unserialize() for php_serialize handler format.
 *
 * SECURITY: every `unserialize()` call in this file is narrowly scoped to
 * an explicit class whitelist — currently `['stdClass']`. Sessions are
 * user-controlled storage (tampered cookie, compromised Redis); allowing
 * arbitrary class instantiation here would let an attacker trigger
 * `__wakeup()` / `__destruct()` gadgets in any class on the autoload
 * graph (the vulnerability commit c43da63 originally fixed by passing
 * `allowed_classes => false`).
 *
 * Why `stdClass` is on the whitelist (added v0.2.26, issue #15):
 *   - `stdClass` has zero methods — no `__wakeup`, no `__destruct`, no
 *     `__get`/`__set`/`__call`. There is no gadget to chain.
 *   - `json_decode($x)` (the default mode without the second arg) returns
 *     a `stdClass` graph, and apps routinely stash that result in
 *     `$_SESSION['oauth_token']`, `$_SESSION['api_profile']`, etc.
 *     Refusing to round-trip it broke real apps in v0.2.25 (issue #15).
 *
 * Adding more classes to the whitelist requires a SECURITY review for
 * each one: any magic method that runs on unserialize (`__wakeup`,
 * `__unserialize`) or destruct (`__destruct`) can be turned into a
 * gadget. `DateTime` for example has `__wakeup` and is therefore
 * deliberately excluded.
 *
 */
/**
 * Encode an array into PHP's native 'php' session serialize format
 * (key|serialized_value for each key). This matches the format produced by
 * session.serialize_handler = php (the default in mod_php / phpredis).
 *
 * @param array<string, mixed> $data
 */
function php_session_encode_from_array(array $data): string
{
    $encoded = '';
    foreach ($data as $key => $value) {
        $encoded .= $key . '|' . serialize($value);
    }
    return $encoded;
}

/**
 * @return array<string, mixed>
 */
function php_session_decode_to_array(string $data): array
{
    $decoded = @unserialize($data, ['allowed_classes' => ['stdClass']]);
    if (is_array($decoded)) {
        /** @var array<string, mixed> $narrowed */
        $narrowed = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k)) {
                $narrowed[$k] = $v;
            }
        }
        return $narrowed;
    }
    $result = [];
    $offset = 0;
    $len = strlen($data);
    while ($offset < $len) {
        $pipe = strpos($data, '|', $offset);
        if ($pipe === false) break;
        $key = substr($data, $offset, $pipe - $offset);
        $offset = $pipe + 1;
        $value = @unserialize(substr($data, $offset), ['allowed_classes' => ['stdClass']]);
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

    // Detect whether the client already had a session cookie BEFORE we ask
    // zeal_session_id() — that call generates+stashes a new ID into $g->cookie
    // when no cookie was present, so checking after would always be true.
    /** @var string $sessionNameForCookie */
    $sessionNameForCookie = $g->session_params['name'];
    $hadIncomingSessionCookie = isset($g->cookie[$sessionNameForCookie]);

    // Get session ID from cookie or generate a new one
    $session_id = zeal_session_id();

    // Bug #12: emit Set-Cookie if the session is brand-new (no incoming
    // PHPSESSID cookie). Without this, a handler that calls session_start()
    // for a first-time visitor + header('Location: ...') redirects them with
    // no cookie — the next request sees no PHPSESSID, starts a fresh session,
    // and the data we just stored (OAuth state, code_verifier, flash msgs)
    // is gone. Idempotent: $hadIncomingSessionCookie is true on the second
    // call within the same request, so we don't emit twice. Skipped when
    // useCookies is off (zeal_session_set_cookie_params 'use_cookies' = 0)
    // or when the response is no longer writable (already flushed).
    //
    // Gated on App::$session_lifecycle (v0.2.22 contract): when set to false,
    // another framework (e.g. Symfony's NativeSessionStorage via the
    // zealphp-symfony bridge, or user code that drives session_start() +
    // $response->cookie() manually) owns cookie emission. We must NOT
    // race them — auto-emitting here when sessionLifecycle is false caused
    // duplicate PHPSESSID headers on /session/dump in the Symfony bridge.
    $useCookies = (bool)ini_get('session.use_cookies');
    if (!$hadIncomingSessionCookie
        && \ZealPHP\App::$session_lifecycle
        && $useCookies
        && $g->openswoole_response !== null
        && is_string($session_id)
        && $session_id !== ''
        && $g->openswoole_response->isWritable()) {
        $cookieParams = zeal_session_get_cookie_params();
        $g->openswoole_response->cookie(
            $sessionNameForCookie,
            $session_id,
            $cookieParams['lifetime'] ? time() + (int)$cookieParams['lifetime'] : 0,
            $cookieParams['path'],
            $cookieParams['domain'],
            $cookieParams['secure'],
            $cookieParams['httponly']
        );
    }

    /** @var array<string, mixed> $session_data */
    $session_data = [];

    if ($handler instanceof \SessionHandlerInterface) {
        /** @var string $sessionName */
        $sessionName = $g->session_params['name'];
        $handler->open($save_path, (string) $sessionName);
        $contents = $handler->read((string) $session_id);
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
    /** @var array<string, mixed> $session_data */
    $g->session = $session_data;
    // #21: snapshot the keys present at load so write_close() can tell an
    // in-request unset() (must delete from store) from a concurrent add
    // (must survive the merge).
    $g->session_loaded_keys = array_keys($session_data);
    if (\ZealPHP\App::$superglobals) {
        $GLOBALS['_SESSION'] = $session_data;
    }

    // Mark session as started so CoSessionManager's finally block
    // calls zeal_session_write_close() — persists data to disk.
    // Without this, user code calling session_start() directly
    // would write to $g->session but never flush to the session file.
    $g->_session_started = true;

    // PHP's native session_start() defines the SID constant. Apps like
    // Adminer read it directly. define() persists across requests in a
    // long-running process, so we only define once — value is empty string
    // (cookie-based sessions, which is always the case in ZealPHP).
    if (!defined('SID')) {
        define('SID', '');
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
        $wHandler = $g->session_params['handler'] ?? null;
        if ($wHandler instanceof \SessionHandlerInterface) {
            // Merge with stored data to mitigate concurrent-request races.
            // Apache serialises session access via file locks; ZealPHP
            // handles requests concurrently, so two requests both reading
            // the same session and both writing back their own snapshots
            // means last-write-wins and the loser's changes are lost.
            // Reading-then-merging before writing keeps top-level keys
            // added by a concurrent request alive.
            //
            // NOTE: array_merge is SHALLOW. If both requests touched the
            // same NESTED array (e.g. both pushed to $_SESSION['cart']),
            // the later writer's cart still replaces the earlier one —
            // last-write-wins survives at that nesting depth. For
            // OAuth-style flows where each step writes top-level scalars
            // (oauth_state, code_verifier, user_id) the merge is enough.
            // Apps with conflicting nested writes should use a database
            // / Redis hash directly, not session storage.
            //
            // Also note: handler-side locking is not assumed. A
            // SessionHandlerInterface implementation that itself serialises
            // (e.g. via Redis WATCH/MULTI) is strictly better than this
            // best-effort merge — but the merge is correct for the file-
            // handler default and for handlers that don't lock.
            $existing = $wHandler->read((string) $session_id);
            if (is_string($existing) && $existing !== '') {
                $existingData = php_session_decode_to_array($existing);
                // $data is $GLOBALS['_SESSION'] (mixed) or $g->session (array)
                // — narrow before the array_merge so PHPStan can verify the
                // call shape. Non-array $data means somebody outside the
                // framework reassigned $_SESSION to a scalar; treat that as
                // empty and let the merge surface the disk state.
                $current = is_array($data) ? $data : [];
                if ($existingData !== []) {
                    $data = array_merge($existingData, $current);
                    // #21: honor in-request deletions. A key that was loaded
                    // this request (in session_loaded_keys) but is now absent
                    // from $current was unset() — it must NOT be resurrected by
                    // the merge-with-stored mitigation. Keys we never loaded
                    // (concurrent adds, not in session_loaded_keys) are left
                    // intact, preserving the cross-request merge guarantee.
                    foreach ($g->session_loaded_keys as $loadedKey) {
                        if (!array_key_exists($loadedKey, $current)) {
                            unset($data[$loadedKey]);
                        }
                    }
                } else {
                    $data = $current;
                }
            }
            /** @var array<string, mixed> $data */
            $wHandler->write((string) $session_id, php_session_encode_from_array($data));
        } else {
            /** @var array<string, mixed> $data */
            file_put_contents($session_file, php_session_encode_from_array($data));
        }

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

    // Delete session data via handler or file
    $save_path = $g->session_params['save_path'] ?? '';
    assert(is_string($save_path));
    $dHandler = $g->session_params['handler'] ?? null;
    if ($dHandler instanceof \SessionHandlerInterface) {
        $dHandler->destroy((string) $session_id);
    } else {
        $session_file = $save_path . '/sess_' . $session_id;
        if (file_exists($session_file)) {
            unlink($session_file);
        }
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

    $g->_session_started = false;

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

    $save_path = $g->session_params['save_path'] ?? '';
    assert(is_string($save_path));

    // Issue #19: a custom SessionHandlerInterface (Redis/Valkey, etc.) keeps
    // session data in the handler, NOT on disk — so the file rename below is a
    // no-op for it. Without migrating the data the regenerated ID points at an
    // empty session, and anything written afterwards (OAuth `sub`/`tokens`/
    // `profile`) is stranded under an ID the client may never receive.
    // mod_php's regenerate copies the current data to the new ID; mirror that.
    $handler = $g->session_params['handler'] ?? null;
    if ($handler instanceof \SessionHandlerInterface) {
        // The live in-memory data is the canonical session contents for the
        // new ID (it already reflects any writes made before regeneration).
        $superglobals = \ZealPHP\App::$superglobals;
        /** @phpstan-ignore-next-line isset.property — runtime tests uninitialized typed slot */
        $data = $superglobals ? ($GLOBALS['_SESSION'] ?? []) : (isset($g->session) ? $g->session : []);
        $data = is_array($data) ? $data : [];
        /** @var array<string, mixed> $data */
        $handler->write((string) $new_session_id, php_session_encode_from_array($data));
        if ($delete_old_session && is_string($old_session_id) && $old_session_id !== '') {
            $handler->destroy((string) $old_session_id);
        }
    } else {
        // File handler: keep old data by renaming the backing file.
        $old_session_file = $save_path . '/sess_' . $old_session_id;
        $new_session_file = $save_path . '/sess_' . $new_session_id;
        if (file_exists($old_session_file)) {
            if ($delete_old_session) {
                unlink($old_session_file);
            } else {
                rename($old_session_file, $new_session_file);
            }
        }
    }

    // Issue #19: emit a Set-Cookie for the NEW ID so the client switches over.
    // Without this the browser keeps sending the old PHPSESSID and never sees
    // the regenerated session. Gated exactly like zeal_session_start()'s
    // first-visit cookie: only when the framework owns session lifecycle
    // (App::$session_lifecycle), cookies are enabled, and the response is
    // still writable — so the Symfony bridge / manual-cookie apps aren't raced.
    $useCookies = (bool) ini_get('session.use_cookies');
    /** @var string $sessionName */
    $sessionName = $g->session_params['name'] ?? 'PHPSESSID';
    if (\ZealPHP\App::$session_lifecycle
        && $useCookies
        && $g->openswoole_response !== null
        && $g->openswoole_response->isWritable()) {
        $cookieParams = zeal_session_get_cookie_params();
        $g->openswoole_response->cookie(
            $sessionName,
            $new_session_id,
            $cookieParams['lifetime'] ? time() + (int)$cookieParams['lifetime'] : 0,
            $cookieParams['path'],
            $cookieParams['domain'],
            $cookieParams['secure'],
            $cookieParams['httponly']
        );
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
 * Set session cookie parameters. Accepts either positional args (PHP < 8.0)
 * or an options array (PHP 8.0+).
 *
 * @param int|array<string, mixed> $lifetime_or_options
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
                $decoded = @unserialize($contents, ['allowed_classes' => ['stdClass']]);
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
    $data = \ZealPHP\App::$superglobals
        ? ($GLOBALS['_SESSION'] ?? [])
        : RequestContext::instance()->session;
    /** @var array<string, mixed> $narrowed */
    $narrowed = is_array($data) ? $data : [];
    return php_session_encode_from_array($narrowed);
}

function zeal_session_decode(string $data): bool
{
    if ($data === '') {
        return false;
    }
    $sessionData = php_session_decode_to_array($data);
    if ($sessionData === []) {
        return false;
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
