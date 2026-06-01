<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\Middleware\LocationHeaderMiddleware;
use ZealPHP\Tests\TestCase;

/**
 * Unit coverage for LocationHeaderMiddleware — rewrites the port of an
 * outbound Location header to a configured value, and ONLY when host+port are
 * both present and the existing port differs.
 *
 * Mutation-killing strategy: assert the EXACT rewritten URL (kills every
 * buildUrl() concat operand removal/reorder + the cast), and test BOTH the
 * rewrite branch and every short-circuit branch with a distinct outcome
 * (kills the three && operands + the `!=` comparison flip).
 */
class LocationHeaderMiddlewareTest extends TestCase
{
    /**
     * Terminal handler that returns a Response carrying the supplied Location
     * header (or no Location header when $location is null).
     */
    private function terminalWithLocation(?string $location): RequestHandlerInterface
    {
        return new class ($location) implements RequestHandlerInterface {
            public function __construct(private ?string $location)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $headers = [];
                if ($this->location !== null) {
                    $headers['Location'] = $this->location;
                }
                return new Response('body', 302, '', $headers);
            }
        };
    }

    private function rewrite(string $location, int $correctPort): string
    {
        $mw = new LocationHeaderMiddleware($correctPort);
        $response = $mw->process(
            new ServerRequest('/', 'GET'),
            $this->terminalWithLocation($location)
        );
        return $response->getHeaderLine('Location');
    }

    // ---- the rewrite branch: host + port present AND port differs ----------

    public function testRewritesPortWhenHostAndPortPresentAndDiffers(): void
    {
        // 8080 → 9000. Full URL must be reassembled with the new port and ALL
        // other components verbatim (scheme, host, path, query, fragment).
        $out = $this->rewrite('http://example.com:8080/path?q=1#frag', 9000);
        $this->assertSame('http://example.com:9000/path?q=1#frag', $out);
    }

    public function testRewritesPortWithMinimalUrlSchemeHostPort(): void
    {
        // No path/query/fragment — buildUrl must emit exactly scheme://host:port.
        $out = $this->rewrite('https://host.test:443', 8443);
        $this->assertSame('https://host.test:8443', $out);
    }

    public function testRewriteUsesConfiguredPortExactValue(): void
    {
        // The injected port appears verbatim (kills CastInt / off-by-one on the
        // assigned port and proves it is THIS object's correctPort).
        $out = $this->rewrite('http://a.example:1/x', 12345);
        $this->assertSame('http://a.example:12345/x', $out);
        $this->assertStringContainsString(':12345/', $out);
        $this->assertStringNotContainsString(':1/', $out);
    }

    public function testBuildUrlAssemblesEachComponentInOrder(): void
    {
        // Distinct, recognizable components so any concat reorder is detectable.
        $out = $this->rewrite('https://userhost:111/seg1/seg2?alpha=beta&x=y#section', 222);
        $this->assertSame('https://userhost:222/seg1/seg2?alpha=beta&x=y#section', $out);
        // scheme before host before port before path before query before fragment.
        $this->assertStringStartsWith('https://userhost:222/', $out);
        $this->assertStringEndsWith('#section', $out);
        $posScheme = strpos($out, 'https://');
        $posHost   = strpos($out, 'userhost');
        $posPort   = strpos($out, ':222');
        $posPath   = strpos($out, '/seg1');
        $posQuery  = strpos($out, '?alpha');
        $posFrag   = strpos($out, '#section');
        $this->assertSame(0, $posScheme);
        $this->assertNotFalse($posHost);
        $this->assertNotFalse($posPort);
        $this->assertNotFalse($posPath);
        $this->assertNotFalse($posQuery);
        $this->assertNotFalse($posFrag);
        $this->assertLessThan($posHost, $posScheme);
        $this->assertLessThan($posPort, $posHost);
        $this->assertLessThan($posPath, $posPort);
        $this->assertLessThan($posQuery, $posPath);
        $this->assertLessThan($posFrag, $posQuery);
    }

    // ---- short-circuit branch 1: port equals correctPort → untouched -------

    public function testLeavesLocationUntouchedWhenPortAlreadyCorrect(): void
    {
        // port == correctPort → the `!= correctPort` clause is false → no rewrite.
        // Kills the comparison flip (== for !=): a flipped op would rewrite here.
        $original = 'http://example.com:9000/keep?this=1#anchor';
        $out = $this->rewrite($original, 9000);
        $this->assertSame($original, $out);
    }

    // ---- short-circuit branch 2: no port in URL → untouched ----------------

    public function testLeavesLocationUntouchedWhenNoPort(): void
    {
        // isset($parsedUrl['port']) is false → no rewrite, header preserved.
        $original = 'http://example.com/nodeport/path';
        $out = $this->rewrite($original, 9000);
        $this->assertSame($original, $out);
    }

    // ---- short-circuit branch 3: no host (relative URL) → untouched --------

    public function testLeavesLocationUntouchedWhenNoHost(): void
    {
        // A relative path: parse_url yields no host → no rewrite.
        $original = '/relative/redirect?x=1';
        $out = $this->rewrite($original, 9000);
        $this->assertSame($original, $out);
    }

    // ---- no Location header at all: response returned verbatim -------------

    public function testResponseUntouchedWhenNoLocationHeader(): void
    {
        $mw = new LocationHeaderMiddleware(9000);
        $response = $mw->process(
            new ServerRequest('/', 'GET'),
            $this->terminalWithLocation(null)
        );
        // hasHeader('Location') is false → the whole if-block is skipped.
        $this->assertFalse($response->hasHeader('Location'));
        $this->assertSame('body', (string) $response->getBody());
        $this->assertSame(302, $response->getStatusCode());
    }

    public function testReturnsHandlerResponseBodyAndStatusOnRewrite(): void
    {
        // Even when rewriting, the rest of the response (body, status) is the
        // handler's — proves withHeader() returns a response, not a fresh one,
        // and that the method still returns the (mutated) response.
        $mw = new LocationHeaderMiddleware(7000);
        $response = $mw->process(
            new ServerRequest('/', 'GET'),
            $this->terminalWithLocation('http://h:6000/p')
        );
        $this->assertSame('http://h:7000/p', $response->getHeaderLine('Location'));
        $this->assertSame('body', (string) $response->getBody());
        $this->assertSame(302, $response->getStatusCode());
    }

    public function testIpv4HostWithDifferingPortIsRewritten(): void
    {
        // host is an IPv4 literal — parse_url still yields host + port.
        $out = $this->rewrite('http://127.0.0.1:3000/api', 8080);
        $this->assertSame('http://127.0.0.1:8080/api', $out);
    }
}
