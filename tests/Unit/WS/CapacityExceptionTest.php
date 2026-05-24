<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\WS;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store\StoreException;
use ZealPHP\WS\CapacityException;

/**
 * Pins the CapacityException type hierarchy + message round-trip.
 *
 * CapacityException MUST stay an extension of StoreException so existing
 * `catch (StoreException)` blocks in user code keep working — new code
 * gets to pattern-match on the specific subtype for differentiated
 * recovery (cap-exceeded → 1013 close; transient Redis failure → retry).
 */
final class CapacityExceptionTest extends TestCase
{
    public function testCapacityExceptionInheritsStoreException(): void
    {
        $parent = (new \ReflectionClass(CapacityException::class))->getParentClass();
        $this->assertNotFalse($parent, 'CapacityException must extend a parent class');
        $this->assertSame(StoreException::class, $parent->getName());
    }

    public function testCapacityExceptionMessageRoundTrips(): void
    {
        $e = new CapacityException('table full at 256 connections');
        $this->assertStringContainsString('256 connections', $e->getMessage());
    }

    public function testCapacityExceptionIsCatchableAsStoreException(): void
    {
        try {
            throw new CapacityException('ws_owner table full');
        } catch (StoreException $e) {
            // The whole point of the inheritance: legacy catches still
            // grab the specific subtype.
            $this->assertInstanceOf(CapacityException::class, $e);
        }
    }
}
