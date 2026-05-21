<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\RequestHeaderMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class RequestHeaderMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        App::superglobals(false);
        $g = RequestContext::instance();
        $g->server = ['HTTP_X_EXISTING' => 'orig'];
    }

    /** @param list<array<string, mixed>> $rules */
    private function dispatch(array $rules): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('ok', 200);
            }
        };
        (new RequestHeaderMiddleware($rules))->process(new ServerRequest('/', 'GET'), $handler);
    }

    public function testSetCreatesHttpServerKey(): void
    {
        $this->dispatch([['op' => 'set', 'name' => 'X-Forwarded-Proto', 'value' => 'https']]);
        $this->assertSame('https', RequestContext::instance()->server['HTTP_X_FORWARDED_PROTO'] ?? null);
    }

    public function testSetReplacesExisting(): void
    {
        $this->dispatch([['op' => 'set', 'name' => 'X-Existing', 'value' => 'replaced']]);
        $this->assertSame('replaced', RequestContext::instance()->server['HTTP_X_EXISTING'] ?? null);
    }

    public function testUnsetRemovesKey(): void
    {
        $this->dispatch([['op' => 'unset', 'name' => 'X-Existing']]);
        $this->assertArrayNotHasKey('HTTP_X_EXISTING', RequestContext::instance()->server);
    }

    public function testAppendJoinsExisting(): void
    {
        $this->dispatch([['op' => 'append', 'name' => 'X-Existing', 'value' => 'more']]);
        $this->assertSame('orig, more', RequestContext::instance()->server['HTTP_X_EXISTING'] ?? null);
    }

    public function testAppendCreatesWhenAbsent(): void
    {
        $this->dispatch([['op' => 'append', 'name' => 'X-New', 'value' => 'v']]);
        $this->assertSame('v', RequestContext::instance()->server['HTTP_X_NEW'] ?? null);
    }
}
