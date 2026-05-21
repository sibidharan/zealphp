<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\Middleware\RedirectMiddleware;
use ZealPHP\Tests\TestCase;

class RedirectMiddlewareTest extends TestCase
{
    /** @param list<array<string, mixed>> $rules */
    private function invoke(array $rules, string $path, string $query = ''): ResponseInterface
    {
        $mw = new RedirectMiddleware($rules);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('handler-ran', 200);
            }
        };
        $uri = '/' === $path[0] ? $path : "/$path";
        if ($query !== '') {
            $uri .= '?' . $query;
        }
        return $mw->process(new ServerRequest($uri, 'GET'), $handler);
    }

    public function testExactPrefixRedirect(): void
    {
        $r = $this->invoke([['from' => '/old', 'to' => '/new', 'status' => 301]], '/old');
        $this->assertSame(301, $r->getStatusCode());
        $this->assertSame('/new', $r->getHeaderLine('Location'));
    }

    public function testPrefixAppendsRemainder(): void
    {
        $r = $this->invoke([['from' => '/svc', 'to' => '/service']], '/svc/users/7');
        $this->assertSame(302, $r->getStatusCode()); // default status
        $this->assertSame('/service/users/7', $r->getHeaderLine('Location'));
    }

    public function testPrefixDoesNotMatchPartialSegment(): void
    {
        // /svcother must NOT match prefix /svc
        $r = $this->invoke([['from' => '/svc', 'to' => '/service']], '/svcother');
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('handler-ran', (string) $r->getBody());
    }

    public function testRegexRedirectWithBackreference(): void
    {
        $r = $this->invoke([['match' => '#^/blog/(\d+)$#', 'to' => '/posts/$1', 'status' => 308]], '/blog/42');
        $this->assertSame(308, $r->getStatusCode());
        $this->assertSame('/posts/42', $r->getHeaderLine('Location'));
    }

    public function testQueryStringPreserved(): void
    {
        $r = $this->invoke([['from' => '/old', 'to' => '/new']], '/old', 'a=1&b=2');
        $this->assertSame('/new?a=1&b=2', $r->getHeaderLine('Location'));
    }

    public function testNoMatchPassesThrough(): void
    {
        $r = $this->invoke([['from' => '/x', 'to' => '/y']], '/unrelated');
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('handler-ran', (string) $r->getBody());
    }

    public function testFirstMatchWins(): void
    {
        $r = $this->invoke([
            ['from' => '/a', 'to' => '/first', 'status' => 301],
            ['from' => '/a', 'to' => '/second', 'status' => 302],
        ], '/a');
        $this->assertSame('/first', $r->getHeaderLine('Location'));
    }

    // --- QSA (query-string append) parity tests — Apache mod_rewrite B9 fix ---

    /**
     * Target already has a query string: incoming query must be merged with &,
     * not dropped.  Apache mod_rewrite QSA: /new?a=1 + ?b=2 → /new?a=1&b=2.
     */
    public function testQsaMergesWithAmpersandWhenTargetHasQuery(): void
    {
        $r = $this->invoke([['from' => '/p', 'to' => '/new?a=1', 'status' => 301]], '/p', 'b=2');
        $this->assertSame(301, $r->getStatusCode());
        $this->assertSame('/new?a=1&b=2', $r->getHeaderLine('Location'));
    }

    /**
     * Target has no query string: incoming query is appended with ?.
     * Existing behaviour must be preserved.
     */
    public function testQsaAppendsWithQuestionMarkWhenTargetHasNoQuery(): void
    {
        $r = $this->invoke([['from' => '/p', 'to' => '/new', 'status' => 302]], '/p', 'b=2');
        $this->assertSame(302, $r->getStatusCode());
        $this->assertSame('/new?b=2', $r->getHeaderLine('Location'));
    }

    /**
     * No incoming query string: target URL is left unchanged regardless of
     * whether the target itself carries a query component.
     */
    public function testQsaNoIncomingQueryLeavesTargetUnchanged(): void
    {
        $r = $this->invoke([['from' => '/p', 'to' => '/new?a=1', 'status' => 301]], '/p');
        $this->assertSame(301, $r->getStatusCode());
        $this->assertSame('/new?a=1', $r->getHeaderLine('Location'));
    }
}
