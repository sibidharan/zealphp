<?php
/**
 * $g compat shim for this scaffold.
 *
 * This is a copy of ZealPHP's canonical dual-runtime shim, which lives at
 * `compat/g.php` in the framework package. The canonical file carries the
 * full explanation of why the shim can't be a framework feature and how the
 * two runtimes resolve `$g`. See:
 *
 *   - vendor/zealphp/zealphp/compat/g.php   (the canonical source)
 *   - https://php.zeal.ninja/legacy-apps#dual-runtime
 *
 * Apps that have ZealPHP in vendor/ on both their Apache and ZealPHP
 * deployments can `require_once` the canonical copy directly instead of
 * keeping their own:
 *
 *   require_once __DIR__ . '/../vendor/zealphp/zealphp/compat/g.php';
 *
 * This scaffold keeps a local copy so it works standalone (before
 * `composer install`).
 */

if (class_exists('\ZealPHP\RequestContext', false)) {
    // $GLOBALS is shared by every request the worker serves (concurrently, in
    // coroutine mode). Never store one request's context there: that handed
    // request 1's $g (session, cookies, input) to every later request. The
    // proxy resolves the current request's context on each access, and
    // survives require_once (which runs this file once per worker).
    if (!($GLOBALS['g'] ?? null) instanceof \ZealPHP\Compat\CurrentRequestContext) {
        $GLOBALS['g'] = new \ZealPHP\Compat\CurrentRequestContext();
    }
    $g = \ZealPHP\RequestContext::instance();
} else {
    if (!isset($GLOBALS['g'])) {
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
    $g = $GLOBALS['g'];
}
