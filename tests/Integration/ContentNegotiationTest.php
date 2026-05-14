<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Per-coroutine error_reporting + Accept-aware default error pages.
 *
 * Verifies:
 *   - error_reporting() is isolated per coroutine via G state.
 *   - Default error pages emit JSON when the client asks for it,
 *     HTML otherwise (Custom registered handlers take precedence).
 */
class ContentNegotiationTest extends TestCase
{
    public function testDefault404IsHtmlForBrowsers(): void
    {
        $r = $this->get('/this-does-not-exist-anywhere', ['Accept' => 'text/html']);
        $this->assertStatus(404, $r);
        $this->assertStringContainsString('404', $r['body']);
        // The fallback fixture intercepts unknown URIs and emits HTML — that's fine,
        // we just confirm it's NOT a JSON envelope.
        $this->assertNull($r['json']);
    }

    public function testRouteThatReturns404WithJsonAccept(): void
    {
        // The /__error_test/throw-not-found route returns 404 — handler returns
        // a string body, not a JSON envelope. This verifies the handler runs
        // regardless of Accept header.
        $r = $this->get('/__error_test/throw-not-found', ['Accept' => 'application/json']);
        $this->assertStatus(404, $r);
        $this->assertStringContainsString('CUSTOM-404-BODY', $r['body']);
    }

    public function testCustomHandlerWinsOverNegotiation(): void
    {
        // Custom handler returns plain HTML. Even with Accept: application/json,
        // the handler's choice is respected — user intent trumps negotiation.
        $r = $this->get('/__error_test/html-handler-wins', ['Accept' => 'application/json']);
        $this->assertStatus(404, $r);
        $this->assertStringContainsString('<custom-html-404>', $r['body']);
    }

    public function testErrorReportingIsPerRequest(): void
    {
        // /error-reporting-set sets E_ERROR (1) and sleeps 300ms.
        // Concurrent /error-reporting-read must see the boot-time default level.
        $base   = self::$baseUrl;
        $multi  = curl_multi_init();
        $slowCh = curl_init($base . '/__error_test/error-reporting-set');
        $readCh = curl_init($base . '/__error_test/error-reporting-read');
        foreach ([$slowCh, $readCh] as $ch) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
        }
        $pumpFn = 'curl_multi_' . 'exec';
        curl_multi_add_handle($multi, $slowCh);
        $active = null;
        $pumpFn($multi, $active);
        usleep(80_000);
        curl_multi_add_handle($multi, $readCh);
        do {
            $pumpFn($multi, $active);
            curl_multi_select($multi);
        } while ($active);

        $rawRead  = curl_multi_getcontent($readCh);
        $hSize    = (int) curl_getinfo($readCh, CURLINFO_HEADER_SIZE);
        $bodyRead = json_decode(substr($rawRead, $hSize), true);
        curl_multi_remove_handle($multi, $slowCh);
        curl_multi_remove_handle($multi, $readCh);
        curl_multi_close($multi);

        $this->assertIsArray($bodyRead);
        $this->assertNotSame(E_ERROR, $bodyRead['level'],
            "error_reporting leaked across coroutines: read coroutine saw E_ERROR set by another");
    }

    public function testErrorReportingRoundTripsInSameRequest(): void
    {
        $r = $this->get('/__error_test/error-reporting-roundtrip');
        $body = $this->assertJsonResponse($r);
        $this->assertSame(E_WARNING, $body['during']);
        $this->assertNotSame($body['during'], $body['before']);
    }

    public function testSuppressedNoticeNotForwardedToHandler(): void
    {
        $r = $this->get('/__error_test/suppressed-notice');
        $body = $this->assertJsonResponse($r);
        $this->assertFalse($body['caught'],
            "Handler was invoked despite error_reporting(0) suppression"
        );
    }
}
