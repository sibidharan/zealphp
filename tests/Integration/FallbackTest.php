<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * App::setFallback() return-value preservation.
 *
 * Regression test for the bug where the implicit /{file} and /{dir}/{uri}
 * routes funneled the fallback's body through dispatchRoute's int-return
 * branch, which called ob_end_clean() and discarded the response body.
 *
 * Fallback handler is registered in route/_fallback_test.php and dispatches
 * by URI under /__fallback_test/*.
 */
class FallbackTest extends TestCase
{
    public function testFallbackEchoBodyIsPreserved(): void
    {
        $r = $this->get('/__fallback_test/echo');
        $this->assertStatus(200, $r);
        $this->assertStringContainsString('FALLBACK-ECHO-BODY', $r['body']);
    }

    public function testFallbackViaIncludeFile(): void
    {
        $r = $this->get('/__fallback_test/include');
        $this->assertStatus(200, $r);
        $this->assertStringContainsString('FALLBACK-INCLUDE-BODY', $r['body']);
    }

    public function testFallbackReturningStringBody(): void
    {
        $r = $this->get('/__fallback_test/string');
        $this->assertStatus(200, $r);
        $this->assertSame('FALLBACK-STRING-BODY', $r['body']);
    }

    public function testFallbackReturningArrayAsJson(): void
    {
        $r = $this->get('/__fallback_test/json');
        $this->assertStatus(200, $r);
        $this->assertHeader('content-type', 'application/json', $r);
        $body = $this->assertJsonResponse($r);
        $this->assertTrue($body['fallback']);
        $this->assertSame(1, $body['x']);
        $this->assertSame('two', $body['y']);
    }

    public function testFallbackReturningGeneratorStreams(): void
    {
        $r = $this->get('/__fallback_test/generator');
        $this->assertStatus(200, $r);
        $this->assertSame('AAABBBCCC', $r['body']);
    }

    public function testFallbackHonorsExplicitStatus(): void
    {
        $r = $this->get('/__fallback_test/status');
        $this->assertStatus(503, $r);
        $this->assertStringContainsString('FALLBACK-503-BODY', $r['body']);
    }

    public function testFallbackReceivesParamInjection(): void
    {
        $r = $this->get('/__fallback_test/param-injection');
        $this->assertStatus(200, $r);
        $body = $this->assertJsonResponse($r);
        $this->assertTrue($body['has_request']);
        $this->assertTrue($body['has_response']);
    }

    public function testDefaultNotFoundBodyIsPreserved(): void
    {
        // When the test fallback sees an unrecognized URL, it echoes the
        // generic "404 Not Found" body and sets the status. Pre-fix this body
        // was discarded by ob_end_clean(); now it survives.
        $r = $this->get('/__fallback_test/unknown-mode');
        $this->assertStatus(404, $r);
        $this->assertStringContainsString('404 Not Found', $r['body']);
    }
}
