<?php

declare(strict_types=1);

namespace ZealPHP\CGI;

/**
 * Blocking FastCGI transport (#289) — plain `stream_socket_client` + blocking
 * `fread`/`fwrite`.
 *
 * Used when the dispatch runs OUTSIDE a coroutine (`Coroutine::getCid() < 0`) —
 * the `legacy-cgi` / `superglobals(true)` lifecycles, where the OpenSwoole worker
 * handles one request at a time synchronously and the request handler is NOT
 * coroutine-wrapped. A blocking socket has no coroutine scheduler to deadlock,
 * which is the correct behaviour there: it replaces #261's nested
 * `Coroutine::run()` that hung every request (#289).
 *
 * Outside a coroutine, OpenSwoole's runtime hooks are inactive, so these
 * `fread`/`fwrite` calls are ordinary blocking syscalls — no event loop involved.
 * The socket timeout (`stream_set_timeout`) bounds a wedged backend so a stuck
 * php-fpm can't pin the worker forever.
 */
final class FcgiBlockingTransport implements FcgiTransport
{
    /** @var resource|null */
    private $sock = null;

    public function __construct(
        private string $host,
        private int $port,
        private bool $isUnix,
        private float $timeout,
    ) {
    }

    public function connect(): void
    {
        $url    = $this->isUnix ? 'unix://' . $this->host : "tcp://{$this->host}:{$this->port}";
        $errno  = 0;
        $errstr = '';
        // Positive timeout bounds connect; <= 0 means "no timeout" (use the ini default).
        $connectTimeout = $this->timeout > 0
            ? $this->timeout
            : (float) (ini_get('default_socket_timeout') ?: 60);

        $sock = @stream_socket_client($url, $errno, $errstr, $connectTimeout, STREAM_CLIENT_CONNECT);
        if ($sock === false) {
            $target = $this->isUnix ? "unix:{$this->host}" : "{$this->host}:{$this->port}";
            $detail = $errstr !== '' ? $errstr : "errno {$errno}";
            throw new FastCgiException("FastCGI: cannot connect to {$target}: {$detail}");
        }

        stream_set_blocking($sock, true);
        if ($this->timeout > 0) {
            $sec  = (int) $this->timeout;
            $usec = (int) (($this->timeout - $sec) * 1_000_000);
            stream_set_timeout($sock, $sec, $usec);
        }
        $this->sock = $sock;
    }

    public function send(string $data): void
    {
        if (!is_resource($this->sock)) {
            throw new FastCgiException('FastCGI: send before connect');
        }
        $len     = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($this->sock, substr($data, $written));
            if ($n === false || $n === 0) {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out'])) {
                    throw new FastCgiException('FastCGI: send timed out');
                }
                throw new FastCgiException('FastCGI: send failed');
            }
            $written += $n;
        }
    }

    public function recv(int $maxLen): string
    {
        if (!is_resource($this->sock)) {
            throw new FastCgiException('FastCGI: recv before connect');
        }
        if ($maxLen < 1) {
            return ''; // nothing to read (callers never request < 1 — recvExact loops on remaining > 0)
        }
        $chunk = @fread($this->sock, $maxLen);
        if ($chunk === false) {
            throw new FastCgiException('FastCGI: recv failed');
        }
        if ($chunk === '') {
            // fread returns '' on BOTH a read timeout and a clean EOF — disambiguate.
            $meta = stream_get_meta_data($this->sock);
            if (!empty($meta['timed_out'])) {
                throw new FastCgiException('FastCGI: recv timed out');
            }
            return ''; // clean EOF — recvExact treats this as connection closed
        }
        return $chunk;
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            fclose($this->sock);
        }
        $this->sock = null;
    }
}
