<?php

namespace ZealPHP\Tests\Integration;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * REAL end-to-end async test for the Redis-backed pub/sub + streams
 * machinery on the live OpenSwoole server.
 *
 * Boots a dedicated ZealPHP process with `ZEALPHP_STORE_BACKEND=redis`
 * on its own port (so the default :8080 Table-backend server used by
 * the rest of tests/Integration/ stays untouched). Then:
 *
 *   1. GETs publish requests on `/demo/pubsub/publish` —
 *      route/demo.php's `App::subscribe('demo:pubsub', …)` handler
 *      runs inside a worker coroutine spawned by `RedisPubSub::runner`
 *      and writes the received message to a Store table.
 *   2. POLLs `/demo/pubsub/log` and ASSERTS the published payload
 *      arrived in the subscriber's Store row — that's the actual
 *      end-to-end async dispatch path (HTTP → PUBLISH → Redis →
 *      SUBSCRIBE coroutine → handler → Store::set).
 *   3. Same for PSUBSCRIBE (pattern subscriber on `demo:pubsub:*`)
 *      and for `App::subscribeReliable` (XREADGROUP consumer-group
 *      runner in `RedisStreams::runner`).
 *
 * Skips gracefully when Redis is unreachable or the dedicated server
 * fails to come up.
 */
final class RedisPubSubFederationTest extends PhpUnitTestCase
{
    private const PORT = 8694;
    private const REDIS_URL = 'redis://127.0.0.1:16379/0';

    /** @var resource|null */
    private static $serverProc = null;

    private static string $logFile = '';

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function setUpBeforeClass(): void
    {
        // 1. Redis reachable?
        try {
            $c = new \Predis\Client(self::REDIS_URL);
            $c->ping();
            $c->disconnect();
        } catch (\Throwable $e) {
            self::markTestSkippedStatic('Redis not reachable at ' . self::REDIS_URL);
        }

        // 2. Free port?
        $sock = @fsockopen('127.0.0.1', self::PORT, $eno, $err, 0.5);
        if ($sock !== false) {
            fclose($sock);
            self::markTestSkippedStatic('Port ' . self::PORT . ' already in use');
        }

        // 3. Boot a dedicated Redis-backed server via proc_open (no shell).
        self::$logFile = sys_get_temp_dir() . '/zptest-redis-server-' . self::PORT . '.log';
        @unlink(self::$logFile);
        /** @var array<string, string> $env */
        $env = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && is_scalar($v)) { $env[$k] = (string) $v; }
        }
        $env = array_merge($env, [
            'ZEALPHP_PORT'           => (string) self::PORT,
            'ZEALPHP_WORKERS'        => '1',
            'ZEALPHP_TASK_WORKERS'   => '0',
            'ZEALPHP_STORE_BACKEND'  => 'redis',
            'ZEALPHP_REDIS_URL'      => self::REDIS_URL,
            'ZEALPHP_REDIS_PREFER'   => 'predis',
            'ZEALPHP_LOG_ASYNC'      => '0',
            'ZEALPHP_ACCESS_LOG'     => '0',
            'ZEALPHP_DEBUG_LOG'      => '0',
            'ZEALPHP_RECYCLE_LOG'    => '0',
            'ZEALPHP_SKIP_DOCS_BUILD'=> '1',
        ]);
        $logFd = fopen(self::$logFile, 'wb');
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => $logFd ?: ['pipe', 'w'],
            2 => $logFd ?: ['pipe', 'w'],
        ];
        $proc = @proc_open(
            [PHP_BINARY, self::projectRoot() . '/app.php'],
            $descriptors,
            $pipes,
            self::projectRoot(),
            $env,
        );
        if (!is_resource($proc)) {
            if ($logFd) { fclose($logFd); }
            self::markTestSkippedStatic('proc_open failed for redis-backed server');
            return;
        }
        self::$serverProc = $proc;
        // Close child's stdin (we never write to it).
        if (isset($pipes[0]) && is_resource($pipes[0])) { fclose($pipes[0]); }

        // 4. Wait up to 15 s for /json to come up.
        $up = false;
        for ($i = 0; $i < 60; $i++) {
            $ch = curl_init('http://127.0.0.1:' . self::PORT . '/json');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
            @curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status >= 200 && $status < 500) { $up = true; break; }
            usleep(250_000);
        }
        if (!$up) {
            self::stopServer();
            $log = self::$logFile && file_exists(self::$logFile)
                ? substr((string) file_get_contents(self::$logFile), -400) : '';
            self::markTestSkippedStatic("Redis-backed server failed to boot on port " . self::PORT . "; tail of log:\n" . $log);
        }

        // 5. Subscriber coroutine needs a moment to SUBSCRIBE after worker start.
        usleep(800_000);
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
        if (self::$logFile && file_exists(self::$logFile)) {
            @unlink(self::$logFile);
        }
    }

    private static function stopServer(): void
    {
        if (!is_resource(self::$serverProc)) { return; }
        // Polite SIGTERM first; OpenSwoole's signal handler flushes coverage
        // (when ZEALPHP_COVERAGE_DIR is set) and shuts down cleanly.
        @proc_terminate(self::$serverProc, 15);   // SIGTERM
        for ($i = 0; $i < 16; $i++) {
            $st = @proc_get_status(self::$serverProc);
            if (!$st['running']) { break; }
            usleep(250_000);
        }
        @proc_terminate(self::$serverProc, 9);    // SIGKILL fallback
        @proc_close(self::$serverProc);
        self::$serverProc = null;
    }

    /** Static-context helper so setUpBeforeClass can mark the whole class skipped. */
    private static function markTestSkippedStatic(string $why): void
    {
        throw new \PHPUnit\Framework\SkippedTestSuiteError($why);
    }

    /**
     * GET wrapper using the dedicated server.
     * @return array{status: int, body: string, json: mixed}
     */
    private function get(string $path): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::PORT . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
    }

    /**
     * Poll `/demo/pubsub/log` for up to ~2 s until $matcher returns true.
     * Returns the matched entry or null on timeout.
     *
     * @param  callable(array<string, mixed>): bool  $matcher
     * @return array<string, mixed>|null
     */
    private function pollLogFor(callable $matcher): ?array
    {
        for ($i = 0; $i < 20; $i++) {
            $r = $this->get('/demo/pubsub/log');
            $json = $r['json'];
            if (is_array($json) && isset($json['log']) && is_array($json['log'])) {
                foreach ($json['log'] as $row) {
                    if (!is_array($row)) { continue; }
                    /** @var array<string, mixed> $typed */
                    $typed = [];
                    foreach ($row as $k => $v) {
                        if (is_string($k)) { $typed[$k] = $v; }
                    }
                    if ($matcher($typed)) { return $typed; }
                }
            }
            usleep(100_000);
        }
        return null;
    }

    /**
     * Render the log endpoint body for failure messages. Centralised so
     * PHPStan can prove the concatenation operands are strings.
     */
    private function logBody(): string
    {
        $r = $this->get('/demo/pubsub/log');
        return $r['body'];
    }

    /**
     * Normalise the JSON body of a GET response to a typed assoc array.
     * @param  array{status: int, body: string, json: mixed}  $r
     * @return array<string, mixed>
     */
    private function jsonOf(array $r): array
    {
        $j = $r['json'];
        if (!is_array($j)) { return []; }
        /** @var array<string, mixed> $out */
        $out = [];
        foreach ($j as $k => $v) {
            if (is_string($k)) { $out[$k] = $v; }
        }
        return $out;
    }

    // ── tests ─────────────────────────────────────────────────────────────

    public function testExactChannelPubSubDeliveryEndToEnd(): void
    {
        $unique = 'cov-' . bin2hex(random_bytes(4));
        $pub    = $this->get('/demo/pubsub/publish?channel=demo:pubsub&msg=' . $unique);
        $j      = $this->jsonOf($pub);
        $this->assertSame(200, $pub['status'], 'publish endpoint must return 200');
        $this->assertTrue((bool) ($j['ok'] ?? false), 'publish must succeed: ' . $pub['body']);
        $rcv    = $j['receivers'] ?? 0;
        $this->assertGreaterThanOrEqual(1, is_numeric($rcv) ? (int) $rcv : 0,
            'at least one subscriber must receive (the demo:pubsub handler)');

        $row = $this->pollLogFor(
            fn(array $r): bool => ($r['payload'] ?? '') === $unique && ($r['channel'] ?? '') === 'demo:pubsub'
        );
        $this->assertNotNull(
            $row,
            "subscriber handler must have stored the message; full log:\n" . $this->logBody()
        );
        $this->assertSame('demo:pubsub', $row['channel']);
        $this->assertSame($unique,        $row['payload']);
    }

    public function testPatternPubSubDeliveryEndToEnd(): void
    {
        $unique = 'warm-' . bin2hex(random_bytes(4));
        $pub    = $this->get('/demo/pubsub/publish?channel=demo:pubsub:zone&msg=' . $unique);
        $j      = $this->jsonOf($pub);
        $this->assertTrue((bool) ($j['ok'] ?? false));

        $row = $this->pollLogFor(
            fn(array $r): bool => ($r['payload'] ?? '') === $unique && ($r['pattern'] ?? '') === 'demo:pubsub:*'
        );
        $this->assertNotNull(
            $row,
            "pattern subscriber must fire on channel matching demo:pubsub:* — full log:\n" . $this->logBody()
        );
        $this->assertSame('demo:pubsub:zone', $row['channel']);
        $this->assertSame('demo:pubsub:*',    $row['pattern']);
        $this->assertSame($unique,            $row['payload']);
    }

    public function testReliableStreamsDeliveryEndToEnd(): void
    {
        $unique = 'rel-' . bin2hex(random_bytes(4));
        $pub    = $this->get('/demo/pubsub/publish-reliable?stream=demo:reliable&msg=' . $unique);
        $j      = $this->jsonOf($pub);
        $this->assertSame(200, $pub['status']);
        $this->assertTrue((bool) ($j['ok'] ?? false), 'publishReliable must succeed: ' . $pub['body']);
        $msgId  = $j['message_id'] ?? '';
        $this->assertNotSame('', is_scalar($msgId) ? (string) $msgId : '',
            'publishReliable must return a Redis-generated stream id');

        $row = $this->pollLogFor(
            fn(array $r): bool => ($r['payload'] ?? '') === $unique
                                && is_scalar($r['channel'] ?? null)
                                && str_starts_with((string) $r['channel'], 'stream:')
        );
        $this->assertNotNull(
            $row,
            "XREADGROUP consumer must dispatch the message; full log:\n" . $this->logBody()
        );
        $this->assertSame('stream:demo:reliable', $row['channel']);
        $this->assertSame($unique,                $row['payload']);
        // The stream message id round-trips into the row's `pattern` column
        // (route/demo.php's subscribeReliable handler stores it there).
        $pat = $row['pattern'] ?? '';
        $this->assertNotSame('', is_scalar($pat) ? (string) $pat : '');
    }
}
