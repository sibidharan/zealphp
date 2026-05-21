<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\SetEnvIfMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class SetEnvIfMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        App::superglobals(false);
        $g = RequestContext::instance();
        $g->server = [];
    }

    /** @param list<array<string, mixed>> $rules */
    private function dispatch(array $rules, ServerRequestInterface $request): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('ok', 200);
            }
        };
        (new SetEnvIfMiddleware($rules))->process($request, $handler);
    }

    public function testUserAgentMatchSetsEnv(): void
    {
        $req = (new ServerRequest('/', 'GET'))->withHeader('User-Agent', 'Googlebot/2.1');
        $this->dispatch([['attr' => 'User-Agent', 'regex' => '#bot#i', 'set' => ['IS_BOT' => '1']]], $req);
        $this->assertSame('1', RequestContext::instance()->server['IS_BOT'] ?? null);
    }

    public function testNonMatchLeavesEnvUnset(): void
    {
        $req = (new ServerRequest('/', 'GET'))->withHeader('User-Agent', 'Mozilla/5.0');
        $this->dispatch([['attr' => 'User-Agent', 'regex' => '#bot#i', 'set' => ['IS_BOT' => '1']]], $req);
        $this->assertArrayNotHasKey('IS_BOT', RequestContext::instance()->server);
    }

    public function testRequestUriAttribute(): void
    {
        $req = new ServerRequest('/admin/users', 'GET');
        $this->dispatch([['attr' => 'Request_URI', 'regex' => '#^/admin#', 'set' => ['ADMIN_AREA' => 'yes']]], $req);
        $this->assertSame('yes', RequestContext::instance()->server['ADMIN_AREA'] ?? null);
    }

    public function testRequestMethodAttribute(): void
    {
        $req = new ServerRequest('/', 'POST');
        $this->dispatch([['attr' => 'Request_Method', 'regex' => '#^POST$#', 'set' => ['IS_WRITE' => '1']]], $req);
        $this->assertSame('1', RequestContext::instance()->server['IS_WRITE'] ?? null);
    }
}
