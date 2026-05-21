<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\ETagMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class ETagMiddlewareTest extends TestCase
{
    private const BODY = 'hello world';

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        App::$file_etag = true;
        $g = RequestContext::instance();
        $g->status = null;
        $g->_streaming = null;
        $g->zealphp_response = $this->headerRecorder();
    }

    protected function tearDown(): void
    {
        $g = RequestContext::instance();
        $g->zealphp_response = null;
        $g->status = null;
        $g->_streaming = null;
        App::$file_etag = true;
        parent::tearDown();
    }

    private function expectedEtag(): string
    {
        return 'W/"' . hash('xxh3', self::BODY) . '"';
    }

    public function testSetsEtagHeaderOnGetWithBody(): void
    {
        $g = RequestContext::instance();
        $response = $this->process('GET', []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->expectedEtag(), $g->zealphp_response->headers['ETag'] ?? null);
    }

    public function testReturns304WhenIfNoneMatchMatches(): void
    {
        $g = RequestContext::instance();
        $response = $this->process('GET', ['if-none-match' => $this->expectedEtag()]);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        // Kills DecrementInteger (303) / IncrementInteger (305) on $g->status = 304.
        $this->assertSame(304, $g->status);
        $this->assertSame($this->expectedEtag(), $g->zealphp_response->headers['ETag'] ?? null);
    }

    public function testNonMatchingIfNoneMatchReturns200(): void
    {
        $g = RequestContext::instance();
        $response = $this->process('GET', ['if-none-match' => 'W/"deadbeef"']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($g->status);
        $this->assertSame($this->expectedEtag(), $g->zealphp_response->headers['ETag'] ?? null);
    }

    public function testNoEtagHeaderForNonGetMethod(): void
    {
        // ETag is a GET/HEAD representation header; POST without a conditional
        // header proceeds with 200 and no ETag emitted.
        $g = RequestContext::instance();
        $response = $this->process('POST', []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('ETag', $g->zealphp_response->headers);
    }

    public function testHeadRequestGetsEtagHeader(): void
    {
        $g = RequestContext::instance();
        $response = $this->process('HEAD', []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->expectedEtag(), $g->zealphp_response->headers['ETag'] ?? null);
    }

    public function testWildcardIfNoneMatchGetReturns304(): void
    {
        $g = RequestContext::instance();
        $response = $this->process('GET', ['if-none-match' => '*']);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame(304, $g->status);
    }

    public function testWildcardIfNoneMatchNonGetReturns412(): void
    {
        $g = RequestContext::instance();
        $response = $this->process('PUT', ['if-none-match' => '*']);

        $this->assertSame(412, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame(412, $g->status);
    }

    public function testIfMatchNoMatchReturns412(): void
    {
        $g = RequestContext::instance();
        $response = $this->process('PUT', ['if-match' => '"nope"']);

        $this->assertSame(412, $response->getStatusCode());
        $this->assertSame(412, $g->status);
    }

    public function testWeakRequestTokenMatchesStrongStoredEtag304(): void
    {
        // The body-hash ETag is weak; a bare-quoted request token must still
        // 304 under weak comparison for GET.
        $bare = ltrim($this->expectedEtag(), 'W/');
        $g = RequestContext::instance();
        $response = $this->process('GET', ['if-none-match' => $bare]);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame(304, $g->status);
    }

    public function testRangeRequestUsesStrongComparisonNo304(): void
    {
        // GET + Range demands strong comparison; the weak body-hash ETag cannot
        // weak-match, so the request proceeds (200) and the range layer runs.
        $g = RequestContext::instance();
        $response = $this->process('GET', [
            'if-none-match' => $this->expectedEtag(),
            'range' => 'bytes=0-4',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($g->status);
    }

    public function testSkippedWhenFileEtagDisabled(): void
    {
        App::$file_etag = false;
        $g = RequestContext::instance();
        $response = $this->process('GET', []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('ETag', $g->zealphp_response->headers);
    }

    private function process(string $method, array $headers): ResponseInterface
    {
        $mw = new ETagMiddleware();
        $request = new ServerRequest('/', $method, '', $headers);
        $handler = new class(self::BODY) implements RequestHandlerInterface {
            public function __construct(private string $body) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->body, 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process($request, $handler);
    }

    private function headerRecorder(): object
    {
        return new class {
            /** @var array<string,string> */
            public array $headers = [];
            public function header(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }
        };
    }
}
