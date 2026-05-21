<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\REST;
use ZealPHP\Tests\TestCase;

/**
 * Additional REST coverage beyond RestTest.php — the request-method branches
 * not already pinned (DELETE, the unsupported-method 406 default), plus
 * setContentType()/response() header + status emission. RestTest covers
 * GET/POST input cleaning and get_referer(); this file fills the rest of the
 * unit-reachable surface (PUT reads php://input which needs the framework's
 * IOStreamWrapper, so it's left to integration tests).
 */
class RestExtraTest extends TestCase
{
    /** @var object{headers: array<string,mixed>, status: int} */
    private $response;

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $this->response = new class {
            /** @var array<string, mixed> */
            public array $headers = [];
            public int $status = 200;

            public function header(string $name, mixed $value, bool $ucwords = true): void
            {
                $this->headers[$name] = $value;
            }

            public function status(int $status): void
            {
                $this->status = $status;
            }
        };

        $g = G::instance();
        $g->server = [];
        $g->get = [];
        $g->post = [];
        $g->zealphp_request = new \stdClass();
        $g->zealphp_response = $this->response;
    }

    protected function tearDown(): void
    {
        $g = G::instance();
        $g->zealphp_request = null;
        $g->zealphp_response = null;
        $g->server = [];
        $g->get = [];
        $g->post = [];
        parent::tearDown();
    }

    private function makeRest(array $server = [], array $get = [], array $post = []): REST
    {
        $g = G::instance();
        $g->server = $server;
        $g->get = $get;
        $g->post = $post;
        return new REST(new \stdClass(), new \stdClass());
    }

    public function testDeleteUsesGetData(): void
    {
        $rest = $this->makeRest(
            ['REQUEST_METHOD' => 'DELETE'],
            ['id' => ' <i>7</i> ']
        );
        $this->assertSame(['id' => '7'], $rest->_request);
    }

    public function testUnsupportedMethodEmits406(): void
    {
        // inputs() falls to default → response('', 406) during construction.
        $this->makeRest(['REQUEST_METHOD' => 'PATCH']);
        $this->assertSame(406, $this->response->status);
        // setHeaders() set the default content type as a side effect.
        $this->assertSame('application/json', $this->response->headers['Content-Type']);
    }

    public function testResponseSetsStatusAndContentTypeHeader(): void
    {
        $rest = $this->makeRest(['REQUEST_METHOD' => 'GET']);
        ob_start();
        $rest->response('hello-body', 418);
        $echo = (string) ob_get_clean();

        $this->assertSame('hello-body', $echo);
        $this->assertSame(418, $this->response->status);
        $this->assertSame('application/json', $this->response->headers['Content-Type']);
    }

    public function testResponseFallsBackTo200ForFalsyStatus(): void
    {
        $rest = $this->makeRest(['REQUEST_METHOD' => 'GET']);
        ob_start();
        $rest->response('x', 0);
        ob_end_clean();
        $this->assertSame(200, $this->response->status);
    }

    public function testSetContentTypeChangesEmittedHeader(): void
    {
        $rest = $this->makeRest(['REQUEST_METHOD' => 'GET']);
        $rest->setContentType('text/plain');
        ob_start();
        $rest->response('plain', 200);
        ob_end_clean();
        $this->assertSame('text/plain', $this->response->headers['Content-Type']);
    }

    public function testGetRequestMethodReflectsServer(): void
    {
        $rest = $this->makeRest(['REQUEST_METHOD' => 'DELETE']);
        $this->assertSame('DELETE', $rest->get_request_method());
    }
}
