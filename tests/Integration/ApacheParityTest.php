<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Apache+mod_php parity — verifies that the uopz overrides and global shims
 * for Apache-only built-ins (apache_request_headers, header_remove, ob_flush,
 * is_uploaded_file, headers_sent, etc.) match Apache+mod_php behavior.
 *
 * Hits /parity/* routes defined in route/apache_parity.php.
 */
class ApacheParityTest extends TestCase
{
    public function testApacheRequestHeadersCanonicalCase(): void
    {
        $r = $this->http('GET', '/parity/request-headers', [
            'X-Foo-Bar' => 'baz',
            'User-Agent' => 'parity-test/1.0',
        ]);
        $this->assertStatus(200, $r);
        $body = $this->assertJsonResponse($r);
        $this->assertArrayHasKey('X-Foo-Bar', $body['apache_request_headers']);
        $this->assertSame('baz', $body['apache_request_headers']['X-Foo-Bar']);
        $this->assertArrayHasKey('User-Agent', $body['apache_request_headers']);
    }

    public function testGetallheadersAliasesApacheRequestHeaders(): void
    {
        $r = $this->http('GET', '/parity/request-headers', ['X-Alias-Test' => 'yes']);
        $body = $this->assertJsonResponse($r);
        $this->assertSame($body['apache_request_headers'], $body['getallheaders']);
        $this->assertArrayHasKey('X-Alias-Test', $body['getallheaders']);
    }

    public function testApacheResponseHeadersReturnsSetHeaders(): void
    {
        $r = $this->get('/parity/response-headers');
        $body = $this->assertJsonResponse($r);
        $this->assertSame('alpha', $body['response_headers']['X-Test-Custom'] ?? null);
        $this->assertSame('beta',  $body['response_headers']['X-Another-Header'] ?? null);
    }

    public function testHeaderRemoveDropsNamedHeader(): void
    {
        $r = $this->get('/parity/header-remove');
        $this->assertStatus(200, $r);
        $this->assertArrayHasKey('x-should-stay', $r['headers']);
        $this->assertSame('kept', $r['headers']['x-should-stay']);
        $this->assertArrayNotHasKey('x-should-go', $r['headers']);
    }

    public function testHeadersSentIsFalseDuringHandler(): void
    {
        $r = $this->get('/parity/headers-sent');
        $body = $this->assertJsonResponse($r);
        $this->assertFalse($body['sent']);
    }

    public function testSetRawCookieDoesNotUrlEncode(): void
    {
        $r = $this->get('/parity/setrawcookie');
        $setCookie = $r['headers']['set-cookie'] ?? '';
        $this->assertStringContainsString('rawck=a b+c/d', $setCookie,
            "setrawcookie should preserve special chars verbatim: $setCookie");
    }

    public function testHttpStatusLineParsesStatus(): void
    {
        $r = $this->get('/parity/header-status');
        $this->assertStatus(418, $r);
    }

    public function testHeaderExplicitCodeParam(): void
    {
        $r = $this->get('/parity/header-code');
        $this->assertStatus(503, $r);
    }

    public function testApacheEnvAndNotePersistInRequest(): void
    {
        $r = $this->get('/parity/apache-env');
        $body = $this->assertJsonResponse($r);
        $this->assertSame('bar', $body['foo']);
        $this->assertSame('hello', $body['greet']);
    }

    public function testVirtualReturnsFalse(): void
    {
        $r = $this->get('/parity/virtual');
        $body = $this->assertJsonResponse($r);
        $this->assertFalse($body['returned']);
    }

    public function testSafeStubsReturnApacheCompatibleValues(): void
    {
        $r = $this->get('/parity/safe-stubs');
        $body = $this->assertJsonResponse($r);
        $this->assertTrue($body['set_time_limit']);
        $this->assertSame(0, $body['ignore_user_abort']); // initial value before set
        $this->assertSame(0, $body['connection_status']);
        $this->assertSame(0, $body['connection_aborted']);
    }

    public function testIsUploadedFileRejectsForgedPath(): void
    {
        $r = $this->get('/parity/is-uploaded');
        $body = $this->assertJsonResponse($r);
        $this->assertFalse($body['forged']);
    }

    public function testObFlushMidHandlerStreams(): void
    {
        $r = $this->get('/parity/ob-flush');
        $this->assertStatus(200, $r);
        // All three chunks should arrive in the body (in order).
        $this->assertStringContainsString('first-chunk',  $r['body']);
        $this->assertStringContainsString('second-chunk', $r['body']);
        $this->assertStringContainsString('third-chunk',  $r['body']);
    }
}
