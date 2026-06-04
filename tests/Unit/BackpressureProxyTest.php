<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Architecture-review hardening: the front-door backpressure advisory and the
 * trusted-proxy gate on HTTPS scheme detection (X-Forwarded-Proto spoofing).
 */
final class BackpressureProxyTest extends TestCase
{
    /** @var array<int, string> */
    private static array $origProxies = [];

    public static function setUpBeforeClass(): void
    {
        self::$origProxies = App::$trusted_proxies;
    }

    protected function tearDown(): void
    {
        App::$trusted_proxies = self::$origProxies;
    }

    // ── backpressureBootAdvisory ──────────────────────────────────

    public function testNoAdvisoryWhenCeilingIsBounded(): void
    {
        $this->assertNull(App::backpressureBootAdvisory(
            ['max_coroutine' => App::DEFAULT_MAX_COROUTINE, 'worker_num' => 4]
        ));
        $this->assertNull(App::backpressureBootAdvisory(['max_coroutine' => 50000]));
    }

    public function testAdvisoryWhenCeilingRaisedToUnbounded(): void
    {
        $advisory = App::backpressureBootAdvisory(['max_coroutine' => 100000, 'worker_num' => 4]);
        $this->assertIsString($advisory);
        $this->assertStringContainsString('max_coroutine=100000', $advisory);
        $this->assertStringContainsString('(x4 workers)', $advisory);
        $this->assertStringContainsString((string) App::DEFAULT_MAX_COROUTINE, $advisory);
    }

    public function testNoAdvisoryWhenMaxCoroutineAbsentOrNonInt(): void
    {
        $this->assertNull(App::backpressureBootAdvisory([]));
        $this->assertNull(App::backpressureBootAdvisory(['max_coroutine' => 'lots']));
    }

    // ── requestIsHttps trusted-proxy gate ─────────────────────────

    public function testXForwardedProtoHonouredFromTrustedProxy(): void
    {
        App::$trusted_proxies = ['10.0.0.0/8'];
        $this->assertTrue(App::requestIsHttps(
            ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'https']
        ));
    }

    public function testXForwardedProtoIgnoredFromUntrustedClient(): void
    {
        App::$trusted_proxies = ['10.0.0.0/8'];
        // Spoofed XFP from a non-proxy peer must NOT flip the scheme — falls
        // through to the real SERVER_PORT (80) → not HTTPS.
        $this->assertFalse(App::requestIsHttps(
            ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https', 'SERVER_PORT' => '80']
        ));
    }

    public function testXForwardedProtoIgnoredWhenNoTrustedProxiesConfigured(): void
    {
        App::$trusted_proxies = [];
        $this->assertFalse(App::requestIsHttps(
            ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'https', 'SERVER_PORT' => '80']
        ));
    }

    public function testRealHttpsAndPort443AlwaysDetected(): void
    {
        App::$trusted_proxies = [];
        $this->assertTrue(App::requestIsHttps(['HTTPS' => 'on']));
        $this->assertTrue(App::requestIsHttps(['SERVER_PORT' => '443']));
        $this->assertFalse(App::requestIsHttps(['SERVER_PORT' => '80']));
    }
}
