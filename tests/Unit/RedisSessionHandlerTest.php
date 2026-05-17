<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\Session\Handler\RedisSessionHandler;

/**
 * Unit tests for ZealPHP\Session\Handler\RedisSessionHandler.
 *
 * The class implements PHP's \SessionHandlerInterface against a Redis backend
 * using the `PHPREDIS_SESSION:{id}` key format (phpredis-compatible). Tests
 * split into two sets:
 *
 *  1. Class-shape contract tests — verify the type satisfies the
 *     SessionHandlerInterface API even without ext-redis loaded. These
 *     guard against accidental signature drift.
 *
 *  2. Connected behaviour tests — skipped automatically when ext-redis is
 *     absent OR when no Redis is reachable at 127.0.0.1:6379. These cover
 *     read/write/destroy round-trips against a live instance.
 *
 * Production deployments should run the connected set as part of an
 * integration job that boots Redis as a docker service.
 */
class RedisSessionHandlerTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // Class-shape contract — no ext-redis required
    // ──────────────────────────────────────────────────────────────

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(RedisSessionHandler::class));
    }

    public function testImplementsSessionHandlerInterface(): void
    {
        $this->assertTrue(
            is_subclass_of(RedisSessionHandler::class, \SessionHandlerInterface::class)
        );
    }

    public function testExposesAllSessionHandlerInterfaceMethods(): void
    {
        $required = ['open', 'close', 'read', 'write', 'destroy', 'gc'];
        foreach ($required as $method) {
            $this->assertTrue(
                method_exists(RedisSessionHandler::class, $method),
                "RedisSessionHandler is missing required method: $method"
            );
        }
    }

    public function testHasGetRedisAccessor(): void
    {
        $this->assertTrue(
            method_exists(RedisSessionHandler::class, 'getRedis'),
            'getRedis() accessor is part of the documented surface'
        );
    }

    public function testConstructorSignatureAcceptsDefaults(): void
    {
        // Verify the constructor's parameter list — pure reflection, no
        // instantiation needed (which would trigger Redis connect()).
        $ref = new \ReflectionMethod(RedisSessionHandler::class, '__construct');
        $params = $ref->getParameters();
        $this->assertCount(4, $params, 'constructor should take host, port, prefix, ttl');
        $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $params);
        $this->assertSame(['host', 'port', 'prefix', 'ttl'], $names);
        foreach ($params as $p) {
            $this->assertTrue($p->isDefaultValueAvailable(), "{$p->getName()} should have a default");
        }
    }

    // ──────────────────────────────────────────────────────────────
    // Connected behaviour — skipped unless Redis is reachable
    // ──────────────────────────────────────────────────────────────

    private function skipIfNoRedis(): RedisSessionHandler
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not loaded — connected tests skipped.');
        }
        try {
            $h = new RedisSessionHandler('127.0.0.1', 6379, 'PHPREDIS_SESSION_TEST:', 60);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not reachable at 127.0.0.1:6379 — connected tests skipped: ' . $e->getMessage());
        }
        return $h;
    }

    public function testOpenReturnsTrueOnConnected(): void
    {
        $h = $this->skipIfNoRedis();
        $this->assertTrue($h->open('', 'PHPSESSID'));
        $h->close();
    }

    public function testReadOnMissingKeyReturnsEmptyString(): void
    {
        $h = $this->skipIfNoRedis();
        $h->open('', 'PHPSESSID');
        $sid = 'test_' . bin2hex(random_bytes(8));
        $this->assertSame('', $h->read($sid));
        $h->close();
    }

    public function testWriteThenReadRoundTrip(): void
    {
        $h = $this->skipIfNoRedis();
        $h->open('', 'PHPSESSID');
        $sid     = 'test_' . bin2hex(random_bytes(8));
        $payload = 'user_id|i:42;name|s:5:"alice";';
        $this->assertTrue($h->write($sid, $payload));
        $this->assertSame($payload, $h->read($sid));
        $h->destroy($sid);
        $h->close();
    }

    public function testDestroyRemovesKey(): void
    {
        $h = $this->skipIfNoRedis();
        $h->open('', 'PHPSESSID');
        $sid = 'test_' . bin2hex(random_bytes(8));
        $h->write($sid, 'tmp|i:1;');
        $this->assertSame('tmp|i:1;', $h->read($sid));
        $this->assertTrue($h->destroy($sid));
        $this->assertSame('', $h->read($sid));
        $h->close();
    }

    public function testKeyPrefixIsApplied(): void
    {
        $h = $this->skipIfNoRedis();
        $h->open('', 'PHPSESSID');
        $sid = 'test_' . bin2hex(random_bytes(8));
        $h->write($sid, 'x|i:1;');
        // Verify the underlying key includes the prefix — the whole point
        // of the configurable prefix is phpredis compatibility.
        $raw = $h->getRedis()->get('PHPREDIS_SESSION_TEST:' . $sid);
        $this->assertSame('x|i:1;', $raw);
        $h->destroy($sid);
        $h->close();
    }

    public function testGcReturnsZero(): void
    {
        $h = $this->skipIfNoRedis();
        $h->open('', 'PHPSESSID');
        // Redis handles expiry via TTL — gc() is documented as a no-op.
        $this->assertSame(0, $h->gc(0));
        $h->close();
    }
}
