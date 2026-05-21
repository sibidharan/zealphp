<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\ContentEncodingMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class ContentEncodingMiddlewareTest extends TestCase
{
    /** @var object{calls: array<int, array{0: string, 1: string}>} */
    private object $recorder;

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $this->recorder = new class {
            /** @var array<int, array{0: string, 1: string}> */
            public array $calls = [];
            public function header(string $name, string $value): void
            {
                $this->calls[] = [$name, $value];
            }
        };
        RequestContext::instance()->zealphp_response = $this->recorder;
    }

    protected function tearDown(): void
    {
        RequestContext::instance()->zealphp_response = null;
    }

    /**
     * @param array<string, string|int> $map
     * @param array<string, string>     $headers
     */
    private function process(string $path, array $map, array $headers = []): ResponseInterface
    {
        $middleware = new ContentEncodingMiddleware($map);
        $request = new ServerRequest($path, 'GET', '', []);

        $handler = new class($headers) implements RequestHandlerInterface {
            /** @param array<string, string> $headers */
            public function __construct(private array $headers) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('body', 200, '', $this->headers);
            }
        };

        return $middleware->process($request, $handler);
    }

    public function testSetsEncodingFromSuffix(): void
    {
        $response = $this->process('/document.html.gz', ['gz' => 'gzip']);
        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
        $this->assertCount(1, $this->recorder->calls);
        $this->assertSame(['Content-Encoding', 'gzip'], $this->recorder->calls[0]);
    }

    public function testEncodingChainOrderPreserved(): void
    {
        $response = $this->process('/data.br.gz', ['gz' => 'gzip', 'br' => 'br']);
        $this->assertSame('br, gzip', $response->getHeaderLine('Content-Encoding'));
    }

    public function testEmptyMapIsNoOp(): void
    {
        $response = $this->process('/document.html.gz', []);
        $this->assertFalse($response->hasHeader('Content-Encoding'));
        $this->assertCount(0, $this->recorder->calls);
    }

    public function testUnmappedSuffixIsNoOp(): void
    {
        $response = $this->process('/document.html', ['gz' => 'gzip']);
        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }

    public function testDotfileGetsNoEncoding(): void
    {
        // ".gz" is a hidden file, not a gzip-encoded resource.
        $response = $this->process('/.gz', ['gz' => 'gzip']);
        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }

    public function testExistingEncodingIsNotOverwritten(): void
    {
        $response = $this->process('/document.html.gz', ['gz' => 'gzip'], ['Content-Encoding' => 'br']);
        $this->assertSame('br', $response->getHeaderLine('Content-Encoding'));
        $this->assertCount(0, $this->recorder->calls);
    }
}
