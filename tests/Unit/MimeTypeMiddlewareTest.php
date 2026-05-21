<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\MimeTypeMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class MimeTypeMiddlewareTest extends TestCase
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
     */
    private function process(
        string $path,
        array $map,
        bool $preExistingCt = false
    ): ResponseInterface {
        $middleware = new MimeTypeMiddleware($map);

        $request = new ServerRequest($path, 'GET', '', []);

        $headers = $preExistingCt ? ['Content-Type' => 'text/html'] : [];

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

    public function testSetsExactMimeForExtension(): void
    {
        $response = $this->process('/module.wasm', ['wasm' => 'application/wasm']);
        $this->assertSame('application/wasm', $response->getHeaderLine('Content-Type'));
    }

    public function testWritesExactMimeToRawResponse(): void
    {
        // Kills the MethodCallRemoval at L74 — the raw response recorder must
        // receive the Content-Type, and with the exact mime string (also kills
        // the CastString on the mime value at L52 if it were non-string).
        $this->process('/scene.glb', ['glb' => 'model/gltf-binary']);
        $this->assertCount(1, $this->recorder->calls);
        $this->assertSame('Content-Type', $this->recorder->calls[0][0]);
        $this->assertSame('model/gltf-binary', $this->recorder->calls[0][1]);
    }

    public function testUppercaseExtensionInPathMatches(): void
    {
        $response = $this->process('/MODULE.WASM', ['wasm' => 'application/wasm']);
        $this->assertSame('application/wasm', $response->getHeaderLine('Content-Type'));
    }

    public function testUppercaseMapKeyIsNormalised(): void
    {
        $response = $this->process('/module.wasm', ['WASM' => 'application/wasm']);
        $this->assertSame('application/wasm', $response->getHeaderLine('Content-Type'));
    }

    public function testLeadingDotMapKeyIsTrimmed(): void
    {
        // Kills the UnwrapLtrim mutant at L52 — a '.wasm' config key must store
        // as 'wasm' so the request hits it.
        $response = $this->process('/module.wasm', ['.wasm' => 'application/wasm']);
        $this->assertSame('application/wasm', $response->getHeaderLine('Content-Type'));
    }

    public function testNumericMapKeyIsStringified(): void
    {
        // Kills the CastString mutant at L52 — a purely-numeric config key is
        // cast to int by PHP; without (string) the ltrim() would receive an int
        // and throw under strict_types in the constructor.
        $response = $this->process('/file.123', ['123' => 'application/x-123']);
        $this->assertSame('application/x-123', $response->getHeaderLine('Content-Type'));
    }

    public function testNonStringMimeValueIsStringified(): void
    {
        // Kills the CastString mutant at L52 on the mime VALUE ((string)$mime).
        // A numeric (int) mime value must be stringified into the map; without
        // the cast the stored value stays an int and the recorder's
        // header(string, string) signature receives an int -> TypeError under
        // strict_types -> mutant killed.
        $response = $this->process('/x.num', ['num' => 4242]);
        $this->assertSame('4242', $response->getHeaderLine('Content-Type'));
        $this->assertSame('4242', $this->recorder->calls[0][1]);
    }

    public function testExistingContentTypeIsNotOverwritten(): void
    {
        $response = $this->process('/module.wasm', ['wasm' => 'application/wasm'], true);
        $this->assertSame('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertCount(0, $this->recorder->calls);
    }

    public function testUnknownExtensionLeavesNoContentType(): void
    {
        $response = $this->process('/file.unknown', ['wasm' => 'application/wasm']);
        $this->assertFalse($response->hasHeader('Content-Type'));
        $this->assertCount(0, $this->recorder->calls);
    }

    public function testNoExtensionLeavesNoContentType(): void
    {
        $response = $this->process('/endpoint', ['wasm' => 'application/wasm']);
        $this->assertFalse($response->hasHeader('Content-Type'));
    }

    public function testMultiSuffixResolvesTypeFromInnerSuffix(): void
    {
        // C3: document.html.gz must resolve text/html from the html suffix;
        // the rightmost gz suffix carries no type. pathinfo() alone would
        // have returned 'gz' and missed the type entirely.
        $response = $this->process('/document.html.gz', ['html' => 'text/html']);
        $this->assertSame('text/html', $response->getHeaderLine('Content-Type'));
    }

    public function testLeadingDotBasenameGetsNoType(): void
    {
        // M12: ".png" is a hidden file named "png", NOT a PNG image. Even with
        // 'png' mapped, no Content-Type is assigned (Apache dotfile rule).
        $response = $this->process('/.png', ['png' => 'image/png']);
        $this->assertFalse($response->hasHeader('Content-Type'));
        $this->assertCount(0, $this->recorder->calls);
    }
}
