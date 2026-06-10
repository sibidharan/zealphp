<?php
/**
 * ZealPHP dual-runtime $g compat shim — CANONICAL VERSION.
 *
 * Lets ONE source tree run in two runtimes from the same files:
 *
 *   1. ZealPHP (coroutine OR superglobals mode) — `$g` is the framework's
 *      per-request RequestContext.
 *   2. Apache / nginx + mod_php / PHP-FPM, with NO ZealPHP loaded at all —
 *      `$g` is a plain object whose properties are references to PHP's real
 *      superglobals.
 *
 * After including this file, application code uses `$g->get`, `$g->post`,
 * `$g->server`, `$g->cookie`, `$g->files`, `$g->request`, `$g->session`
 * EVERYWHERE — never `$_GET` / `$_SESSION` directly. That single accessor
 * resolves correctly in both runtimes.
 *
 * ── Why this is NOT a framework feature (and can't be) ──
 *
 * The whole job of the Apache branch below is to run *when ZealPHP is not
 * loaded*. Under Apache + mod_php there is no OpenSwoole, no Composer
 * autoloader bootstrapped, no `ZealPHP\` namespace — so nothing in the
 * framework's `src/` (PSR-4, autoloaded) can possibly execute. The bridge
 * therefore HAS to be a standalone, dependency-free file the app includes
 * unconditionally. ZealPHP ships this canonical copy so dual-runtime apps
 * don't hand-roll (and drift) their own; but it is included by the app, not
 * loaded by the framework.
 *
 * ── Two ways to use it ──
 *
 *   A. Copy this file into your project (e.g. bootstrap/g.php) and
 *      `require_once __DIR__ . '/bootstrap/g.php';` at the top of each entry
 *      point. Version it with your app.
 *
 *   B. If ZealPHP is in your vendor/ on BOTH deployments (same source tree
 *      served by Apache and ZealPHP — the SNA Labs pattern), require this
 *      copy directly. A plain file include works without the autoloader:
 *        require_once __DIR__ . '/vendor/zealphp/zealphp/compat/g.php';
 *
 * ── Which runtime gets which $g ──
 *
 *   ZealPHP loaded  → `\ZealPHP\RequestContext::instance()`. In coroutine
 *                     mode this is the per-coroutine context (the ONLY safe
 *                     accessor — superglobals are intentionally empty there).
 *                     In superglobals(true) mode it bridges to $_GET/$_SESSION.
 *   ZealPHP absent  → plain stdClass with &$_GET, &$_SESSION, ... references,
 *                     so reads AND writes flow through PHP's live superglobals
 *                     exactly as native Apache code expects.
 *
 * Why references (`&`) and not copies: legacy code mutates `$_SESSION` after
 * `session_start()` (`$_SESSION['user'] = ...`). The reference makes that
 * write visible through `$g->session` too — they're the same array.
 *
 * NOTE: keep the key list below in sync with the request-data properties on
 * `\ZealPHP\RequestContext`. The test `tests/Unit/CompatShimDriftTest.php`
 * fails if they diverge.
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
