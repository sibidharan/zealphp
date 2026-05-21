<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * Conformance: pre-routing URI security guard (Apache parity).
 *
 * Covers the three hardening fixes applied to App::process():
 *   - H2: double-encoded traversal is caught by decode-until-stable
 *         (App::decodeUntilStable) before the `..` check runs.
 *   - M1: path normalization (App::normalizeRequestPath) collapses `//` and
 *         drops `/./` and `/../` segments the way Apache ap_normalize_path does,
 *         so security-relevant route patterns can't be bypassed.
 *   - M9: encoded-slash policy is gated by App::$allow_encoded_slashes (the
 *         404-reject decision itself lives in process(); here we pin the flag's
 *         default and the helper invariants the guard relies on).
 *
 * The helpers are pure and public, so they're exercised directly — no server.
 */
class UriSecurityConformanceTest extends TestCase
{
    private bool $savedAllowEncodedSlashes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedAllowEncodedSlashes = App::$allow_encoded_slashes;
    }

    protected function tearDown(): void
    {
        App::$allow_encoded_slashes = $this->savedAllowEncodedSlashes;
        parent::tearDown();
    }

    // --- H2: decode-until-stable -------------------------------------------

    public function testSingleEncodedTraversalDecodesToDotDot(): void
    {
        $this->assertSame('/a/../b', App::decodeUntilStable('/a/%2e%2e/b'));
    }

    public function testDoubleEncodedTraversalFullyDecodes(): void
    {
        // %252e%252e -> %2e%2e -> .. — a single rawurldecode would stop at the
        // first layer and leave `%2e%2e` (which the `..` regex misses).
        $this->assertSame('/a/../b', App::decodeUntilStable('/a/%252e%252e/b'));
    }

    public function testTripleEncodedTraversalFullyDecodes(): void
    {
        $this->assertSame('/../etc/passwd', App::decodeUntilStable('/%25252e%25252e/etc/passwd'));
    }

    public function testDecodeUntilStableReturnsUnchangedForPlainPath(): void
    {
        $this->assertSame('/a/b/c', App::decodeUntilStable('/a/b/c'));
    }

    public function testDecodeUntilStableHonoursIterationCap(): void
    {
        // A pathological over-encoded input must not loop forever; with a cap of
        // 1 we peel exactly one layer and return the partially-decoded form.
        $this->assertSame('/%252e%252e', App::decodeUntilStable('/%25252e%25252e', 1));
    }

    public function testDecodeUntilStableDecodesNullByteEncoding(): void
    {
        $this->assertSame("/x\0y", App::decodeUntilStable('/x%00y'));
    }

    /**
     * The guard's traversal regex must fire on every decode depth once the
     * payload is fully decoded. This pins the H2 contract end-to-end on the
     * helper output that process() feeds into its `..` check.
     *
     * @dataProvider traversalPayloads
     */
    public function testTraversalSurvivesToDotDotRegex(string $payload): void
    {
        $decoded = App::decodeUntilStable($payload);
        $this->assertSame(
            1,
            preg_match('#(^|/)\.\.(/|$)#', $decoded),
            "decoded payload should expose `..`: {$payload} -> {$decoded}"
        );
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function traversalPayloads(): array
    {
        return [
            ['/css/%2e%2e/%2e%2e/etc/passwd'],
            ['/css/%252e%252e/%252e%252e/etc/passwd'],
            ['/%2e%2e/secret'],
            ['/a/b/%252e%252e/%252e%252e/%252e%252e/etc/passwd'],
        ];
    }

    // --- M1: path normalization --------------------------------------------

    public function testCollapsesDuplicateSlashes(): void
    {
        $this->assertSame('/admin/', App::normalizeRequestPath('//admin//'));
    }

    public function testCollapsesTripleSlashRuns(): void
    {
        $this->assertSame('/a/b/c', App::normalizeRequestPath('/a///b////c'));
    }

    public function testDropsCurrentDirSegments(): void
    {
        $this->assertSame('/admin', App::normalizeRequestPath('/./admin'));
        $this->assertSame('/a/b', App::normalizeRequestPath('/a/./b'));
    }

    public function testUnwindsParentSegments(): void
    {
        $this->assertSame('/b', App::normalizeRequestPath('/a/../b'));
    }

    public function testParentSegmentClampsAtRoot(): void
    {
        // `..` above root is dropped (clamped), matching Apache's routing path.
        $this->assertSame('/etc/passwd', App::normalizeRequestPath('/../etc/passwd'));
        $this->assertSame('/etc/passwd', App::normalizeRequestPath('/../../etc/passwd'));
    }

    public function testNormalizationLeavesCleanPathUntouched(): void
    {
        $this->assertSame('/a/b/c', App::normalizeRequestPath('/a/b/c'));
    }

    public function testRootStaysRoot(): void
    {
        $this->assertSame('/', App::normalizeRequestPath('/'));
        $this->assertSame('/', App::normalizeRequestPath('//'));
    }

    public function testPreservesTrailingSlash(): void
    {
        // DirectorySlash / strip-trailing-slash logic downstream depends on the
        // trailing slash surviving normalization.
        $this->assertSame('/admin/', App::normalizeRequestPath('/admin/'));
        $this->assertSame('/admin/', App::normalizeRequestPath('/admin/./'));
    }

    public function testNoTrailingSlashWhenOriginalHadNone(): void
    {
        $this->assertSame('/admin', App::normalizeRequestPath('/admin'));
    }

    public function testAsteriskFormPassesThrough(): void
    {
        // OPTIONS * — Apache special-cases the asterisk-form target.
        $this->assertSame('*', App::normalizeRequestPath('*'));
    }

    public function testEmptyPathPassesThrough(): void
    {
        $this->assertSame('', App::normalizeRequestPath(''));
    }

    public function testRelativePathKeepsLeadingParent(): void
    {
        // Relative input (no leading slash): a leading `..` has nothing to unwind
        // and is retained rather than silently dropped.
        $this->assertSame('../a', App::normalizeRequestPath('../a'));
        $this->assertSame('a/b', App::normalizeRequestPath('a/./b'));
    }

    public function testMixedNormalizationCombines(): void
    {
        $this->assertSame('/admin/', App::normalizeRequestPath('//./admin//./'));
    }

    // --- M9: encoded-slash flag --------------------------------------------

    public function testAllowEncodedSlashesDefaultsOff(): void
    {
        // Apache AllowEncodedSlashes default is Off; we mirror it. The guard in
        // process() returns 404 for a raw %2F when this is false.
        $fresh = App::$allow_encoded_slashes;
        $this->assertFalse($fresh, 'App::$allow_encoded_slashes must default to false (Apache parity)');
    }

    public function testAllowEncodedSlashesIsTogglable(): void
    {
        App::$allow_encoded_slashes = true;
        $this->assertTrue(App::$allow_encoded_slashes);
        App::$allow_encoded_slashes = false;
        $this->assertFalse(App::$allow_encoded_slashes);
    }
}
