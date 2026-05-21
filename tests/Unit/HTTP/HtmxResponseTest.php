<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\HTTP;

use ZealPHP\App;
use ZealPHP\HTTP\HtmxResponse;
use ZealPHP\HTTP\Request as ZRequest;
use ZealPHP\HTTP\Response as ZResponse;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * Unit tests for ZealPHP\HTTP\HtmxResponse and the Response::htmx() accessor.
 */
class HtmxResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $g = RequestContext::instance();
        $g->status = null;
        $g->_streaming = false;
        $g->server = [];

        $or = new \OpenSwoole\Http\Request();
        $or->header = [];
        $g->zealphp_request = new ZRequest($or);
    }

    private function fake(bool $writable = true): FakeOpenSwooleResponse
    {
        $f = new FakeOpenSwooleResponse();
        $f->writable = $writable;
        return $f;
    }

    private function wrap(FakeOpenSwooleResponse $fake): ZResponse
    {
        return new ZResponse($fake);
    }

    /** Extract header() calls from the fake log as [name => value] map (last wins). */
    private function headers(FakeOpenSwooleResponse $fake): array
    {
        $out = [];
        foreach ($fake->log as $entry) {
            if (($entry[0] ?? null) === 'header') {
                $out[(string)$entry[1]] = (string)$entry[2];
            }
        }
        return $out;
    }

    // ---- Response::htmx() accessor -----------------------------------------

    public function testHtmxAccessorReturnsSameInstance(): void
    {
        $resp = $this->wrap($this->fake());
        $a = $resp->htmx();
        $b = $resp->htmx();
        $this->assertSame($a, $b);
    }

    public function testHtmxAccessorReturnsHtmxResponseInstance(): void
    {
        $resp = $this->wrap($this->fake());
        $this->assertInstanceOf(HtmxResponse::class, $resp->htmx());
    }

    // ---- pushUrl() ---------------------------------------------------------

    public function testPushUrlQueuesHeader(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()->pushUrl('/new-page');
        $resp->flush();
        $this->assertSame('/new-page', $this->headers($fake)['HX-Push-Url']);
    }

    public function testPushUrlReturnsSelf(): void
    {
        $resp = $this->wrap($this->fake());
        $htmx = $resp->htmx();
        $this->assertSame($htmx, $htmx->pushUrl('/x'));
    }

    // ---- replaceUrl() ------------------------------------------------------

    public function testReplaceUrlQueuesHeader(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()->replaceUrl('/updated');
        $resp->flush();
        $this->assertSame('/updated', $this->headers($fake)['HX-Replace-Url']);
    }

    // ---- redirect() --------------------------------------------------------

    public function testRedirectQueuesHxRedirectHeader(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()->redirect('/dashboard');
        $resp->flush();
        $this->assertSame('/dashboard', $this->headers($fake)['HX-Redirect']);
    }

    // ---- location() --------------------------------------------------------

    public function testLocationQueuesHxLocationHeader(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()->location('/page');
        $resp->flush();
        $this->assertSame('/page', $this->headers($fake)['HX-Location']);
    }

    public function testLocationAcceptsJsonObject(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $json = '{"path":"/page","target":"#content"}';
        $resp->htmx()->location($json);
        $resp->flush();
        $this->assertSame($json, $this->headers($fake)['HX-Location']);
    }

    // ---- reswap() ----------------------------------------------------------

    public function testReswapQueuesHeader(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()->reswap('outerHTML');
        $resp->flush();
        $this->assertSame('outerHTML', $this->headers($fake)['HX-Reswap']);
    }

    // ---- retarget() --------------------------------------------------------

    public function testRetargetQueuesHeader(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()->retarget('#alerts');
        $resp->flush();
        $this->assertSame('#alerts', $this->headers($fake)['HX-Retarget']);
    }

    // ---- reselect() --------------------------------------------------------

    public function testReselectQueuesHeader(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()->reselect('.partial');
        $resp->flush();
        $this->assertSame('.partial', $this->headers($fake)['HX-Reselect']);
    }

    // ---- refresh() ---------------------------------------------------------

    public function testRefreshDefaultQueuesTrue(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()->refresh();
        $resp->flush();
        $this->assertSame('true', $this->headers($fake)['HX-Refresh']);
    }

    public function testRefreshFalseQueuesFalse(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()->refresh(false);
        $resp->flush();
        $this->assertSame('false', $this->headers($fake)['HX-Refresh']);
    }

    // ---- trigger() ---------------------------------------------------------

    public function testTriggerQueuesSingleEvent(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()->trigger('itemSaved');
        $resp->flush();
        $this->assertSame('itemSaved', $this->headers($fake)['HX-Trigger']);
    }

    public function testTriggerQueuesJsonEvents(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $json = '{"showMessage":{"level":"info","message":"Saved!"}}';
        $resp->htmx()->trigger($json);
        $resp->flush();
        $this->assertSame($json, $this->headers($fake)['HX-Trigger']);
    }

    // ---- triggerAfterSwap() ------------------------------------------------

    public function testTriggerAfterSwapQueuesHeader(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()->triggerAfterSwap('afterSwapEvent');
        $resp->flush();
        $this->assertSame('afterSwapEvent', $this->headers($fake)['HX-Trigger-After-Swap']);
    }

    // ---- triggerAfterSettle() ----------------------------------------------

    public function testTriggerAfterSettleQueuesHeader(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()->triggerAfterSettle('afterSettleEvent');
        $resp->flush();
        $this->assertSame('afterSettleEvent', $this->headers($fake)['HX-Trigger-After-Settle']);
    }

    // ---- chaining ----------------------------------------------------------

    public function testChainingQueuesMultipleHeaders(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()
            ->retarget('#main')
            ->reswap('innerHTML')
            ->trigger('contentLoaded');
        $resp->flush();
        $headers = $this->headers($fake);
        $this->assertSame('#main', $headers['HX-Retarget']);
        $this->assertSame('innerHTML', $headers['HX-Reswap']);
        $this->assertSame('contentLoaded', $headers['HX-Trigger']);
    }

    // ---- oob() static helper -----------------------------------------------

    public function testOobProducesWrapperWithIdAndSwapAttribute(): void
    {
        $html = HtmxResponse::oob('notifications', '<li>New item</li>');
        $this->assertStringContainsString('id="notifications"', $html);
        $this->assertStringContainsString('hx-swap-oob="true"', $html);
        $this->assertStringContainsString('<li>New item</li>', $html);
    }

    public function testOobUsesCustomSwapStrategy(): void
    {
        $html = HtmxResponse::oob('cart', '<span>3</span>', 'innerHTML');
        $this->assertStringContainsString('hx-swap-oob="innerHTML"', $html);
    }

    public function testOobUsesCustomTag(): void
    {
        $html = HtmxResponse::oob('toast', 'msg', 'true', 'span');
        $this->assertStringStartsWith('<span ', $html);
        $this->assertStringEndsWith('</span>', $html);
    }

    public function testOobEscapesIdAndSwapValues(): void
    {
        $html = HtmxResponse::oob('my"id', 'content', 'bad"swap');
        $this->assertStringContainsString('id="my&quot;id"', $html);
        $this->assertStringContainsString('hx-swap-oob="bad&quot;swap"', $html);
    }

    public function testOobStripsNonAlphanumericFromTag(): void
    {
        $html = HtmxResponse::oob('el', 'body', 'true', 'div<script>');
        $this->assertStringStartsWith('<divscript ', $html);
    }

    public function testOobFallsBackToDivForEmptyTag(): void
    {
        $html = HtmxResponse::oob('el', 'body', 'true', '<<>>');
        $this->assertStringStartsWith('<div ', $html);
    }

    // ---- CRLF injection blocked via Response::header() --------------------

    public function testCrlfInValueIsBlockedSilently(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        // trigger() calls Response::header() which blocks CRLF — no header queued.
        @$resp->htmx()->trigger("evil\r\nSet-Cookie: x=1");
        $resp->flush();
        $this->assertArrayNotHasKey('HX-Trigger', $this->headers($fake));
    }

    // ---- Edge branches (Part B coverage boost) ----------------------------

    public function testLocationWithArrayJsonForm(): void
    {
        // location() accepts a pre-encoded JSON object string
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $json = '{"path":"/dashboard","target":"#main","swap":"innerHTML"}';
        $resp->htmx()->location($json);
        $resp->flush();
        $this->assertSame($json, $this->headers($fake)['HX-Location']);
    }

    public function testReplaceUrlFalseCancellation(): void
    {
        // replaceUrl('false') — the string "false" prevents URL replacement
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()->replaceUrl('false');
        $resp->flush();
        $this->assertSame('false', $this->headers($fake)['HX-Replace-Url']);
    }

    public function testPushUrlFalseCancellation(): void
    {
        // pushUrl('false') — the string "false" prevents history push
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()->pushUrl('false');
        $resp->flush();
        $this->assertSame('false', $this->headers($fake)['HX-Push-Url']);
    }

    public function testRefreshFalseQueuesStringFalse(): void
    {
        // refresh(false) must queue "false" string — not omit the header
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->htmx()->refresh(false);
        $resp->flush();
        $this->assertArrayHasKey('HX-Refresh', $this->headers($fake));
        $this->assertSame('false', $this->headers($fake)['HX-Refresh']);
    }

    public function testChainOverStreamedResponse(): void
    {
        // HtmxResponse must work even on a response marked as streaming
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $g = RequestContext::instance();
        $g->_streaming = true;

        $resp->htmx()->retarget('#output')->trigger('streamDone');
        $resp->flush();

        $headers = $this->headers($fake);
        $this->assertSame('#output', $headers['HX-Retarget']);
        $this->assertSame('streamDone', $headers['HX-Trigger']);
    }
}
