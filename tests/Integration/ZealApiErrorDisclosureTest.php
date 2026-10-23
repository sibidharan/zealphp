<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * A ZealAPI handler that throws used to send the exception message AND the
 * full stack trace to the client, whatever App::displayErrors() said. A
 * library exception's message carries internals (a PDOException names the DB
 * user and host). In production only the API's own \Exception text goes out.
 *
 * Fixture: fixtures/zealapi_errors_server.php (own server, own port, ZEALPHP_DEV unset).
 */
final class ZealApiErrorDisclosureTest extends TestCase
{
    private const PORT = 9840;
    private static int $pid = 0;

    public static function setUpBeforeClass(): void
    {
        $mods = (string) @shell_exec(escapeshellarg(PHP_BINARY) . ' -m 2>/dev/null');
        if (stripos($mods, 'openswoole') === false) {
            return;
        }
        @shell_exec('fuser -k ' . self::PORT . '/tcp 2>/dev/null');
        usleep(200000);
        $cmd = sprintf('env -u ZEALPHP_DEV %s -d opcache.enable_cli=0 %s %d > /dev/null 2>&1 & echo $!',
            escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/fixtures/zealapi_errors_server.php'), self::PORT);
        self::$pid = (int) trim((string) shell_exec($cmd));
        for ($i = 0; $i < 50; $i++) {
            $ctx = stream_context_create(['http' => ['timeout' => 1]]);
            if (@file_get_contents('http://127.0.0.1:' . self::PORT . '/ping', false, $ctx) !== false) {
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

    private function fetchApi(string $path): string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $body = @file_get_contents('http://127.0.0.1:' . self::PORT . $path, false, $ctx);
        if ($body === false) {
            $this->markTestSkipped('zealapi_errors fixture did not boot (OpenSwoole unavailable).');
        }
        return $body;
    }

    public function testLibraryExceptionDetailsAndTraceStayInTheLog(): void
    {
        $body = $this->fetchApi('/api/boom/library');
        $this->assertStringNotContainsString('secret_db_user', $body);
        $this->assertStringNotContainsString('"stack"', $body);
        $this->assertStringNotContainsString('library.php', $body);
        $this->assertSame('Internal error', json_decode($body, true)['error'] ?? null);
    }

    public function testApiOwnErrorMessageStillReachesTheClient(): void
    {
        $body = $this->fetchApi('/api/boom/plain');
        $this->assertSame('Invalid input', json_decode($body, true)['error'] ?? null);
        $this->assertStringNotContainsString('"stack"', $body);
    }
}
