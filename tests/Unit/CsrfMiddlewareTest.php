<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Middleware\CsrfMiddleware;
use OpenSwoole\Core\Psr\ServerRequest;
use OpenSwoole\Core\Psr\Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ZealPHP\RequestContext;

final class CsrfMiddlewareTest extends TestCase
{
    private CsrfMiddleware $mw;
    private RequestHandlerInterface $handler;

    protected function setUp(): void
    {
        $this->mw = new CsrfMiddleware();
        $this->handler = new class implements RequestHandlerInterface {
            public bool $called = false;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;
                return new Response('OK', 200);
            }
        };
    }

    public function testGetRequestGeneratesToken(): void
    {
        $g = RequestContext::instance();
        $g->session = [];
        $g->memo = [];
        $g->post = [];
        $g->server = ['REQUEST_METHOD' => 'GET'];

        $request = new ServerRequest('/', 'GET');
        $this->mw->process($request, $this->handler);

        $this->assertTrue($this->handler->called);
        $this->assertArrayHasKey('_csrf_token', $g->session);
        $this->assertArrayHasKey('csrf_token', $g->memo);
        $this->assertSame($g->session['_csrf_token'], $g->memo['csrf_token']);
        $this->assertSame(64, strlen($g->memo['csrf_token']));
    }

    public function testPostWithoutTokenReturns403(): void
    {
        $g = RequestContext::instance();
        $g->session = ['_csrf_token' => bin2hex(random_bytes(32))];
        $g->memo = [];
        $g->post = [];
        $g->server = ['REQUEST_METHOD' => 'POST'];

        $request = new ServerRequest('/submit', 'POST');
        $response = $this->mw->process($request, $this->handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->handler->called);
    }

    public function testPostWithValidTokenPasses(): void
    {
        $token = bin2hex(random_bytes(32));
        $g = RequestContext::instance();
        $g->session = ['_csrf_token' => $token];
        $g->memo = [];
        $g->post = ['_csrf_token' => $token];
        $g->server = ['REQUEST_METHOD' => 'POST'];

        $request = new ServerRequest('/submit', 'POST');
        $response = $this->mw->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->handler->called);
    }

    public function testPostWithHeaderTokenPasses(): void
    {
        $token = bin2hex(random_bytes(32));
        $g = RequestContext::instance();
        $g->session = ['_csrf_token' => $token];
        $g->memo = [];
        $g->post = [];
        $g->server = ['REQUEST_METHOD' => 'POST', 'HTTP_X_CSRF_TOKEN' => $token];

        $request = new ServerRequest('/submit', 'POST');
        $response = $this->mw->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->handler->called);
    }

    public function testPostWithWrongTokenReturns403(): void
    {
        $g = RequestContext::instance();
        $g->session = ['_csrf_token' => bin2hex(random_bytes(32))];
        $g->memo = [];
        $g->post = ['_csrf_token' => 'wrong-token'];
        $g->server = ['REQUEST_METHOD' => 'POST'];

        $request = new ServerRequest('/submit', 'POST');
        $response = $this->mw->process($request, $this->handler);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testExemptPathSkipsValidation(): void
    {
        $mw = new CsrfMiddleware(exempt: ['/api/webhook']);
        $g = RequestContext::instance();
        $g->session = ['_csrf_token' => bin2hex(random_bytes(32))];
        $g->memo = [];
        $g->post = [];
        $g->server = ['REQUEST_METHOD' => 'POST'];

        $request = new ServerRequest('/api/webhook/stripe', 'POST');
        $response = $mw->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->handler->called);
    }

    public function testTokenPersistsAcrossRequests(): void
    {
        $g = RequestContext::instance();
        $g->session = [];
        $g->memo = [];
        $g->post = [];
        $g->server = ['REQUEST_METHOD' => 'GET'];

        $request = new ServerRequest('/', 'GET');
        $this->mw->process($request, $this->handler);
        $token1 = $g->memo['csrf_token'];

        $this->mw->process($request, $this->handler);
        $token2 = $g->memo['csrf_token'];

        $this->assertSame($token1, $token2);
    }

    public function testDeleteRequiresToken(): void
    {
        $g = RequestContext::instance();
        $g->session = ['_csrf_token' => bin2hex(random_bytes(32))];
        $g->memo = [];
        $g->post = [];
        $g->server = ['REQUEST_METHOD' => 'DELETE'];

        $request = new ServerRequest('/item/42', 'DELETE');
        $response = $this->mw->process($request, $this->handler);

        $this->assertSame(403, $response->getStatusCode());
    }
}
