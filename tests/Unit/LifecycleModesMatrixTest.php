<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Runtime;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Lifecycle Mode Matrix — The Five Supported Modes + Two Refused Combos.
 *
 * ZealPHP exposes four boot-only knobs that decide how every request is
 * executed. They live on `App` as fluent static setters and are frozen at
 * `App::run()` time (guarded by `AppLifecycleSettersBootOnlyTest`):
 *
 *     superglobals(bool)         — $g storage strategy + SessionManager class
 *     processIsolation(bool)     — App::include() dispatch (subprocess vs inline)
 *     enableCoroutine(bool)      — OpenSwoole auto-coroutine-per-request wrapper
 *     hookAll(int)               — Runtime::enableCoroutine($flags) — I/O hooks
 *
 * Each knob defaults to `null` and resolves to "follow App::$superglobals"
 * at App::run() boot time. The default coupling preserves the historical
 * behaviour for any app that doesn't touch these knobs.
 *
 * The six SUPPORTED modes — every other combination is either redundant
 * or unsafe (and the unsafe ones throw at boot, see below):
 *
 * ┌──────────────────────┬──────────────┬──────────────────┬─────────────────┬───────────┐
 * │ Mode                 │ superglobals │ processIsolation │ enableCoroutine │ hookAll   │
 * ├──────────────────────┼──────────────┼──────────────────┼─────────────────┼───────────┤
 * │ 1. Legacy CGI (def)  │ true         │ true             │ false           │ 0         │
 * │ 2. Coroutine (def)   │ false        │ false            │ true            │ HOOK_ALL  │
 * │ 3. Mixed-mode/Symf   │ true         │ FALSE            │ false           │ 0         │
 * │ 4. In-process + sync │ true         │ false            │ false           │ 0         │
 * │ 5. Coroutine, no hk  │ false        │ false            │ true            │ 0         │
 * │ 6. CoroutineIsolated │ false        │ TRUE             │ true            │ HOOK_ALL  │
 * └──────────────────────┴──────────────┴──────────────────┴─────────────────┴───────────┘
 *
 * Mode 6 is the "best of both worlds" hybrid — added to the matrix once the
 * lifecycle validator was proven to ALLOW it (sg=false breaks the validator's
 * sg+ec / sg+hooks throws). See testMode6CoroutineIsolatedHybrid() for the
 * full caveat list. The headline win: parent worker runs coroutines (true
 * request-level concurrency) AND each public/*.php gets full subprocess
 * isolation with real $_GET / $_POST / $_SESSION inside the subprocess.
 *
 * The two REFUSED combinations (both throw RuntimeException at App::run()):
 *
 *   1. superglobals(true) + enableCoroutine(true)
 *      → process-wide $_GET / $_POST / $_SESSION would race across concurrent
 *        coroutines. Per-coroutine $g was designed to avoid exactly this bug.
 *
 *   2. superglobals(true) + hookAll(non-zero)
 *      → hooked I/O can yield mid-request, exposing process-wide superglobal
 *        mutations to other coroutines.
 *
 * processIsolation caveats — how App::include() dispatches a public/*.php file
 * changes shape but NOT contract:
 *
 *   processIsolation(true):
 *     • Each App::include() spawns a fresh PHP subprocess via the cgi_mode
 *       backend (pool by default — warm worker reused; proc — fresh proc_open
 *       per request, ~30-50 ms cost; fcgi — forward to upstream FPM pool).
 *     • TRUE global-scope isolation. `define()`-heavy plugins (unmodified
 *       WordPress / Drupal) don't leak constants across requests.
 *     • Universal return contract still applies — file's `return` value flows
 *       back via JSON metadata channel.
 *     • CGI-style $_SERVER preamble auto-populated for the subprocess.
 *
 *   processIsolation(false):
 *     • App::include() runs the file IN-PROCESS via App::executeFile().
 *     • Zero subprocess cost — straight `include` under output buffering.
 *     • No global-scope isolation: function/class re-declarations across
 *       requests are visible. Apps with idempotent boot code (Symfony,
 *       Laravel) are fine; apps that call `define()` unconditionally at
 *       request scope (WordPress) break.
 *     • Universal return contract still applies — captured echo + return
 *       value are combined per the file-execution-family table.
 *
 * cgi_mode (the dispatch strategy when processIsolation=true) is independent
 * of the four lifecycle knobs above. Default is 'pool' — the FPM-style warm
 * worker pool. Fork mode was removed in v0.2.41+; 'proc', 'pool', and 'fcgi'
 * are the three valid values. cgi_mode is meaningless when processIsolation
 * is false (App::include() never dispatches; runs inline).
 *
 * This test file pins every cell of the matrix + the two refused combos +
 * the cgi_mode default. It uses `validateLifecycleCombination()` via
 * Reflection so we can exercise the throw path without booting the server.
 */
final class LifecycleModesMatrixTest extends TestCase
{
    /** @var bool|null */
    private static ?bool $origSg = null;
    /** @var bool|null */
    private static ?bool $origPi = null;
    /** @var bool|null */
    private static ?bool $origEc = null;
    /** @var int|null */
    private static ?int $origHa = null;
    /** @var string */
    private static string $origCgiMode = '';

    public static function setUpBeforeClass(): void
    {
        // Snapshot boot defaults so any cross-test contamination is undone.
        self::$origSg      = App::$superglobals;
        self::$origPi      = App::$process_isolation;
        self::$origEc      = App::$enable_coroutine_override;
        self::$origHa      = App::$hook_all_override;
        self::$origCgiMode = App::$cgi_mode;
    }

    protected function setUp(): void
    {
        // Pre-boot so setters accept writes (mirrors AppLifecycleSettersBootOnlyTest).
        App::$run_has_started = false;
    }

    protected function tearDown(): void
    {
        // Restore boot defaults after every test so test order is irrelevant.
        App::$run_has_started            = false;
        App::$superglobals               = self::$origSg ?? true;
        App::$process_isolation          = self::$origPi;
        App::$enable_coroutine_override  = self::$origEc;
        App::$hook_all_override          = self::$origHa;
        App::$cgi_mode                   = self::$origCgiMode !== '' ? self::$origCgiMode : 'pool';
    }

    /**
     * Drive `App::validateLifecycleCombination()` directly via Reflection.
     * It's `private static` because it's only called from inside `App::run()`,
     * but the validation logic is pure — no I/O — and the test surface is
     * far cleaner driving it directly than spinning a whole server up.
     *
     * @return mixed The thrown exception (caught + returned) or null on no-throw.
     */
    private function validate(bool $sg, int $hookFlags, bool $enableCo): ?\Throwable
    {
        $rm = new \ReflectionMethod(App::class, 'validateLifecycleCombination');
        $rm->setAccessible(true);
        try {
            $rm->invoke(null, $sg, $hookFlags, $enableCo);
            return null;
        } catch (\Throwable $e) {
            return $e;
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //   The five SUPPORTED modes — one test each, all four knobs pinned
    // ══════════════════════════════════════════════════════════════════

    /**
     * Mode 1 — Legacy CGI (the default when superglobals(true) only is set).
     *
     * Use when: serving unmodified WordPress / Drupal / wp-plugin-heavy code
     * that calls `define()` at request scope. Each include = fresh subprocess
     * via cgi_mode ('pool' is the default — warm worker, ms latency).
     *
     * Knob shape: sg=true, pi=true, ec=false, ha=0.
     */
    public function testMode1LegacyCgiResolvedKnobs(): void
    {
        App::superglobals(true);
        App::processIsolation(null);  // null → resolves to follow superglobals
        App::enableCoroutine(null);
        App::hookAll(null);

        $this->assertTrue(App::$superglobals);
        $this->assertTrue(App::processIsolation(), 'pi null → follows sg=true → true');
        $this->assertFalse(App::enableCoroutine(), 'ec null → follows !sg → false');
        $this->assertSame(0, App::hookAll(), 'ha null → follows !sg → 0 (no hooks)');

        // Boot-time validator passes for this shape.
        $this->assertNull($this->validate(true, 0, false), 'legacy CGI is supported');
    }

    /**
     * Mode 2 — Coroutine (the recommended default for new ZealPHP apps).
     *
     * Use when: writing modern apps that benefit from concurrent coroutine
     * I/O. Per-coroutine $g via Coroutine::getContext() — isolated state per
     * request. HOOK_ALL transparently makes curl/fopen/mysqli yield-aware.
     * App::include() runs in-process via executeFile() (no subprocess cost).
     *
     * Knob shape: sg=false, pi=false, ec=true, ha=HOOK_ALL.
     *
     * Caveat: PDO is NOT hooked in OpenSwoole 22.1 / 26.2 regardless of
     * HOOK_ALL. Use OpenSwoole\Coroutine\MySQL / PostgreSQL for true
     * yielding DB access, OR accept PDO blocks the worker.
     */
    public function testMode2CoroutineResolvedKnobs(): void
    {
        App::superglobals(false);
        App::processIsolation(null);
        App::enableCoroutine(null);
        App::hookAll(null);

        $this->assertFalse(App::$superglobals);
        $this->assertFalse(App::processIsolation(), 'pi null → follows sg=false → false');
        $this->assertTrue(App::enableCoroutine(), 'ec null → follows !sg → true');
        $this->assertSame(Runtime::HOOK_ALL, App::hookAll(), 'ha null → follows !sg → HOOK_ALL');

        $this->assertNull($this->validate(false, Runtime::HOOK_ALL, true), 'coroutine is supported');
    }

    /**
     * Mode 3 — Mixed-mode / Symfony bridge.
     *
     * Use when: hosting Symfony or Laravel on ZealPHP. Real $_SESSION needed
     * (framework's session bag introspection), but the per-include CGI fork
     * cost (the 30-50 ms proc_open hit on EVERY request when pi=true) would
     * destroy throughput. Sequential request handling per worker means
     * superglobals don't race — no need for coroutine isolation.
     *
     * Knob shape: sg=true, pi=FALSE (the asymmetry vs Legacy CGI), ec=false, ha=0.
     *
     * Caveat: includes are in-process. Apps with `define()` at request scope
     * (WordPress style) will break — constants leak across requests. Symfony
     * / Laravel boot code is idempotent and tolerates this.
     */
    public function testMode3MixedModeSymfonyResolvedKnobs(): void
    {
        App::superglobals(true);
        App::processIsolation(false);  // explicit override — the defining knob
        App::enableCoroutine(null);
        App::hookAll(null);

        $this->assertTrue(App::$superglobals);
        $this->assertFalse(App::processIsolation(), 'pi=false — App::include() runs INLINE');
        $this->assertFalse(App::enableCoroutine());
        $this->assertSame(0, App::hookAll());

        $this->assertNull($this->validate(true, 0, false), 'mixed-mode is supported');
    }

    /**
     * Mode 4 — In-process + sync (same shape as Mixed-mode).
     *
     * Use when: you want Apache-prefork-MPM semantics with sub-ms include
     * cost. Identical to Mixed-mode/Symfony but listed separately because
     * the intent is different — Mixed-mode is "Symfony just works", this is
     * "I want sequential PHP without fork cost". Both have the same knob
     * shape so the validator treats them identically.
     */
    public function testMode4InProcessSyncResolvedKnobs(): void
    {
        App::superglobals(true);
        App::processIsolation(false);
        App::enableCoroutine(false);
        App::hookAll(0);

        $this->assertTrue(App::$superglobals);
        $this->assertFalse(App::processIsolation());
        $this->assertFalse(App::enableCoroutine());
        $this->assertSame(0, App::hookAll());

        $this->assertNull($this->validate(true, 0, false), 'in-process+sync is supported');
    }

    /**
     * Mode 5 — Coroutine without HOOK_ALL.
     *
     * Use when: per-coroutine $g isolation is wanted but the global I/O hooks
     * cause measurable trouble — e.g., test harnesses that introspect socket
     * state, or code that bundles its own custom Coroutine\Http\Client and
     * doesn't want fopen/curl re-routed under it.
     *
     * Knob shape: sg=false, pi=false, ec=true, ha=0 (explicit override).
     *
     * Caveat: blocking PHP I/O (fopen, curl_exec, sleep) WILL block the
     * worker. You're opting out of the framework's "transparent async" win.
     */
    public function testMode5CoroutineNoHooksResolvedKnobs(): void
    {
        App::superglobals(false);
        App::processIsolation(null);
        App::enableCoroutine(null);
        App::hookAll(0);  // explicit override — the defining knob

        $this->assertFalse(App::$superglobals);
        $this->assertFalse(App::processIsolation());
        $this->assertTrue(App::enableCoroutine());
        $this->assertSame(0, App::hookAll(), 'ha=0 explicit — no I/O hooks even in coroutine mode');

        $this->assertNull($this->validate(false, 0, true), 'coroutine-no-hooks is supported');
    }

    /**
     * Mode 6 — Coroutine + Process Isolation (the "best of both worlds" hybrid).
     *
     * Use when: modern app that runs most of its code in the parent worker
     * with coroutine concurrency, but ALSO needs to occasionally include
     * legacy isolated PHP (a WordPress plugin endpoint, a third-party admin
     * panel, a heritage `define()`-heavy script) WITHOUT taking the global-
     * scope hit at the parent.
     *
     * Knob shape: sg=false, pi=TRUE (explicit), ec=true, ha=HOOK_ALL.
     *
     * What this delivers:
     *   • Parent worker: per-coroutine $g (no race), HOOK_ALL hooks pipe I/O,
     *     enable_coroutine wraps every request in a coroutine. N concurrent
     *     requests in flight at all times.
     *   • When a coroutine hits App::include('/wp-login.php'), the parent
     *     pops a pool worker from its Coroutine\Channel, sends the request
     *     frame over stdin, and YIELDS on the pipe read. The scheduler runs
     *     other coroutines while the subprocess is busy.
     *   • Multiple coroutines dispatch to DIFFERENT pool workers in parallel
     *     (channel pops one per coroutine — up to `cgiPoolSize`). True
     *     request-level concurrency through the CGI path.
     *   • INSIDE each pool subprocess: real $_GET / $_POST / $_SERVER /
     *     $_COOKIE / $_REQUEST populated per request (pool_worker.php:209),
     *     reset to clean state between requests (pool_worker.php:265).
     *     Full global-scope isolation per request — `define()` calls don't
     *     leak across requests.
     *
     * What this does NOT deliver — important to be honest:
     *   • Coroutines do NOT run INSIDE the pool subprocess. Each subprocess
     *     handles one request at a time, sequentially. If user PHP code
     *     spawns coroutines via go() inside the subprocess, there is no
     *     scheduler running there to execute them. (We could enable a
     *     scheduler in pool_worker, but that re-introduces the superglobals-
     *     race-across-coroutines problem inside the subprocess — defeats
     *     the purpose. Stay sequential per subprocess; scale by adding more
     *     pool workers via cgiPoolSize().)
     *   • The CGI dispatch round-trip (pipe write + subprocess execute +
     *     pipe read) adds ~1-3 ms per included file on top of the PHP code
     *     itself. Routes/middleware/API in the parent stay sub-ms.
     *
     * Caveat: NOT the default. To get this combo you must EXPLICITLY set
     * `App::processIsolation(true)` — otherwise pi resolves to follow
     * sg=false → pi=false (no isolation). The defaults assume you either
     * want full coroutine speed (no isolation) OR full superglobal compat
     * (Legacy CGI mode); the hybrid is for the modern-mostly-with-legacy-
     * pockets case.
     */
    public function testMode6CoroutineIsolatedHybrid(): void
    {
        App::superglobals(false);
        App::processIsolation(true);   // explicit — the defining knob for Mode 6
        App::enableCoroutine(null);
        App::hookAll(null);

        $this->assertFalse(App::$superglobals);
        $this->assertTrue(App::processIsolation(), 'pi=true explicit — CGI dispatch ON');
        $this->assertTrue(App::enableCoroutine(), 'ec null → follows !sg → true');
        $this->assertSame(Runtime::HOOK_ALL, App::hookAll(), 'ha null → follows !sg → HOOK_ALL');

        // The validator MUST pass — sg=false breaks the sg+ec / sg+hooks throws.
        $this->assertNull(
            $this->validate(false, Runtime::HOOK_ALL, true),
            'Mode 6 hybrid is supported: sg=false allows ec=true + ha=HOOK_ALL even with pi=true'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    //   The two REFUSED combos — both MUST throw at boot
    // ══════════════════════════════════════════════════════════════════

    /**
     * Refused #1 — superglobals(true) + enableCoroutine(true).
     *
     * Process-wide $_GET / $_POST / $_SESSION would race across concurrent
     * coroutines. Per-coroutine $g was designed to avoid exactly this bug.
     * The validator throws with a message naming both flags so the error
     * is self-diagnosing.
     */
    public function testRefusedSuperglobalsPlusEnableCoroutineWithoutExtZealphp(): void
    {
        if (\extension_loaded('zealphp')) {
            $this->markTestSkipped('ext-zealphp makes this combination safe');
        }
        $ex = $this->validate(true, 0, true);
        $this->assertInstanceOf(\RuntimeException::class, $ex);
        $this->assertStringContainsString('superglobals(true) + App::enableCoroutine(true)', (string)$ex->getMessage());
        $this->assertStringContainsString('ext-zealphp', (string)$ex->getMessage());
    }

    /**
     * Refused #2 without ext-zealphp — superglobals(true) + hookAll(non-zero).
     *
     * With ext-zealphp loaded, this combination is safe (per-coroutine
     * superglobal save/restore). Without it, hooked I/O yields mid-request.
     */
    public function testRefusedSuperglobalsPlusHookAllWithoutExtZealphp(): void
    {
        if (\extension_loaded('zealphp')) {
            $this->markTestSkipped('ext-zealphp makes this combination safe');
        }
        $ex = $this->validate(true, Runtime::HOOK_ALL, false);
        $this->assertInstanceOf(\RuntimeException::class, $ex);
        $this->assertStringContainsString('superglobals(true) + App::hookAll(non-zero)', (string)$ex->getMessage());
        $this->assertStringContainsString('ext-zealphp', (string)$ex->getMessage());
    }

    /**
     * The two refusals are independent: hitting BOTH at once still throws.
     * Validator surfaces the enableCoroutine error first (declaration order
     * in validateLifecycleCombination) — pinning it so a refactor that
     * inverts the order surfaces in the diff.
     */
    public function testRefusedBothUnsafeFlagsSetSurfacesEnableCoroutineFirstWithoutExtZealphp(): void
    {
        if (\extension_loaded('zealphp')) {
            // ext-zealphp makes both combinations safe — no exception
            $ex = $this->validate(true, Runtime::HOOK_ALL, true);
            $this->assertNull($ex);
            return;
        }
        $ex = $this->validate(true, Runtime::HOOK_ALL, true);
        $this->assertInstanceOf(\RuntimeException::class, $ex);
        $this->assertStringContainsString('enableCoroutine(true)', (string)$ex->getMessage());
    }

    // ══════════════════════════════════════════════════════════════════
    //   processIsolation — the dispatch-shape switch
    // ══════════════════════════════════════════════════════════════════

    /**
     * Test that processIsolation(null) resolves to follow $superglobals.
     * This is the historical coupling — apps that don't touch the knob get
     * the default behaviour: superglobals(true) → pi=true (subprocess) and
     * superglobals(false) → pi=false (inline).
     */
    public function testProcessIsolationNullFollowsSuperglobals(): void
    {
        App::superglobals(true);
        App::processIsolation(null);
        $this->assertTrue(App::processIsolation(), 'null + sg=true → pi=true');

        App::superglobals(false);
        App::processIsolation(null);
        $this->assertFalse(App::processIsolation(), 'null + sg=false → pi=false');
    }

    /**
     * Explicit override beats the default coupling. This is the knob users
     * touch when they want Mixed-mode (sg=true + pi=false — "Symfony shape").
     */
    public function testProcessIsolationExplicitOverridesDefault(): void
    {
        App::superglobals(true);
        App::processIsolation(false);
        $this->assertFalse(App::processIsolation(), 'pi=false explicit wins over sg=true coupling');

        App::superglobals(false);
        App::processIsolation(true);
        $this->assertTrue(App::processIsolation(), 'pi=true explicit wins over sg=false coupling');
    }

    // ══════════════════════════════════════════════════════════════════
    //   cgi_mode — meaningful only when processIsolation=true
    // ══════════════════════════════════════════════════════════════════

    /**
     * Pool is the default — the FPM-style warm worker pool, shipped v0.2.41+.
     * Codifies the v0.2.41 default flip away from 'proc'. A regression here
     * means somebody changed the default; the test should fail loud so the
     * doc + release notes get updated in lock-step.
     */
    public function testCgiModeDefaultIsPool(): void
    {
        // Constructor of App initialises $cgi_mode from the class default,
        // not from any per-instance config. Asserting the class default
        // directly pins the v0.2.41 contract.
        $defaultProperty = (new \ReflectionClass(App::class))->getDefaultProperties()['cgi_mode'] ?? null;
        $this->assertSame('pool', $defaultProperty, 'cgiMode default is pool from v0.2.41+');
    }

    /**
     * The three valid cgi_mode values. Fork was removed in v0.2.41+; anything
     * else throws InvalidArgumentException. Test the setter accepts each
     * valid value and rejects fork.
     */
    public function testCgiModeAcceptsThreeValidValuesRejectsFork(): void
    {
        foreach (['pool', 'proc', 'fcgi'] as $mode) {
            App::cgiMode($mode);
            $this->assertSame($mode, App::cgiMode(), "cgiMode($mode) is valid");
        }

        $this->expectException(\InvalidArgumentException::class);
        App::cgiMode('fork');  // removed in v0.2.41 — must throw
    }

    /**
     * cgi_mode is independent of the four lifecycle knobs. Setting cgiMode
     * doesn't touch superglobals/processIsolation/enableCoroutine/hookAll.
     * This prevents a sneaky regression where someone wires the knobs
     * together "for convenience" — they're orthogonal by design.
     */
    public function testCgiModeOrthogonalToLifecycleKnobs(): void
    {
        App::superglobals(true);
        App::processIsolation(true);
        App::enableCoroutine(false);
        App::hookAll(0);

        $snapshot = [
            'sg' => App::$superglobals,
            'pi' => App::processIsolation(),
            'ec' => App::enableCoroutine(),
            'ha' => App::hookAll(),
        ];

        App::cgiMode('proc');
        App::cgiMode('fcgi');
        App::cgiMode('pool');

        $this->assertSame($snapshot['sg'], App::$superglobals);
        $this->assertSame($snapshot['pi'], App::processIsolation());
        $this->assertSame($snapshot['ec'], App::enableCoroutine());
        $this->assertSame($snapshot['ha'], App::hookAll());
    }
}
