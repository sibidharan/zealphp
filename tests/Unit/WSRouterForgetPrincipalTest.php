<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\WSRouter;

/**
 * The framework's close handler drops a closed fd's principal, so an app that
 * never calls release() can't leave a reused fd holding the last user's identity.
 */
class WSRouterForgetPrincipalTest extends TestCase
{
    public function testForgetPrincipalClearsOnlyThatFd(): void
    {
        $map = new \ReflectionProperty(WSRouter::class, 'principalByFd');
        $map->setValue(null, [41 => 'alice', 42 => 'bob']);
        WSRouter::forgetPrincipal(41);
        $this->assertNull(WSRouter::principalForFd(41));
        $this->assertSame('bob', WSRouter::principalForFd(42));
        $map->setValue(null, []);
    }
}
