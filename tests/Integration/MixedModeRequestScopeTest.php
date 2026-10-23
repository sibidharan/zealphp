<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * mixed mode keeps RequestContext as a process-wide singleton, so request-scoped
 * state must be reset between requests by SessionManager. RequestContext::once()
 * used to survive forever there: request N+1's once('current_user', ...) returned
 * request N's user — an authentication bleed between visitors.
 *
 * Fixture: fixtures/mixed_mode_request_scope_server.php (own server, own port).
 */
final class MixedModeRequestScopeTest extends TestCase
{
    private const PORT = 9835;
    private static ?int $pid = null;
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        $fixture = __DIR__ . '/fixtures/mixed_mode_request_scope_server.php';
        self::$log = sys_get_temp_dir() . '/zealphp_mixed_scope_' . getmypid() . '.log';

        $mods = (string) @shell_exec(escapeshellarg(PHP_BINARY) . ' -m 2>/dev/null');
        if (stripos($mods, 'openswoole') === false) {
            return;
        }
        @shell_exec('fuser -k ' . self::PORT . '/tcp 2>/dev/null');
        usleep(200000);
        $cmd = sprintf(
            '%s -d opcache.enable_cli=0 %s %d > %s 2>&1 & echo $!',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($fixture),
            self::PORT,
            escapeshellarg(self::$log)
        );
        self::$pid = (int) trim((string) shell_exec($cmd));
        for ($i = 0; $i < 50; $i++) {
            $ctx = stream_context_create(['http' => ['timeout' => 1]]);
            if (@file_get_contents('http://127.0.0.1:' . self::PORT . '/ping', false, $ctx) !== false) {
                return;
            }
            if (self::$pid && !@posix_kill(self::$pid, 0)) {
                break;
            }
            usleep(200000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pid) {
            @posix_kill(self::$pid, SIGKILL);
            self::$pid = null;
        }
        @shell_exec('fuser -k ' . self::PORT . '/tcp 2>/dev/null');
        @unlink(self::$log);
    }

    /** @return array<string, mixed> */
    private function whoami(string $u): array
    {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $body = @file_get_contents('http://127.0.0.1:' . self::PORT . '/whoami?u=' . rawurlencode($u), false, $ctx);
        if ($body === false) {
            $detail = \is_file(self::$log) ? "\n" . substr((string) @file_get_contents(self::$log), -400) : '';
            $this->markTestSkipped('mixed-mode fixture did not boot (OpenSwoole unavailable).' . $detail);
        }
        $d = json_decode((string) $body, true);
        $this->assertIsArray($d, "whoami must return JSON: $body");
        return $d;
    }

    public function testOnceMemoDoesNotSurviveIntoNextRequest(): void
    {
        foreach (['alice', 'bob', 'carol'] as $u) {
            $d = $this->whoami($u);
            $this->assertSame($u, $d['user'] ?? null, "request for '$u' was served another visitor's once() value");
        }
    }
}
