<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * - A WebSocket upgrade carrying a session id the store never issued must not
 *   load it, nor write it: the handshake used to create sess_<id>, after which
 *   HTTP strict mode accepted the attacker-chosen id (session fixation).
 * - Engine warnings must not reach the response body in production mode, even
 *   when display_errors is on (the CLI default).
 *
 * Fixture: fixtures/ws_session_strict_server.php (own server, own port).
 */
final class WebSocketSessionStrictModeTest extends TestCase
{
    private const PORT = 9890;
    private const SID = '0123456789abcdef0123456789abcdef';

    private static int $pid = 0;
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        $mods = (string) @shell_exec(escapeshellarg(PHP_BINARY) . ' -m 2>/dev/null');
        if (stripos($mods, 'openswoole') === false) {
            return;
        }
        self::$dir = sys_get_temp_dir() . '/zp_ws_strict_' . getmypid();
        @mkdir(self::$dir);
        @shell_exec('fuser -k ' . self::PORT . '/tcp 2>/dev/null');
        usleep(200000);
        $cmd = sprintf('env -u ZEALPHP_DEV %s -d opcache.enable_cli=0 -d display_errors=1 %s %d %s > /dev/null 2>&1 & echo $!',
            escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/fixtures/ws_session_strict_server.php'), self::PORT, escapeshellarg(self::$dir));
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
        foreach (glob(self::$dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(self::$dir);
    }

    private function upgrade(string $cookie): string
    {
        $s = @stream_socket_client('tcp://127.0.0.1:' . self::PORT, $errno, $errstr, 3);
        if ($s === false) {
            $this->markTestSkipped('fixture did not boot');
        }
        fwrite($s, "GET /ws HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\n"
            . "Cookie: {$cookie}\r\n\r\n");
        stream_set_timeout($s, 3);
        $status = (string) fgets($s);
        usleep(300000);
        fclose($s);
        usleep(200000);
        return $status;
    }

    public function testUpgradeWithAnUnissuedIdDoesNotCreateIt(): void
    {
        $this->assertStringContainsString('101', $this->upgrade('PHPSESSID=' . self::SID));
        $this->assertFileDoesNotExist(self::$dir . '/sess_' . self::SID, 'the handshake minted a store entry for a client-chosen id');

        $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => 'Cookie: PHPSESSID=' . self::SID . "\r\n"]]);
        $body = (string) @file_get_contents('http://127.0.0.1:' . self::PORT . '/whoami', false, $ctx);
        $this->assertNotSame(self::SID, json_decode($body, true)['sid'] ?? null, 'HTTP strict mode must still rotate the fixated id');
    }

    public function testUpgradeWithAnIssuedIdStillLoadsIt(): void
    {
        $body = (string) @file_get_contents('http://127.0.0.1:' . self::PORT . '/whoami');
        $sid = json_decode($body, true)['sid'] ?? '';
        $this->assertIsString($sid);
        $this->assertNotSame('', $sid);
        $this->assertStringContainsString('101', $this->upgrade('PHPSESSID=' . $sid));
        $this->assertFileExists(self::$dir . '/sess_' . $sid);
    }

    public function testEngineWarningsStayOutOfTheResponse(): void
    {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $body = (string) @file_get_contents('http://127.0.0.1:' . self::PORT . '/warn', false, $ctx);
        if ($body === '') {
            $this->markTestSkipped('fixture did not boot');
        }
        $this->assertStringNotContainsString('undefined_probe_key', $body);
        $this->assertStringNotContainsString('Warning', $body);
    }
}
