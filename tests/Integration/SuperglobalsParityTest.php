<?php
namespace ZealPHP\Tests\Integration;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Apache-parity tests for `superglobals(true) + processIsolation(false)`
 * (the "drop-in LAMP" lifecycle, v0.2.27).
 *
 * Spawns its own dedicated server via tests/fixtures/mixed_mode_server.php
 * so we can probe the superglobal-population contract under the actual
 * Mixed-mode lifecycle - the long-running dev server on :8080 runs in
 * coroutine mode (superglobals=false) and can't exercise this path.
 *
 * Pins:
 *
 *   1. $_GET, $_POST, $_COOKIE, $_FILES, $_SERVER, $_REQUEST are populated
 *      per request (regression test for the v0.1.x to v0.2.x linking that
 *      was dropped in commit 327e180 + 900c18a, restored in v0.2.27 at
 *      App.php:3565).
 *
 *   2. $g->session and $_SESSION are the SAME array - mutations through
 *      either name show up in the other immediately. This is the
 *      `unset($g->session)` + __get/__set proxy fix in v0.2.27.
 *
 *   3. The full session contract round-trips: a counter incremented
 *      through $_SESSION on one request reads correctly via $g->session
 *      on the next request (proves session_write_close persists writes
 *      through both names, since they are the same array).
 */
class SuperglobalsParityTest extends PhpUnitTestCase
{
    private static int $port = 8197;

    /** @var resource|null */
    private static $process = null;
    /** @var array<int, resource> */
    private static array $pipes = [];

    public static function setUpBeforeClass(): void
    {
        $script = realpath(__DIR__ . '/../fixtures/mixed_mode_server.php');
        if ($script === false) {
            self::fail('mixed_mode_server.php fixture not found');
        }

        $pipes = [];
        // Array form of proc_open bypasses the shell entirely - no shell
        // metacharacter interpretation, no command injection surface.
        $proc = proc_open(
            [PHP_BINARY, $script, (string) self::$port],
            [
                0 => ['pipe', 'r'],
                1 => ['file', '/tmp/zealphp_parity_test.log', 'w'],
                2 => ['file', '/tmp/zealphp_parity_test.log', 'a'],
            ],
            $pipes
        );
        if (!is_resource($proc)) {
            self::fail('Could not spawn mixed-mode test server');
        }
        self::$process = $proc;
        self::$pipes   = $pipes;

        // Poll for the server to come up (worst case ~6s).
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
        self::fail('Mixed-mode test server did not come up within 6 seconds');
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
    }

    /**
     * @return array{status: int, body: string, json: array<string, mixed>}
     */
    private function probe(string $query = '', string $cookieJar = ''): array
    {
        return $this->call('/__parity_probe', $query, $cookieJar);
    }

    /**
     * @return array{status: int, body: string, json: array<string, mixed>}
     */
    private function call(string $path, string $query = '', string $cookieJar = '', ?string $postBody = null): array
    {
        $url = 'http://127.0.0.1:' . self::$port . $path . ($query ? '?' . $query : '');
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HEADER         => false,
        ]);
        if ($cookieJar !== '') {
            curl_setopt($ch, CURLOPT_COOKIEJAR,  $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        }
        if ($postBody !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        }
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close intentionally omitted - deprecated in PHP 8+; handle
        // is released when $ch goes out of scope.
        /** @var array<string, mixed> $json */
        $json = json_decode($body, true) ?: [];
        return ['status' => $code, 'body' => $body, 'json' => $json];
    }

    public function testDollarGetIsPopulatedFromQueryString(): void
    {
        $r = $this->probe('foo=bar&baz=qux');
        $this->assertSame(200, $r['status']);
        $this->assertSame(['foo' => 'bar', 'baz' => 'qux'], $r['json']['_GET']);
    }

    public function testDollarServerHasStandardKeys(): void
    {
        $r = $this->probe();
        $this->assertSame('GET',                 $r['json']['_SERVER_method']);
        $this->assertSame('/__parity_probe',     $r['json']['_SERVER_uri']);
        $this->assertTrue((bool) $r['json']['_SERVER_has_host']);
    }

    public function testDollarRequestMergesGetAndPost(): void
    {
        $r = $this->probe('q=1');
        $this->assertArrayHasKey('q', $r['json']['_REQUEST']);
        $this->assertSame('1', $r['json']['_REQUEST']['q']);
    }

    public function testGetParityWithDollarGet(): void
    {
        $r = $this->probe('alpha=1&beta=2');
        $this->assertTrue((bool) $r['json']['g_get_equals_dollar'],
            '$g->get must equal $_GET in superglobals mode');
        $this->assertSame($r['json']['_GET'], $r['json']['g_get']);
    }

    public function testSessionAliasMutationCrosses(): void
    {
        $r = $this->probe('');
        $this->assertTrue((bool) $r['json']['session_is_aliased'],
            'Writes via $_SESSION must be visible through $g->session and vice versa');
        $this->assertTrue((bool) $r['json']['g_session_equals_dollar'],
            '$g->session and $_SESSION must be the same array in superglobals mode');
    }

    public function testGetAliasMutationCrosses(): void
    {
        // issue #17 — $g->get must be a LIVE reference to $_GET in superglobals
        // mode, not a per-request snapshot. Mutating either after dispatch must
        // be visible through the other.
        $r = $this->probe('foo=bar');
        $this->assertTrue((bool) $r['json']['get_is_aliased'],
            'Writes via $_GET must be visible through $g->get and vice versa (issue #17)');
    }

    public function testSessionCounterPersistsAcrossRequests(): void
    {
        $jar = tempnam(sys_get_temp_dir(), 'zsg_jar_');
        try {
            $r1 = $this->probe('', $jar);
            $r2 = $this->probe('', $jar);
            $r3 = $this->probe('', $jar);
            $this->assertSame(1, $r1['json']['_SESSION']['hits']);
            $this->assertSame(2, $r2['json']['_SESSION']['hits']);
            $this->assertSame(3, $r3['json']['_SESSION']['hits']);
            // The $g->session counter must increment in lockstep - proving
            // the alias persists writes via both names.
            $this->assertSame(1, $r1['json']['_SESSION']['via_g']);
            $this->assertSame(2, $r2['json']['_SESSION']['via_g']);
            $this->assertSame(3, $r3['json']['_SESSION']['via_g']);
        } finally {
            @unlink($jar);
        }
    }

    public function testPostBodyPopulatesDollarPost(): void
    {
        $r = $this->call('/__parity_post', '', '', 'name=daisy&role=engineer');
        $this->assertSame(200, $r['status']);
        $this->assertSame(['name' => 'daisy', 'role' => 'engineer'], $r['json']['_POST']);
        $this->assertTrue((bool) $r['json']['g_post_eq_dollar'],
            '$g->post must equal $_POST in superglobals mode');
        $this->assertSame('POST', $r['json']['_SERVER_method']);
    }

    public function testSessionDestroyClearsBothNames(): void
    {
        $r = $this->call('/__parity_destroy');
        $this->assertSame(200, $r['status']);
        $this->assertTrue((bool) $r['json']['before']['_SESSION_has']);
        $this->assertTrue((bool) $r['json']['before']['g_session_has'],
            '$g->session must see the same marker as $_SESSION before destroy');
        $this->assertTrue((bool) $r['json']['both_empty_after'],
            'session_destroy must clear both $_SESSION and $g->session (they are the same array)');
    }

    public function testSessionUnsetClearsBothNames(): void
    {
        $r = $this->call('/__parity_unset');
        $this->assertSame(200, $r['status']);
        $this->assertTrue((bool) $r['json']['both_empty_after'],
            'session_unset must clear both $_SESSION and $g->session');
        $this->assertSame('set', $r['json']['session_id_still'],
            'session_unset must NOT destroy the session id (only the data)');
    }
}
