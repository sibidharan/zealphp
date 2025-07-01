<?php
namespace ZealPHP\Session;

use ZealPHP\G;

/**
 * Start or resume a session and populate session data from file storage.
 *
 * Ensures session parameters are initialized, reads existing session file,
 * and restores data into the global session container.
 *
 * @return bool True on success.
 */
function zeal_session_start(): bool
{
    $g = G::instance();

    // Ensure session parameters are initialized
    if (!isset($g->session_params['save_path'])) {
        $g->session_params['save_path'] = '/var/lib/php/sessions';
    }
    if (!isset($g->session_params['name'])) {
        $g->session_params['name'] = 'PHPSESSID';
    }
    if (!isset($g->session_params['cookie_params'])) {
        $g->session_params['cookie_params'] = [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => false,
        ];
    }

    // Ensure session save path exists
    if (!is_dir($g->session_params['save_path'])) {
        mkdir($g->session_params['save_path'], 0777, true);
    }

    // Get session ID from cookie or generate a new one
    $session_id = zeal_session_id();

    // Read session data from file
    $session_data = [];
    $session_file = $g->session_params['save_path'] . '/sess_' . $session_id;
    if (file_exists($session_file)) {
        $session_data = unserialize(file_get_contents($session_file));
    }

    // Populate $g->session
    $g->session = $session_data;

    return true;
}


/**
 * Get or set the session ID
 */
/**
 * Get or set the current session ID in the session container.
 *
 * When called without an argument, returns the existing session ID or generates a new one.
 * When an ID is provided, sets it as the current session ID.
 *
 * @param string|null $id Optional session ID to set.
 * @return string The current session ID.
 */
function zeal_session_id($id = null): string
{
    $g = G::instance();

    if (!isset($g->session_params['name'])) {
        $g->session_params['name'] = 'PHPSESSID';
    }

    $session_name = $g->session_params['name'];

    if ($id === null) {
        // Get session ID from cookie or generate new one
        if (isset($g->cookie[$session_name])) {
            return $g->cookie[$session_name];
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

/**
 * Get the current session status.
 *
 * @return int One of PHP_SESSION_NONE or PHP_SESSION_ACTIVE.
 */
function zeal_session_status(): int
    $g = G::instance();
    if(isset($g->session)){
        return PHP_SESSION_ACTIVE;
    }else{
        return PHP_SESSION_NONE;
    }
}


/**
 * Get or set the session name
 */
/**
 * Get or set the session name.
 *
 * @param string|null $name Optional name to set for the session.
 * @return string The current session name.
 */
function zeal_session_name($name = null): string
{
    $g = G::instance();

    if ($name === null) {
        return $g->session_params['name'] ?? 'PHPSESSID';
    } else {
        $g->session_params['name'] = $name;
        return $name;
    }
}

/**
 * Write session data to storage and close the session.
 *
 * Serializes the current session data to file and clears it from memory.
 *
 * @return bool True on success.
 */
function zeal_session_write_close(): bool
{
    $g = G::instance();

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
 * Destroy the current session: remove data file and unset session variables and cookie.
 *
 * @return bool True on success.
 */
function zeal_session_destroy(): bool
{
    $g = G::instance();

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
/**
 * Unset all session variables in the current session.
 *
 * @return void
 */
function zeal_session_unset(): void
{
    $g = G::instance();
    $g->session = [];
}

/**
 * Regenerate session ID
 */
/**
 * Generate a new session ID and optionally delete the old session file.
 *
 * @param bool $delete_old_session If true, removes the old session file.
 * @return bool True on success.
 */
function zeal_session_regenerate_id($delete_old_session = false): bool
{
    $g = G::instance();

    // Get old session ID
    $old_session_id = zeal_session_id();

    // Generate new session ID
    $new_session_id = uniqid('', true);
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
 */
/**
 * Retrieve the cookie parameters for the session.
 *
 * @return array Associative array with keys: lifetime, path, domain, secure, httponly.
 */
function zeal_session_get_cookie_params(): array
{
    $g = G::instance();
    return $g->session_params['cookie_params'] ?? [
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => false,
    ];
}

/**
 * Set session cookie parameters
 */
/**
 * Set the cookie parameters for the session.
 *
 * @param int    $lifetime  Lifetime of the cookie in seconds.
 * @param string $path      Path on the server in which the cookie will be available.
 * @param string $domain    Cookie domain.
 * @param bool   $secure    Whether the cookie should only be transmitted over HTTPS.
 * @param bool   $httponly  Whether the cookie is accessible only through HTTP protocol.
 * @return void
 */
function zeal_session_set_cookie_params($lifetime, $path = '/', $domain = '', $secure = false, $httponly = false): void
{
    $g = G::instance();
    $g->session_params['cookie_params'] = compact('lifetime', 'path', 'domain', 'secure', 'httponly');
}

/**
 * Get or set the session cache limiter.
 *
 * @param string|null $cache_limiter Optional new cache limiter value.
 * @return string The current or new cache limiter.
 */
function zeal_session_cache_limiter($cache_limiter = null): string
{
    $g = G::instance();

    if ($cache_limiter === null) {
        return $g->cache_limiter ?? 'nocache';
    } else {
        $g->cache_limiter = $cache_limiter;
        return $cache_limiter;
    }
}

/**
 * Commit session data and end session (alias for write and close).
 *
 * @return bool True on success.
 */
function zeal_session_commit(): bool
{
    return zeal_session_write_close();
}

/**
 * Get or set the session cache expiration time.
 *
 * @param int|null $cache_expire New cache expiration in minutes.
 * @return int The current or new cache expiration in minutes.
 */
function zeal_session_cache_expire($cache_expire = null): int
{
    $g = G::instance();

    if ($cache_expire === null) {
        return $g->cache_expire ?? 180;
    } else {
        $g->cache_expire = $cache_expire;
        return $cache_expire;
    }
}

/**
 * Abort session changes and restore data from storage.
 *
 * Discards any changes to session variables and reloads original data.
 *
 * @return bool True on success.
 */
function zeal_session_abort(): bool
{
    $g = G::instance();

    // Discard session changes
    if (isset($g->session)) {
        // Get session ID
        $session_id = zeal_session_id();

        // Read session data from file
        $session_file = $g->session_params['save_path'] . '/sess_' . $session_id;
        if (file_exists($session_file)) {
            $session_data = unserialize(file_get_contents($session_file));
            $g->session = $session_data;
        } else {
            unset($g->session);
        }
    }

    return true;
}

/**
 * Serialize the current session data to a string.
 *
 * @return string Serialized session data.
 */
function zeal_session_encode(): string
{
    return serialize(G::instance()->session);
}

/**
 * Unserialize session data from a string into the current session.
 *
 * @param string $data Serialized session data.
 * @return void
 */
function zeal_session_decode($data): void
{
    G::instance()->session = unserialize($data);
}

/**
 * Generate a new session ID with an optional prefix.
 *
 * @param string $prefix Optional prefix for the session ID.
 * @return string The generated session ID.
 */
function zeal_session_create_id($prefix = ''): string
{
    return session_create_id($prefix);
}

/**
 * Get or set the session save path.
 *
 * @param string|null $path Optional new path to store session files.
 * @return string The current or new session save path.
 */
function zeal_session_save_path($path = null): string
{
    $g = G::instance();

    if ($path === null) {
        return $g->session_params['save_path'] ?? '/var/lib/php/sessions';
    } else {
        $g->session_params['save_path'] = $path;
        return $path;
    }
}

/**
 * Get or set the session storage module name.
 *
 * @param string|null $module Optional module name (e.g., 'files').
 * @return string The current or new session module name.
 */
function zeal_session_module_name($module = null): string
{
    $g = G::instance();

    if ($module === null) {
        return $g->session_module_name ?? 'files';
    } else {
        $g->session_module_name = $module;
        return $module;
    }
}
