<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

use function ZealPHP\header as zheader;
use function ZealPHP\http_response_code as zhttp_response_code;
use function ZealPHP\response_set_status;

/**
 * #327 — a raw `header("HTTP/1.1 <code> <reason>")` status line passes through
 * verbatim, like Apache mod_php (verified live on Apache 2.4.67 + PHP 8.4:
 * `header("HTTP/1.1 600 Custom Reason")` → `HTTP/1.1 600 Custom Reason` on the
 * wire, code AND reason untouched — while `http_response_code(600)` → 500).
 *
 * The vendor PSR-7 Response::withStatus() throws on codes outside its phrase
 * table, so the raw line rides RequestContext side-channel fields
 * (`$raw_status_code` / `$raw_status_reason`); the PSR flow keeps the
 * pre-existing placeholder (#320 semantics) and the emit chokepoint overrides
 * the wire status via App::emitEffectiveStatus().
 */
class RawStatusLineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        $g = RequestContext::instance();
        $g->status = null;
        $g->raw_status_code = null;
        $g->raw_status_reason = null;
    }

    protected function tearDown(): void
    {
        $g = RequestContext::instance();
        $g->status = null;
        $g->raw_status_code = null;
        $g->raw_status_reason = null;
        parent::tearDown();
    }

    public function testRawStatusLinePassesThroughOutOfRangeCodeAndReason(): void
    {
        $g = RequestContext::instance();
        zheader('HTTP/1.1 600 Custom Reason');

        $this->assertSame(600, $g->raw_status_code, 'raw code carried verbatim (#327)');
        $this->assertSame('Custom Reason', $g->raw_status_reason);
        // The PSR flow keeps the #320 placeholder (600 is not withStatus-safe).
        $this->assertSame(500, $g->status);
    }

    public function testRawStatusLineInRangeKeepsPsrStatusAndCustomReason(): void
    {
        $g = RequestContext::instance();
        zheader('HTTP/1.1 418 I am a teapot');

        $this->assertSame(418, $g->raw_status_code);
        $this->assertSame('I am a teapot', $g->raw_status_reason);
        $this->assertSame(418, $g->status, 'in-range code flows to the PSR layer unchanged');
    }

    public function testRawStatusLineWithoutReasonLeavesReasonNull(): void
    {
        $g = RequestContext::instance();
        zheader('HTTP/1.1 207');

        $this->assertSame(207, $g->raw_status_code);
        $this->assertNull($g->raw_status_reason, 'no reason text → defer to IANA phrase at emit');
    }

    public function testHttpResponseCodeAfterRawLineClearsTheOverride(): void
    {
        $g = RequestContext::instance();
        zheader('HTTP/1.1 600 Custom');
        zhttp_response_code(201);

        $this->assertNull($g->raw_status_code, 'a later explicit set wins over the raw line');
        $this->assertNull($g->raw_status_reason);
        $this->assertSame(201, $g->status);
    }

    public function testResponseSetStatusClearsTheOverride(): void
    {
        $g = RequestContext::instance();
        zheader('HTTP/1.1 999 Edge');
        response_set_status(404);

        $this->assertNull($g->raw_status_code);
        $this->assertSame(404, $g->status);
    }

    // ---- App::emitEffectiveStatus() resolution ------------------------------

    public function testEmitEffectiveStatusPrefersRawOverride(): void
    {
        $g = RequestContext::instance();
        $g->raw_status_code = 600;
        $g->raw_status_reason = 'Custom Reason';
        $fake = new \ZealPHP\Tests\Unit\HTTP\FakeOpenSwooleResponse();

        $effective = App::emitEffectiveStatus($fake, 500);

        $this->assertSame(600, $effective);
        $this->assertContains(['status', 600, 'Custom Reason'], $fake->log);
    }

    public function testEmitEffectiveStatusRawCodeWithoutReasonUsesIanaPhrase(): void
    {
        $g = RequestContext::instance();
        $g->raw_status_code = 207;
        $g->raw_status_reason = null;
        $fake = new \ZealPHP\Tests\Unit\HTTP\FakeOpenSwooleResponse();

        $effective = App::emitEffectiveStatus($fake, 207);

        $this->assertSame(207, $effective);
        $this->assertContains(['status', 207, 'Multi-Status'], $fake->log);
    }

    public function testEmitEffectiveStatusWithoutOverrideMatchesEmitStatus(): void
    {
        $fake = new \ZealPHP\Tests\Unit\HTTP\FakeOpenSwooleResponse();

        $effective = App::emitEffectiveStatus($fake, 451);

        $this->assertSame(451, $effective);
        $this->assertContains(['status', 451, 'Unavailable For Legal Reasons'], $fake->log);
    }

    // ---- #474: a raw status line must not bleed into the NEXT request --------

    public function testRequestBeginResetClearsRawStatusOverride(): void
    {
        $g = RequestContext::instance();
        zheader('HTTP/1.1 200 OK');                 // prior request armed the raw override
        $this->assertSame(200, $g->raw_status_code);

        $m = new \ReflectionMethod(App::class, 'clearRawStatusOverride');
        $m->setAccessible(true);
        $m->invoke(null, $g);                       // the NEXT request begins

        $this->assertNull($g->raw_status_code, '#474 — raw override cleared at request-begin');
        $this->assertNull($g->raw_status_reason);
        // The helper clears only the raw pair; $g->status is reset inline at the
        // call site (left at the zheader-set 200 here, untouched by the helper).
        $this->assertSame(200, $g->status);
    }

    public function testStatusDoesNotBleedAcrossRequestsAfterRawLine(): void
    {
        // request N: a Slim-style raw 200 emit arms raw_status_code on the reused $g.
        $g = RequestContext::instance();
        zheader('HTTP/1.1 200 OK');

        // request N+1 begins (mixed/legacy-cgi reuse the same RequestContext):
        $m = new \ReflectionMethod(App::class, 'clearRawStatusOverride');
        $m->setAccessible(true);
        $m->invoke(null, $g);

        // request N+1 is a ZealAPI 404 — it must reach the wire as 404, not the stale 200.
        $fake = new \ZealPHP\Tests\Unit\HTTP\FakeOpenSwooleResponse();
        $effective = App::emitEffectiveStatus($fake, 404);

        $this->assertSame(404, $effective, '#474 — 404 no longer inherits the prior 200');
        $this->assertContains(['status', 404, 'Not Found'], $fake->log);
    }
}
