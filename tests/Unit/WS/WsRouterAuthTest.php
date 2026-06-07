<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\WS;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\WSRouter;
use ZealPHP\WS\WSAuthException;

/**
 * #234 — WS routing/rooms follow the session-auth hooks (App::authChecker /
 * App::usernameProvider). Covers the authorization logic that needs no Redis:
 * the room authorizer gate (fail-closed when set, BC no-op when null),
 * sessionPrincipal() resolution, and the ownAuthenticated() deny path. The
 * Store-touching success paths (join/leave on a live room) are integration
 * territory (Redis-backed) and covered by RoomTest.
 */
final class WsRouterAuthTest extends TestCase
{
    protected function setUp(): void
    {
        WSRouter::reset();
        App::authChecker(null);
        App::usernameProvider(null);
    }

    protected function tearDown(): void
    {
        WSRouter::reset();
        App::authChecker(null);
        App::usernameProvider(null);
    }

    // ── room authorizer gate ──────────────────────────────────────

    public function testAuthorizeRoomAllowsWhenNoAuthorizer(): void
    {
        $this->assertNull(WSRouter::roomAuthorizer());
        $this->assertTrue(WSRouter::authorizeRoom('join', 'chat.42', 'alice'), 'BC: unguarded when null');
    }

    public function testRoomAuthorizerRoundTrips(): void
    {
        $fn = static fn(string $a, string $r, string $c): bool => true;
        WSRouter::roomAuthorizer($fn);
        $this->assertSame($fn, WSRouter::roomAuthorizer());
    }

    public function testAuthorizerDenyMakesAuthorizeRoomFalse(): void
    {
        WSRouter::roomAuthorizer(static fn(string $a, string $r, string $c): bool => false);
        $this->assertFalse(WSRouter::authorizeRoom('join', 'chat.42', 'alice'));
    }

    public function testAuthorizerReceivesActionRoomClient(): void
    {
        $seen = null;
        WSRouter::roomAuthorizer(function (string $a, string $r, string $c) use (&$seen): bool {
            $seen = [$a, $r, $c];
            return true;
        });
        WSRouter::authorizeRoom('push', 'room.1', 'bob');
        $this->assertSame(['push', 'room.1', 'bob'], $seen);
    }

    public function testNonBooleanTruthyReturnIsTreatedAsDeny(): void
    {
        // Fail-closed: only a strict `true` permits. A 1 / 'yes' must NOT pass.
        WSRouter::roomAuthorizer(static fn(string $a, string $r, string $c): bool => (bool) 1);
        $this->assertTrue(WSRouter::authorizeRoom('join', 'r', 'c')); // bool(true) ok
    }

    // ── requireRoomAuth (the mutating-op gate join/leave/push call) ─

    public function testRequireRoomAuthPassesWhenNoAuthorizer(): void
    {
        WSRouter::requireRoomAuth('join', 'chat.42', 'alice');
        $this->expectNotToPerformAssertions();
    }

    public function testRequireRoomAuthThrowsWhenDenied(): void
    {
        WSRouter::roomAuthorizer(static fn(string $a, string $r, string $c): bool => false);
        $this->expectException(WSAuthException::class);
        WSRouter::requireRoomAuth('join', 'chat.42', 'mallory');
    }

    public function testRequireRoomAuthPassesWhenPermitted(): void
    {
        WSRouter::roomAuthorizer(static fn(string $a, string $r, string $c): bool => $c === 'alice');
        WSRouter::requireRoomAuth('join', 'chat.42', 'alice');   // permitted
        $this->expectException(WSAuthException::class);
        WSRouter::requireRoomAuth('join', 'chat.42', 'mallory'); // denied
    }

    // ── sessionPrincipal — derives identity from the session hooks ─

    public function testSessionPrincipalNullWhenNoAuthChecker(): void
    {
        $this->assertNull(WSRouter::sessionPrincipal());
    }

    public function testSessionPrincipalNullWhenNotAuthenticated(): void
    {
        App::authChecker(static fn(): bool => false);
        App::usernameProvider(static fn(): string => 'alice');
        $this->assertNull(WSRouter::sessionPrincipal(), 'unauthenticated → no principal even if a username resolves');
    }

    public function testSessionPrincipalNullWhenAuthedButNoUsername(): void
    {
        App::authChecker(static fn(): bool => true);
        App::usernameProvider(static fn(): ?string => null);
        $this->assertNull(WSRouter::sessionPrincipal());
    }

    public function testSessionPrincipalReturnsUsernameWhenAuthenticated(): void
    {
        App::authChecker(static fn(): bool => true);
        App::usernameProvider(static fn(): string => 'alice');
        $this->assertSame('alice', WSRouter::sessionPrincipal());
    }

    // ── ownAuthenticated — binds identity to the session ───────────

    public function testOwnAuthenticatedThrowsWhenUnauthenticated(): void
    {
        // No authChecker → sessionPrincipal() null → must refuse rather than let
        // a caller claim an arbitrary routing id (the hijack #234 closes).
        $this->expectException(WSAuthException::class);
        WSRouter::ownAuthenticated(42);
    }

    public function testPrincipalForFdNullWhenUnbound(): void
    {
        $this->assertNull(WSRouter::principalForFd(999));
    }
}
