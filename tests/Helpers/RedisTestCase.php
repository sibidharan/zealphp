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
        try {
            $this->client = new PredisClient($this->url);
            $this->client->ping();
        } catch (\Throwable $e) {
            $this->client = null;
            $this->markTestSkipped('Redis/Valkey not available at ' . $this->url . ' (' . $e->getMessage() . ')');
        }
        $this->client->flushdb();

        // Enable HOOK_ALL once per Redis-touching test. Required for the
        // SUBSCRIBE-loop tests (RedisPubSub, RedisStreams, TieredBackend
        // invalidation) — predis/phpredis SUBSCRIBE blocks the worker
        // without HOOK_ALL; the rendezvous Channel never gets a chance
        // to yield, the test hangs forever, and PHPUnit coverage
        // instrumentation segfaults the process (SIGSEGV in CI Infection).
        // No-op for non-coroutine code paths. Idempotent across tests.
        if (class_exists(\OpenSwoole\Runtime::class)) {
            \OpenSwoole\Runtime::enableCoroutine(true, \OpenSwoole\Runtime::HOOK_ALL);
        }
    }

    /**
     * Tests that drive a Redis SUBSCRIBE loop (RedisPubSub, RedisStreams,
     * TieredBackend invalidation) call this in setUp() to ensure the
     * loop's blocking socket read yields to the OpenSwoole scheduler.
     * Without HOOK_ALL, the subscriber coroutine blocks the worker;
     * `Channel::pop()` on the rendezvous never returns and the whole
     * suite hangs (signal 11 / SIGSEGV under PHPUnit coverage instrumentation).
     *
     * Idempotent; safe to call once per test.
     */
    protected function enableHookAll(): void
    {
        if (class_exists(\OpenSwoole\Runtime::class)) {
            \OpenSwoole\Runtime::enableCoroutine(true, \OpenSwoole\Runtime::HOOK_ALL);
        } else {
            $this->markTestSkipped('OpenSwoole not loaded — SUBSCRIBE loop tests need it');
        }
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            try { $this->client->flushdb(); } catch (\Throwable $e) {}
            try { $this->client->disconnect(); } catch (\Throwable $e) {}
        }
    }
}
