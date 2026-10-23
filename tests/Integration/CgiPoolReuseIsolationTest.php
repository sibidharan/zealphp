<?php
namespace ZealPHP\Tests\Integration;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * End-to-end: one visitor's state must not reach the next visitor through a
 * REUSED cgi-pool subprocess (cgiPoolMaxRequests > 1, one subprocess, so every
 * request below lands on the same one).
 *
 * H1 — after session_write_close() the subprocess kept PHP's internal session
 * id. The next visitor's session_start() resumed it, read the previous user's
 * data, and the host emitted that id to them as Set-Cookie.
 *
 * H2 — the body travelled on raw stdout with only its length framed, so bytes
 * an app wrote straight to fd 1 shifted into the next visitor's response.
 */
class CgiPoolReuseIsolationTest extends PhpUnitTestCase
{
    private static int $port = 9851;

    /** @var resource|null */
    private static $process = null;
    /** @var array<int, resource> */
    private static array $pipes = [];
    private static string $publicDir = '';
    private static string $sessionDir = '';

    public static function setUpBeforeClass(): void
    {
        self::$publicDir  = sys_get_temp_dir() . '/zptest-pool-reuse-pub-' . bin2hex(random_bytes(4));
        self::$sessionDir = sys_get_temp_dir() . '/zptest-pool-reuse-sess-' . bin2hex(random_bytes(4));
        mkdir(self::$publicDir, 0755, true);
        mkdir(self::$sessionDir, 0700, true);
        $sp = var_export(self::$sessionDir, true);

        file_put_contents(self::$publicDir . '/login.php', "<?php\n"
            . "session_save_path($sp);\nsession_start();\n"
            . "\$_SESSION['user'] = 'alice';\necho 'logged in';\n");
        file_put_contents(self::$publicDir . '/whoami.php', "<?php\n"
            . "session_save_path($sp);\nsession_start();\n"
            . "echo 'user=' . (\$_SESSION['user'] ?? 'none');\n");
        file_put_contents(self::$publicDir . '/stdout.php', "<?php\n"
            . "\$f = fopen('php://stdout', 'w');\nfwrite(\$f, 'SECRET-OF-USER-1');\n"
            . "echo str_repeat('A', 100);\n");
        file_put_contents(self::$publicDir . '/b.php', "<?php\necho 'B';\n");

        $script = realpath(__DIR__ . '/../fixtures/cgi_pool_reuse_server.php');
        if ($script === false) {
            self::fail('cgi_pool_reuse_server.php fixture not found');
        }

        $pipes = [];
        $log = sys_get_temp_dir() . '/zealphp_cgi_pool_reuse_test.log';
        $proc = proc_open(
            [PHP_BINARY, $script, (string) self::$port],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            null,
            ['ZEALPHP_TEST_PUBLIC_DIR' => self::$publicDir] + $_ENV
        );
        if (!is_resource($proc)) {
            self::fail('Could not spawn cgi-pool reuse test server');
        }
        self::$process = $proc;
        self::$pipes   = $pipes;

        $deadline = time() + 8;
        while (time() < $deadline) {
            $sock = @stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $errstr, 0.5);
            if ($sock !== false) {
                fclose($sock);
                return;
            }
            usleep(150_000);
        }
        self::tearDownAfterClass();
        self::fail('cgi-pool reuse test server did not come up within 8 seconds');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process, 9);
            proc_close(self::$process);
            self::$process = null;
        }
        foreach (self::$pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        self::$pipes = [];
        foreach (glob(self::$publicDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir(self::$publicDir);
        foreach (glob(self::$sessionDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir(self::$sessionDir);
    }

    /**
     * @return array{status: int, body: string, sid: string}
     */
    private function call(string $path, string $cookie = ''): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        if ($cookie !== '') {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $cookie]);
        }
        $raw   = (string) curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $sid = '';
        if (preg_match('/^set-cookie:\s*PHPSESSID=([^;\r\n]+)/im', substr($raw, 0, $hSize), $m)) {
            $sid = $m[1];
        }
        return ['status' => $code, 'body' => substr($raw, $hSize), 'sid' => $sid];
    }

    public function testNextVisitorIsNotHandedThePreviousSession(): void
    {
        $alice = $this->call('/login.php');
        $this->assertSame(200, $alice['status']);
        $this->assertNotSame('', $alice['sid'], 'login must issue a session cookie');

        $stranger = $this->call('/whoami.php');
        $this->assertSame('user=none', $stranger['body'],
            'a cookieless visitor must not see the previous visitor\'s session data');
        $this->assertNotSame($alice['sid'], $stranger['sid'],
            'the previous visitor\'s session id must not be sent to the next visitor');

        $back = $this->call('/whoami.php', 'PHPSESSID=' . $alice['sid']);
        $this->assertSame('user=alice', $back['body'], 'the owner still resumes their session');
    }

    public function testDirectStdoutWritesDoNotReachTheNextResponse(): void
    {
        $first = $this->call('/stdout.php');
        $this->assertSame(str_repeat('A', 100), $first['body']);

        $second = $this->call('/b.php');
        $this->assertSame('B', $second['body'], 'response 2 must not carry bytes from response 1');
        $this->assertStringNotContainsString('SECRET', $second['body']);
    }
}
