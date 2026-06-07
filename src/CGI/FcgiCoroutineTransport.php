<?php

declare(strict_types=1);

namespace ZealPHP\CGI;

use OpenSwoole\Coroutine\Client as CoClient;

/**
 * Coroutine-yielding FastCGI transport (#289) — `OpenSwoole\Coroutine\Client`.
 *
 * Every `send()` / `recv()` yields under the OpenSwoole event loop, so the worker
 * is never blocked. Used ONLY when the dispatch already runs inside a coroutine
 * (`Coroutine::getCid() >= 0`) — e.g. an fcgi backend reached from a coroutine-mode
 * request. Outside a coroutine, {@see FcgiBlockingTransport} is used instead (the
 * coroutine client cannot even be constructed at `getCid() < 0`).
 */
final class FcgiCoroutineTransport implements FcgiTransport
{
    private ?CoClient $conn = null;

    /**
     * Read-ahead buffer. `OpenSwoole\Coroutine\Client::recv()` returns ALL
     * available bytes (its argument is a timeout, not a length), so a small fast
     * response arrives in one segment. The FCGI framing reads EXACT lengths
     * (8-byte header, then content), so we buffer what `recv()` returns and hand
     * back at most the requested count — otherwise `recvExact(8)` for the header
     * would swallow the whole response and the body would be lost (#289).
     */
    private string $buffer = '';

    public function __construct(
        private string $host,
        private int $port,
        private bool $isUnix,
        private float $timeout,
    ) {
    }

    public function connect(): void
    {
        $conn = $this->isUnix
            ? new CoClient(SWOOLE_UNIX_STREAM)
            : new CoClient(SWOOLE_SOCK_TCP);

        $ok = $this->isUnix
            ? $conn->connect($this->host, 0, $this->timeout)
            : $conn->connect($this->host, $this->port, $this->timeout);

        if (!$ok) {
            $errMsg = is_string($conn->errMsg) ? $conn->errMsg : 'unknown error';
            $target = $this->isUnix ? "unix:{$this->host}" : "{$this->host}:{$this->port}";
            throw new FastCgiException("FastCGI: cannot connect to {$target}: {$errMsg}");
        }

        $this->conn = $conn;
    }

    public function send(string $data): void
    {
        if ($this->conn === null) {
            throw new FastCgiException('FastCGI: send before connect');
        }
        $result = $this->conn->send($data);
        if ($result === false) {
            $errMsg = is_string($this->conn->errMsg) ? $this->conn->errMsg : 'unknown error';
            throw new FastCgiException("FastCGI: send failed: {$errMsg}");
        }
    }

    public function recv(int $maxLen): string
    {
        if ($this->conn === null) {
            throw new FastCgiException('FastCGI: recv before connect');
        }
        if ($maxLen < 1) {
            return '';
        }
        // Refill the buffer from the socket only when empty. recv()'s argument is
        // the TIMEOUT in seconds (not a byte count) — it returns whatever is
        // available, possibly the whole response at once.
        if ($this->buffer === '') {
            $chunk = $this->conn->recv((float) $this->timeout);
            if ($chunk === false) {
                $errMsg = is_string($this->conn->errMsg) ? $this->conn->errMsg : 'connection error';
                throw new FastCgiException("FastCGI: recv failed: {$errMsg}");
            }
            if (!is_string($chunk) || $chunk === '') {
                return ''; // clean EOF — recvExact treats this as connection closed
            }
            $this->buffer = $chunk;
        }
        $out          = substr($this->buffer, 0, $maxLen);
        $this->buffer = substr($this->buffer, $maxLen);
        return $out;
    }

    public function close(): void
    {
        if ($this->conn !== null) {
            $this->conn->close();
            $this->conn = null;
        }
    }
}
