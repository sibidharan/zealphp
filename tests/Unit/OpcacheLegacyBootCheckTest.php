<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * `App::opcacheLegacyBootCheck()` emits a boot advisory when opcache is enabled
 * under coroutine-legacy (Stage 7 re-executes require_once'd files, so a warm
 * opcache re-copies symbols into a table that already has them).
 *
 * The advisory STRING is built by the pure seam `App::opcacheLegacyAdvisory()`
 * so both `dups_fix` branches are testable without opcache enabled in the SAPI
 * (the phpunit CLI SAPI has no opcache, so the full boot check early-returns
 * null there — only the early-return guards are exercised through it).
 */
final class OpcacheLegacyBootCheckTest extends TestCase
{
    private bool $savedSilent;

    protected function setUp(): void
    {
        $this->savedSilent = App::$silent_redeclare;
    }

    protected function tearDown(): void
    {
        App::$silent_redeclare = $this->savedSilent;
        putenv('ZEALPHP_OPCACHE_ADVISORY');
    }

    public function testAdvisoryWithDupsFixOffExplainsBothCases(): void
    {
        $msg = App::opcacheLegacyAdvisory(false, '/var/www/public');

        $this->assertStringContainsString('opcache.dups_fix is', $msg);
        $this->assertStringContainsString('Set opcache.dups_fix=1', $msg);   // CLASS fix
        $this->assertStringContainsString('FUNCTIONS', $msg);                // function-case caveat
        $this->assertStringContainsString('/var/www/public/', $msg);         // blacklist hint uses docRoot
        $this->assertStringContainsString('ZEALPHP_OPCACHE_ADVISORY=0', $msg);
    }

    public function testAdvisoryWithDupsFixOnNotesUnpatchedFunctionCase(): void
    {
        $msg = App::opcacheLegacyAdvisory(true, '/app');

        $this->assertStringContainsString('class redeclares are', $msg);
        $this->assertStringContainsString('Cannot redeclare function', $msg); // remaining function case
        $this->assertStringContainsString('/app/', $msg);
        $this->assertStringNotContainsString('Set opcache.dups_fix=1', $msg);  // not the OFF branch
    }

    public function testBootCheckReturnsNullOutsideCoroutineLegacy(): void
    {
        App::$silent_redeclare = false;   // only coroutine-legacy re-executes require_once'd files
        $this->assertNull(App::opcacheLegacyBootCheck());
    }

    public function testBootCheckSuppressedByEnvFlag(): void
    {
        App::$silent_redeclare = true;
        putenv('ZEALPHP_OPCACHE_ADVISORY=0');
        $this->assertNull(App::opcacheLegacyBootCheck());
    }
}
