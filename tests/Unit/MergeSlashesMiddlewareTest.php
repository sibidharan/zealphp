<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\MergeSlashesMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class MergeSlashesMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        $_SERVER = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        parent::tearDown();
    }

    public function testCollapsesConsecutiveSlashesInPath(): void
    {
        RequestContext::instance()->server['REQUEST_URI'] = '/a//b///c';
        $this->invoke();
        $this->assertSame('/a/b/c', RequestContext::instance()->server['REQUEST_URI']);
    }

    public function testPreservesQueryStringWhileCollapsingPath(): void
    {
        RequestContext::instance()->server['REQUEST_URI'] = '/a//b?x=1//2';
        $this->invoke();
        // Only the path before '?' is collapsed; the query (with its slashes) stays.
        $this->assertSame('/a/b?x=1//2', RequestContext::instance()->server['REQUEST_URI']);
    }

    public function testLeavesAlreadyNormalizedPathUntouched(): void
    {
        RequestContext::instance()->server['REQUEST_URI'] = '/a/b/c';
        $this->invoke();
        $this->assertSame('/a/b/c', RequestContext::instance()->server['REQUEST_URI']);
    }

    public function testEmptyUriIsNotWritten(): void
    {
        // Empty string: the `&& $uri !== ''` guard must short-circuit so we
        // never enter the block. Kills the `||` mutant on line 33: with `||`
        // the empty-string branch would be entered. We assert REQUEST_URI is
        // left exactly as the empty string and no spurious key mutation occurs.
        RequestContext::instance()->server['REQUEST_URI'] = '';
        RequestContext::instance()->server['__sentinel_unchanged'] = 1;
        $this->invoke();
        $this->assertSame('', RequestContext::instance()->server['REQUEST_URI']);
        $this->assertSame(1, RequestContext::instance()->server['__sentinel_unchanged']);
    }

    public function testMissingUriDefaultsToEmptyAndIsNotWritten(): void
    {
        // No REQUEST_URI at all → defaults to '' → block skipped.
        unset(RequestContext::instance()->server['REQUEST_URI']);
        $this->invoke();
        $this->assertArrayNotHasKey('REQUEST_URI', RequestContext::instance()->server);
    }

    public function testNonStringUriIsLeftUntouched(): void
    {
        // REQUEST_URI as a non-string scalar (int). The `is_string($uri) && ...`
        // guard must short-circuit on is_string()=false so the block is skipped
        // and the value is left exactly as-is. Kills LogicalAnd → LogicalOr on
        // line 33: with `||`, is_string(false) || (0 !== '') is true → the block
        // would run and rewrite/normalize REQUEST_URI (changing the int value).
        RequestContext::instance()->server['REQUEST_URI'] = 0;
        $this->invoke();
        $this->assertSame(0, RequestContext::instance()->server['REQUEST_URI']);
    }

    private function invoke(): ResponseInterface
    {
        $mw = new MergeSlashesMiddleware();
        $request = new ServerRequest('/', 'GET', '', []);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process($request, $handler);
    }
}
