<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Helpers;

use PHPUnit\Framework\TestCase;
use Predis\Client as PredisClient;

abstract class RedisTestCase extends TestCase
{
    protected ?PredisClient $client = null;
    protected string $url;

    protected function setUp(): void
    {
        $url = getenv('ZEALPHP_REDIS_URL');
        $this->url = is_string($url) && $url !== '' ? $url : 'redis://127.0.0.1:16379/0';

        // Connect at TOP LEVEL (not via Coroutine::run). Reasons:
        //   1. markTestSkipped() throws SkippedWithMessageException, which
        //      PHPUnit's setUp wrapper catches to mark the test as skipped.
        //      If we throw from INSIDE Coroutine::run, OpenSwoole doesn't
        //      re-throw it cleanly — it escapes as an uncaught fatal at
        //      {main}, crashing the WHOLE PHPUnit process. Mutation CI
        //      (no Redis service container) hit this.
        //   2. HOOK_ALL is OFF at setUp time. Subscriber-loop tests turn
        //      it on inside their own Coroutine::run via
        //      requireYieldingSubscribe(); tearDown disables it again so
        //      the next test's setUp is back to plain PHP.
        try {
            $this->client = new PredisClient($this->url);
            $this->client->ping();
        } catch (\Throwable $e) {
            $this->client = null;
            $this->markTestSkipped('Redis/Valkey not available at ' . $this->url . ' (' . $e->getMessage() . ')');
        }
        $this->client->flushdb();
    }

    protected function tearDown(): void
    {
        // Disable HOOK_ALL FIRST so the predis disconnect below runs in
        // plain PHP — otherwise the hooked stream_socket close needs a
        // coroutine context and the cleanup throws.
        $this->disableHookAll();
        if ($this->client !== null) {
            try { $this->client->flushdb(); }   catch (\Throwable $e) {}
            try { $this->client->disconnect(); } catch (\Throwable $e) {}
        }
    }

    /**
     * Run a closure that opens / uses predis sockets. When HOOK_ALL is on
     * process-wide and we're at the top level (no coroutine), wraps the
     * call in `Coroutine::run` so the hooked socket APIs have the context
     * they require. Inside an existing coroutine (or in a non-OpenSwoole
     * environment) the closure runs synchronously.
     */
    protected function runRedis(callable $fn): void
    {
        if (class_exists(\OpenSwoole\Coroutine::class) && \OpenSwoole\Coroutine::getCid() === -1) {
            \OpenSwoole\Coroutine::run($fn);
            return;
        }
        $fn();
    }

    /**
     * SUBSCRIBE-loop tests call this AT THE TOP OF THE TEST METHOD
     * (before `Coroutine::run`). It:
     *   1. Skips the test when phpredis is the active extension (phpredis
     *      SUBSCRIBE blocks at the C level — HOOK_ALL cannot hook C-side
     *      socket reads. Per CLAUDE.md: "Unit tests can't exercise the
     *      phpredis SUBSCRIBE path — the standalone spike at
     *      scripts/spike-phpredis-subscribe.php is the canonical
     *      validation"). markTestSkipped MUST throw from the test's own
     *      stack — not from inside a Coroutine::run — so PHPUnit's
     *      TestRunner can catch the SkippedTestError.
     *   2. Enables HOOK_ALL so the subsequent Coroutine::run's
     *      subscriber loop yields to the OpenSwoole scheduler. Paired
     *      with `disableHookAll()` in tearDown so other tests in the
     *      same run keep their non-coroutine semantics.
     */
    protected function requireYieldingSubscribe(): void
    {
        if (extension_loaded('redis')) {
            $this->markTestSkipped(
                'SUBSCRIBE tests skip when ext-redis (phpredis) is loaded — ' .
                'phpredis SUBSCRIBE blocks at the C level and HOOK_ALL cannot ' .
                'hook C-side socket reads. Validated separately via ' .
                'scripts/spike-phpredis-subscribe.php. The predis SUBSCRIBE ' .
                'path runs here when ext-redis is absent.'
            );
        }
        if (class_exists(\OpenSwoole\Runtime::class)) {
            \OpenSwoole\Runtime::enableCoroutine(true, \OpenSwoole\Runtime::HOOK_ALL);
            $this->hookAllEnabled = true;
        }
    }

    /** @deprecated — alias kept for already-injected sites; use requireYieldingSubscribe(). */
    protected function enableHookAll(): void
    {
        $this->requireYieldingSubscribe();
    }

    /** @internal — flag tracked so tearDown only resets when enabled. */
    private bool $hookAllEnabled = false;

    protected function disableHookAll(): void
    {
        if ($this->hookAllEnabled && class_exists(\OpenSwoole\Runtime::class)) {
            \OpenSwoole\Runtime::enableCoroutine(false);
            $this->hookAllEnabled = false;
        }
    }
}
