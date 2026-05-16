<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

use function ZealPHP\Session\zeal_session_decode;
use function ZealPHP\Session\zeal_session_start;

/**
 * Regression tests for the v0.2.12 fix: corrupted session files must not
 * crash the worker with TypeError on assignment to the typed
 * `RequestContext::$session` array property.
 *
 * Pre-fix symptom (caught in production): empty / truncated / corrupted
 * session files made `unserialize()` return false, which was then assigned
 * directly to `$g->session` (typed `array`). PHP 8 raises TypeError, the
 * worker abnormal-exits, and every request that touches an affected
 * session ID 500s until the worker recycles.
 *
 * Fix: defensive read+decode at every site that loads session data from
 * disk or user input. Empty/corrupted/non-array results reduce to `[]`,
 * not a fatal.
 */
class SessionFileCorruptionTest extends TestCase
{
    private string $sessionDir;
    private string $sessionId;

    protected function setUp(): void
    {
        // Use a per-test temp directory so we control the session file
        // contents without colliding with the real /var/lib/php/sessions.
        $this->sessionDir = sys_get_temp_dir() . '/zealphp_test_sessions_' . uniqid();
        @mkdir($this->sessionDir, 0700, true);

        // Force a deterministic session id so we know which file to write.
        $this->sessionId = 'corrupt_test_' . bin2hex(random_bytes(8));

        $g = RequestContext::instance();
        $g->session_params = [
            'name'     => 'PHPSESSID',
            'save_path' => $this->sessionDir,
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        $g->cookie  = ['PHPSESSID' => $this->sessionId];
        $g->session = [];
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup of the temp session directory.
        if (is_dir($this->sessionDir)) {
            foreach (glob($this->sessionDir . '/sess_*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->sessionDir);
        }
    }

    private function sessionFile(): string
    {
        return $this->sessionDir . '/sess_' . $this->sessionId;
    }

    public function testEmptyFileDoesNotCrashSessionStart(): void
    {
        file_put_contents($this->sessionFile(), '');

        $this->assertTrue(zeal_session_start(), 'session_start must succeed on empty file');
        $this->assertSame([], RequestContext::instance()->session, 'empty file → empty session');
    }

    public function testCorruptedFileDoesNotCrashSessionStart(): void
    {
        // Truncated serialize header — unserialize() returns false.
        file_put_contents($this->sessionFile(), 'a:1:{s:4:"user"');

        $this->assertTrue(zeal_session_start());
        $this->assertSame([], RequestContext::instance()->session, 'corrupted file → empty session, no fatal');
    }

    public function testGarbageFileDoesNotCrashSessionStart(): void
    {
        // Random bytes — definitely not a valid serialize stream.
        file_put_contents($this->sessionFile(), bin2hex(random_bytes(64)));

        $this->assertTrue(zeal_session_start());
        $this->assertSame([], RequestContext::instance()->session);
    }

    public function testValidSerializedNonArrayBecomesEmptySession(): void
    {
        // `unserialize()` succeeds but produces a string. Pre-fix this would
        // be assigned to the typed array property → TypeError.
        file_put_contents($this->sessionFile(), serialize('not-an-array'));

        $this->assertTrue(zeal_session_start());
        $this->assertSame([], RequestContext::instance()->session);
    }

    public function testValidSessionFileLoadsCorrectly(): void
    {
        $expected = ['user_id' => 42, 'role' => 'admin', 'login_at' => 1234567890];
        file_put_contents($this->sessionFile(), serialize($expected));

        $this->assertTrue(zeal_session_start());
        $this->assertSame($expected, RequestContext::instance()->session);
    }

    public function testMissingFileLeavesSessionEmpty(): void
    {
        // Don't write any file — zeal_session_start should still succeed.
        $this->assertTrue(zeal_session_start());
        $this->assertSame([], RequestContext::instance()->session);
    }

    // ─── zeal_session_decode (user-controlled input) ───────────────

    public function testSessionDecodeRejectsEmptyString(): void
    {
        $this->assertFalse(zeal_session_decode(''));
    }

    public function testSessionDecodeRejectsCorruptedInput(): void
    {
        $this->assertFalse(zeal_session_decode('a:1:{s:4:"user"'));
    }

    public function testSessionDecodeRejectsGarbageInput(): void
    {
        $this->assertFalse(zeal_session_decode(bin2hex(random_bytes(64))));
    }

    public function testSessionDecodeRejectsValidSerializedNonArray(): void
    {
        // Pre-fix this would TypeError on assignment.
        $this->assertFalse(zeal_session_decode(serialize('not-an-array')));
        $this->assertFalse(zeal_session_decode(serialize(42)));
        $this->assertFalse(zeal_session_decode(serialize(null)));
    }

    public function testSessionDecodeAcceptsValidArray(): void
    {
        $payload = ['k' => 'v', 'n' => 7];
        $this->assertTrue(zeal_session_decode(serialize($payload)));
        $this->assertSame($payload, RequestContext::instance()->session);
    }
}
