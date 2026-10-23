<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\HTTP;

use ZealPHP\HTTP\Response as ZResponse;
use ZealPHP\Tests\TestCase;

/**
 * Response::cookie()/rawCookie() follow native setcookie() rules: a name or
 * attribute carrying a separator would add attributes of the caller's (or the
 * request's) choosing to the Set-Cookie line.
 */
final class ResponseCookieRulesTest extends TestCase
{
    private function response(): ZResponse
    {
        return new ZResponse(new FakeOpenSwooleResponse());
    }

    public function testAttributeInjectionThroughPathOrDomainIsRefused(): void
    {
        $r = $this->response();
        $this->assertFalse(@$r->cookie('c', 'v', 0, '/; Domain=evil.test'));
        $this->assertFalse(@$r->cookie('c', 'v', 0, '/', 'example.test; SameSite=None'));
        $this->assertFalse(@$r->cookie('c', 'v', 0, "/\r\nX: y"));
        $this->assertFalse(@$r->rawCookie('c', 'v', 0, '/; Domain=evil.test'));
        $this->assertSame([], $r->cookiesList);
        $this->assertSame([], $r->rawCookiesList);
    }

    public function testBadNamesAndAttributeWordsAreRefused(): void
    {
        $r = $this->response();
        foreach (['', 'a=b', 'a;b', 'a b'] as $name) {
            $this->assertFalse(@$r->cookie($name, 'v'), json_encode($name));
        }
        $this->assertFalse(@$r->cookie('c', 'v', 0, '/', '', false, false, 'Lax; Domain=x'));
        $this->assertFalse(@$r->cookie('c', 'v', 0, '/', '', false, false, '', 'High;x'));
    }

    public function testRawCookieValueIsAnAttributeBoundary(): void
    {
        $r = $this->response();
        $this->assertFalse(@$r->rawCookie('c', 'v; Domain=evil.test'));
        $this->assertTrue($r->cookie('c', 'v; ok because cookie() url-encodes'));
    }

    public function testOrdinaryCookiesStillWork(): void
    {
        $r = $this->response();
        $this->assertTrue($r->cookie('sid', 'abc', 0, '/app', 'example.test', true, true, 'Strict', 'High'));
        $this->assertTrue($r->rawCookie('t', 'abc%20def', 0, '/'));
        $this->assertCount(1, $r->cookiesList);
        $this->assertCount(1, $r->rawCookiesList);
    }
}
