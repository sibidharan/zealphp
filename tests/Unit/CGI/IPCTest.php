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
}
