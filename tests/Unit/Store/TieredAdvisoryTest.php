<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Coroutine;
use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\TableBackend;
use ZealPHP\Store\TieredBackend;

/**
 * Covers the Finding-2 advisory seam: `Store::tieredAdvisory()` /
 * `Store::tieredBootChecks()` surface the SILENT cross-node L1 coherence gap
 * (invalidation OFF by default) and the unauthenticated-invalidation gap
 * (no HMAC secret). Pure unit tests — no live Redis needed for the
 * advisory decision (it inspects the backend's enable/secret flags, which
 * are set at construction; the L2 RedisBackend is never dialed).
 */
final class TieredAdvisoryTest extends TestCase
{
    private function l2(): RedisBackend
    {
        // Pool is lazy — never connects unless an op runs. The advisory path
        // doesn't touch L2, so a dummy URL is fine.
        return new RedisBackend(new RedisConnectionPool('redis://127.0.0.1:6379', 1), 'zptest');
    }

    private function tiered(?string $secret = null): TieredBackend
    {
        return new TieredBackend(new TableBackend(), $this->l2(), l1Ttl: 5, invalidationSecret: $secret);
    }

    public function testAdvisoryPresentWhenInvalidationDisabledAndNoSecret(): void
    {
        $advisory = Store::tieredAdvisory($this->tiered());
        $this->assertIsString($advisory);
        $this->assertStringContainsString('cross-node L1 invalidation is OFF', $advisory);
        $this->assertStringContainsString('enableInvalidation()', $advisory);
        $this->assertStringContainsString('BOOT-ORDER', $advisory);
        // Mentions the staleness window so the operator sees the impact.
        $this->assertStringContainsString('5s', $advisory);
    }

    public function testAdvisoryStillPresentWhenDisabledEvenWithSecret(): void
    {
        // A secret set but invalidation never enabled is still a coherence gap.
        $advisory = Store::tieredAdvisory($this->tiered('s3cr3t'));
        $this->assertIsString($advisory);
        $this->assertStringContainsString('cross-node L1 invalidation is OFF', $advisory);
    }

    public function testAdvisoryWarnsUnauthenticatedWhenEnabledWithoutSecret(): void
    {
        Coroutine::run(function (): void {
            $b = $this->tiered(); // no secret
            $b->enableInvalidation();
            $advisory = Store::tieredAdvisory($b);
            $this->assertIsString($advisory);
            $this->assertStringContainsString('UNAUTHENTICATED', $advisory);
            $this->assertStringContainsString('forge', $advisory);
            $b->stopInvalidation();
        });
    }

    public function testAdvisoryAbsentWhenEnabledWithSecret(): void
    {
        Coroutine::run(function (): void {
            $b = $this->tiered('s3cr3t');
            $b->enableInvalidation();
            $this->assertNull(Store::tieredAdvisory($b));
            $b->stopInvalidation();
        });
    }

    public function testAdvisoryAbsentOnTableBackend(): void
    {
        $this->assertNull(Store::tieredAdvisory(new TableBackend()));
    }

    public function testAdvisoryAbsentOnPlainRedisBackend(): void
    {
        $this->assertNull(Store::tieredAdvisory($this->l2()));
    }

    public function testBootChecksReturnsAdvisoryListShape(): void
    {
        // tieredBootChecks() returns 0-or-1 element list mirroring redisBootChecks().
        $disabled = $this->tiered();
        $list = $this->bootChecksFor($disabled);
        $this->assertCount(1, $list);
        $this->assertStringContainsString('cross-node L1 invalidation is OFF', $list[0]);
    }

    public function testBootChecksEmptyWhenNoAdvisory(): void
    {
        $this->assertSame([], $this->bootChecksFor(new TableBackend()));
    }

    /**
     * tieredBootChecks() reads Store::defaultBackend(), so reflect-inject the
     * backend under test, call the seam, then restore the default — keeps the
     * process-wide Store state untouched for other tests.
     *
     * @return list<string>
     */
    private function bootChecksFor(\ZealPHP\Store\StoreBackend $backend): array
    {
        $ref = new \ReflectionClass(Store::class);
        $prop = $ref->getProperty('backend');
        $prop->setAccessible(true);
        $cfgProp = $ref->getProperty('backendConfig');
        $cfgProp->setAccessible(true);

        $prevBackend = $prop->getValue();
        $prevCfg = $cfgProp->getValue();
        $prop->setValue(null, $backend);
        try {
            return Store::tieredBootChecks();
        } finally {
            $prop->setValue(null, $prevBackend);
            $cfgProp->setValue(null, $prevCfg);
        }
    }
}
