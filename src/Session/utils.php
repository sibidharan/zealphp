<?php

namespace ZealPHP\Session;

use ZealPHP\G;

/**
 * Start a new session or resume existing one
 */
function zeal_session_start()
{
    $g = G::getInstance();

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
function zeal_session_id($id = null)
{
    $g = G::getInstance();

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
 * Get or set the session name
 */
function zeal_session_name($name = null)
{
    $g = G::getInstance();

    if ($name === null) {
        return $g->session_params['name'] ?? 'PHPSESSID';
    } else {
        $g->session_params['name'] = $name;
        return $name;
    }
}

/**
 * Write session data and close the session
 */
function zeal_session_write_close()
{
    $g = G::getInstance();

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
function zeal_session_destroy()
{
    $g = G::getInstance();

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
function zeal_session_unset()
{
    $g = G::getInstance();
    $g->session = [];
}

/**
 * Regenerate session ID
 */
function zeal_session_regenerate_id($delete_old_session = false)
{
    $g = G::getInstance();

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
function zeal_session_get_cookie_params()
{
    $g = G::getInstance();
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
function zeal_session_set_cookie_params($lifetime, $path = '/', $domain = '', $secure = false, $httponly = false)
{
    $g = G::getInstance();
    $g->session_params['cookie_params'] = compact('lifetime', 'path', 'domain', 'secure', 'httponly');
}