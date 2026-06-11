<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

use function ZealPHP\Session\zeal_session_start;
use function ZealPHP\Session\zeal_session_write_close;
use function ZealPHP\Session\zeal_session_id;

/**
 * Tests for session behaviour that depends on lifecycle mode flags introduced
 * in PR #150: App::$coroutine_isolated_superglobals, the early-return guard
 * in zeal_session_start(), session_id stored in session_params, and
 * zeal_session_write_close() reading from session_params['session_id'].
 *
 * All tests are pure unit tests — no running server required.
 *
 * Coverage targets:
 *   - zeal_session_start() early-return when _session_started=true AND
 *     coroutine_isolated_superglobals=true  (the double-start guard).
 *   - zeal_session_start() runs full logic when _session_started=true but
 *     coroutine_isolated_superglobals=false  (sync mode: second call is OK).
 *   - zeal_session_start() always runs when _session_started=false.
 *   - zeal_session_start() stores session_id in session_params['session_id'].
 *   - zeal_session_write_close() reads session_params['session_id'] when set.
 *   - zeal_session_write_close() falls back to zeal_session_id() when
 *     session_params['session_id'] is absent.
 *   - zeal_session_write_close() uses $g->session in cis=true mode.
 *
 * SessionManager._session_started reset is verified via SessionManager's
 * __invoke() source — checked structurally (same guard pattern as
 * CoSessionManagerTest for the constructor).
 */
final class SessionLifecycleModeTest extends TestCase
{
    private string $savePath;
    /** @var bool|null */
    private ?bool $savedSuperglobals = null;
    private bool $savedCis = false;
    private bool $savedSessionLifecycle = true;
    /** @var mixed */
    private mixed $savedSuperSession = null;

    protected function setUp(): void
    {
        App::$cwd = dirname(__DIR__, 2);

        $this->savedSuperglobals    = App::$superglobals;
        $this->savedCis             = App::$coroutine_isolated_superglobals;
        $this->savedSessionLifecycle = App::$session_lifecycle;
        $this->savedSuperSession    = $GLOBALS['_SESSION'] ?? null;

        // Default test mode: superglobals=true, cis=false (sync mode).
        // Individual tests override as needed.
        App::superglobals(true);
        App::$coroutine_isolated_superglobals = false;
        App::$session_lifecycle = true;

        $this->savePath = sys_get_temp_dir() . '/zealphp_sess_lc_' . bin2hex(random_bytes(6));
        @mkdir($this->savePath, 0700, true);

        $g = RequestContext::instance();

        // Re-initialize the declared typed `session` slot in coroutine mode
        // (same guard pattern as SessionUtilsCoverageTest).
        App::superglobals(false);
        $g->session = [];
        App::superglobals(true);

        $g->session_params   = ['save_path' => $this->savePath, 'name' => 'PHPSESSID'];
        $g->cookie           = [];
        $g->server           = [];
        $g->openswoole_response = null;
        $g->_session_started = false;
        $GLOBALS['_SESSION'] = [];
    }

    protected function tearDown(): void
    {
        $g = RequestContext::instance();

        App::superglobals(false);
        $g->session = [];
        App::superglobals(true);

        $g->session_params   = [];
        $g->cookie           = [];
        $g->server           = [];
        $g->openswoole_response = null;
        $g->_session_started = false;

        if ($this->savedSuperSession === null) {
            unset($GLOBALS['_SESSION']);
        } else {
            $GLOBALS['_SESSION'] = $this->savedSuperSession;
        }

        App::$coroutine_isolated_superglobals = $this->savedCis;
        App::$session_lifecycle               = $this->savedSessionLifecycle;
        if ($this->savedSuperglobals !== null) {
            App::superglobals($this->savedSuperglobals);
        }

        if (is_dir($this->savePath)) {
            foreach (glob($this->savePath . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->savePath);
        }

        parent::tearDown();
    }

    // ── Early-return guard (double-start protection) ──────────────────────

    /**
     * When _session_started=true AND coroutine_isolated_superglobals=true,
     * a second call to zeal_session_start() must return true immediately
     * without re-reading from disk or re-setting $g->session.
     */
    public function testStartEarlyReturnWhenAlreadyStartedAndCisTrue(): void
    {
        App::superglobals(true);
        App::$coroutine_isolated_superglobals = true;

        $g = RequestContext::instance();
        $g->_session_started = true;

        // Poison the session with a sentinel value before the second call.
        // If start() re-ran, it would reset session to [] from the empty file.
        App::superglobals(false);
        $g->session = ['sentinel' => 'preserved'];
        App::superglobals(true);

        $result = zeal_session_start();

        $this->assertTrue($result, 'early-return path still returns true');
        // Session must not have been reset — early return skipped the disk read.
        App::superglobals(false);
        /** @phpstan-ignore-next-line isset.property */
        $sessionValue = isset($g->session) ? ($g->session['sentinel'] ?? 'missing') : 'missing';
        App::superglobals(true);
        $this->assertSame('preserved', $sessionValue,
            'session data must be unchanged when early-return fired');
    }

    /**
     * When _session_started=true but coroutine_isolated_superglobals=false
     * (sync mode), zeal_session_start() must run the full logic (no early return).
     * We verify this by checking that session_params['session_id'] is populated,
     * which only happens in the full code path.
     */
    public function testStartNoEarlyReturnWhenAlreadyStartedAndCisFalse(): void
    {
        App::superglobals(true);
        App::$coroutine_isolated_superglobals = false;

        $g = RequestContext::instance();
        $g->_session_started = true;
        unset($g->session_params['session_id']); // ensure it's absent before the call

        $result = zeal_session_start();

        $this->assertTrue($result);
        // Full path was taken — session_id was stored in session_params.
        $this->assertArrayHasKey('session_id', $g->session_params,
            'full session_start path must store session_id in session_params');
    }

    /**
     * When _session_started=false, start() always runs the full logic
     * regardless of coroutine_isolated_superglobals value.
     */
    public function testStartRunsFullLogicWhenNotYetStarted(): void
    {
        App::superglobals(true);
        App::$coroutine_isolated_superglobals = false;

        $g = RequestContext::instance();
        $g->_session_started = null;
        unset($g->session_params['session_id']);

        $result = zeal_session_start();

        $this->assertTrue($result);
        $this->assertArrayHasKey('session_id', $g->session_params);
        $this->assertTrue($g->_session_started,
            '_session_started must be true after successful start');
    }

    public function testStartRunsFullLogicWhenNotYetStartedAndCisTrue(): void
    {
        App::superglobals(true);
        App::$coroutine_isolated_superglobals = true;

        $g = RequestContext::instance();
        $g->_session_started = null;
        unset($g->session_params['session_id']);

        $result = zeal_session_start();

        $this->assertTrue($result);
        $this->assertArrayHasKey('session_id', $g->session_params);
        $this->assertTrue($g->_session_started);
    }

    // ── session_id stored in session_params ───────────────────────────────

    public function testStartStoresSessionIdInSessionParams(): void
    {
        $g = RequestContext::instance();
        $g->cookie = ['PHPSESSID' => 'test-sid-abc'];

        zeal_session_start();

        $this->assertArrayHasKey('session_id', $g->session_params,
            'zeal_session_start must store session_id in session_params');
        $this->assertSame('test-sid-abc', $g->session_params['session_id']);
    }

    public function testStartStoresGeneratedSessionIdWhenNoCookie(): void
    {
        $g = RequestContext::instance();
        $g->cookie = []; // no incoming cookie → new ID generated

        zeal_session_start();

        $this->assertArrayHasKey('session_id', $g->session_params);
        $stored = $g->session_params['session_id'];
        $this->assertIsString($stored);
        $this->assertNotEmpty($stored);
    }

    // ── zeal_session_write_close() reads session_params['session_id'] ──────

    public function testWriteCloseReadsSessionIdFromSessionParams(): void
    {
        $g = RequestContext::instance();
        $g->session_params['session_id'] = 'wc-sid-from-params';
        $g->session_params['save_path']  = $this->savePath;

        // Initialize session data via coroutine-mode write so it's present.
        App::superglobals(false);
        $g->session = ['key' => 'value'];
        App::superglobals(true);
        // In cis=false superglobals mode, write_close reads $GLOBALS['_SESSION'].
        $GLOBALS['_SESSION'] = ['key' => 'value'];

        $result = zeal_session_write_close();

        $this->assertTrue($result);
        $expectedFile = $this->savePath . '/sess_wc-sid-from-params';
        $this->assertFileExists($expectedFile,
            'write_close must use session_params[session_id] as the file name');
    }

    public function testWriteCloseUsesLiveGlobalsWhenCisTrue(): void
    {
        // #379 contract INVERSION: cis=true (Mode 4) now persists the LIVE
        // $GLOBALS['_SESSION'] — zeal_session_start() detaches the
        // $g->session slot so the global IS the one store. The previous
        // expectation here ("typed slot wins") pinned exactly the bug: once
        // ext-zealphp's per-coroutine restore severed the
        // `$_SESSION = &$g->session` binding, the slot held a stale load-time
        // copy and every post-request-1 session mutation was silently lost
        // (the phpMyAdmin login-loop / TinyFileManager lost-login class).
        App::superglobals(true);
        App::$coroutine_isolated_superglobals = true;

        $g = RequestContext::instance();
        $g->session_params['session_id'] = 'wc-cis-sid';
        $g->session_params['save_path']  = $this->savePath;

        // A stale typed-slot copy (the severed-reference shape) …
        App::superglobals(false);
        $g->session = ['stale_key' => 'stale_value'];
        App::superglobals(true);

        // … and the LIVE store holding the request's real mutations.
        $GLOBALS['_SESSION'] = ['live_key' => 'live_value'];

        $result = zeal_session_write_close();
        $this->assertTrue($result);

        $sessionFile = $this->savePath . '/sess_wc-cis-sid';
        $this->assertFileExists($sessionFile);
        $contents = (string) file_get_contents($sessionFile);
        $this->assertStringContainsString('live_key', $contents,
            '#379: write_close must persist the LIVE $GLOBALS[_SESSION] when cis=true');
        $this->assertStringNotContainsString('stale_key', $contents,
            '#379: a stale typed-slot copy must NOT shadow the live store');
    }

    public function testWriteCloseFallsBackToZealSessionIdWhenNoSessionParamId(): void
    {
        $g = RequestContext::instance();
        // Explicitly absent from session_params.
        unset($g->session_params['session_id']);
        $g->cookie = ['PHPSESSID' => 'fallback-cookie-sid'];
        $g->session_params['save_path'] = $this->savePath;
        $GLOBALS['_SESSION'] = ['fb_key' => 'fb_value'];

        $result = zeal_session_write_close();
        $this->assertTrue($result);

        $expectedFile = $this->savePath . '/sess_fallback-cookie-sid';
        $this->assertFileExists($expectedFile,
            'write_close falls back to zeal_session_id() when session_params[session_id] absent');
    }

    // ── SessionManager._session_started reset ────────────────────────────

    /**
     * Verify structurally that SessionManager.__invoke() resets _session_started
     * to false at the start of each request invocation. We check the source
     * directly (same approach as CoSessionManagerTest for contract pinning).
     */
    public function testSessionManagerResetsSessionStartedFlagAtRequestStart(): void
    {
        $rm = new \ReflectionMethod(\ZealPHP\Session\SessionManager::class, '__invoke');
        $file  = (string) $rm->getFileName();
        $start = (int) $rm->getStartLine();
        $end   = (int) $rm->getEndLine();

        $lines = array_slice(file($file) ?: [], $start - 1, $end - $start + 1);
        $body  = implode('', $lines);

        $this->assertStringContainsString('_session_started', $body,
            'SessionManager::__invoke must reference _session_started');
        // The reset to false must be present.
        $this->assertMatchesRegularExpression(
            '/_session_started\s*=\s*false/',
            $body,
            'SessionManager::__invoke must reset _session_started to false'
        );
        // The reset must appear BEFORE the main request dispatch. We use the
        // LAST call_user_func in the method body — that's the main middleware
        // dispatch (not the early bench-mode path). The reset at the start of
        // each request must come before the main dispatch call.
        $resetPos       = (int) strpos($body, '_session_started         = false');
        $lastDispatch   = (int) strrpos($body, 'call_user_func($this->middleware');
        $this->assertGreaterThan(0, $resetPos,
            '_session_started = false line must exist in __invoke');
        $this->assertGreaterThan(0, $lastDispatch,
            'main call_user_func($this->middleware) dispatch must exist');
        $this->assertLessThan($lastDispatch, $resetPos,
            '_session_started must be reset BEFORE the main middleware dispatch');
    }

    // ── CoSessionManager cgiOwnsSessions check ───────────────────────────

    /**
     * cgiOwnsSessions() returns true when sg=true AND processIsolation()=true.
     * Both SessionManager and CoSessionManager gate $manageSession on this.
     * Verify the condition logic via App::cgiOwnsSessions() directly.
     */
    public function testCgiOwnsSessionsTrueWhenSgTrueAndPiTrue(): void
    {
        App::$run_has_started = false;
        App::superglobals(true);
        App::processIsolation(true);

        $this->assertTrue(App::cgiOwnsSessions(),
            'cgiOwnsSessions() must return true when sg=T and pi=T');
    }

    public function testCgiOwnsSessionsFalseWhenPiFalse(): void
    {
        App::$run_has_started = false;
        App::superglobals(true);
        App::processIsolation(false);

        $this->assertFalse(App::cgiOwnsSessions(),
            'cgiOwnsSessions() must return false when pi=false (mixed-mode)');
    }

    public function testCgiOwnsSessionsFalseWhenSgFalse(): void
    {
        App::$run_has_started = false;
        App::superglobals(false);
        App::processIsolation(null); // follows sg=false → pi=false

        $this->assertFalse(App::cgiOwnsSessions(),
            'cgiOwnsSessions() must return false when sg=false (coroutine mode)');
    }

    /**
     * CoSessionManager.__invoke sets $manageSession = false when
     * cgiOwnsSessions() returns true. Verify the source includes both guards.
     */
    public function testCoSessionManagerGatesManageSessionOnCgiOwnsSessions(): void
    {
        $rm = new \ReflectionMethod(\ZealPHP\Session\CoSessionManager::class, '__invoke');
        $file  = (string) $rm->getFileName();
        $start = (int) $rm->getStartLine();
        $end   = (int) $rm->getEndLine();

        $lines = array_slice(file($file) ?: [], $start - 1, $end - $start + 1);
        $body  = implode('', $lines);

        $this->assertStringContainsString('cgiOwnsSessions', $body,
            'CoSessionManager::__invoke must check cgiOwnsSessions()');
        $this->assertStringContainsString('session_lifecycle', $body,
            'CoSessionManager::__invoke must check session_lifecycle');
        // $manageSession is false when EITHER lifecycle flag is off OR cgi owns sessions.
        $this->assertStringContainsString('manageSession', $body,
            'CoSessionManager::__invoke must have a $manageSession variable');
    }
}
