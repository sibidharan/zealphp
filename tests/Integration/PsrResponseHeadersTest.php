<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * A handler's returned PSR-7 response keeps every value of a repeated header.
 * The emit loop called OpenSwoole's scalar header() once per value, which
 * overwrites by name, so only the last Link / Set-Cookie reached the wire, and
 * a Set-Cookie on the response replaced the cookies set with setcookie(). Same
 * collapse as #260, on the PSR-7 path.
 *
 * Routes: route/_psr_headers_test.php. TestCase::http() joins repeated header
 * lines with ', ', in wire order.
 */
class PsrResponseHeadersTest extends TestCase
{
    /**
     * The response's Set-Cookie lines in wire order, without the demo site's
     * session cookie (SessionStartMiddleware adds one to every response).
     */
    private function cookies(array $r): string
    {
        $lines = explode(', ', $r['headers']['set-cookie'] ?? '');
        $lines = array_filter($lines, static fn(string $l): bool => !str_starts_with($l, 'PHPSESSID='));
        return implode(', ', $lines);
    }

    public function testRepeatedHeadersAllReachTheWire(): void
    {
        $r = $this->get('/__psr_headers/repeated');
        $this->assertStatus(200, $r);
        $this->assertSame('one, two', $r['headers']['x-multi'] ?? null);
        $this->assertSame('</a.css>; rel=preload, </b.js>; rel=preload', $r['headers']['link'] ?? null);
    }

    public function testEverySetCookieIsSent(): void
    {
        $r = $this->get('/__psr_headers/cookies');
        $this->assertStatus(200, $r);
        $this->assertSame('p=1, q=2', $this->cookies($r));
    }

    public function testSetcookieCookiesAreKept(): void
    {
        $r = $this->get('/__psr_headers/cookies-and-setcookie');
        $this->assertStatus(200, $r);
        $this->assertSame('side=1, p=1, q=2', $this->cookies($r));

        $r = $this->get('/__psr_headers/cookie-lowercase-and-setcookie');
        $this->assertSame('side=1, p=1', $this->cookies($r));
    }

    public function testHeaderMiddlewareAddSendsEachCookieOnce(): void
    {
        $r = $this->get('/__psr_headers/header-middleware-add');
        $this->assertStatus(200, $r);
        $this->assertSame('a=1, b=2', $this->cookies($r));
    }

    public function testRepeatedHeadersOnNoContentResponse(): void
    {
        $r = $this->get('/__psr_headers/no-content');
        $this->assertStatus(204, $r);
        $this->assertSame('</a.css>; rel=preload, </b.js>; rel=preload', $r['headers']['link'] ?? null);
    }
}
