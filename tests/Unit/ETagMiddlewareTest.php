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

    public function testSkippedForNonGetMethod(): void
    {
        $g = RequestContext::instance();
        $response = $this->process('POST', []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('ETag', $g->zealphp_response->headers);
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
