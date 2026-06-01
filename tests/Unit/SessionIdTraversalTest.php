<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\Session\Handler\FileSessionHandler;
use ZealPHP\Tests\TestCase;
use function ZealPHP\Session\zeal_valid_session_id;

/**
 * Security regression: an attacker-chosen PHPSESSID must never escape the
 * session save directory (arbitrary file read/write/delete via path traversal).
 * Pins the boundary validator + the basename() sink hardening.
 */
class SessionIdTraversalTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function maliciousIds(): array
    {
        return [
            'parent traversal' => ['../../etc/passwd'],
            'forward slash'    => ['a/b'],
            'back slash'       => ['a\\b'],
            'nul byte'         => ["a\0b"],
            'bare dotdot'      => ['..'],
            'embedded dotdot'  => ['foo/../bar'],
            'empty'            => [''],
            'oversized'        => [/* 257 chars */ '0000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000'],
        ];
    }

    /**
     * @dataProvider maliciousIds
     */
    public function testValidatorRejectsTraversalAndSeparators(string $id): void
    {
        $this->assertFalse(zeal_valid_session_id($id), "malicious id must be rejected: " . bin2hex($id));
    }

    public function testValidatorAcceptsLegitimateIds(): void
    {
        $this->assertTrue(zeal_valid_session_id('abc123'));
        $this->assertTrue(zeal_valid_session_id('sess_test_id'));      // underscore (legacy/synthetic)
        $this->assertTrue(zeal_valid_session_id('aZ09,-'));            // PHP sid alphabet
        $this->assertTrue(zeal_valid_session_id(session_create_id())); // a real PHP id
    }

    public function testFileHandlerCannotEscapeSaveDirectoryWithTraversalId(): void
    {
        $base = sys_get_temp_dir() . '/zealphp_sid_traversal_' . bin2hex(random_bytes(4));
        $save = $base . '/sessions';
        @mkdir($save, 0700, true);
        $escapeTarget = $base . '/escape';   // one directory ABOVE the save dir
        @unlink($escapeTarget);

        $h = new FileSessionHandler();
        $h->open($save, 'PHPSESSID');
        // basename('../escape') === 'escape' → writes $save/sess_escape, never $base/escape.
        $h->write('../escape', 'pwned');

        $this->assertFileDoesNotExist($escapeTarget, 'session write must not escape the save directory');
        $this->assertFileExists($save . '/sess_escape', 'write must be contained as sess_escape inside savePath');

        @unlink($save . '/sess_escape');
        @rmdir($save);
        @rmdir($base);
    }
}
