<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Session\Handler\RedisSessionHandler;

use function ZealPHP\Session\zeal_session_set_save_handler;

/**
 * #295 — pins the session-handler WIRING that was previously broken: a configured
 * custom handler (Redis) is now actually reachable by the zeal_session_* core,
 * instead of being silently ignored in favour of the inline file path.
 *
 * Three contracts:
 *   1. App::sessionHandler('redis') resolves to a RedisSessionHandler instance
 *      (so the read sites' `?? App::resolveActiveSessionHandler()` fallback wires it).
 *   2. The unconfigured default (null) resolves to null — preserving the inline
 *      FILE path (the BC pin: a wiring fix must not change the unconfigured default).
 *   3. zeal_session_set_save_handler($h) (the new session_set_save_handler override)
 *      writes BOTH scopes — process-wide App::$session_handler AND the current
 *      request's $g->session_params['handler'].
 *
 * RedisSessionHandler is lazy-connect (#271/#285), so constructing/resolving one
 * here needs no live Redis. The full read/write round-trip (which needs HOOK_ALL +
 * a coroutine) is covered by the #295 deterministic harness, not unit tests.
 */
final class SessionHandlerWiringTest extends TestCase
{
    /** @var string|\SessionHandlerInterface|null */
    private $orig;

    protected function setUp(): void
    {
        $this->orig = App::sessionHandler();
    }

    protected function tearDown(): void
    {
        App::sessionHandler($this->orig); // setter resets the resolver memoisation
        unset(RequestContext::instance()->session_params['handler']);
    }

    public function testRedisAliasResolvesToRedisHandler(): void
    {
        App::sessionHandler('redis');
        $this->assertInstanceOf(RedisSessionHandler::class, App::resolveActiveSessionHandler());
    }

    public function testUnconfiguredResolvesToNullPreservingFileDefault(): void
    {
        App::sessionHandler(null);
        $this->assertNull(
            App::resolveActiveSessionHandler(),
            '#295 BC pin: unconfigured stays on the inline FILE path'
        );
    }

    public function testInstanceResolvesToSameInstance(): void
    {
        $h = new RedisSessionHandler('192.0.2.1', 6379); // TEST-NET-1, never connected
        App::sessionHandler($h);
        $this->assertSame($h, App::resolveActiveSessionHandler());
    }

    public function testOverrideWritesBothProcessAndPerRequestScopes(): void
    {
        $spy = new class extends \SessionHandler {};
        $ok = zeal_session_set_save_handler($spy);

        $this->assertTrue($ok);
        $this->assertSame($spy, App::sessionHandler(), 'process-wide slot set (future requests)');
        $this->assertSame(
            $spy,
            RequestContext::instance()->session_params['handler'],
            'per-coroutine slot set (this request)'
        );
    }

    public function testOverrideRejectsNonHandlerArgument(): void
    {
        $this->assertFalse(zeal_session_set_save_handler('not-a-handler'));
        $this->assertFalse(zeal_session_set_save_handler(null));
    }
}
