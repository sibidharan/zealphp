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
}
