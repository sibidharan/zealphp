<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Coroutine;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\Compat\CurrentRequestContext;
use ZealPHP\RequestContext;

/**
 * compat/g.php under ZealPHP: `$GLOBALS['g']` must always mean the CURRENT
 * request. It used to hold the first request's RequestContext for the life of
 * the worker, so later requests read and wrote that request's session.
 */
class CompatCurrentRequestContextTest extends TestCase
{
    private const SHIM = __DIR__ . '/../../compat/g.php';

    private bool $superglobals;

    protected function setUp(): void
    {
        $this->superglobals = App::$superglobals;
        App::superglobals(false);
        unset($GLOBALS['g']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['g']);
        App::superglobals($this->superglobals);
    }

    public function testGlobalGFollowsEachConcurrentRequest(): void
    {
        $seen = [];
        Coroutine::run(function () use (&$seen): void {
            foreach (['alice', 'bob'] as $i => $user) {
                Coroutine::create(function () use ($i, $user, &$seen): void {
                    $ctx = RequestContext::instance();
                    $ctx->get = ['user' => $user];
                    $ctx->session = [];
                    include self::SHIM;              // like require_once: the 2nd run finds the proxy already there
                    $local = $g === $ctx;            // the local $g is this request's context
                    Coroutine::usleep(20000 - $i * 10000); // interleave: bob finishes first
                    $GLOBALS['g']->session['who'] = $user; // by-reference write through the proxy
                    $seen[$user] = [
                        $GLOBALS['g']->get['user'],
                        $ctx->session['who'],
                        isset($GLOBALS['g']->session['who']),
                        $local,
                    ];
                });
            }
        });

        $this->assertSame(['alice', 'alice', true, true], $seen['alice']);
        $this->assertSame(['bob', 'bob', true, true], $seen['bob']);
        $this->assertInstanceOf(CurrentRequestContext::class, $GLOBALS['g']);
    }

    public function testSetAndUnsetForwardToTheCurrentContext(): void
    {
        $r = [];
        Coroutine::run(function () use (&$r): void {
            $proxy = new CurrentRequestContext();
            $proxy->get = ['a' => '1'];
            $r[] = RequestContext::instance()->get;
            $proxy->memo['k'] = 'v';
            $r[] = isset($proxy->memo['k']);
            $r[] = isset($proxy->no_such_slot);
            $proxy->psr_request = 'x';
            $r[] = isset($proxy->psr_request);
            unset($proxy->psr_request);
            $r[] = isset($proxy->psr_request);
        });
        $this->assertSame([['a' => '1'], true, false, true, false], $r);
    }

    public function testTheShimKeepsAnExistingProxy(): void
    {
        $same = null;
        Coroutine::run(function () use (&$same): void {
            include self::SHIM;
            $first = $GLOBALS['g'];
            include self::SHIM;
            $same = $first === $GLOBALS['g'];
        });
        $this->assertTrue($same);
    }
}
