<?php
namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Unit coverage for the pure mod_php superglobal-parity helpers (issues
 * #304/#306/#307):
 *
 *  - App::normalizeUploadedFiles()      — index-major → field-major $_FILES + full_path (#304)
 *  - App::synthesizeRequestServerVars() — QUERY_STRING / CONTENT_* + HTTP Basic/Digest auth (#306/#307)
 *
 * Both are pure static functions of their input, so they're tested directly
 * without booting a server. ($_COOKIE treat-data parity (#305) and the
 * REQUEST_URI-with-query parity (#306) are a separate change — they need a
 * global OpenSwoole config flip + a query-safe dispatch layer respectively.)
 */
class SuperglobalParityUnitTest extends TestCase
{
    // ---- #304: normalizeUploadedFiles -------------------------------------

    public function testSingleFileGainsFullPathKey(): void
    {
        $in = ['file' => [
            'name'     => 'a.txt',
            'type'     => 'text/plain',
            'tmp_name' => '/tmp/x',
            'error'    => 0,
            'size'     => 3,
        ]];
        $out = App::normalizeUploadedFiles($in);
        $this->assertSame(
            [
                'name'      => 'a.txt',
                'type'      => 'text/plain',
                'tmp_name'  => '/tmp/x',
                'error'     => 0,
                'size'      => 3,
                'full_path' => 'a.txt',
            ],
            $out['file'],
            'single file stays flat and gains full_path defaulting to name'
        );
    }

    public function testSingleFileKeepsProvidedFullPath(): void
    {
        $in = ['file' => [
            'name'      => 'a.txt',
            'type'      => 'text/plain',
            'tmp_name'  => '/tmp/x',
            'error'     => 0,
            'size'      => 3,
            'full_path' => 'sub/dir/a.txt',
        ]];
        $out = App::normalizeUploadedFiles($in);
        $this->assertSame('sub/dir/a.txt', $out['file']['full_path']);
    }

    public function testArrayFieldIsTransposedToFieldMajor(): void
    {
        $in = ['files' => [
            0 => ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/a', 'error' => 0, 'size' => 3],
            1 => ['name' => 'b.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/b', 'error' => 0, 'size' => 4],
        ]];
        $out = App::normalizeUploadedFiles($in);
        $this->assertSame(
            [
                'name'      => ['a.txt', 'b.txt'],
                'type'      => ['text/plain', 'text/plain'],
                'tmp_name'  => ['/tmp/a', '/tmp/b'],
                'error'     => [0, 0],
                'size'      => [3, 4],
                'full_path' => ['a.txt', 'b.txt'],
            ],
            $out['files']
        );
        // Canonical PHP idiom must now resolve: parallel arrays keyed by index.
        $this->assertSame('/tmp/a', $out['files']['tmp_name'][0]);
        $this->assertSame('/tmp/b', $out['files']['tmp_name'][1]);
    }

    public function testNestedNameFieldIsTransposedRecursively(): void
    {
        $in = ['doc' => [
            'main'  => ['name' => 'm.txt', 'type' => 't', 'tmp_name' => '/tmp/m', 'error' => 0, 'size' => 1],
            'thumb' => ['name' => 'th.txt', 'type' => 't', 'tmp_name' => '/tmp/th', 'error' => 0, 'size' => 2],
        ]];
        $out = App::normalizeUploadedFiles($in);
        $this->assertSame(['main' => 'm.txt', 'thumb' => 'th.txt'], $out['doc']['name']);
        $this->assertSame(['main' => '/tmp/m', 'thumb' => '/tmp/th'], $out['doc']['tmp_name']);
        $this->assertSame(['main' => 'm.txt', 'thumb' => 'th.txt'], $out['doc']['full_path']);
    }

    public function testNonFileEntryPassesThrough(): void
    {
        $out = App::normalizeUploadedFiles(['weird' => 'not-a-struct']);
        $this->assertSame('not-a-struct', $out['weird']);
    }

    public function testEmptyFilesYieldsEmptyArray(): void
    {
        $this->assertSame([], App::normalizeUploadedFiles([]));
    }

    // ---- #306 + #307: synthesizeRequestServerVars -------------------------

    public function testQueryStringDefaultsToEmptyWhenAbsent(): void
    {
        $out = App::synthesizeRequestServerVars(['REQUEST_URI' => '/s.php']);
        $this->assertSame('', $out['QUERY_STRING']);
    }

    public function testQueryStringPreservedWhenPresent(): void
    {
        $out = App::synthesizeRequestServerVars(['REQUEST_URI' => '/s.php', 'QUERY_STRING' => 'x=1&y=2']);
        $this->assertSame('x=1&y=2', $out['QUERY_STRING']);
    }

    public function testRequestUriLeftPathOnly(): void
    {
        // PR-A keeps REQUEST_URI path-only (the router matches on it); the
        // mod_php query re-append is deferred to the query-safe-dispatch change.
        $out = App::synthesizeRequestServerVars(['REQUEST_URI' => '/s.php', 'QUERY_STRING' => 'x=1']);
        $this->assertSame('/s.php', $out['REQUEST_URI']);
    }

    public function testContentTypeAndLengthMirroredFromHeaders(): void
    {
        $out = App::synthesizeRequestServerVars([
            'REQUEST_URI'         => '/s.php',
            'HTTP_CONTENT_TYPE'   => 'application/x-www-form-urlencoded',
            'HTTP_CONTENT_LENGTH' => '3',
        ]);
        $this->assertSame('application/x-www-form-urlencoded', $out['CONTENT_TYPE']);
        $this->assertSame('3', $out['CONTENT_LENGTH']);
    }

    public function testContentVarsAbsentWhenNoBodyHeaders(): void
    {
        $out = App::synthesizeRequestServerVars(['REQUEST_URI' => '/s.php']);
        $this->assertArrayNotHasKey('CONTENT_TYPE', $out);
        $this->assertArrayNotHasKey('CONTENT_LENGTH', $out);
    }

    public function testBasicAuthDecodedIntoPhpAuthVars(): void
    {
        $out = App::synthesizeRequestServerVars([
            'REQUEST_URI'        => '/s.php',
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('alice:s3cr3t'),
        ]);
        $this->assertSame('alice', $out['PHP_AUTH_USER']);
        $this->assertSame('s3cr3t', $out['PHP_AUTH_PW']);
        // AUTH_TYPE is NOT set (mod_php parity: no auth module configured).
        $this->assertArrayNotHasKey('AUTH_TYPE', $out);
        // HTTP_AUTHORIZATION kept (Bearer flows rely on it).
        $this->assertArrayHasKey('HTTP_AUTHORIZATION', $out);
    }

    public function testBasicAuthPasswordMayContainColon(): void
    {
        $out = App::synthesizeRequestServerVars([
            'REQUEST_URI'        => '/s.php',
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('user:pa:ss'),
        ]);
        $this->assertSame('user', $out['PHP_AUTH_USER']);
        $this->assertSame('pa:ss', $out['PHP_AUTH_PW']);
    }

    public function testBasicAuthCaseInsensitiveScheme(): void
    {
        $out = App::synthesizeRequestServerVars([
            'REQUEST_URI'        => '/s.php',
            'HTTP_AUTHORIZATION' => 'basic ' . base64_encode('a:b'),
        ]);
        $this->assertSame('a', $out['PHP_AUTH_USER']);
        $this->assertSame('b', $out['PHP_AUTH_PW']);
    }

    public function testBasicAuthWithoutColonIsNotDecoded(): void
    {
        $out = App::synthesizeRequestServerVars([
            'REQUEST_URI'        => '/s.php',
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('nocolon'),
        ]);
        $this->assertArrayNotHasKey('PHP_AUTH_USER', $out);
        $this->assertArrayNotHasKey('PHP_AUTH_PW', $out);
    }

    public function testDigestAuthPublishesDigestVar(): void
    {
        $out = App::synthesizeRequestServerVars([
            'REQUEST_URI'        => '/s.php',
            'HTTP_AUTHORIZATION' => 'Digest username="alice", realm="x"',
        ]);
        $this->assertSame('username="alice", realm="x"', $out['PHP_AUTH_DIGEST']);
        $this->assertArrayNotHasKey('AUTH_TYPE', $out);
    }

    public function testNoAuthHeaderLeavesAuthVarsUnset(): void
    {
        $out = App::synthesizeRequestServerVars(['REQUEST_URI' => '/s.php']);
        $this->assertArrayNotHasKey('PHP_AUTH_USER', $out);
        $this->assertArrayNotHasKey('AUTH_TYPE', $out);
    }
}
