<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

use function ZealPHP\zeal_putenv;
use function ZealPHP\zeal_getenv;

/**
 * Unit coverage for the per-coroutine env helpers added to src/utils.php in
 * v0.3.5: `zeal_putenv()` / `zeal_getenv()`.
 *
 * Both functions are pure relative to the request-scoped state container —
 * they read/write `RequestContext::instance()->memo['_env']` and fall back to
 * the boot-time process snapshot `App::$boot_env`. Neither needs a live
 * OpenSwoole server, a request object, or a `$g->zealphp_response`. In the
 * PHPUnit process the coroutine id is < 0, so `RequestContext::instance()`
 * returns the process-wide singleton, which we reset between tests so each
 * case starts from a clean request-scoped env.
 *
 * Behaviour pinned:
 *   - zeal_putenv("NAME=value")  → request-scoped set, getenv reads it back
 *   - zeal_putenv("NAME")        → request-scoped unset (getenv → false)
 *   - zeal_putenv("NAME=")       → empty-string value (NOT an unset)
 *   - "NAME=a=b"                 → only the first '=' splits name/value
 *   - request-scoped value shadows the boot snapshot
 *   - getenv($name) falls back to App::$boot_env when not set request-scoped
 *   - getenv($name) → false for a name in neither store
 *   - getenv($name, true) [$local_only] ignores the boot snapshot
 *   - getenv() [no-arg] returns the merged map (boot ∪ request, request wins,
 *     request 'false' entries removed)
 *   - getenv(null, true) returns ONLY request-scoped entries
 *   - lazy allocation: memo['_env'] auto-initialises when absent / non-array
 */
class UtilsEnvTest extends TestCase
{
    /** @var array<string, string> */
    private array $savedBootEnv = [];

    protected function setUp(): void
    {
        // zeal_getenv() / zeal_putenv() resolve through RequestContext::instance().
        // With superglobals disabled the per-coroutine path is taken, but cid < 0
        // in PHPUnit so we still get the process-wide singleton — deterministic.
        App::superglobals(false);

        $this->savedBootEnv = App::$boot_env;
        App::$boot_env = [];

        // Reset request-scoped env to a known-clean array.
        RequestContext::instance()->memo['_env'] = [];
    }

    protected function tearDown(): void
    {
        App::$boot_env = $this->savedBootEnv;
        RequestContext::instance()->memo['_env'] = [];
        parent::tearDown();
    }

    // ── zeal_putenv() set + read-back ─────────────────────────────────

    public function testPutenvSetsRequestScopedValueReadBackByGetenv(): void
    {
        $this->assertTrue(zeal_putenv('TENANT=acme'));
        $this->assertSame('acme', zeal_getenv('TENANT'));
    }

    public function testPutenvAlwaysReturnsTrue(): void
    {
        $this->assertTrue(zeal_putenv('A=1'));
        $this->assertTrue(zeal_putenv('B'));
        $this->assertTrue(zeal_putenv('C='));
    }

    public function testPutenvOverwritesPreviousRequestScopedValue(): void
    {
        zeal_putenv('LOCALE=en_US');
        zeal_putenv('LOCALE=fr_FR');
        $this->assertSame('fr_FR', zeal_getenv('LOCALE'));
    }

    public function testPutenvEmptyValueIsEmptyStringNotUnset(): void
    {
        // "NAME=" has an '=' so it sets the empty string — distinct from "NAME"
        // (no '=') which removes the variable.
        zeal_putenv('EMPTY=');
        $this->assertSame('', zeal_getenv('EMPTY'));
    }

    public function testPutenvSplitsOnFirstEqualsOnly(): void
    {
        // Only the first '=' separates name from value; the rest is the value.
        zeal_putenv('CONN=key=val;other=x');
        $this->assertSame('key=val;other=x', zeal_getenv('CONN'));
    }

    // ── zeal_putenv() unset ("NAME" with no '=') ──────────────────────

    public function testPutenvNoEqualsStoresFalseSentinelReadAsEmptyString(): void
    {
        // ACTUAL behaviour (not the docblock's aspirational "getenv returns
        // false"): zeal_putenv('NAME') stores a literal `false` sentinel in the
        // request-scoped map. A subsequent NAMED zeal_getenv('NAME') hits the
        // array_key_exists() arm, where `is_scalar(false)` is true → it returns
        // `(string) false` === ''. So a direct named lookup of an "unset" var
        // yields '' here, NOT bool false. The false→absent translation only
        // happens in the no-arg merged-map path (see
        // testGetenvNoArgRemovesUnsetEntriesFromMergedMap). This test pins the
        // observed contract so the divergence is caught if it changes.
        zeal_putenv('GONE=present');
        $this->assertSame('present', zeal_getenv('GONE'));

        zeal_putenv('GONE');
        $this->assertSame('', zeal_getenv('GONE'));
    }

    public function testPutenvUnsetMasksBootEnvAsEmptyStringOnNamedLookup(): void
    {
        // A request-scoped unset records a `false` sentinel that shadows the
        // boot value: the array_key_exists() arm short-circuits before the boot
        // fallback, so the named lookup never sees '/usr/bin'. It returns ''
        // (the scalar-false cast), confirming the boot value is masked.
        App::$boot_env = ['PATH' => '/usr/bin'];
        zeal_putenv('PATH');
        $this->assertSame('', zeal_getenv('PATH'));
    }

    // ── zeal_getenv($name) fallback to boot snapshot ──────────────────

    public function testGetenvFallsBackToBootEnv(): void
    {
        App::$boot_env = ['HOME' => '/home/zeal'];
        $this->assertSame('/home/zeal', zeal_getenv('HOME'));
    }

    public function testGetenvReturnsFalseForUnknownName(): void
    {
        $this->assertFalse(zeal_getenv('DOES_NOT_EXIST'));
    }

    public function testRequestScopedValueShadowsBootEnv(): void
    {
        App::$boot_env = ['REGION' => 'us-east'];
        zeal_putenv('REGION=eu-west');
        $this->assertSame('eu-west', zeal_getenv('REGION'));
    }

    // ── zeal_getenv($name, local_only) ────────────────────────────────

    public function testGetenvLocalOnlyIgnoresBootEnv(): void
    {
        App::$boot_env = ['ONLY_BOOT' => 'boot-value'];
        // $local_only=true must NOT consult the boot snapshot for a named lookup.
        $this->assertFalse(zeal_getenv('ONLY_BOOT', true));
    }

    public function testGetenvLocalOnlyReturnsRequestScopedValue(): void
    {
        zeal_putenv('REQ_ONLY=here');
        $this->assertSame('here', zeal_getenv('REQ_ONLY', true));
    }

    // ── zeal_getenv() no-arg merged map ───────────────────────────────

    public function testGetenvNoArgReturnsMergedMapRequestWins(): void
    {
        App::$boot_env = ['A' => 'boot-a', 'B' => 'boot-b'];
        zeal_putenv('B=req-b');   // overrides boot
        zeal_putenv('C=req-c');   // request-only

        $merged = zeal_getenv();
        $this->assertIsArray($merged);
        $this->assertSame('boot-a', $merged['A']);   // from boot, untouched
        $this->assertSame('req-b', $merged['B']);     // request wins over boot
        $this->assertSame('req-c', $merged['C']);     // request-only
    }

    public function testGetenvNoArgRemovesUnsetEntriesFromMergedMap(): void
    {
        App::$boot_env = ['KEEP' => 'k', 'DROP' => 'd'];
        zeal_putenv('DROP');   // request-scoped unset

        $merged = zeal_getenv();
        $this->assertIsArray($merged);
        $this->assertArrayHasKey('KEEP', $merged);
        $this->assertArrayNotHasKey('DROP', $merged);
    }

    public function testGetenvNoArgLocalOnlyReturnsOnlyRequestScoped(): void
    {
        App::$boot_env = ['BOOT_X' => 'bx'];
        zeal_putenv('REQ_X=rx');

        $local = zeal_getenv(null, true);
        $this->assertIsArray($local);
        $this->assertSame(['REQ_X' => 'rx'], $local);
    }

    public function testGetenvNoArgLocalOnlySkipsFalseUnsetEntries(): void
    {
        // A request-scoped 'false' (unset marker) must not appear in the
        // local-only merged map — the foreach drops it via the $v === false arm.
        zeal_putenv('SET_ME=yes');
        zeal_putenv('UNSET_ME');

        $local = zeal_getenv(null, true);
        $this->assertIsArray($local);
        $this->assertSame(['SET_ME' => 'yes'], $local);
    }

    // ── lazy allocation of memo['_env'] ───────────────────────────────

    public function testPutenvLazilyAllocatesEnvWhenMissing(): void
    {
        // Simulate a fresh request context where memo['_env'] was never set.
        unset(RequestContext::instance()->memo['_env']);
        $this->assertTrue(zeal_putenv('FRESH=ok'));
        $this->assertSame('ok', zeal_getenv('FRESH'));
    }

    public function testPutenvReinitialisesEnvWhenNonArray(): void
    {
        // A non-array sentinel in the slot must be replaced with a fresh array
        // rather than fatally indexed into.
        RequestContext::instance()->memo['_env'] = 'corrupt-non-array';
        $this->assertTrue(zeal_putenv('REPAIR=done'));
        $this->assertSame('done', zeal_getenv('REPAIR'));
    }

    public function testGetenvTreatsMissingEnvSlotAsEmptyLocal(): void
    {
        // With no memo['_env'] at all, a named lookup must still fall back to
        // the boot snapshot (the `(is_array(...)) ? ... : []` guard).
        unset(RequestContext::instance()->memo['_env']);
        App::$boot_env = ['BOOT_ONLY' => 'bv'];
        $this->assertSame('bv', zeal_getenv('BOOT_ONLY'));
    }

    public function testGetenvNoArgWithEmptyEverythingReturnsEmptyArray(): void
    {
        App::$boot_env = [];
        RequestContext::instance()->memo['_env'] = [];
        $this->assertSame([], zeal_getenv());
    }
}
