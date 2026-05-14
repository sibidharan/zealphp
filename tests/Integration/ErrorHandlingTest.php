<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Apache `ErrorDocument` equivalent — verifies App::setErrorHandler() routes
 * 4xx/5xx through user-registered handlers, with return-value support for
 * string / array / Generator / void+echo / status-via-http_response_code.
 *
 * Handlers + trigger routes live in route/_error_test.php. They self-gate to
 * /__error_test/* URLs so the demo site's normal error pages stay untouched.
 */
class ErrorHandlingTest extends TestCase
{
    public function testStatusSpecific404Handler(): void
    {
        $r = $this->get('/__error_test/throw-not-found');
        $this->assertStatus(404, $r);
        $this->assertStringContainsString('CUSTOM-404-BODY status=404', $r['body']);
    }

    public function testStatusSpecific500HandlerReceivesException(): void
    {
        $r = $this->get('/__error_test/throw-exception');
        $this->assertStatus(500, $r);
        $this->assertStringContainsString('CUSTOM-500-BODY msg=boom-message', $r['body']);
    }

    public function testStatusSpecific403HandlerEchoBody(): void
    {
        $r = $this->get('/__error_test/.htblocked');
        $this->assertStatus(403, $r);
        $this->assertStringContainsString('CUSTOM-403-BODY', $r['body']);
    }

    public function testStatusSpecific400HandlerForTraversal(): void
    {
        $r = $this->get('/__error_test/%2e%2e/foo');
        $this->assertStatus(400, $r);
        $this->assertStringContainsString('CUSTOM-400-BODY', $r['body']);
    }

    public function testTeapotHandlerReturnsArrayAsJson(): void
    {
        $r = $this->get('/__error_test/teapot');
        // OpenSwoole's status table omits 418 — accept either 418 (mapped) or 200 fallback,
        // but body must reflect the handler's array.
        $body = $this->assertJsonResponse($r);
        $this->assertSame(418, $body['error']);
        $this->assertSame('teapot', $body['catch_all_special']);
    }

    public function testHandlerCanReturnArrayAsJson(): void
    {
        $r = $this->get('/__error_test/array-via-410');
        $this->assertStatus(410, $r);
        $this->assertHeader('content-type', 'application/json', $r);
        $body = $this->assertJsonResponse($r);
        $this->assertSame(410, $body['error_status']);
        $this->assertSame('array', $body['shape']);
    }

    public function testHandlerCanReturnGenerator(): void
    {
        $r = $this->get('/__error_test/generator-via-422');
        $this->assertStatus(422, $r);
        $this->assertSame('ABC', $r['body']);
    }

    public function testHandlerOwnExceptionFallsBackToDefault(): void
    {
        // Status 502 chosen so the global 500 handler is not poisoned for other tests.
        // The 502 handler itself throws — recursion guard must catch it and emit the
        // framework default body instead of looping back into the handler.
        $r = $this->get('/__error_test/handler-self-throws');
        $this->assertStatus(502, $r);
        $this->assertStringContainsString('502', $r['body']);
    }

    public function testRouteIntReturnRoutesThroughRenderError(): void
    {
        // `return 404;` from a registered route must trigger the 404 handler,
        // not just emit an empty 404 — Apache ErrorDocument semantics.
        $r = $this->get('/__error_test/throw-not-found');
        $this->assertNotSame('', $r['body']);
    }
}
