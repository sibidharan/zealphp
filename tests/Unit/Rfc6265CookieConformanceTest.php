<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * Conformance: HTTP State Management — Cookies (RFC 6265).
 *
 * Validates ZealPHP's `setcookie()` override against RFC 6265:
 *   - §4.1.1 cookie-name is a token — must reject separators/controls.
 *   - §4.1.1 cookie-value / av-value must not carry CTLs; CR/LF/NUL rejection
 *     also closes Set-Cookie header-injection (a security-critical edge case).
 *   - all attributes (Path, Domain, Secure, HttpOnly, Max-Age/Expires, SameSite)
 *     are propagated to the response verbatim.
 */
class Rfc6265CookieConformanceTest extends TestCase
{
    private CookieCaptureResponse $resp;

    protected function setUp(): void
    {
        App::superglobals(false);
        $this->resp = new CookieCaptureResponse();
        $g = RequestContext::instance();
        $g->zealphp_response = $this->resp;
    }

    /**
     * §4.1.1: cookie-name = token. Separators and controls are illegal.
     * Each must be refused (return false) and emit no cookie.
     */
    public function testRejectsIllegalCookieNameCharacters(): void
    {
        foreach (['a=b', 'a;b', 'a,b', "a\tb", "a b", "a\r", "a\n", "a\0"] as $badName) {
            $ok = @\ZealPHP\setcookie($badName, 'v');
            $this->assertFalse($ok, "name '" . addcslashes($badName, "\0..\37") . "' must be rejected");
        }
        $this->assertCount(0, $this->resp->cookies, 'no illegal-named cookie should be emitted');
    }

    /**
     * §4.1.1 + header-injection defense: CR/LF/NUL in value/path/domain/samesite
     * must be refused (a CRLF in a value would forge additional headers).
     */
    public function testRejectsCrlfInjectionAcrossFields(): void
    {
        $this->assertFalse(@\ZealPHP\setcookie('sid', "v\r\nSet-Cookie: evil=1"));
        $this->assertFalse(@\ZealPHP\setcookie('sid', 'v', 0, "/\r\nX: y"));
        $this->assertFalse(@\ZealPHP\setcookie('sid', 'v', 0, '/', "ex.com\r\n"));
        $this->assertFalse(@\ZealPHP\setcookie('sid', 'v', 0, '/', 'ex.com', false, false, "Lax\r\n"));
        $this->assertFalse(@\ZealPHP\setcookie('sid', "v\0null"));
        $this->assertCount(0, $this->resp->cookies, 'no injected cookie should be emitted');
    }

    /** A well-formed cookie is accepted and every attribute propagates verbatim. */
    public function testValidCookiePropagatesAllAttributes(): void
    {
        $ok = \ZealPHP\setcookie('SID', 'abc123', 1893456000, '/app', 'example.com', true, true, 'Strict');
        $this->assertTrue($ok);
        $this->assertCount(1, $this->resp->cookies);
        $this->assertSame(
            ['SID', 'abc123', 1893456000, '/app', 'example.com', true, true, 'Strict'],
            $this->resp->cookies[0]
        );
    }

    /** §5.4.7-era SameSite values are all accepted and passed through. */
    public function testSameSiteValuesAccepted(): void
    {
        foreach (['Lax', 'Strict', 'None'] as $ss) {
            $this->resp->cookies = [];
            $this->assertTrue(\ZealPHP\setcookie('c', 'v', 0, '/', '', true, false, $ss));
            $this->assertSame($ss, $this->resp->cookies[0][7], "SameSite=$ss must propagate");
        }
    }

    public function testPlainCookieAccepted(): void
    {
        $this->assertTrue(\ZealPHP\setcookie('token', 'xyz'));
        $this->assertSame('token', $this->resp->cookies[0][0]);
        $this->assertSame('xyz', $this->resp->cookies[0][1]);
    }
}

/**
 * Captures cookie() calls so the conformance test can assert what would be
 * emitted, without a live OpenSwoole response.
 */
class CookieCaptureResponse
{
    /** @var list<array{0:string,1:string,2:int,3:string,4:string,5:bool,6:bool,7:string}> */
    public array $cookies = [];

    public function cookie(
        string $name,
        string $value = '',
        int $expire = 0,
        string $path = '',
        string $domain = '',
        bool $secure = false,
        bool $httponly = false,
        string $samesite = ''
    ): void {
        $this->cookies[] = [$name, $value, $expire, $path, $domain, $secure, $httponly, $samesite];
    }
}
