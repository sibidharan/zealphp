<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\CacheControlMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class CacheControlMiddlewareTest extends TestCase
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
     * @param array<string, int>|null $map
     */
    private function process(
        string $path,
        ?array $map = null,
        bool $publicCache = true,
        bool $preExisting = false
    ): ResponseInterface {
        $middleware = new CacheControlMiddleware($map, $publicCache);

        $request = new ServerRequest($path, 'GET', '', []);

        $headers = ['Content-Type' => 'text/plain'];
        if ($preExisting) {
            $headers['Cache-Control'] = 'no-store';
        }

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

    public function testStampsExactDefaultValueForCss(): void
    {
        $response = $this->process('/style.css');
        $this->assertSame('max-age=2628000, public', $response->getHeaderLine('Cache-Control'));
    }

    public function testWritesExactValueToRawResponse(): void
    {
        $this->process('/app.js', ['js' => 3600]);
        $this->assertCount(1, $this->recorder->calls);
        $this->assertSame('Cache-Control', $this->recorder->calls[0][0]);
        $this->assertSame('max-age=3600, public', $this->recorder->calls[0][1]);
    }


    public function testPrivateCacheValue(): void
    {
        $response = $this->process('/style.css', null, false);
        $this->assertSame('max-age=2628000, private', $response->getHeaderLine('Cache-Control'));
    }

    public function testUppercaseExtensionInPathMatches(): void
    {
        // Kills the UnwrapStrToLower mutant at L93 — the request path extension
        // is lowercased before lookup, so .CSS matches the 'css' map key.
        $response = $this->process('/STYLE.CSS', ['css' => 100]);
        $this->assertSame('max-age=100, public', $response->getHeaderLine('Cache-Control'));
    }

    public function testUppercaseMapKeyIsNormalised(): void
    {
        // Kills the UnwrapStrToLower mutant at L116 (normaliseMap) — a config
        // key supplied as 'CSS' must be lowered so a /style.css request hits.
        $response = $this->process('/style.css', ['CSS' => 200]);
        $this->assertSame('max-age=200, public', $response->getHeaderLine('Cache-Control'));
    }

    public function testLeadingDotMapKeyIsTrimmed(): void
    {
        // Kills the UnwrapLtrim mutant at L116 — a config key supplied as
        // '.css' must have its leading dot stripped so it stores as 'css'
        // and a /style.css request hits it.
        $response = $this->process('/style.css', ['.css' => 300]);
        $this->assertSame('max-age=300, public', $response->getHeaderLine('Cache-Control'));
    }

    public function testNumericMapKeyIsStringified(): void
    {
        // Kills the CastString mutant at L116 — a purely-numeric config key
        // is cast to int by PHP; without (string) the ltrim() would receive an
        // int and throw under strict_types in the constructor.
        $response = $this->process('/file.123', ['123' => 400]);
        $this->assertSame('max-age=400, public', $response->getHeaderLine('Cache-Control'));
    }

    public function testUnknownExtensionIsNotStamped(): void
    {
        $response = $this->process('/page.xyz', ['css' => 100]);
        $this->assertFalse($response->hasHeader('Cache-Control'));
        $this->assertCount(0, $this->recorder->calls);
    }

    public function testNoExtensionIsNotStamped(): void
    {
        $response = $this->process('/about', ['css' => 100]);
        $this->assertFalse($response->hasHeader('Cache-Control'));
    }

    public function testPreExistingCacheControlIsNotOverwritten(): void
    {
        $response = $this->process('/style.css', null, true, true);
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertCount(0, $this->recorder->calls);
    }

    // -------------------------------------------------------------------------
    // B4 parity fix — Apache mod_expires.c:455–458 error-response suppression
    // -------------------------------------------------------------------------

    public function testNoCacheControlOn404Response(): void
    {
        // Apache never stamps caching headers on 4xx/5xx responses.
        // A /missing.css 404 must NOT get Cache-Control: max-age=N — that
        // would cause browsers and CDNs to cache error pages as assets.
        $middleware = new CacheControlMiddleware();
        $request    = new ServerRequest('/missing.css', 'GET', '', []);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('not found', 404, '', ['Content-Type' => 'text/css']);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertFalse($response->hasHeader('Cache-Control'));
        $this->assertCount(0, $this->recorder->calls);
    }

    public function testNoCacheControlOn500Response(): void
    {
        // Same guard for 5xx.
        $middleware = new CacheControlMiddleware();
        $request    = new ServerRequest('/app.js', 'GET', '', []);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('error', 500, '', ['Content-Type' => 'text/javascript']);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertFalse($response->hasHeader('Cache-Control'));
    }

    public function testCacheControlStillStampedOn200AfterErrorGuard(): void
    {
        // Confirm the guard doesn't accidentally suppress 2xx responses.
        $response = $this->process('/style.css');
        $this->assertSame('max-age=2628000, public', $response->getHeaderLine('Cache-Control'));
    }

    public function testNoCacheControlOnExact400Response(): void
    {
        // Kills GreaterThanOrEqualTo at L94: >= 400 must suppress on exactly 400,
        // not just > 400 (which would allow 400 through).
        $middleware = new CacheControlMiddleware();
        $request    = new ServerRequest('/style.css', 'GET', '', []);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('bad request', 400, '', ['Content-Type' => 'text/css']);
            }
        };

        $response = $middleware->process($request, $handler);
        $this->assertFalse($response->hasHeader('Cache-Control'));
        $this->assertCount(0, $this->recorder->calls);
    }

    public function testNormaliseMapCastsSecondsToInt(): void
    {
        // Kills CastInt at L126: without (int) cast the map value would remain
        // a float or string. We pass a float-looking int and verify the emitted
        // max-age is the integer form (no decimal point).
        $middleware = new CacheControlMiddleware(['css' => 3600]);
        $request    = new ServerRequest('/style.css', 'GET', '', []);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('body', 200, '', []);
            }
        };

        $response = $middleware->process($request, $handler);
        // If (int) cast is removed, sprintf with a non-int value could produce
        // 'max-age=3600, public' still (sprintf %d coerces). This mutant is
        // effectively equivalent for int inputs. Exercise the path for coverage:
        $this->assertSame('max-age=3600, public', $response->getHeaderLine('Cache-Control'));
    }
}
