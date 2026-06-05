<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\HTTP\Response as ZResponse;
use ZealPHP\Middleware\RangeMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * #258 — date-form If-Range must be evaluated identically by BOTH range paths.
 *
 * The buffered RangeMiddleware path and the zero-copy Response::sendFile()
 * (ifRangeMatches) path previously disagreed: the middleware used Apache's 60 s
 * clock-skew rule, sendFile used exact-second. Same request → 206 on one path,
 * 200 on the other. The fix factors a single shared strong-validation helper
 * (Response::ifRangeDateMatches, the 60 s skew rule per RFC 9110 §13.1.5) and
 * calls it from both. These tests pin the shared helper across the matrix AND
 * assert the two paths now agree on identical (ifRange, lastModified, mtime,
 * reqTime) tuples.
 */
class IfRangeDateParityTest extends TestCase
{
    private const BODY = 'Hello, World! This is test content for range requests.';

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        // RangeMiddleware early-returns when $g->_streaming is truthy — make sure
        // a prior test class didn't leave it set, else the matrix parity check
        // sees a spurious 200.
        $g = RequestContext::instance();
        $g->_streaming = false;
        $g->status = null;
    }

    protected function tearDown(): void
    {
        $g = RequestContext::instance();
        $g->_streaming = false;
        $g->status = null;
        $g->zealphp_response = null;
        parent::tearDown();
    }

    // ── Shared helper: the four canonical cases ───────────────────────────

    public function testHelperMatchBeyond60sHonours(): void
    {
        $mtime = time() - 120;                 // file modified 2 min ago
        $ifRange = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        // Served "now" → 120 s skew ≥ 60 → strong match → honour.
        $this->assertTrue(ZResponse::ifRangeDateMatches($ifRange, $mtime, time()));
    }

    public function testHelperMatchWithin60sIgnores(): void
    {
        $mtime = time() - 10;                  // file modified 10 s ago
        $ifRange = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        // Served "now" → only 10 s skew < 60 → weak → ignore (the divergence).
        $this->assertFalse(ZResponse::ifRangeDateMatches($ifRange, $mtime, time()));
    }

    public function testHelperDatesDifferIgnores(): void
    {
        $mtime = time() - 120;
        $ifRange = gmdate('D, d M Y H:i:s', $mtime - 5) . ' GMT'; // 5 s off mtime
        $this->assertFalse(ZResponse::ifRangeDateMatches($ifRange, $mtime, time()));
    }

    public function testHelperUnparseableIgnores(): void
    {
        $mtime = time() - 120;
        $this->assertFalse(ZResponse::ifRangeDateMatches('not-a-date', $mtime, time()));
    }

    public function testHelperNullReqTimeUsesWallClock(): void
    {
        // sendFile passes reqTime = null → helper falls back to now().
        $mtime = time() - 120;
        $ifRange = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        $this->assertTrue(ZResponse::ifRangeDateMatches($ifRange, $mtime, null));
    }

    // ── sendFile's private ifRangeMatches delegates to the shared helper ───

    public function testSendFilePrivateMatcherDelegatesToSharedHelper(): void
    {
        // Drive the private ifRangeMatches() via reflection with a date-form
        // If-Range and confirm it now follows the 60 s skew rule (a fresh file,
        // < 60 s, is NOT honoured — proving it no longer uses exact-second).
        $fake = new \ZealPHP\Tests\Unit\HTTP\FakeOpenSwooleResponse();
        $resp = new ZResponse($fake);
        $ref = new \ReflectionMethod(ZResponse::class, 'ifRangeMatches');

        $freshMtime = time() - 10;
        $ifRangeFresh = gmdate('D, d M Y H:i:s', $freshMtime) . ' GMT';
        $etag = 'W/"deadbeef-10"';
        $this->assertFalse(
            (bool) $ref->invoke($resp, $ifRangeFresh, $etag, $freshMtime),
            'fresh (<60s) date If-Range must be ignored — 60s skew rule, not exact-second'
        );

        $oldMtime = time() - 300;
        $ifRangeOld = gmdate('D, d M Y H:i:s', $oldMtime) . ' GMT';
        $this->assertTrue(
            (bool) $ref->invoke($resp, $ifRangeOld, $etag, $oldMtime),
            'old (>60s) matching date If-Range must be honoured'
        );
    }

    // ── Cross-path parity: middleware result == shared-helper verdict ──────

    /**
     * Run RangeMiddleware with explicit Last-Modified + Date response headers
     * and a date-form If-Range. Returns the resulting status (206 = honoured,
     * 200 = ignored).
     */
    private function middlewareStatus(string $ifRange, int $mtime, int $reqTime): int
    {
        $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        $dateHeader   = gmdate('D, d M Y H:i:s', $reqTime) . ' GMT';

        $request = new ServerRequest('/', 'GET', '', [
            'range'    => 'bytes=0-4',
            'if-range' => $ifRange,
        ]);
        $handler = new class($lastModified, $dateHeader) implements RequestHandlerInterface {
            public function __construct(private string $lastModified, private string $dateHeader) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(IfRangeDateParityTest::body(), 200, '', [
                    'Content-Type'  => 'text/plain',
                    'Last-Modified' => $this->lastModified,
                    'Date'          => $this->dateHeader,
                ]);
            }
        };

        $g = RequestContext::instance();
        $prev = $g->zealphp_response;
        $g->zealphp_response = new class {
            public function header(string $name, string $value): void {}
        };
        try {
            $response = (new RangeMiddleware())->process($request, $handler);
        } finally {
            $g->zealphp_response = $prev;
        }
        return $response->getStatusCode();
    }

    public static function body(): string
    {
        return self::BODY;
    }

    public function testMiddlewareAndHelperAgreeAcrossMatrix(): void
    {
        $now = time();
        $cases = [
            // [label, mtime, ifRangeOffsetFromMtime, reqTime]
            'match beyond 60s' => [$now - 120, 0,  $now],
            'match within 60s' => [$now - 10,  0,  $now],
            'dates differ'     => [$now - 120, -5, $now],
        ];

        foreach ($cases as $label => [$mtime, $ifOffset, $reqTime]) {
            $ifRange = gmdate('D, d M Y H:i:s', $mtime + $ifOffset) . ' GMT';

            $helperHonours = ZResponse::ifRangeDateMatches($ifRange, $mtime, $reqTime);
            $mwStatus      = $this->middlewareStatus($ifRange, $mtime, $reqTime);
            $mwHonours     = ($mwStatus === 206);

            $this->assertSame(
                $helperHonours,
                $mwHonours,
                "Path divergence on '{$label}': shared helper "
                . ($helperHonours ? 'HONOURS' : 'IGNORES')
                . " but RangeMiddleware returned {$mwStatus}"
            );
        }
    }
}
