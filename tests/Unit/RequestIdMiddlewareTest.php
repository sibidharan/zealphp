<?php
namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\RequestIdMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class RequestIdMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        $g = RequestContext::instance();
        $g->memo = [];
        // The live-OpenSwoole-response mirror slot is process-wide in
        // superglobals mode — clear it so the default path (no live response)
        // is exercised unless a test explicitly installs a stub.
        $g->zealphp_response = null;
    }

    protected function tearDown(): void
    {
        // Don't let a stubbed response leak into other test classes that share
        // the process-wide RequestContext singleton.
        RequestContext::instance()->zealphp_response = null;
    }

    /**
     * Minimal stub mirroring the single method RequestIdMiddleware calls on
     * the live OpenSwoole response — records each header() call so a test can
     * assert the EXACT (name, value) the middleware mirrored onto it.
     */
    private function responseSpy(): object
    {
        return new class {
            /** @var array<int, array{0: string, 1: string}> */
            public array $headerCalls = [];

            public function header(string $key, string $value): bool
            {
                $this->headerCalls[] = [$key, $value];
                return true;
            }
        };
    }

    private function terminal(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('ok', 200);
            }
        };
    }

    public function testGeneratesIdWhenNoneInbound(): void
    {
        $mw = new RequestIdMiddleware();
        $response = $mw->process(new ServerRequest('/', 'GET'), $this->terminal());

        $id = $response->getHeaderLine('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function testExposesIdToHandlersViaMemo(): void
    {
        $mw = new RequestIdMiddleware();
        $response = $mw->process(new ServerRequest('/', 'GET'), $this->terminal());

        $this->assertTrue(RequestContext::has('request_id'));
        $this->assertSame(
            $response->getHeaderLine('X-Request-Id'),
            RequestContext::once('request_id', fn() => null)
        );
    }

    public function testTrustsInboundIdByDefault(): void
    {
        $mw = new RequestIdMiddleware();
        $request = new ServerRequest('/', 'GET', '', ['x-request-id' => 'upstream-123']);
        $response = $mw->process($request, $this->terminal());

        $this->assertSame('upstream-123', $response->getHeaderLine('X-Request-Id'));
    }

    public function testMintsFreshIdWhenInboundNotTrusted(): void
    {
        $mw = new RequestIdMiddleware('X-Request-Id', false);
        $request = new ServerRequest('/', 'GET', '', ['x-request-id' => 'upstream-123']);
        $response = $mw->process($request, $this->terminal());

        $id = $response->getHeaderLine('X-Request-Id');
        $this->assertNotSame('upstream-123', $id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function testCustomHeaderName(): void
    {
        $mw = new RequestIdMiddleware('X-Correlation-Id');
        $response = $mw->process(new ServerRequest('/', 'GET'), $this->terminal());

        $this->assertNotSame('', $response->getHeaderLine('X-Correlation-Id'));
        $this->assertSame('', $response->getHeaderLine('X-Request-Id'));
    }

    // ------------------------------------------------------------------
    // process() — the live-OpenSwoole-response mirror branch (l.67-69)
    // This is the previously-uncovered method branch: when
    // $g->zealphp_response !== null the id is ALSO written onto the live
    // response via ->header($headerName, $id) so streaming / fallback paths
    // see it. Kills the IfNegation on `!== null` and the FunctionCallRemoval
    // of the ->header() call.
    // ------------------------------------------------------------------

    public function testMirrorsIdOntoLiveResponseWhenPresent(): void
    {
        $spy = $this->responseSpy();
        RequestContext::instance()->zealphp_response = $spy;

        $mw = new RequestIdMiddleware();
        $response = $mw->process(new ServerRequest('/', 'GET'), $this->terminal());

        $id = $response->getHeaderLine('X-Request-Id');
        // The live response received EXACTLY one header() call, with the same
        // header name and the same id that the PSR response carries.
        $this->assertCount(1, $spy->headerCalls);
        $this->assertSame(['X-Request-Id', $id], $spy->headerCalls[0]);
        // And the id is a freshly-minted 32-hex correlation id.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function testMirrorsInboundIdOntoLiveResponse(): void
    {
        // The mirrored value is the SAME id resolution as the PSR response —
        // a trusted inbound id is mirrored verbatim, not a fresh one.
        $spy = $this->responseSpy();
        RequestContext::instance()->zealphp_response = $spy;

        $mw = new RequestIdMiddleware();
        $request = new ServerRequest('/', 'GET', '', ['x-request-id' => 'upstream-xyz']);
        $response = $mw->process($request, $this->terminal());

        $this->assertSame('upstream-xyz', $response->getHeaderLine('X-Request-Id'));
        $this->assertSame([['X-Request-Id', 'upstream-xyz']], $spy->headerCalls);
    }

    public function testMirrorsCustomHeaderNameOntoLiveResponse(): void
    {
        // The custom header name flows into BOTH the live response header() call
        // and the PSR withHeader(). Kills any operand swap that hardcodes the
        // default name in either sink.
        $spy = $this->responseSpy();
        RequestContext::instance()->zealphp_response = $spy;

        $mw = new RequestIdMiddleware('X-Trace-Id');
        $response = $mw->process(new ServerRequest('/', 'GET'), $this->terminal());

        $id = $response->getHeaderLine('X-Trace-Id');
        $this->assertSame([['X-Trace-Id', $id]], $spy->headerCalls);
        // The default name is NOT used on the live response.
        $this->assertSame('', $response->getHeaderLine('X-Request-Id'));
    }

    public function testNoLiveResponseLeavesPsrPathIntact(): void
    {
        // With NO live response installed the ->header() branch is skipped
        // (the null guard's false arm) yet the PSR response still carries the
        // id. Kills the IfNegation that would force-call header() on null.
        RequestContext::instance()->zealphp_response = null;

        $mw = new RequestIdMiddleware();
        $response = $mw->process(new ServerRequest('/', 'GET'), $this->terminal());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $response->getHeaderLine('X-Request-Id'));
    }

    // ------------------------------------------------------------------
    // process() — memo key + trustInbound branch exactness
    // ------------------------------------------------------------------

    public function testMemoKeyIsExactlyRequestId(): void
    {
        // The id is stored under the EXACT memo key 'request_id' (l.66). Kills
        // any mutation of that literal key — RequestContext::once reads it back.
        $mw = new RequestIdMiddleware();
        $response = $mw->process(new ServerRequest('/', 'GET'), $this->terminal());

        $memo = RequestContext::instance()->memo;
        $this->assertArrayHasKey('request_id', $memo);
        $this->assertSame($response->getHeaderLine('X-Request-Id'), $memo['request_id']);
    }

    public function testTrustInboundWithEmptyHeaderMintsFresh(): void
    {
        // trustInbound=true but the inbound header value is empty string: the
        // `$inbound !== ''` guard is false so a fresh id is minted (l.55-60).
        // Kills the IfNegation on `$inbound !== ''` — the mutant would adopt
        // the empty inbound value and emit an empty id.
        $mw = new RequestIdMiddleware();
        $request = new ServerRequest('/', 'GET', '', ['x-request-id' => '']);
        $response = $mw->process($request, $this->terminal());

        $id = $response->getHeaderLine('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        $this->assertNotSame('', $id);
    }

    public function testTwoRequestsGetDistinctMintedIds(): void
    {
        // Each mint is independent random_bytes(16) — distinct ids prove the
        // generation path actually runs per call (kills a constant-return mut).
        $mw = new RequestIdMiddleware();
        $a = $mw->process(new ServerRequest('/', 'GET'), $this->terminal())
            ->getHeaderLine('X-Request-Id');
        RequestContext::instance()->memo = [];
        $b = $mw->process(new ServerRequest('/', 'GET'), $this->terminal())
            ->getHeaderLine('X-Request-Id');

        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $b);
    }
}
