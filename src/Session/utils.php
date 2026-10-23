<?php
namespace ZealPHP\Session;

use ZealPHP\RequestContext;

/**
 * Session utility functions for ZealPHP's coroutine-safe session layer.
 *
 * These functions are registered via `uopz_set_return()` at startup to replace
 * PHP's built-in `session_*()` family. They read and write to
 * `RequestContext::instance()->session` (coroutine-isolated storage) rather
 * than the process-global `$_SESSION`, making session operations safe under
 * OpenSwoole coroutine concurrency.
 *
 * SECURITY: every `unserialize()` call in this file passes an explicit class
 * whitelist — currently `['allowed_classes' => ['stdClass']]`. Sessions are
 * user-controlled storage (tampered cookie, compromised Redis); allowing
 * arbitrary class instantiation would let an attacker trigger `__wakeup()` /
 * `__destruct()` gadgets in any class on the autoload graph.
 *
 * Why `stdClass` is on the whitelist (added v0.2.26, issue #15):
 * - `stdClass` has zero methods — no `__wakeup`, no `__destruct`, no
 *   `__get`/`__set`/`__call`. There is no gadget to chain.
 * - `json_decode($x)` (without the second arg) returns a `stdClass` graph,
 *   and apps routinely stash that result in `$_SESSION['oauth_token']`,
 *   `$_SESSION['api_profile']`, etc. Refusing to round-trip it broke real
 *   apps in v0.2.25 (issue #15).
 *
 * Adding more classes to the whitelist requires a security review for each
 * one: any magic method that runs on unserialize (`__wakeup`, `__unserialize`)
 * or on destruct (`__destruct`) can be turned into a gadget. `DateTime` for
 * example has `__wakeup` and is therefore deliberately excluded.
 */

/**
 * Encode an array into PHP's native `php` session serialize format
 * (`key|serialized_value` for each key). Matches the format produced by
 * `session.serialize_handler = php` (the default in mod_php / phpredis).
 *
 * @param array<string, mixed> $data
 */
function php_session_encode_from_array(array $data): string
{
    $encoded = '';
    foreach ($data as $key => $value) {
        // Native session_encode() refuses a key containing `|`: the decoder
        // would split the key there and read the rest of it as a value, so a
        // user-chosen key could plant other keys. Skip it (with a warning, as
        // PHP does) instead of writing an ambiguous entry.
        if (str_contains((string) $key, '|')) {
            trigger_error("Session key '" . $key . "' contains the '|' separator and was not saved", E_USER_WARNING);
            continue;
        }
        $encoded .= $key . '|' . serialize($value);
    }
    return $encoded;
}

/**
 * Decode a PHP session string back to an associative array.
 *
 * Tries `unserialize()` first (handles the `php_serialize` handler format),
 * then falls back to parsing the native `php` format (`key|serialized_value`
 * pairs). All `unserialize()` calls use `['allowed_classes' => ['stdClass']]`
 * — see the file-level security note above.
 *
 * @return array<string, mixed>
 */
function php_session_decode_to_array(string $data): array
{
    // php_serialize format is one serialized array; only try it when the data
    // can be one (saves a failing unserialize() per call on the php format).
    $decoded = str_starts_with($data, 'a:')
        ? @unserialize($data, ['allowed_classes' => ['stdClass']])
        : false;
    // ...and only when the WHOLE string is that one array. unserialize() stops
    // after the first value, so php-format data whose first KEY is
    // `a:1:{s:4:"role";s:5:"admin";}` (a key an app built from input, e.g.
    // `$_SESSION["viewed_$slug"]`) used to decode as ['role' => 'admin'], with
    // every real key gone.
    if (is_array($decoded) && serialize($decoded) !== $data) {
        $decoded = false;
    }
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
            // A value that does not unserialize means the data is corrupt
            // (truncated, edited). Native session_decode() gives up on the
            // whole session here; so do we. Skipping to the next `;` and
            // carrying on resumed parsing INSIDE the broken value, so a stored
            // string such as `x;role|b:1;` became a real `role` key.
            return [];
        }
        // The value's length is taken from its re-serialization; if the stored
        // bytes are not exactly that (a hand-edited or foreign encoding) the
        // next key would be read from the wrong offset — treat it as corrupt.
        $serialized = serialize($value);
        if (substr_compare($data, $serialized, $offset, strlen($serialized)) !== 0) {
            return [];
        }
        $offset += strlen($serialized);
        $result[$key] = $value;
    }
    return $result;
}

/**
 * Start a new session or resume an existing one.
 *
 * Coroutine-safe replacement for PHP's `session_start()`. Reads the session
 * ID from the `PHPSESSID` cookie (or generates one for new visitors),
 * loads session data from the file backend or a registered
 * `\SessionHandlerInterface`, and stores it in `$g->session`.
 *
 * In superglobals mode (`App::$superglobals = true`) the data is also
 * mirrored to `$_SESSION` (or bound via reference in coroutine-isolated
 * superglobals mode). Emits a `Set-Cookie` header for first-time visitors
 * when `App::$session_lifecycle` is `true` and the response is still
 * writable.
 *
 * The `ZEALPHP_SESSION_SECURE` env var overrides the `secure` cookie flag
 * (otherwise auto-detected from `X-Forwarded-Proto` / `HTTPS` / port `443`).
 */
/**
 * Resolve the active custom session handler, guaranteeing the
 * `SessionHandlerInterface` contract that `open()` precedes any
 * `read()`/`write()`/`destroy()` (#369). A freshly-resolved handler —
 * `session_regenerate_id()` / `session_write_close()` reached without a
 * prior `session_start()` in this request — was used un-`open()`ed, and
 * `FileSessionHandler`'s typed `$savePath` fataled on first `write()`
 * (breaking the textbook login fixation defence with an uncaught Error).
 * `open()` is invoked once per request (flagged in `session_params`).
 */
function zeal_session_opened_handler(): ?\SessionHandlerInterface
{
    $g = RequestContext::instance();
    $handler = $g->session_params['handler'] ?? \ZealPHP\App::resolveActiveSessionHandler();
    if (!($handler instanceof \SessionHandlerInterface)) {
        return null;
    }
    if (!isset($g->session_params['handler_opened'])) {
        $save_path = $g->session_params['save_path'] ?? \ZealPHP\App::sessionSavePath();
        $name = $g->session_params['name'] ?? 'PHPSESSID';
        $handler->open(is_string($save_path) ? $save_path : '', is_string($name) ? $name : 'PHPSESSID');
        $params = $g->session_params;
        $params['handler'] = $handler;
        $params['handler_opened'] = true;
        $g->session_params = $params;
    }
    return $handler;
}

/**
 * @internal Call the open handler's `close()` once, as native
 * session_write_close()/session_destroy() do, and mark it closed so a later
 * session_start() in the same request opens it again. Without it a handler
 * never got the chance to free per-request state (the Table handler's
 * per-coroutine read snapshot grew for the worker's lifetime).
 */
function zeal_session_close_handler(): void
{
    $g = RequestContext::instance();
    if (!isset($g->session_params['handler_opened'])) {
        return;
    }
    $handler = $g->session_params['handler'] ?? null;
    $params = $g->session_params;
    unset($params['handler_opened']);
    $g->session_params = $params;
    if ($handler instanceof \SessionHandlerInterface) {
        $handler->close();
    }
}

/**
 * Emit the session cookie for $id with the configured cookie params.
 *
 * @internal
 */
function zeal_session_emit_cookie(string $name, string $id): void
{
    $g = RequestContext::instance();
    if ($g->openswoole_response === null) {
        return;
    }
    $cookieParams = zeal_session_get_cookie_params();
    $g->openswoole_response->cookie(
        $name,
        $id,
        $cookieParams['lifetime'] ? time() + (int)$cookieParams['lifetime'] : 0,
        $cookieParams['path'],
        $cookieParams['domain'],
        $cookieParams['secure'],
        $cookieParams['httponly'],
        // SameSite (8th arg) — was computed ('Lax') but never emitted, so an
        // explicit None (iframe/OAuth/SSO) or Strict override was silently
        // dropped on the wire. The None⇒Secure invariant is enforced in
        // zeal_session_get_cookie_params().
        $cookieParams['samesite'] ?? 'Lax'
    );
}

function zeal_session_start(): bool
{
    $g = RequestContext::instance();

    // If session is already active AND we're using per-coroutine $g (Mode 4),
    // return immediately. Prevents the double-start where CoSessionManager
    // starts the session, then the file's session_start() re-reads from disk.
    // In sync mode (process-wide $g), SessionManager re-starts cleanly each
    // request via _session_started reset — the second call is harmless.
    // Already started this request: a second session_start() (the app's own,
    // after the manager's) is a no-op, like PHP's. Re-running it reloaded the
    // store over $g->session and dropped what the request had written since —
    // a CSRF token issued on this GET, a just-rotated id. Applies whenever the
    // session state is per-request: coroutine mode and coroutine-legacy.
    if ($g->_session_started
        && (\ZealPHP\App::$coroutine_isolated_superglobals || !\ZealPHP\App::$superglobals)) {
        return true;
    }

    // Ensure session parameters are initialized
    if (!isset($g->session_params['save_path'])) {
        // #373: honour App::sessionSavePath() — the App static IS the
        // documented knob (defaults to /var/lib/php/sessions, so unconfigured
        // behaviour is unchanged). Hardcoding the literal here silently
        // dropped the configured path for the inline file backend AND
        // FileSessionHandler.
        $g->session_params['save_path'] = \ZealPHP\App::sessionSavePath();
    }
    if (!isset($g->session_params['name'])) {
        $g->session_params['name'] = 'PHPSESSID';
    }
    if (!isset($g->session_params['cookie_params'])) {
        // Secure-cookie auto-detect routes through App::requestIsHttps(), which
        // only trusts X-Forwarded-Proto from a configured trusted proxy (parity
        // with App::clientIp()). Trusting the header from any client let an
        // attacker flip the Secure flag with one header (Secure cookie on a
        // plaintext listener → browser drops it → session resets every request).
        $isHttps = \ZealPHP\App::requestIsHttps($g->server);
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

    $handler = $g->session_params['handler'] ?? \ZealPHP\App::resolveActiveSessionHandler();

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

    // Get session ID from cookie or generate a new one.
    // Store in session_params so write_close can read it without going
    // through $g->cookie (which has auto-global caching issues in Mode 4).
    $session_id = zeal_session_id();
    $params = $g->session_params;
    $params['session_id'] = $session_id;
    $g->session_params = $params;

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
        zeal_session_emit_cookie($sessionNameForCookie, $session_id);
    }

    /** @var array<string, mixed> $session_data */
    $session_data = [];
    // Whether the id had a BACKING STORE ENTRY at load time — the canonical
    // session.use_strict_mode signal (a never-issued/foreign id has none; an
    // issued-but-empty session does). Consumed by the managers' strict-mode
    // check via zeal_session_strict_should_regenerate() (ext-zealphp#2).
    // File store: the file existing (even zero-length) means the id was
    // issued. Handler store: read() returning non-'' is the only signal a
    // \SessionHandlerInterface exposes — an issued-but-never-written handler
    // entry is indistinguishable from a missing one (documented limitation).
    $session_existed = false;

    if ($handler instanceof \SessionHandlerInterface) {
        /** @var string $sessionName */
        $sessionName = $g->session_params['name'];
        $handler->open($save_path, (string) $sessionName);
        // #369: record the open so later regenerate/write_close resolution
        // (zeal_session_opened_handler) doesn't re-open.
        $params = $g->session_params;
        $params['handler'] = $handler;
        $params['handler_opened'] = true;
        $g->session_params = $params;
        $contents = $handler->read((string) $session_id);
        if (is_string($contents) && $contents !== '') {
            $session_existed = true;
            $session_data = php_session_decode_to_array($contents);
        }
    } else {
        $session_file = $save_path . '/sess_' . basename((string)$session_id);
        if (file_exists($session_file)) {
            $session_existed = true;
            // Shared lock prevents reading a partially-written file
            // from a concurrent write_close on another coroutine.
            $fp = @fopen($session_file, 'r');
            if ($fp !== false) {
                flock($fp, LOCK_SH);
                $contents = stream_get_contents($fp);
                flock($fp, LOCK_UN);
                fclose($fp);
            } else {
                $contents = false;
            }
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
    // session.use_strict_mode where no session manager runs. When the CGI
    // subprocess owns sessions (App::cgiOwnsSessions()) the managers step
    // aside, so an in-process route's own session_start() is the only gate:
    // without this it took the client's PHPSESSID as-is, and wrote it, for an
    // id the store never issued (session fixation). Rotate like PHP does.
    if (!$session_existed
        && $hadIncomingSessionCookie
        && \ZealPHP\App::$session_strict_mode
        && \ZealPHP\App::cgiOwnsSessions()
        && $session_id === ($g->cookie[$sessionNameForCookie] ?? null)
        // Not an id this request minted itself (a second session_start()).
        && $session_id !== ($g->session_params['strict_minted'] ?? null)) {
        $fresh = session_create_id();
        if (is_string($fresh) && $fresh !== '') {
            $session_id = $fresh;
            $session_data = [];
            zeal_session_id($fresh);
            $params = $g->session_params;
            $params['session_id'] = $fresh;
            $params['strict_minted'] = $fresh;
            $g->session_params = $params;
            if ((bool) ini_get('session.use_cookies')
                && $g->openswoole_response !== null
                && $g->openswoole_response->isWritable()) {
                zeal_session_emit_cookie($sessionNameForCookie, $fresh);
            }
        }
    }

    // #374: framework bookkeeping no longer rides $_SESSION (it lives in
    // $g->memo) — strip the two legacy keys that pre-fix releases persisted
    // into user stores, so they don't survive via the loaded-keys merge.
    unset($session_data['__start_time'], $session_data['UNIQUE_REQUEST_ID']);
    /** @var array<string, mixed> $session_data */
    $g->session = $session_data;
    // #21: snapshot the keys present at load so write_close() can tell an
    // in-request unset() (must delete from store) from a concurrent add
    // (must survive the merge).
    $g->session_loaded_keys = array_keys($session_data);
    $g->session_loaded_digest = zeal_session_digest($session_data);
    // Record store-entry existence for the managers' strict-mode check
    // (ext-zealphp#2 — see the $session_existed comment above).
    $params = $g->session_params;
    $params['session_existed'] = $session_existed;
    $g->session_params = $params;
    if (\ZealPHP\App::$superglobals) {
        if (\ZealPHP\App::$coroutine_isolated_superglobals) {
            // Mode 4 (#379): populate the live $_SESSION and DETACH the typed
            // slot — the same slot-detach alias design every other superglobal
            // uses (the v0.2.30 populate path for $_GET/$_SERVER/…). The
            // previous `$_SESSION = &$g->session` reference binding was
            // SEVERED by ext-zealphp's per-coroutine superglobal restore on
            // the first post-bind yield (the restore ZVAL_COPYs a plain array
            // into the $GLOBALS['_SESSION'] bucket, replacing the
            // IS_REFERENCE wrapper): from then on user writes landed in
            // $GLOBALS['_SESSION'] while $g->session kept the stale load-time
            // copy — and write_close() persisted the stale copy, so every
            // session mutation after request 1 was silently lost (the
            // phpMyAdmin CSRF login loop / TinyFileManager post-yield write
            // loss / "file frozen at request 1" class). With the slot
            // detached, $g->session proxies the live $_SESSION via
            // __get/__set/__isset (truthful since v0.4.8), there is exactly
            // ONE store, and ext-zealphp's Stage-1 machinery isolates it per
            // coroutine like the other six superglobals.
            $GLOBALS['_SESSION'] = $session_data;
            unset($g->session);
        } else {
            $GLOBALS['_SESSION'] = $session_data;
        }
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
 * Whether a session id is safe to use in a filesystem path / store key.
 * Rejects the inputs that would let an attacker-chosen `PHPSESSID` escape the
 * session save directory: empty/oversized values, NUL bytes, path separators
 * (`/`, `\`), and parent-directory references (`..`). The character set is
 * otherwise left permissive so a legitimate custom/legacy session id is not
 * rejected — the `basename()` applied at every `sess_<id>` file sink is the
 * belt-and-suspenders traversal guard.
 */
function zeal_valid_session_id(string $id): bool
{
    if ($id === '' || strlen($id) > 256) {
        return false;
    }
    if (strpbrk($id, "/\\\0") !== false) {
        return false;
    }
    return strpos($id, '..') === false;
}

/**
 * `session.use_strict_mode` provenance decision (#244).
 *
 * `zeal_valid_session_id()` checks only the FORMAT of a client-supplied id; a
 * well-formed but server-never-issued id (a planted/fixated `PHPSESSID`) still
 * passes it. PHP's `session.use_strict_mode=1` rejects any id the server has no
 * record of by minting a fresh one. The session managers reproduce that here:
 * after `zeal_session_start()` has loaded the store for a CLIENT-SUPPLIED id,
 * an EMPTY result means the id is unrecognised (stale / foreign / never issued)
 * — so it must not be honoured, and a fresh server-generated id is issued in
 * its place. This is the single trust check both `CoSessionManager` and
 * `SessionManager` consult, kept here so it is unit-testable without driving a
 * full request through OpenSwoole.
 *
 * PHP's `session.use_strict_mode` rejects ids the server NEVER ISSUED. When
 * the caller can tell whether the id had a BACKING STORE ENTRY (file existed /
 * handler returned data), that is the canonical signal (ext-zealphp#2): an
 * issued-but-still-empty session — the cookie sent on a data-less first
 * visit, a redirect, any page that stores nothing — is a KNOWN id and must
 * NOT rotate. The old data-emptiness heuristic rotated those on EVERY
 * request, which (combined with the regenerate→write_close sid desync, fixed
 * alongside) cascaded into rotate-and-lose-every-write. The array heuristic
 * remains the fallback for callers that cannot determine store existence.
 *
 * @param bool   $strictMode      `App::$session_strict_mode`.
 * @param bool   $clientSupplied  Whether the active id came from the client
 *                                (cookie/query param) rather than being
 *                                server-minted.
 * @param array<array-key, mixed> $loadedSession  The session data the store
 *                                resolved for that id; its emptiness is the
 *                                FALLBACK signal when `$storeEntryExists`
 *                                is unknown.
 * @param bool|null $storeEntryExists  Whether the id had a backing store
 *                                entry at load time (`$g->session_params
 *                                ['session_existed']`); `null` = unknown.
 * @return bool  `true` when the id must be rotated to a fresh server id.
 */
function zeal_session_strict_should_regenerate(
    bool $strictMode,
    bool $clientSupplied,
    array $loadedSession,
    ?bool $storeEntryExists = null
): bool {
    if (!$strictMode || !$clientSupplied) {
        return false;
    }
    if ($storeEntryExists !== null) {
        return !$storeEntryExists;
    }
    return $loadedSession === [];
}

/**
 * Coroutine-mode override of `session_set_save_handler()` (#295).
 *
 * Native `session_set_save_handler()` registers with PHP's session module, which
 * the `zeal_session_*` overrides never consult — so a custom handler (Redis, etc.)
 * was silently ignored and sessions fell back to the inline file path. This routes
 * the handler to the slots the framework actually reads, in BOTH scopes:
 *
 *  - `App::$session_handler` (process-wide) so every future request and worker
 *    sees it — the common boot-time call shape; and
 *  - the current coroutine's `$g->session_params['handler']` (immediate) so a
 *    per-request middleware call takes effect for THIS request.
 *
 * Only the object-handler form is supported (PHP's modern signature). The legacy
 * 6-callable form and a non-handler argument are rejected (return `false`), as the
 * `zeal_session_*` core requires a `\SessionHandlerInterface`.
 *
 * @param mixed $handler            A `\SessionHandlerInterface` instance.
 * @param bool  $register_shutdown  Accepted for signature parity; unused (the
 *                                  managers own the write-close lifecycle).
 * @return bool  `true` when wired, `false` for an unsupported argument.
 */
function zeal_session_set_save_handler($handler = null, $register_shutdown = true): bool
{
    if (!($handler instanceof \SessionHandlerInterface)) {
        return false;
    }
    // Process-wide: future requests/workers resolve it via App::resolveActiveSessionHandler().
    \ZealPHP\App::sessionHandler($handler);
    // Per-coroutine: this request sees it immediately, even before the manager runs.
    $g = RequestContext::instance();
    $g->session_params['handler'] = $handler;
    return true;
}

/**
 * Get or set the session ID.
 *
 * With no argument: reads the `PHPSESSID` (or custom session name) cookie
 * from `$g->cookie`. When no cookie is present a fresh ID is generated via
 * `session_create_id()` and stashed in `$g->cookie`. A malformed inbound ID
 * (path traversal, NUL, oversized) is silently replaced with a fresh one.
 *
 * NOTE: this function only validates id FORMAT. The `session.use_strict_mode`
 * PROVENANCE check (rotating a well-formed but never-issued id that loads an
 * empty session — #244) lives in the session managers, which call
 * `zeal_session_strict_should_regenerate()` after the store has been read.
 *
 * @param string|null $id  Pass a string to set the session ID explicitly.
 * @return string|false  The current (or newly-set) session ID.
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
            $sid = (string)$g->cookie[$session_name];
            // SECURITY: a forged/malformed PHPSESSID (path traversal, NUL,
            // oversized) must never reach the `sess_<id>` file path. Reject it
            // and mint a fresh id instead of trusting the attacker's value
            // (PHP `session.use_strict_mode` behaviour for malformed ids).
            if (!zeal_valid_session_id($sid)) {
                $sid = session_create_id();
                $g->cookie[$session_name] = $sid;
            }
            return $sid;
        } else {
            $new_id = session_create_id();
            $g->cookie[$session_name] = $new_id;
            return $new_id;
        }
    } else {
        // Set session ID — same validation as the cookie path.
        if (!zeal_valid_session_id($id)) {
            $id = session_create_id();
        }
        $g->cookie[$session_name] = $id;
        return $id;
    }
}

/**
 * Return the current session status (`PHP_SESSION_ACTIVE` or `PHP_SESSION_NONE`).
 *
 * Mode-aware: in superglobals mode inspects `$GLOBALS['_SESSION']`; in
 * coroutine mode checks whether the typed `$g->session` slot is initialised
 * (it is `unset()` by `zeal_session_write_close()` / `zeal_session_destroy()`
 * to mark a session inactive).
 */
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
 * Get or set the session name (the cookie name, e.g. `'PHPSESSID'`).
 *
 * Stored per-request in `$g->session_params['name']`.
 *
 * @param string|null $name  Pass `null` to read the current value.
 * @return string  The current (or newly-set) session name.
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
 * @internal md5 of each value's serialization — the load-time snapshot
 * zeal_session_merge_onto_store() diffs against (`$g->session_loaded_digest`).
 *
 * @param array<array-key, mixed> $data
 * @return array<string, string>
 */
function zeal_session_digest(array $data): array
{
    $digest = [];
    foreach ($data as $k => $v) {
        $digest[(string) $k] = md5(serialize($v));
    }
    return $digest;
}

/**
 * @internal Apply this request's changes to what the store holds NOW.
 *
 * Only keys whose value differs from the load-time snapshot are written, and
 * only keys this request loaded and then unset are removed. Everything else
 * comes from `$existing`, so a key a concurrent request added, changed or
 * removed — and this request never touched — keeps the concurrent state. The
 * old merge (`array_merge($existing, $current)`) wrote back every loaded key,
 * reverting the other request's change and resurrecting the key it removed.
 * The merge is still top-level (shallow): two requests changing the same key
 * are last-write-wins.
 *
 * @param array<array-key, mixed> $existing  Store contents read just before the write.
 * @param array<array-key, mixed> $current   This request's session.
 * @param array<string, string>   $loaded    `$g->session_loaded_digest`.
 * @param list<string>            $loadedKeys `$g->session_loaded_keys`.
 * @return array<string, mixed>
 */
function zeal_session_merge_onto_store(array $existing, array $current, array $loaded, array $loadedKeys): array
{
    /** @var array<string, mixed> $merged session keys are strings */
    $merged = $existing;
    foreach ($current as $k => $v) {
        $key = (string) $k;
        if (!isset($loaded[$key]) || $loaded[$key] !== md5(serialize($v))) {
            $merged[$key] = $v;
        }
    }
    foreach ($loadedKeys as $k) {
        if (!array_key_exists($k, $current)) {
            unset($merged[$k]);
        }
    }
    return $merged;
}

/**
 * Write session data to the backing store and mark the session inactive.
 *
 * Coroutine-safe replacement for PHP's `session_write_close()`. Performs a
 * read-merge-write under `flock(LOCK_EX)` (file backend) or an optimistic
 * `WATCH`/`MULTI`/`EXEC` retry loop (custom `\SessionHandlerInterface`) to
 * guard against concurrent-coroutine last-write-wins data loss on the same
 * session ID. The merge is shallow — top-level keys added by a concurrent
 * request survive, but conflicting nested arrays are last-write-wins.
 *
 * After writing, `$g->session` (coroutine mode) or `$GLOBALS['_SESSION']`
 * (superglobals mode) is `unset()` so `zeal_session_status()` returns
 * `PHP_SESSION_NONE` for the remainder of the request.
 */
function zeal_session_write_close(): bool
{
    return zeal_session_store(true);
}

/**
 * Persist the active session to its store WITHOUT closing it — called by the
 * response layer just before the response's last byte is sent.
 *
 * The managers used to write the session in their `finally`, i.e. after
 * `end()`. A client that issues its next request as soon as the response
 * arrives (a redirect after login/logout, an OAuth state set before the
 * provider bounce) could then read the store before the write landed, so a
 * logout was not yet visible and an OAuth/CSRF state could be lost. Saving
 * here closes that window; the manager's close afterwards skips the write
 * when nothing changed since, and writes again when the app kept mutating
 * the session after the response went out. No-op when no session is active.
 */
function zeal_session_persist_before_send(): void
{
    $g = RequestContext::instance();
    if (!($g->_session_started ?? false)) {
        return;
    }
    try {
        zeal_session_store(false);
    } catch (\Throwable $e) {
        // Same contract as the managers: a failed write fails this request's
        // session, never the response. The manager's close retries it.
        $g->_session_persisted_hash = null;
        \ZealPHP\elog('Session write failed: ' . $e->getMessage(), 'error');
    }
}

/**
 * @internal Shared body of zeal_session_write_close() ($close = true) and
 * zeal_session_persist_before_send() ($close = false).
 */
function zeal_session_store(bool $close): bool
{
    $g = RequestContext::instance();

    // In superglobals mode the canonical session store is $_SESSION (the
    // declared typed property $g->session shadows the __get/__set proxy,
    // so Symfony/legacy code that writes through $_SESSION never reaches
    // $g->session). Persist whichever holds the live data for the mode.
    // In Mode 4 (coroutine_isolated_superglobals), $g->session is the
    // canonical store — $_SESSION is bound via reference, but $GLOBALS['_SESSION']
    // may not follow the reference correctly across function scopes.
    // #379: in EVERY superglobals mode (incl. Mode 4) the canonical store is
    // the live $GLOBALS['_SESSION'] — zeal_session_start() detaches the
    // $g->session slot so it proxies $_SESSION; only pure coroutine mode
    // (superglobals off) keeps $g->session as the canonical typed store. The
    // old "Mode 4 reads $g->session" choice persisted the STALE load-time
    // copy once the ext's per-coroutine restore severed the reference binding
    // (every post-request-1 session mutation silently lost).
    $useGSession = !\ZealPHP\App::$superglobals;
    $superglobals = \ZealPHP\App::$superglobals;
    $hasSession = $useGSession
        /** @phpstan-ignore-next-line isset.property — runtime tests uninitialized typed slot */
        ? isset($g->session)
        : (isset($GLOBALS['_SESSION']) && is_array($GLOBALS['_SESSION']));

    if ($hasSession) {
        // Read SID from session_params (set by zeal_session_start) — not
        // zeal_session_id() which reads $g->cookie and suffers from
        // auto-global caching in coroutine mode.
        /** @var string $session_id */
        $session_id = isset($g->session_params['session_id']) && is_string($g->session_params['session_id'])
            ? $g->session_params['session_id']
            : zeal_session_id();
        $save_path = $g->session_params['save_path'] ?? '';
        assert(is_string($save_path));
        $session_file = $save_path . '/sess_' . basename((string)$session_id);
        $data = $useGSession ? $g->session : $GLOBALS['_SESSION'];
        // The id is part of the fingerprint: a session_regenerate_id() after the
        // early save must still be written under the new id.
        $hash = md5($session_file . "\0" . serialize($data));
        $unchanged = $g->_session_persisted_hash === $hash;
        $wHandler = $unchanged ? null : zeal_session_opened_handler();   // #369: guarantees open() ran
        if ($unchanged) {
            // Already persisted before the response was sent, and the app has
            // not touched the session since — nothing to write.
        } elseif ($wHandler instanceof \SessionHandlerInterface) {
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
            // Optimistic-locking retry loop. RedisSessionHandler uses
            // WATCH/MULTI: read() WATCHes the key, write() MULTI+EXEC.
            // If another coroutine modified the key between our read and
            // write, EXEC returns false → write() returns false → we
            // re-read, re-merge, and retry. Max 3 attempts; on exhaustion
            // fall through to a plain write (last-writer-wins, same as
            // the pre-locking behavior — safe degradation).
            //
            // A session this request loaded with data that is no longer in the
            // store was destroyed (logout) or rotated by another request since
            // our read: writing would bring it back, so the write is dropped.
            // A handler only exposes "no data" for a missing entry, so a
            // session another request emptied completely is treated the same.
            $current = is_array($data) ? $data : [];
            $mustExist = $g->session_loaded_digest !== [];
            $maxRetries = 3;
            for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
                $existing = $wHandler->read((string) $session_id);
                if (!is_string($existing) || $existing === '') {
                    if ($mustExist) {
                        $data = null;
                        break;
                    }
                    $data = $current;
                } else {
                    // An entry that does not decode is corrupt: replace it.
                    $existingData = php_session_decode_to_array($existing);
                    $data = $existingData === [] ? $current : zeal_session_merge_onto_store(
                        $existingData,
                        $current,
                        $g->session_loaded_digest,
                        $g->session_loaded_keys
                    );
                }
                /** @var array<string, mixed> $data */
                $written = $wHandler->write(
                    (string) $session_id,
                    php_session_encode_from_array($data)
                );
                if ($written !== false) {
                    break;
                }
                // WATCH/MULTI conflict — retry with fresh read
            }
        } else {
            // File-based sessions: flock(LOCK_EX) serializes concurrent
            // writes to the same session file (Apache mod_session parity).
            // Without locking, two coroutines writing the same session race:
            // the loser's changes are silently lost. The lock is held briefly
            // (encode + write + flush) — negligible performance impact.
            //
            // Read-merge-write under the lock: re-read the file's current
            // state (another coroutine may have written since our read at
            // session_start), merge with our changes, write back.
            //
            // A session this request loaded with data must still have its file:
            // if another request destroyed it (or rotated the id) since our
            // read, `c+` would recreate it with our stale copy — a logout undone
            // by any request that was in flight. Open without creating instead.
            // A destroy that unlinks the file while we hold it only leaves our
            // write on the unlinked inode.
            /** @var array<string, mixed> $data */
            $current = $data;
            $mustExist = $g->session_loaded_digest !== [];
            $fp = @fopen($session_file, $mustExist ? 'r+' : 'c+');
            if ($fp !== false && !$mustExist) {
                @chmod($session_file, 0600);   // native PHP's mode; 'c+' honours the umask (0644)
            }
            if ($fp !== false) {
                flock($fp, LOCK_EX);
                try {
                    $diskContents = stream_get_contents($fp);
                    $diskData = is_string($diskContents) && $diskContents !== ''
                        ? php_session_decode_to_array($diskContents)
                        : [];
                    if ($mustExist && $diskData === []) {
                        // This request loaded data, and the store now holds none
                        // (another request emptied it — a logout — or it is
                        // unreadable). Writing our load-time copy back would undo
                        // that, as the handler branch already refuses to.
                        $data = null;
                    } else {
                        if ($diskData !== []) {
                            $data = zeal_session_merge_onto_store(
                                $diskData,
                                $current,
                                $g->session_loaded_digest,
                                $g->session_loaded_keys
                            );
                        }
                        // Encode BEFORE truncating: an encoder failure (or an
                        // error handler that throws) must not leave the session
                        // file emptied.
                        $encoded = php_session_encode_from_array($data);
                        ftruncate($fp, 0);
                        rewind($fp);
                        fwrite($fp, $encoded);
                        fflush($fp);
                    }
                } finally {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                }
            } elseif ($mustExist) {
                $data = null;
            } else {
                file_put_contents($session_file, php_session_encode_from_array($data));
                @chmod($session_file, 0600);
            }
        }

        if (!$close) {
            // Keep the session open. Remember what was stored, and treat every
            // key that is now in the store as loaded (the store is the new
            // snapshot), so a key the app unsets after this point is deleted by
            // the final close instead of being resurrected from the store by
            // the merge, and only later changes are written by it.
            if (is_array($data)) {
                $g->_session_persisted_hash = $hash;
                $g->session_loaded_keys = array_values(array_unique(array_merge(
                    $g->session_loaded_keys,
                    array_map('strval', array_keys($data))
                )));
                $g->session_loaded_digest = zeal_session_digest($data);
            }
            return true;
        }
        $g->_session_persisted_hash = null;
        // Native session_write_close() ends with the handler's close(): it is
        // where a handler frees what it holds for this request (#M6 — the
        // Table handler's per-coroutine read snapshot was never released).
        zeal_session_close_handler();

        // Mark the session closed so a RE-ENTRANT close is a true no-op (#2).
        // The framework runs a double session lifecycle: a handler may call
        // session_write_close() directly AND the manager's `finally` calls it
        // again (gated on `_session_started`). Without resetting the flag here,
        // that second close re-runs the read-merge-write against a store that the
        // mark-inactive below just emptied — and its deletion loop (which removes
        // every `session_loaded_keys` entry absent from the now-empty store) wipes
        // the whole session file on every other request, so data alternates
        // present/absent (the #2 1,2,1,2 symptom). Resetting the flag makes the
        // manager's second close short-circuit; resetting the loaded-keys snapshot
        // guarantees no stale deletion loop can fire even if a close still re-enters.
        $g->_session_started = false;
        $g->session_loaded_keys = [];
        $g->session_loaded_digest = [];

        // Clear the canonical store so zeal_session_status() reports
        // PHP_SESSION_NONE for the remainder of the request. #379: every
        // superglobals mode (Mixed AND Mode 4) now stores in the live
        // $GLOBALS['_SESSION'] (the start path detaches the $g->session slot),
        // so both take this branch; in Mode 4 ext-zealphp's request-end
        // superglobals_clear() re-parks it anyway — this just makes the
        // inactive signal correct for code running between close and teardown.
        // Pure coroutine mode keeps the typed-slot unset.
        if ($superglobals && !$useGSession) {
            $GLOBALS['_SESSION'] = [];
            unset($GLOBALS['_SESSION']);
        } elseif (!$superglobals) {
            unset($g->session);
        }
    }
    return true;
}

/**
 * Destroy the session and delete its backing storage.
 *
 * Deletes the session file (or calls `\SessionHandlerInterface::destroy()` for
 * custom backends), removes `$g->session` and `$_SESSION`, and clears the
 * session cookie from `$g->cookie`. Sets `$g->_session_started` to `false`.
 */
function zeal_session_destroy(): bool
{
    $g = RequestContext::instance();

    // Get session ID
    $session_id = zeal_session_id();

    // Delete session data via handler or file
    $save_path = $g->session_params['save_path'] ?? '';
    assert(is_string($save_path));
    $dHandler = $g->session_params['handler'] ?? \ZealPHP\App::resolveActiveSessionHandler();
    if ($dHandler instanceof \SessionHandlerInterface) {
        $dHandler->destroy((string) $session_id);
        zeal_session_close_handler();
    } else {
        $session_file = $save_path . '/sess_' . basename((string)$session_id);
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
    $g->session_loaded_keys = [];
    $g->session_loaded_digest = [];
    $g->_session_persisted_hash = null;

    return true;
}

/**
 * Unset all session variables without destroying the session.
 *
 * Resets `$g->session` to `[]` and, in superglobals mode, also clears
 * `$GLOBALS['_SESSION']`. The session file / backend entry is NOT deleted —
 * call `zeal_session_destroy()` to remove it entirely.
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
 * Regenerate the session ID, optionally deleting the old session.
 *
 * Coroutine-safe replacement for PHP's `session_regenerate_id()`. Copies the
 * current in-memory session data to the new ID via the active backend (custom
 * `\SessionHandlerInterface` or file), then emits a fresh `Set-Cookie` header
 * so the client switches to the new ID. Gated by `App::$session_lifecycle`
 * and `session.use_cookies` — same guards as `zeal_session_start()`.
 *
 * @param bool $delete_old_session  When `true`, the old session file/entry is deleted.
 */
function zeal_session_regenerate_id($delete_old_session = false): bool
{
    $g = RequestContext::instance();

    // Get old session ID
    $old_session_id = zeal_session_id();

    // Generate new session ID
    $new_session_id = bin2hex(random_bytes(32));
    zeal_session_id($new_session_id);
    // ext-zealphp#2 root cause — keep write_close's canonical sid slot in
    // sync. zeal_session_write_close() deliberately reads the sid from
    // session_params (NOT zeal_session_id(), which suffers auto-global
    // caching in Mode 4); without this sync every post-regenerate session
    // write landed in the OLD (deleted) id's store — login flows lost their
    // session, and under strict mode the next request loaded empty and
    // rotated AGAIN (the rotate-and-lose-every-write cascade).
    $params = $g->session_params;
    $params['session_id'] = $new_session_id;
    $g->session_params = $params;

    $save_path = $g->session_params['save_path'] ?? '';
    assert(is_string($save_path));

    // Issue #19: a custom SessionHandlerInterface (Redis/Valkey, etc.) keeps
    // session data in the handler, NOT on disk — so the file rename below is a
    // no-op for it. Without migrating the data the regenerated ID points at an
    // empty session, and anything written afterwards (OAuth `sub`/`tokens`/
    // `profile`) is stranded under an ID the client may never receive.
    // mod_php's regenerate copies the current data to the new ID; mirror that.
    $handler = zeal_session_opened_handler();   // #369: guarantees open() ran
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
        $old_session_file = $save_path . '/sess_' . basename((string)$old_session_id);
        $new_session_file = $save_path . '/sess_' . basename((string)$new_session_id);
        if (file_exists($old_session_file)) {
            if ($delete_old_session) {
                unlink($old_session_file);
            } else {
                rename($old_session_file, $new_session_file);
            }
        }
    }

    // The entry under the new id was written from this request's data (or is
    // still to be created, for a file deleted above), not loaded: the close
    // writes the whole session to it rather than diffing against the old id's
    // load-time snapshot — and must create it rather than treat it as
    // destroyed.
    $g->session_loaded_digest = [];

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
            $cookieParams['httponly'],
            // SameSite (8th arg) — was computed ('Lax') but never emitted, so an
            // explicit None (iframe/OAuth/SSO) or Strict override was silently
            // dropped on the wire. The None⇒Secure invariant is enforced in
            // zeal_session_get_cookie_params().
            $cookieParams['samesite'] ?? 'Lax'
        );
    }

    return true;
}

/**
 * Get the current session cookie parameters.
 *
 * Returns the array stored in `$g->session_params['cookie_params']`, falling
 * back to safe defaults (`path='/'`, `httponly=true`, `samesite='Lax'`).
 *
 * @return array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite?: string}
 */
function zeal_session_get_cookie_params(): array
{
    $g = RequestContext::instance();
    $params = $g->session_params['cookie_params'] ?? null;
    if (is_array($params)) {
        $samesite = isset($params['samesite']) && is_string($params['samesite'])
            ? $params['samesite']
            : 'Lax';
        return [
            'lifetime' => isset($params['lifetime']) && is_int($params['lifetime']) ? $params['lifetime'] : 0,
            'path'     => isset($params['path']) && is_string($params['path']) ? $params['path'] : '/',
            'domain'   => isset($params['domain']) && is_string($params['domain']) ? $params['domain'] : '',
            // SameSite=None is rejected by browsers unless Secure is also set —
            // enforce the invariant so an explicit None doesn't silently fail.
            'secure'   => !empty($params['secure']) || strcasecmp($samesite, 'None') === 0,
            'httponly' => array_key_exists('httponly', $params) ? (bool) $params['httponly'] : true,
            'samesite' => $samesite,
        ];
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
 * Set session cookie parameters.
 *
 * Accepts either the positional-args form (PHP < 8.0 style) or an options
 * array (PHP 8.0+ `session_set_cookie_params(array $options)` style). Both
 * are merged over the defaults `['lifetime'=>0, 'path'=>'/', 'domain'=>'',
 * 'secure'=>false, 'httponly'=>false, 'samesite'=>'Lax']`.
 *
 * @param int|array<string, mixed> $lifetime_or_options  Integer lifetime in seconds, or an options array.
 * @param string $path
 * @param string $domain
 * @param bool   $secure
 * @param bool   $httponly
 */
function zeal_session_set_cookie_params($lifetime_or_options, $path = null, $domain = null, $secure = null, $httponly = null): void
{
    $g = RequestContext::instance();
    // #375: PHP 8 semantics — omitted (null) positional params mean "keep the
    // current value". The old non-null defaults ($secure=false,
    // $httponly=false) made a bare session_set_cookie_params(3600) silently
    // strip HttpOnly/Secure from the session cookie. The array form likewise
    // merges over the CURRENT params (native: keys absent from the array keep
    // their configured value), not over hardcoded defaults.
    $current = $g->session_params['cookie_params'] ?? null;
    $base = array_merge([
        'lifetime' => 0, 'path' => '/', 'domain' => '',
        'secure' => false, 'httponly' => false, 'samesite' => 'Lax',
    ], is_array($current) ? $current : []);
    if (is_array($lifetime_or_options)) {
        $g->session_params['cookie_params'] = array_merge($base, $lifetime_or_options);
    } else {
        $base['lifetime'] = $lifetime_or_options;
        if ($path !== null)     { $base['path'] = $path; }
        if ($domain !== null)   { $base['domain'] = $domain; }
        if ($secure !== null)   { $base['secure'] = $secure; }
        if ($httponly !== null) { $base['httponly'] = $httponly; }
        $g->session_params['cookie_params'] = $base;
    }
}

/**
 * Get or set the session cache limiter (e.g. `'nocache'`, `'public'`, `'private'`).
 *
 * Stored per-request in `$g->cache_limiter`. Defaults to `'nocache'`.
 *
 * @param string|null $cache_limiter  Pass `null` to read the current value.
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

/**
 * Alias for `zeal_session_write_close()` — write session data and close.
 *
 * Mirrors PHP's `session_commit()`, which is itself an alias of
 * `session_write_close()`.
 */
function zeal_session_commit(): bool
{
    return zeal_session_write_close();
}

/**
 * Get or set the session cache expiry in minutes.
 *
 * Stored per-request in `$g->cache_expire`. Defaults to `180` when not set.
 *
 * @param int|null $cache_expire  Pass `null` to read the current value.
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

/**
 * Discard in-memory session changes and reload session data from the file.
 *
 * Mirrors PHP's `session_abort()`: any writes to `$g->session` or
 * `$_SESSION` since the last `session_start()` are thrown away, and the
 * session data is re-read from the session file on disk. Returns `true` always.
 */
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
        $session_file = $save_path . '/sess_' . basename((string)$session_id);
        if (file_exists($session_file)) {
            /** @var array<string, mixed> $session_data */
            $session_data = [];
            $contents = @file_get_contents($session_file);
            if (is_string($contents) && $contents !== '') {
                // #372: session files are written in the native `php`
                // serialize-handler format (`key|serialized;…`) — raw
                // unserialize() can't parse it, returned false, and the
                // "restored" session became [] which the manager's close then
                // PERSISTED, wiping the store. Use the session decoder.
                $session_data = php_session_decode_to_array($contents);
            }
            $g->session = $session_data;
            // #379 superglobals modes: the canonical store is the live
            // $GLOBALS['_SESSION'] — restore through it too.
            if (\ZealPHP\App::$superglobals) {
                $GLOBALS['_SESSION'] = $session_data;
            }
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

/**
 * Encode the current session data to PHP's `php` serialize format string.
 *
 * Reads from `$GLOBALS['_SESSION']` in superglobals mode, or from
 * `RequestContext::instance()->session` in coroutine mode.
 */
function zeal_session_encode(): string
{
    $data = \ZealPHP\App::$superglobals
        ? ($GLOBALS['_SESSION'] ?? [])
        : RequestContext::instance()->session;
    /** @var array<string, mixed> $narrowed */
    $narrowed = is_array($data) ? $data : [];
    return php_session_encode_from_array($narrowed);
}

/**
 * Decode a session data string and populate the active session.
 *
 * Mirrors PHP's `session_decode()`: parses `$data` via
 * `php_session_decode_to_array()` and stores the result in both `$g->session`
 * and `$GLOBALS['_SESSION']` (in superglobals mode). Returns `false` when
 * `$data` is empty or decodes to an empty array.
 */
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
 * Create a new session ID, optionally with a `$prefix`.
 *
 * Thin wrapper around PHP's `session_create_id()`.
 *
 * @param string $prefix  Optional prefix prepended to the generated ID.
 * @return string|false  The new session ID, or `false` on failure.
 */
function zeal_session_create_id($prefix = '')
{
    return session_create_id($prefix);
}

/**
 * Get or set the session save path.
 *
 * Stored per-request in `$g->session_params['save_path']`. Defaults to
 * `'/var/lib/php/sessions'` when not set.
 *
 * @param string|null $path  Pass `null` to read the current value.
 * @return string  The current (or newly-set) save path.
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
 * Get or set the session module name (e.g. `'files'`, `'redis'`).
 *
 * Stored per-request in `$g->session_module_name`. Defaults to `'files'`
 * when not set. This is a ZealPHP-internal tracking field; the actual
 * backend is determined by the registered `\SessionHandlerInterface`.
 *
 * @param string|null $module  Pass `null` to read the current value.
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

/**
 * Garbage-collect expired sessions for the active storage.
 *
 * ZealPHP replaced PHP's probabilistic per-request GC (`session.gc_probability`)
 * with deterministic, explicit collection — but nothing called it, so on a
 * long-lived worker `sess_*` files (default storage) accumulated until inodes
 * exhausted, and a leaked/abandoned `PHPSESSID` stayed replayable forever.
 * `App::run()` now schedules this on a worker-0 timer (see
 * `App::registerSessionGc()`).
 *
 * A registered `SessionHandlerInterface` owns its own GC (Redis/Table handlers
 * already expire rows server-side); the default inline file path sweeps
 * `sess_*` files whose mtime is older than `$maxlifetime` seconds.
 *
 * @param int $maxlifetime Seconds of inactivity after which a session expires.
 * @return int Number of file entries removed (handler backends return their
 *             own count, or 0).
 */
function zeal_session_gc(int $maxlifetime): int
{
    $maxlifetime = max(1, $maxlifetime);
    $g = RequestContext::instance();

    // This runs from a worker timer, i.e. outside any request: there are no
    // session_params, so the configured App::sessionSavePath() is the path.
    // Falling back to the literal default swept the system session dir (other
    // PHP apps' sessions, with their own lifetimes) and never expired sessions
    // kept under a custom path.
    $rawPath = $g->session_params['save_path'] ?? \ZealPHP\App::sessionSavePath();
    $savePath = is_string($rawPath) ? $rawPath : '';

    // open() first, as the SessionHandlerInterface contract requires:
    // FileSessionHandler only learns its directory there, and gc() before it
    // threw an Error that killed worker 0. Not recorded in session_params —
    // this is not a request's session.
    $handler = $g->session_params['handler'] ?? \ZealPHP\App::resolveActiveSessionHandler();
    if ($handler instanceof \SessionHandlerInterface) {
        $name = $g->session_params['name'] ?? 'PHPSESSID';
        $handler->open($savePath, is_string($name) ? $name : 'PHPSESSID');
        $res = $handler->gc($maxlifetime);
        return is_int($res) ? $res : 0;
    }

    if ($savePath === '' || !is_dir($savePath)) {
        return 0;
    }

    $removed = 0;
    $cutoff = time() - $maxlifetime;
    foreach (glob($savePath . '/sess_*') ?: [] as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $cutoff && @unlink($file)) {
            $removed++;
        }
    }
    return $removed;
}
