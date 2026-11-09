<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Coroutine;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\HTTP\Response;
use ZealPHP\RequestContext;

/**
 * A Response writes to the request context it was made in, and holds that
 * context weakly: the context holds the response, so a strong reference back
 * kept every request (and its upload temp files) alive until the cycle
 * collector ran.
 */
class ResponseContextTest extends TestCase
{
    private bool $superglobals;

    protected function setUp(): void
    {
        $this->superglobals = App::$superglobals;
        App::superglobals(false);
    }

    protected function tearDown(): void
    {
        App::superglobals($this->superglobals);
    }

    public function testAResponseWritesToItsOwnRequestsContext(): void
    {
        $seen = [];
        Coroutine::run(function () use (&$seen): void {
            $handoff = new Coroutine\Channel(1);
            $done = new Coroutine\Channel(1);
            Coroutine::create(function () use ($handoff, $done, &$seen): void {
                $own = RequestContext::instance();
                $own->status = null;
                $handoff->push(new Response(new \OpenSwoole\Http\Response()));
                $done->pop(5);                 // stay alive while the other coroutine uses it
                $seen['own'] = $own->status;
            });
            Coroutine::create(function () use ($handoff, $done, &$seen): void {
                $other = RequestContext::instance();
                $other->status = null;
                $response = $handoff->pop(5);
                if ($response instanceof Response) {
                    $response->header('Location', '/next');   // sets 302 on its context
                }
                $seen['other'] = $other->status;
                $done->push(true);
            });
        });
        $this->assertSame(302, $seen['own'] ?? null, 'the status lands in the context the response belongs to');
        $this->assertArrayHasKey('other', $seen);
        $this->assertNull($seen['other'], 'not in whichever context calls it');
    }

    public function testAResponseDoesNotKeepItsContextAlive(): void
    {
        $kept = null;
        $context = null;
        Coroutine::run(function () use (&$kept, &$context): void {
            Coroutine::create(function () use (&$kept, &$context): void {
                $g = RequestContext::instance();
                $g->zealphp_response = $kept = new Response(new \OpenSwoole\Http\Response());
                $context = \WeakReference::create($g);
            });
        });
        $this->assertInstanceOf(Response::class, $kept);
        $this->assertInstanceOf(\WeakReference::class, $context);
        $this->assertNull($context->get(), 'the coroutine ended; its context must be freed');
    }
}
