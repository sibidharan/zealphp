<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Coroutine as Co;
use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * Extra branch coverage for RequestContext::__get / __set / instance() that
 * RequestContextInvariantsTest.php and RequestContextOnceTest.php do not reach:
 *
 *   - superglobals(true): the superglobal-key proxy mapping ($g->session ↔
 *     $GLOBALS['_SESSION']) through BOTH __get and __set, the auto-init-to-[]
 *     branch, and the legacy $GLOBALS[$key] read for non-superglobal keys.
 *   - superglobals(false): the unset()-then-read path (returns ref to null),
 *     the missing-key path, the declared-but-unset re-init via __set, and the
 *     undeclared-write BadMethodCallException.
 *   - instance(): the per-coroutine context branch (cid >= 0) vs the
 *     process-wide singleton (cid < 0), plus the static get()/set() helpers.
 *
 * Each test fully owns + restores App::$superglobals and any $GLOBALS / slot
 * mutation in a finally so the ordering-sensitive full suite leaks nothing.
 */
class RequestContextExtraTest extends TestCase
{
    private bool $originalMode;

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        $this->originalMode = App::$superglobals;
    }

    protected function tearDown(): void
    {
        App::$superglobals = $this->originalMode;
        parent::tearDown();
    }

    // ── superglobals(true): superglobal-key proxy mapping ─────────────

    public function testSuperglobalGetMapsToGlobalsAndAutoInits(): void
    {
        App::$superglobals = true;
        $g = RequestContext::instance();

        // The superglobal-key proxy in __get/__set only fires when the declared
        // typed property is "uninitialized" (after unset()) — that's the state
        // SessionManager establishes for the $g->session ↔ $_SESSION alias.
        // unset() the declared slot so reads/writes route through the magic
        // methods rather than the direct typed-property slot.
        unset($g->get);
        unset($GLOBALS['_GET']);
        try {
            // __get: 'get' → $GLOBALS['_GET'], initialized to [] when missing.
            $this->assertSame([], $g->get);
            $this->assertIsArray($GLOBALS['_GET']);

            // __set: writing $g->get round-trips to $GLOBALS['_GET'].
            $g->get = ['q' => 'value'];
            $this->assertSame(['q' => 'value'], $GLOBALS['_GET']);
            // __get reads it back through the same mapping.
            $this->assertSame(['q' => 'value'], $g->get);
        } finally {
            unset($GLOBALS['_GET']);
            $g->get = [];
        }
    }

    public function testSuperglobalSessionWriteHitsUnderscoreSession(): void
    {
        App::$superglobals = true;
        $g = RequestContext::instance();

        $savedSession = $GLOBALS['_SESSION'] ?? null;
        unset($g->session);
        try {
            $g->session = ['uid' => 7];
            // Symmetric __set mapping: 'session' → $GLOBALS['_SESSION'],
            // NOT $GLOBALS['session'].
            $this->assertSame(['uid' => 7], $GLOBALS['_SESSION']);
            $this->assertArrayNotHasKey('session', $GLOBALS);
            $this->assertSame(['uid' => 7], $g->session);
        } finally {
            if ($savedSession === null) {
                unset($GLOBALS['_SESSION']);
            } else {
                $GLOBALS['_SESSION'] = $savedSession;
            }
            $g->session = [];
        }
    }

    public function testSuperglobalAllMappedKeysRoundTrip(): void
    {
        App::$superglobals = true;
        $g = RequestContext::instance();

        $keys = ['get', 'post', 'cookie', 'files', 'server', 'request'];
        $saved = [];
        foreach ($keys as $k) {
            $saved[$k] = $GLOBALS['_' . strtoupper($k)] ?? null;
            unset($g->$k);
        }
        try {
            foreach ($keys as $k) {
                $g->$k = [$k => 'X'];
                $this->assertSame([$k => 'X'], $GLOBALS['_' . strtoupper($k)], "key $k");
                $this->assertSame([$k => 'X'], $g->$k);
            }
        } finally {
            foreach ($keys as $k) {
                $gk = '_' . strtoupper($k);
                if ($saved[$k] === null) {
                    unset($GLOBALS[$gk]);
                } else {
                    $GLOBALS[$gk] = $saved[$k];
                }
                $g->$k = [];
            }
        }
    }

    public function testSuperglobalEnvKeyMapsToGlobalsEnv(): void
    {
        // 'env' is in the mapped-key list but is NOT a declared property — so
        // __get/__set always run for it in superglobals mode.
        App::$superglobals = true;
        $g = RequestContext::instance();

        $saved = $GLOBALS['_ENV'] ?? null;
        unset($GLOBALS['_ENV']);
        try {
            // __get auto-inits to [].
            $this->assertSame([], $g->env);
            $g->env = ['HOME' => '/root'];
            $this->assertSame(['HOME' => '/root'], $GLOBALS['_ENV']);
        } finally {
            if ($saved === null) {
                unset($GLOBALS['_ENV']);
            } else {
                $GLOBALS['_ENV'] = $saved;
            }
        }
    }

    public function testSuperglobalNonMappedKeyUsesGlobalsBridge(): void
    {
        App::$superglobals = true;
        $g = RequestContext::instance();

        try {
            // __set: a non-superglobal undeclared key → $GLOBALS[$key].
            $g->my_legacy_xyz = 'bridged';
            $this->assertSame('bridged', $GLOBALS['my_legacy_xyz']);
            // __get: same key reads back from $GLOBALS[$key].
            $this->assertSame('bridged', $g->my_legacy_xyz);
        } finally {
            unset($GLOBALS['my_legacy_xyz']);
        }
    }

    // ── superglobals(false): coroutine-mode __get / __set ─────────────

    public function testCoroutineModeUnsetSlotReadsAsNull(): void
    {
        App::$superglobals = false;
        $g = RequestContext::instance();

        // status is a declared nullable typed property; default null reads fine.
        $g->status = null;
        $this->assertNull($g->status);

        // unset() a declared typed property → "uninitialized" slot. __get must
        // hand back a ref to a local null rather than throwing.
        $g->status = 200;
        unset($g->status);
        $this->assertNull($g->status);

        // Re-initialize via __set (declared-but-unset → direct write re-inits).
        $g->status = 404;
        $this->assertSame(404, $g->status);
        $g->status = null;
    }

    public function testCoroutineModeMissingKeyReadsAsNull(): void
    {
        App::$superglobals = false;
        $g = RequestContext::instance();

        // Reading an undeclared key in coroutine mode returns ref to null
        // (no autovivification, no dynamic property created).
        $value = $g->totally_undeclared_key;
        $this->assertNull($value);
        $this->assertFalse(property_exists($g, 'totally_undeclared_key'));
    }

    public function testCoroutineModeUndeclaredWriteThrows(): void
    {
        App::$superglobals = false;
        $g = RequestContext::instance();

        $this->expectException(\BadMethodCallException::class);
        $g->zealphp_reqeust = 'typo-should-throw';
    }

    public function testCoroutineModeDeclaredArrayReinitAfterUnset(): void
    {
        App::$superglobals = false;
        $g = RequestContext::instance();

        $g->session = ['k' => 1];
        $this->assertSame(['k' => 1], $g->session);
        unset($g->session);
        // __set re-inits the declared (non-nullable array) slot directly,
        // bypassing __get — reading the uninitialized non-nullable slot would
        // raise a TypeError, which is why __set's direct re-init path matters.
        $g->session = ['k' => 2];
        $this->assertSame(['k' => 2], $g->session);
        $g->session = [];
    }

    // ── static get() / set() helpers ─────────────────────────────────

    public function testStaticGetSetHelpers(): void
    {
        App::$superglobals = false;
        RequestContext::set('status', 451);
        $this->assertSame(451, RequestContext::get('status'));
        RequestContext::set('status', null);
        $this->assertNull(RequestContext::get('status'));
    }

    // ── instance(): per-coroutine vs singleton ───────────────────────

    public function testInstanceIsSingletonOutsideCoroutine(): void
    {
        App::$superglobals = false;
        // cid < 0 outside a coroutine → process-wide singleton, same handle.
        $a = RequestContext::instance();
        $b = RequestContext::instance();
        $this->assertSame($a, $b);
    }

    public function testInstancePerCoroutineIsIsolated(): void
    {
        App::$superglobals = false;

        $outerOutside = RequestContext::instance();
        $captured = [];

        // Run two coroutines; each gets its own RequestContext on its
        // Coroutine::getContext() — distinct from each other and from the
        // outside-coroutine singleton.
        \OpenSwoole\Coroutine::run(function () use (&$captured) {
            $chan = new \OpenSwoole\Coroutine\Channel(2);
            \OpenSwoole\Coroutine::create(function () use ($chan) {
                $g = RequestContext::instance();
                $g->status = 201;
                $chan->push([spl_object_id($g), $g->status]);
            });
            \OpenSwoole\Coroutine::create(function () use ($chan) {
                $g = RequestContext::instance();
                $g->status = 202;
                $chan->push([spl_object_id($g), $g->status]);
            });
            $captured[] = $chan->pop();
            $captured[] = $chan->pop();
        });

        $this->assertCount(2, $captured);
        // Each coroutine saw its own status (no cross-leak).
        $statuses = [$captured[0][1], $captured[1][1]];
        sort($statuses);
        $this->assertSame([201, 202], $statuses);
        // (Object-ids are not compared: PHP recycles spl_object_id once a
        // coroutine's context is freed, so two sequential coroutines may reuse
        // the same id. The no-cross-leak status assertion above is the real
        // per-coroutine isolation guarantee.)
        // Neither coroutine's write persisted onto the outside-coroutine
        // process-wide singleton.
        $this->assertNotSame(201, $outerOutside->status);
        $this->assertNotSame(202, $outerOutside->status);
    }

    public function testGAliasResolvesToRequestContext(): void
    {
        // Exercise the class_alias tail of the file via the alias entry point.
        $this->assertTrue(class_exists(\ZealPHP\G::class));
        $this->assertInstanceOf(RequestContext::class, \ZealPHP\G::instance());
    }
}
