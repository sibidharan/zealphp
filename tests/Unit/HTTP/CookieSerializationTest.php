<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\HTTP;

use ZealPHP\App;
use ZealPHP\HTTP\Response as ZResponse;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * Set-Cookie wire-format parity with PHP 8.4's php_setcookie() (#293, #319).
 *
 * Every expected string below was captured LIVE from Apache 2.4.67 + mod_php
 * 8.4 (php:8.4-apache) — the project's parity reference:
 *
 *   Set-Cookie: c_full=v1; expires=Wed, 18 May 2033 03:33:20 GMT; Max-Age=218969254; path=/p; secure; HttpOnly; SameSite=Strict
 *   Set-Cookie: c_space=a%20b%20c; path=/
 *   Set-Cookie: c_plus=a%2Bb%2Fc%3Dd; path=/
 *   Set-Cookie: c_none=v; path=/; SameSite=None
 *   Set-Cookie: c_empty=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=/
 *   Set-Cookie: c_negexp=v; expires=<past>; Max-Age=0
 *   Set-Cookie: c_raw=a%20b; path=/        (setrawcookie — value verbatim)
 *
 * Divergences this pins against the OLD OpenSwoole C-side serializer:
 * dashed expires date (18-May-2033), missing Max-Age, `+` for space, and
 * lowercase `httponly`/`samesite=` attribute casing.
 */
class CookieSerializationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        $g = RequestContext::instance();
        $g->status = null;
        $g->_streaming = false;
    }

    // ---- serializeCookie() — pure string building -------------------------

    public function testFullTupleMatchesPhp84WireFormat(): void
    {
        $line = ZResponse::serializeCookie(
            'c_full', 'v1', 2000000000, '/p', '', true, true, 'Strict'
        );
        // Max-Age is now-relative; pin the exact non-volatile bytes around it.
        $this->assertMatchesRegularExpression(
            '#^c_full=v1; expires=Wed, 18 May 2033 03:33:20 GMT; Max-Age=\d+; path=/p; secure; HttpOnly; SameSite=Strict$#',
            $line,
        );
        $maxAge = (int) preg_replace('#^.*Max-Age=(\d+).*$#', '$1', $line);
        $this->assertEqualsWithDelta(2000000000 - time(), $maxAge, 2.0);
    }

    public function testSpaceEncodesAsPercent20NotPlus(): void
    {
        $this->assertSame(
            'c_space=a%20b%20c; path=/',
            ZResponse::serializeCookie('c_space', 'a b c', 0, '/'),
        );
    }

    public function testPlusSlashEqualsEncodeLikeRawurlencode(): void
    {
        // Base64-ish payloads (JWTs) must survive: + is %2B, never a literal +.
        $this->assertSame(
            'c_plus=a%2Bb%2Fc%3Dd; path=/',
            ZResponse::serializeCookie('c_plus', 'a+b/c=d', 0, '/'),
        );
    }

    public function testEmptyValueEmitsPhpDeletionForm(): void
    {
        $this->assertSame(
            'c_empty=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=/',
            ZResponse::serializeCookie('c_empty', '', 0, '/'),
        );
    }

    public function testPastExpireClampsMaxAgeToZero(): void
    {
        $line = ZResponse::serializeCookie('c_negexp', 'v', time() - 100);
        $this->assertStringContainsString('Max-Age=0', $line);
        $this->assertStringStartsWith('c_negexp=v; expires=', $line);
    }

    public function testSessionCookieOmitsExpiresAndMaxAge(): void
    {
        $line = ZResponse::serializeCookie('sid', 'v', 0, '/');
        $this->assertSame('sid=v; path=/', $line);
    }

    public function testAttributeOrderAndCasing(): void
    {
        $this->assertSame(
            'c=v; path=/p; domain=ex.com; secure; HttpOnly; SameSite=Lax',
            ZResponse::serializeCookie('c', 'v', 0, '/p', 'ex.com', true, true, 'Lax'),
        );
    }

    public function testRawValuePassesThroughVerbatim(): void
    {
        $this->assertSame(
            'c_raw=a%20b; path=/',
            ZResponse::serializeCookie('c_raw', 'a%20b', 0, '/', raw: true),
        );
    }

    public function testSameSiteNoneWithoutSecureEmitsAsIsLikePhp(): void
    {
        // #319 — parity decision: PHP/Apache do NOT auto-coerce Secure, and
        // neither do we (the framework logs a warning instead — silent client
        // drop is the developer's bug to fix, not ours to mask).
        $this->assertSame(
            'c_none=v; path=/; SameSite=None',
            @ZResponse::serializeCookie('c_none', 'v', 0, '/', '', false, false, 'None'),
        );
    }

    // ---- flush() — emission path -------------------------------------------

    public function testFlushEmitsSerializedSetCookieHeaderLines(): void
    {
        $fake = new FakeOpenSwooleResponse();
        $fake->writable = true;
        $resp = new ZResponse($fake);

        $resp->cookie('a', 'x y', 0, '/');
        $resp->rawCookie('b', 'r%20w', 0, '/');
        $resp->flush();

        // Both lines reach the wire as Set-Cookie header values (the #260
        // array-valued multi-header mechanism), serialized in PHP — never via
        // OpenSwoole's C-side cookie() (whose format diverges from PHP 8.4).
        $setCookieValues = [];
        foreach ($fake->log as $entry) {
            if (($entry[0] ?? null) === 'cookie' || ($entry[0] ?? null) === 'rawCookie') {
                $this->fail('flush() must not delegate to the C-side cookie serializer');
            }
            if (($entry[0] ?? null) === 'header' && strcasecmp((string) $entry[1], 'Set-Cookie') === 0) {
                $v = $entry[2];
                foreach (is_array($v) ? $v : [$v] as $line) {
                    $setCookieValues[] = $line;
                }
            }
        }
        $this->assertSame(['a=x%20y; path=/', 'b=r%20w; path=/'], $setCookieValues);
    }
}
