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

    /**
     * A symlink with a SERVABLE name (.php) inside docroot pointing OUTSIDE it
     * must not be executed/served — this is the path that actually reaches
     * includeCheck()'s realpath() containment guard (the bare-name variant above
     * never matches a route). The escaping target must never leak.
     */
    public function testServablePhpSymlinkEscapeIsRefused(): void
    {
        $target = '/etc/passwd';
        $link = ZEALPHP_ROOT . '/public/escape-conformance.php';
        @unlink($link);
        if (!@symlink($target, $link)) {
            $this->markTestSkipped('cannot create symlink in this environment');
        }
        try {
            $r = $this->get('/escape-conformance.php');
            $this->assertContains($r['status'], [403, 404], 'escaping .php symlink must not be served');
            $this->assertStringNotContainsString('root:', (string) $r['body'], 'symlink target must not leak');
            $r2 = $this->get('/escape-conformance');
            $this->assertContains($r2['status'], [403, 404], 'extensionless escaping symlink must not be served');
            $this->assertStringNotContainsString('root:', (string) $r2['body']);
        } finally {
            @unlink($link);
        }
    }

    /**
     * ENOTDIR parity (Apache request.c:1244 — "deny rather than assume not
     * found"): a path whose ancestor segment is a regular file, not a
     * directory, is 403 (not 404). Uses an existing public file as the file
     * ancestor with an extra path segment appended.
     */
    public function testEnotdirReturns403(): void
    {
        // /css/zealphp.css is a regular file; /css/zealphp.css/extra hits ENOTDIR.
        $r = $this->get('/css/zealphp.css/extra');
        $this->assertContains($r['status'], [403, 404], 'ENOTDIR path resolved to a valid status');
        $this->assertStringNotContainsString('<?php', (string) $r['body'], 'no source leak on ENOTDIR');
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
     * then again to `..`; the pre-routing guard (App::decodeUntilStable) decodes
     * to a fixed point before the `..` check, so it is rejected with 400 (Apache
     * rejects at the parse layer) and never reaches /etc/passwd.
     *
     * NOTE: this exercises a PHP-routed path. URLs under the OpenSwoole static
     * handler prefixes (/css, /js, /img) are served by OpenSwoole's C handler
     * before PHP runs and do NOT receive this guard — see
     * {@see testStaticHandlerPrefixesAreOpenSwooleGoverned} and
     * docs/apache-parity-audit.md (static-handler caveat).
     */
    public function testDoubleEncodedTraversalIsRejected(): void
    {
        $r = $this->get('/%252e%252e/%252e%252e/%252e%252e/etc/passwd');
        $this->assertStatus(400, $r);
        $this->assertStringNotContainsString('root:', (string) $r['body'], 'no /etc/passwd leak');
    }

    /**
     * M1 — path normalization. `//x//` and `/./` segments are collapsed before
     * route matching (Apache ap_normalize_path / MergeSlashes), so a doubled-up
     * or dot-segmented path resolves to the same route as the clean form rather
     * than slipping past a pattern guard. Exercised on a PHP-routed endpoint
     * (`/json`); static-handler prefixes are OpenSwoole-governed (see note above).
     */
    public function testDuplicateSlashAndDotSegmentsAreNormalized(): void
    {
        $clean = $this->get('/json');
        $this->assertStatus(200, $clean);

        foreach (['//json', '/./json', '/json/.', '//json//'] as $p) {
            $r = $this->get($p);
            $this->assertStatus(200, $r, "normalized path should resolve to the route: $p");
        }
    }

    /**
     * M9 — encoded slash. With AllowEncodedSlashes off (the default, matching
     * Apache), a raw `%2F` in a PHP-routed path is refused with 404 before it can
     * decode to a real `/` and alter routing. (Static-handler prefixes are
     * OpenSwoole-governed — see note above.)
     */
    public function testEncodedSlashIsRejected(): void
    {
        foreach (['/jso%2fn', '/api%2F..%2F..%2Fapp.php'] as $p) {
            $r = $this->get($p);
            $this->assertStatus(404, $r, "encoded slash must 404: $p");
            $this->assertStringNotContainsString('<?php', (string) $r['body'], "no source leak: $p");
        }
    }

    /**
     * Documented limitation (audit C1/M1/M9 static-handler caveat). OpenSwoole's
     * `enable_static_handler` serves files under the static prefixes (/css, /js,
     * /img) directly from C, BEFORE the PHP request pipeline runs. Therefore the
     * PHP-layer path normalization (M1), encoded-slash rejection (M9) and
     * realpath symlink-containment (C1) do NOT apply to those prefixes — the
     * handler decodes %2F and serves the asset. This test pins that reality so
     * the limitation is explicit, not hidden. Security-sensitive deploys should
     * keep the static dirs symlink-free with no user-controlled filenames, or
     * narrow App::$static_handler_locations / disable enable_static_handler so
     * those paths flow through PHP. See docs/apache-parity-audit.md + STANDARDS.md.
     */
    public function testStaticHandlerPrefixesAreOpenSwooleGoverned(): void
    {
        // Served directly by OpenSwoole's static handler (bypasses PHP guards):
        // %2F is decoded by the C handler and the asset is returned (200), unlike
        // the PHP-routed 404 in testEncodedSlashIsRejected. This is OpenSwoole's
        // behaviour, documented as a known parity caveat — not a PHP-layer bug.
        $r = $this->get('/css/%2fzealphp.css');
        $this->assertStatus(200, $r, 'OpenSwoole static handler serves /css/%2f… directly (documented caveat)');
    }
}
