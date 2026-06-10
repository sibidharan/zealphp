<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * ext-zealphp#42 (residual) — `isset($g->server)` must be truthful when the
 * slot is an unset-and-proxied superglobal alias (coroutine-legacy + the #346
 * Apache bridge). Without `__isset`, PHP reports `isset($g->server) === false`
 * even though reads through the `__get` proxy return the fully-populated
 * `$_SERVER` — so app-level defensive code like
 *
 *     if (!isset($g->server)) { $g->server = []; }
 *
 * "repairs" a healthy request by assigning through `__set`, which lands on
 * `$GLOBALS['_SERVER']` and WIPES the live request state for the remainder of
 * the request (observed in the wild: labs-dashboard-web `logit()` emptied
 * `$_SERVER` between dispatch and render on every page).
 *
 * Contract pinned here:
 *  - superglobals mode: `isset($g->X)` mirrors `isset($_X)` for the seven
 *    proxied names (Apache parity — notably `isset($g->session)` stays FALSE
 *    before session_start), and `isset($GLOBALS[$key])` for legacy custom keys.
 *  - coroutine mode: an unset typed slot / undeclared key reports FALSE
 *    (zeal_session_status()'s inactive-session detection depends on it).
 *  - `unset($g->X)` is symmetric with `__set` (drops the superglobal alias in
 *    superglobals mode, no-ops in coroutine mode).
 *
 * Tested on DETACHED instances (reflection-constructed, the private bridge
 * helper invoked directly) so the suite's real singleton is never touched.
 */
class RequestContextIssetTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedServer = [];
    /** @var array<string, mixed> */
    private array $savedGet = [];
    private bool $savedSuperglobals = false;
    private bool $hadSession = false;
    /** @var mixed */
    private $savedSession = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedServer = $_SERVER;
        $this->savedGet = $_GET;
        $this->savedSuperglobals = App::$superglobals;
        $this->hadSession = array_key_exists('_SESSION', $GLOBALS);
        $this->savedSession = $GLOBALS['_SESSION'] ?? null;
        App::superglobals(true);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_GET = $this->savedGet;
        App::superglobals($this->savedSuperglobals);
        if ($this->hadSession) {
            $GLOBALS['_SESSION'] = $this->savedSession;
        } else {
            unset($GLOBALS['_SESSION']);
        }
        unset($GLOBALS['zp42_custom']);
        parent::tearDown();
    }

    private function makeDetached(bool $bridged): RequestContext
    {
        $rc = new \ReflectionClass(RequestContext::class);
        $instance = $rc->newInstanceWithoutConstructor();
        if ($bridged) {
            $m = $rc->getMethod('bridgeSuperglobalSlots');
            $m->invoke(null, $instance);
        }
        return $instance;
    }

    public function testIssetServerIsTrueOnBridgedInstance(): void
    {
        $_SERVER['ZP42_KEY'] = 'present';
        $g = $this->makeDetached(true);
        $this->assertTrue(isset($g->server),
            '#42: isset($g->server) must be TRUE when the proxied $_SERVER is populated');
        $this->assertTrue(isset($g->get),
            '#42: isset($g->get) must be TRUE — $_GET always exists in a request');
    }

    public function testIssetSessionStaysFalseBeforeSessionStart(): void
    {
        unset($GLOBALS['_SESSION']);
        $g = $this->makeDetached(true);
        $this->assertFalse(isset($g->session),
            'Apache parity: isset($g->session) must stay FALSE before session_start()');
        $GLOBALS['_SESSION'] = ['user' => 'x'];
        $this->assertTrue(isset($g->session),
            'isset($g->session) must flip TRUE once $_SESSION exists');
    }

    public function testEmptyServerIsFalseWhenPopulated(): void
    {
        $_SERVER['ZP42_KEY'] = 'present';
        $g = $this->makeDetached(true);
        $this->assertFalse(empty($g->server),
            'empty($g->server) must be FALSE when $_SERVER is non-empty (empty() rides __isset)');
    }

    public function testDefensiveGuardNoLongerWipesServer(): void
    {
        // The exact in-the-wild pattern (labs-dashboard-web logit()): a
        // null-safety guard that "synthesizes" $g->server when isset() lies.
        // Pre-fix this assigned [] through __set → $GLOBALS['_SERVER'] = []
        // → every later read in the request saw an empty $_SERVER.
        $_SERVER = ['HTTP_HOST' => 'zeal.example', 'DOCUMENT_ROOT' => '/srv/app'];
        $g = $this->makeDetached(true);

        if (!isset($g->server) || (!is_array($g->server) && !is_object($g->server))) {
            $g->server = [];
        }

        $this->assertSame('zeal.example', $_SERVER['HTTP_HOST'] ?? null,
            '#42 regression pin: the defensive guard must NOT wipe the live $_SERVER');
        $this->assertSame('/srv/app', $g->server['DOCUMENT_ROOT'] ?? null);
    }

    public function testIssetCustomKeyMirrorsGlobals(): void
    {
        $g = $this->makeDetached(true);
        $this->assertFalse(isset($g->zp42_custom),
            'legacy custom key: isset is FALSE before any write');
        $g->zp42_custom = 'v';
        $this->assertTrue(isset($g->zp42_custom),
            'legacy custom key: isset mirrors $GLOBALS after a __set write');
    }

    public function testUnsetOnProxiedSlotDoesNotDropTheSuperglobal(): void
    {
        // The framework uses `unset($g->server)` as "detach the slot" —
        // bridgeSuperglobalSlots(), the per-request populate, the session
        // managers. __unset only fires when the slot is ALREADY detached, so
        // it must NOT escalate to unset($GLOBALS['_SERVER']) and wipe live
        // request state on bridge re-entry / request 2+ on a reused context.
        $_SERVER['ZP42_KEY'] = 'present';
        $g = $this->makeDetached(true);
        unset($g->server);
        $this->assertSame('present', $_SERVER['ZP42_KEY'] ?? null,
            'unset($g->server) on a detached slot must leave $_SERVER intact');
        $this->assertTrue(isset($g->server),
            'the proxy stays live — the alias cannot be detached from user code');
        // Legacy CUSTOM keys keep __set symmetry.
        $g->zp42_custom = 'v';
        unset($g->zp42_custom);
        $this->assertFalse(isset($GLOBALS['zp42_custom']),
            'unset($g->custom) drops the legacy $GLOBALS entry (symmetric with __set)');
    }

    public function testBridgeReEntryLeavesSuperglobalsIntact(): void
    {
        // Regression pin for the __unset escalation hazard: re-running the
        // bridge on an already-bridged instance re-issues unset() on every
        // detached slot — with naive symmetric __unset semantics that nuked
        // $_GET/$_ENV/$_SERVER process-wide (caught by RequestInputTest's
        // $_ENV probe going null).
        $_SERVER['ZP42_KEY'] = 'present';
        $_GET['zp42_q'] = 'q';
        $g = $this->makeDetached(true);
        $rc = new \ReflectionClass(RequestContext::class);
        $m = $rc->getMethod('bridgeSuperglobalSlots');
        $m->invoke(null, $g); // second bridge — slots already detached
        $this->assertSame('present', $_SERVER['ZP42_KEY'] ?? null);
        $this->assertSame('q', $_GET['zp42_q'] ?? null);
        $this->assertIsArray($GLOBALS['_ENV'] ?? null,
            'a re-bridge must never unset the real $_ENV');
    }

    public function testCoroutineModeUnsetSlotReportsFalse(): void
    {
        App::superglobals(false);
        $g = $this->makeDetached(true); // bridge unsets the typed slots
        $this->assertFalse(isset($g->session),
            'coroutine mode: an unset typed slot must report FALSE — '
            . 'zeal_session_status() uses this as the inactive-session signal');
        $this->assertFalse(isset($g->zp42_undeclared),
            'coroutine mode: an undeclared key reports FALSE');
    }

    public function testCoroutineModeUnsetIsANoOp(): void
    {
        App::superglobals(false);
        $g = $this->makeDetached(true);
        unset($g->session, $g->zp42_undeclared); // must not throw
        $this->assertFalse(isset($g->session));
    }
}
