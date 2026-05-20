<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Integration coverage for the WebSocket endpoints in route/ws.php.
 *
 * WebSocket upgrade + frame exchange is real-server-only — it cannot be driven
 * by the curl-based http() helper, and it bypasses the PSR-15 stack entirely.
 * These tests connect a real coroutine WS client to the running server and
 * assert the actual onOpen / onMessage wire behaviour of each endpoint.
 *
 * Requires the openswoole extension (for the coroutine client) and a server on
 * TEST_SERVER_PORT — same precondition as every other Integration test.
 */
class WebSocketTest extends TestCase
{
    /**
     * Open a WS connection to $path, hand the live client + upgrade result to
     * $fn, and return whatever $fn returns. Assertions are made on the returned
     * data OUTSIDE the coroutine so PHPUnit failures propagate cleanly.
     *
     * @return mixed
     */
    private function ws(string $path, \Closure $fn)
    {
        if (!extension_loaded('openswoole')) {
            $this->markTestSkipped('openswoole extension required for WebSocket client');
        }

        $ret = null;
        $err = null;
        \OpenSwoole\Coroutine::run(function () use ($path, $fn, &$ret, &$err) {
            $cli = new \OpenSwoole\Coroutine\Http\Client(TEST_SERVER_HOST, TEST_SERVER_PORT);
            $cli->set(['timeout' => 5.0]);
            try {
                $upgraded = $cli->upgrade($path);
                $ret = $fn($cli, $upgraded);
            } catch (\Throwable $e) {
                $err = $e;
            } finally {
                $cli->close();
            }
        });

        if ($err !== null) {
            throw $err;
        }
        return $ret;
    }

    // ── /ws/echo ────────────────────────────────────────────────────────

    public function testEchoUpgradeSendsConnectedFrame(): void
    {
        [$upgraded, $open] = $this->ws('/ws/echo', function ($cli, $upgraded) {
            $frame = $cli->recv(2.0);
            return [$upgraded, $frame->data ?? null];
        });

        $this->assertTrue($upgraded, 'WS upgrade to /ws/echo must succeed (HTTP 101)');
        $conn = json_decode((string) $open, true);
        $this->assertIsArray($conn);
        $this->assertSame('connected', $conn['event'] ?? null);
        $this->assertSame('/ws/echo', $conn['path'] ?? null);
    }

    public function testEchoReturnsExactPayload(): void
    {
        $echo = $this->ws('/ws/echo', function ($cli) {
            $cli->recv(2.0);                 // drain the onOpen frame
            $cli->push('hello world');
            return ($cli->recv(2.0))->data ?? null;
        });

        $this->assertSame('echo: hello world', $echo);
    }

    // ── /ws/broadcast ───────────────────────────────────────────────────

    public function testBroadcastConnectedFrameReportsClientCount(): void
    {
        $open = $this->ws('/ws/broadcast', fn($cli) => ($cli->recv(2.0))->data ?? null);

        $conn = json_decode((string) $open, true);
        $this->assertSame('connected', $conn['event'] ?? null);
        $this->assertArrayHasKey('clients', $conn);
        $this->assertGreaterThanOrEqual(1, $conn['clients']);
    }

    public function testBroadcastDeliversMessageToSender(): void
    {
        $msg = $this->ws('/ws/broadcast', function ($cli) {
            $cli->recv(2.0);                 // onOpen frame
            $cli->push('hi-all');
            return ($cli->recv(2.0))->data ?? null;
        });

        $payload = json_decode((string) $msg, true);
        $this->assertSame('hi-all', $payload['message'] ?? null);
        $this->assertArrayHasKey('from', $payload);
        $this->assertArrayHasKey('time', $payload);
    }

    // ── /ws/rooms (Store-backed, cross-worker) ──────────────────────────

    public function testRoomsJoinFrameReflectsRoomAndUid(): void
    {
        $open = $this->ws('/ws/rooms?room=lobby&uid=alice', fn($cli) => ($cli->recv(2.0))->data ?? null);

        $joined = json_decode((string) $open, true);
        $this->assertSame('joined', $joined['event'] ?? null);
        $this->assertSame('lobby', $joined['room'] ?? null);
        $this->assertSame('alice', $joined['uid'] ?? null);
        $this->assertGreaterThanOrEqual(1, $joined['online'] ?? 0);
    }

    public function testRoomsBroadcastsWithSenderUid(): void
    {
        $msg = $this->ws('/ws/rooms?room=lobby&uid=alice', function ($cli) {
            $cli->recv(2.0);                 // joined frame
            $cli->push('msg1');
            return ($cli->recv(2.0))->data ?? null;
        });

        $payload = json_decode((string) $msg, true);
        $this->assertSame('alice', $payload['from'] ?? null);
        $this->assertSame('msg1', $payload['msg'] ?? null);
        $this->assertSame('lobby', $payload['room'] ?? null);
    }

    // ── /ws/auth (manual auth on upgrade) ───────────────────────────────

    public function testAuthAcceptedWithToken(): void
    {
        $open = $this->ws('/ws/auth?token=secret', fn($cli) => ($cli->recv(2.0))->data ?? null);

        $auth = json_decode((string) $open, true);
        $this->assertSame('authenticated', $auth['event'] ?? null);
        $this->assertSame('token', $auth['via'] ?? null);
    }

    public function testAuthRejectedWithoutCredentials(): void
    {
        [$open, $closeOpcode, $closeCode, $closeReason] = $this->ws('/ws/auth', function ($cli) {
            $first = $cli->recv(2.0);        // error frame
            $close = $cli->recv(2.0);        // server disconnects 4001 → CLOSE frame
            return [
                $first->data ?? null,
                $close->opcode ?? null,
                $close->code ?? null,
                $close->reason ?? null,
            ];
        });

        $err = json_decode((string) $open, true);
        $this->assertArrayHasKey('error', $err);
        $this->assertStringContainsString('Unauthorized', $err['error']);
        // The server closes the connection with a 4001 application close code.
        $this->assertSame(\OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_CLOSE, $closeOpcode);
        $this->assertSame(4001, $closeCode);
        $this->assertSame('Unauthorized', $closeReason);
    }

    public function testAuthSecureEchoAfterAccept(): void
    {
        $echo = $this->ws('/ws/auth?token=secret', function ($cli) {
            $cli->recv(2.0);                 // authenticated frame
            $cli->push('classified');
            return ($cli->recv(2.0))->data ?? null;
        });

        $payload = json_decode((string) $echo, true);
        $this->assertSame('classified', $payload['secure_echo'] ?? null);
    }

    // ── /ws/binary (opcode-aware handling) ──────────────────────────────

    public function testBinaryFrameEchoedAsBinary(): void
    {
        $bytes = "\x00\x01\x02\xfe\xff";
        [$opcode, $data] = $this->ws('/ws/binary', function ($cli) use ($bytes) {
            $cli->recv(2.0);                 // info frame
            $cli->push($bytes, \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_BINARY);
            $frame = $cli->recv(2.0);
            return [$frame->opcode ?? null, $frame->data ?? null];
        });

        $this->assertSame(\OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_BINARY, $opcode);
        $this->assertSame($bytes, $data);
    }

    public function testBinaryEndpointEchoesTextAsJson(): void
    {
        $msg = $this->ws('/ws/binary', function ($cli) {
            $cli->recv(2.0);                 // info frame
            $cli->push('plain text');
            return ($cli->recv(2.0))->data ?? null;
        });

        $payload = json_decode((string) $msg, true);
        $this->assertSame('plain text', $payload['text_echo'] ?? null);
        $this->assertSame(strlen('plain text'), $payload['bytes'] ?? null);
    }

    // ── /ws/ticker (server-pushed frames + "stop" close) ────────────────

    public function testTickerPushesAndStops(): void
    {
        [$open, $tick, $stopped] = $this->ws('/ws/ticker', function ($cli) {
            $open = $cli->recv(2.0);         // connected frame
            $tick = $cli->recv(2.0);         // first server tick (~1s later)
            $cli->push('stop');
            $stopped = $cli->recv(2.0);      // stopped frame
            return [$open->data ?? null, $tick->data ?? null, $stopped->data ?? null];
        });

        $this->assertSame('connected', (json_decode((string) $open, true))['event'] ?? null);
        $tickData = json_decode((string) $tick, true);
        $this->assertSame(1, $tickData['tick'] ?? null);
        $this->assertSame('stopped', (json_decode((string) $stopped, true))['event'] ?? null);
    }
}
