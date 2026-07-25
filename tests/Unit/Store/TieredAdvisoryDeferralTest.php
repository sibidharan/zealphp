<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Coroutine;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\Store;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\TableBackend;
use ZealPHP\Store\TieredBackend;

/**
 * #490 — the Tiered advisory must be evaluated at a moment the operator could
 * actually have satisfied.
 *
 * It used to be emitted from `defaultBackend()` at BUILD time, but the thing it
 * asks for (`enableInvalidation()`) needs a coroutine and is therefore only
 * legal from an `App::onWorkerStart()` hook — necessarily later. The warning
 * was thus unsatisfiable (it fired forever even for correct apps), and because
 * its branch always won, the second advisory (invalidation ON but
 * UNAUTHENTICATED — an evict-forgery/DoS signal) was unreachable from the boot
 * path.
 *
 * These tests pin the TIMING contract (the decision itself is covered by
 * TieredAdvisoryTest).
 */
final class TieredAdvisoryDeferralTest extends TestCase
{
    /** @var list<callable> */
    private array $prevHooks = [];
    private mixed $prevBackend = null;
    private bool $prevScheduled = false;

    protected function setUp(): void
    {
        // Snapshot every piece of process-wide state these tests touch.
        $this->prevHooks     = self::hooks();
        $this->prevBackend   = self::storeProp('backend')->getValue();
        $this->prevScheduled = (bool) self::storeProp('tieredAdvisoryScheduled')->getValue();
    }

    protected function tearDown(): void
    {
        self::appHooksProp()->setValue(null, $this->prevHooks);
        self::storeProp('backend')->setValue(null, $this->prevBackend);
        self::storeProp('tieredAdvisoryScheduled')->setValue(null, $this->prevScheduled);
    }

    // ── the fix: defer, don't emit at build time ────────────────────────

    public function testBuildingTieredRegistersAWorkerStartHookInsteadOfEmittingNow(): void
    {
        self::resetScheduled();
        $before = count(self::hooks());

        $this->buildTieredDefault();

        $this->assertCount(
            $before + 1,
            self::hooks(),
            '#490: the advisory must be deferred to a worker-start hook, not decided at build time'
        );
    }

    public function testSchedulingIsIdempotentAcrossRepeatedBuilds(): void
    {
        self::resetScheduled();
        $before = count(self::hooks());

        $this->buildTieredDefault();
        $this->buildTieredDefault();
        $this->buildTieredDefault();

        $this->assertCount(
            $before + 1,
            self::hooks(),
            'repeated defaultBackend() calls must not pile up hooks / duplicate the warning'
        );
    }

    // ── the hook itself ────────────────────────────────────────────────

    public function testHookIsNoOpOnNonZeroWorkerIds(): void
    {
        $hook = $this->freshlyRegisteredHook();

        // Workers 1..N and task workers (higher ids) must stay silent so a
        // 32-worker server logs the advisory once, not 32 times. No timer is
        // armed on these ids, so this is safe to call outside an event loop.
        $hook(null, 1);
        $hook(null, 7);
        $hook(null, 31);

        $this->addToAssertionCount(1);   // reaching here = no timer, no throw
    }

    public function testWorkerZeroDefersToATimerThatActuallyFires(): void
    {
        $hook = $this->freshlyRegisteredHook();

        // Neutral default backend so the timer's emit is a no-op — this test is
        // about the DEFERRAL firing, not about logging (elog's async sink would
        // otherwise leave a channel consumer coroutine parked past the test).
        self::storeProp('backend')->setValue(null, new TableBackend());

        Coroutine::run(static function () use ($hook): void {
            $hook(null, 0);
            Coroutine::usleep(20000);   // 20 ms: let the 1 ms timer fire AND complete
        });

        $this->addToAssertionCount(1);   // deferred callback ran, loop drained
    }

    // ── emit reads the CURRENT default backend ─────────────────────────

    public function testEmitIsNoOpWhenDefaultBackendIsNotTiered(): void
    {
        self::storeProp('backend')->setValue(null, new TableBackend());

        self::invokeEmit();

        $this->addToAssertionCount(1);   // no advisory, no throw
    }

    /**
     * The #490 unmasking: once the decision happens AFTER worker start,
     * `enableInvalidation()` has had its chance to run — so a backend that is
     * enabled-but-unauthenticated now reaches the security advisory instead of
     * being masked by the permanently-winning "invalidation is OFF" branch.
     */
    public function testEnabledWithoutSecretReachesTheSecurityAdvisory(): void
    {
        Coroutine::run(function (): void {
            $backend = $this->tiered();   // no secret
            $backend->enableInvalidation();

            self::storeProp('backend')->setValue(null, $backend);

            // This is the state the boot path now observes at emit time (it
            // reads the CURRENT default backend), and it selects the security
            // branch — which the old build-time evaluation could never reach.
            // The emit itself is exercised by the timer test above; calling it
            // here would park elog's async-log consumer past the test.
            $advisory = Store::tieredAdvisory($backend);
            $this->assertIsString($advisory);
            $this->assertStringContainsString(
                'UNAUTHENTICATED',
                $advisory,
                '#490: the security branch must be reachable once the decision is deferred'
            );

            $backend->stopInvalidation();
        });
    }

    // ── guidance text ──────────────────────────────────────────────────

    public function testAdvisoryNamesTheOnlyLegalPlacement(): void
    {
        $advisory = Store::tieredAdvisory($this->tiered());
        $this->assertIsString($advisory);
        // Telling the operator "at boot" is what made the old message a trap:
        // app.php file scope IS boot, and it cannot work there.
        $this->assertStringContainsString('App::onWorkerStart()', $advisory);
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function tiered(?string $secret = null): TieredBackend
    {
        // Pool is lazy — never dials unless an op runs.
        $l2 = new RedisBackend(new RedisConnectionPool('redis://127.0.0.1:6379', 1), 'zptest');
        return new TieredBackend(new TableBackend(), $l2, l1Ttl: 5, invalidationSecret: $secret);
    }

    /** Build a Tiered default backend through the real facade path. */
    private function buildTieredDefault(): void
    {
        Store::defaultBackend(Store::BACKEND_TIERED, [
            'url'    => 'redis://127.0.0.1:6379',
            'prefix' => 'zptest',
            'l1_ttl' => 5,
        ]);
    }

    /** @return callable the hook the tiered build path just registered */
    private function freshlyRegisteredHook(): callable
    {
        self::resetScheduled();
        $this->buildTieredDefault();
        $hooks = self::hooks();
        $hook = end($hooks);
        $this->assertIsCallable($hook);
        return $hook;
    }

    private static function resetScheduled(): void
    {
        self::storeProp('tieredAdvisoryScheduled')->setValue(null, false);
    }

    private static function invokeEmit(): void
    {
        $m = new \ReflectionMethod(Store::class, 'emitTieredAdvisory');
        $m->setAccessible(true);
        $m->invoke(null);
    }

    private static function storeProp(string $name): \ReflectionProperty
    {
        $p = new \ReflectionProperty(Store::class, $name);
        $p->setAccessible(true);
        return $p;
    }

    private static function appHooksProp(): \ReflectionProperty
    {
        $p = new \ReflectionProperty(App::class, 'workerStartHooks');
        $p->setAccessible(true);
        return $p;
    }

    /** @return list<callable> */
    private static function hooks(): array
    {
        /** @var list<callable> $hooks */
        $hooks = self::appHooksProp()->getValue();
        return $hooks;
    }
}
