<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\ContentLanguageMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class ContentLanguageMiddlewareTest extends TestCase
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
        $middleware = new ContentLanguageMiddleware($map);
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

    public function testSetsLanguageFromSuffix(): void
    {
        $response = $this->process('/page.fr.html', ['fr' => 'fr']);
        $this->assertSame('fr', $response->getHeaderLine('Content-Language'));
        $this->assertCount(1, $this->recorder->calls);
        $this->assertSame(['Content-Language', 'fr'], $this->recorder->calls[0]);
    }

    public function testLanguageChainOrderPreserved(): void
    {
        $response = $this->process('/page.en.fr.html', ['en' => 'en', 'fr' => 'fr']);
        $this->assertSame('en, fr', $response->getHeaderLine('Content-Language'));
    }

    public function testEmptyMapIsNoOp(): void
    {
        $response = $this->process('/page.fr.html', []);
        $this->assertFalse($response->hasHeader('Content-Language'));
        $this->assertCount(0, $this->recorder->calls);
    }

    public function testUnmappedSuffixIsNoOp(): void
    {
        $response = $this->process('/page.html', ['fr' => 'fr']);
        $this->assertFalse($response->hasHeader('Content-Language'));
    }

    public function testDotfileGetsNoLanguage(): void
    {
        $response = $this->process('/.fr', ['fr' => 'fr']);
        $this->assertFalse($response->hasHeader('Content-Language'));
    }

    public function testExistingLanguageIsNotOverwritten(): void
    {
        $response = $this->process('/page.fr.html', ['fr' => 'fr'], ['Content-Language' => 'de']);
        $this->assertSame('de', $response->getHeaderLine('Content-Language'));
        $this->assertCount(0, $this->recorder->calls);
    }
}
