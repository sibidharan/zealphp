<?php

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * Issue #164 regression — `superglobals(false)` + `sessionLifecycle(false)`.
 *
 * In coroutine mode CoSessionManager leaves the typed `array $session` slot
 * UNSET (it `unset()`s it / never assigns it in that coroutine). The v0.3.6
 * access-log path reads `$g->session['username']` (the `%u` token); a by-ref
 * `__get` return of `null` for an unset typed-`array` property is type-checked
 * by PHP and TypeErrors — 500-ing every request even though the body rendered.
 *
 * `RequestContext::__get()` now hands back `[]` (array-compatible) for the unset
 * array-typed superglobal keys WITHOUT initializing the slot, so the value is
 * safe to subscript AND `isset($g->session)` stays false (the session-state
 * detection in Session/utils.php relies on that distinction).
 */
class AccessLogSessionTest extends TestCase
{
    public function testUnsetSessionSlotReadsAsEmptyArrayNotNull(): void
    {
        $orig = App::$superglobals;
        $g = RequestContext::instance();
        try {
            App::superglobals(false);
            unset($g->session);
            $this->assertFalse(isset($g->session), 'precondition: session slot is unset');

            $val = $g->session;                       // must NOT throw TypeError
            $this->assertIsArray($val);
            $this->assertSame([], $val);
            $this->assertNull($g->session['username'] ?? null);

            // Reading must NOT re-initialize the slot — Session/utils.php uses
            // isset($g->session) as the "session active" signal.
            $this->assertFalse(isset($g->session), 'read must not re-init the slot');
        } finally {
            $g->session = [];               // restore the singleton for later tests
            App::$superglobals = $orig;
        }
    }

    public function testAccessLogLineDoesNotThrowWhenSessionUnset(): void
    {
        $orig = App::$superglobals;
        $g = RequestContext::instance();
        try {
            App::superglobals(false);
            unset($g->session);                       // sessionLifecycle(false) state

            // The %u token reads $g->session['username']; the whole line build
            // must not TypeError (issue #164 was a 500 on every request here).
            $line = App::formatAccessLogLine(200, 0, 0.001);
            $this->assertIsString($line);
            // %u with no session and no REMOTE_USER renders '-' per CLF.
            $this->assertStringContainsString('-', $line);
        } finally {
            $g->session = [];
            App::$superglobals = $orig;
        }
    }
}
