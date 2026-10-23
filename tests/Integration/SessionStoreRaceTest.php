<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use ZealPHP\Tests\TestCase;

/**
 * A request still running when another request destroys the session, or
 * removes a key from it, must not write its old copy back when it closes. The
 * close used to recreate the store entry (file `c+`, Table set) and merge its
 * load-time snapshot over the store, so a logout done while any other request
 * of that user was in flight was undone a moment later, and an unset key came
 * back.
 *
 * Fixture: fixtures/session_store_race_server.php (own servers, own ports).
 */
final class SessionStoreRaceTest extends TestCase
{
    /** @var array<string, array{0: int, 1: string, 2: string}> name => [port, mode, handler] */
    private const SERVERS = [
        'coroutine-inline' => [9872, 'coroutine', 'inline'],
        'mixed-inline'     => [9873, 'mixed', 'inline'],
        'coroutine-table'  => [9874, 'coroutine', 'table'],
        'coroutine-file'   => [9875, 'coroutine', 'file'],
    ];

    /** @var array<string, int> */
    private static array $pids = [];
    private static string $log = '';
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        $mods = (string) @shell_exec(escapeshellarg(PHP_BINARY) . ' -m 2>/dev/null');
        if (stripos($mods, 'openswoole') === false) {
            return;
        }
        self::$dir = sys_get_temp_dir() . '/zealphp_sess_race_' . getmypid();
        @mkdir(self::$dir, 0700, true);
        self::$log = self::$dir . '/server.log';
        $fixture = __DIR__ . '/fixtures/session_store_race_server.php';
        foreach (self::SERVERS as $name => [$port, $mode, $handler]) {
            @shell_exec('fuser -k ' . $port . '/tcp 2>/dev/null');
            usleep(200000);
            $savePath = self::$dir . '/' . $name;
            $cmd = sprintf('%s -d opcache.enable_cli=0 %s %d %s %s %s >> %s 2>&1 & echo $!',
                escapeshellarg(PHP_BINARY), escapeshellarg($fixture), $port, $mode, $handler,
                escapeshellarg($savePath), escapeshellarg(self::$log));
            self::$pids[$name] = (int) trim((string) shell_exec($cmd));
        }
        foreach (self::SERVERS as [$port]) {
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
        foreach (self::SERVERS as [$port]) {
            @shell_exec('fuser -k ' . $port . '/tcp 2>/dev/null');
        }
        self::$pids = [];
        if (self::$dir !== '' && is_dir(self::$dir)) {
            @shell_exec('rm -rf ' . escapeshellarg(self::$dir));
        }
    }

    /** @return array<string, array{0: string}> */
    public static function servers(): array
    {
        $out = [];
        foreach (array_keys(self::SERVERS) as $name) {
            $out[$name] = [$name];
        }
        return $out;
    }

    /** @return array{body: string, cookie: ?string}|null */
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

    /**
     * Send a request without waiting for its answer.
     *
     * @return resource
     */
    private function begin(int $port, string $path, string $cookie)
    {
        $fp = stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 5);
        $this->assertNotFalse($fp, "connect: $errstr");
        fwrite($fp, "GET $path HTTP/1.1\r\nHost: 127.0.0.1\r\nCookie: $cookie\r\nConnection: close\r\n\r\n");
        return $fp;
    }

    /** @param resource $fp */
    private function finish($fp): string
    {
        stream_set_timeout($fp, 5);
        $raw = (string) stream_get_contents($fp);
        fclose($fp);
        return $raw;
    }

    /** @return array<string, mixed> */
    private function session(int $port, string $cookie): array
    {
        $r = $this->fetch($port, '/get', $cookie);
        $this->assertNotNull($r);
        $d = json_decode($r['body'], true);
        return is_array($d) ? $d : [];
    }

    private function login(string $name, string $user = 'alice'): string
    {
        $port = self::SERVERS[$name][0];
        $r = $this->fetch($port, '/login?u=' . $user);
        if ($r === null) {
            $this->markTestSkipped("$name fixture did not boot (OpenSwoole unavailable).");
        }
        $this->assertNotNull($r['cookie'], 'login must start a session');
        $this->assertSame($user, $this->session($port, (string) $r['cookie'])['user'] ?? null);
        return (string) $r['cookie'];
    }

    #[DataProvider('servers')]
    public function testLogoutDuringAnInFlightRequestStaysLoggedOut(string $name): void
    {
        $port = self::SERVERS[$name][0];
        $cookie = $this->login($name);
        $slow = $this->begin($port, '/slow', $cookie);
        usleep(250000);
        $this->fetch($port, '/logout', $cookie);
        $this->finish($slow);
        usleep(200000);
        $this->assertArrayNotHasKey('user', $this->session($port, $cookie),
            "$name: the in-flight request put the destroyed session back");
    }

    #[DataProvider('servers')]
    public function testLogoutDuringAnUntouchedInFlightRequestStaysLoggedOut(string $name): void
    {
        $port = self::SERVERS[$name][0];
        $cookie = $this->login($name);
        $slow = $this->begin($port, '/slow?touch=0', $cookie);
        usleep(250000);
        $this->fetch($port, '/logout', $cookie);
        $this->finish($slow);
        usleep(200000);
        $this->assertArrayNotHasKey('user', $this->session($port, $cookie),
            "$name: a read-only in-flight request put the destroyed session back");
    }

    #[DataProvider('servers')]
    public function testKeyRemovedDuringAnInFlightRequestStaysRemoved(string $name): void
    {
        $port = self::SERVERS[$name][0];
        $cookie = $this->login($name);
        $slow = $this->begin($port, '/slow', $cookie);
        usleep(250000);
        $this->fetch($port, '/drop', $cookie);
        $this->finish($slow);
        usleep(200000);
        $s = $this->session($port, $cookie);
        $this->assertArrayNotHasKey('tmp', $s, "$name: the in-flight request restored a key another request removed");
        $this->assertSame(1, $s['seen'] ?? null, "$name: the in-flight request's own change was lost");
        $this->assertSame('alice', $s['user'] ?? null);
    }

    #[DataProvider('servers')]
    public function testKeyChangedDuringAnInFlightRequestIsNotReverted(string $name): void
    {
        $port = self::SERVERS[$name][0];
        $cookie = $this->login($name);
        $slow = $this->begin($port, '/slow', $cookie);
        usleep(250000);
        $this->fetch($port, '/login?u=bob', $cookie);
        $this->finish($slow);
        usleep(200000);
        $s = $this->session($port, $cookie);
        $this->assertSame('bob', $s['user'] ?? null, "$name: the in-flight request reverted a key it never touched");
        $this->assertSame(1, $s['seen'] ?? null, "$name: concurrent changes must merge");
    }

    #[DataProvider('servers')]
    public function testRegenerateStillCarriesTheSession(string $name): void
    {
        $port = self::SERVERS[$name][0];
        $cookie = $this->login($name);
        $r = $this->fetch($port, '/regen', $cookie);
        $this->assertNotNull($r);
        $this->assertNotNull($r['cookie'], 'regenerate must send the new id');
        $this->assertNotSame($cookie, $r['cookie']);
        $s = $this->session($port, (string) $r['cookie']);
        $this->assertSame('alice', $s['user'] ?? null, "$name: regenerate lost the session");
        $this->assertSame(1, $s['regen'] ?? null);
        $this->assertArrayNotHasKey('user', $this->session($port, $cookie), "$name: the old id still works");
    }

    public function testTableRowTooBigForTheColumnIsNotServedStale(): void
    {
        $port = self::SERVERS['coroutine-table'][0];
        $cookie = $this->login('coroutine-table');
        $this->fetch($port, '/big?n=4096', $cookie);
        $s = $this->session($port, $cookie);
        $this->assertSame(4096, $s['big'] ?? null, 'a session bigger than the Table row must still be stored');
        $this->assertSame('alice', $s['user'] ?? null);
        $this->fetch($port, '/big?n=10', $cookie);
        $this->assertSame(10, $this->session($port, $cookie)['big'] ?? null, 'the update after an oversize write was lost');
        $this->fetch($port, '/big?n=4000', $cookie);
        $this->assertSame(4000, $this->session($port, $cookie)['big'] ?? null, 'a stale Table row was served');
    }
}
