<?php

namespace ZealPHP\HTTP;

use function ZealPHP\response_set_status;

/**
 * Thin wrapper around `\OpenSwoole\Http\Response`. The `__call` / `__get` /
 * `__set` proxies forward to the underlying response — these `@method`
 * annotations expose the forwarded signatures to static analysis so call
 * sites are statically typed instead of treated as mixed.
 *
 * @method bool isWritable()
 * @method bool write(string $content)
 * @method int|false sendfile(string $filename, int $offset = 0, int $length = 0)
 * @method bool detach()
 * @method bool trailer(string $key, string $value)
 * @method void upgrade()
 * @method bool push(string $filename, int $opcode = 1, bool $finish = true)
 * @method bool recv(float $timeout = 0)
 * @method static \OpenSwoole\Http\Response|false create(int $fd = -1)
 * @method void close()
 */
class Response
{
    public \OpenSwoole\Http\Response $parent;
    private \ZealPHP\RequestContext $g;
    private ?int $statusCode = null;

    /**
     * Outbound headers / cookies pending emission. Stored on the Response
     * (not G) so the per-request response state lives with the object that
     * owns it. Each entry in $headersList is [string $name, string $value];
     * $cookiesList / $rawCookiesList are arrays of cookie() / rawCookie()
     * argument tuples.
     */
    /** @var array<int, array{0: string, 1: string}> */
    public array $headersList = [];
    /** @var array<int, array{0: string, 1: string, 2: int, 3: string, 4: string, 5: bool, 6: bool, 7: string, 8: string}> */
    public array $cookiesList = [];
    /** @var array<int, array{0: string, 1: string, 2: int, 3: string, 4: string, 5: bool, 6: bool, 7: string, 8: string}> */
    public array $rawCookiesList = [];

    public function __construct(\OpenSwoole\Http\Response $response)
    {
        $this->parent = $response;
        $this->g = \ZealPHP\RequestContext::instance();
    }

    /**
     * Forward method calls to the underlying OpenSwoole response.
     *
     * @param string         $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if (method_exists($this->parent, $name)) {
            // @phpstan-ignore-next-line — __call proxy; signature is dynamic by design
            return call_user_func_array([$this->parent, $name], $arguments);
        }
        throw new \BadMethodCallException("Method {$name} does not exist");
    }

    /**
     * Proxy property reads to the underlying OpenSwoole response.
     *
     * @param string $name
     * @return mixed
     */
    public function &__get($name)
    {
        \ZealPHP\elog($name);

        if (property_exists($this->parent, $name)) {
            return $this->parent->$name;
        } else {
            if($name == 'parent'){
                return $this->parent;
            }
        }
        throw new \InvalidArgumentException("Property {$name} does not exist");
    }

    /**
     * Proxy property writes to the underlying OpenSwoole response.
     *
     * @param string $name
     * @param mixed  $value
     */
    public function __set($name, $value)
    {
        \ZealPHP\elog($name);
        if($name == 'parent'){
            assert($value instanceof \OpenSwoole\Http\Response);
            $this->parent = $value;
            return;
        }
        if (property_exists($this->parent, $name)) {
            $this->parent->$name = $value;
        } else {
            $this->$name = $value;
        }
    }

    public function status(int $statusCode, string $reason = ''): bool
    {
        $this->statusCode = $statusCode;
        $this->g->status = $statusCode;
        return $this->parent->status($statusCode, $reason);
    }

    /**
     * Emit a JSON response and end the connection.
     *
     * @param mixed $data
     * @param int   $status
     */
    public function json($data, $status = 200): void
    {
        $this->header('Content-Type', 'application/json');
        $this->status($status);
        $this->end((string)json_encode($data));
    }

    // You can override methods if necessary or add more custom methods
    public function header(string $key, string $value): bool
    {
        // CRLF / NUL injection guard. Also block `:` and whitespace in the
        // header name itself (RFC 7230 field-name = token, which excludes
        // separators). Without this, attacker-controlled $value can smuggle
        // a second header or split the response.
        if (strpbrk($key, "\r\n\0: \t") !== false || strpbrk($value, "\r\n\0") !== false) {
            trigger_error('Header injection blocked: control characters in name or value', E_USER_WARNING);
            return false;
        }
        $this->headersList[] = [$key, $value];
        if (strtolower($key) === 'location' && $value && ($this->g->status === 200 || $this->g->status === null)) {
            $this->g->status = 302;
        }
        return true;
    }

    /**
     * Send an HTTP redirect.
     *
     * @param string $url           Destination URL (absolute or relative)
     * @param int    $status        301 Moved Permanently, 302 Found (default),
     *                              307 Temporary Redirect, 308 Permanent Redirect
     * @param bool   $allowExternal Permit cross-origin / protocol-relative
     *                              targets. Default `false` — safe-by-default:
     *                              a `//evil.com` or different-host absolute URL
     *                              is REFUSED (open-redirect / CWE-601 guard).
     *                              Set `true` only when an external redirect is
     *                              genuinely intended (and validate user input
     *                              against an allowlist first).
     */
    public function redirect(string $url, int $status = 302, bool $allowExternal = false): void
    {
        if (strpbrk($url, "\r\n\0") !== false) {
            throw new \InvalidArgumentException('Redirect URL contains control characters');
        }
        // Leading/trailing whitespace bypasses the scheme-prefix check below:
        // `   javascript:alert(1)` doesn't match `#^javascript:#i` but browsers
        // strip leading whitespace from Location header values before parsing,
        // executing the javascript: URL anyway. Reject up front — callers with
        // legitimate URLs should trim themselves.
        if ($url !== trim($url, " \t\v\f")) {
            throw new \InvalidArgumentException('Redirect URL contains leading or trailing whitespace');
        }
        // Backslash in URLs is never legitimate per RFC 3986. Browsers parse
        // `/\evil.com` and `\\evil.com` as protocol-relative redirects to
        // evil.com — same effective bypass as `//evil.com` (which our
        // protocol-relative warning catches downstream). Block at the source.
        if (strpos($url, '\\') !== false) {
            throw new \InvalidArgumentException('Redirect URL contains backslash');
        }
        if (preg_match('#^(javascript|data|vbscript):#i', $url)) {
            throw new \InvalidArgumentException('Unsafe redirect URL scheme');
        }

        // Open-redirect guard (CWE-601). Refuse cross-origin / protocol-relative
        // targets by default — warning-and-emitting let `$res->redirect($_GET['next'])`
        // ship an open redirect. Opt in with $allowExternal when truly intended.
        if (preg_match('#^//#', $url)) {
            if (!$allowExternal) {
                throw new \InvalidArgumentException('Protocol-relative redirect blocked: ' . $url . ' (pass $allowExternal=true to permit)');
            }
            \ZealPHP\elog('[security] Protocol-relative redirect detected: ' . $url, 'warn');
        } elseif (isset(parse_url($url)['host'])) {
            $requestHost = $this->g->server['HTTP_HOST'] ?? $this->g->server['SERVER_NAME'] ?? '';
            if ($requestHost !== '' && parse_url($url, PHP_URL_HOST) !== $requestHost) {
                if (!$allowExternal) {
                    throw new \InvalidArgumentException('Cross-origin redirect blocked: ' . $url . ' (pass $allowExternal=true to permit)');
                }
                \ZealPHP\elog('[security] Cross-origin redirect: ' . $url, 'warn');
            }
        }

        $this->g->status = $status;
        $this->headersList[] = ['Location', $url];

        // OpenSwoole's PSR-7 emit() drops reason phrases, which makes its
        // internal status table the source of truth — and that table omits 308.
        // Calling status() without a reason silently downgrades 308 → 200.
        // Workaround: emit the redirect inline (with explicit reason) and mark
        // the response as streaming so the PSR-7 layer's empty-body emit doesn't
        // overwrite what we just wrote.
        if ($this->parent->isWritable()) {
            $reason = self::REDIRECT_REASONS[$status] ?? '';
            $this->g->_streaming = true;
            $this->parent->status($status, $reason);
            foreach ($this->headersList as [$k, $v]) {
                $this->parent->header($k, $v);
            }
            foreach ($this->cookiesList as $cookie) {
                $this->parent->cookie(...$cookie);
            }
            foreach ($this->rawCookiesList as $cookie) {
                $this->parent->rawCookie(...$cookie);
            }
            $this->parent->end();
        }
    }

    private const REDIRECT_REASONS = [
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
    ];

    public function cookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false, string $samesite = '', string $priority = ''): bool
    {
        $this->cookiesList[] = [$key, $value, $expire, $path, $domain, $secure, $httponly, $samesite, $priority];
        return true;
    }

    public function rawCookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false, string $samesite = '', string $priority = ''): bool
    {
        $this->rawCookiesList[] = [$key, $value, $expire, $path, $domain, $secure, $httponly, $samesite, $priority];
        return true;
    }

    public function end(?string $data = null): bool
    {
        return $this->parent->end($data);
    }

    /**
     * Stream a response body in chunks. Headers are flushed immediately.
     * The $fn callback receives a $write(string $chunk) closure; call it
     * for each piece of content. The response is closed when $fn returns.
     *
     * Use inside a coroutine route — co::sleep() or channel ops between
     * $write() calls yield the event loop so other requests aren't blocked.
     */
    public function stream(callable $fn): void
    {
        $this->g->_streaming = true;
        $this->flush();
        // Guard each write: if the client disconnected, write() would return false
        // and OpenSwoole would emit ERRNO 1005 notices — return false silently instead.
        $write = function(string $chunk): bool {
            if (!$this->parent->isWritable()) return false;
            return $this->parent->write($chunk) !== false;
        };
        try {
            $fn($write);
        } catch (\Throwable $e) {
            // Swallow exceptions from disconnected client writes inside streaming callbacks
        }
        if ($this->parent->isWritable()) {
            $this->parent->end();
        }
    }

    /**
     * Server-Sent Events endpoint. Sets the required headers and delegates
     * to stream(). The $fn callback receives an $emit() closure:
     *   $emit(string $data, string $event = '', string $id = '')
     * which formats and sends one SSE message.
     */
    public function sse(callable $fn): void
    {
        $this->header('Content-Type', 'text/event-stream');
        $this->header('Cache-Control', 'no-cache');
        $this->header('X-Accel-Buffering', 'no');
        $this->stream(function(callable $write) use ($fn) {
            $emit = function(string $data, string $event = '', string $id = '') use ($write): void {
                $msg = '';
                if ($id !== '')    $msg .= "id: $id\n";
                if ($event !== '') $msg .= "event: $event\n";
                $msg .= "data: $data\n\n";
                $write($msg);
            };
            $fn($emit);
        });
    }

    /**
     * Maximum number of comma-separated range specs honoured in a single
     * Range header. Mirrors Apache's AP_DEFAULT_MAX_RANGES (byterange_filter.c).
     * Beyond this the Range header is ignored and the full body is served (200),
     * matching Apache's CVE-2011-3192 mitigation.
     */
    private const MAX_RANGES = 200;

    /**
     * Parse a Range header value into a normalised list of [start, end] byte
     * ranges (inclusive, clamped to the resource size).
     *
     * Mirrors RangeMiddleware's spec handling so both the buffered and the
     * zero-copy file paths agree on suffix / open-end / bounded semantics.
     *
     * Returns one of:
     *  - ['status' => 'ignore', 'ranges' => []]
     *        Header is not a `bytes=` range, contains a syntactically invalid
     *        spec (RFC 7233 §2.1: an invalid spec invalidates the whole header),
     *        or the range count exceeds {@see MAX_RANGES} (CVE-2011-3192
     *        mitigation). Caller serves the full body (200).
     *  - ['status' => 'unsatisfiable', 'ranges' => []]
     *        Every well-formed spec is out of bounds (416).
     *  - ['status' => 'ok', 'ranges' => array<int, array{0: int, 1: int}>]
     *
     * @return array{status: 'ignore'|'unsatisfiable'|'ok', ranges: array<int, array{0: int, 1: int}>}
     */
    public static function parseRange(string $rangeHeader, int $total): array
    {
        if (!preg_match('/^bytes=(.+)$/i', $rangeHeader, $m)) {
            return ['status' => 'ignore', 'ranges' => []];
        }

        $specs = explode(',', $m[1]);
        if (count($specs) > self::MAX_RANGES) {
            return ['status' => 'ignore', 'ranges' => []];
        }

        $ranges = [];
        foreach ($specs as $spec) {
            $spec = trim($spec);
            if ($spec === '') {
                continue;
            }

            // Strict byte-range-spec grammar (RFC 7233 §2.1):
            //   suffix:  "-" 1*DIGIT          (bytes=-N)
            //   open:    1*DIGIT "-"          (bytes=N-)
            //   bounded: 1*DIGIT "-" 1*DIGIT  (bytes=N-M)
            // Any other shape (trailing/leading garbage, multiple dashes) makes
            // the whole header invalid → ignore it and serve the full body.
            if (!preg_match('/^(\d*)-(\d*)$/', $spec, $sm) || ($sm[1] === '' && $sm[2] === '')) {
                return ['status' => 'ignore', 'ranges' => []];
            }

            if ($sm[1] === '') {
                // Suffix range: bytes=-N → last N bytes. A suffix longer than
                // the file means "the entire representation" (RFC 7233 §2.1) —
                // clamp the start to 0 rather than treating it as unsatisfiable.
                $suffixLen = (int) $sm[2];
                if ($suffixLen <= 0) {
                    // Degenerate single spec — skip it; if it's the only spec the
                    // post-loop empty check emits 416 (#185).
                    continue;
                }
                $ranges[] = [max(0, $total - $suffixLen), $total - 1];
            } elseif ($sm[2] === '') {
                // Open-end range: bytes=N-
                $start = (int) $sm[1];
                if ($start >= $total) {
                    // Out-of-bounds spec — skip it (RFC 7233 §4.4: an unsatisfiable
                    // spec in a multi-range is ignored, not fatal); 416 only if ALL
                    // specs are unsatisfiable (post-loop check) (#185).
                    continue;
                }
                $ranges[] = [$start, $total - 1];
            } else {
                // Bounded range: bytes=N-M
                $start = (int) $sm[1];
                $end   = (int) $sm[2];
                if ($start > $end || $start >= $total) {
                    // Unsatisfiable spec — skip (see the §4.4 note above) (#185).
                    continue;
                }
                $ranges[] = [$start, min($end, $total - 1)];
            }
        }

        // Every spec was unsatisfiable/degenerate → 416 (RFC 7233 §4.4). A
        // multi-range header keeps its satisfiable specs (the bad ones skipped above).
        if ($ranges === []) {
            return ['status' => 'unsatisfiable', 'ranges' => []];
        }

        return ['status' => 'ok', 'ranges' => $ranges];
    }

    /**
     * Serve a file with Range request support using OpenSwoole's zero-copy sendfile.
     *
     * @param string $path     Absolute path to the file
     * @param string $filename Optional download filename (triggers Content-Disposition: attachment)
     */
    public function sendFile(string $path, string $filename = ''): void
    {
        if (!file_exists($path) || !is_readable($path)) {
            $this->status(404);
            $this->g->_streaming = true;
            $this->flush();
            $this->parent->end('File not found');
            return;
        }

        $this->g->_streaming = true;
        $total = filesize($path);
        if ($total === false) { $total = 0; }
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        if ($mime === 'text/plain' || $mime === 'application/octet-stream') {
            $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'css'  => 'text/css',
                'js'   => 'application/javascript',
                'json' => 'application/json',
                'svg'  => 'image/svg+xml',
                'xml'  => 'application/xml',
                'woff' => 'font/woff',
                'woff2'=> 'font/woff2',
                'ttf'  => 'font/ttf',
                'otf'  => 'font/otf',
                'webp' => 'image/webp',
                'avif' => 'image/avif',
                'mp4'  => 'video/mp4',
                'webm' => 'video/webm',
                default => $mime,
            };
        }

        $this->header('Content-Type', $mime);
        $this->header('Accept-Ranges', 'bytes');

        if ($filename !== '') {
            $this->header('Content-Disposition', 'attachment; filename="' . addcslashes($filename, '"\\') . '"');
        }

        // Conditional GET — Apache-style ETag (inode-size-mtime as weak validator)
        // + If-None-Match / If-Modified-Since handling. Returns 304 on match.
        $mtime = filemtime($path);
        if ($mtime === false) { $mtime = 0; }
        $etag = 'W/"' . dechex($mtime) . '-' . dechex($total) . '"';
        $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        $this->header('ETag', $etag);
        $this->header('Last-Modified', $lastModified);

        $reqHeaders = $this->g->zealphp_request->parent->header ?? [];
        if (!is_array($reqHeaders)) {
            $reqHeaders = [];
        }
        $ifNoneMatch = $reqHeaders['if-none-match'] ?? '';
        if (!is_string($ifNoneMatch)) {
            $ifNoneMatch = '';
        }
        $ifModifiedSince = $reqHeaders['if-modified-since'] ?? '';
        if (!is_string($ifModifiedSince)) {
            $ifModifiedSince = '';
        }
        $notModified = false;
        if ($ifNoneMatch !== '') {
            foreach (array_map(static fn(string $s): string => trim($s), explode(',', $ifNoneMatch)) as $tag) {
                if ($tag === $etag || $tag === '*' || $tag === ltrim($etag, 'W/')) {
                    $notModified = true;
                    break;
                }
            }
        } elseif ($ifModifiedSince !== '') {
            // Apache ap_condition_if_modified_since: a future-dated
            // If-Modified-Since (later than the request time) is invalid and
            // must NOT yield a 304 — otherwise a client can force spurious 304s
            // by sending a date past "now". Guard with $since <= time().
            $since = strtotime($ifModifiedSince);
            if ($since !== false && $since <= time() && $since >= $mtime) {
                $notModified = true;
            }
        }
        if ($notModified) {
            $this->status(304);
            $this->flush();
            $this->parent->end('');
            return;
        }

        $rangeHeader = $reqHeaders['range'] ?? '';
        if (!is_string($rangeHeader)) {
            $rangeHeader = '';
        }

        // If-Range (RFC 9110 §13.1.5): only honour the Range if the validator
        // still matches. A leading `"` (or `W/"`) marks an entity-tag form,
        // otherwise it is an HTTP-date compared against the file mtime (strong:
        // exact second match required). On mismatch we ignore the Range and
        // serve the full body — never a 412 here.
        if ($rangeHeader !== '') {
            $ifRange = $reqHeaders['if-range'] ?? '';
            if (!is_string($ifRange)) {
                $ifRange = '';
            }
            if ($ifRange !== '' && !$this->ifRangeMatches($ifRange, $etag, $mtime)) {
                $rangeHeader = '';
            }
        }

        $parsed = $rangeHeader !== ''
            ? self::parseRange($rangeHeader, $total)
            : ['status' => 'ignore', 'ranges' => []];

        if ($parsed['status'] === 'unsatisfiable') {
            $this->status(416);
            $this->header('Content-Range', "bytes */{$total}");
            $this->flush();
            $this->parent->end('');
            return;
        }

        if ($parsed['status'] === 'ok') {
            $ranges = $parsed['ranges'];
            if (count($ranges) === 1) {
                [$start, $end] = $ranges[0];
                $length = $end - $start + 1;

                $this->status(206);
                $this->header('Content-Range', "bytes {$start}-{$end}/{$total}");
                $this->header('Content-Length', (string) $length);
                $this->flush();
                $this->parent->sendfile($path, $start, $length);
                return;
            }

            $this->sendMultipart($path, $ranges, $total, $mime);
            return;
        }

        // status === 'ignore' — full body (200).
        $this->header('Content-Length', (string) $total);
        $this->flush();
        $this->parent->sendfile($path, 0, $total);
    }

    /**
     * Evaluate an If-Range header value against the resource's validators.
     *
     * @param string $ifRange Raw If-Range header value
     * @param string $etag    The resource's current ETag (entity-tag form)
     * @param int    $mtime   The resource's last-modified time (unix seconds)
     * @return bool true → the validator still matches, honour the Range
     */
    private function ifRangeMatches(string $ifRange, string $etag, int $mtime): bool
    {
        $ifRange = trim($ifRange);
        if ($ifRange === '') {
            return true;
        }

        // Entity-tag form: starts with `"` or `W/"`. Strong comparison only —
        // a weak validator must not be used to short-circuit a Range request,
        // so a `W/`-prefixed If-Range never matches (mirrors Apache's
        // ap_condition_if_range, which compares the raw strings).
        if (str_starts_with($ifRange, '"') || str_starts_with($ifRange, 'W/"')) {
            return $ifRange === $etag;
        }

        // HTTP-date form: honour the Range only if the file has not been
        // modified since the supplied date (exact-second strong match).
        $when = strtotime($ifRange);
        return $when !== false && $when === $mtime;
    }

    /**
     * Emit a 206 multipart/byteranges response for two or more ranges,
     * streaming each slice straight from the file. Body framing mirrors
     * RangeMiddleware so both paths produce byte-identical multipart output.
     *
     * @param array<int, array{0: int, 1: int}> $ranges
     */
    private function sendMultipart(string $path, array $ranges, int $total, string $mime): void
    {
        $boundary = 'zealphp_' . bin2hex(random_bytes(16));

        // Pre-compute the exact Content-Length: each part's framing plus its
        // slice length, the inter-part CRLF separators, and the closing
        // delimiter — matching the wire bytes written below.
        $length = 0;
        $partHeaders = [];
        foreach ($ranges as $i => [$start, $end]) {
            $header = "--{$boundary}\r\n"
                . "Content-Type: {$mime}\r\n"
                . "Content-Range: bytes {$start}-{$end}/{$total}\r\n"
                . "\r\n";
            $partHeaders[$i] = $header;
            $length += strlen($header) + ($end - $start + 1);
            if ($i > 0) {
                $length += 2; // inter-part "\r\n"
            }
        }
        $closing = "\r\n--{$boundary}--\r\n";
        $length += strlen($closing);

        $this->header('Content-Type', "multipart/byteranges; boundary={$boundary}");
        $this->header('Content-Length', (string) $length);
        $this->status(206);
        $this->flush();

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            $this->parent->end('');
            return;
        }
        foreach ($ranges as $i => [$start, $end]) {
            $part = ($i > 0 ? "\r\n" : '') . $partHeaders[$i];
            $this->parent->write($part);
            $remaining = $end - $start + 1;
            fseek($fh, $start);
            while ($remaining > 0) {
                $chunk = fread($fh, min(8192, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $this->parent->write($chunk);
                $remaining -= strlen($chunk);
            }
        }
        fclose($fh);
        $this->parent->write($closing);
        $this->parent->end();
    }

    /**
     * Returns a fluent HtmxResponse builder that queues HX-* headers into
     * this response. Each call returns the same builder instance (one per
     * Response object) so callers can chain or call multiple times.
     */
    public function htmx(): HtmxResponse
    {
        if (!isset($this->htmxBuilder)) {
            $this->htmxBuilder = new HtmxResponse($this);
        }
        return $this->htmxBuilder;
    }

    private ?HtmxResponse $htmxBuilder = null;

    public function flush(): bool
    {
        if ($this->parent->isWritable()) {
            foreach ($this->headersList as $header) {
                $this->parent->header(...$header);
            }
            foreach ($this->cookiesList as $cookie) {
                $this->parent->cookie(...$cookie);
            }
            foreach ($this->rawCookiesList as $cookie) {
                $this->parent->rawCookie(...$cookie);
            }
            $this->headersList = [];
            $this->cookiesList = [];
            $this->rawCookiesList = [];
            $this->g->status = null;
            return true;
        }
        return false;
    }
}