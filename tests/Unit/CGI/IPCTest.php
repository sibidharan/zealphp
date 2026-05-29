<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\CGI\IPC;

/**
 * SPIKE — Frame round-trip + edge cases for the IPC primitive.
 * Validates the wire format that PoolWorker (child) and WorkerPool (parent)
 * use to communicate over proc_open pipes.
 */
final class IPCTest extends TestCase
{
    /**
     * @return resource Reads at the front, writes at the back — bidirectional.
     */
    private function makePipe()
    {
        // php://memory is rewindable + seekable, perfect for round-trip tests
        // without needing real OS pipes.
        $fp = fopen('php://memory', 'r+');
        if (!is_resource($fp)) {
            $this->fail('Could not open php://memory');
        }

        return $fp;
    }

    public function testRoundTripSimplePayload(): void
    {
        $fp = $this->makePipe();
        IPC::writeFrame($fp, ['status' => 200, 'body' => 'hello world']);
        rewind($fp);
        $out = IPC::readFrame($fp);
        $this->assertIsArray($out);
        $this->assertSame(200, $out['status']);
        $this->assertSame('hello world', $out['body']);
    }

    public function testRoundTripNestedPayload(): void
    {
        $fp = $this->makePipe();
        $payload = [
            'status'  => 201,
            'headers' => ['Content-Type: text/html', 'X-Foo: bar'],
            'cookies' => [['name' => 'sid', 'value' => 'abc123', 'expires' => 0]],
            'body'    => str_repeat('a', 5000),
        ];
        IPC::writeFrame($fp, $payload);
        rewind($fp);
        $out = IPC::readFrame($fp);
        $this->assertSame($payload, $out);
    }

    public function testMultipleFramesBackToBack(): void
    {
        $fp = $this->makePipe();
        IPC::writeFrame($fp, ['n' => 1]);
        IPC::writeFrame($fp, ['n' => 2]);
        IPC::writeFrame($fp, ['n' => 3]);
        rewind($fp);

        $this->assertSame(['n' => 1], IPC::readFrame($fp));
        $this->assertSame(['n' => 2], IPC::readFrame($fp));
        $this->assertSame(['n' => 3], IPC::readFrame($fp));
        $this->assertNull(IPC::readFrame($fp), 'no fourth frame should be present');
    }

    public function testReadFrameOnEmptyStreamReturnsNull(): void
    {
        $fp = $this->makePipe();
        $this->assertNull(IPC::readFrame($fp));
    }

    public function testReadFrameOnTruncatedHeaderReturnsNull(): void
    {
        $fp = $this->makePipe();
        fwrite($fp, "\x00\x00"); // only 2 of the 4 header bytes
        rewind($fp);
        $this->assertNull(IPC::readFrame($fp));
    }

    public function testReadFrameOnTruncatedBodyReturnsNull(): void
    {
        $fp = $this->makePipe();
        // header says 100 bytes will follow, but write only 10.
        fwrite($fp, pack('N', 100) . str_repeat('x', 10));
        rewind($fp);
        $this->assertNull(IPC::readFrame($fp));
    }

    public function testReadFrameRejectsOversizedLength(): void
    {
        $fp = $this->makePipe();
        fwrite($fp, pack('N', IPC::MAX_FRAME_BYTES + 1)); // exceeds sanity cap
        rewind($fp);
        $this->assertNull(IPC::readFrame($fp));
    }

    public function testReadFrameRejectsZeroLength(): void
    {
        $fp = $this->makePipe();
        fwrite($fp, pack('N', 0));
        rewind($fp);
        $this->assertNull(IPC::readFrame($fp));
    }

    public function testReadFrameRejectsNonJsonBody(): void
    {
        $fp = $this->makePipe();
        $garbage = 'not valid json';
        fwrite($fp, pack('N', strlen($garbage)) . $garbage);
        rewind($fp);
        $this->assertNull(IPC::readFrame($fp));
    }

    public function testReadFrameRejectsJsonScalar(): void
    {
        $fp = $this->makePipe();
        $scalar = '"just a string"';
        fwrite($fp, pack('N', strlen($scalar)) . $scalar);
        rewind($fp);
        $this->assertNull(IPC::readFrame($fp));
    }

    public function testRoundTripEmptyArray(): void
    {
        $fp = $this->makePipe();
        IPC::writeFrame($fp, []);
        rewind($fp);
        $this->assertSame([], IPC::readFrame($fp));
    }

    public function testRoundTripLargePayload(): void
    {
        $fp = $this->makePipe();
        $payload = ['data' => str_repeat('x', 100_000)];
        IPC::writeFrame($fp, $payload);
        rewind($fp);
        $this->assertSame($payload, IPC::readFrame($fp));
    }

    /**
     * A binary `body` (invalid UTF-8) makes a plain json_encode() fail. The
     * writeFrame() fallback base64-encodes the body, tags it with
     * `body_encoding=base64`, and retries — so the frame still round-trips.
     */
    public function testWriteFrameBase64EncodesBinaryBodyOnJsonFailure(): void
    {
        $fp = $this->makePipe();
        $binary = "\xff\xfe\x00\x01 binary body \x80\x81";
        $this->assertFalse(
            json_encode(['body' => $binary]) !== false,
            'precondition: raw binary body must defeat json_encode'
        );

        IPC::writeFrame($fp, ['status' => 200, 'body' => $binary]);
        rewind($fp);
        $out = IPC::readFrame($fp);

        $this->assertIsArray($out);
        $this->assertSame(200, $out['status']);
        $this->assertSame('base64', $out['body_encoding']);
        $this->assertIsString($out['body']);
        $this->assertSame($binary, base64_decode($out['body']));
    }

    /**
     * When json_encode() fails AND the body isn't a string, the base64 retry
     * can't help (e.g. a resource buried in the payload). writeFrame() must
     * fall back to a synthetic 500 error frame rather than writing garbage.
     */
    public function testWriteFrameEmitsErrorFrameWhenPayloadUnencodable(): void
    {
        $fp = $this->makePipe();
        $res = fopen('php://memory', 'r');
        $this->assertIsResource($res);

        // body is NOT a string, so the base64 branch is skipped; the second
        // json_encode also fails -> synthetic 500 frame.
        IPC::writeFrame($fp, ['status' => 200, 'body' => $res, 'extra' => $res]);
        fclose($res);
        rewind($fp);
        $out = IPC::readFrame($fp);

        $this->assertIsArray($out);
        $this->assertSame(500, $out['status']);
        $this->assertIsString($out['body']);
        $this->assertStringContainsString('json_encode failed', $out['body']);
    }

    /**
     * readFrame()'s timeout path: a non-EOF stream that yields no data within
     * the deadline returns null instead of blocking forever. We promise a
     * 10-byte body in the header then write nothing, on a non-blocking socket.
     */
    public function testReadFrameHonoursTimeoutOnStalledStream(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        [$read, $write] = $pair;
        stream_set_blocking($read, false);

        // Header promises 10 bytes, but the body never arrives.
        fwrite($write, pack('N', 10));

        $start = microtime(true);
        $out = IPC::readFrame($read, 0.2);
        $elapsed = microtime(true) - $start;

        $this->assertNull($out, 'stalled body read must time out to null');
        $this->assertGreaterThanOrEqual(0.18, $elapsed, 'must actually wait for the deadline');
        $this->assertLessThan(2.0, $elapsed, 'must not block far past the deadline');

        fclose($read);
        fclose($write);
    }
}
