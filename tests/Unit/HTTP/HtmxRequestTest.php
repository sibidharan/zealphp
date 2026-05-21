<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\HTTP;

use ZealPHP\App;
use ZealPHP\HTTP\Request as ZRequest;
use ZealPHP\Tests\TestCase;

/**
 * Unit tests for the htmx HX-* request-header detection helpers added to
 * ZealPHP\HTTP\Request.
 */
class HtmxRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
    }

    private function makeRequest(array $headers): ZRequest
    {
        $r = new \OpenSwoole\Http\Request();
        $r->header = $headers;
        return new ZRequest($r);
    }

    // ---- isHtmx() ----------------------------------------------------------

    public function testIsHtmxReturnsTrueWhenHeaderPresent(): void
    {
        $req = $this->makeRequest(['hx-request' => 'true']);
        $this->assertTrue($req->isHtmx());
    }

    public function testIsHtmxReturnsFalseWhenHeaderAbsent(): void
    {
        $req = $this->makeRequest([]);
        $this->assertFalse($req->isHtmx());
    }

    public function testIsHtmxReturnsFalseForNonTrueValue(): void
    {
        $req = $this->makeRequest(['hx-request' => '1']);
        $this->assertFalse($req->isHtmx());
    }

    // ---- isBoosted() -------------------------------------------------------

    public function testIsBoostedReturnsTrueWhenHeaderPresent(): void
    {
        $req = $this->makeRequest(['hx-boosted' => 'true']);
        $this->assertTrue($req->isBoosted());
    }

    public function testIsBoostedReturnsFalseWhenHeaderAbsent(): void
    {
        $req = $this->makeRequest([]);
        $this->assertFalse($req->isBoosted());
    }

    // ---- isHistoryRestoreRequest() -----------------------------------------

    public function testIsHistoryRestoreRequestTrue(): void
    {
        $req = $this->makeRequest(['hx-history-restore-request' => 'true']);
        $this->assertTrue($req->isHistoryRestoreRequest());
    }

    public function testIsHistoryRestoreRequestFalseWhenAbsent(): void
    {
        $req = $this->makeRequest([]);
        $this->assertFalse($req->isHistoryRestoreRequest());
    }

    // ---- htmxTarget() ------------------------------------------------------

    public function testHtmxTargetReturnsId(): void
    {
        $req = $this->makeRequest(['hx-target' => 'results']);
        $this->assertSame('results', $req->htmxTarget());
    }

    public function testHtmxTargetReturnsNullWhenAbsent(): void
    {
        $req = $this->makeRequest([]);
        $this->assertNull($req->htmxTarget());
    }

    public function testHtmxTargetReturnsNullForEmptyString(): void
    {
        $req = $this->makeRequest(['hx-target' => '']);
        $this->assertNull($req->htmxTarget());
    }

    // ---- htmxTrigger() -----------------------------------------------------

    public function testHtmxTriggerReturnsId(): void
    {
        $req = $this->makeRequest(['hx-trigger' => 'btn-submit']);
        $this->assertSame('btn-submit', $req->htmxTrigger());
    }

    public function testHtmxTriggerReturnsNullWhenAbsent(): void
    {
        $req = $this->makeRequest([]);
        $this->assertNull($req->htmxTrigger());
    }

    // ---- htmxTriggerName() -------------------------------------------------

    public function testHtmxTriggerNameReturnsName(): void
    {
        $req = $this->makeRequest(['hx-trigger-name' => 'search']);
        $this->assertSame('search', $req->htmxTriggerName());
    }

    public function testHtmxTriggerNameReturnsNullWhenAbsent(): void
    {
        $req = $this->makeRequest([]);
        $this->assertNull($req->htmxTriggerName());
    }

    // ---- htmxCurrentUrl() --------------------------------------------------

    public function testHtmxCurrentUrlReturnsUrl(): void
    {
        $req = $this->makeRequest(['hx-current-url' => 'https://example.com/page']);
        $this->assertSame('https://example.com/page', $req->htmxCurrentUrl());
    }

    public function testHtmxCurrentUrlReturnsNullWhenAbsent(): void
    {
        $req = $this->makeRequest([]);
        $this->assertNull($req->htmxCurrentUrl());
    }

    // ---- htmxPrompt() ------------------------------------------------------

    public function testHtmxPromptReturnsUserInput(): void
    {
        $req = $this->makeRequest(['hx-prompt' => 'Are you sure?']);
        $this->assertSame('Are you sure?', $req->htmxPrompt());
    }

    public function testHtmxPromptReturnsNullWhenAbsent(): void
    {
        $req = $this->makeRequest([]);
        $this->assertNull($req->htmxPrompt());
    }
}
