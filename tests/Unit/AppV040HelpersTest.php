<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\Store;
use ZealPHP\Store\StoreException;

/**
 * Patch-coverage for the App.php v0.2.40 helpers that AppPubSubAliasesTest
 * + AppOnProcessTest + AppRedisBootChecksTest + AppParallelTest didn't
 * directly exercise — the edge-cases + validation paths + the new
 * publish/publishReliable wrappers that delegate to Store.
 */
final class AppV040HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
    }

    // ── App::publish / publishReliable — symmetric front door ──────────

    public function testPublishOnTableBackendThrows(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches('/requires the redis backend/');
        App::publish('test-channel', 'payload');
    }

    public function testPublishReliableOnTableBackendThrows(): void
    {
        $this->expectException(StoreException::class);
        App::publishReliable('test-stream', 'payload');
    }

    // ── App::addProcess validation ──────────────────────────────────────

    public function testAddProcessRejectsZeroWorkers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::addProcess('bad-' . bin2hex(random_bytes(2)), fn() => null, workers: 0);
    }

    public function testAddProcessRejectsDuplicateName(): void
    {
        $name = 'dup-' . bin2hex(random_bytes(3));
        App::addProcess($name, fn() => null);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already registered/');
        App::addProcess($name, fn() => null);
    }

    public function testOnProcessBcAliasDelegates(): void
    {
        // The BC alias should be functionally identical to addProcess —
        // we verify by triggering the same duplicate-name guard.
        $name = 'bc-dup-' . bin2hex(random_bytes(3));
        App::onProcess($name, fn() => null);
        $this->expectException(\InvalidArgumentException::class);
        App::onProcess($name, fn() => null);
    }

    // ── App::onSignal registration ──────────────────────────────────────

    public function testOnSignalRegistersHandlerWithoutImmediateInvocation(): void
    {
        $fired = false;
        App::onSignal(SIGUSR2, function () use (&$fired): void { $fired = true; });
        // Registration alone shouldn't fire the handler.
        $this->assertFalse($fired);
    }

    public function testApplySignalHandlersForMethodExists(): void
    {
        // Reflection-only check. Actually CALLING applySignalHandlersFor
        // invokes OpenSwoole\Process::signal which spawns the event loop —
        // contaminates subsequent Coroutine::run-using tests in the same
        // PHPUnit process. So we don't invoke it here; the method's
        // behaviour is exercised inside a real App::run() event-loop
        // context (integration tests + the live server).
        $r = new \ReflectionClass(App::class);
        $this->assertTrue($r->hasMethod('applySignalHandlersFor'));
    }

    public function testOnSignalTagsWorkerOnlyHandlersForWorkerApply(): void
    {
        // #311 — worker-scoped handlers must be tagged worker_only:true so the
        // per-worker workerStart applySignalHandlersFor('worker') (the fix) picks
        // them up; the master 'start' path applies only worker_only:false ones.
        // We assert the registry tag here (the Process::signal wiring is exercised
        // by the live server — see the note above on event-loop contamination).
        $sig = SIGUSR1;
        App::onSignal($sig, fn() => null, workerOnly: true);

        $prop = (new \ReflectionClass(App::class))->getProperty('signalHandlers');
        $prop->setAccessible(true);
        $all = $prop->getValue();
        $this->assertIsArray($all);
        $this->assertArrayHasKey($sig, $all);
        $hasWorkerOnly = false;
        foreach ($all[$sig] as $entry) {
            if (($entry['worker_only'] ?? null) === true) {
                $hasWorkerOnly = true;
                break;
            }
        }
        $this->assertTrue($hasWorkerOnly, 'worker-scoped handler must be tagged worker_only:true');
    }

    // ── App::clearTimer guard ──────────────────────────────────────────

    public function testClearTimerWithZeroIsNoOp(): void
    {
        // Timer::clear handles missing IDs gracefully.
        App::clearTimer(0);
        $this->assertTrue(true);   // smoke
    }

    // ── App::stats subsystem coverage ───────────────────────────────────

    public function testStatsBackendsKindIsConcreteClassShortName(): void
    {
        $s = App::stats();
        $this->assertSame('TableBackend',  $s['backends']['store_kind']);
        $this->assertSame('AtomicBackend', $s['backends']['counter_kind']);
    }

    public function testStatsWorkerCountsArePresent(): void
    {
        $s = App::stats();
        $this->assertIsInt($s['workers']['http']);
        $this->assertIsInt($s['workers']['task']);
    }

    public function testStatsMemoryReportsPositiveUsage(): void
    {
        $s = App::stats();
        $this->assertGreaterThan(0, $s['memory']['usage_bytes']);
        $this->assertGreaterThanOrEqual($s['memory']['usage_bytes'], $s['memory']['peak_bytes']);
    }

    public function testStatsPhpReportsRunningVersion(): void
    {
        $s = App::stats();
        $this->assertSame(PHP_VERSION, $s['php']);
    }

    public function testStatsCacheSlotHasExpectedShape(): void
    {
        $s = App::stats();
        $cache = $s['cache'];
        $this->assertIsArray($cache);
        // Cache::stats returns 10 keys when initialised. When uninitialised
        // (no Cache::init call), the safeStats wrapper still returns an array.
        if (isset($cache['memory_entries'])) {
            $this->assertArrayHasKey('hits_memory',     $cache);
            $this->assertArrayHasKey('hits_file',       $cache);
            $this->assertArrayHasKey('misses',          $cache);
            $this->assertArrayHasKey('hit_rate',        $cache);
        }
    }

    // ── App::tick / after API existence ────────────────────────────────

    public function testTimerHelpersAreCallable(): void
    {
        // tick/after both call into OpenSwoole\Timer which is only meaningful
        // inside a coroutine + scheduler context. The unit test just verifies
        // the method bindings are correct.
        $r = new \ReflectionClass(App::class);
        $this->assertTrue($r->hasMethod('tick'));
        $this->assertTrue($r->hasMethod('after'));
        $this->assertTrue($r->hasMethod('clearTimer'));
    }

    // ── App::onWorkerStart/Stop registration ───────────────────────────

    public function testOnWorkerStartHookAccepted(): void
    {
        $cb = function (): void {};
        App::onWorkerStart($cb);
        // Registration is a no-fail — actual invocation happens in run().
        $this->assertTrue(true);
    }

    public function testOnWorkerStopHookAccepted(): void
    {
        $cb = function (): void {};
        App::onWorkerStop($cb);
        $this->assertTrue(true);
    }
}
