<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Public/ file-based routing parity with Apache+mod_php:
 *   DirectorySlash, DirectoryIndex (.php → .html → .htm fallback),
 *   dotfile blocking, URL-decoded traversal rejection, PATH_INFO,
 *   ETag / If-None-Match / If-Modified-Since on sendFile().
 *
 * Fixtures under public/parity-test/.
 */
class PublicRoutingTest extends TestCase
{
    public function testDirectorySlashRedirects301(): void
    {
        $r = $this->get('/parity-test/sub-dir');
        $this->assertStatus(301, $r);
        $this->assertHeader('location', '/parity-test/sub-dir/', $r);
    }

    public function testDirectoryIndexHtmlFallback(): void
    {
        // Directory has only index.html, no index.php — Apache DirectoryIndex
        // should fall through to it.
        $r = $this->get('/parity-test/sub-dir/');
        $this->assertStatus(200, $r);
        $this->assertStringContainsString('parity-test-index-html-OK', $r['body']);
    }

    public function testDirectoryIndexPhpStillWorks(): void
    {
        $r = $this->get('/parity-test/php-dir/');
        $this->assertStatus(200, $r);
        $this->assertStringContainsString('parity-test-index-php-OK', $r['body']);
    }

    public function testDotfileBlockedAtRoot(): void
    {
        $r = $this->get('/.env');
        $this->assertStatus(403, $r);
    }

    public function testDotfileBlockedInSubdir(): void
    {
        // The actual file public/parity-test/.secretdotfile exists; the dotfile
        // pattern route must intercept and 403 before the static handler or
        // implicit file routes serve it.
        $r = $this->get('/parity-test/.secretdotfile');
        $this->assertStatus(403, $r);
    }

    public function testWellKnownIsAllowedThroughDotfileBlock(): void
    {
        // .well-known is a registered IETF convention (RFC 8615) — must not 403.
        // No fixture exists, so 404 (or fallback) is acceptable; 403 is NOT.
        $r = $this->get('/.well-known/acme-challenge/token');
        $this->assertNotSame(403, $r['status']);
    }

    public function testUrlEncodedTraversalRejected(): void
    {
        $r = $this->get('/%2e%2e/etc/passwd');
        $this->assertStatus(400, $r);
    }

    public function testNullByteRejected(): void
    {
        $r = $this->get('/foo%00bar');
        $this->assertStatus(400, $r);
    }

    public function testPathInfoIsExposed(): void
    {
        $r = $this->get('/parity-test/path-info.php/users/42');
        $this->assertStatus(200, $r);
        $body = $this->assertJsonResponse($r);
        $this->assertSame('/users/42', $body['path_info']);
        $this->assertStringContainsString('parity-test/path-info.php', $body['script_name']);
    }

    public function testSendFileEmitsEtag(): void
    {
        $r = $this->get('/http/sendfile-test');
        $this->assertStatus(200, $r);
        $etag = $r['headers']['etag'] ?? '';
        $this->assertNotSame('', $etag, 'sendFile should emit an ETag header');
        $this->assertStringStartsWith('W/"', $etag);
    }

    public function testSendFileConditionalGetReturns304(): void
    {
        $r = $this->get('/http/sendfile-test');
        $etag = $r['headers']['etag'] ?? '';
        $this->assertNotSame('', $etag);
        $r2 = $this->get('/http/sendfile-test', ['If-None-Match' => $etag]);
        $this->assertStatus(304, $r2);
    }

    public function testSendFileIfModifiedSinceReturns304(): void
    {
        $r = $this->get('/http/sendfile-test');
        $lastMod = $r['headers']['last-modified'] ?? '';
        $this->assertNotSame('', $lastMod);
        $r2 = $this->get('/http/sendfile-test', ['If-Modified-Since' => $lastMod]);
        $this->assertStatus(304, $r2);
    }

    public function testSendFileRangeRequest(): void
    {
        $r = $this->get('/http/sendfile-test', ['Range' => 'bytes=0-9']);
        $this->assertStatus(206, $r);
        $this->assertArrayHasKey('content-range', $r['headers']);
        $this->assertSame(10, strlen($r['body']));
    }
}
