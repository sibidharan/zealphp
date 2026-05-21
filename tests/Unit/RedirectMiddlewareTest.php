<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\RedirectMiddleware;
use ZealPHP\Tests\TestCase;

class RedirectMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
    }

    public function testPrefixRedirectExactMatch(): void
    {
        $mw = new RedirectMiddleware([['from' => '/old', 'to' => '/new', 'status' => 301]]);
        $response = $this->process($mw, '/old');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/new', $response->getHeaderLine('Location'));
    }

    public function testPrefixRedirectAppendsRemainder(): void
    {
        $mw = new RedirectMiddleware([['from' => '/old', 'to' => '/new']]);
        $response = $this->process($mw, '/old/page');

        $this->assertSame(302, $response->getStatusCode()); // default status
        $this->assertSame('/new/page', $response->getHeaderLine('Location'));
    }

    public function testTrailingSlashPrefixMatchesSubpath(): void
    {
        // from='/old/' — rtrim('/old/','/').'/' = '/old/'. Path '/old/x' must match.
        // Kills UnwrapRtrim: without rtrim the comparison prefix becomes '/old//'
        // and '/old/x' would NOT match → no redirect.
        $mw = new RedirectMiddleware([['from' => '/old/', 'to' => '/new']]);
        $response = $this->process($mw, '/old/x');

        $this->assertSame(302, $response->getStatusCode());
        // remainder = substr('/old/x', strlen('/old/')) = 'x' → '/new' . 'x'
        $this->assertSame('/newx', $response->getHeaderLine('Location'));
    }

    public function testQueryStringPreserved(): void
    {
        $mw = new RedirectMiddleware([['from' => '/old', 'to' => '/new']]);
        $response = $this->process($mw, '/old', 'a=1&b=2');

        $this->assertSame('/new?a=1&b=2', $response->getHeaderLine('Location'));
    }

    public function testRegexRedirectWithBackreference(): void
    {
        $mw = new RedirectMiddleware([['match' => '#^/blog/(\d+)$#', 'to' => '/posts/$1', 'status' => 302]]);
        $response = $this->process($mw, '/blog/42');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/posts/42', $response->getHeaderLine('Location'));
    }

    public function testSecondRuleMatchesAfterFirstMisses(): void
    {
        // First rule does not match '/b'; loop must CONTINUE to the second rule.
        // Kills Continue_ → break (break would stop after the first miss and
        // fall through to the handler → 200 instead of the redirect).
        $mw = new RedirectMiddleware([
            ['from' => '/a', 'to' => '/x'],
            ['from' => '/b', 'to' => '/y', 'status' => 301],
        ]);
        $response = $this->process($mw, '/b');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/y', $response->getHeaderLine('Location'));
    }

    public function testRuleWithNonStringToIsIgnored(): void
    {
        // 'to' is an int, not a string → `isset && is_string` is false → rule
        // dropped at construction → request passes through (200).
        // Kills LogicalAnd→Or on line 46 (which would accept the int `to` and
        // build a bogus redirect rule that fires here).
        $mw = new RedirectMiddleware([['from' => '/old', 'to' => 123]]);
        $response = $this->process($mw, '/old');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', (string) $response->getBody());
        $this->assertSame('', $response->getHeaderLine('Location'));
    }

    public function testRuleWithoutFromOrMatchIsIgnored(): void
    {
        // Valid 'to' but neither 'from' nor 'match' → both prefix & regex null
        // → rule dropped at construction → pass through.
        $mw = new RedirectMiddleware([['to' => '/new']]);
        $response = $this->process($mw, '/anything');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Location'));
    }

    public function testValidRuleAfterMissingToRuleStillRegisters(): void
    {
        // First rule has no 'to' → skipped via `continue` at the $to===null
        // guard; the loop must CONTINUE so the second valid rule registers.
        // Kills Continue_ → break on the $to===null branch (break would abort
        // construction before the valid rule is added → no redirect).
        $mw = new RedirectMiddleware([
            ['from' => '/skipme'],            // no 'to' → dropped
            ['from' => '/old', 'to' => '/new', 'status' => 301],
        ]);
        $response = $this->process($mw, '/old');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/new', $response->getHeaderLine('Location'));
    }

    public function testValidRuleAfterNoFromNoMatchRuleStillRegisters(): void
    {
        // First rule has 'to' but neither 'from' nor 'match' → skipped via the
        // `$prefix === null && $regex === null` continue. Loop must CONTINUE so
        // the second valid rule registers. Kills Continue_ → break on line 53.
        $mw = new RedirectMiddleware([
            ['to' => '/new'],                 // no from/match → dropped
            ['from' => '/old', 'to' => '/dest', 'status' => 308],
        ]);
        $response = $this->process($mw, '/old');

        $this->assertSame(308, $response->getStatusCode());
        $this->assertSame('/dest', $response->getHeaderLine('Location'));
    }

    public function testRuleWithBothFromAndMatchIsKept(): void
    {
        // Rule supplies BOTH 'from' and 'match' (valid: not both null). Original
        // keeps it (prefix takes precedence in resolve()). Kills
        // LogicalAndAllSubExprNegation on line 52: the mutant
        // `!(prefix===null) && !(regex===null)` is true when both are set →
        // it would `continue` (drop the rule) → no redirect.
        $mw = new RedirectMiddleware([
            ['from' => '/old', 'match' => '#^/old$#', 'to' => '/new', 'status' => 301],
        ]);
        $response = $this->process($mw, '/old');

        // resolve() checks regex first: '#^/old$#' matches '/old' → preg_replace
        // yields '/new'. The rule must have been kept at construction for any
        // redirect to happen at all.
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/new', $response->getHeaderLine('Location'));
    }

    public function testNoMatchPassesThrough(): void
    {
        $mw = new RedirectMiddleware([['from' => '/old', 'to' => '/new']]);
        $response = $this->process($mw, '/unrelated');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', (string) $response->getBody());
    }

    private function process(RedirectMiddleware $mw, string $path, string $query = ''): ResponseInterface
    {
        $uri = $path . ($query !== '' ? '?' . $query : '');
        $request = new ServerRequest($uri, 'GET', '', []);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process($request, $handler);
    }
}
