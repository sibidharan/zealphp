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

    /**
     * Queue a response header.
     *
     * Mirrors PHP's native `header($value, $replace = true)` semantics (#260):
     * `$replace = true` (the default) drops any previously-queued header of the
     * same name before adding this one — last write wins, as every framework
     * caller that sets a single header (Content-Type, Cache-Control, …) relies
     * on. `$replace = false` is the APPEND form (`header($h, false)`) — it keeps
     * prior same-name entries, so multiple `Link` / `WWW-Authenticate` / CSP
     * headers all survive (flush() groups same-name appends into one
     * array-valued OpenSwoole call so they all reach the wire).
     */
    public function header(string $key, string $value, bool $replace = true): bool
    {
        // CRLF / NUL injection guard. Also block `:` and whitespace in the
        // header name itself (RFC 7230 field-name = token, which excludes
        // separators). Without this, attacker-controlled $value can smuggle
        // a second header or split the response.
        if (strpbrk($key, "\r\n\0: \t") !== false || strpbrk($value, "\r\n\0") !== false) {
            trigger_error('Header injection blocked: control characters in name or value', E_USER_WARNING);
            return false;
        }
        if ($replace) {
            // Drop prior same-name entries (case-insensitive) so this value wins.
            $this->headersList = array_values(array_filter(
                $this->headersList,
                static fn(array $pair): bool => strcasecmp($pair[0], $key) !== 0
            ));
        }
        $this->headersList[] = [$key, $value];
        if (strtolower($key) === 'location' && $value && ($this->g->status === 200 || $this->g->status === null)) {
            $this->g->status = 302;
        }
        return true;
    }

    /** Default port for a URL scheme (RFC 6454 origin). 0 = unknown scheme. */
    private function defaultPort(string $scheme): int
    {
        return match (strtolower($scheme)) {
            'http', 'ws'   => 80,
            'https', 'wss' => 443,
            default        => 0,
        };
    }

    /**
     * Split a host[:port] authority into [bare host, port]. Handles bracketed
     * IPv6 literals (`[::1]`, `[::1]:8080`) and the `host:port` form. A missing
     * or non-numeric port yields `null` for the port (the caller applies the
     * scheme default).
     *
     * @return array{0: string, 1: ?int}
     */
    private function splitHostPort(string $authority): array
    {
        if ($authority !== '' && $authority[0] === '[') {
            // IPv6 literal — `[::1]` or `[::1]:8080`.
            $end = strpos($authority, ']');
            if ($end === false) {
                return [$authority, null];
            }
            $host = substr($authority, 0, $end + 1);
            $rest = substr($authority, $end + 1);
            $port = (str_starts_with($rest, ':') && ctype_digit(substr($rest, 1)))
                ? (int) substr($rest, 1) : null;
            return [$host, $port];
        }
        $colon = strrpos($authority, ':');
        if ($colon === false) {
            return [$authority, null];
        }
        $portStr = substr($authority, $colon + 1);
        return ctype_digit($portStr)
            ? [substr($authority, 0, $colon), (int) $portStr]
            : [$authority, null];
    }

    /**
     * RFC 6454 same-origin test for the open-redirect (CWE-601) guard. An
     * origin is the triple (scheme, host, port); two URLs are same-origin iff
     * all three match (#432). This is **strict by design**: ZealPHP commonly
     * runs several instances on the same host at different ports (`php app.php
     * -p 8081` vs `-p 8082`), so a same-host different-PORT target is a
     * *different instance* and must be treated as cross-origin — and a
     * scheme/port downgrade to an attacker-controlled service on the same host
     * is a genuine CWE-601 hole. Same-origin absolute redirects keep working
     * because the request's own (scheme, host, port) is compared, not a
     * default — fixing the prior port-blind over-block (a same-origin redirect
     * 500'd on every non-default port) and the host-only under-block together.
     *
     * A target with no host (relative URL) is same-origin; an undeterminable
     * request host preserves the historical "allow" so a working relative
     * redirect never regresses.
     */
    private function isSameOrigin(string $url): bool
    {
        $target = parse_url($url);
        if (!is_array($target) || !isset($target['host'])) {
            return true; // relative / no host → same-origin
        }
        /** @var mixed $reqHostRaw */
        $reqHostRaw = $this->g->server['HTTP_HOST'] ?? $this->g->server['SERVER_NAME'] ?? '';
        if (!is_string($reqHostRaw) || $reqHostRaw === '') {
            return true; // request origin undeterminable → don't over-block
        }

        // Request scheme — HTTPS via the HTTPS flag, X-Forwarded-Proto, or the
        // bound port 443.
        $https      = strtolower((string) ($this->g->server['HTTPS'] ?? ''));
        $xfp        = strtolower((string) ($this->g->server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $serverPort = (string) ($this->g->server['SERVER_PORT'] ?? '');
        $reqScheme  = ($https !== '' && $https !== 'off') || $xfp === 'https' || $serverPort === '443'
            ? 'https' : 'http';

        // Request host + port — HTTP_HOST carries the client-visible port; fall
        // back to SERVER_NAME[:SERVER_PORT], then to the scheme default.
        [$reqHost, $reqPort] = $this->splitHostPort($reqHostRaw);
        if ($reqPort === null) {
            $reqPort = ctype_digit($serverPort) ? (int) $serverPort : $this->defaultPort($reqScheme);
        }

        // Target origin — scheme is present on any absolute URL (`//host` is the
        // protocol-relative branch handled before this is reached). parse_url()
        // already lower-cases the scheme and returns an int port, so no extra
        // strtolower()/(int) is needed (avoids redundant-cast mutants).
        $tgtScheme = $target['scheme'] ?? $reqScheme;
        $tgtHost   = $target['host'];
        $tgtPort   = $target['port'] ?? $this->defaultPort($tgtScheme);

        return $reqScheme === $tgtScheme
            && strcasecmp($reqHost, $tgtHost) === 0
            && $reqPort === $tgtPort;
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
        } elseif (isset(parse_url($url)['host']) && !$this->isSameOrigin($url)) {
            // Cross-origin per the RFC 6454 (scheme, host, port) triple (#432).
            if (!$allowExternal) {
                throw new \InvalidArgumentException('Cross-origin redirect blocked: ' . $url . ' (pass $allowExternal=true to permit)');
            }
            \ZealPHP\elog('[security] Cross-origin redirect: ' . $url, 'warn');
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
            // Group same-name headers so multiple appends (e.g. several Link
            // preload hints emitted alongside a redirect) survive on the wire
            // (#260) — same path flush() uses.
            $this->emitQueuedHeaders();
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
        // HEAD: emit headers only, never the streamed body — mirrors the
        // Generator streaming path in App::emitGeneratorStream() (which ends the
        // response without writing chunks). Without this, stream()/sse() (sse()
        // delegates here) would invoke $fn and write a body on a HEAD request,
        // violating RFC 9110 §9.3.2 (#238). Flush the queued headers, end the
        // connection, and return WITHOUT calling $fn.
        $method = (string) ($this->g->server['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'HEAD') {
            $this->flush();
            if ($this->parent->isWritable()) {
                $this->parent->end();
            }
            return;
        }
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
     *        contains no spec at all (`bytes=,` — the empty byte-range-set, also
     *        invalid per §2.1, #365), or the range count exceeds {@see MAX_RANGES}
     *        (CVE-2011-3192 mitigation). Caller serves the full body (200).
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
        // Track whether the set contained ANY syntactically-valid spec. RFC 7233
        // §2.1: byte-range-set = 1#( byte-range-spec / suffix-byte-range-spec ) —
        // at least one spec is required. A header with none (`bytes=,`, `bytes=,,`,
        // `bytes=, ,`) is not a valid Range request at all → ignore it and serve
        // the full 200 (#365), as opposed to a valid spec that fell out of bounds
        // (→ 416). Without this we can't tell "no parseable spec → ignore" from
        // "all valid specs unsatisfiable → unsatisfiable".
        $sawSpec = false;
        foreach ($specs as $spec) {
            $spec = trim($spec);
            if ($spec === '') {
                continue;
            }
            $sawSpec = true;

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

        if ($ranges === []) {
            // No range survived. Distinguish the two empty cases (#365):
            //  - No syntactically-valid spec was present at all (`bytes=,`) →
            //    the header is invalid per RFC 7233 §2.1 → ignore → full 200.
            //  - ≥1 valid spec parsed but every one fell outside [0, total) /
            //    was degenerate (`bytes=500-600`, `bytes=-0`) → 416 (§4.4).
            return $sawSpec
                ? ['status' => 'unsatisfiable', 'ranges' => []]
                : ['status' => 'ignore', 'ranges' => []];
        }

        // Coalesce overlapping/adjacent specs into disjoint unions before
        // serving (#230). Mirrors RangeMiddleware::coalesceRanges() so the
        // zero-copy sendFile() path has the same DoS-amplification cap as the
        // buffered path: N duplicate/overlapping full-file ranges can't multiply
        // the bytes written — Σ(end-start+1) over the result ≤ $total.
        return ['status' => 'ok', 'ranges' => self::coalesceRanges($ranges)];
    }

    /**
     * Coalesce a list of [start, end] byte ranges (inclusive) into the minimal
     * set of disjoint ranges covering the same bytes.
     *
     * Sorts by start, then merges any spec that overlaps OR is immediately
     * adjacent to the running union (`next.start <= cur.end + 1`). Every input
     * spec is already clamped to `[0, total-1]` by {@see parseRange()}, so the
     * union of the result can never exceed the resource size — the
     * DoS-amplification cap (#230). Result is start-ascending (RFC 9110 §14.2
     * permits the server to reorder/merge multipart ranges). Kept in lock-step
     * with {@see \ZealPHP\Middleware\RangeMiddleware::coalesceRanges()}.
     *
     * @param array<int, array{0: int, 1: int}> $ranges
     * @return list<array{0: int, 1: int}>
     */
    private static function coalesceRanges(array $ranges): array
    {
        usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        /** @var list<array{0: int, 1: int}> $merged */
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            if ($merged === []) {
                $merged[] = [$start, $end];
                continue;
            }
            $lastIndex = count($merged) - 1;
            [$curStart, $curEnd] = $merged[$lastIndex];
            if ($start <= $curEnd + 1) {
                $merged[$lastIndex] = [$curStart, max($curEnd, $end)];
            } else {
                $merged[] = [$start, $end];
            }
        }
        return $merged;
    }

    /**
     * Serve a file with Range request support using OpenSwoole's zero-copy sendfile.
     *
     * The default mod_mime suffix maps `sendFile()` resolves against (#317) —
     * an Apache `mime.types` / `AddEncoding` / `AddLanguage` stock-conf subset.
     * Built once per worker; override with {@see setFileMimeResolver()}.
     */
    private static ?MimeResolver $fileMimeResolver = null;

    /**
     * Override the suffix→metadata resolver `sendFile()` uses — pass your own
     * `MimeResolver` (e.g. extra types, no language map), or `null` to restore
     * the built-in Apache-parity default.
     */
    public static function setFileMimeResolver(?MimeResolver $resolver): void
    {
        self::$fileMimeResolver = $resolver ?? self::defaultFileMimeResolver();
    }

    private static function fileMimeResolver(): MimeResolver
    {
        return self::$fileMimeResolver ??= self::defaultFileMimeResolver();
    }

    /**
     * Apache stock-conf parity maps: common `mime.types` entries, the
     * `AddEncoding` compression suffixes, and the default-conf `AddLanguage`
     * set. Kept deliberately modest — the full IANA registry belongs to a
     * user-supplied resolver via {@see setFileMimeResolver()}.
     */
    private static function defaultFileMimeResolver(): MimeResolver
    {
        return new MimeResolver(
            [
                'html' => 'text/html', 'htm' => 'text/html',
                'css'  => 'text/css',
                'js'   => 'application/javascript', 'mjs' => 'application/javascript',
                'json' => 'application/json',
                'txt'  => 'text/plain',
                'csv'  => 'text/csv',
                'md'   => 'text/markdown',
                'xml'  => 'application/xml',
                'svg'  => 'image/svg+xml',
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
                'avif' => 'image/avif',
                'ico'  => 'image/vnd.microsoft.icon',
                'woff' => 'font/woff', 'woff2' => 'font/woff2',
                'ttf'  => 'font/ttf', 'otf' => 'font/otf',
                'mp3'  => 'audio/mpeg',
                'ogg'  => 'audio/ogg',
                'wav'  => 'audio/wav',
                'mp4'  => 'video/mp4',
                'webm' => 'video/webm',
                'pdf'  => 'application/pdf',
                'zip'  => 'application/zip',
                'tar'  => 'application/x-tar',
                'wasm' => 'application/wasm',
            ],
            ['gz' => 'gzip', 'br' => 'br', 'zst' => 'zstd', 'bz2' => 'x-bzip2', 'z' => 'compress'],
            [
                'ca' => 'ca', 'cs' => 'cs', 'da' => 'da', 'de' => 'de', 'el' => 'el',
                'en' => 'en', 'es' => 'es', 'et' => 'et', 'fr' => 'fr', 'he' => 'he',
                'hi' => 'hi', 'hr' => 'hr', 'it' => 'it', 'ja' => 'ja', 'ko' => 'ko',
                'nl' => 'nl', 'pl' => 'pl', 'pt' => 'pt', 'ru' => 'ru', 'sv' => 'sv',
                'ta' => 'ta', 'tr' => 'tr', 'zh' => 'zh',
            ],
        );
    }

    /**
     * Resolve a file's response metadata for `sendFile()` (#317): walk the
     * suffix chain via {@see MimeResolver} (Apache `mod_mime` semantics), then
     * fall back to magic-bytes sniffing only when no suffix mapped a type.
     * When the chain yielded an ENCODING but no type (`README.gz`), magic
     * bytes must not label the encoding as the type (application/gzip +
     * Content-Encoding: gzip would double-decode) — emit octet-stream.
     *
     * @return array{type: string, encoding: ?string, languages: list<string>}
     */
    private static function resolveFileMetadata(string $path): array
    {
        $resolved = self::fileMimeResolver()->resolve($path);
        $type = $resolved['type'];
        if ($type === null) {
            $type = $resolved['encoding'] !== null
                ? 'application/octet-stream'
                : (mime_content_type($path) ?: 'application/octet-stream');
        }
        return ['type' => $type, 'encoding' => $resolved['encoding'], 'languages' => $resolved['languages']];
    }

    /**
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

        // Content metadata — Apache mod_mime parity via the framework's own
        // MimeResolver (#317): the WHOLE suffix chain is walked, so
        // `app.html.gz` → Content-Type: text/html + Content-Encoding: gzip and
        // `page.fr.html` → text/html + Content-Language: fr. Magic bytes / the
        // legacy rightmost-suffix fallback only run when no suffix maps a type.
        // NOTE the classic Apache gotcha applies on purpose: a `backup.tar.gz`
        // download gets `Content-Encoding: gzip`, which browsers transparently
        // decode — exactly what Apache's stock `AddEncoding x-gzip .gz` does.
        $meta = self::resolveFileMetadata($path);
        $mime = $meta['type'];

        $this->header('Content-Type', $mime);
        if ($meta['encoding'] !== null) {
            $this->header('Content-Encoding', $meta['encoding']);
        }
        if ($meta['languages'] !== []) {
            $this->header('Content-Language', implode(', ', $meta['languages']));
        }
        $this->header('Accept-Ranges', 'bytes');

        if ($filename !== '') {
            // RFC 6266 / RFC 5987: HTTP field values are US-ASCII (RFC 9110 §5.5),
            // so a non-ASCII download name must travel as the `filename*` ext-value
            // (UTF-8'' + percent-encoded), NOT raw UTF-8 octets inside the quoted
            // `filename=` string (which browsers garble/strip). We always emit an
            // ASCII-downgraded `filename=` fallback for legacy clients, and append
            // `filename*=UTF-8''…` only when the name actually has non-ASCII bytes
            // (#361). Matches mod_php / mod_dav download behaviour.
            $ascii = (string) preg_replace('/[^\x20-\x7e]/', '_', $filename);
            $disposition = 'attachment; filename="' . addcslashes($ascii, '"\\') . '"';
            if ($ascii !== $filename) {
                $disposition .= "; filename*=UTF-8''" . rawurlencode($filename);
            }
            $this->header('Content-Disposition', $disposition);
        }

        // Conditional preconditions — Apache-style ETag (mtime-size as weak
        // validator), then DELEGATE to the framework's own
        // ConditionalRequest::evaluate() (#321) so ALL FOUR conditional
        // headers run in RFC 9110 / ap_meets_conditions() order:
        // If-Match → If-Unmodified-Since → If-None-Match → If-Modified-Since.
        // Previously only steps 3–4 were inlined here: If-Match /
        // If-Unmodified-Since were ignored outright (no 412), and weak-ETag
        // stripping used ltrim()'s character-class semantics.
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
        $condHeaders = [];
        foreach ($reqHeaders as $hk => $hv) {
            if (is_string($hk) && is_string($hv)) {
                $condHeaders[$hk] = $hv;
            }
        }
        $methodRaw = $this->g->server['REQUEST_METHOD'] ?? 'GET';
        $method    = is_string($methodRaw) && $methodRaw !== '' ? $methodRaw : 'GET';
        $cond = ConditionalRequest::evaluate($method, $condHeaders, $etag, $mtime);
        if ($cond === 304 || $cond === 412) {
            $this->status($cond);
            $this->flush();
            $this->parent->end('');
            return;
        }

        // HEAD (RFC 9110 §9.3.2): "identical to GET except that the server MUST
        // NOT send content in the response." Emit every header a GET would
        // (Content-Type / Content-Length / ETag / Last-Modified / Accept-Ranges,
        // already queued above) but no body bytes. This mirrors the stream()/sse()
        // HEAD guard (#238); without it the zero-copy sendfile() paths below would
        // write the full file on HEAD (#358), bypassing ResponseMiddleware's
        // HEAD body-strip (sendFile sets $g->_streaming = true). We advertise the
        // full representation's Content-Length here — the body-less HEAD analogue
        // of the 200 path — never a per-range length, matching Apache static HEAD.
        if (strcasecmp($method, 'HEAD') === 0) {
            $this->status(200);
            $this->header('Content-Length', (string) $total);
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
        // otherwise it is an HTTP-date evaluated by the shared
        // self::ifRangeDateMatches() strong-validation helper (Apache's 60 s
        // clock-skew rule — identical to the buffered RangeMiddleware path, see
        // #258). On mismatch we ignore the Range and serve the full body —
        // never a 412 here.
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

        // Entity-tag form: starts with `"` or `W/"`. RFC 9110 §13.1.5 mandates
        // the STRONG comparison function for If-Range, and §8.8.1 forbids a weak
        // validator from authorising sub-range retrieval. So if EITHER tag is
        // weak (`W/`-prefixed) the validator can never satisfy If-Range → fall
        // through to the full 200 (#362). Only two non-weak, byte-identical
        // opaque-tags match. sendFile's ETag is always weak, so by-ETag If-Range
        // on these resources always serves the full body — clients should use
        // the HTTP-date If-Range form (handled by ifRangeDateMatches()).
        if (str_starts_with($ifRange, '"') || str_starts_with($ifRange, 'W/"')) {
            if (str_starts_with($ifRange, 'W/') || str_starts_with($etag, 'W/')) {
                return false;
            }
            return $ifRange === $etag;
        }

        // HTTP-date form: defer to the single shared date-strong helper so the
        // buffered (RangeMiddleware) and zero-copy (this) paths can never drift
        // on what "strong date match" means (#258). reqTime = null → the helper
        // uses wall-clock now (sendFile has no upstream Date header to consult).
        return self::ifRangeDateMatches($ifRange, $mtime, null);
    }

    /**
     * Shared If-Range HTTP-date evaluator — the SINGLE source of truth for
     * date-form `If-Range` strong validation across both range-serving paths
     * (this Response::sendFile() zero-copy path AND the buffered
     * RangeMiddleware path), so they can never diverge on the same request
     * (#258).
     *
     * Implements RFC 9110 §13.1.5's strong-validation requirement via Apache's
     * one-minute clock-skew rule (ap_condition_if_range): a Last-Modified date
     * is only a STRONG validator if it is at least 60 s older than the time the
     * representation was served — a value younger than that window is treated
     * as weak and a weak validator MUST NOT be used to short-circuit a Range
     * request. The Range is honoured only when the supplied date matches the
     * resource mtime exactly AND that 60 s skew window has elapsed.
     *
     * @param string   $ifRange Raw If-Range header value (already known to be
     *                          the HTTP-date form, not an entity-tag).
     * @param int      $mtime   Resource last-modified time (unix seconds).
     * @param int|null $reqTime Time the response is/was served (unix seconds) —
     *                          from the response Date header when available;
     *                          null → use wall-clock now.
     * @return bool true → the date is a strong match, honour the Range.
     */
    public static function ifRangeDateMatches(string $ifRange, int $mtime, ?int $reqTime): bool
    {
        $when = strtotime(trim($ifRange));
        if ($when === false || $when !== $mtime) {
            // Unparseable, or the validator no longer matches the resource —
            // ignore the Range and serve the full representation.
            return false;
        }
        $served = $reqTime ?? time();
        if ($served < $mtime + 60) {
            // Skew window not yet elapsed — weak match, not allowed with Range.
            return false;
        }
        return true;
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

        // Assemble the whole multipart body and emit it in ONE length-delimited
        // end($payload) call rather than incremental write()s (#366). The moment
        // OpenSwoole sees a write() it switches the response to chunked
        // transfer-encoding and silently drops the Content-Length we queued above
        // — diverging from Apache, which sends multipart/byteranges length-
        // delimited. coalesceRanges() in parseRange() caps Σ(slice) ≤ $total, so
        // the buffered payload is bounded by the file size (the same byte budget
        // a 200 would write). Result: the precomputed Content-Length survives on
        // the wire, so clients can show a progress total for the multi-range fetch.
        $payload = '';
        foreach ($ranges as $i => [$start, $end]) {
            $payload .= ($i > 0 ? "\r\n" : '') . $partHeaders[$i];
            $remaining = $end - $start + 1;
            fseek($fh, $start);
            while ($remaining > 0) {
                $chunk = fread($fh, min(8192, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $payload .= $chunk;
                $remaining -= strlen($chunk);
            }
        }
        fclose($fh);
        $payload .= $closing;
        $this->parent->end($payload);
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
            $this->emitQueuedHeaders();
            // Serialize cookies in PHP (#293) — OpenSwoole's C-side cookie()
            // diverges from PHP 8.4 on the wire (dashed expires date, missing
            // Max-Age, `+` for space, lowercase attribute names). Emit the
            // PHP-8.4-identical lines as Set-Cookie header values instead,
            // riding the same array-valued multi-header mechanism as #260.
            $lines = [];
            foreach ($this->cookiesList as $cookie) {
                $lines[] = self::serializeCookie(...$cookie);
            }
            foreach ($this->rawCookiesList as $cookie) {
                $lines[] = self::serializeCookie(...[...$cookie, true]);
            }
            if (count($lines) === 1) {
                $this->parent->header('Set-Cookie', $lines[0]);
            } elseif ($lines !== []) {
                $this->emitMultiHeader('Set-Cookie', $lines);
            }
            $this->headersList = [];
            $this->cookiesList = [];
            $this->rawCookiesList = [];
            $this->g->status = null;
            return true;
        }
        return false;
    }

    /**
     * Serialize ONE `Set-Cookie` header value byte-identical to PHP 8.4's
     * `php_setcookie()` (#293) — verified live against Apache 2.4.67 +
     * mod_php 8.4:
     *
     * - `expires` uses the IMF-fixdate form with SPACES (`Wed, 18 May 2033 …`),
     *   not the legacy dashed Netscape date.
     * - `Max-Age=max(0, expire - now)` is emitted alongside `expires`
     *   (RFC 6265 §5.2.2 — Max-Age wins where supported).
     * - The value is `rawurlencode()`d — space is `%20` (never `+`; cookies are
     *   not form-encoded, and a literal `+` corrupts base64 payloads like JWTs).
     * - PHP's attribute order and casing: `path`, `domain`, `secure`,
     *   `HttpOnly`, `SameSite`.
     * - An EMPTY value emits PHP's canonical deletion form:
     *   `name=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0`.
     *
     * #319: `SameSite=None` without `Secure` is emitted AS-IS — PHP/Apache do
     * not auto-coerce, and parity wins — but a warning is logged, because
     * modern browsers (Chrome 80+, RFC 6265bis) silently drop such cookies.
     *
     * @param string $name     Cookie name (validated upstream by setcookie/setrawcookie).
     * @param string $value    Cookie value; ''  → PHP's deletion form.
     * @param int    $expire   Unix expiry; 0 → session cookie (no expires/Max-Age).
     * @param string $path     `path=` attribute ('' → omitted).
     * @param string $domain   `domain=` attribute ('' → omitted).
     * @param bool   $secure   Emit `secure`.
     * @param bool   $httponly Emit `HttpOnly`.
     * @param string $samesite `SameSite=` attribute ('' → omitted).
     * @param string $priority `Priority=` attribute (Chrome extension; '' → omitted).
     * @param bool   $raw      true → value passes through verbatim (setrawcookie).
     */
    public static function serializeCookie(
        string $name,
        string $value = '',
        int $expire = 0,
        string $path = '',
        string $domain = '',
        bool $secure = false,
        bool $httponly = false,
        string $samesite = '',
        string $priority = '',
        bool $raw = false,
    ): string {
        $parts = [];
        if ($value === '') {
            // PHP's deletion form: any empty-value setcookie() becomes an
            // explicit expiry at epoch+1 regardless of the $expire given.
            $parts[] = $name . '=deleted';
            $parts[] = 'expires=Thu, 01 Jan 1970 00:00:01 GMT';
            $parts[] = 'Max-Age=0';
        } else {
            $parts[] = $name . '=' . ($raw ? $value : rawurlencode($value));
            if ($expire !== 0) {
                $parts[] = 'expires=' . gmdate('D, d M Y H:i:s', $expire) . ' GMT';
                $parts[] = 'Max-Age=' . max(0, $expire - time());
            }
        }
        if ($path !== '') {
            $parts[] = 'path=' . $path;
        }
        if ($domain !== '') {
            $parts[] = 'domain=' . $domain;
        }
        if ($secure) {
            $parts[] = 'secure';
        }
        if ($httponly) {
            $parts[] = 'HttpOnly';
        }
        if ($samesite !== '') {
            $parts[] = 'SameSite=' . $samesite;
            if (!$secure && strcasecmp($samesite, 'None') === 0) {
                \ZealPHP\elog(
                    "Cookie '{$name}' sets SameSite=None without Secure — modern browsers silently drop it (add 'secure' => true)",
                    'warn'
                );
            }
        }
        if ($priority !== '') {
            $parts[] = 'Priority=' . $priority;
        }
        return implode('; ', $parts);
    }

    /**
     * Emit every queued header to the underlying OpenSwoole response, grouping
     * same-name entries so multiple appends survive on the wire (#260).
     *
     * `header($name, $value, false)` (PHP's append form) queues each value as a
     * separate `$headersList` entry — correct. But OpenSwoole's scalar
     * `$response->header($name, $scalar)` OVERWRITES any prior entry of the same
     * name (it stores under the header name as the array key), so emitting one
     * scalar call per entry collapses `Link` / `WWW-Authenticate` /
     * `Content-Security-Policy` multi-value headers to the LAST value only.
     *
     * OpenSwoole DOES support multiple same-name headers — pass an ARRAY value
     * and the ext emits one wire line per element (the same mechanism that makes
     * multiple `Set-Cookie` work). So we group by name (first-seen order
     * preserved), then emit a scalar for single-value names and an array for
     * names with >1 value.
     */
    private function emitQueuedHeaders(): void
    {
        /** @var array<string, list<string>> $grouped */
        $grouped = [];
        foreach ($this->headersList as [$name, $value]) {
            // Preserve first-seen order of distinct names; append repeats.
            $grouped[$name][] = $value;
        }
        foreach ($grouped as $name => $values) {
            if (count($values) === 1) {
                $this->parent->header($name, $values[0]);
            } else {
                // Array value → OpenSwoole emits one `Name: value` line per
                // element (proven against ext-openswoole 26.x: header() parses
                // the value as a zval and the emit loop expands array values).
                $this->emitMultiHeader($name, $values);
            }
        }
    }

    /**
     * Emit a single header name with multiple values as one OpenSwoole
     * `header()` call carrying an ARRAY value, so the ext writes one wire line
     * per value (#260). Isolated in its own method because the
     * openswoole/ide-helper stub types the value param as `string` while the
     * real ext accepts any zval (incl. an array) — the array-value behaviour is
     * a documented ext-vs-stub mismatch (see phpstan.neon ignoreErrors).
     *
     * @param list<string> $values
     */
    private function emitMultiHeader(string $name, array $values): void
    {
        $this->parent->header($name, $values);
    }
}