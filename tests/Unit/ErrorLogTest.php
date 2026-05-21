<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;

/**
 * Unit tests for the mod_php-parity error_log() override (src/utils.php).
 */
class ErrorLogTest extends TestCase
{
    public function testMessageType3AppendsToDestinationFile(): void
    {
        $file = sys_get_temp_dir() . '/zealphp_errorlog_' . uniqid() . '.log';
        try {
            $this->assertTrue(\ZealPHP\error_log("first\n", 3, $file));
            $this->assertTrue(\ZealPHP\error_log("second\n", 3, $file));
            $contents = (string) file_get_contents($file);
            $this->assertSame("first\nsecond\n", $contents);
        } finally {
            @unlink($file);
        }
    }

    public function testSystemLoggerTypeReturnsTrue(): void
    {
        // type 0 routes into the framework log (log_write) and reports success.
        $this->assertTrue(\ZealPHP\error_log('routed to framework log'));
    }

    public function testEmailTypeReturnsFalse(): void
    {
        // type 1 (email) can't be delivered under the coroutine runtime.
        $this->assertFalse(\ZealPHP\error_log('would-be email', 1));
    }
}
