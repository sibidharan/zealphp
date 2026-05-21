<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Middleware\CompressionMiddleware;
use ZealPHP\Tests\TestCase;

/**
 * Branch coverage for src/Middleware/CompressionMiddleware.php not reached by
 * CompressionMiddlewareTest.php:
 *
 *   - deflate selection (gzip absent, deflate present)
 *   - streaming-response skip ($g->_streaming)
 *   - already-encoded skip (Content-Encoding present on handler response)
 *   - min-length threshold skip (body shorter than minLength)
 *   - uncompressible content-type skip (image/* etc.)
 *   - no acceptable encoding → pass-through unchanged
 */
class CompressionExtraTest extends TestCase
{
    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
    }

    protected function tearDown(): void
    {
        RequestContext::instance()->_streaming = null;
        parent::tearDown();
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $responseHeaders
     */
    private function exec(
        array $headers,
        string $body,
        array $responseHeaders = ['Content-Type' => 'text/html'],
        int $minLength = 1
    ): ResponseInterface {
        $middleware = new CompressionMiddleware(minLength: $minLength);
        $request = new ServerRequest('/', 'GET', '', $headers);
        $handler = new class($body, $responseHeaders) implements RequestHandlerInterface {
            /** @param array<string,string> $h */
            public function __construct(private string $body, private array $h) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->body, 200, '', $this->h);
            }
        };
        return $middleware->process($request, $handler);
    }

    public function testDeflateSelectedWhenGzipAbsent(): void
    {
        $body = str_repeat('hello world ', 200);
        $response = $this->exec(['accept-encoding' => 'deflate'], $body);
        $this->assertSame('deflate', $response->getHeaderLine('Content-Encoding'));
        $this->assertSame('Accept-Encoding', $response->getHeaderLine('Vary'));
        $this->assertSame($body, gzinflate((string)$response->getBody()));
    }

    public function testGzipPreferredOverDeflate(): void
    {
        $body = str_repeat('hello world ', 200);
        $response = $this->exec(['accept-encoding' => 'gzip, deflate'], $body);
        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    public function testStreamingResponseIsNotCompressed(): void
    {
        RequestContext::instance()->_streaming = true;
        $body = str_repeat('hello world ', 200);
        $response = $this->exec(['accept-encoding' => 'gzip'], $body);
        $this->assertFalse($response->hasHeader('Content-Encoding'));
        $this->assertSame($body, (string)$response->getBody());
    }

    public function testAlreadyEncodedResponseIsSkipped(): void
    {
        $body = str_repeat('hello world ', 200);
        $response = $this->exec(
            ['accept-encoding' => 'gzip'],
            $body,
            ['Content-Type' => 'text/html', 'Content-Encoding' => 'br']
        );
        // Stays as the original br encoding — not re-gzipped.
        $this->assertSame('br', $response->getHeaderLine('Content-Encoding'));
    }

    public function testBodyBelowMinLengthIsSkipped(): void
    {
        $response = $this->exec(['accept-encoding' => 'gzip'], 'tiny', minLength: 1024);
        $this->assertFalse($response->hasHeader('Content-Encoding'));
        $this->assertSame('tiny', (string)$response->getBody());
    }

    public function testUncompressibleContentTypeIsSkipped(): void
    {
        $body = str_repeat("\x00\x01\x02\x03", 400);
        $response = $this->exec(
            ['accept-encoding' => 'gzip'],
            $body,
            ['Content-Type' => 'image/png']
        );
        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }

    public function testNoAcceptableEncodingPassesThrough(): void
    {
        $body = str_repeat('hello world ', 200);
        $response = $this->exec(['accept-encoding' => 'br'], $body);
        $this->assertFalse($response->hasHeader('Content-Encoding'));
        $this->assertSame($body, (string)$response->getBody());
    }

    public function testMissingAcceptEncodingPassesThrough(): void
    {
        $body = str_repeat('hello world ', 200);
        $response = $this->exec([], $body);
        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }
}
