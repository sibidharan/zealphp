<?php

declare(strict_types=1);

namespace ZealPHP\Legacy;

use OpenSwoole\Coroutine\Client as CoClient;

/**
 * FastCGI 1.0 `RESPONDER` client for ZealPHP's legacy-include dispatch path.
 *
 * Implements the FCGI binary protocol (`BEGIN_REQUEST` → `PARAMS` → `STDIN` →
 * `STDOUT` + `STDERR` + `END_REQUEST`) over an OpenSwoole coroutine socket so the
 * call never blocks the event loop.
 *
 * Protocol: https://fastcgi-devkit.org/doc/FCGI_Spec.html
 * Reference implementations: `mod_proxy_fcgi.c`, `ngx_http_fastcgi_module.c`
 */
final class FastCgiClient
{
    // ── Record types (FCGI 1.0 §2.2) ────────────────────────────────────────
    public const FCGI_VERSION           = 1;
    public const FCGI_BEGIN_REQUEST     = 1;
    public const FCGI_ABORT_REQUEST     = 2;
    public const FCGI_END_REQUEST       = 3;
    public const FCGI_PARAMS            = 4;
    public const FCGI_STDIN             = 5;
    public const FCGI_STDOUT            = 6;
    public const FCGI_STDERR            = 7;
    public const FCGI_GET_VALUES        = 9;
    public const FCGI_GET_VALUES_RESULT = 10;

    // ── Role / flag constants ────────────────────────────────────────────────
    public const FCGI_RESPONDER = 1;
    public const FCGI_KEEP_CONN = 1;

    // ── Framing limits ──────────────────────────────────────────────────────
    public const MAX_CONTENT = 65535;
    public const HEADER_LEN  = 8;

    private string $address;
    private int $timeout;

    /**
     * @param string $address  TCP `"host:port"` or Unix `"unix:/path/to/fpm.sock"`
     * @param int    $timeout  Socket timeout in seconds (`0` = no timeout)
     */
    public function __construct(
        string $address = '127.0.0.1:9000',
        int $timeout = 30,
    ) {
        $this->address = $address;
        $this->timeout = $timeout;
    }

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Dispatch one FCGI `RESPONDER` request and return parsed response data.
     *
     * @param array<string,string> $params    FCGI `PARAMS` (CGI environment variables).
     * @param string               $stdinBody Request body (`POST` data).
     * @return array{status:int,headers:array<string,string>,body:string,stderr:string}
     * @throws FastCgiException on protocol error, timeout, or connection failure.
     */
    public function request(array $params, string $stdinBody = ''): array
    {
        $reqId = 1; // one connection per request — nginx model
        $conn  = $this->connect();

        try {
            $this->sendBeginRequest($conn, $reqId);
            $this->sendParams($conn, $reqId, $params);
            $this->sendStdin($conn, $reqId, $stdinBody);

            return $this->readResponse($conn, $reqId);
        } catch (FastCgiException $e) {
            $this->sendAbort($conn, $reqId);
            throw $e;
        } finally {
            $conn->close();
        }
    }

    // ── Connection ───────────────────────────────────────────────────────────

    private function connect(): CoClient
    {
        if (str_starts_with($this->address, 'unix:')) {
            $conn = new CoClient(SWOOLE_UNIX_STREAM);
            $path = substr($this->address, 5);
            $ok   = $conn->connect($path, 0, (float) $this->timeout);
        } else {
            $conn = new CoClient(SWOOLE_SOCK_TCP);
            $parts = explode(':', $this->address, 2);
            $host  = $parts[0];
            $port  = isset($parts[1]) ? (int) $parts[1] : 9000;
            $ok    = $conn->connect($host, $port, (float) $this->timeout);
        }

        if (!$ok) {
            $errMsg = is_string($conn->errMsg) ? $conn->errMsg : 'unknown error';
            throw new FastCgiException(
                "FastCGI: cannot connect to {$this->address}: {$errMsg}"
            );
        }

        return $conn;
    }

    // ── Record framing (send side) ───────────────────────────────────────────

    /**
     * Build a complete FCGI record: 8-byte header + body + alignment padding.
     *
     * Header layout (FCGI 1.0 §2.1):
     *   `version(1) type(1) requestIdB1(1) requestIdB0(1)`
     *   `contentLengthB1(1) contentLengthB0(1) paddingLength(1) reserved(1)`
     */
    public function encodeRecord(int $type, int $reqId, string $body): string
    {
        $contentLen = strlen($body);

        if ($contentLen > self::MAX_CONTENT) {
            throw new FastCgiException("FCGI body too large: {$contentLen} bytes");
        }

        // Pad to 8-byte boundary (mod_proxy_fcgi.c alignment behaviour)
        $paddingLen = (8 - ($contentLen % 8)) % 8;
        $padding    = str_repeat("\x00", $paddingLen);

        return pack(
            'CCnnCC',
            self::FCGI_VERSION,
            $type,
            $reqId,
            $contentLen,
            $paddingLen,
            0  // reserved
        ) . $body . $padding;
    }

    /**
     * Send a `BEGIN_REQUEST` record (`role=RESPONDER`, `flags=0` — no keep-conn).
     *
     * Body layout: `roleB1(1) roleB0(1) flags(1) reserved×5`
     * Source: `mod_proxy_fcgi.c:321-339`, FCGI spec §5.1
     */
    private function sendBeginRequest(CoClient $conn, int $reqId): void
    {
        $body = pack(
            'nCCCCCC',
            self::FCGI_RESPONDER,
            0, // flags: no KEEP_CONN (Apache default: backend->close = 1)
            0, 0, 0, 0, 0 // reserved
        );
        $this->sendRaw($conn, $this->encodeRecord(self::FCGI_BEGIN_REQUEST, $reqId, $body));
    }

    /**
     * Encode and send `PARAMS` records followed by empty `PARAMS` terminator.
     *
     * Multiple NV-pairs concatenate into one record body; fragments if total
     * exceeds `MAX_CONTENT`.
     * Source: `mod_proxy_fcgi.c:466-525`
     *
     * @param array<string,string> $params
     */
    private function sendParams(CoClient $conn, int $reqId, array $params): void
    {
        $encoded = $this->encodeParams($params);

        if ($encoded !== '') {
            $chunks = str_split($encoded, self::MAX_CONTENT);
            foreach ($chunks as $chunk) {
                $this->sendRaw($conn, $this->encodeRecord(self::FCGI_PARAMS, $reqId, $chunk));
            }
        }

        // Empty PARAMS record = end-of-params (mod_proxy_fcgi.c:519-525)
        $this->sendRaw($conn, $this->encodeRecord(self::FCGI_PARAMS, $reqId, ''));
    }

    /**
     * Send `STDIN` record(s) followed by empty `STDIN` terminator.
     * Source: `mod_proxy_fcgi.c:742-758`
     */
    private function sendStdin(CoClient $conn, int $reqId, string $body): void
    {
        if ($body !== '') {
            $chunks = str_split($body, self::MAX_CONTENT);
            foreach ($chunks as $chunk) {
                $this->sendRaw($conn, $this->encodeRecord(self::FCGI_STDIN, $reqId, $chunk));
            }
        }
        // Empty STDIN record = end-of-stdin
        $this->sendRaw($conn, $this->encodeRecord(self::FCGI_STDIN, $reqId, ''));
    }

    /**
     * Send `ABORT_REQUEST` to the backend (client disconnect / error path).
     */
    private function sendAbort(CoClient $conn, int $reqId): void
    {
        try {
            $this->sendRaw($conn, $this->encodeRecord(self::FCGI_ABORT_REQUEST, $reqId, ''));
        } catch (\Throwable) {
            // Best-effort; connection may already be broken
        }
    }

    private function sendRaw(CoClient $conn, string $data): void
    {
        $result = $conn->send($data);
        if ($result === false) {
            $errMsg = is_string($conn->errMsg) ? $conn->errMsg : 'unknown error';
            throw new FastCgiException("FastCGI: send failed: {$errMsg}");
        }
    }

    // ── NV-pair encoding (FCGI §3.4) ─────────────────────────────────────────

    /**
     * Encode name-value pairs per FCGI 1.0 §3.4.
     *
     * Length encoding (`ngx_http_fastcgi_module.c:1092-1102`, `mod_proxy_fcgi.c:501-503`):
     *   `len ≤ 127`  → 1 byte
     *   `len ≥ 128`  → 4 bytes with MSB set (big-endian, top bit = 1)
     *
     * @param array<string,string> $params
     */
    public function encodeParams(array $params): string
    {
        $buf = '';
        foreach ($params as $name => $value) {
            $buf .= $this->encodeLength(strlen($name));
            $buf .= $this->encodeLength(strlen($value));
            $buf .= $name . $value;
        }
        return $buf;
    }

    /**
     * Encode a single FCGI length field.
     * `≤ 127` → 1 byte; `≥ 128` → 4 bytes with MSB set.
     */
    public function encodeLength(int $len): string
    {
        if ($len < 0) {
            throw new FastCgiException("FastCGI: negative length {$len}");
        }
        if ($len <= 127) {
            return chr($len);
        }
        // 4-byte form: top bit set to mark long encoding
        return pack('N', $len | 0x80000000);
    }

    // ── Response parsing (receive side) ──────────────────────────────────────

    /**
     * Read `STDOUT` + `STDERR` + `END_REQUEST` records and assemble response.
     *
     * Header state machine mirrors `mod_proxy_fcgi.c:543-594` and
     * `ngx_http_fastcgi_module.c:1690-1857`.
     *
     * @return array{status:int,headers:array<string,string>,body:string,stderr:string}
     */
    public function readResponse(CoClient $conn, int $reqId): array
    {
        $stdoutBuf = '';
        $stderrBuf = '';
        $appStatus = 0;
        $done      = false;

        while (!$done) {
            $rawHeader = $this->recvExact($conn, self::HEADER_LEN);

            $hdr = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved', $rawHeader);

            if ($hdr === false) {
                throw new FastCgiException("FastCGI: failed to unpack record header");
            }

            // unpack() with C/n formats always yields int values; is_int guards
            // satisfy PHPStan L10 which sees array<string,mixed> from unpack().
            if (!is_int($hdr['version']) || !is_int($hdr['contentLength'])
                || !is_int($hdr['paddingLength']) || !is_int($hdr['type'])
            ) {
                throw new FastCgiException("FastCGI: malformed record header fields");
            }

            $version    = $hdr['version'];
            $contentLen = $hdr['contentLength'];
            $paddingLen = $hdr['paddingLength'];
            $type       = $hdr['type'];

            if ($version !== self::FCGI_VERSION) {
                throw new FastCgiException("FastCGI: unexpected protocol version {$version}");
            }

            $content = $contentLen > 0 ? $this->recvExact($conn, $contentLen) : '';
            if ($paddingLen > 0) {
                $this->recvExact($conn, $paddingLen);
            }

            switch ($type) {
                case self::FCGI_STDOUT:
                    if ($contentLen > 0) {
                        $stdoutBuf .= $content;
                    }
                    break;

                case self::FCGI_STDERR:
                    if ($contentLen > 0) {
                        $stderrBuf .= $content;
                    }
                    break;

                case self::FCGI_END_REQUEST:
                    if ($contentLen >= 5) {
                        $end = unpack('NappStatus/CprotocolStatus', $content);
                        if ($end !== false && is_int($end['appStatus'])) {
                            $appStatus = $end['appStatus'];
                        }
                    }
                    $done = true;
                    break;

                default:
                    // Unknown record type — skip per FCGI spec
                    break;
            }
        }

        return $this->parseStdout($stdoutBuf, $stderrBuf, $appStatus);
    }

    /**
     * Parse raw `STDOUT` bytes into status + headers + body.
     *
     * CGI header block ends at first blank line (`CRLFCRLF` or `LFLF`).
     * `Status:` header is extracted and removed (nginx: `ngx_http_fastcgi_module.c:648-656`;
     * Apache: `ap_scan_script_header_err_brigade_ex`).
     *
     * @return array{status:int,headers:array<string,string>,body:string,stderr:string}
     */
    public function parseStdout(string $stdout, string $stderr, int $appStatus): array
    {
        $status  = 200;
        $headers = [];

        // Split at blank line (CRLFCRLF preferred, LFLF fallback)
        $sepPos = strpos($stdout, "\r\n\r\n");
        if ($sepPos !== false) {
            $headerBlock = substr($stdout, 0, $sepPos);
            $body        = substr($stdout, $sepPos + 4);
        } else {
            $sepPos2 = strpos($stdout, "\n\n");
            if ($sepPos2 !== false) {
                $headerBlock = substr($stdout, 0, $sepPos2);
                $body        = substr($stdout, $sepPos2 + 2);
            } else {
                // No blank line — treat everything as body
                return ['status' => $status, 'headers' => $headers, 'body' => $stdout, 'stderr' => $stderr];
            }
        }

        $lines = preg_split('/\r?\n/', $headerBlock) ?: [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }
            $name  = trim(substr($line, 0, $colonPos));
            $value = trim(substr($line, $colonPos + 1));

            if (strcasecmp($name, 'Status') === 0) {
                $status = (int) $value; // "302 Found" → 302
                continue;
            }

            $headers[$name] = $value;
        }

        return [
            'status'  => $status,
            'headers' => $headers,
            'body'    => $body,
            'stderr'  => $stderr,
        ];
    }

    // ── Socket I/O ───────────────────────────────────────────────────────────

    /**
     * Read exactly `$n` bytes from the coroutine socket, looping on short reads.
     *
     * OpenSwoole sockets can return short reads; this loop ensures the full
     * requested byte count is available before proceeding (partial-frame defence).
     */
    public function recvExact(CoClient $conn, int $n): string
    {
        $buf       = '';
        $remaining = $n;

        while ($remaining > 0) {
            $chunk = $conn->recv($remaining);
            if ($chunk === false || $chunk === '') {
                $errMsg = is_string($conn->errMsg) ? $conn->errMsg : 'connection closed';
                throw new FastCgiException(
                    "FastCGI: connection closed while reading ({$remaining} of {$n} bytes remaining): {$errMsg}"
                );
            }
            $chunkStr   = is_string($chunk) ? $chunk : '';
            $buf       .= $chunkStr;
            $remaining -= strlen($chunkStr);
        }

        return $buf;
    }
}

/**
 * Thrown on protocol or I/O error; triggers `502 Bad Gateway` in the
 * `App::include()` dispatch path.
 */
final class FastCgiException extends \RuntimeException
{
}
