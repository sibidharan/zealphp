<?php
namespace ZealPHP\Tests\Unit;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * App::when() — centralized path-scoped middleware: canonicalization, prefix /
 * regex matching, registration-order accumulation, alias reuse, the empty-
 * registry fast path, and memoization.
 */
class WhenMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        App::$middleware_aliases = [];
        App::$when_middleware = [];
        App::$when_middleware_compiled = [];
        App::$when_middleware_memo = [];
    }

    /** A distinct no-op middleware (identity comparison) that continues the chain. */
    private function mw(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };
    }

    /** Mimic the App::run() boot-compile so resolveWhenMiddleware() can run in a unit test. */
    private function compile(): void
    {
        App::$when_middleware_compiled = [];
        foreach (App::$when_middleware as $entry) {
            App::$when_middleware_compiled[] = [
                'type'  => $entry['type'],
                'key'   => $entry['key'],
                'chain' => App::compileMiddlewareChain($entry['spec']),
            ];
        }
        App::$when_middleware_memo = [];
    }

    // ───────────────────────── canonicalization ─────────────────────────

    public function testCanonicalizesPrefixForms(): void
    {
        App::when('/admin', [$this->mw()]);
        App::when('admin/', [$this->mw()]);
        App::when('/', [$this->mw()]);
        App::when('', [$this->mw()]);

        $keys = array_map(fn($e) => $e['key'], App::$when_middleware);
        $this->assertSame(['/admin', '/admin', '/', '/'], $keys);
        $this->assertSame(['prefix', 'prefix', 'prefix', 'prefix'], array_map(fn($e) => $e['type'], App::$when_middleware));
    }

    public function testRegexScopeDetected(): void
    {
        App::when('#^/api/v\d+/#', [$this->mw()]);
        $this->assertSame('regex', App::$when_middleware[0]['type']);
        $this->assertSame('#^/api/v\d+/#', App::$when_middleware[0]['key']);
    }

    public function testSingleEntryNormalizedToList(): void
    {
        $mw = $this->mw();
        App::when('/x', $mw);
        $this->assertSame([$mw], App::$when_middleware[0]['spec']);
    }

    // ───────────────────────── matching ─────────────────────────

    public function testPrefixMatchesOnSegmentBoundary(): void
    {
        $mw = $this->mw();
        App::when('/admin', [$mw]);
        $this->compile();

        $this->assertSame([$mw], App::resolveWhenMiddleware('/admin'));
        $this->assertSame([$mw], App::resolveWhenMiddleware('/admin/users'));
        $this->assertSame([], App::resolveWhenMiddleware('/administrators'));
        $this->assertSame([], App::resolveWhenMiddleware('/other'));
    }

    public function testCatchAllMatchesEverything(): void
    {
        $mw = $this->mw();
        App::when('/', [$mw]);
        $this->compile();

        $this->assertSame([$mw], App::resolveWhenMiddleware('/'));
        $this->assertSame([$mw], App::resolveWhenMiddleware('/anything/at/all'));
        $this->assertSame([$mw], App::resolveWhenMiddleware('/api/secured/list'));
    }

    public function testRegexScopeMatches(): void
    {
        $mw = $this->mw();
        App::when('#^/api/v\d+/#', [$mw]);
        $this->compile();

        $this->assertSame([$mw], App::resolveWhenMiddleware('/api/v1/users'));
        $this->assertSame([$mw], App::resolveWhenMiddleware('/api/v42/x'));
        $this->assertSame([], App::resolveWhenMiddleware('/api/users'));
    }

    public function testApiPathScopedLikeAnyOther(): void
    {
        // The headline: api endpoints are just /api/... URLs — no special case.
        $mw = $this->mw();
        App::when('/api/secured', [$mw]);
        $this->compile();

        $this->assertSame([$mw], App::resolveWhenMiddleware('/api/secured/list'));
        $this->assertSame([], App::resolveWhenMiddleware('/api/open/list'));
    }

    // ───────────────────── accumulation / ordering ─────────────────────

    public function testRegistrationOrderAccumulatesOutermostFirst(): void
    {
        $broad = $this->mw();
        $narrow = $this->mw();
        App::when('/', [$broad]);            // registered first → outermost
        App::when('/admin', [$narrow]);
        $this->compile();

        // Both match /admin/x; broad (registered first) comes first (outermost).
        $this->assertSame([$broad, $narrow], App::resolveWhenMiddleware('/admin/x'));
        // Only the catch-all matches a non-admin path.
        $this->assertSame([$broad], App::resolveWhenMiddleware('/elsewhere'));
    }

    public function testRepeatedScopeAccumulatesInCallOrder(): void
    {
        $a = $this->mw();
        $b = $this->mw();
        App::when('/admin', [$a]);
        App::when('/admin', [$b]);
        $this->compile();

        $this->assertSame([$a, $b], App::resolveWhenMiddleware('/admin'));
    }

    // ───────────────────── alias reuse / fast path / memo ─────────────────────

    public function testAliasResolvedInWhenChain(): void
    {
        $mw = $this->mw();
        App::middlewareAlias('guard', $mw);
        App::when('/admin', ['guard']);
        $this->compile();

        $this->assertSame([$mw], App::resolveWhenMiddleware('/admin'));
    }

    public function testEmptyRegistryIsFastPath(): void
    {
        // Nothing registered → compiled empty → always [] (the byte-identical path).
        $this->assertSame([], App::resolveWhenMiddleware('/admin'));
        $this->assertSame([], App::resolveWhenMiddleware('/'));
    }

    public function testMemoizationReturnsStableResult(): void
    {
        $mw = $this->mw();
        App::when('/admin', [$mw]);
        $this->compile();

        $first = App::resolveWhenMiddleware('/admin/x');
        $second = App::resolveWhenMiddleware('/admin/x');
        $this->assertSame($first, $second);
        $this->assertArrayHasKey('/admin/x', App::$when_middleware_memo);
    }
}
