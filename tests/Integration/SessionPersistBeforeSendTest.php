<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use ZealPHP\Tests\TestCase;

/**
 * The session must be in the store before the response that changed it
 * reaches the client. It used to be written in the session manager's
 * request-end `finally`, after end(), so a client that followed up at once (a
 * redirect after login or logout, an OAuth state set before the provider
 * bounce) could read the old session: a logout not yet in effect, a lost
 * OAuth/CSRF state.
 *
 * Fixture: fixtures/session_persist_before_send_server.php (own servers, own ports).
 */
final class SessionPersistBeforeSendTest extends TestCase
{
    private const SERVERS = ['coroutine' => 9838, 'mixed' => 9839];

    /** @var array<string, int> */
    private static array $pids = [];
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        $mods = (string) @shell_exec(escapeshellarg(PHP_BINARY) . ' -m 2>/dev/null');
        if (stripos($mods, 'openswoole') === false) {
            return;
        }
        self::$log = sys_get_temp_dir() . '/zealphp_sess_persist_' . getmypid() . '.log';
        $fixture = __DIR__ . '/fixtures/session_persist_before_send_server.php';
        foreach (self::SERVERS as $mode => $port) {
            @shell_exec('fuser -k ' . $port . '/tcp 2>/dev/null');
            usleep(200000);
            $cmd = sprintf('%s -d opcache.enable_cli=0 %s %d %s >> %s 2>&1 & echo $!',
                escapeshellarg(PHP_BINARY), escapeshellarg($fixture), $port, $mode, escapeshellarg(self::$log));
            self::$pids[$mode] = (int) trim((string) shell_exec($cmd));
        }
        foreach (self::SERVERS as $port) {
            for ($i = 0; $i < 50; $i++) {
                $ctx = stream_context_create(['http' => ['timeout' => 1]]);
                if (@file_get_contents("http://127.0.0.1:$port/ping", false, $ctx) !== false) {
                    break;
                }
                usleep(200000);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$pids as $pid) {
            @posix_kill($pid, SIGKILL);
        }
        foreach (self::SERVERS as $port) {
            @shell_exec('fuser -k ' . $port . '/tcp 2>/dev/null');
        }
        self::$pids = [];
        if (self::$log !== '') {
            @unlink(self::$log);
        }
    }

    /**
     * @return array{body: string, cookie: ?string}|null
     */
    private function fetch(int $port, string $path, ?string $cookie = null): ?array
    {
        $ctx = stream_context_create(['http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'header' => $cookie !== null ? "Cookie: $cookie\r\n" : '',
        ]]);
        $body = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
        if ($body === false) {
            return null;
        }
        $set = null;
        foreach ($http_response_header as $h) {
            if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $h, $m) === 1) {
                $set = $m[1];
            }
        }
        return ['body' => $body, 'cookie' => $set];
    }

    /** @return array<string, mixed> */
    private function session(int $port, string $cookie): array
    {
        $r = $this->fetch($port, '/get', $cookie);
        $this->assertNotNull($r);
        $d = json_decode($r['body'], true);
        return is_array($d) ? $d : [];
    }

    /** @return array<string, array{0: string}> */
    public static function modes(): array
    {
        return ['coroutine (CoSessionManager)' => ['coroutine'], 'mixed (SessionManager)' => ['mixed']];
    }

    #[DataProvider('modes')]
    public function testSessionIsStoredBeforeTheResponseArrives(string $mode): void
    {
        $port = self::SERVERS[$mode];
        $first = $this->fetch($port, '/set?v=one');
        if ($first === null) {
            $this->markTestSkipped("$mode fixture did not boot (OpenSwoole unavailable).");
        }
        $cookie = $first['cookie'];
        $this->assertNotNull($cookie, 'the first request must start a session');
        usleep(800000);
        $this->assertSame('one', $this->session($port, $cookie)['v'] ?? null);

        // The follow-up is sent while /set is still lingering after end().
        $this->assertSame('set', $this->fetch($port, '/set?v=two', $cookie)['body'] ?? null);
        $this->assertSame('two', $this->session($port, $cookie)['v'] ?? null,
            "$mode: the next request read the session before the previous response's write landed");
    }

    #[DataProvider('modes')]
    public function testBodylessStatusAlsoStoresTheSessionFirst(string $mode): void
    {
        $port = self::SERVERS[$mode];
        $first = $this->fetch($port, '/set?v=one');
        if ($first === null) {
            $this->markTestSkipped("$mode fixture did not boot (OpenSwoole unavailable).");
        }
        $cookie = (string) $first['cookie'];
        usleep(1500000);
        $this->fetch($port, '/set204?v=gone', $cookie);
        $this->assertSame('gone', $this->session($port, $cookie)['v'] ?? null,
            "$mode: a 204 reached the client before its session write");
    }

    #[DataProvider('modes')]
    public function testChangesAfterTheResponseAreStillStored(string $mode): void
    {
        $port = self::SERVERS[$mode];
        $first = $this->fetch($port, '/set?v=one');
        if ($first === null) {
            $this->markTestSkipped("$mode fixture did not boot (OpenSwoole unavailable).");
        }
        $cookie = (string) $first['cookie'];
        usleep(800000);
        $this->assertSame('after', $this->fetch($port, '/after', $cookie)['body'] ?? null);
        usleep(1500000);   // the late write goes through the fixture's 500 ms store
        $s = $this->session($port, $cookie);
        $this->assertSame('yes', $s['late'] ?? null, "$mode: a change made after end() was not stored");
        $this->assertArrayNotHasKey('tmp', $s, "$mode: a key unset after end() came back from the early save");
        $this->assertSame('one', $s['v'] ?? null);
    }
}
