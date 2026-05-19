<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\App;
use ZealPHP\ZealAPI;

/**
 * Unit tests for the v0.2.25 auth hooks — issue #13.
 *
 * Before this fix, ZealAPI::isAuthenticated() was hardcoded `return false;`
 * (a stub from PR #10) so every endpoint guarded by `requirePostAuth()`
 * would 403 even for legitimately logged-in users. The fix lets apps
 * register a callback via `App::authChecker()` / `adminChecker()` /
 * `usernameProvider()` that ZealAPI consults instead of the hardcoded
 * fail-closed default.
 *
 * These tests pin three things per method:
 *   - default behaviour when no callback is registered (safe fallback)
 *   - registered callback's return value flows through
 *   - clearing the callback (setter with null) restores the default
 *
 * Plus `requirePostAuth()` integration so the composed guard inherits
 * the new behaviour automatically.
 */
class ZealApiAuthHooksTest extends TestCase
{
    private ZealAPI $api;

    public static function tearDownAfterClass(): void
    {
        // Defensive reset so a test failure can't leave a checker installed
        // that leaks into other unit test classes.
        App::authChecker(null);
        App::adminChecker(null);
        App::usernameProvider(null);
    }

    protected function setUp(): void
    {
        App::authChecker(null);
        App::adminChecker(null);
        App::usernameProvider(null);
        // ZealAPI's __construct expects ($request, $response, $cwd); none
        // of the auth methods touch those, so placeholders are fine for
        // these tests. Real request handling is integration-tested
        // separately under tests/Integration/.
        $this->api = new ZealAPI(null, null, sys_get_temp_dir());
    }

    // ──────────────────────────────────────────────────────────────
    // isAuthenticated()
    // ──────────────────────────────────────────────────────────────

    public function testIsAuthenticatedDefaultsToFalse(): void
    {
        $this->assertFalse($this->api->isAuthenticated());
        $this->assertNull(App::authChecker());
    }

    public function testIsAuthenticatedRoutesThroughCheckerWhenTrue(): void
    {
        App::authChecker(fn(): bool => true);
        $this->assertTrue($this->api->isAuthenticated());
    }

    public function testIsAuthenticatedRoutesThroughCheckerWhenFalse(): void
    {
        App::authChecker(fn(): bool => false);
        $this->assertFalse($this->api->isAuthenticated());
    }

    public function testIsAuthenticatedCoercesTruthyToBool(): void
    {
        // The setter accepts any callable returning a value; the framework
        // coerces to bool so apps can return their own truthy values
        // (e.g. an object, a non-empty string) without ceremony.
        /** @phpstan-ignore-next-line argument.type — verifying runtime coercion */
        App::authChecker(fn() => 'some-non-empty-user-id');
        $this->assertTrue($this->api->isAuthenticated());
    }

    public function testIsAuthenticatedNullClearsCheckerAndRestoresDefault(): void
    {
        App::authChecker(fn(): bool => true);
        $this->assertTrue($this->api->isAuthenticated());
        App::authChecker(null);
        $this->assertFalse($this->api->isAuthenticated());
    }

    // ──────────────────────────────────────────────────────────────
    // isAdmin()
    // ──────────────────────────────────────────────────────────────

    public function testIsAdminDefaultsToFalse(): void
    {
        $this->assertFalse($this->api->isAdmin());
    }

    public function testIsAdminRoutesThroughChecker(): void
    {
        App::adminChecker(fn(): bool => true);
        $this->assertTrue($this->api->isAdmin());
        App::adminChecker(fn(): bool => false);
        $this->assertFalse($this->api->isAdmin());
    }

    public function testIsAdminIndependentOfAuthChecker(): void
    {
        // Possible to be authenticated but not admin — the two hooks are
        // separate by design.
        App::authChecker(fn(): bool => true);
        App::adminChecker(fn(): bool => false);
        $this->assertTrue($this->api->isAuthenticated());
        $this->assertFalse($this->api->isAdmin());
    }

    // ──────────────────────────────────────────────────────────────
    // getUsername()
    // ──────────────────────────────────────────────────────────────

    public function testGetUsernameDefaultsToNull(): void
    {
        $this->assertNull($this->api->getUsername());
    }

    public function testGetUsernameRoutesThroughProvider(): void
    {
        App::usernameProvider(fn(): string => 'alice');
        $this->assertSame('alice', $this->api->getUsername());
    }

    public function testGetUsernameCoercesNonStringToNull(): void
    {
        // Providers that return false/null (anonymous user) should surface
        // as null rather than the literal value.
        /** @phpstan-ignore-next-line argument.type — verifying runtime coercion */
        App::usernameProvider(fn() => null);
        $this->assertNull($this->api->getUsername());

        /** @phpstan-ignore-next-line argument.type — verifying runtime coercion */
        App::usernameProvider(fn() => false);
        $this->assertNull($this->api->getUsername());
    }

    public function testGetUsernameProviderReturningEmptyStringPassesThrough(): void
    {
        // Empty string is still a string — pass through rather than coerce
        // to null, since the framework can't tell whether the app means
        // "no name yet" or "the user really did name themselves ''".
        App::usernameProvider(fn(): string => '');
        $this->assertSame('', $this->api->getUsername());
    }

    // ──────────────────────────────────────────────────────────────
    // setter round-trips
    // ──────────────────────────────────────────────────────────────

    public function testAuthCheckerSetterReturnsCurrentCallable(): void
    {
        $checker = fn(): bool => true;
        $this->assertSame($checker, App::authChecker($checker));
        $this->assertSame($checker, App::authChecker());
    }

    public function testAdminCheckerSetterReturnsCurrentCallable(): void
    {
        $checker = fn(): bool => true;
        $this->assertSame($checker, App::adminChecker($checker));
        $this->assertSame($checker, App::adminChecker());
    }

    public function testUsernameProviderSetterReturnsCurrentCallable(): void
    {
        $provider = fn(): string => 'alice';
        $this->assertSame($provider, App::usernameProvider($provider));
        $this->assertSame($provider, App::usernameProvider());
    }
}
