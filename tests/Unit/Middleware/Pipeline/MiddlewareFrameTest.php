<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware\Pipeline;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\Middleware\Pipeline\MiddlewareFrame;
use ZealPHP\Tests\TestCase;

/**
 * Unit coverage for the PSR-15 onion frame.
 *
 * MiddlewareFrame::handle($req) MUST call `$middleware->process($req, $next)`
 * — passing THE SAME request and THE inner handler as `$next` — and return
 * exactly what process() returns. We assert the recorded process() arguments
 * and the returned response identity so:
 *   - FunctionCallRemoval of `$this->middleware->process(...)` fails (no return).
 *   - ReturnRemoval fails (the caller asserts the returned object identity).
 *   - swapping the two process() arguments fails (we assert which is which).
 */
class MiddlewareFrameTest extends TestCase
{
    /**
     * A middleware that records exactly what process() received and returns a
     * sentinel response so the frame's return value is observable.
     */
    private function recordingMiddleware(Response $sentinel): MiddlewareInterface
    {
        return new class ($sentinel) implements MiddlewareInterface {
            public ?ServerRequestInterface $seenRequest = null;
            public ?RequestHandlerInterface $seenHandler = null;
            public int $calls = 0;

            public function __construct(private Response $sentinel)
            {
            }

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                $this->calls++;
                $this->seenRequest = $request;
                $this->seenHandler = $handler;
                return $this->sentinel;
            }
        };
    }

    private function terminal(string $body): RequestHandlerInterface
    {
        return new class ($body) implements RequestHandlerInterface {
            public function __construct(private string $body)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->body, 200);
            }
        };
    }

    public function testHandleCallsProcessExactlyOnce(): void
    {
        $sentinel = new Response('SENTINEL', 201);
        $mw = $this->recordingMiddleware($sentinel);
        $inner = $this->terminal('inner');
        $frame = new MiddlewareFrame($mw, $inner);

        $result = $frame->handle(new ServerRequest('/x', 'GET'));

        // FunctionCallRemoval / ReturnRemoval: the exact sentinel must come back.
        $this->assertSame($sentinel, $result);
        $this->assertSame(1, $mw->calls, 'process() must be invoked exactly once');
    }

    public function testHandlePassesSameRequestToProcess(): void
    {
        $mw = $this->recordingMiddleware(new Response('s', 200));
        $frame = new MiddlewareFrame($mw, $this->terminal('inner'));
        $req = new ServerRequest('/path', 'POST');

        $frame->handle($req);

        // The first process() arg is the SAME request object handed to handle().
        $this->assertSame($req, $mw->seenRequest);
    }

    public function testHandlePassesInnerHandlerAsNext(): void
    {
        $mw = $this->recordingMiddleware(new Response('s', 200));
        $inner = $this->terminal('inner-body');
        $frame = new MiddlewareFrame($mw, $inner);

        $frame->handle(new ServerRequest('/', 'GET'));

        // The second process() arg ($next) is the inner handler, NOT the frame.
        $this->assertSame($inner, $mw->seenHandler);
        $this->assertNotSame($frame, $mw->seenHandler);
    }

    public function testReturnIsWhateverProcessReturns(): void
    {
        // A pass-through middleware that simply calls $next->handle() — proves
        // the frame returns the middleware's return value verbatim (which in
        // turn is the terminal's response).
        $passthrough = new class implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                return $handler->handle($request);
            }
        };
        $frame = new MiddlewareFrame($passthrough, $this->terminal('TERMINAL-BODY'));

        $result = $frame->handle(new ServerRequest('/', 'GET'));

        $this->assertSame('TERMINAL-BODY', (string) $result->getBody());
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testFramesNestForOnionOrdering(): void
    {
        // new MiddlewareFrame($outer, new MiddlewareFrame($inner, $terminal))
        // — outer wraps inner wraps terminal. Each appends its tag so the body
        // proves the exact in/out ordering (kills any short-circuit mutation).
        $tagging = function (string $tag): MiddlewareInterface {
            return new class ($tag) implements MiddlewareInterface {
                public function __construct(private string $tag)
                {
                }

                public function process(
                    ServerRequestInterface $request,
                    RequestHandlerInterface $handler
                ): ResponseInterface {
                    $inner = $handler->handle($request);
                    $body = '[' . $this->tag . '>' . (string) $inner->getBody() . '<' . $this->tag . ']';
                    return new Response($body, $inner->getStatusCode());
                }
            };
        };

        $chain = new MiddlewareFrame(
            $tagging('A'),
            new MiddlewareFrame($tagging('B'), $this->terminal('CORE'))
        );

        $result = $chain->handle(new ServerRequest('/', 'GET'));

        // Outer A wraps inner B wraps terminal CORE.
        $this->assertSame('[A>[B>CORE<B]<A]', (string) $result->getBody());
    }
}
