<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Table;
use PHPUnit\Framework\TestCase;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\StoreException;

/**
 * H1: RedisBackend::make() rejects silently-broken opts combos.
 *
 * Pre-v0.2.41 a tracked-mode table with a non-zero TTL was accepted; the
 * TTL was silently ignored because the membership SET would drift if
 * keys expired. Surfaces at boot now.
 */
final class RedisBackendMakeValidationTest extends TestCase
{
    private function backend(): RedisBackend
    {
        // The pool isn't actually used for the make()-level validation we're testing.
        return new RedisBackend(new RedisConnectionPool('redis://127.0.0.1:6379'));
    }

    public function testTrackedModeDefaultIsAccepted(): void
    {
        $this->backend()->make('t1', 1024, ['v' => [Table::TYPE_STRING, 32]]);
        $this->expectNotToPerformAssertions();
    }

    public function testTrackedModeWithExplicitTtlZeroIsAccepted(): void
    {
        $this->backend()->make('t2', 1024, ['v' => [Table::TYPE_STRING, 32]], ['mode' => 'tracked', 'ttl' => 0]);
        $this->expectNotToPerformAssertions();
    }

    public function testTrackedModeWithPositiveTtlThrows(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches('/tracked.*does not support TTL/');
        $this->backend()->make('t3', 1024, ['v' => [Table::TYPE_STRING, 32]], ['mode' => 'tracked', 'ttl' => 60]);
    }

    public function testTtlModeWithPositiveTtlIsAccepted(): void
    {
        $this->backend()->make('t4', 1024, ['v' => [Table::TYPE_STRING, 32]], ['mode' => 'ttl', 'ttl' => 3600]);
        $this->expectNotToPerformAssertions();
    }

    public function testTtlModeWithZeroTtlThrows(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches("/'ttl' mode requires/");
        $this->backend()->make('t5', 1024, ['v' => [Table::TYPE_STRING, 32]], ['mode' => 'ttl', 'ttl' => 0]);
    }

    public function testUnknownModeThrows(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches("/unknown mode/");
        $this->backend()->make('t6', 1024, ['v' => [Table::TYPE_STRING, 32]], ['mode' => 'lru', 'ttl' => 60]);
    }
}
