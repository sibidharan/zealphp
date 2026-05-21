<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\HostRouterMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class HostRouterMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->server = [];
    }

    protected function tearDown(): void
    {
        RequestContext::instance()->server = [];
    }

    /**
     * @param array<string, mixed> $hosts
     * @param array<string, string|string[]> $headers
     */
    private function process(array $hosts, string $host, array $headers = [], string $version = '1.1'): ResponseInterface
    {
        $mw = new HostRouterMiddleware($hosts);
        $mergedHeaders = array_merge(['host' => $host], $headers);
        $request = (new ServerRequest('/', 'GET', '', $mergedHeaders))
            ->withProtocolVersion($version);

        // Fallthrough handler — distinguishable from any host-routed response.
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('FALLTHROUGH', 200, '', ['Content-Type' => 'text/plain']);
            }
        };

        return $mw->process($request, $handler);
    }

    /**
     * Build a request with no Host header.
     *
     * @param array<string, mixed> $hosts
     * @param array<string, scalar|null> $server
     */
    private function processNoHostHeader(array $server, array $hosts, string $version = '1.0'): ResponseInterface
    {
        $g = RequestContext::instance();
        $g->server = $server;

        $mw = new HostRouterMiddleware($hosts);
        $request = (new ServerRequest('/', 'GET', '', []))
            ->withProtocolVersion($version);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('FALLTHROUGH', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process($request, $handler);
    }

    // -------------------------------------------------------------------------
    // Exact matching
    // -------------------------------------------------------------------------

    public function testExactHostMatchDispatches(): void
    {
        $response = $this->process(
            ['docs.example.com' => fn() => 'docs body'],
            'docs.example.com'
        );

        $this->assertSame('docs body', (string) $response->getBody());
    }

    public function testRegisteredHostKeyIsLowercased(): void
    {
        // Register a MIXED-CASE host key, request all lowercase. The constructor
        // MUST lowercase the key for the match to land. Kills UnwrapStrToLower
        // at L66 (key stored mixed-case -> no match -> fallthrough).
        $response = $this->process(
            ['Docs.Example.COM' => fn() => 'docs body'],
            'docs.example.com'
        );

        $this->assertSame('docs body', (string) $response->getBody());
    }

    public function testNumericHostKeyIsStringCast(): void
    {
        // A purely-numeric host key is coerced by PHP to an int array key.
        // The constructor's (string) cast is load-bearing: strtolower() needs a
        // string, and the lookup compares against the string request host.
        $response = $this->process(
            ['127' => fn() => 'numeric-host'],
            '127'
        );

        $this->assertSame('numeric-host', (string) $response->getBody());
    }

    public function testPortIsStrippedBeforeMatching(): void
    {
        $response = $this->process(
            ['example.com' => fn() => 'site'],
            'example.com:8080'
        );

        $this->assertSame('site', (string) $response->getBody());
    }

    public function testNoMatchFallsThroughToHandler(): void
    {
        $response = $this->process(
            ['a.com' => fn() => 'a'],
            'unknown.com'
        );

        $this->assertSame('FALLTHROUGH', (string) $response->getBody());
    }

    // -------------------------------------------------------------------------
    // Catch-all
    // -------------------------------------------------------------------------

    public function testCatchAllRegistrationDoesNotStopFollowingHosts(): void
    {
        // '*' is registered FIRST; a real host follows it. The Continue_ mutant
        // at L69 (continue -> break) would abort the loop after '*', so the
        // 'late.com' handler would never register and the request would hit the
        // catch-all instead of its own handler.
        $response = $this->process(
            [
                '*'        => fn() => 'CATCHALL',
                'late.com' => fn() => 'LATE',
            ],
            'late.com'
        );

        $this->assertSame('LATE', (string) $response->getBody());
    }

    public function testCatchAllUsedWhenNoOtherHostMatches(): void
    {
        $response = $this->process(
            [
                '*'        => fn() => 'CATCHALL',
                'late.com' => fn() => 'LATE',
            ],
            'nobody.com'
        );

        $this->assertSame('CATCHALL', (string) $response->getBody());
    }

    // -------------------------------------------------------------------------
    // Leading wildcard (*.example.com)
    // -------------------------------------------------------------------------

    public function testWildcardRegistrationDoesNotStopFollowingHosts(): void
    {
        // Wildcard registered first; a plain host follows. Continue_ mutant at
        // L73 (continue -> break) would abort before registering 'plain.com'.
        $response = $this->process(
            [
                '*.example.com' => fn() => 'WILD',
                'plain.com'     => fn() => 'PLAIN',
            ],
            'plain.com'
        );

        $this->assertSame('PLAIN', (string) $response->getBody());
    }

    public function testWildcardSubdomainMatches(): void
    {
        $response = $this->process(
            ['*.example.com' => fn() => 'WILD'],
            'sub.example.com'
        );

        $this->assertSame('WILD', (string) $response->getBody());
    }

    public function testWildcardDoesNotMatchBareDomain(): void
    {
        // *.example.com must NOT match example.com itself -> falls through.
        $response = $this->process(
            ['*.example.com' => fn() => 'WILD'],
            'example.com'
        );

        $this->assertSame('FALLTHROUGH', (string) $response->getBody());
    }

    // -------------------------------------------------------------------------
    // Trailing wildcard (www.*)  — new in wave2
    // -------------------------------------------------------------------------

    public function testTrailingWildcardMatchesAnyTld(): void
    {
        $mw = new HostRouterMiddleware(['www.*' => fn() => 'trailing-wild']);

        foreach (['www.example.com', 'www.org', 'www.co.uk'] as $host) {
            $response = $this->process(['www.*' => fn() => 'trailing-wild'], $host);
            $this->assertSame(
                'trailing-wild',
                (string) $response->getBody(),
                "Expected trailing wildcard to match {$host}"
            );
        }
    }

    public function testTrailingWildcardDoesNotMatchWithoutDot(): void
    {
        // 'www' alone (no dot suffix) must NOT match www.*
        $response = $this->process(['www.*' => fn() => 'trailing-wild'], 'www');
        $this->assertSame('FALLTHROUGH', (string) $response->getBody());
    }

    public function testTrailingWildcardRegistrationDoesNotStopFollowingHosts(): void
    {
        // Trailing wildcard first; exact host follows. If the loop broke early
        // 'exact.com' would never register.
        $response = $this->process(
            [
                'www.*'     => fn() => 'TWC',
                'exact.com' => fn() => 'EXACT',
            ],
            'exact.com'
        );

        $this->assertSame('EXACT', (string) $response->getBody());
    }

    // -------------------------------------------------------------------------
    // Regex server_name (~^pattern)  — new in wave2
    // -------------------------------------------------------------------------

    public function testRegexServerNameMatches(): void
    {
        $response = $this->process(
            ['~^admin\..+' => fn() => 'admin-regex'],
            'admin.example.com'
        );

        $this->assertSame('admin-regex', (string) $response->getBody());
    }

    public function testRegexServerNameDoesNotMatchNonMatchingHost(): void
    {
        $response = $this->process(
            ['~^admin\..+' => fn() => 'admin-regex'],
            'user.example.com'
        );

        $this->assertSame('FALLTHROUGH', (string) $response->getBody());
    }

    public function testRegexServerNameIsCaseInsensitive(): void
    {
        // Regex rules are applied with the 'i' flag (nginx default for server_name)
        $response = $this->process(
            ['~^ADMIN\..+' => fn() => 'admin-regex'],
            'admin.example.com'
        );

        $this->assertSame('admin-regex', (string) $response->getBody());
    }

    public function testRegexRegistrationDoesNotStopFollowingHosts(): void
    {
        $response = $this->process(
            [
                '~^admin\..+' => fn() => 'REGEX',
                'plain.com'   => fn() => 'PLAIN',
            ],
            'plain.com'
        );

        $this->assertSame('PLAIN', (string) $response->getBody());
    }

    // -------------------------------------------------------------------------
    // Precedence: exact > leading-wc > trailing-wc > regex > catch-all
    // -------------------------------------------------------------------------

    public function testExactBeatsLeadingWildcard(): void
    {
        $response = $this->process(
            [
                '*.example.com'     => fn() => 'leading-wc',
                'sub.example.com'   => fn() => 'exact',
            ],
            'sub.example.com'
        );

        $this->assertSame('exact', (string) $response->getBody());
    }

    public function testLeadingWildcardBeatsTrailingWildcard(): void
    {
        // www.example.com could match either *.example.com (leading) or www.* (trailing).
        // Exact > leading-wc > trailing-wc → leading-wc wins.
        $response = $this->process(
            [
                'www.*'         => fn() => 'trailing-wc',
                '*.example.com' => fn() => 'leading-wc',
            ],
            'www.example.com'
        );

        $this->assertSame('leading-wc', (string) $response->getBody());
    }

    public function testTrailingWildcardBeatsRegex(): void
    {
        // www.example.com matches both www.* (trailing) and a regex.
        // trailing-wc has higher precedence than regex.
        $response = $this->process(
            [
                '~^www\..+'  => fn() => 'regex',
                'www.*'      => fn() => 'trailing-wc',
            ],
            'www.example.com'
        );

        $this->assertSame('trailing-wc', (string) $response->getBody());
    }

    public function testRegexBeatsCatchAll(): void
    {
        $response = $this->process(
            [
                '*'          => fn() => 'catchall',
                '~^api\..+'  => fn() => 'regex',
            ],
            'api.example.com'
        );

        $this->assertSame('regex', (string) $response->getBody());
    }

    public function testFullPrecedenceChain(): void
    {
        $hosts = [
            '*'             => fn() => 'catchall',
            '~^sub\..+'     => fn() => 'regex',
            'www.*'         => fn() => 'trailing-wc',
            '*.example.com' => fn() => 'leading-wc',
            'sub.example.com' => fn() => 'exact',
        ];

        // Exact wins over everything
        $this->assertSame('exact', (string) $this->process($hosts, 'sub.example.com')->getBody());
        // Leading-wc beats trailing-wc, regex, catchall
        $this->assertSame('leading-wc', (string) $this->process($hosts, 'other.example.com')->getBody());
        // Trailing-wc beats regex, catchall (www.org doesn't match *.example.com)
        $this->assertSame('trailing-wc', (string) $this->process($hosts, 'www.org')->getBody());
        // Regex beats catchall (sub.org doesn't match leading or trailing wc)
        $this->assertSame('regex', (string) $this->process($hosts, 'sub.org')->getBody());
        // Catch-all last resort
        $this->assertSame('catchall', (string) $this->process($hosts, 'nobody.io')->getBody());
    }

    // -------------------------------------------------------------------------
    // IPv6 host parsing  — fix for B8
    // -------------------------------------------------------------------------

    public function testIpv6HostWithPortStripsPortCorrectly(): void
    {
        // [::1]:80 — port separator is after ], not inside the brackets
        $response = $this->process(
            ['[::1]' => fn() => 'ipv6-loopback'],
            '[::1]:80'
        );

        $this->assertSame('ipv6-loopback', (string) $response->getBody());
    }

    public function testIpv6HostWithoutPortMatches(): void
    {
        $response = $this->process(
            ['[::1]' => fn() => 'ipv6-loopback'],
            '[::1]'
        );

        $this->assertSame('ipv6-loopback', (string) $response->getBody());
    }

    public function testIpv6FullAddressWithPort(): void
    {
        $response = $this->process(
            ['[2001:db8::1]' => fn() => 'ipv6-full'],
            '[2001:db8::1]:8080'
        );

        $this->assertSame('ipv6-full', (string) $response->getBody());
    }

    // -------------------------------------------------------------------------
    // Trailing dot normalisation  — nginx parity
    // -------------------------------------------------------------------------

    public function testTrailingDotIsStripped(): void
    {
        $response = $this->process(
            ['example.com' => fn() => 'dotless'],
            'example.com.'
        );

        $this->assertSame('dotless', (string) $response->getBody());
    }

    public function testTrailingDotWithPortIsStripped(): void
    {
        $response = $this->process(
            ['example.com' => fn() => 'dotless'],
            'example.com.:8080'
        );

        $this->assertSame('dotless', (string) $response->getBody());
    }

    // -------------------------------------------------------------------------
    // Host validation → 400  — nginx parity (only when HostRouterMiddleware is active)
    // -------------------------------------------------------------------------

    public function testMissingHostOnHttp11Returns400(): void
    {
        // HTTP/1.1 without Host header must be rejected with 400.
        $response = $this->processNoHostHeader([], ['example.com' => fn() => 'ok'], '1.1');

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testMissingHostOnHttp10PassesThrough(): void
    {
        // HTTP/1.0 without Host is allowed (RFC 7230 §5.4).
        $response = $this->processNoHostHeader([], ['example.com' => fn() => 'ok'], '1.0');

        // Falls through to fallthrough handler (no matching host, no catch-all)
        $this->assertSame('FALLTHROUGH', (string) $response->getBody());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDuplicateHostHeaderReturns400(): void
    {
        $mw = new HostRouterMiddleware(['example.com' => fn() => 'ok']);
        // Build a request with two Host headers
        $request = (new ServerRequest('/', 'GET', '', []))
            ->withProtocolVersion('1.1')
            ->withHeader('Host', 'example.com')
            ->withAddedHeader('Host', 'evil.com');

        $fallthroughHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('FALLTHROUGH', 200, '', ['Content-Type' => 'text/plain']);
            }
        };

        $response = $mw->process($request, $fallthroughHandler);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testInvalidHostCharactersReturn400(): void
    {
        // Characters like < > { } are not valid in Host headers
        $response = $this->process(
            ['example.com' => fn() => 'ok'],
            'exam<ple>.com'
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testInvalidHostWithConsecutiveDotsReturns400(): void
    {
        $response = $this->process(
            ['example.com' => fn() => 'ok'],
            'exam..ple.com'
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testValidHostWithHyphensAndDotsIsAccepted(): void
    {
        $response = $this->process(
            ['my-host.example.com' => fn() => 'valid'],
            'my-host.example.com'
        );

        $this->assertSame('valid', (string) $response->getBody());
    }

    // -------------------------------------------------------------------------
    // Response coercion (pre-existing, kept for coverage)
    // -------------------------------------------------------------------------

    public function testHandlerReturningResponseInterfaceIsUsedDirectly(): void
    {
        $response = $this->process(
            ['api.example.com' => fn() => new Response('teapot', 418, '', ['Content-Type' => 'text/plain'])],
            'api.example.com'
        );

        $this->assertSame(418, $response->getStatusCode());
        $this->assertSame('teapot', (string) $response->getBody());
    }

    public function testIntResultBecomesStatusCode(): void
    {
        $response = $this->process(
            ['api.example.com' => fn() => 503],
            'api.example.com'
        );

        $this->assertSame(503, $response->getStatusCode());
    }

    public function testArrayResultBecomesJsonWith200(): void
    {
        $response = $this->process(
            ['api.example.com' => fn() => ['status' => 'ok', 'n' => 7]],
            'api.example.com'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"status":"ok","n":7}', (string) $response->getBody());
    }

    public function testObjectResultBecomesJson(): void
    {
        $obj = (object) ['a' => 1];
        $response = $this->process(
            ['api.example.com' => fn() => $obj],
            'api.example.com'
        );

        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"a":1}', (string) $response->getBody());
    }

    public function testNullResultBecomesEmpty200(): void
    {
        $response = $this->process(
            ['api.example.com' => fn() => null],
            'api.example.com'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    public function testScalarResultBecomesHtmlBody(): void
    {
        $response = $this->process(
            ['api.example.com' => fn() => 3.5],
            'api.example.com'
        );

        $this->assertSame('3.5', (string) $response->getBody());
        $this->assertSame('text/html', $response->getHeaderLine('Content-Type'));
    }

    // -------------------------------------------------------------------------
    // $g->server['HTTP_HOST'] fallback (pre-existing)
    // -------------------------------------------------------------------------

    public function testHostFromServerHttpHostMixedCaseLowercased(): void
    {
        $response = $this->processNoHostHeader(
            ['HTTP_HOST' => 'Fallback.COM'],
            ['fallback.com' => fn() => 'from-server']
        );

        $this->assertSame('from-server', (string) $response->getBody());
    }

    public function testHostFromServerHttpHostNonStringScalarCast(): void
    {
        $response = $this->processNoHostHeader(
            ['HTTP_HOST' => 8080],
            ['8080' => fn() => 'numeric-server-host']
        );

        $this->assertSame('numeric-server-host', (string) $response->getBody());
    }

    // -------------------------------------------------------------------------
    // Constructor validation
    // -------------------------------------------------------------------------

    public function testNonCallableHandlerThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HostRouterMiddleware(['bad.com' => 'not-callable']);
    }
}
