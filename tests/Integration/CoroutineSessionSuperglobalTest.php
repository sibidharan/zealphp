<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Coroutine mode: $_SESSION is one process-wide array, not the request's
 * session. Data a request left in it used to be visible to the NEXT request on
 * the worker — another user's session data. The manager now clears it at the
 * start and end of every request.
 *
 * Fixture: fixtures/coroutine_session_superglobal_server.php (one worker).
 */
final class CoroutineSessionSuperglobalTest extends TestCase
{
    private const PORT = 9891;
    private static int $pid = 0;

    public static function setUpBeforeClass(): void
    {
        $mods = (string) @shell_exec(escapeshellarg(PHP_BINARY) . ' -m 2>/dev/null');
        if (stripos($mods, 'openswoole') === false) {
            return;
        }
        @shell_exec('fuser -k ' . self::PORT . '/tcp 2>/dev/null');
        usleep(200000);
        $cmd = sprintf('%s -d opcache.enable_cli=0 %s %d > /dev/null 2>&1 & echo $!',
            escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/fixtures/coroutine_session_superglobal_server.php'), self::PORT);
        self::$pid = (int) trim((string) shell_exec($cmd));
        for ($i = 0; $i < 50; $i++) {
            if (@file_get_contents('http://127.0.0.1:' . self::PORT . '/ping', false, stream_context_create(['http' => ['timeout' => 1]])) !== false) {
                break;
            }
            usleep(200000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pid > 0) {
            @posix_kill(self::$pid, SIGKILL);
        }
        @shell_exec('fuser -k ' . self::PORT . '/tcp 2>/dev/null');
    }

    /** @return array<string, mixed> */
    private function call(string $path): array
    {
        $body = @file_get_contents('http://127.0.0.1:' . self::PORT . $path, false, stream_context_create(['http' => ['timeout' => 5]]));
        if ($body === false) {
            $this->markTestSkipped('fixture did not boot');
        }
        $d = json_decode($body, true);
        return is_array($d) ? $d : [];
    }

    public function testOneUsersSessionSuperglobalDoesNotReachTheNextRequest(): void
    {
        $this->call('/write?v=alice-secret');
        $next = $this->call('/read');
        $this->assertNull($next['sg'], 'the next request (another user) saw $_SESSION left by the previous one');
    }
}
