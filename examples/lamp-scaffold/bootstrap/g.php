<?php
/**
 * $g compat shim — runs on Apache (mod_php) AND ZealPHP unchanged.
 *
 * Drop this file into your project and `require_once` it at the top of every
 * public/*.php. After that, use `$g->get`, `$g->post`, `$g->session`, etc. —
 * never `$_GET`, `$_POST`, `$_SESSION` directly. Your code now works on both
 * servers from a single source tree.
 *
 * What this file does:
 *
 *   - On ZealPHP: returns `\ZealPHP\RequestContext::instance()`, which the
 *     framework populates per request from the OpenSwoole HTTP request.
 *     `$g->get` is a DECLARED property — `$_GET` is never involved.
 *
 *   - On Apache (no ZealPHP loaded): creates a plain object with REFERENCES
 *     to PHP's real superglobals (`&$_GET`, `&$_SESSION`, etc.). Reads and
 *     writes still go through the live PHP arrays — same semantics as Apache
 *     code that uses `$_GET` directly.
 *
 * Why references and not copies: legacy code often mutates `$_SESSION` after
 * `session_start()` (e.g., `$_SESSION['user'] = ...`). The reference makes
 * that mutation visible through `$g->session` too.
 *
 * Why this can't ship as part of the framework: on Apache there IS no ZealPHP
 * — vendor/ doesn't get autoloaded by mod_php. The shim has to be a single
 * standalone file the app can include unconditionally.
 *
 * This file has no dependencies. Copy it, version it with your app.
 */

if (!isset($GLOBALS['g'])) {
    if (class_exists('\ZealPHP\RequestContext', false)) {
        $GLOBALS['g'] = \ZealPHP\RequestContext::instance();
    } else {
        $GLOBALS['g'] = (object) [
            'get'     => &$_GET,
            'post'    => &$_POST,
            'server'  => &$_SERVER,
            'files'   => &$_FILES,
            'request' => &$_REQUEST,
            'cookie'  => &$_COOKIE,
            'session' => &$_SESSION,
        ];
    }
}

$g = $GLOBALS['g'];
