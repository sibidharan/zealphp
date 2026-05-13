<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\REST;
use ZealPHP\Tests\TestCase;

class RestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        // Keep PHPUnit in plain superglobal mode so coroutine hooks do not leak
        // into the integration test process.
        App::superglobals(true);
        $g = G::instance();
        $g->server = [];
        $g->get = [];
        $g->post = [];
        $g->zealphp_request = new \stdClass();
        $g->zealphp_response = new class {
            public array $headers = [];
            public int $status = 200;

            public function header($name, $value): void
            {
                $this->headers[$name] = $value;
            }

            public function status($status): void
            {
                $this->status = $status;
            }
        };
    }

    private function makeRest(array $server = [], array $get = [], array $post = []): REST
    {
        $g = G::instance();
        $g->server = $server;
        $g->get = $get;
        $g->post = $post;

        return new REST(new \stdClass(), new \stdClass());
    }

    public function testRequestMethodDefaultsToGet(): void
    {
        $rest = $this->makeRest();

        $this->assertSame('GET', $rest->get_request_method());
    }

    public function testRefererReturnsNullWhenMissing(): void
    {
        $rest = $this->makeRest(['REQUEST_METHOD' => 'GET']);

        $this->assertNull($rest->get_referer());
    }

    public function testRefererReadsFromGServer(): void
    {
        $rest = $this->makeRest([
            'REQUEST_METHOD' => 'GET',
            'HTTP_REFERER' => 'https://example.test/from',
        ]);

        $this->assertSame('https://example.test/from', $rest->get_referer());
    }

    public function testGetInputsComeFromGGet(): void
    {
        $rest = $this->makeRest(['REQUEST_METHOD' => 'GET'], [
            'q' => '  <b>hello</b> ',
        ]);

        $this->assertSame(['q' => 'hello'], $rest->_request);
    }

    public function testPostMergesGGetAndGPost(): void
    {
        $rest = $this->makeRest(
            ['REQUEST_METHOD' => 'POST'],
            ['page' => ' 1 '],
            ['name' => ' <b>Alice</b> ', 'role' => ' admin ']
        );

        $this->assertSame([
            'page' => '1',
            'name' => 'Alice',
            'role' => 'admin',
        ], $rest->_request);
    }
}
