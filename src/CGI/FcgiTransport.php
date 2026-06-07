<?php

declare(strict_types=1);

namespace ZealPHP\CGI;

/**
 * Socket transport for {@see FastCgiClient} (#289).
 *
 * The FastCGI protocol framing in `FastCgiClient` is transport-agnostic — only
 * three operations touch the socket: connect, send, and receive. This interface
 * abstracts those three so the client can run over EITHER:
 *
 *  - {@see FcgiCoroutineTransport} — `OpenSwoole\Coroutine\Client`; yields under
 *    the event loop. Used when already inside a coroutine (`getCid() >= 0`).
 *  - {@see FcgiBlockingTransport} — plain `stream_socket_client` with blocking
 *    `fread`/`fwrite`. Used when OUTSIDE a coroutine (`getCid() < 0`), e.g. the
 *    `legacy-cgi` / `superglobals(true)` lifecycles where the request handler is
 *    NOT coroutine-wrapped.
 *
 * Why this exists: #261 fixed an "API must be called in the coroutine" fatal by
 * wrapping the coroutine client in `Coroutine::run()` when outside a coroutine.
 * But that nests a fresh scheduler INSIDE the OpenSwoole reactor callback — the
 * reactor is parked waiting for the scheduler, which needs the reactor to deliver
 * the socket-readable event, so the FCGI read never completes and every request
 * HANGS until `cgi_timeout` (#289). A blocking socket has no scheduler to deadlock,
 * which is the semantically correct behaviour for the synchronous, one-request-at-
 * a-time `legacy-cgi` worker.
 */
interface FcgiTransport
{
    /**
     * Open the connection to the FastCGI backend.
     *
     * @throws FastCgiException on connect failure or timeout.
     */
    public function connect(): void;

    /**
     * Send all bytes to the backend.
     *
     * @throws FastCgiException on a write error.
     */
    public function send(string $data): void;

    /**
     * Receive up to `$maxLen` bytes. Returns `''` on a clean EOF (peer closed).
     *
     * @throws FastCgiException on a hard socket error or timeout.
     */
    public function recv(int $maxLen): string;

    /** Close the connection (idempotent). */
    public function close(): void;
}
