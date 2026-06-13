<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\CGI\WorkerPool;
use ZealPHP\Middleware\IpAccessMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\Unit\HTTP\FakeOpenSwooleResponse;
use ZealPHP\Tests\TestCase;

/**
 * Regression tests for the security-audit batch (@Guruprasanth-M): #248 CIDR
 * fail-open, #249 forgeable leftmost XFF, #250 access-log CRLF injection, #243
 * open redirect, #257 CGI pool env leak.
 */
final class SecurityHardeningAuditTest extends TestCase
{
    /** @var array<int, string> */
    private static array $origProxies = [];
    private static string $origLogFormat = '';

    public static function setUpBeforeClass(): void
    {
        self::$origProxies   = App::$trusted_proxies;
        self::$origLogFormat = App::accessLogFormat();
    }

    protected function tearDown(): void
    {
        App::$trusted_proxies = self::$origProxies;
        App::accessLogFormat(self::$origLogFormat); // also resets the compiled cache
        App::$cgi_pool_env_allowlist = [];
    }

    private function callPrivate(object|string $target, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($target, $method);
        $ref->setAccessible(true);
        return $ref->invoke(is_object($target) ? $target : null, ...$args);
    }

    // ── #248 — CIDR matchers fail CLOSED on a malformed prefix ─────

    public function testCidrContainsFailsClosedOnMalformedPrefix(): void
    {
        $this->assertFalse($this->callPrivate(App::class, 'cidrContains', '10.0.0.0/abc', '8.8.8.8'));
        $this->assertFalse($this->callPrivate(App::class, 'cidrContains', '10.0.0.0/', '8.8.8.8'));
        // Legit masks still work.
        $this->assertTrue($this->callPrivate(App::class, 'cidrContains', '10.0.0.0/8', '10.6.6.6'));
        $this->assertFalse($this->callPrivate(App::class, 'cidrContains', '10.0.0.0/8', '11.0.0.1'));
        $this->assertTrue($this->callPrivate(App::class, 'cidrContains', '0.0.0.0/0', '1.2.3.4')); // explicit match-all is fine
    }

    public function testIpAccessCidrMatchFailsClosedOnMalformedPrefix(): void
    {
        $mw = new IpAccessMiddleware(['allow' => ['10.0.0.0/abc']]);
        $this->assertFalse($this->callPrivate($mw, 'cidrMatch', '8.8.8.8', '10.0.0.0/abc'));
        $this->assertFalse($this->callPrivate($mw, 'cidrMatch', '8.8.8.8', '10.0.0.0/'));
        $this->assertFalse($this->callPrivate($mw, 'cidrMatch', '8.8.8.8', '10.0.0.0'));     // no slash → fail closed
        $this->assertFalse($this->callPrivate($mw, 'cidrMatch', '8.8.8.8', '10.0.0.0/99'));  // out-of-range prefix
        $this->assertTrue($this->callPrivate($mw, 'cidrMatch', '10.6.6.6', '10.0.0.0/8'));   // control
    }

    // ── #249 — clientIp() must not promote the forgeable leftmost XFF ──

    public function testClientIpReturnsSocketPeerWhenAllHopsTrusted(): void
    {
        App::$trusted_proxies = ['10.0.0.0/8'];
        $g = RequestContext::instance();
        $g->server = [
            'REMOTE_ADDR'          => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '10.6.6.6, 10.0.0.9, 10.0.0.1', // all trusted; leftmost forged
        ];
        $this->assertSame('10.0.0.1', App::clientIp()); // the observed peer, NOT 10.6.6.6
    }

    public function testClientIpStillReturnsFirstUntrustedHop(): void
    {
        App::$trusted_proxies = ['10.0.0.0/8'];
        $g = RequestContext::instance();
        $g->server = [
            'REMOTE_ADDR'          => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.9, 10.0.0.1',
        ];
        $this->assertSame('203.0.113.7', App::clientIp());
    }

    // ── #250 — access-log CRLF injection ─────────────────────────

    public function testAccessLogEscapesCrlfInRequestUri(): void
    {
        App::accessLogFormat('%r'); // resets the compiled token cache
        $g = RequestContext::instance();
        $g->server = [
            'REQUEST_METHOD'  => 'GET',
            'REQUEST_URI'     => "/x\r\nFAKE 200 injected",
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ];
        $line = App::formatAccessLogLine(200, 10, null);
        $this->assertStringNotContainsString("\n", $line);
        $this->assertStringNotContainsString("\r", $line);
        $this->assertStringContainsString('\\r\\n', $line); // escaped, not raw
    }

    // ── #257 — CGI pool subprocess env allowlist / httpoxy ───────

    public function testSubprocessEnvDropsHttpProxyByDefault(): void
    {
        $env = WorkerPool::filterSubprocessEnv(
            ['HTTP_PROXY' => 'http://evil', 'PATH' => '/usr/bin', 'DB_PASSWORD' => 's3cret'],
            [],
            42
        );
        $this->assertArrayNotHasKey('HTTP_PROXY', $env);
        $this->assertSame('/usr/bin', $env['PATH']);           // pass-through default keeps app vars
        $this->assertSame('s3cret', $env['DB_PASSWORD']);
        $this->assertSame('42', $env['ZEALPHP_POOL_MAX_REQUESTS']);
    }

    public function testSubprocessEnvStrictAllowlistDropsSecrets(): void
    {
        $env = WorkerPool::filterSubprocessEnv(
            ['HTTP_PROXY' => 'x', 'PATH' => '/usr/bin', 'AWS_SECRET' => 'k', 'ZEALPHP_LOG_DIR' => '/t'],
            ['PATH', 'ZEALPHP_*'],
            7
        );
        $this->assertArrayNotHasKey('AWS_SECRET', $env);       // not allowlisted → dropped
        $this->assertArrayNotHasKey('HTTP_PROXY', $env);
        $this->assertSame('/usr/bin', $env['PATH']);
        $this->assertSame('/t', $env['ZEALPHP_LOG_DIR']);      // prefix glob match
        $this->assertSame('7', $env['ZEALPHP_POOL_MAX_REQUESTS']);
    }

    // ── #243 — open redirect blocked by default ──────────────────

    private function freshResponse(): \ZealPHP\HTTP\Response
    {
        $fake = new FakeOpenSwooleResponse();
        $g = RequestContext::instance();
        $g->openswoole_response = $fake;
        $g->status = 200;
        $g->server = ['HTTP_HOST' => 'myapp.test'];
        return new \ZealPHP\HTTP\Response($fake);
    }

    public function testRedirectBlocksProtocolRelative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->freshResponse()->redirect('//evil.com');
    }

    public function testRedirectBlocksCrossOrigin(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->freshResponse()->redirect('https://evil.com/login');
    }

    public function testRedirectAllowsRelativeAndSameHost(): void
    {
        $this->freshResponse()->redirect('/dashboard');
        $this->assertSame(302, RequestContext::instance()->status);
        // #432 strict RFC 6454: freshResponse() is plain http (HTTP_HOST only),
        // so a same-origin absolute target must also be http — http→https is a
        // different origin under the strict (scheme,host,port) comparison.
        $this->freshResponse()->redirect('http://myapp.test/account', 301);
        $this->assertSame(301, RequestContext::instance()->status);
    }

    public function testRedirectExternalPermittedWithOptIn(): void
    {
        $this->freshResponse()->redirect('https://evil.com/ok', 302, true);
        $this->assertSame(302, RequestContext::instance()->status); // opt-in → no exception
    }
}
