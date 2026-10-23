<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * coroutine-legacy refuses an ext-zealphp older than the first release with
 * the PHP2Shell-audit isolation fixes (App::legacyExtVersionAdvisory()).
 */
class LegacyExtVersionAdvisoryTest extends TestCase
{
    public function testOutdatedExtInCoroutineLegacyIsRefused(): void
    {
        $msg = App::legacyExtVersionAdvisory(true, true, '0.3.60');
        $this->assertNotNull($msg);
        $this->assertStringContainsString(App::MIN_EXT_VERSION_COROUTINE_LEGACY, $msg);
        $this->assertStringContainsString('0.3.60', $msg);
    }

    public function testCurrentAndNewerExtArePassed(): void
    {
        $this->assertNull(App::legacyExtVersionAdvisory(true, true, App::MIN_EXT_VERSION_COROUTINE_LEGACY));
        $this->assertNull(App::legacyExtVersionAdvisory(true, true, '0.4.0'));
    }

    public function testOtherModesAreNotGated(): void
    {
        $this->assertNull(App::legacyExtVersionAdvisory(false, true, '0.3.1'), 'coroutine mode keeps state per coroutine in PHP');
        $this->assertNull(App::legacyExtVersionAdvisory(true, false, '0.3.1'), 'mixed / sequential modes do not swap state');
    }

    public function testMissingExtIsLeftToTheExistingRefusal(): void
    {
        $this->assertNull(App::legacyExtVersionAdvisory(true, true, false));
    }
}
