<?php

declare(strict_types=1);

namespace ZealPHP\CGI;

/**
 * Length-prefixed JSON framing for the native FCGI-style worker pool.
 *
 * Wire format (symmetric, parent <-> child):
 *   [4 bytes: uint32 big-endian length]
 *   [N bytes: JSON-encoded payload]
 *
 * Sanity cap: 64 MB per frame — protects against runaway corrupted-length
 * reads draining all memory.
 */
final class IPC
{
    /** Hard ceiling on a single frame body size (bytes). */
    public const MAX_FRAME_BYTES = 64 * 1024 * 1024;

    /**
     * Write a length-prefixed JSON frame to an open stream.
     *
     * @param resource           $fp      Writable stream.
     * @param array<mixed,mixed> $payload Anything json_encode-able.
     */
    public static function writeFrame($fp, array $payload): void
    {
        fwrite($fp, self::encodeFrame($payload));
        fflush($fp);
    }

    /**
     * Encode a payload as one length-prefixed JSON frame (header + body) without
     * writing it, so a caller can push it through a non-blocking pipe itself.
     *
     * Encoding falls back in steps rather than dropping the payload: a binary
     * `body` is base64-encoded first (byte-exact round trip); any remaining
     * invalid UTF-8 (a raw byte in a query string, cookie or header) is
     * substituted with U+FFFD so the request still goes through. Only a
     * payload that is unencodable for another reason (a resource, recursion)
     * becomes the synthetic 500 frame.
     *
     * @param array<mixed,mixed> $payload Anything json_encode-able.
     */
    public static function encodeFrame(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            if (isset($payload['body']) && is_string($payload['body'])) {
                $payload['body'] = base64_encode($payload['body']);
                $payload['body_encoding'] = 'base64';
            }
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if ($json === false) {
            $json = (string) json_encode([
                'status'  => 500,
                'body'    => 'IPC: json_encode failed: ' . json_last_error_msg(),
                'headers' => [],
                'cookies' => [],
            ], JSON_UNESCAPED_SLASHES);
        }
        return pack('N', strlen($json)) . $json;
    }

    /**
     * Read one length-prefixed JSON frame.
     *
     * @param resource $fp Readable stream.
     * @param float $timeout Max seconds to wait (default 30).
     * @return array<mixed,mixed>|null Null on EOF, timeout, or framing error.
     */
    public static function readFrame($fp, float $timeout = 30.0): ?array
    {
        $hdr = self::readExact($fp, 4, $timeout);
        if ($hdr === null) {
            return null;
        }
        /** @var array{1:int} $u */
        $u   = unpack('N', $hdr);
        $len = $u[1];
        if ($len <= 0 || $len > self::MAX_FRAME_BYTES) {
            return null;
        }
        $body = self::readExact($fp, $len, $timeout);
        if ($body === null) {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Read exactly $n bytes from a stream with timeout.
     *
     * @param resource $fp
     * @param positive-int $n
     * @param float $timeout Max seconds to wait.
     */
    private static function readExact($fp, int $n, float $timeout = 30.0): ?string
    {
        $out = '';
        $deadline = microtime(true) + $timeout;
        while (strlen($out) < $n) {
            $remaining = $n - strlen($out);
            if ($remaining < 1) {
                break;
            }
            $chunk = fread($fp, $remaining);
            if ($chunk !== false && $chunk !== '') {
                $out .= $chunk;
                continue;
            }
            if (feof($fp)) {
                return null;
            }
            if (microtime(true) >= $deadline) {
                return null;
            }
            usleep(10000); // 10ms yield
        }

        return $out;
    }
}
