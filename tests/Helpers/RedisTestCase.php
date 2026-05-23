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

        // SUBSCRIBE-loop tests (RedisPubSub, RedisStreams, TieredBackend
        // invalidation) need OpenSwoole HOOK_ALL active so their blocking
        // socket reads yield to the scheduler. HOOK_ALL is process-wide
        // and persists across tests once any earlier test enables it.
        //
        // Side effect: once HOOK_ALL is on, predis's `stream_socket_client`
        // call REQUIRES a coroutine context — otherwise PHP throws
        // "OpenSwoole\Error: API must be called in the coroutine".
        //
        // We solve both by running setUp's predis ops inside Coroutine::run
        // when we're at the top level (no coroutine yet). The run() body
        // executes synchronously when HOOK_ALL is off — zero overhead.
        $this->runRedis(function (): void {
            try {
                $this->client = new PredisClient($this->url);
                $this->client->ping();
            } catch (\Throwable $e) {
                $this->client = null;
                $this->markTestSkipped('Redis/Valkey not available at ' . $this->url . ' (' . $e->getMessage() . ')');
            }
            $this->client->flushdb();
        });

        // Note: HOOK_ALL is NOT enabled here — many tests (RedisBackendTest,
        // TableBackendTest, etc.) construct ZealPHP-internal Redis clients
        // OUTSIDE a coroutine context. Enabling HOOK_ALL process-wide would
        // break those tests with "API must be called in the coroutine".
        //
        // The SUBSCRIBE-loop tests (RedisPubSub, RedisStreams, TieredBackend
        // invalidation) call `$this->enableHookAll()` themselves at the top
        // of their test bodies INSIDE Coroutine::run — see those classes.
        // Once HOOK_ALL flips on (process-wide), subsequent tests' setUp /
        // tearDown automatically wraps predis ops via runRedis().
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            $client = $this->client;
            $this->runRedis(function () use ($client): void {
                try { $client->flushdb(); }   catch (\Throwable $e) {}
                try { $client->disconnect(); } catch (\Throwable $e) {}
            });
        }
        // Auto-disable HOOK_ALL if this test enabled it — otherwise other
        // tests in the same PHPUnit run inherit the process-wide flag and
        // their non-coroutine RedisClient construction fails.
        $this->disableHookAll();
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
