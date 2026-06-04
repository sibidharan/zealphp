<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Middleware\StackHandler;
use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\ResponseMiddleware;
use ZealPHP\Tests\TestCase;

/**
 * Issue #227 — defence-in-depth: `App::middleware()` must self-heal when
 * `App::$middleware_stack` reads back null after boot. A per-request
 * class-static reset (run in the session-manager `finally`) can zero the
 * static if it fires without an exempting boot snapshot; the root fix is the
 * reset gate (`App::perRequestStateResetsActive()`, pinned by
 * `PerRequestResetGateTest`), but this accessor still rebuilds the stack from
 * `App::$middleware_wait_stack` so routing keeps working instead of "Call to a
 * member function handle() on null" — and serves every other caller of
 * `App::middleware()`.
 *
 * The request hot path (the OnRequest closure) is additionally hardened with a
 * registration-time `use` capture of the assembled stack — immune to any
 * class-static reset because a closure binding lives outside the reset's
 * scope — but that lives inside a live-server closure; this suite pins the
 * accessor-level self-heal that backs it.
 */
class MiddlewareStackHealTest extends TestCase
{
    private static ?StackHandler $savedStack = null;
    /** @var array<int, MiddlewareInterface> */
    private static array $savedWait = [];

    public static function setUpBeforeClass(): void
    {
        // Snapshot the shared statics so this class leaves them exactly as found
        // (RouteMiddlewareTest / ResponseMiddlewarePipelineTest read them too).
        self::$savedStack = App::$middleware_stack;
        self::$savedWait  = App::$middleware_wait_stack;
    }

    public static function tearDownAfterClass(): void
    {
        App::$middleware_stack      = self::$savedStack;
        App::$middleware_wait_stack = self::$savedWait;
    }

    protected function tearDown(): void
    {
        App::$middleware_stack      = self::$savedStack;
        App::$middleware_wait_stack = self::$savedWait;
    }

    /** A middleware that short-circuits with a fixed status, never calling the handler. */
    private function shortCircuit(int $status): MiddlewareInterface
    {
        return new class($status) implements MiddlewareInterface {
            public function __construct(private int $status)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response('short', $this->status);
            }
        };
    }

    public function testReturnsExistingStackUntouchedWhenPresent(): void
    {
        $stack = (new StackHandler())->add(new ResponseMiddleware());
        \assert($stack instanceof StackHandler);
        App::$middleware_stack = $stack;

        // Non-null static → returned verbatim, no rebuild (zero overhead path).
        $this->assertSame($stack, App::middleware());
    }

    public function testRebuildsFromWaitStackWhenStaticZeroed(): void
    {
        // Simulate per-coroutine isolation zeroing the assembled static while
        // the queued wait stack survives.
        App::$middleware_wait_stack = [$this->shortCircuit(418)];
        App::$middleware_stack      = null;

        $mw = App::middleware();
        $this->assertInstanceOf(StackHandler::class, $mw);

        // The rebuilt stack must INCLUDE the queued middleware — not just a bare
        // router. The short-circuit returns 418 before any routing runs, which
        // it can only do if it was actually added to the rebuilt chain.
        $resp = $mw->handle(new ServerRequest('/anything', 'GET'));
        $this->assertSame(418, $resp->getStatusCode());
        $this->assertSame('short', (string) $resp->getBody());
    }

    public function testReturnsNullWhenStaticNullAndWaitStackEmpty(): void
    {
        // Genuinely "not initialised" (no middleware queued, null static): the
        // self-heal must NOT fabricate a stack — that would mask a real
        // "called before init()" bug in normal (non-isolation) modes.
        App::$middleware_wait_stack = [];
        App::$middleware_stack      = null;

        $this->assertNull(App::middleware());
    }

    public function testRebuildIsNotCachedBackIntoStatic(): void
    {
        // The rebuild is deliberately not written back to the static: under
        // real isolation the write wouldn't persist to the next coroutine, and
        // caching here could mask a pre-init ordering mistake.
        App::$middleware_wait_stack = [$this->shortCircuit(200)];
        App::$middleware_stack      = null;

        App::middleware();
        $this->assertNull(App::$middleware_stack);
    }

    public function testRebuildPreservesFirstRegisteredOutermostOrder(): void
    {
        // Two middlewares: the FIRST registered must wrap outermost (run first).
        // The outer short-circuits at 418 before the inner can set 451, proving
        // the rebuild honours the same ordering as buildMiddlewareStack().
        App::$middleware_wait_stack = [$this->shortCircuit(418), $this->shortCircuit(451)];
        App::$middleware_stack      = null;

        $mw = App::middleware();
        $this->assertInstanceOf(StackHandler::class, $mw);
        $resp = $mw->handle(new ServerRequest('/anything', 'GET'));
        $this->assertSame(418, $resp->getStatusCode());
    }
}
