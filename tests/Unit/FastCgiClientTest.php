<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\CGI\FastCgiClient;
use ZealPHP\CGI\FastCgiException;
use ZealPHP\CGI\FcgiTransport;
use ZealPHP\CGI\FcgiBlockingTransport;
use ZealPHP\CGI\FcgiCoroutineTransport;

/**
 * Protocol-layer unit tests for FastCgiClient.
 *
 * All tests exercise the pure-PHP encoding/decoding logic without requiring
 * a real php-fpm process. The "mock socket" pattern works by driving
 * recvExact() / parseStdout via reflection or by testing encodeRecord /
 * encodeParams / encodeLength directly, then assembling full wire bytes and
 * feeding them back through a stream-socket pair where needed.
 */
class FastCgiClientTest extends TestCase
{
    private FastCgiClient $client;

    protected function setUp(): void
    {
        $this->client = new FastCgiClient('127.0.0.1:9000', 5);
    }

    // ── encodeLength ─────────────────────────────────────────────────────────

    public function testEncodeLengthZero(): void
    {
        $this->assertSame("\x00", $this->client->encodeLength(0));
    }

    public function testEncodeLengthShortMaxBoundary(): void
    {
        // 127 is the last value that fits in 1 byte
        $this->assertSame("\x7f", $this->client->encodeLength(127));
        $this->assertSame(1, strlen($this->client->encodeLength(127)));
    }

    public function testEncodeLengthLongMinBoundary(): void
    {
        // 128 must use 4-byte form with MSB set
        $encoded = $this->client->encodeLength(128);
        $this->assertSame(4, strlen($encoded));
        $unpacked = unpack('N', $encoded);
        $this->assertIsArray($unpacked);
        // MSB must be set (0x80000000 | 128 = 0x80000080)
        $this->assertSame(0x80000080, $unpacked[1]);
    }

    public function testEncodeLengthLargeValue(): void
    {
        $encoded = $this->client->encodeLength(65535);
        $this->assertSame(4, strlen($encoded));
        $unpacked = unpack('N', $encoded);
        $this->assertIsArray($unpacked);
        $this->assertSame(0x80000000 | 65535, $unpacked[1]);
    }

    public function testEncodeLengthNegativeThrows(): void
    {
        $this->expectException(FastCgiException::class);
        $this->client->encodeLength(-1);
    }

    // ── encodeParams NV-pair encoding ────────────────────────────────────────

    public function testEncodeParamsEmpty(): void
    {
        $this->assertSame('', $this->client->encodeParams([]));
    }

    public function testEncodeParamsSingleShortNameShortValue(): void
    {
        // "FOO" => "bar" — both lengths < 128, so each 1 byte
        $encoded = $this->client->encodeParams(['FOO' => 'bar']);
        // Expected: \x03 \x03 FOO bar
        $this->assertSame("\x03\x03FOObar", $encoded);
    }

    public function testEncodeParamsEmptyValue(): void
    {
        $encoded = $this->client->encodeParams(['KEY' => '']);
        // name len=3 (1 byte), value len=0 (1 byte), then "KEY"
        $this->assertSame("\x03\x00KEY", $encoded);
    }

    public function testEncodeParamsLongName(): void
    {
        // Name of 128 chars must use 4-byte length encoding
        $name  = str_repeat('A', 128);
        $value = 'v';
        $encoded = $this->client->encodeParams([$name => $value]);

        // First 4 bytes: long name-length
        $nameLenBytes = substr($encoded, 0, 4);
        $unpacked = unpack('N', $nameLenBytes);
        $this->assertIsArray($unpacked);
        $this->assertSame(0x80000000 | 128, $unpacked[1]);

        // Next 1 byte: short value-length
        $this->assertSame("\x01", $encoded[4]);

        // Then the name and value data
        $this->assertSame($name . $value, substr($encoded, 5));
    }

    public function testEncodeParamsLongValue(): void
    {
        $name  = 'X';
        $value = str_repeat('V', 200);
        $encoded = $this->client->encodeParams([$name => $value]);

        // 1 byte name-len, 4 byte value-len
        $this->assertSame("\x01", $encoded[0]);
        $valueLenBytes = substr($encoded, 1, 4);
        $unpacked = unpack('N', $valueLenBytes);
        $this->assertIsArray($unpacked);
        $this->assertSame(0x80000000 | 200, $unpacked[1]);
        $this->assertSame($name . $value, substr($encoded, 5));
    }

    public function testEncodeParamsMultiplePairs(): void
    {
        $params  = ['A' => '1', 'BB' => '22'];
        $encoded = $this->client->encodeParams($params);
        // A=1:   \x01\x01 A 1
        // BB=22: \x02\x02 BB 22
        $expected = "\x01\x01A1\x02\x02BB22";
        $this->assertSame($expected, $encoded);
    }

    public function testDecodeParamsRoundTrip(): void
    {
        // Verify that decoding the encoded bytes reproduces the original params
        $params  = ['SCRIPT_FILENAME' => '/var/www/html/index.php', 'REQUEST_METHOD' => 'GET'];
        $encoded = $this->client->encodeParams($params);
        $decoded = $this->decodeParams($encoded);
        $this->assertSame($params, $decoded);
    }

    // ── encodeRecord framing ─────────────────────────────────────────────────

    public function testEncodeRecordHeaderFields(): void
    {
        $type  = FastCgiClient::FCGI_PARAMS;
        $reqId = 1;
        $body  = 'HELLO';

        $record = $this->client->encodeRecord($type, $reqId, $body);

        $hdr = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved', $record);
        $this->assertIsArray($hdr);

        $this->assertSame(FastCgiClient::FCGI_VERSION, $hdr['version']);
        $this->assertSame($type, $hdr['type']);
        $this->assertSame($reqId, $hdr['requestId']);
        $this->assertSame(strlen($body), $hdr['contentLength']);
    }

    public function testEncodeRecordPaddingAlignment(): void
    {
        // Body of 5 bytes → padding should be 3 to reach 8-byte boundary
        $record = $this->client->encodeRecord(FastCgiClient::FCGI_STDIN, 1, 'ABCDE');
        $hdr = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved', $record);
        $this->assertIsArray($hdr);
        $this->assertSame(3, $hdr['paddingLength']);
        // Total length = 8 (header) + 5 (content) + 3 (padding) = 16
        $this->assertSame(16, strlen($record));
    }

    public function testEncodeRecordNoPaddingWhenAligned(): void
    {
        // Body of 8 bytes → no padding needed
        $record = $this->client->encodeRecord(FastCgiClient::FCGI_STDIN, 1, '12345678');
        $hdr = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved', $record);
        $this->assertIsArray($hdr);
        $this->assertSame(0, $hdr['paddingLength']);
        $this->assertSame(16, strlen($record));
    }

    public function testEncodeRecordEmptyBody(): void
    {
        $record = $this->client->encodeRecord(FastCgiClient::FCGI_PARAMS, 1, '');
        $this->assertSame(FastCgiClient::HEADER_LEN, strlen($record));
        $hdr = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved', $record);
        $this->assertIsArray($hdr);
        $this->assertSame(0, $hdr['contentLength']);
        $this->assertSame(0, $hdr['paddingLength']);
    }

    public function testEncodeRecordBodyTooLargeThrows(): void
    {
        $this->expectException(FastCgiException::class);
        $this->client->encodeRecord(FastCgiClient::FCGI_STDOUT, 1, str_repeat('X', 65536));
    }

    // ── recvExact via stream-socket pair ─────────────────────────────────────

    public function testRecvExactMalformedHeaderThrows(): void
    {
        // Simulate a connection that closes before delivering a full header
        // We use a real socket pair to drive recvExact
        [$a, $b] = $this->makeSocketPair();

        // Write only 4 bytes then close — recvExact(8) must throw
        fwrite($a, "\x01\x06\x00\x01"); // partial header
        fclose($a);

        $conn = $this->wrapSocket($b);

        $this->expectException(FastCgiException::class);
        $this->client->recvExact($conn, 8);
        fclose($b);
    }

    // ── Full response parse via parseStdout reflection ───────────────────────

    public function testParseStdoutBasic200(): void
    {
        $stdout = "Content-Type: text/html\r\n\r\n<html/>";
        $result = $this->invokeParseStdout($stdout, '', 0);
        $this->assertSame(200, $result['status']);
        $this->assertContains(['Content-Type', 'text/html'], $result['headers']);
        $this->assertSame('<html/>', $result['body']);
        $this->assertSame('', $result['stderr']);
    }

    public function testParseStdoutStatusHeader(): void
    {
        // php-fpm emits Status: 302 Found — must be extracted and removed
        $stdout = "Status: 302 Found\r\nLocation: /new\r\n\r\n";
        $result = $this->invokeParseStdout($stdout, '', 0);
        $this->assertSame(302, $result['status']);
        $this->assertNotContains('Status', array_column($result['headers'], 0));
        $this->assertContains(['Location', '/new'], $result['headers']);
    }

    public function testParseStdoutStatus404(): void
    {
        $stdout = "Status: 404 Not Found\r\nContent-Type: text/plain\r\n\r\nNot found";
        $result = $this->invokeParseStdout($stdout, '', 0);
        $this->assertSame(404, $result['status']);
        $this->assertSame('Not found', $result['body']);
    }

    public function testParseStdoutLfOnlyLineEnding(): void
    {
        // LF-only separators (legacy CGI)
        $stdout = "Content-Type: text/plain\nX-Foo: bar\n\nbody text";
        $result = $this->invokeParseStdout($stdout, '', 0);
        $this->assertSame(200, $result['status']);
        $this->assertContains(['X-Foo', 'bar'], $result['headers']);
        $this->assertSame('body text', $result['body']);
    }

    public function testParseStdoutStderrPassthrough(): void
    {
        $stdout = "Content-Type: text/html\r\n\r\n";
        $stderr = "PHP Fatal error: something went wrong";
        $result = $this->invokeParseStdout($stdout, $stderr, 1);
        $this->assertSame($stderr, $result['stderr']);
    }

    public function testParseStdoutNoBlankLineTreatsAllAsBody(): void
    {
        // No blank line → entire stdout returned as body with status 200
        $stdout = "This is not a header block";
        $result = $this->invokeParseStdout($stdout, '', 0);
        $this->assertSame(200, $result['status']);
        $this->assertSame($stdout, $result['body']);
    }

    public function testParseStdoutMultipleHeaders(): void
    {
        $stdout = "Content-Type: application/json\r\nX-Custom: value\r\nSet-Cookie: id=1\r\n\r\n{\"ok\":true}";
        $result = $this->invokeParseStdout($stdout, '', 0);
        $this->assertSame(200, $result['status']);
        $this->assertContains(['Content-Type', 'application/json'], $result['headers']);
        $this->assertContains(['X-Custom', 'value'], $result['headers']);
        $this->assertSame('{"ok":true}', $result['body']);
    }

    // ── Full wire-level test via socket pair ─────────────────────────────────

    public function testFullRequestResponseViaMockServer(): void
    {
        // Build a fake server response: STDOUT record + END_REQUEST record
        $responseHeaders = "Content-Type: text/plain\r\nStatus: 200 OK\r\n\r\n";
        $responseBody    = "Hello from php-fpm";
        $stdout          = $responseHeaders . $responseBody;

        // Build STDOUT record
        $stdoutRecord = $this->client->encodeRecord(FastCgiClient::FCGI_STDOUT, 1, $stdout);
        // Empty STDOUT terminator
        $stdoutEnd    = $this->client->encodeRecord(FastCgiClient::FCGI_STDOUT, 1, '');
        // END_REQUEST record: appStatus=0, protocolStatus=0
        $endBody      = pack('NCC', 0, 0, 0) . "\x00\x00\x00"; // 8 bytes
        $endRecord    = $this->client->encodeRecord(FastCgiClient::FCGI_END_REQUEST, 1, $endBody);

        $serverPayload = $stdoutRecord . $stdoutEnd . $endRecord;

        [$a, $b] = $this->makeSocketPair();
        fwrite($a, $serverPayload);
        fclose($a);

        $conn   = $this->wrapSocket($b);
        $result = $this->invokeReadResponse($conn, 1);
        fclose($b);

        $this->assertSame(200, $result['status']);
        $this->assertContains(['Content-Type', 'text/plain'], $result['headers']);
        $this->assertSame($responseBody, $result['body']);
    }

    public function testStderrCollectedInResponse(): void
    {
        $stderrContent = "Warning: something";
        $stderrRecord  = $this->client->encodeRecord(FastCgiClient::FCGI_STDERR, 1, $stderrContent);
        $stdoutContent = "Content-Type: text/html\r\n\r\nok";
        $stdoutRecord  = $this->client->encodeRecord(FastCgiClient::FCGI_STDOUT, 1, $stdoutContent);
        $stdoutEnd     = $this->client->encodeRecord(FastCgiClient::FCGI_STDOUT, 1, '');
        $endBody       = pack('NCC', 0, 0, 0) . "\x00\x00\x00";
        $endRecord     = $this->client->encodeRecord(FastCgiClient::FCGI_END_REQUEST, 1, $endBody);

        [$a, $b] = $this->makeSocketPair();
        fwrite($a, $stderrRecord . $stdoutRecord . $stdoutEnd . $endRecord);
        fclose($a);

        $conn   = $this->wrapSocket($b);
        $result = $this->invokeReadResponse($conn, 1);
        fclose($b);

        $this->assertSame($stderrContent, $result['stderr']);
        $this->assertSame('ok', $result['body']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Decode raw NV-pair bytes back to associative array for round-trip test.
     *
     * @return array<string,string>
     */
    private function decodeParams(string $data): array
    {
        $result = [];
        $pos    = 0;
        $len    = strlen($data);

        while ($pos < $len) {
            [$nameLen, $pos] = $this->readLength($data, $pos);
            [$valLen,  $pos] = $this->readLength($data, $pos);
            $name  = substr($data, $pos, $nameLen);
            $pos  += $nameLen;
            $value = substr($data, $pos, $valLen);
            $pos  += $valLen;
            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * @return array{int,int}
     */
    private function readLength(string $data, int $pos): array
    {
        $first = ord($data[$pos]);
        if (($first & 0x80) === 0) {
            return [$first, $pos + 1];
        }
        $unpacked = unpack('N', substr($data, $pos, 4));
        $val = ($unpacked !== false) ? ($unpacked[1] & 0x7fffffff) : 0;
        return [$val, $pos + 4];
    }

    /**
     * @return array{resource,resource}
     */
    private function makeSocketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->fail('stream_socket_pair() failed');
        }
        return [$pair[0], $pair[1]];
    }

    /**
     * Wrap a PHP stream resource in an {@see FcgiTransport} that reads from the
     * stream. `fread($stream, $maxLen)` honours the `recv()` "return at most
     * $maxLen bytes" contract, so the FCGI record framing reads exact lengths
     * (8-byte header, then content) without a real socket.
     *
     * @param resource $stream
     */
    private function wrapSocket(mixed $stream): FcgiTransport
    {
        return new class($stream) implements FcgiTransport {
            /** @param resource $stream */
            public function __construct(private mixed $stream)
            {
            }
            public function connect(): void
            {
            }
            public function send(string $data): void
            {
            }
            public function recv(int $maxLen): string
            {
                if ($maxLen < 1 || !is_resource($this->stream) || feof($this->stream)) {
                    return '';
                }
                $data = fread($this->stream, $maxLen);
                return ($data === false) ? '' : $data;
            }
            public function close(): void
            {
            }
        };
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string,stderr:string}
     */
    private function invokeParseStdout(string $stdout, string $stderr, int $appStatus): array
    {
        return $this->client->parseStdout($stdout, $stderr, $appStatus);
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string,stderr:string}
     */
    private function invokeReadResponse(FcgiTransport $conn, int $reqId): array
    {
        return $this->client->readResponse($conn, $reqId);
    }

    // ── #289 transport error paths (no socket needed) ────────────────────────

    public function testBlockingTransportRecvBeforeConnectThrows(): void
    {
        $t = new FcgiBlockingTransport('127.0.0.1', 9000, false, 5.0);
        $this->expectException(FastCgiException::class);
        $t->recv(8);
    }

    public function testBlockingTransportSendBeforeConnectThrows(): void
    {
        $t = new FcgiBlockingTransport('127.0.0.1', 9000, false, 5.0);
        $this->expectException(FastCgiException::class);
        $t->send('x');
    }

    public function testCoroutineTransportRecvBeforeConnectThrows(): void
    {
        $t = new FcgiCoroutineTransport('127.0.0.1', 9000, false, 5.0);
        $this->expectException(FastCgiException::class);
        $t->recv(8);
    }

    public function testCoroutineTransportSendBeforeConnectThrows(): void
    {
        $t = new FcgiCoroutineTransport('127.0.0.1', 9000, false, 5.0);
        $this->expectException(FastCgiException::class);
        $t->send('x');
    }

    public function testBlockingTransportConnectToDeadAddressThrows(): void
    {
        // Port 1 is reserved/unbound — connect must fail fast with a FastCgiException
        // (not a fatal). Validates the blocking connect error path (#289).
        $t = new FcgiBlockingTransport('127.0.0.1', 1, false, 1.0);
        $this->expectException(FastCgiException::class);
        $t->connect();
    }

    public function testBlockingTransportCloseIsIdempotent(): void
    {
        $t = new FcgiBlockingTransport('127.0.0.1', 9000, false, 5.0);
        $t->close(); // never connected — must not error
        $t->close();
        $this->expectNotToPerformAssertions();
    }

    // ── New error-path tests (Part B coverage boost) ─────────────────────────

    public function testFastCgiExceptionIsRuntimeException(): void
    {
        // FastCgiException must extend RuntimeException so callers can catch it
        // as a general runtime error. This is the contract cgiFcgi() relies on
        // when mapping connection failures to 502 responses.
        $e = new FastCgiException('FastCGI: cannot connect to 127.0.0.1:19999: connection refused');
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertStringContainsString('cannot connect', $e->getMessage());
    }

    public function testPartialFrameTruncationThrows(): void
    {
        // Write only 4 of the required 8 header bytes then close — recvExact must throw.
        [$a, $b] = $this->makeSocketPair();
        fwrite($a, "\x01\x06\x00\x01"); // 4 bytes, not a full FCGI header
        fclose($a);

        $conn = $this->wrapSocket($b);
        $this->expectException(FastCgiException::class);
        $this->client->recvExact($conn, 8);
        fclose($b);
    }

    public function testMalformedEndRequestProtocolStatusIgnored(): void
    {
        // END_REQUEST with a non-zero protocolStatus byte (e.g. 2 = UNKNOWN_ROLE).
        // The client must still set $done=true and return parsed output; it does
        // NOT throw — unknown protocol status is a soft signal per spec §5.5.
        $stdoutContent = "Content-Type: text/plain\r\n\r\nresult";
        $stdoutRecord  = $this->client->encodeRecord(FastCgiClient::FCGI_STDOUT, 1, $stdoutContent);
        $stdoutEnd     = $this->client->encodeRecord(FastCgiClient::FCGI_STDOUT, 1, '');
        // protocolStatus = 2 (UNKNOWN_ROLE) — non-zero but still well-formed END_REQUEST
        $endBody   = pack('NCC', 0, 2, 0) . "\x00\x00\x00";
        $endRecord = $this->client->encodeRecord(FastCgiClient::FCGI_END_REQUEST, 1, $endBody);

        [$a, $b] = $this->makeSocketPair();
        fwrite($a, $stdoutRecord . $stdoutEnd . $endRecord);
        fclose($a);

        $conn   = $this->wrapSocket($b);
        $result = $this->invokeReadResponse($conn, 1);
        fclose($b);

        $this->assertSame(200, $result['status']);
        $this->assertSame('result', $result['body']);
    }

    public function testAbortDuringParamsServerClosesMidStream(): void
    {
        // Simulate the server closing the connection in the middle of sending
        // STDOUT (partial FCGI header returned — connection closed).
        [$a, $b] = $this->makeSocketPair();

        // Write a valid STDOUT record then close mid-way through the next record
        $stdoutContent = "Content-Type: text/html\r\n\r\nhello";
        $stdoutRecord  = $this->client->encodeRecord(FastCgiClient::FCGI_STDOUT, 1, $stdoutContent);
        // Write only the first 4 bytes of the next record header, then close
        fwrite($a, $stdoutRecord . "\x01\x06\x00\x01");
        fclose($a);

        $conn = $this->wrapSocket($b);
        $this->expectException(FastCgiException::class);
        $this->invokeReadResponse($conn, 1);
        fclose($b);
    }

    public function testLargeStdinChunkedOver65535(): void
    {
        // A stdin body larger than MAX_CONTENT (65535) must be split into multiple
        // FCGI_STDIN records. Verify encodeParams + encodeRecord don't throw and
        // that the body is correctly chunked (each chunk ≤ MAX_CONTENT).
        $largeBody = str_repeat('X', FastCgiClient::MAX_CONTENT + 100); // 65635 bytes
        $chunks    = str_split($largeBody, FastCgiClient::MAX_CONTENT);
        $this->assertCount(2, $chunks);
        $this->assertSame(FastCgiClient::MAX_CONTENT, strlen($chunks[0]));
        $this->assertSame(100, strlen($chunks[1]));

        // Each chunk must encode without throwing (i.e. ≤ MAX_CONTENT)
        foreach ($chunks as $chunk) {
            $record = $this->client->encodeRecord(FastCgiClient::FCGI_STDIN, 1, $chunk);
            $hdr = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved', $record);
            $this->assertIsArray($hdr);
            $this->assertLessThanOrEqual(FastCgiClient::MAX_CONTENT, $hdr['contentLength']);
        }
    }

    public function testRecordLengthOverflowThrows(): void
    {
        // A single body > 65535 bytes cannot fit in one FCGI record — must throw.
        $this->expectException(FastCgiException::class);
        $this->expectExceptionMessageMatches('/too large/i');
        $this->client->encodeRecord(FastCgiClient::FCGI_STDIN, 1, str_repeat('Y', 65536));
    }

    public function testParseStdoutEmptyStdout(): void
    {
        // Empty STDOUT — no blank line, whole thing treated as body (empty string).
        $result = $this->invokeParseStdout('', '', 0);
        $this->assertSame(200, $result['status']);
        $this->assertSame('', $result['body']);
        $this->assertSame([], $result['headers']);
    }

    public function testParseStdoutHeaderWithoutColonIsSkipped(): void
    {
        // A header line without a colon must be silently ignored.
        $stdout = "Content-Type: text/plain\r\nBadLineWithoutColon\r\nX-Ok: yes\r\n\r\nbody";
        $result = $this->invokeParseStdout($stdout, '', 0);
        $this->assertContains(['X-Ok', 'yes'], $result['headers']);
        $this->assertNotContains('BadLineWithoutColon', array_column($result['headers'], 0));
        $this->assertSame('body', $result['body']);
    }

    // ── #289 transport SUCCESS paths over real sockets ───────────────────────

    /**
     * Exercise the full {@see FcgiBlockingTransport} lifecycle — connect, send,
     * recv, clean-EOF, and close — against a real loopback TCP server. This is the
     * path used OUTSIDE a coroutine (legacy-cgi / superglobals(true)); a blocking
     * socket has no scheduler to deadlock (#289). Runs synchronously: the kernel
     * completes the TCP handshake into the listen backlog, so connect() returns
     * before we accept() server-side.
     */
    public function testBlockingTransportFullLifecycleOverLoopback(): void
    {
        $this->assertLessThan(0, \OpenSwoole\Coroutine::getCid(), 'precondition: outside a coroutine');

        $errno  = 0;
        $errstr = '';
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "server listen failed: {$errstr} ({$errno})");
        $name = stream_socket_get_name($server, false);
        $this->assertIsString($name);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port);

        $t = new FcgiBlockingTransport('127.0.0.1', $port, false, 2.0);
        $t->connect(); // kernel completes handshake into the accept backlog

        // maxLen < 1 short-circuits without touching the socket
        $this->assertSame('', $t->recv(0));

        $peer = stream_socket_accept($server, 2);
        $this->assertNotFalse($peer, 'accept failed');

        // client → server
        $t->send('PING-FROM-CLIENT');
        $this->assertSame('PING-FROM-CLIENT', fread($peer, 4096));

        // server → client
        fwrite($peer, 'PONG-FROM-SERVER');
        $this->assertSame('PONG-FROM-SERVER', $t->recv(4096));

        // clean EOF: server closes → recv() returns '' (NOT a timeout throw)
        fclose($peer);
        usleep(30_000); // let the FIN land before the EOF read
        $this->assertSame('', $t->recv(4096));

        $t->close();
        $t->close(); // idempotent
        fclose($server);
    }

    /**
     * Exercise the full {@see FcgiCoroutineTransport} lifecycle inside a coroutine
     * — connect, send, and the read-ahead BUFFER slicing in recv() — against a real
     * loopback server. This is the #289 fix: `OpenSwoole\Coroutine\Client::recv()`
     * returns ALL available bytes at once (its arg is a timeout, not a length), so
     * the transport buffers the whole response and hands back at most $maxLen per
     * call. Two recv() calls (8-byte header then body) must reconstruct the exact
     * bytes — proving the body isn't swallowed by recvExact(8) (the original bug).
     */
    public function testCoroutineTransportBufferSlicingOverLoopback(): void
    {
        $errno  = 0;
        $errstr = '';
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "server listen failed: {$errstr} ({$errno})");
        $name = stream_socket_get_name($server, false);
        $this->assertIsString($name);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port);

        // A response big enough to prove multi-call slicing out of one recv().
        $payload     = 'HEADER01' . str_repeat('B', 4096);
        $reconstruct = '';
        $sentRequest = '';

        \OpenSwoole\Coroutine::run(function () use ($server, $port, $payload, &$reconstruct, &$sentRequest): void {
            $t = new FcgiCoroutineTransport('127.0.0.1', $port, false, 2.0);
            $t->connect(); // Coroutine\Client connect — handshake via backlog

            $peer = stream_socket_accept($server, 1); // ready from backlog → returns immediately
            if ($peer === false) {
                return;
            }
            stream_set_timeout($peer, 1);

            $t->send('REQUEST-BYTES');           // client → server
            $sentRequest = (string) fread($peer, 4096); // data already buffered → no block

            fwrite($peer, $payload);             // server → client (whole response at once)
            fclose($peer);                       // data delivered before FIN

            // First recv refills the buffer with the WHOLE payload, returns 8 bytes;
            // second recv slices the remainder out of the buffer (no socket read).
            $head        = $t->recv(8);
            $rest        = $t->recv(strlen($payload));
            $reconstruct = $head . $rest;

            $t->close();
        });

        $this->assertSame('REQUEST-BYTES', $sentRequest, 'server must have received the client send()');
        $this->assertSame($payload, $reconstruct, 'recv() buffer slicing must reconstruct the exact response (#289)');
        fclose($server);
    }

    /**
     * End-to-end {@see FastCgiClient::request()} round-trip inside a coroutine
     * against a mock FCGI server. Covers the transport selection in connect()
     * (coroutine branch → {@see FcgiCoroutineTransport}) plus the BEGIN_REQUEST /
     * PARAMS / STDIN send helpers and the full readResponse() loop — the complete
     * #289 dispatch path. HOOK_ALL is enabled so the raw-socket mock server yields
     * cooperatively under the scheduler, then restored so other tests are unaffected.
     */
    public function testFullRequestRoundTripInsideCoroutine(): void
    {
        $result = null;

        // HOOK_ALL so the raw-socket mock server yields cooperatively; the listener
        // MUST be created inside the hooked coroutine region and set non-blocking,
        // else the hooked stream_socket_accept() can't see the coroutine client's
        // connection. Restored to false afterwards so other tests are unaffected.
        \OpenSwoole\Runtime::enableCoroutine(\OpenSwoole\Runtime::HOOK_ALL);
        try {
            \OpenSwoole\Coroutine::run(function () use (&$result): void {
                $errno  = 0;
                $errstr = '';
                $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
                if ($server === false) {
                    return;
                }
                stream_set_blocking($server, false);
                $name = stream_socket_get_name($server, false);
                $port = is_string($name) ? (int) substr($name, (int) strrpos($name, ':') + 1) : 0;

                $client = new FastCgiClient("127.0.0.1:{$port}", 3);

                // Canned FCGI response: STDOUT (headers + body) + empty STDOUT + END_REQUEST.
                $stdout    = "Content-Type: text/plain\r\nStatus: 200 OK\r\n\r\nROUNDTRIP-BODY";
                $response  = $client->encodeRecord(FastCgiClient::FCGI_STDOUT, 1, $stdout);
                $response .= $client->encodeRecord(FastCgiClient::FCGI_STDOUT, 1, '');
                $response .= $client->encodeRecord(FastCgiClient::FCGI_END_REQUEST, 1, pack('NCC', 0, 0, 0) . "\x00\x00\x00");

                go(function () use ($server, $response): void {
                    $peer = @stream_socket_accept($server, 3); // hooked → yields
                    if ($peer === false) {
                        return;
                    }
                    fread($peer, 8192);       // drain the client's request bytes
                    fwrite($peer, $response); // hooked → yields
                    fclose($peer);
                });
                go(function () use ($client, &$result): void {
                    $result = $client->request(
                        ['SCRIPT_FILENAME' => '/tmp/index.php', 'REQUEST_METHOD' => 'GET'],
                        ''
                    );
                });
            });
        } finally {
            \OpenSwoole\Runtime::enableCoroutine(false); // restore: don't leak hooks into other tests
        }

        $this->assertIsArray($result);
        $this->assertSame(200, $result['status']);
        $this->assertSame('ROUNDTRIP-BODY', $result['body']);
        $this->assertContains(['Content-Type', 'text/plain'], $result['headers']);
    }

    /**
     * A timeout <= 0 means "no explicit socket timeout" — connect() falls back to
     * the `default_socket_timeout` ini value and skips stream_set_timeout().
     */
    public function testBlockingTransportZeroTimeoutUsesIniDefault(): void
    {
        $errno  = 0;
        $errstr = '';
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "server listen failed: {$errstr} ({$errno})");
        $name = stream_socket_get_name($server, false);
        $this->assertIsString($name);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $t = new FcgiBlockingTransport('127.0.0.1', $port, false, 0.0); // <= 0 → ini default
        $t->connect();
        $t->close();
        fclose($server);
        $this->assertTrue(true, 'connect() succeeded with the ini-default socket timeout');
    }

    /**
     * Cover the {@see FcgiCoroutineTransport} UNIX-socket connect branch, the
     * recv(maxLen < 1) short-circuit, and the clean-EOF path (peer closes without
     * sending → recv() returns '').
     */
    public function testCoroutineTransportUnixSocketAndEofPaths(): void
    {
        $sockPath  = sys_get_temp_dir() . '/zealfcgi_' . getmypid() . '.sock';
        @unlink($sockPath);
        $emptyRecv = null;
        $eofRecv   = null;

        \OpenSwoole\Runtime::enableCoroutine(\OpenSwoole\Runtime::HOOK_ALL);
        try {
            \OpenSwoole\Coroutine::run(function () use ($sockPath, &$emptyRecv, &$eofRecv): void {
                $errno  = 0;
                $errstr = '';
                $server = stream_socket_server('unix://' . $sockPath, $errno, $errstr);
                if ($server === false) {
                    return;
                }
                stream_set_blocking($server, false);

                $t = new FcgiCoroutineTransport($sockPath, 0, true, 2.0); // isUnix=true
                $t->connect();

                $emptyRecv = $t->recv(0); // maxLen < 1 → '' without touching the socket

                go(function () use ($server): void {
                    $peer = @stream_socket_accept($server, 2);
                    if ($peer !== false) {
                        fclose($peer); // close WITHOUT sending → client sees a clean EOF
                    }
                });
                $eofRecv = $t->recv(8); // buffer empty, peer closed → '' (clean EOF)
                $t->close();
            });
        } finally {
            \OpenSwoole\Runtime::enableCoroutine(false);
            @unlink($sockPath);
        }

        $this->assertSame('', $emptyRecv, 'recv(0) must short-circuit to empty string');
        $this->assertSame('', $eofRecv, 'recv() on a closed peer must return empty string (clean EOF)');
    }
}
