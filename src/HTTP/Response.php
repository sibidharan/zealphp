<?php

namespace ZealPHP\HTTP;

use function ZealPHP\response_set_status;

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
    public array $headersList = [];
    public array $cookiesList = [];
    public array $rawCookiesList = [];

    public function __construct(\OpenSwoole\Http\Response $response)
    {
        $this->parent = $response;
        $this->g = \ZealPHP\RequestContext::instance();
    }

    // Magic method to forward method calls to the parent
    public function __call($name, $arguments)
    {
        if (method_exists($this->parent, $name)) {
            return call_user_func_array([$this->parent, $name], $arguments);
        }
        throw new \BadMethodCallException("Method {$name} does not exist");
    }

    // Magic method to get properties from the parent
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

    // Magic method to set properties on the parent
    public function __set($name, $value)
    {
        \ZealPHP\elog($name);
        if($name == 'parent'){
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

    public function json($data, $status = 200)
    {
        $this->header('Content-Type', 'application/json');
        $this->status($status);
        $this->end(json_encode($data));
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
     * @param string $url    Destination URL (absolute or relative)
     * @param int    $status 301 Moved Permanently, 302 Found (default),
     *                       307 Temporary Redirect, 308 Permanent Redirect
     */
    public function redirect(string $url, int $status = 302): void
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

        if (preg_match('#^//#', $url)) {
            \ZealPHP\elog('[security] Protocol-relative redirect detected: ' . $url, 'warn');
        } elseif (isset(parse_url($url)['host'])) {
            $requestHost = $this->g->server['HTTP_HOST'] ?? $this->g->server['SERVER_NAME'] ?? '';
            if ($requestHost !== '' && parse_url($url, PHP_URL_HOST) !== $requestHost) {
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
        $this->stream(function($write) use ($fn) {
            $emit = function(string $data, string $event = '', string $id = '') use ($write) {
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
        $etag = 'W/"' . dechex($mtime) . '-' . dechex($total) . '"';
        $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        $this->header('ETag', $etag);
        $this->header('Last-Modified', $lastModified);

        $reqHeaders = $this->g->zealphp_request->parent->header ?? [];
        $ifNoneMatch = $reqHeaders['if-none-match'] ?? '';
        $ifModifiedSince = $reqHeaders['if-modified-since'] ?? '';
        $notModified = false;
        if ($ifNoneMatch !== '') {
            foreach (array_map('trim', explode(',', $ifNoneMatch)) as $tag) {
                if ($tag === $etag || $tag === '*' || $tag === ltrim($etag, 'W/')) {
                    $notModified = true;
                    break;
                }
            }
        } elseif ($ifModifiedSince !== '') {
            $since = strtotime($ifModifiedSince);
            if ($since !== false && $since >= $mtime) {
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

        if ($rangeHeader !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $m)) {
            $start = $m[1] !== '' ? (int) $m[1] : null;
            $end   = $m[2] !== '' ? (int) $m[2] : null;

            if ($start === null && $end !== null) {
                $start = max(0, $total - $end);
                $end = $total - 1;
            } elseif ($start !== null && $end === null) {
                $end = $total - 1;
            }

            if ($start === null || $start >= $total || $start > $end) {
                $this->status(416);
                $this->header('Content-Range', "bytes */{$total}");
                $this->flush();
                $this->parent->end('');
                return;
            }

            $end = min($end, $total - 1);
            $length = $end - $start + 1;

            $this->status(206);
            $this->header('Content-Range', "bytes {$start}-{$end}/{$total}");
            $this->header('Content-Length', (string) $length);
            $this->flush();
            $this->parent->sendfile($path, $start, $length);
        } else {
            $this->header('Content-Length', (string) $total);
            $this->flush();
            $this->parent->sendfile($path, 0, $total);
        }
    }

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