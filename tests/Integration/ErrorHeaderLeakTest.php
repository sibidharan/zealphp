<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * B6 regression: handler-set response headers must NOT leak onto error bodies.
 *
 * Apache ap_send_error_response calls apr_table_clear(r->headers_out) before
 * emitting the error page, preserving only:
 *   - Location (redirect chains, err_headers_out fallback)
 *   - WWW-Authenticate (401 Basic/Digest challenge, set in err_headers_out by mod_auth)
 *
 * Trigger routes live in route/_error_test.php under /__error_test/header-leak/*.
 *
 * NOTE: this is an integration test — requires a running server on the port
 * defined by TEST_SERVER_URL (default :8080). The team lead runs the full
 * integration suite post-merge; the gate here is unit + phpstan.
 */
class ErrorHeaderLeakTest extends TestCase
{
    /**
     * A handler that sets Content-Type: application/pdf then throws must NOT
     * carry that Content-Type on the 500 error response.
     */
    public function testContentTypeHeaderDoesNotLeakOnto500(): void
    {
        $r = $this->get('/__error_test/header-leak-contenttype');
        $this->assertStatus(500, $r);
        $ct = $r['headers']['content-type'] ?? '';
        $this->assertStringNotContainsString(
            'application/pdf',
            $ct,
            'Content-Type: application/pdf from the failed handler must not appear on the 500 body'
        );
    }

    /**
     * Custom headers set by a handler that then returns 404 must not appear
     * on the error response.
     */
    public function testCustomAndContentTypeHeadersDoNotLeakOnto404(): void
    {
        $r = $this->get('/__error_test/header-leak-custom');
        $this->assertStatus(404, $r);

        $this->assertArrayNotHasKey(
            'x-custom-leaked',
            $r['headers'],
            'X-Custom-Leaked set by the handler must not appear on the 404 error body'
        );
        $ct = $r['headers']['content-type'] ?? '';
        $this->assertStringNotContainsString(
            'text/csv',
            $ct,
            'Content-Type: text/csv from the handler must not appear on the 404 error body'
        );
    }

    /**
     * WWW-Authenticate set before a 401 MUST survive the header clear.
     * Apache preserves it via err_headers_out (http_request.c:604).
     */
    public function testWwwAuthenticatePreservedOn401(): void
    {
        $r = $this->get('/__error_test/header-leak-401-preserves-www-auth');
        $this->assertStatus(401, $r);

        $this->assertArrayHasKey(
            'www-authenticate',
            $r['headers'],
            'WWW-Authenticate must be preserved on 401 error responses'
        );
        $this->assertStringContainsString(
            'Basic realm="test"',
            $r['headers']['www-authenticate'],
            'WWW-Authenticate value must be unchanged'
        );
        // Non-preserved header must vanish
        $this->assertArrayNotHasKey(
            'x-should-vanish',
            $r['headers'],
            'X-Should-Vanish must be cleared before the 401 error body'
        );
    }

    /**
     * Location set before a non-redirect error (500) MUST survive — Apache
     * checks err_headers_out for Location before clearing headers_out
     * (http_protocol.c:1246-1251).
     */
    public function testLocationPreservedAcrossErrorBoundary(): void
    {
        $r = $this->get('/__error_test/header-leak-location-survives');
        // 500 with Location preserved; status is 500 (not a redirect).
        $this->assertStatus(500, $r);

        $this->assertArrayHasKey(
            'location',
            $r['headers'],
            'Location must be preserved across the error boundary'
        );
        $this->assertStringContainsString(
            '/some-target',
            $r['headers']['location'],
            'Location value must be unchanged'
        );
        // Other non-preserved header must vanish
        $this->assertArrayNotHasKey(
            'x-also-leaked',
            $r['headers'],
            'X-Also-Leaked must be cleared before the 500 error body'
        );
    }
}
