<?php
namespace ZealPHP\Tests\Integration;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Regression for issue #108 — "Session Data Loss in CGI Isolation Mode
 * (Double-Write Race Condition) in v0.2.42".
 *
 * Reproduces the reporter's exact scenario end-to-end:
 *   1. Visit /set.php — sets $_SESSION['test'] = "Success!"
 *   2. Visit /get.php with same PHPSESSID — expects "Value: Success!"
 *
 * Pre-fix behaviour:
 *   - Pool mode (v0.2.42's new default): pool_reset_request_state() nulls
 *     $_SESSION between dispatches without calling session_write_close(),
 *     so the file on disk is never updated.
 *   - All CGI modes: host SessionManager runs session_write_close() in
 *     finally with stale $_SESSION, racing the subprocess's write.
 *
 * Post-fix behaviour: session_write_close() runs at the top of
 * pool_reset_request_state(), and CGI dispatch sets a $g->_cgi_session_handoff
 * flag that SessionManager's finally honors (skips the host's write).
 */
class CgiSessionPersistenceTest extends PhpUnitTestCase
{
    private static int $port = 8198;

    /** @var resource|null */
    private static $process = null;
    /** @var array<int, resource> */
    private static array $pipes = [];
    private static string $publicDir = '';
    private static string $sessionDir = '';

    public static function setUpBeforeClass(): void
    {
        self::$publicDir  = sys_get_temp_dir() . '/zptest-cgi-pub-' . bin2hex(random_bytes(4));
        self::$sessionDir = sys_get_temp_dir() . '/zptest-cgi-sess-' . bin2hex(random_bytes(4));
        mkdir(self::$publicDir, 0755, true);
        mkdir(self::$sessionDir, 0700, true);

        file_put_contents(self::$publicDir . '/set.php', '<?php' . "\n"
            . 'session_save_path(' . var_export(self::$sessionDir, true) . ');' . "\n"
            . 'session_start();' . "\n"
            . '$_SESSION["test"] = "Success!";' . "\n"
            . 'echo "Session Set!";' . "\n");
        file_put_contents(self::$publicDir . '/get.php', '<?php' . "\n"
            . 'session_save_path(' . var_export(self::$sessionDir, true) . ');' . "\n"
            . 'session_start();' . "\n"
            . 'echo "Value: " . ($_SESSION["test"] ?? "EMPTY");' . "\n");
        // #355 — a script that NEVER calls session_start(): must emit no
        // Set-Cookie and report an EMPTY request-side $_COOKIE (mod_php
        // session.auto_start=0 parity — no unsolicited tracking cookie /
        // $_COOKIE pollution in legacy-cgi).
        file_put_contents(self::$publicDir . '/nosession.php', '<?php' . "\n"
            . 'echo "cookies=" . count($_COOKIE);' . "\n");

        $script = realpath(__DIR__ . '/../fixtures/cgi_isolation_server.php');
        if ($script === false) {
            self::fail('cgi_isolation_server.php fixture not found');
        }

        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, $script, (string) self::$port],
            [
                0 => ['pipe', 'r'],
                1 => ['file', '/tmp/zealphp_cgi_session_test.log', 'w'],
                2 => ['file', '/tmp/zealphp_cgi_session_test.log', 'a'],
            ],
            $pipes,
            null,
            ['ZEALPHP_TEST_PUBLIC_DIR' => self::$publicDir] + $_ENV
        );
        if (!is_resource($proc)) {
            self::fail('Could not spawn CGI-isolation test server');
        }
        self::$process = $proc;
        self::$pipes   = $pipes;

        $deadline = time() + 6;
        while (time() < $deadline) {
            $sock = @stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $errstr, 0.5);
            if ($sock !== false) {
                fclose($sock);
                return;
            }
            usleep(150_000);
        }
        self::tearDownAfterClass();
        self::fail('CGI-isolation test server did not come up within 6 seconds');
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
     * @return array{status: int, body: string, set_cookie: string}
     */
    private function call(string $path, string $cookie = ''): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($cookie !== '') {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $cookie]);
        }
        $raw   = (string) curl_exec($ch);
        $code  = (int)    curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hSize = (int)    curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerRaw = substr($raw, 0, $hSize);
        $bodyRaw   = substr($raw, $hSize);

        $setCookie = '';
        foreach (explode("\r\n", $headerRaw) as $line) {
            if (stripos($line, 'set-cookie:') === 0) {
                $setCookie = trim(substr($line, strlen('set-cookie:')));
                break;
            }
        }
        return ['status' => $code, 'body' => $bodyRaw, 'set_cookie' => $setCookie];
    }

    /**
     * Issue #108 — end-to-end. Set a session value via one HTTP request,
     * read it back on a second request with the same PHPSESSID cookie.
     */
    public function testSessionWriteInCgiSubprocessSurvivesIntoNextRequest(): void
    {
        $r1 = $this->call('/set.php');
        $this->assertSame(200, $r1['status'], 'set.php should return 200');
        $this->assertSame('Session Set!', $r1['body']);

        preg_match('/PHPSESSID=([^;]+)/', $r1['set_cookie'], $m);
        $sid = $m[1] ?? '';
        $this->assertNotSame('', $sid, 'PHPSESSID must be set on first response');

        $r2 = $this->call('/get.php', 'PHPSESSID=' . $sid);
        $this->assertSame(200, $r2['status']);
        $this->assertSame(
            'Value: Success!',
            $r2['body'],
            'issue #108 — session value written in CGI subprocess (set.php) '
            . 'must persist and be readable in the next request (get.php)'
        );
    }

    /**
     * #355 — a legacy-cgi script that never calls session_start() must get NO
     * Set-Cookie and an untouched request-side $_COOKIE. The old eager host
     * mint injected an unsolicited PHPSESSID into both (defeating shared-cache
     * caching + a request-input fidelity bug); now minting is lazy.
     */
    public function testNoSessionScriptEmitsNoCookieAndCleanCookieSuperglobal(): void
    {
        $r = $this->call('/nosession.php');
        $this->assertSame(200, $r['status']);
        $this->assertSame(
            'cookies=0',
            $r['body'],
            '#355 — request-side $_COOKIE must stay empty (no minted PHPSESSID injected)'
        );
        $this->assertSame(
            '',
            $r['set_cookie'],
            '#355 — no unsolicited Set-Cookie for a script that never started a session'
        );
    }
}
