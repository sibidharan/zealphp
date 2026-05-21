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

    // ----- ~regex spec --------------------------------------------------

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
}
