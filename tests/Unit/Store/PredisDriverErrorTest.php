<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store\PredisDriver;
use ZealPHP\Store\RedisClient;
use ZealPHP\Store\StoreException;

/**
 * Drives PredisDriver's connect-error path + the explicit driver-prefer
 * paths in RedisClient::pickDriver. Both exercise lines the happy-path
 * RedisClientTest can't reach (which assumes a reachable valkey).
 */
final class PredisDriverErrorTest extends TestCase
{
    public function testConstructorWrapsPredisConnectFailureAsStoreException(): void
    {
        // Port 1 is the dead-port convention used elsewhere in the suite —
        // reliably refused, even on locked-down hosts.
        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches('/predis connect failed/');
        new PredisDriver('redis://127.0.0.1:1');
    }

    public function testRedisClientWrapsPredisConnectFailure(): void
    {
        // Force predis (instead of auto so we hit PredisDriver explicitly).
        $this->expectException(StoreException::class);
        new RedisClient('redis://127.0.0.1:1', ['prefer' => 'predis']);
    }

    public function testRedisClientForcedPhpredisGracefullyFailsWhenAbsent(): void
    {
        if (extension_loaded('redis')) {
            $this->markTestSkipped('phpredis IS loaded; this test only exercises the missing-ext path');
        }
        $this->expectException(StoreException::class);
        // prefer=phpredis with the ext NOT loaded — PhpredisDriver ctor throws.
        new RedisClient('redis://127.0.0.1:16379/0', ['prefer' => 'phpredis']);
    }

    public function testRedisClientForcedPredisWorks(): void
    {
        // Round-trip via an explicit predis selection — exercises the
        // 'predis' branch of pickDriver() distinct from 'auto'.
        $url = (string) getenv('ZEALPHP_REDIS_URL');
        if ($url === '') { $url = 'redis://127.0.0.1:16379/0'; }
        try {
            $c = new \Predis\Client($url); $c->ping(); $c->disconnect();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis/Valkey not available at ' . $url);
        }
        $client = new RedisClient($url, ['prefer' => 'predis']);
        $this->assertSame('predis', $client->driverName());
        $this->assertTrue($client->ping());
        $client->close();
    }
}
