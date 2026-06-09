<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

use function ZealPHP\Session\zeal_session_strict_should_regenerate;
use function ZealPHP\Session\zeal_valid_session_id;

/**
 * Covers the #244 session-fixation fix: `App::$session_strict_mode` + the shared
 * `zeal_session_strict_should_regenerate()` decision that both CoSessionManager
 * (coroutine) and SessionManager (superglobals) consult.
 *
 * The bug: `zeal_valid_session_id()` validates only the FORMAT of a
 * client-supplied PHPSESSID, so a well-formed but server-never-issued id (a
 * planted/fixated id) is accepted verbatim. PHP's `session.use_strict_mode=1`
 * rotates any id that loads an empty (unrecognised) session. This suite pins
 * the rotation decision at the seam the managers reach (the pure helper), plus
 * the fluent setter — driving a full OpenSwoole request through the managers is
 * integration territory and needs the uopz session_* overrides (not loaded in
 * the unit bootstrap).
 */
final class SessionStrictModeTest extends TestCase
{
    private bool $origStrict;

    protected function setUp(): void
    {
        $this->origStrict = App::$session_strict_mode;
    }

    protected function tearDown(): void
    {
        App::$session_strict_mode = $this->origStrict;
    }

    // --- the helper: provenance decision ------------------------------------

    /**
     * The fixation case: a client-supplied id that loaded an EMPTY session is
     * unrecognised → must be rotated to a fresh server id.
     */
    public function testClientIdWithEmptySessionRegenerates(): void
    {
        $this->assertTrue(
            zeal_session_strict_should_regenerate(true, true, []),
            'strict + client-supplied + empty store must rotate the id (fixation defence)'
        );
    }

    /**
     * A client-supplied id that resolved to a NON-EMPTY stored session is a
     * legitimate returning visitor → preserve it, no rotation.
     */
    public function testClientIdWithExistingSessionPreserved(): void
    {
        $this->assertFalse(
            zeal_session_strict_should_regenerate(true, true, ['user_id' => 7]),
            'a recognised (non-empty) session must keep its id'
        );
    }

    /**
     * A server-MINTED id (not client-supplied) is never a planted value, so even
     * an empty session must not be rotated — that is just a brand-new session.
     */
    public function testServerMintedIdNeverRegenerates(): void
    {
        $this->assertFalse(
            zeal_session_strict_should_regenerate(true, false, []),
            'a server-generated id is not attacker-controlled — no rotation'
        );
    }

    /**
     * `App::sessionStrictMode(false)` disables the rotation entirely: even a
     * client id with an empty session is accepted verbatim (legacy behaviour /
     * multi-node opt-out).
     */
    public function testStrictModeOffDisablesRotation(): void
    {
        $this->assertFalse(
            zeal_session_strict_should_regenerate(false, true, []),
            'strict mode off → client id accepted verbatim'
        );
    }

    // --- store-entry existence beats data-emptiness (ext-zealphp#2) ----------

    /**
     * PHP's session.use_strict_mode rejects ids the server NEVER ISSUED — an
     * issued-but-still-empty session (cookie sent on a data-less first visit,
     * a redirect, any page that stores nothing) is a KNOWN id and must NOT
     * rotate. The old data-emptiness heuristic rotated those every request:
     * combined with the regenerate→write_close sid desync it produced the
     * rotate-and-lose-everything cascade on the rig (ext-zealphp#2).
     */
    public function testKnownStoreEntryWithEmptyDataDoesNotRegenerate(): void
    {
        $this->assertFalse(
            zeal_session_strict_should_regenerate(true, true, [], storeEntryExists: true),
            'an issued-but-empty session is a known id — no rotation'
        );
    }

    public function testMissingStoreEntryRegenerates(): void
    {
        $this->assertTrue(
            zeal_session_strict_should_regenerate(true, true, ['stale' => 1], storeEntryExists: false),
            'no backing store entry = never-issued/foreign id → rotate, regardless of in-memory data'
        );
    }

    public function testNullExistenceFallsBackToDataEmptinessHeuristic(): void
    {
        // BC: callers that cannot determine store existence keep the old
        // data-emptiness behaviour.
        $this->assertTrue(zeal_session_strict_should_regenerate(true, true, [], storeEntryExists: null));
        $this->assertFalse(zeal_session_strict_should_regenerate(true, true, ['u' => 1], storeEntryExists: null));
    }

    /**
     * The empty check is exact: a session holding only a falsy-keyed value is
     * still non-empty and must be preserved.
     */
    public function testNonEmptySessionWithFalsyValuePreserved(): void
    {
        $this->assertFalse(
            zeal_session_strict_should_regenerate(true, true, ['flag' => false]),
            'any populated key means the id is recognised'
        );
    }

    // --- the rotated id is a usable, valid session id -----------------------

    /**
     * When the helper says "rotate", the managers mint via session_create_id().
     * Assert that a freshly minted id differs from a planted one AND passes the
     * format validator — so the cookie the client receives is a clean server id.
     */
    public function testFreshIdDiffersFromPlantedAndIsValid(): void
    {
        $planted = 'attacker_fixed_session_0123456789abcdef';
        // The planted id is well-formed (this is the whole bug — format alone
        // accepts it), so the manager only rejects it via the empty-session
        // provenance check, then mints a replacement.
        $this->assertTrue(zeal_valid_session_id($planted));
        $this->assertTrue(
            zeal_session_strict_should_regenerate(true, true, []),
            'planted id with empty store triggers rotation'
        );

        $fresh = session_create_id();
        $this->assertIsString($fresh);
        $this->assertNotSame($planted, $fresh, 'rotation must yield a different id');
        $this->assertTrue(
            zeal_valid_session_id($fresh),
            'the server-minted replacement must itself be a valid session id'
        );
    }

    // --- the fluent setter --------------------------------------------------

    public function testSetterDefaultsToTrue(): void
    {
        // Security-first default: strict mode is ON unless explicitly disabled.
        App::$session_strict_mode = true;
        $this->assertTrue(App::sessionStrictMode(), 'no-arg call reads the current value');
    }

    public function testSetterRoundTrips(): void
    {
        $this->assertFalse(App::sessionStrictMode(false), 'setter returns the new value');
        $this->assertFalse(App::$session_strict_mode, 'backing property updated');
        $this->assertFalse(App::sessionStrictMode(), 'read-back reflects the set value');

        $this->assertTrue(App::sessionStrictMode(true));
        $this->assertTrue(App::$session_strict_mode);
        $this->assertTrue(App::sessionStrictMode());
    }

    public function testSetterNullArgDoesNotMutate(): void
    {
        App::$session_strict_mode = false;
        $this->assertFalse(App::sessionStrictMode(null), 'null arg is a pure read — no mutation');
        $this->assertFalse(App::$session_strict_mode);
    }
}
