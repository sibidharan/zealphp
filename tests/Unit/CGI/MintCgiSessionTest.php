<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\CGI\Dispatcher;
use ZealPHP\RequestContext;

/**
 * #355 (legacy-cgi half) — `Dispatcher::mintCgiSession()` must NOT mint an
 * unsolicited session id for a first-time visitor that never started a
 * session. mod_php with session.auto_start=0 emits no Set-Cookie and leaves
 * the request-side $_COOKIE clean unless the script calls session_start().
 *
 * The old code unconditionally host-minted (in cgiOwnsSessions() mode), which:
 *   - injected the minted id into $g->cookie (=> the subprocess's $_COOKIE), a
 *     request-input fidelity bug (a handler saw a cookie the client never sent);
 *   - emitted a Set-Cookie the application never asked for (breaks shared-cache
 *     caching, starts a store entry per request).
 *
 * Now lazy: forward an id the client ALREADY sent (returning visitor); mint a
 * fresh first-visit id only when App::$cgi_session_auto_start is explicitly on
 * (the #108 escape hatch). mintCgiSession() is private — exercised via
 * reflection.
 */
final class MintCgiSessionTest extends TestCase
{
    private bool $origSg;
    /** @var int|bool|null */
    private $origPi;
    private bool $origAuto;

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        $this->origSg   = App::$superglobals;
        $this->origPi   = App::$process_isolation;
        $this->origAuto = App::$cgi_session_auto_start;
        // Legacy-CGI mode so cgiOwnsSessions() is true (the only mode where
        // mintCgiSession() does anything).
        App::$run_has_started   = false;
        App::$superglobals      = true;
        App::$process_isolation = true;
        App::$cgi_session_auto_start = false;
    }

    protected function tearDown(): void
    {
        App::$superglobals           = $this->origSg;
        App::$process_isolation      = $this->origPi;
        App::$cgi_session_auto_start = $this->origAuto;
        App::$run_has_started        = false;
    }

    private function mint(RequestContext $g): mixed
    {
        $m = new \ReflectionMethod(Dispatcher::class, 'mintCgiSession');
        $m->setAccessible(true);
        return $m->invoke(null, $g);
    }

    private function freshContext(): RequestContext
    {
        $g = RequestContext::instance();
        $g->cookie = [];
        $g->openswoole_response = new class {
            /** @var array<int, array<int, mixed>> */
            public array $cookies = [];
            public function isWritable(): bool { return true; }
            public function cookie(mixed ...$a): bool { $this->cookies[] = $a; return true; }
            public function __call(string $n, array $a): mixed { return true; }
        };
        return $g;
    }

    public function testFirstVisitNoCookieMintsNothingByDefault(): void
    {
        $this->assertTrue(App::cgiOwnsSessions(), 'precondition: legacy-cgi mode');
        $g = $this->freshContext();

        $result = $this->mint($g);

        $this->assertNull($result, 'no id returned for a first-time visitor');
        $this->assertArrayNotHasKey('PHPSESSID', $g->cookie, '$_COOKIE must stay clean — no injected id');
        $this->assertSame([], $g->openswoole_response->cookies, 'no unsolicited Set-Cookie emitted');
    }

    public function testReturningVisitorCookieIsForwardedNotReminted(): void
    {
        $g = $this->freshContext();
        $g->cookie = ['PHPSESSID' => 'client-sent-id-123'];

        $result = $this->mint($g);

        $this->assertSame('client-sent-id-123', $result, 'the client-sent id is forwarded');
        $this->assertSame('client-sent-id-123', $g->cookie['PHPSESSID'], 'no remint — the client id is kept');
        // No new Set-Cookie is needed when the client already holds the id.
        $this->assertSame([], $g->openswoole_response->cookies);
    }

    public function testEagerOptInMintsFirstVisitSession(): void
    {
        App::$cgi_session_auto_start = true;
        $g = $this->freshContext();

        $result = $this->mint($g);

        $this->assertIsString($result, 'eager opt-in mints a fresh id (#108 escape hatch)');
        $this->assertNotSame('', $result);
        $this->assertSame($result, $g->cookie['PHPSESSID'], 'minted id stashed for the subprocess $_COOKIE');
        $this->assertCount(1, $g->openswoole_response->cookies, 'eager opt-in emits one Set-Cookie');
        $this->assertSame('PHPSESSID', $g->openswoole_response->cookies[0][0]);
        $this->assertSame($result, $g->openswoole_response->cookies[0][1]);
    }

    public function testNoOpOutsideCgiOwnsSessionsMode(): void
    {
        App::$process_isolation = false; // mixed mode → cgiOwnsSessions() false
        $this->assertFalse(App::cgiOwnsSessions());
        $g = $this->freshContext();

        $this->assertNull($this->mint($g));
        $this->assertArrayNotHasKey('PHPSESSID', $g->cookie);
    }

    // ── emitCgiSessionCookieFromMeta() — the post-subprocess cookie emit ──

    /**
     * @param array<string, mixed> $meta
     */
    private function emit(RequestContext $g, mixed $response, array $meta): void
    {
        $m = new \ReflectionMethod(Dispatcher::class, 'emitCgiSessionCookieFromMeta');
        $m->setAccessible(true);
        $m->invoke(null, $g, $response, $meta);
    }

    public function testEmitDoesNothingWhenSubprocessReportedNoSession(): void
    {
        $g = $this->freshContext();
        // No 'session_id' key → the script never called session_start().
        $this->emit($g, $g->openswoole_response, ['status' => 200]);
        $this->assertSame([], $g->openswoole_response->cookies, '#355 — no cookie when no session was started');
    }

    public function testEmitSendsCookieWhenSubprocessStartedSession(): void
    {
        $g = $this->freshContext();
        $this->emit($g, $g->openswoole_response, ['session_id' => 'sub-started-abc', 'session_name' => 'PHPSESSID']);
        $this->assertCount(1, $g->openswoole_response->cookies, '#108 — emit the session cookie the subprocess could not');
        $this->assertSame('PHPSESSID', $g->openswoole_response->cookies[0][0]);
        $this->assertSame('sub-started-abc', $g->openswoole_response->cookies[0][1]);
    }

    public function testEmitSkipsRedundantCookieForReturningVisitor(): void
    {
        $g = $this->freshContext();
        $g->cookie = ['PHPSESSID' => 'already-have-this'];
        $this->emit($g, $g->openswoole_response, ['session_id' => 'already-have-this', 'session_name' => 'PHPSESSID']);
        $this->assertSame([], $g->openswoole_response->cookies, 'no redundant Set-Cookie when the client already holds the id');
    }

    public function testEmitHonoursCustomSessionName(): void
    {
        $g = $this->freshContext();
        $this->emit($g, $g->openswoole_response, ['session_id' => 'xyz', 'session_name' => 'MYSESS']);
        $this->assertSame('MYSESS', $g->openswoole_response->cookies[0][0]);
    }

    public function testEmitNoOpOutsideCgiOwnsSessionsMode(): void
    {
        App::$process_isolation = false; // cgiOwnsSessions() false
        $g = $this->freshContext();
        $this->emit($g, $g->openswoole_response, ['session_id' => 'xyz']);
        $this->assertSame([], $g->openswoole_response->cookies);
    }
}
