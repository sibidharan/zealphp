<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function ZealPHP\cgroup_cpu_quota;
use function ZealPHP\default_worker_count;

/**
 * Container-aware default worker count — a bare `php app.php` must NOT fall back
 * to OpenSwoole's swoole_cpu_num() = HOST cpu count, which over-spawns in a
 * cgroup-CPU-limited Docker container (e.g. 24 workers on a 6-CPU container).
 */
class DefaultWorkerCountTest extends TestCase
{
    public function testNeverExceedsThePreferredCount(): void
    {
        $this->assertLessThanOrEqual(4, default_worker_count(4));
        $this->assertGreaterThanOrEqual(1, default_worker_count(4));
    }

    public function testPreferredIsFlooredToAtLeastOne(): void
    {
        $this->assertSame(1, default_worker_count(0));
        $this->assertSame(1, default_worker_count(-5));
    }

    public function testQuotaIsNullOrPositive(): void
    {
        $q = cgroup_cpu_quota();
        $this->assertTrue($q === null || $q > 0, 'cgroup quota must be null or a positive float');
    }

    public function testCapsToCgroupQuotaWhenItIsBelowPreferred(): void
    {
        $q = cgroup_cpu_quota();
        if ($q === null || $q < 1.0) {
            // No cgroup CPU limit → an unconstrained preferred passes through.
            $this->assertSame(8, default_worker_count(8));
            return;
        }
        // A huge preferred is capped to the cgroup quota, never the host cpus.
        $this->assertSame((int) floor($q), default_worker_count(100000));
        $this->assertLessThanOrEqual((int) floor($q), default_worker_count(100000));
    }
}
