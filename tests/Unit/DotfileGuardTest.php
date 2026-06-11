<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * #368 — the dotfile route guard must match a dot-segment in ANY path position
 * so every blocked dotpath returns a uniform 403, not 403 (final-segment
 * dotfile) vs 404 (dot-directory content). The OLD pattern anchored the
 * dot-segment to the FINAL segment (it ran to end-of-string and could not
 * cross a slash), so `/.git/config` fell through to a 404 while `/.env`
 * matched and returned 403 — leaking which kind of dotpath was requested.
 *
 * #359 — the guard keeps the `.well-known` carve-out (RFC 8615), exact-segment
 * only (a decoy `.well-knownx` stays blocked).
 *
 * Tests the COMPILED pattern the framework registers (via the private
 * registerImplicitRoutes()), not a hand-written copy — so a regression in the
 * source pattern is caught.
 */
final class DotfileGuardTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        App::$block_dotfiles = true;
        if (App::instance() === null) {
            App::init('127.0.0.1', 19994, ZEALPHP_ROOT);
        }
        $app = App::instance();
        self::assertNotNull($app);
        self::$app = $app;
    }

    /**
     * Locate the dotfile guard's compiled regex among the registered routes.
     * The guard is the route whose pattern carries the `.well-known` negative
     * lookahead — unique to the dotfile block.
     */
    private function dotfileGuardPattern(): string
    {
        $app = self::$app;
        $ref = new \ReflectionClass($app);
        // Reset routes to a known baseline, then register implicit routes so the
        // dotfile guard is present regardless of test-ordering side effects.
        $routesProp = $ref->getProperty('routes');
        $routesProp->setAccessible(true);
        /** @var array<int, array<string, mixed>> $before */
        $before = $routesProp->getValue($app);

        $register = $ref->getMethod('registerImplicitRoutes');
        $register->setAccessible(true);
        $register->invoke($app);

        /** @var array<int, array<string, mixed>> $after */
        $after = $routesProp->getValue($app);

        $pattern = null;
        foreach ($after as $route) {
            $p = $route['pattern'] ?? '';
            if (is_string($p) && str_contains($p, 'well-known')) {
                $pattern = $p;
                break;
            }
        }

        // Restore the pre-test route baseline so we don't leak duplicate
        // implicit routes into sibling tests sharing the singleton.
        $routesProp->setValue($app, $before);

        self::assertNotNull($pattern, 'dotfile guard route (with .well-known lookahead) must be registered');
        return $pattern;
    }

    public function testDotfileGuardBlocksFinalSegmentDotfile(): void
    {
        $pat = $this->dotfileGuardPattern();
        $this->assertSame(1, preg_match($pat, '/.env'), '/.env must be blocked (403)');
        $this->assertSame(1, preg_match($pat, '/.htaccess'), '/.htaccess must be blocked (403)');
    }

    public function testDotfileGuardBlocksDotDirectoryContent(): void
    {
        // The #368 fix: a dot-DIRECTORY (non-final dot-segment) must match too,
        // so /.git/config returns the SAME 403 as /.env (was 404 before).
        $pat = $this->dotfileGuardPattern();
        $this->assertSame(1, preg_match($pat, '/.git/config'), '/.git/config must be blocked (403)');
        $this->assertSame(1, preg_match($pat, '/.svn/entries'), '/.svn/entries must be blocked (403)');
    }

    public function testDotfileGuardBlocksDeepNestedDotSegment(): void
    {
        $pat = $this->dotfileGuardPattern();
        $this->assertSame(1, preg_match($pat, '/assets/.git/HEAD'), 'a dot-segment anywhere is blocked');
    }

    public function testDotfileGuardExemptsWellKnown(): void
    {
        // #359 — RFC 8615 well-known URIs must NOT be blocked by the guard.
        $pat = $this->dotfileGuardPattern();
        $this->assertSame(0, preg_match($pat, '/.well-known/security.txt'), '.well-known is exempt');
        $this->assertSame(0, preg_match($pat, '/.well-known/acme-challenge/tok'), 'ACME path is exempt');
        $this->assertSame(0, preg_match($pat, '/.well-known'), 'bare .well-known is exempt');
    }

    public function testDotfileGuardStillBlocksWellKnownDecoy(): void
    {
        // Exact-segment carve-out: a dotfile merely PREFIXED with well-known
        // (.well-knownx) is not the registered convention → stays blocked.
        $pat = $this->dotfileGuardPattern();
        $this->assertSame(1, preg_match($pat, '/.well-knownx'), '.well-knownx is a decoy → blocked');
    }

    public function testDotfileGuardLeavesNormalPathsAlone(): void
    {
        $pat = $this->dotfileGuardPattern();
        $this->assertSame(0, preg_match($pat, '/normal/file.txt'));
        $this->assertSame(0, preg_match($pat, '/foo.bar'), 'a dot in a filename (not a dot-segment) is fine');
        $this->assertSame(0, preg_match($pat, '/wellknownfile'));
    }
}
