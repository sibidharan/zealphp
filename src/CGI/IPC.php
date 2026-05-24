<?php

declare(strict_types=1);

namespace ZealPHP\CGI;

/**
 * SPIKE — Length-prefixed JSON framing for the native FCGI-style worker pool.
 *
 * Wire format (symmetric, parent <-> child):
 *   [4 bytes: uint32 big-endian length]
 *   [N bytes: JSON-encoded payload]
 *
 * Same shape as `cgiFork()` already uses for IPC over OpenSwoole\Process pipes.
 * Extracted to a class so PoolWorker (child) and WorkerPool (parent) share
 * one definition and the framing can be unit-tested in isolation.
 *
 * Sanity cap: 64 MB per frame — protects against runaway corrupted-length
 * reads draining all memory. Adjust if real payloads ever exceed that.
 */
final class IPC
{
    /** Hard ceiling on a single frame body size (bytes). */
    public const MAX_FRAME_BYTES = 64 * 1024 * 1024;

    /**
     * Write a length-prefixed JSON frame to an open stream.
     *
     * @param resource           $fp      Writable stream (parent stdin pipe to child, or child STDOUT).
     * @param array<mixed,mixed> $payload Anything json_encode-able.
     */
    public static function writeFrame($fp, array $payload): void
    {
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $hdr  = pack('N', strlen($json));
        fwrite($fp, $hdr . $json);
        fflush($fp);
    }

    /**
     * Read one length-prefixed JSON frame.
     *
     * @param resource $fp Readable stream.
     * @return array<mixed,mixed>|null Null on EOF or framing error.
     */
    public static function readFrame($fp): ?array
    {
        $hdr = self::readExact($fp, 4);
        if ($hdr === null) {
            return null;
        }
        /** @var array{1:int} $u */
        $u   = unpack('N', $hdr);
        $len = $u[1];
        if ($len <= 0 || $len > self::MAX_FRAME_BYTES) {
            return null;
        }
        $body = self::readExact($fp, $len);
        if ($body === null) {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Read exactly $n bytes from a stream. Handles short reads by looping
     * until the full count arrives. Returns null on EOF or stream error.
     *
     * @param resource $fp
     * @param positive-int $n
     */
    private static function readExact($fp, int $n): ?string
    {
        $out = '';
        while (strlen($out) < $n) {
            $remaining = $n - strlen($out);
            if ($remaining < 1) {
                break;
            }
            $chunk = fread($fp, $remaining);
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $out .= $chunk;
        }

        return $out;
    }
}
