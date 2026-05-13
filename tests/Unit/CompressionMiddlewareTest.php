<?php
namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\CompressionMiddleware;
use ZealPHP\Tests\TestCase;

class CompressionMiddlewareTest extends TestCase
{
    private const BODY = '<html><body>' . 'proxied content ' . '</body></html>';

    public function testGzipStillAppliesWithoutProxyHeader(): void
    {
        $response = $this->process(['accept-encoding' => 'gzip'], true);

        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    public function testCompressionIsSkippedForForwardedProxyHeader(): void
    {
        $response = $this->process([
            'accept-encoding'   => 'gzip',
            'x-forwarded-proto' => 'https',
        ], true);

        $this->assertFalse($response->hasHeader('Content-Encoding'));
        $this->assertSame($this->body(), (string)$response->getBody());
    }

    public function testProxySkipIsOptIn(): void
    {
        $response = $this->process([
            'accept-encoding'   => 'gzip',
            'x-forwarded-proto' => 'https',
        ], false);

        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    private function process(array $headers, bool $skipProxiedRequests): ResponseInterface
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $middleware = new CompressionMiddleware(
            minLength: 1,
            skipProxiedRequests: $skipProxiedRequests
        );

        $request = new ServerRequest('/', 'GET', '', $headers);
        $body = $this->body();

        $handler = new class($body) implements RequestHandlerInterface {
            public function __construct(private string $body) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->body, 200, '', ['Content-Type' => 'text/html']);
            }
        };

        return $middleware->process($request, $handler);
    }

    private function body(): string
    {
        return str_repeat(self::BODY, 80);
    }
}
