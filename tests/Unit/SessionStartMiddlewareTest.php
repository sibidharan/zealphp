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

use function ZealPHP\Session\zeal_session_status;

/**
 * SessionStartMiddleware — eager session bootstrap so first-time visitors get a
 * PHPSESSID cookie even when their handler never calls session_start() (issue
 * #12). Runs the REAL `zeal_session_start()` (ext-zealphp is the override
 * engine — no uopz stubbing) against a temp save_path, observing the
 * middleware through the engine's own signals: the `_session_started` flag,
 * the minted `session_params['session_id']`, and `zeal_session_status()`.
 */
class SessionStartMiddlewareTest extends TestCase
{
    private string $savePath = '';
    private bool $hadSession = false;
    /** @var mixed */
    private $savedSession = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $this->hadSession = array_key_exists('_SESSION', $GLOBALS);
        $this->savedSession = $GLOBALS['_SESSION'] ?? null;
        unset($GLOBALS['_SESSION']);

        $this->savePath = sys_get_temp_dir() . '/zealphp_ssmw_' . bin2hex(random_bytes(6));
        @mkdir($this->savePath, 0777, true);

        $g = RequestContext::instance();
        $g->_session_started = null;
        $g->session_params = ['save_path' => $this->savePath];
        $g->cookie = [];                       // no PHPSESSID → first-time visitor
        $GLOBALS['_COOKIE'] = [];
    }

    protected function tearDown(): void
    {
        $g = RequestContext::instance();
        $g->_session_started = null;
        $g->session_params = [];
        $g->cookie = [];
        if ($this->hadSession) {
            $GLOBALS['_SESSION'] = $this->savedSession;
        } else {
            unset($GLOBALS['_SESSION']);
        }
        foreach (glob($this->savePath . '/sess_*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->savePath);
        parent::tearDown();
    }

    public function testStartsSessionForFirstTimeVisitor(): void
    {
        $resp = $this->dispatch();

        $g = RequestContext::instance();
        $this->assertTrue($g->_session_started);
        $sid = $g->session_params['session_id'] ?? null;
        $this->assertIsString($sid, 'an eager start must mint a session id');
        $this->assertNotSame('', $sid);
        $this->assertSame(
            PHP_SESSION_ACTIVE,
            zeal_session_status(),
            'the REAL engine must report an active session after the eager start'
        );
        $this->assertSame('HANDLED', (string)$resp->getBody());
    }

    public function testDoesNotRestartWhenAlreadyStarted(): void
    {
        RequestContext::instance()->_session_started = true;
        $resp = $this->dispatch();

        $this->assertArrayNotHasKey(
            'session_id',
            RequestContext::instance()->session_params,
            'an already-started session must not be re-started (no fresh id minted)'
        );
        $this->assertSame('HANDLED', (string)$resp->getBody());
    }

    public function testFlagIsMarkedTrueAfterStart(): void
    {
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
