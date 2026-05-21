<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\IpAccessMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class IpAccessMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        // Force the middleware down its request-server-params fallback path so
        // each test controls the client IP precisely.
        RequestContext::instance()->server = [];
        RequestContext::instance()->status = null;
    }

    /**
     * @param array{allow?: string[], deny?: string[]} $config
     */
    private function process(array $config, string $clientIp): ResponseInterface
    {
        $middleware = new IpAccessMiddleware($config);

        $request = (new ServerRequest('/', 'GET', '', []))
            ->withServerParams(['REMOTE_ADDR' => $clientIp]);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/html']);
            }
        };

        return $middleware->process($request, $handler);
    }

    public function testDefaultAllowsEveryone(): void
    {
        // Default config => allow ['*'], deny [] => pass. NOTE: the L70
        // ArrayItemRemoval mutant (['*'] -> []) is an EQUIVALENT mutant —
        // allow=['*'] passes everything, and allow=[] makes !empty() false so
        // the allow check is skipped, also passing everything. No observable
        // behaviour distinguishes them.
        $response = $this->process([], '203.0.113.99');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', (string)$response->getBody());
    }

    public function testDenyWinsOverWildcardAllow(): void
    {
        // allow ['*'] but deny the specific IP => 403. Proves deny is checked
        // before the allow short-circuit.
        $response = $this->process(['allow' => ['*'], 'deny' => ['1.2.3.4']], '1.2.3.4');
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden', (string)$response->getBody());
        $this->assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        $this->assertSame(403, RequestContext::instance()->status);
    }

    public function testDenyListMissPassesWithWildcard(): void
    {
        $response = $this->process(['allow' => ['*'], 'deny' => ['1.2.3.4']], '5.6.7.8');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAllowListOnlyPassesInList(): void
    {
        $response = $this->process(['allow' => ['198.51.100.42'], 'deny' => []], '198.51.100.42');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAllowListOnlyBlocksOutOfList(): void
    {
        $response = $this->process(['allow' => ['198.51.100.42'], 'deny' => []], '198.51.100.43');
        $this->assertSame(403, $response->getStatusCode());
    }

    // ---- CIDR boundary cases (kill DecrementInteger/GreaterThan/Identical) ----

    #[DataProvider('cidrCases')]
    public function testCidrBoundaries(string $cidr, string $ip, bool $expectAllowed): void
    {
        $response = $this->process(['allow' => [$cidr], 'deny' => []], $ip);
        $this->assertSame(
            $expectAllowed ? 200 : 403,
            $response->getStatusCode(),
            "$ip against $cidr should " . ($expectAllowed ? 'pass' : 'be blocked')
        );
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function cidrCases(): array
    {
        return [
            // /8 — only first byte matters (bytes=1, rem=0 path)
            '10.0.0.0/8 in-range first byte'     => ['10.0.0.0/8', '10.255.255.255', true],
            '10.0.0.0/8 just-outside first byte' => ['10.0.0.0/8', '11.0.0.0', false],
            '10.0.0.0/8 below boundary'          => ['10.0.0.0/8', '9.255.255.255', false],

            // /24 — three full bytes (bytes=3, rem=0)
            '203.0.113.0/24 in-range'  => ['203.0.113.0/24', '203.0.113.200', true],
            '203.0.113.0/24 outside'   => ['203.0.113.0/24', '203.0.114.1', false],

            // /25 — partial last byte (bytes=3, rem=1): mask path
            '192.168.1.0/25 low half in'   => ['192.168.1.0/25', '192.168.1.127', true],
            '192.168.1.0/25 high half out' => ['192.168.1.0/25', '192.168.1.128', false],

            // /28 — rem=4 mask
            '192.168.1.0/28 in'  => ['192.168.1.0/28', '192.168.1.15', true],
            '192.168.1.0/28 out' => ['192.168.1.0/28', '192.168.1.16', false],

            // /32 — exact single host (bytes=4, rem=0)
            '192.168.1.5/32 exact'    => ['192.168.1.5/32', '192.168.1.5', true],
            '192.168.1.5/32 mismatch' => ['192.168.1.5/32', '192.168.1.6', false],

            // /0 — matches everything (bytes=0, rem=0): kills GreaterThan
            // mutant ($bytes > 0 -> $bytes >= 0 would early-return false for
            // any differing first byte even with /0).
            '0.0.0.0/0 matches any' => ['0.0.0.0/0', '8.8.8.8', true],

            // IPv6 CIDR
            '2001:db8::/32 in'  => ['2001:db8::/32', '2001:db8:1234::1', true],
            '2001:db8::/32 out' => ['2001:db8::/32', '2001:dba8::1', false],

            // Mixed v4/v6 — different binary lengths => no match
            'v4 cidr vs v6 ip' => ['10.0.0.0/8', '::1', false],
        ];
    }

    public function testMalformedCidrSubnetDoesNotMatch(): void
    {
        // inet_pton fails on the subnet => cidrMatch returns false.
        // Kills the FalseValue/LogicalOr mutants on the inet_pton guard.
        $response = $this->process(['allow' => ['not-an-ip/8'], 'deny' => []], '10.0.0.1');
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMalformedClientIpDoesNotMatchCidr(): void
    {
        $response = $this->process(['allow' => ['10.0.0.0/8'], 'deny' => []], 'garbage');
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testServerParamRemoteAddrIsRead(): void
    {
        // Kills the CastString mutant at L90 and proves REMOTE_ADDR from
        // $g->server takes precedence over the request server-params fallback.
        RequestContext::instance()->server = ['REMOTE_ADDR' => '198.51.100.42'];
        try {
            $middleware = new IpAccessMiddleware(['allow' => ['198.51.100.42'], 'deny' => []]);
            // Request server params point at a DIFFERENT, blocked IP — if the
            // middleware ignored $g->server it would 403.
            $request = (new ServerRequest('/', 'GET', '', []))
                ->withServerParams(['REMOTE_ADDR' => '1.1.1.1']);
            $handler = new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response('OK', 200, '', ['Content-Type' => 'text/html']);
                }
            };
            $response = $middleware->process($request, $handler);
            $this->assertSame(200, $response->getStatusCode());
        } finally {
            RequestContext::instance()->server = [];
        }
    }

    public function testEmptyClientIpNeverMatches(): void
    {
        // No REMOTE_ADDR anywhere => clientIp '' => matchesAny returns false
        // for both deny and allow. allow=['*'] non-empty + no match => 403.
        $response = $this->process(['allow' => ['*'], 'deny' => []], '');
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testEmptyClientIpPassesWhenOnlyDenyConfigured(): void
    {
        // Kills L107 FalseValue (return false -> true on empty IP). With empty
        // allow + a deny list, the correct code lets the empty IP through
        // (matchesAny('') === false for deny, allow empty => pass => 200).
        // The mutant would treat the empty IP as matching the deny list => 403.
        $response = $this->process(['allow' => [], 'deny' => ['1.2.3.4']], '');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIntegerRemoteAddrFromServerIsStringified(): void
    {
        // Kills L90 CastString. Under strict_types, dropping (string) on a
        // non-string REMOTE_ADDR makes clientIp return an int, which then trips
        // a TypeError at matchesAny(string $ip). Set an int REMOTE_ADDR whose
        // string form matches the allow rule.
        RequestContext::instance()->server = ['REMOTE_ADDR' => 12345];
        try {
            $middleware = new IpAccessMiddleware(['allow' => ['12345'], 'deny' => []]);
            $request = new ServerRequest('/', 'GET', '', []);
            $handler = new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response('OK', 200, '', ['Content-Type' => 'text/html']);
                }
            };
            $response = $middleware->process($request, $handler);
            $this->assertSame(200, $response->getStatusCode());
        } finally {
            RequestContext::instance()->server = [];
        }
    }

    public function testIntegerRemoteAddrFromServerParamsIsStringified(): void
    {
        // Kills L98 CastString on the request-server-params fallback path.
        // $g->server has no REMOTE_ADDR, so the fallback reads the int from the
        // request server params; dropping (string) returns an int -> TypeError.
        $middleware = new IpAccessMiddleware(['allow' => ['12345'], 'deny' => []]);
        $request = (new ServerRequest('/', 'GET', '', []))
            ->withServerParams(['REMOTE_ADDR' => 12345]);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/html']);
            }
        };
        $response = $middleware->process($request, $handler);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testForbiddenResponseHasContentTypeHeader(): void
    {
        // Kills ArrayItemRemoval at L151 (headers [] -> drops Content-Type).
        $response = $this->process(['allow' => ['9.9.9.9'], 'deny' => []], '8.8.8.8');
        $this->assertSame(403, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Content-Type'));
        $this->assertSame('text/plain', $response->getHeaderLine('Content-Type'));
    }
}
