<?php
namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\RangeMiddleware;
use ZealPHP\Tests\TestCase;

class RangeMiddlewareTest extends TestCase
{
    public const BODY = 'Hello, World! This is test content for range requests.';

    public function testSingleRangeReturns206(): void
    {
        $response = $this->processRange('bytes=0-4');

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('Hello', (string) $response->getBody());
        $this->assertSame('bytes 0-4/54', $response->getHeaderLine('Content-Range'));
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
    }

    public function testSingleRangeSuffix(): void
    {
        $response = $this->processRange('bytes=-5');

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('ests.', (string) $response->getBody());
        $this->assertSame('bytes 49-53/54', $response->getHeaderLine('Content-Range'));
    }

    public function testSingleRangeOpenEnd(): void
    {
        $response = $this->processRange('bytes=50-');

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('sts.', (string) $response->getBody());
        $this->assertSame('bytes 50-53/54', $response->getHeaderLine('Content-Range'));
    }

    public function testNoRangeHeaderAddsAcceptRanges(): void
    {
        $response = $this->processRange(null);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::BODY, (string) $response->getBody());
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
    }

    public function testUnsatisfiableRangeReturns416(): void
    {
        $response = $this->processRange('bytes=100-200');

        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */54', $response->getHeaderLine('Content-Range'));
    }

    public function testSkipsNon200Responses(): void
    {
        $response = $this->processRange('bytes=0-4', 301);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(self::BODY, (string) $response->getBody());
    }

    public function testSkipsEmptyBody(): void
    {
        $response = $this->processRange('bytes=0-4', 200, '');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSkipsPostRequests(): void
    {
        $response = $this->processRange('bytes=0-4', 200, null, 'POST');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::BODY, (string) $response->getBody());
    }

    private function processRange(
        ?string $rangeHeader,
        int $status = 200,
        ?string $body = null,
        string $method = 'GET'
    ): ResponseInterface {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $middleware = new RangeMiddleware();

        $headers = [];
        if ($rangeHeader !== null) {
            $headers['range'] = $rangeHeader;
        }

        $request = new ServerRequest('/', $method, '', $headers);
        $responseBody = $body ?? self::BODY;

        $handler = new class($responseBody, $status) implements RequestHandlerInterface {
            public function __construct(private string $body, private int $status) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->body, $this->status, '', ['Content-Type' => 'text/plain']);
            }
        };

        return $middleware->process($request, $handler);
    }
}
