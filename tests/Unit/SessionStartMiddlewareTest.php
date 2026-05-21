<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\SessionStartMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * SessionStartMiddleware — eager session bootstrap so first-time visitors get a
 * PHPSESSID cookie even when their handler never calls session_start() (issue
 * #12). zeal_session_start() is stubbed via uopz so the test observes exactly
 * when the middleware triggers a start, with no real session-file side effects.
 */
class SessionStartMiddlewareTest extends TestCase
{
    /** Count of zeal_session_start() invocations during a test. */
    public static int $starts = 0;

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        self::$starts = 0;
        uopz_set_return('ZealPHP\\Session\\zeal_session_start', static function (): void {
            SessionStartMiddlewareTest::$starts++;
        }, true);
    }

    protected function tearDown(): void
    {
        uopz_unset_return('ZealPHP\\Session\\zeal_session_start');
        RequestContext::instance()->_session_started = null;
    }

    public function testStartsSessionForFirstTimeVisitor(): void
    {
        RequestContext::instance()->_session_started = null;
        $resp = $this->dispatch();
        $this->assertSame(1, self::$starts, 'a first-time visitor must get exactly one eager session start');
        $this->assertTrue(RequestContext::instance()->_session_started);
        $this->assertSame('HANDLED', (string)$resp->getBody());
    }

    public function testDoesNotRestartWhenAlreadyStarted(): void
    {
        RequestContext::instance()->_session_started = true;
        $resp = $this->dispatch();
        $this->assertSame(0, self::$starts, 'an already-started session must not be re-started');
        $this->assertSame('HANDLED', (string)$resp->getBody());
    }

    public function testFlagIsMarkedTrueAfterStart(): void
    {
        RequestContext::instance()->_session_started = null;
        $this->dispatch();
        $this->assertTrue(
            RequestContext::instance()->_session_started,
            'the started flag must be set true so the next middleware/handler does not restart'
        );
    }

    public function testHandlerIsAlwaysInvoked(): void
    {
        RequestContext::instance()->_session_started = true; // start path skipped
        $resp = $this->dispatch();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('HANDLED', (string)$resp->getBody());
    }

    private function dispatch(): ResponseInterface
    {
        $mw = new SessionStartMiddleware();
        $request = new ServerRequest('/', 'GET', '', []);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('HANDLED', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process($request, $handler);
    }
}
