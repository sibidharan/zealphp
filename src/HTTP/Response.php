<?php

namespace ZealPHP\HTTP;

use function ZealPHP\response_set_status;

class Response
{
    public \OpenSwoole\Http\Response $parent;
    private \ZealPHP\G $g;
    public function __construct(\OpenSwoole\Http\Response $response)
    {
        $this->parent = $response;
        $this->g = \ZealPHP\G::instance();
        $this->g->response_headers_list = [];
        $this->g->response_cookies_list = [];
        $this->g->response_rawcookies_list = [];
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
        $this->g->response_headers_list[] = [$key, $value];
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
        $this->g->response_headers_list[] = ['Location', $url];
    }

    public function cookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false, string $samesite = '', string $priority = ''): bool
    {
        $this->g->response_cookies_list[] = [$key, $value, $expire, $path, $domain, $secure, $httponly, $samesite, $priority];
        return true;
    }

    public function rawCookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false, string $samesite = '', string $priority = ''): bool
    {
        $this->g->response_rawcookies_list[] = [$key, $value, $expire, $path, $domain, $secure, $httponly, $samesite, $priority];
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

        $rangeHeader = $this->g->zealphp_request->parent->header['range'] ?? '';

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
            foreach ($this->g->response_headers_list as $header) {
                $this->parent->header(...$header);
            }
            foreach ($this->g->response_cookies_list as $cookie) {
                $this->parent->cookie(...$cookie);
            }
            foreach ($this->g->response_rawcookies_list as $cookie) {
                $this->parent->rawCookie(...$cookie);
            }
            $this->g->response_headers_list = [];
            $this->g->response_cookies_list = [];
            $this->g->response_rawcookies_list = [];
            $this->g->status = null;
            return true;
        }
        return false;
    }
}