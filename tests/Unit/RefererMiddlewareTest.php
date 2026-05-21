<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\RefererMiddleware;
use ZealPHP\Tests\TestCase;

/**
 * RefererMiddleware — nginx `valid_referers` / `$invalid_referer` parity.
 * Asserts exact allow/deny outcomes at every spec form (exact host, `*.x`,
 * `x.*`, `~regex`, path prefix) and at the `none`/`blocked` toggles, plus the
 * boundary cases that pin the host/scheme/wildcard arithmetic.
 */
class RefererMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
    }

    // ----- none / blocked toggles --------------------------------------

    public function testMissingRefererAllowedByDefault(): void
    {
        $this->assertSame(200, $this->invoke([], null));
    }

    public function testMissingRefererBlockedWhenAllowNoneFalse(): void
    {
        $this->assertSame(403, $this->invoke([], null, allowNone: false));
    }

    public function testSchemelessRefererAllowedByDefault(): void
    {
        $this->assertSame(200, $this->invoke([], 'example.com/page'));
    }

    public function testSchemelessRefererBlockedWhenAllowBlockedFalse(): void
    {
        $this->assertSame(403, $this->invoke([], 'example.com/page', allowBlocked: false));
    }

    // ----- 403 response shape ------------------------------------------

    public function test403ResponseShape(): void
    {
        $resp = $this->process(['good.com'], 'http://evil.com');
        $this->assertSame(403, $resp->getStatusCode());
        $this->assertSame('Forbidden', (string)$resp->getBody());
        $this->assertSame('text/plain', $resp->getHeaderLine('Content-Type'));
    }

    public function testNonStringSpecsAreFilteredOut(): void
    {
        // A non-string spec must be discarded by array_filter(is_string) — if it
        // survived, matchSpec(string) would TypeError on the int/bool argument.
        $this->assertSame(403, $this->invoke([true, 12345], 'http://x.com'));
    }

    // ----- scheme detection (anchor + case) ----------------------------

    public function testLeadingWhitespaceTreatedAsBlocked(): void
    {
        // `^https?://` is start-anchored: a leading space means "no scheme at
        // start" → blocked → allowed by default. (Caret removal would parse it.)
        $this->assertSame(200, $this->invoke(['good.com'], '  http://good.com'));
    }

    public function testSchemeMatchIsCaseInsensitive(): void
    {
        // The `i` flag: uppercase scheme still parses as a real referer.
        $this->assertSame(200, $this->invoke(['good.com'], 'HTTP://good.com', allowBlocked: false));
    }

    // ----- host parsing -------------------------------------------------

    public function testExactHostWithoutPathMatches(): void
    {
        $this->assertSame(200, $this->invoke(['good.com'], 'http://good.com'));
    }

    public function testRefererHostIsLowercased(): void
    {
        $this->assertSame(200, $this->invoke(['good.com'], 'http://GOOD.com'));
    }

    public function testSpecHostIsLowercased(): void
    {
        $this->assertSame(200, $this->invoke(['GOOD.com'], 'http://good.com'));
    }

    public function testPortIsIgnored(): void
    {
        $this->assertSame(200, $this->invoke(['good.com'], 'http://good.com:8443/x'));
    }

    public function testExactHostMismatchBlocked(): void
    {
        $this->assertSame(403, $this->invoke(['good.com'], 'http://bad.com'));
    }

    // ----- *.example.com wildcard --------------------------------------

    public function testWildcardSubdomainMatches(): void
    {
        $this->assertSame(200, $this->invoke(['*.example.com'], 'http://sub.example.com'));
    }

    public function testWildcardApexMatches(): void
    {
        // host === substr(spec, 2) — the apex (no subdomain) case.
        $this->assertSame(200, $this->invoke(['*.example.com'], 'http://example.com'));
    }

    public function testWildcardRequiresDotBoundary(): void
    {
        // "fooexample.com" must NOT match "*.example.com" (no dot separator).
        $this->assertSame(403, $this->invoke(['*.example.com'], 'http://fooexample.com'));
    }

    public function testWildcardUnrelatedHostBlocked(): void
    {
        $this->assertSame(403, $this->invoke(['*.example.com'], 'http://evil.com'));
    }

    // ----- example.* suffix wildcard -----------------------------------

    public function testSuffixWildcardMatches(): void
    {
        $this->assertSame(200, $this->invoke(['example.*'], 'http://example.org'));
    }

    public function testSuffixWildcardUnrelatedBlocked(): void
    {
        $this->assertSame(403, $this->invoke(['example.*'], 'http://other.org'));
    }

    public function testSuffixWildcardRequiresDot(): void
    {
        // "examplexyz.com" must NOT match "example.*" — the spec is "example."
        // (substr 0,-1), so the dot is required.
        $this->assertSame(403, $this->invoke(['example.*'], 'http://examplexyz.com'));
    }

    // ----- example.* suffix wildcard — DNS-label boundary (B1 security fix) ------

    public function testSuffixWildcardDoesNotMatchSubdomain(): void
    {
        // B1 security fix: example.* must NOT match example.evil.com — nginx's
        // label-aware wildcard (ngx_hash_wildcard_init) stops at one DNS label
        // after the base; str_starts_with alone would incorrectly allow this.
        $this->assertSame(403, $this->invoke(['example.*'], 'http://example.evil.com'));
    }

    public function testSuffixWildcardMatchesSingleLabel(): void
    {
        // One label after base ("org") — this is the valid case.
        $this->assertSame(200, $this->invoke(['example.*'], 'http://example.org'));
        $this->assertSame(200, $this->invoke(['example.*'], 'http://example.com'));
    }

    public function testSuffixWildcardDoesNotMatchMultipleLabels(): void
    {
        // "example.co.uk" → remainder after "example." is "co.uk" which contains a dot.
        $this->assertSame(403, $this->invoke(['example.*'], 'http://example.co.uk'));
    }

    // ----- ~regex spec — case-insensitive (B7 fix) ----------------------

    public function testRegexSpecMatches(): void
    {
        $this->assertSame(200, $this->invoke(['~^yx\.'], 'http://yx.com/path'));
    }

    public function testRegexSpecIsTakenFromOffsetOne(): void
    {
        // substr(spec, 1) drops only the leading "~"; "~^x" → /^x/ is anchored,
        // so "yx.com" (x not at start) must NOT match.
        $this->assertSame(403, $this->invoke(['~^x'], 'http://yx.com'));
    }

    public function testRegexSpecNoMatchBlocked(): void
    {
        $this->assertSame(403, $this->invoke(['~google'], 'http://example.com'));
    }

    public function testRegexSpecIsCaseInsensitive(): void
    {
        // B7 fix: nginx compiles all ~regex with NGX_REGEX_CASELESS.
        // Lowercase pattern must match uppercase Referer host.
        $this->assertSame(200, $this->invoke(['~\.google\.'], 'http://WWW.GOOGLE.COM/'));
    }

    public function testRegexSpecCaseInsensitiveUppercasePattern(): void
    {
        // Uppercase pattern against lowercase Referer — same fix.
        $this->assertSame(200, $this->invoke(['~\.GOOGLE\.'], 'http://www.google.com/'));
    }

    // ----- ~regex spec — malformed pattern handling (B10 fix) -----------

    public function testMalformedRegexFailsClosed(): void
    {
        // B10 fix: a malformed regex must not silently pass requests — the spec
        // is skipped (fail-closed), so with no other matching spec the request
        // is blocked. Previously @preg_match suppressed the error and returned
        // false (treated as non-match — that's correct), but without the error
        // being surfaced. Now we log via elog() and explicitly skip.
        $this->assertSame(403, $this->invoke(['~[broken'], 'http://example.com'));
    }

    public function testMalformedRegexDoesNotAffectOtherSpecs(): void
    {
        // A bad regex spec must not prevent other valid specs from matching.
        $this->assertSame(200, $this->invoke(['~[broken', 'example.com'], 'http://example.com'));
    }

    // ----- server_names token (Wave2) -----------------------------------

    public function testServerNamesAutoAllowsOwnHost(): void
    {
        // nginx `server_names` token: own server hostname is auto-allowed.
        $mw = new RefererMiddleware([], serverNames: ['myapp.example.com']);
        $request = new ServerRequest('/', 'GET', '', ['referer' => 'http://myapp.example.com/page']);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        $this->assertSame(200, $mw->process($request, $handler)->getStatusCode());
    }

    public function testServerNamesIsCaseInsensitive(): void
    {
        // Own host matching must be case-insensitive (DNS is case-insensitive).
        $mw = new RefererMiddleware([], serverNames: ['MyApp.Example.COM']);
        $request = new ServerRequest('/', 'GET', '', ['referer' => 'http://myapp.example.com/page']);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        $this->assertSame(200, $mw->process($request, $handler)->getStatusCode());
    }

    public function testServerNamesDoesNotAllowOtherHosts(): void
    {
        // server_names only adds own host(s) — foreign hosts are still blocked.
        $mw = new RefererMiddleware([], serverNames: ['myapp.example.com']);
        $request = new ServerRequest('/', 'GET', '', ['referer' => 'http://evil.com/steal']);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        $this->assertSame(403, $mw->process($request, $handler)->getStatusCode());
    }

    public function testServerNamesCombinesWithOtherSpecs(): void
    {
        // Both own host (server_names) and explicit specs may match.
        $this->assertSame(200, $this->invokeWithServerNames(['other.com'], 'http://myapp.example.com/', ['myapp.example.com']));
        $this->assertSame(200, $this->invokeWithServerNames(['other.com'], 'http://other.com/', ['myapp.example.com']));
        $this->assertSame(403, $this->invokeWithServerNames(['other.com'], 'http://evil.com/', ['myapp.example.com']));
    }

    // ----- path prefix --------------------------------------------------

    public function testPathPrefixMatches(): void
    {
        $this->assertSame(200, $this->invoke(['good.com/gallery'], 'http://good.com/gallery/1.jpg'));
    }

    public function testPathPrefixMismatchBlocked(): void
    {
        $this->assertSame(403, $this->invoke(['good.com/gallery'], 'http://good.com/other'));
    }

    // ----- helpers ------------------------------------------------------

    /**
     * @param array<int, mixed> $referers
     */
    private function invoke(array $referers, ?string $referer, bool $allowNone = true, bool $allowBlocked = true): int
    {
        return $this->process($referers, $referer, $allowNone, $allowBlocked)->getStatusCode();
    }

    /**
     * @param array<int, mixed> $referers
     */
    private function process(array $referers, ?string $referer, bool $allowNone = true, bool $allowBlocked = true): ResponseInterface
    {
        $mw = new RefererMiddleware($referers, $allowNone, $allowBlocked);
        $headers = $referer === null ? [] : ['referer' => $referer];
        $request = new ServerRequest('/', 'GET', '', $headers);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process($request, $handler);
    }

    /**
     * Invoke with server_names token support.
     *
     * @param array<int, mixed> $referers
     * @param list<string>      $serverNames
     */
    private function invokeWithServerNames(array $referers, string $referer, array $serverNames): int
    {
        $mw = new RefererMiddleware($referers, serverNames: $serverNames);
        $request = new ServerRequest('/', 'GET', '', ['referer' => $referer]);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process($request, $handler)->getStatusCode();
    }
}
