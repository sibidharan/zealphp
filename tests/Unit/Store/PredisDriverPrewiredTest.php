<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use Predis\Client as PredisClient;
use ZealPHP\Store\PredisDriver;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * Validates the pre-wired Predis\Client escape hatch — the path users
 * follow to drive Redis Cluster or Redis Sentinel configurations.
 * In both topologies, predis is configured via a multi-node parameters
 * array + an `cluster`/`replication` option that the standard URL form
 * can't express, so the driver accepts a fully-constructed
 * `Predis\Client` and uses it as-is.
 */
final class PredisDriverPrewiredTest extends RedisTestCase
{
    public function testDriverAcceptsAPreBuiltPredisClient(): void
    {
        $client = new PredisClient($this->url);
        $driver = new PredisDriver($client);

        // Round-trip a value through the pre-wired driver to confirm it
        // actually owns the connection we passed in.
        $driver->set('prewired:test', 'hello');
        $this->assertSame('hello', $driver->get('prewired:test'));
        $this->assertSame(1, $driver->del('prewired:test'));
        $this->assertNull($driver->get('prewired:test'));
    }

    public function testDriverStillAcceptsAUrlString(): void
    {
        $driver = new PredisDriver($this->url);
        $this->assertTrue($driver->ping());
        $driver->close();
    }
}
