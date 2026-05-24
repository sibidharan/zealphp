<?php

declare(strict_types=1);

namespace ZealPHP;

/**
 * Typed outbound-HTTP response. Returned by every `Http::*` call.
 *
 * Fields are public + readonly — call sites do `$r->status` / `$r->body`
 * directly. `json()` decodes the body once (no caching — small enough
 * cost; cache yourself if a hot loop reads it many times).
 */
final class HTTPResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int    $status,
        public readonly string $body,
        public readonly array  $headers = [],
        public readonly ?\Throwable $error = null,
    ) {}

    /** @return mixed JSON-decoded body, or null when not valid JSON */
    public function json(): mixed
    {
        return json_decode($this->body, true);
    }

    /** 2xx response — successful by convention. */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Whether the request failed at the transport layer (network, DNS, timeout, TLS). */
    public function failed(): bool
    {
        return $this->error !== null;
    }
}

/**
 * Ergonomic outbound HTTP wrapper around `OpenSwoole\Coroutine\Http\Client`.
 *
 * Yields naturally under `Runtime::HOOK_ALL` (the default in coroutine
 * mode). Outside a coroutine context, calls wrap themselves in
 * `Coroutine::run()` so sync callers (`php -r`, unit tests) also work.
 *
 * For the common case — JSON request/response, short timeout, a handful
 * of headers — this is one call:
 *
 *     $r = HTTP::get('https://api.example.com/users', ['Authorization' => 'Bearer ...']);
 *     if ($r->ok()) { return $r->json(); }
 *
 *     $r = HTTP::post('https://hooks.slack.com/...', json: ['text' => 'hi']);
 *
 * For concurrent fan-out (N requests in parallel), use HTTP::all() —
 * built on App::parallel().
 *
 * For complex multipart uploads / streaming bodies, drop down to
 * `OpenSwoole\Coroutine\Http\Client` directly — this wrapper is
 * deliberately small.
 */
final class HTTP
{
    /** @param array<string, string> $headers */
    public static function get(string $url, array $headers = [], float $timeout = 30.0): HTTPResponse
    {
        return self::request('GET', $url, null, $headers, $timeout);
    }

    /**
     * POST with optional body. When `$body` is null, sends an empty body.
     * When `$body` is array, JSON-encodes + sets `Content-Type: application/json`
     * (unless a Content-Type header is already set). When `$body` is
     * string, sends as-is.
     *
     * @param array<string, string> $headers
     */
    public static function post(string $url, mixed $body = null, array $headers = [], float $timeout = 30.0): HTTPResponse
    {
        return self::request('POST', $url, $body, $headers, $timeout);
    }

    /** @param array<string, string> $headers */
    public static function put(string $url, mixed $body = null, array $headers = [], float $timeout = 30.0): HTTPResponse
    {
        return self::request('PUT', $url, $body, $headers, $timeout);
    }

    /** @param array<string, string> $headers */
    public static function delete(string $url, array $headers = [], float $timeout = 30.0): HTTPResponse
    {
        return self::request('DELETE', $url, null, $headers, $timeout);
    }

    /**
     * Send a request. Arbitrary method. Network errors (DNS, connect,
     * TLS, timeout) come back as an HTTPResponse with `status=0` +
     * `error=<Throwable>` rather than throwing — handlers can check
     * `$r->failed()` once at the top of the response-processing block
     * instead of try-catching every call.
     *
     * @param array<string, string> $headers
     */
    public static function request(string $method, string $url, mixed $body = null, array $headers = [], float $timeout = 30.0): HTTPResponse
    {
        if (\OpenSwoole\Coroutine::getCid() < 0) {
            // Outside coroutine — wrap in Coroutine::run so the hooked
            // socket calls have a scheduler.
            $r = null;
            $err = null;
            \OpenSwoole\Coroutine::run(function () use ($method, $url, $body, $headers, $timeout, &$r, &$err): void {
                try { $r = self::request($method, $url, $body, $headers, $timeout); }
                catch (\Throwable $e) { $err = $e; }
            });
            if ($err !== null) {
                return new HTTPResponse(0, '', [], $err);
            }
            return $r instanceof HTTPResponse ? $r : new HTTPResponse(0, '', [], new \RuntimeException('HTTP::request: coroutine wrap returned null'));
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return new HTTPResponse(0, '', [], new \InvalidArgumentException("HTTP::request: invalid url: $url"));
        }
        $scheme   = strtolower((string) ($parts['scheme'] ?? 'http'));
        $tls      = $scheme === 'https';
        $host     = (string) $parts['host'];
        $port     = isset($parts['port']) ? (int) $parts['port'] : ($tls ? 443 : 80);
        $path     = (string) ($parts['path'] ?? '/');
        if (isset($parts['query'])) {
            $path .= '?' . (string) $parts['query'];
        }

        // Prepare body + headers. Arrays JSON-encoded; strings sent as-is.
        $bodyStr = '';
        $finalHeaders = $headers;
        if (is_array($body)) {
            $bodyStr = (string) json_encode($body);
            if (!self::hasHeaderCi($finalHeaders, 'Content-Type')) {
                $finalHeaders['Content-Type'] = 'application/json';
            }
        } elseif (is_string($body)) {
            $bodyStr = $body;
        } elseif ($body !== null) {
            return new HTTPResponse(0, '', [], new \InvalidArgumentException(
                'HTTP::request: $body must be null, string, or array; got ' . get_debug_type($body)
            ));
        }

        try {
            $client = new \OpenSwoole\Coroutine\Http\Client($host, $port, $tls);
            $client->set(['timeout' => $timeout]);
            $client->setMethod(strtoupper($method));
            if ($finalHeaders !== []) { $client->setHeaders($finalHeaders); }
            if ($bodyStr !== '')      { $client->setData($bodyStr); }
            $ok = $client->execute($path);
            if (!$ok) {
                /** @var mixed $errMsgRaw */
                $errMsgRaw = $client->errMsg ?? '';
                $errMsg = is_string($errMsgRaw) && $errMsgRaw !== '' ? $errMsgRaw : 'HTTP::request: client->execute returned false';
                $client->close();
                return new HTTPResponse(0, '', [], new \RuntimeException($errMsg));
            }
            /** @var mixed $statusRaw */ $statusRaw = $client->statusCode ?? 0;
            $status = is_int($statusRaw) ? $statusRaw : (is_numeric($statusRaw) ? (int) $statusRaw : 0);
            /** @var mixed $bodyRaw */ $bodyRaw = $client->body ?? '';
            $respBody = is_string($bodyRaw) ? $bodyRaw : (is_scalar($bodyRaw) ? (string) $bodyRaw : '');
            /** @var mixed $rawHeaders */ $rawHeaders = $client->headers ?? [];
            $respHeaders = [];
            if (is_array($rawHeaders)) {
                foreach ($rawHeaders as $k => $v) {
                    $respHeaders[(string) $k] = is_scalar($v) ? (string) $v : '';
                }
            }
            $client->close();
            return new HTTPResponse($status, $respBody, $respHeaders);
        } catch (\Throwable $e) {
            return new HTTPResponse(0, '', [], $e);
        }
    }

    /**
     * Concurrent fan-out. Each entry in `$requests` is a callable that
     * returns an HTTPResponse — typically a thunk like
     * `fn() => HTTP::get('...')`. Results in input order. Uses
     * `App::parallel()` under the hood — every request gets its own
     * coroutine.
     *
     * @param  list<callable(): HTTPResponse> $requests
     * @return list<HTTPResponse>
     */
    public static function all(array $requests): array
    {
        if ($requests === []) { return []; }
        /** @var list<HTTPResponse> $r */
        $r = App::parallel($requests);
        return $r;
    }

    /**
     * Case-insensitive header check — outbound headers are user-provided.
     *
     * @param array<string, string> $headers
     */
    private static function hasHeaderCi(array $headers, string $name): bool
    {
        $lower = strtolower($name);
        foreach (array_keys($headers) as $k) {
            if (strtolower($k) === $lower) { return true; }
        }
        return false;
    }
}
