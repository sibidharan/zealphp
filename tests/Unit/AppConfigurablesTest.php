<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\App;
use ZealPHP\RequestContext;

/**
 * Unit tests for the Apache-parity configurables added under §9 of the
 * full-parity plan: $strip_trailing_slash, $server_admin, $canonical_name,
 * $use_canonical_name, $hostname_lookups, $trusted_proxies + App::clientIp(),
 * $access_log_format + App::formatAccessLogLine(), $limit_request_* properties,
 * and App::tryInclude().
 *
 * Pure unit tests — no running server required. Mutate $g->server directly to
 * exercise the helpers; restore defaults in tearDown so test order is irrelevant.
 */
class AppConfigurablesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Coroutine mode so we can write directly to RequestContext properties
        // instead of bouncing through $_SERVER. App::init() is required so the
        // singleton exists for App::clientIp()'s $g lookup.
        App::superglobals(false);
        if (App::instance() === null) {
            App::init('0.0.0.0', 19998, ZEALPHP_ROOT);
        }
    }

    public static function tearDownAfterClass(): void
    {
        // CRITICAL: setUpBeforeClass()'s App::superglobals(false) triggered
        // OpenSwoole\Runtime::enableCoroutine(HOOK_ALL) (per App::init() in
        // src/App.php:234-237), which intercepts native curl + file I/O and
        // requires every such call to live inside a coroutine. Without this
        // teardown, ANY downstream Integration test using TestCase->http()
        // (curl_exec under the hood) fatals with "OpenSwoole\Error: API must
        // be called in the coroutine". Restore the pre-test state.
        \OpenSwoole\Runtime::enableCoroutine(0);
        App::superglobals(true);
    }

    protected function setUp(): void
    {
        // Reset every configurable to its documented default before each test.
        App::stripTrailingSlash(false);
        App::serverAdmin(null);
        App::canonicalName(null);
        App::useCanonicalName(false);
        App::hostnameLookups(false);
        App::trustedProxies([]);
        App::accessLogFormat('%h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i"');
        App::limitRequestFields(100);
        App::limitRequestFieldSize(8190);
        App::limitRequestLine(8190);

        // Clear any leftover server vars from previous tests.
        $g = RequestContext::instance();
        $g->server = [];
        $g->session = [];
    }

    // ─────────────────────────────────────────────────────────────
    // stripTrailingSlash() — fluent setter only (redirect is integration)
    // ─────────────────────────────────────────────────────────────

    public function testStripTrailingSlashDefaultOff(): void
    {
        $this->assertFalse(App::stripTrailingSlash());
        $this->assertFalse(App::$strip_trailing_slash);
    }

    public function testStripTrailingSlashSetterRoundtrips(): void
    {
        // Setter returns the new value (the canonical superglobals() convention).
        $this->assertTrue(App::stripTrailingSlash(true));
        $this->assertTrue(App::stripTrailingSlash());
        $this->assertTrue(App::$strip_trailing_slash);
        $this->assertFalse(App::stripTrailingSlash(false));
    }

    // ─────────────────────────────────────────────────────────────
    // serverAdmin()
    // ─────────────────────────────────────────────────────────────

    public function testServerAdminDefaultNull(): void
    {
        $this->assertNull(App::serverAdmin());
    }

    public function testServerAdminSetAndClear(): void
    {
        App::serverAdmin('ops@example.com');
        $this->assertSame('ops@example.com', App::serverAdmin());
        // Empty string normalises to null (consistent "clear" semantics).
        App::serverAdmin('');
        $this->assertNull(App::serverAdmin());
    }

    // ─────────────────────────────────────────────────────────────
    // canonicalName() + useCanonicalName() + canonicalHost()
    // ─────────────────────────────────────────────────────────────

    public function testCanonicalHostFallsBackToHostHeader(): void
    {
        $g = RequestContext::instance();
        $g->server = ['HTTP_HOST' => 'client.example.com'];
        $this->assertSame('client.example.com', App::canonicalHost());
    }

    public function testCanonicalHostFallsBackToServerName(): void
    {
        $g = RequestContext::instance();
        $g->server = ['SERVER_NAME' => 'srv.example.com'];
        $this->assertSame('srv.example.com', App::canonicalHost());
    }

    public function testCanonicalHostRespectsUseCanonicalName(): void
    {
        $g = RequestContext::instance();
        $g->server = ['HTTP_HOST' => 'client.example.com'];
        App::canonicalName('canonical.example.com:443');
        App::useCanonicalName(true);
        $this->assertSame('canonical.example.com:443', App::canonicalHost());
    }

    public function testCanonicalHostIgnoresCanonicalNameWhenUseIsOff(): void
    {
        $g = RequestContext::instance();
        $g->server = ['HTTP_HOST' => 'client.example.com'];
        App::canonicalName('canonical.example.com');
        // useCanonicalName left at default false
        $this->assertSame('client.example.com', App::canonicalHost());
    }

    // ─────────────────────────────────────────────────────────────
    // hostnameLookups()
    // ─────────────────────────────────────────────────────────────

    public function testHostnameLookupsDefaultOff(): void
    {
        $this->assertFalse(App::hostnameLookups());
    }

    public function testHostnameLookupsRoundtrip(): void
    {
        App::hostnameLookups(true);
        $this->assertTrue(App::hostnameLookups());
        $this->assertTrue(App::$hostname_lookups);
    }

    // ─────────────────────────────────────────────────────────────
    // trustedProxies() + App::clientIp() + CIDR matcher
    // ─────────────────────────────────────────────────────────────

    public function testClientIpReturnsRemoteAddrWhenNoProxiesTrusted(): void
    {
        $g = RequestContext::instance();
        $g->server = [
            'REMOTE_ADDR' => '203.0.113.42',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1, 203.0.113.42',  // ignored — peer untrusted
        ];
        $this->assertSame('203.0.113.42', App::clientIp());
    }

    public function testClientIpReturnsEmptyWhenRemoteAddrMissing(): void
    {
        $g = RequestContext::instance();
        $g->server = [];
        $this->assertSame('', App::clientIp());
    }

    public function testClientIpWalksXForwardedForWhenPeerIsTrusted(): void
    {
        App::trustedProxies(['10.0.0.0/8']);
        $g = RequestContext::instance();
        $g->server = [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.6',
        ];
        // 10.0.0.6 (rightmost) IS in trusted_proxies → skipped.
        // 198.51.100.7 (next-right) NOT trusted → returned.
        $this->assertSame('198.51.100.7', App::clientIp());
    }

    public function testClientIpFallsBackToFirstHopWhenAllTrusted(): void
    {
        App::trustedProxies(['10.0.0.0/8', '192.168.0.0/16']);
        $g = RequestContext::instance();
        $g->server = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.5, 10.0.0.2',
        ];
        $this->assertSame('192.168.1.5', App::clientIp());
    }

    public function testClientIpUsesXRealIpWhenForwardedForMissing(): void
    {
        App::trustedProxies(['10.0.0.0/8']);
        $g = RequestContext::instance();
        $g->server = [
            'REMOTE_ADDR'      => '10.0.0.5',
            'HTTP_X_REAL_IP'   => '203.0.113.99',
        ];
        $this->assertSame('203.0.113.99', App::clientIp());
    }

    public function testClientIpHandlesIPv6CidrTrustedProxy(): void
    {
        App::trustedProxies(['2001:db8::/32']);
        $g = RequestContext::instance();
        $g->server = [
            'REMOTE_ADDR' => '2001:db8::1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        ];
        $this->assertSame('203.0.113.10', App::clientIp());
    }

    public function testClientIpRejectsCrossFamilyCidrMatch(): void
    {
        // IPv4 peer must NOT match an IPv6 CIDR — strlen guard.
        App::trustedProxies(['::/0']);
        $g = RequestContext::instance();
        $g->server = [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.5',
        ];
        // Peer is NOT trusted (different family) → X-Forwarded-For ignored.
        $this->assertSame('203.0.113.10', App::clientIp());
    }

    public function testClientIpBareHostInTrustedProxies(): void
    {
        // Bare IP (no /prefix) is treated as /32 single-host.
        App::trustedProxies(['10.0.0.5']);
        $g = RequestContext::instance();

        $g->server = [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ];
        $this->assertSame('203.0.113.7', App::clientIp());

        // Sibling IP NOT covered by the bare /32 → untrusted.
        $g->server = [
            'REMOTE_ADDR' => '10.0.0.6',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ];
        $this->assertSame('10.0.0.6', App::clientIp());
    }

    public function testTrustedProxiesGetterReturnsCurrent(): void
    {
        App::trustedProxies(['10.0.0.0/8', '192.168.0.0/16']);
        $this->assertSame(['10.0.0.0/8', '192.168.0.0/16'], App::trustedProxies());
    }

    // ─────────────────────────────────────────────────────────────
    // accessLogFormat() + App::formatAccessLogLine()
    // ─────────────────────────────────────────────────────────────

    public function testAccessLogFormatDefaultIsCombined(): void
    {
        $this->assertStringContainsString('%h', App::accessLogFormat());
        $this->assertStringContainsString('%{Referer}i', App::accessLogFormat());
    }

    public function testFormatAccessLogLineWithSimpleSpec(): void
    {
        App::accessLogFormat('%h %m %U %>s %b');
        $g = RequestContext::instance();
        $g->server = [
            'REMOTE_ADDR'     => '203.0.113.1',
            'REQUEST_METHOD'  => 'GET',
            'REQUEST_URI'     => '/foo?x=1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ];
        $line = App::formatAccessLogLine(200, 1234);
        $this->assertSame('203.0.113.1 GET /foo 200 1234', $line);
    }

    public function testFormatAccessLogLineEmitsDashForZeroBytesInClf(): void
    {
        App::accessLogFormat('%b %B');
        $g = RequestContext::instance();
        $g->server = ['REMOTE_ADDR' => '127.0.0.1'];
        // %b → '-' when zero (CLF), %B → '0' when zero.
        $this->assertSame('- 0', App::formatAccessLogLine(204, 0));
    }

    public function testFormatAccessLogLineRendersHeaderToken(): void
    {
        App::accessLogFormat('%{Referer}i');
        $g = RequestContext::instance();
        $g->server = [
            'HTTP_REFERER' => 'https://example.com/page',
        ];
        $this->assertSame('https://example.com/page', App::formatAccessLogLine(200, 0));
    }

    public function testFormatAccessLogLineRendersDurationTokens(): void
    {
        App::accessLogFormat('%D %T');
        $g = RequestContext::instance();
        $g->server = [];
        $this->assertSame('1234500 1', App::formatAccessLogLine(200, 0, 1.2345));
        $this->assertSame('- -', App::formatAccessLogLine(200, 0, null));
    }

    public function testAccessLogFormatSetterInvalidatesCompiledCache(): void
    {
        // First call compiles & caches '%h'.
        App::accessLogFormat('%h');
        $g = RequestContext::instance();
        $g->server = ['REMOTE_ADDR' => '10.0.0.1'];
        $this->assertSame('10.0.0.1', App::formatAccessLogLine(200, 0));

        // Reassign — cache must be invalidated, new spec must take effect.
        App::accessLogFormat('STATUS=%>s');
        $this->assertSame('STATUS=418', App::formatAccessLogLine(418, 0));
    }

    public function testFormatAccessLogLinePassesUnknownDirectiveThrough(): void
    {
        App::accessLogFormat('%z %h');  // %z is unknown
        $g = RequestContext::instance();
        $g->server = ['REMOTE_ADDR' => '10.0.0.1'];
        $this->assertSame('%z 10.0.0.1', App::formatAccessLogLine(200, 0));
    }

    // ─────────────────────────────────────────────────────────────
    // Request header limit setters
    // ─────────────────────────────────────────────────────────────

    public function testRequestHeaderLimitDefaults(): void
    {
        $this->assertSame(100,  App::limitRequestFields());
        $this->assertSame(8190, App::limitRequestFieldSize());
        $this->assertSame(8190, App::limitRequestLine());
    }

    public function testRequestHeaderLimitSettersClampToNonNegative(): void
    {
        App::limitRequestFields(-1);
        $this->assertSame(0, App::limitRequestFields());
        App::limitRequestFieldSize(16384);
        $this->assertSame(16384, App::limitRequestFieldSize());
    }

    // ─────────────────────────────────────────────────────────────
    // App::tryInclude() — file-missing returns null, not 403
    // ─────────────────────────────────────────────────────────────

    public function testTryIncludeReturnsNullWhenFileMissing(): void
    {
        $this->assertNull(App::tryInclude('/this-definitely-does-not-exist.php'));
    }

    public function testTryIncludeReturnsNullForMissingNestedPath(): void
    {
        $this->assertNull(App::tryInclude('/nope/also-missing.php'));
    }
}
