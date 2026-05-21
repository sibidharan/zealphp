<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Conformance: static document-root serving — the "Apache *is* a web server"
 * surface (mod_dir / mod_mime / traversal hardening). Proves that pointing
 * ZealPHP at public/ serves assets safely:
 *   - traversal (encoded / double-encoded / backslash / null-byte) never escapes
 *     the document root;
 *   - dotfiles are never served;
 *   - directory requests never leak a listing (autoindex off);
 *   - common asset extensions get correct MIME types.
 */
class StaticServingConformanceTest extends TestCase
{
    /**
     * Directory-traversal corpus: every variant must stay inside docroot —
     * rejected (400) or not-found (404), and must NEVER leak /etc/passwd or
     * source. Mutation-friendly: weakening any assertion fails loudly.
     */
    public function testTraversalCorpusNeverEscapesDocroot(): void
    {
        $payloads = [
            '/css/../../../../etc/passwd',
            '/css/%2e%2e%2f%2e%2e%2f%2e%2e%2fetc%2fpasswd',
            '/css/%252e%252e%2f%252e%252e%2fetc%2fpasswd', // double-encoded
            '/css/..%2f..%2fapp.php',
            '/css/..%5c..%5capp.php',                       // backslash
            '/%2e%2e/%2e%2e/etc/passwd',
            '/css/%00/../etc/passwd',                       // null-byte + traversal
            '/..%2fapp.php',
        ];
        foreach ($payloads as $p) {
            $r = $this->get($p);
            $this->assertContains($r['status'], [400, 404], "must be rejected: $p (got {$r['status']})");
            $body = (string) $r['body'];
            $this->assertStringNotContainsString('root:', $body, "no /etc/passwd leak: $p");
            $this->assertStringNotContainsString('<?php', $body, "no source leak: $p");
        }
    }

    /**
     * Dotfiles (the live-exploit class — scanners hammer these) are never
     * served: 403 or 404, never 200 with content.
     */
    public function testDotfilesAreNeverServed(): void
    {
        foreach (['/.env', '/.git/config', '/.htaccess', '/.ssh/id_rsa', '/.aws/credentials'] as $p) {
            $r = $this->get($p);
            $this->assertContains($r['status'], [403, 404], "dotfile must not be served: $p (got {$r['status']})");
        }
    }

    /** A directory with no index file never leaks a listing (autoindex off). */
    public function testDirectoryListingIsNotLeaked(): void
    {
        $r = $this->get('/css/');
        $this->assertContains($r['status'], [403, 404], 'bare directory must not autoindex');
        $this->assertStringNotContainsString('Index of', (string) $r['body']);
        $this->assertStringNotContainsString('zealphp.css</a>', (string) $r['body'], 'no file listing leaked');
    }

    /** Correct MIME types for common asset extensions (mod_mime parity). */
    public function testStaticAssetMimeTypes(): void
    {
        $cases = [
            '/css/zealphp.css' => 'text/css',
            '/js/learn.js'     => 'javascript', // text/ or application/javascript
        ];
        foreach ($cases as $path => $expected) {
            $r = $this->get($path);
            $this->assertStatus(200, $r);
            $ct = strtolower($r['headers']['content-type'] ?? '');
            $this->assertStringContainsString($expected, $ct, "$path content-type was '$ct'");
        }
    }

    /**
     * A symlink inside the document root pointing OUTSIDE it must not serve the
     * target (Apache `FollowSymLinks` off / `SymLinksIfOwnerMatch` default).
     * The escaping target (/etc/passwd) must never leak.
     */
    public function testSymlinkEscapeIsRefused(): void
    {
        $link = ZEALPHP_ROOT . '/public/symlink-escape-conformance';
        @unlink($link);
        if (!@symlink('/etc/passwd', $link)) {
            $this->markTestSkipped('cannot create symlink in this environment');
        }
        try {
            $r = $this->get('/symlink-escape-conformance');
            $this->assertContains($r['status'], [403, 404], 'escaping symlink must not be served');
            $this->assertStringNotContainsString('root:', (string) $r['body'], 'symlink target must not leak');
        } finally {
            @unlink($link);
        }
    }

    /** Static assets carry Last-Modified and support conditional 304 (sendFile path). */
    public function testStaticConditionalGet(): void
    {
        $r = $this->get('/http/sendfile-test');
        $this->assertStatus(200, $r);
        $etag = $r['headers']['etag'] ?? '';
        $lastMod = $r['headers']['last-modified'] ?? '';
        $this->assertNotSame('', $etag, 'static file must emit ETag');
        $this->assertNotSame('', $lastMod, 'static file must emit Last-Modified');
        $this->assertStatus(304, $this->get('/http/sendfile-test', ['If-None-Match' => $etag]));
        $this->assertStatus(304, $this->get('/http/sendfile-test', ['If-Modified-Since' => $lastMod]));
    }

    /**
     * H2 — double-encoded traversal. `%252e%252e` decodes once to `%2e%2e`
     * then again to `..`; the pre-routing guard decodes until stable before the
     * `..` check, so it is rejected with 400 (Apache rejects at the parse layer)
     * and never reaches /etc/passwd.
     */
    public function testDoubleEncodedTraversalIsRejected(): void
    {
        $r = $this->get('/css/%252e%252e/%252e%252e/%252e%252e/etc/passwd');
        $this->assertStatus(400, $r);
        $this->assertStringNotContainsString('root:', (string) $r['body'], 'no /etc/passwd leak');
    }

    /**
     * M1 — path normalization. `//admin//` and `/./` segments are collapsed
     * before route matching (Apache ap_normalize_path / MergeSlashes), so a
     * doubled-up or dot-segmented path resolves to the same route as the clean
     * form rather than slipping past a pattern. The implicit static handler
     * resolves `//css//zealphp.css//`-style noise to the real asset.
     */
    public function testDuplicateSlashAndDotSegmentsAreNormalized(): void
    {
        $clean = $this->get('/css/zealphp.css');
        $this->assertStatus(200, $clean);

        foreach (['//css//zealphp.css', '/css/./zealphp.css', '/./css//zealphp.css'] as $p) {
            $r = $this->get($p);
            $this->assertStatus(200, $r, "normalized path should resolve to the asset: $p");
            $this->assertStringContainsString(
                strtolower('text/css'),
                strtolower($r['headers']['content-type'] ?? ''),
                "normalized $p should serve the CSS asset"
            );
        }
    }

    /**
     * M9 — encoded slash. With AllowEncodedSlashes off (the default, matching
     * Apache), a raw `%2F` in the path is refused with 404 before it can decode
     * to a real `/` and alter routing.
     */
    public function testEncodedSlashIsRejected(): void
    {
        foreach (['/css/%2fzealphp.css', '/css%2F..%2F..%2Fapp.php'] as $p) {
            $r = $this->get($p);
            $this->assertStatus(404, $r, "encoded slash must 404: $p");
            $this->assertStringNotContainsString('<?php', (string) $r['body'], "no source leak: $p");
        }
    }
}
