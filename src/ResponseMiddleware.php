<?php

declare(strict_types=1);

namespace ZealPHP;

use OpenSwoole\Core\Psr\Middleware\StackHandler;
use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\Middleware\Pipeline\MiddlewareFrame;
use ZealPHP\Middleware\Pipeline\RouteDispatchHandler;
use ZealPHP\Middleware\Pipeline\PathDispatchHandler;
use function ZealPHP\elog;
use function ZealPHP\jTraceEx;
use function ZealPHP\response_add_header;
use function ZealPHP\response_set_status;
use function ZealPHP\access_log;

/**
 * The router / dispatch middleware (innermost PSR-15 layer).
 *
 * Extracted verbatim from src/App.php (Phase 0 structural relocation). FQCN
 * unchanged (`ZealPHP\ResponseMiddleware`) — it is both the injected `$app`
 * handler param and the dispatch terminal of the middleware onion.
 */
class ResponseMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $params
     */
    private function dispatchRawRoute(array $route, array $params, string $method): ResponseInterface
    {
        $g = RequestContext::instance();
        $handler = $route['handler'];
        assert(is_callable($handler));
        $paramMap = $route['param_map'];
        assert(is_array($paramMap));

        $invokeArgs = [];
        foreach ($paramMap as $param) {
            assert(is_array($param));
            $pname = $param['name'] ?? null;
            assert(is_string($pname));
            if (isset($params[$pname])) {
                $invokeArgs[] = $params[$pname];
            } else if ($pname === 'app') {
                $invokeArgs[] = $this;
            } else if ($pname === 'request' || $pname === 'req') {
                $invokeArgs[] = $g->zealphp_request;
            } else if ($pname === 'response' || $pname === 'res') {
                $invokeArgs[] = $g->zealphp_response;
            } else {
                $invokeArgs[] = $param['has_default'] ? $param['default'] : null;
            }
        }

        // Output-buffer baseline: this method opens no buffer of its own, so on
        // an exit()/ExitException we must only reclaim buffers the HANDLER left
        // open — draining all the way to level 0 would steal a parent dispatch's
        // buffer (e.g. an exception-handler ob_start, or a nested render).
        $obBase = ob_get_level();
        try {
            // Pin request-input superglobals to THIS coroutine's request before
            // the handler reads them (coroutine-legacy overlap defence). No-op
            // in every other mode.
            App::rebindRequestInput($g);
            $object = call_user_func_array($handler, $invokeArgs);
            if ($object instanceof ResponseInterface) {
                return $object;
            }

            if ($object instanceof \Generator) {
                // Capture status BEFORE flush — Response::flush() clears g->status.
                $streamStatus = $g->status ?? 200;
                // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                App::emitStatus($g->openswoole_response, $streamStatus);
                // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                $g->zealphp_response->header('Accept-Ranges', 'none');
                // HEAD: send headers only, never the streamed body (Apache
                // strips content buckets via ctx->final_header_only). Streaming
                // length is unknown/chunked, so no Content-Length is emitted.
                if ($method === 'HEAD') {
                    // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                    $g->openswoole_response->end();
                    return (new Response('', $streamStatus));
                }
                // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                $g->zealphp_response->flush();
                foreach ($object as $chunk) {
                    // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                    if (!$g->openswoole_response->isWritable()) break;
                    // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                    $g->openswoole_response->write((string)$chunk);
                    \OpenSwoole\Coroutine::sleep(0);
                }
                // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                if ($g->openswoole_response->isWritable()) {
                    // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                    $g->openswoole_response->end();
                }
                return (new Response('', $streamStatus));
            }

            if ($g->_streaming ?? false) {
                return (new Response('', $g->status ?? 200));
            }

            if (is_int($object)) {
                // Universal return contract: int = HTTP status. Coerce out-of-range
                // to 500 + log warning so bugs surface instead of silently emitting
                // bogus status codes (Apache-parity behavior).
                $status = App::coerceStatusCode((int)$object);
                $body = '';
            } else {
                $status = $g->status ?? 200;
                if (is_array($object) or is_object($object)) {
                    response_add_header('Content-Type', 'application/json');
                    $body = (string)json_encode($object);
                } else if (is_string($object)) {
                    $body = $object;
                } else {
                    $body = '';
                }
            }

            if ($method === 'HEAD') {
                response_add_header('Content-Length', (string)strlen($body));
                return (new Response('', $status));
            }
            return (new Response($body, $status));
        } catch (\Throwable|\OpenSwoole\ExitException $e) {
            if ($e instanceof \OpenSwoole\ExitException
                || ($e::class === 'ExitException' && method_exists($e, 'getStatus'))
            ) {
                $exitStatus = $e->getStatus();
                $buffered = '';
                while (ob_get_level() > $obBase) {
                    $buffered = (string)ob_get_clean() . $buffered;
                }
                if ($exitStatus === 0 || $exitStatus === null) {
                    return (new Response($buffered))->withStatus($g->status ?? 200);
                } elseif (is_string($exitStatus)) {
                    return (new Response($buffered . $exitStatus))->withStatus($g->status ?? 200);
                } elseif (is_int($exitStatus) && $exitStatus >= 100 && $exitStatus <= 599) {
                    return (new Response($buffered))->withStatus($exitStatus);
                } else {
                    return (new Response($buffered))->withStatus($g->status ?? 200);
                }
            }
            // If this dispatch was itself invoked by renderError (error handler
            // dispatch path), let the throw bubble back so the outer renderError
            // catches it and renders the ORIGINAL error status's default page —
            // not a fresh 500 from inside the recursion.
            if ($g->error_render_depth > 0) {
                throw $e;
            }
            // User-installed exception handler runs before the default error page.
            $excStack = $g->exception_handlers_stack;
            if (!empty($excStack)) {
                ob_start();
                try { $excStack[count($excStack) - 1]($e); } catch (\Throwable $e2) { /* swallow */ }
                $body = (string)ob_get_clean();
                return (new Response($body))->withStatus($g->status ?? 500);
            }
            elog(jTraceEx($e), "error");
            $app = App::instance();
            assert($app !== null);
            return $app->renderError(500, $e);
        }
    }

    /**
     * Dispatch gate. When the matched route carries per-route middleware
     * (resolved to instances once at `App::run()`), run the request through that
     * PSR-15 chain — each middleware wrapping the next, the innermost terminal
     * invoking the route handler via `dispatchMatched()`. Fast path: routes with
     * no middleware (the overwhelming majority) go straight to `dispatchMatched`
     * with zero added work, byte-for-byte the pre-feature behaviour. Fallback /
     * error-handler dispatches carry no 'middleware' key, so they fast-path too.
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $params
     */
    public function dispatchRoute(array $route, array $params, string $method, ?ServerRequestInterface $request = null): ResponseInterface
    {
        $backend = $route['backend'] ?? null;
        if (!is_array($backend)) {
            // Fast path (the overwhelming majority): no per-route backend, so
            // App::include() resolves the backend from the global cgiMode /
            // registry exactly as before — byte-for-byte the pre-feature path.
            return $this->dispatchRouteInner($route, $params, $method, $request);
        }
        // Make the route's backend the override for App::include() calls made
        // anywhere inside this dispatch (handler + any per-route middleware),
        // then restore the prior value so nested dispatches (error handler,
        // fallback) don't inherit it.
        $g = RequestContext::instance();
        $prev = $g->cgi_backend_override;
        /** @var array{mode:string, interpreter?:string, address?:string, fcgi_params?:array<string,string>} $backend */
        $g->cgi_backend_override = $backend;
        try {
            return $this->dispatchRouteInner($route, $params, $method, $request);
        } finally {
            $g->cgi_backend_override = $prev;
        }
    }

    /**
     * The middleware-vs-direct dispatch decision, factored out so
     * `dispatchRoute()` can wrap it with the per-route backend override.
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $params
     */
    private function dispatchRouteInner(array $route, array $params, string $method, ?ServerRequestInterface $request): ResponseInterface
    {
        $chain = $route['middleware'] ?? null;
        if (is_array($chain) && $chain !== []) {
            return $this->dispatchWithMiddleware($route, $params, $method, $chain, $request);
        }
        return $this->dispatchMatched($route, $params, $method);
    }

    /**
     * Run the matched route's middleware onion, then the handler. The onion is
     * assembled fresh per request from a few lightweight `RequestHandler`
     * wrappers — the EXPENSIVE work (alias resolution + instantiation) was done
     * once at boot — with the matched params baked into the terminal, so the
     * chain holds no shared per-request state and is re-entrant-safe. The
     * first-listed middleware ends up outermost (runs first), consistent with
     * the global stack. A middleware that returns without calling the handler
     * (a 403/redirect) short-circuits before the route handler runs.
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $params
     * @param array<int|string, mixed> $chain
     */
    private function dispatchWithMiddleware(array $route, array $params, string $method, array $chain, ?ServerRequestInterface $request): ResponseInterface
    {
        if ($request === null) {
            // No PSR-7 request in scope (a non-process dispatch path). Real
            // middleware'd routes are only reached from process(), which always
            // threads the request — so this is defensive; run the handler plain.
            return $this->dispatchMatched($route, $params, $method);
        }
        $handler = new RouteDispatchHandler($this, $route, $params, $method);
        foreach (array_reverse($chain) as $mw) {
            if ($mw instanceof MiddlewareInterface) {
                $handler = new MiddlewareFrame($mw, $handler);
            }
        }
        return $handler->handle($request);
    }

    /**
     * Invoke the matched route handler with reflection-injected params and apply
     * the universal return contract. This is the dispatch terminal — the path a
     * route takes once any per-route middleware have run (or immediately, for a
     * route with none).
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $params
     */
    public function dispatchMatched(array $route, array $params, string $method): ResponseInterface
    {
        if (($route['raw'] ?? false) === true) {
            return $this->dispatchRawRoute($route, $params, $method);
        }

        $g = RequestContext::instance();
        $handler = $route['handler'];
        assert(is_callable($handler));
        $paramMap = $route['param_map'];
        assert(is_array($paramMap));

        $invokeArgs = [];
        foreach ($paramMap as $param) {
            assert(is_array($param));
            $pname = $param['name'] ?? null;
            assert(is_string($pname));
            if (isset($params[$pname])) {
                $invokeArgs[] = $params[$pname];
            } else if ($pname === 'app') {
                $invokeArgs[] = $this;
            } else if ($pname === 'request' || $pname === 'req') {
                $invokeArgs[] = $g->zealphp_request;
            } else if ($pname === 'response' || $pname === 'res') {
                $invokeArgs[] = $g->zealphp_response;
            } else {
                $invokeArgs[] = $param['has_default'] ? $param['default'] : null;
            }
        }

        try {
            // Pin request-input superglobals to THIS coroutine's request before
            // the handler reads them (coroutine-legacy overlap defence). No-op
            // in every other mode.
            App::rebindRequestInput($g);
            ob_start();
            $object = call_user_func_array($handler, $invokeArgs);

            // Fast paths — discard output buffer without string copy
            if ($object instanceof \Generator) {
                ob_end_clean();
                return App::emitGeneratorStream($object, $method);
            }

            if ($g->_streaming ?? false) {
                // Apache+mod_php auto-flushes any remaining buffer at handler exit
                // even in streaming mode. Mirror that to keep the last echo visible.
                if (ob_get_level() > 0) {
                    $remaining = ob_get_clean();
                    if ($remaining !== false && $remaining !== ''
                        && isset($g->openswoole_response)
                        && $g->openswoole_response->isWritable()) {
                        $g->openswoole_response->write($remaining);
                    }
                }
                return (new Response('', $g->status ?? 200));
            }

            if (is_int($object)) {
                ob_end_clean();
                // Universal return contract: int = HTTP status. Coerce out-of-range
                // (< 100 or >= 600) to 500 + log warning — Apache-parity behavior.
                $istatus = App::coerceStatusCode((int)$object);
                // Status-only returns from a handler (e.g. `return 404;`) route
                // through renderError so any registered custom error page fires —
                // Apache's `ErrorDocument` behavior for unhandled status codes.
                if ($istatus >= 400 && $istatus < 600) {
                    $app = App::instance();
                    assert($app !== null);
                    return $app->renderError($istatus);
                }
                return (new Response('', $istatus));
            }

            $status = $g->status ?? 200;

            if ($object instanceof ResponseInterface) {
                ob_end_clean();
                return $object;
            }

            if (is_array($object) || is_object($object)) {
                ob_end_clean();
                $body = (string)json_encode($object);
                // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                $g->zealphp_response->header('Content-Type', 'application/json');
                if ($method === 'HEAD') {
                    // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                    $g->zealphp_response->header('Content-Length', (string)strlen($body));
                    return (new Response('', $status));
                }
                return (new Response($body, $status));
            }

            if (is_string($object)) {
                ob_end_clean();
                if ($method === 'HEAD') {
                    // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                    $g->zealphp_response->header('Content-Length', (string)strlen($object));
                    return (new Response('', $status));
                }
                return (new Response($object, $status));
            }

            // void + echo — only path that needs the buffered output
            $buffer = (string)ob_get_clean();
            if ($method === 'HEAD') {
                // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                $g->zealphp_response->header('Content-Length', (string)strlen($buffer));
                return (new Response('', $status));
            }
            return (new Response($buffer, $status));
        } catch (\Throwable|\OpenSwoole\ExitException $e) {
            if ($e instanceof \OpenSwoole\ExitException
                || ($e::class === 'ExitException' && method_exists($e, 'getStatus'))
            ) {
                $exitStatus = $e->getStatus();
                $buffered = (string)ob_get_clean();
                if ($exitStatus === 0 || $exitStatus === null) {
                    return (new Response($buffered))->withStatus($g->status ?? 200);
                } elseif (is_string($exitStatus)) {
                    return (new Response($buffered . $exitStatus))->withStatus($g->status ?? 200);
                } elseif (is_int($exitStatus) && $exitStatus >= 100 && $exitStatus <= 599) {
                    return (new Response($buffered))->withStatus($exitStatus);
                } else {
                    return (new Response($buffered))->withStatus($g->status ?? 200);
                }
            }
            // Inside an error-render recursion — rethrow so the outer renderError
            // catches and falls through to the default body for the ORIGINAL status.
            if ($g->error_render_depth > 0) {
                @ob_end_clean();
                throw $e;
            }
            // User-installed exception handler runs before the default error page.
            $excStack = $g->exception_handlers_stack;
            if (!empty($excStack)) {
                if (ob_get_level() > 0) { @ob_clean(); }
                ob_start();
                try { $excStack[count($excStack) - 1]($e); } catch (\Throwable $e2) { /* swallow */ }
                $body = (string)ob_get_clean();
                @ob_end_clean();
                return (new Response($body))->withStatus($g->status ?? 500);
            }
            @ob_end_clean();
            elog(jTraceEx($e), "error");
            $app = App::instance();
            assert($app !== null);
            return $app->renderError(500, $e);
        }
    }

    /**
     * Reconstruct the request line + headers for a TRACE echo body, mirroring
     * Apache's ap_send_http_trace() (http_filters.c:1130). Format is the request
     * line, each header as `Name: value`, then a terminating blank line — the
     * message/http representation the client sent. Header names/values are
     * passed through verbatim (TRACE is an introspection echo); CR/LF inside a
     * value is stripped so a crafted header can't inject extra wire lines.
     *
     * @param array<string, string> $headers
     */
    public static function buildTraceEcho(string $method, string $uri, string $protocol, array $headers): string
    {
        $crlf = "\r\n";
        $out = $method . ' ' . $uri . ' ' . $protocol . $crlf;
        foreach ($headers as $name => $value) {
            $cleanName = str_replace(["\r", "\n"], '', $name);
            $cleanValue = str_replace(["\r", "\n"], '', $value);
            $out .= $cleanName . ': ' . $cleanValue . $crlf;
        }
        return $out . $crlf;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $g = RequestContext::instance();
        // Stash the canonical PSR-7 request so inner layers (ZealAPI's in-file
        // $middleware onion, etc.) can reach the same object the middleware
        // stack used, without re-threading it through every handler signature.
        $g->psr_request = $request;
        $uri = (string)$g->server['REQUEST_URI'];
        $method = (string)$g->server['REQUEST_METHOD'];
        $app = App::instance();
        assert($app !== null);

        // URL-decoded traversal/null-byte rejection BEFORE route matching.
        // Apache rejects these at the URI parse layer; we do the same so encoded
        // attacks (%2e%2e, %00, backslash) can't survive past pattern matching.
        $parsedPath = parse_url($uri, PHP_URL_PATH);
        $rawPath = is_string($parsedPath) ? $parsedPath : $uri;

        // Apache AllowEncodedSlashes Off (default): an encoded slash in the RAW
        // path is refused with 404 before it can be decoded to a real `/`. Check
        // the pre-decode bytes — once decoded, %2F is indistinguishable from a
        // literal slash. Gated by App::$allow_encoded_slashes (default false).
        if (!App::$allow_encoded_slashes && stripos($rawPath, '%2f') !== false) {
            return $app->renderError(404);
        }

        // Decode-until-stable: Apache normalises before each access check, so a
        // double-encoded payload (%252e%252e -> %2e%2e -> ..) is caught. A single
        // rawurldecode() peels only one layer. Decode repeatedly (capped) then run
        // the traversal/null-byte/backslash checks against the fully-decoded form.
        $decoded = App::decodeUntilStable($rawPath);
        if (strpos($decoded, "\0") !== false
            || strpos($decoded, '\\') !== false
            || preg_match('#(^|/)\.\.(/|$)#', $decoded)) {
            return $app->renderError(400);
        }

        // Apache ap_normalize_path: collapse `//` -> `/` and drop `/./` segments
        // before route matching, so `//admin//` and `/./admin` cannot bypass a
        // pattern route guarding `/admin`. Rebuild REQUEST_URI from the normalised
        // path so every downstream consumer (PATH_INFO, route table) sees one form.
        $path = App::normalizeRequestPath($rawPath);
        if ($path !== $rawPath) {
            $qs = parse_url($uri, PHP_URL_QUERY);
            $uri = $path . (is_string($qs) && $qs !== '' ? '?' . $qs : '');
            $g->server['REQUEST_URI'] = $uri;
        }

        // RFC 9112 §3.2: an HTTP/1.1 request MUST carry a Host header; a server
        // MUST reject one that lacks it with 400. HTTP/1.0 is exempt (Host is
        // optional there). curl-based clients always send Host, so this only
        // bites malformed/raw requests — the vhost-confusion / smuggling surface.
        if (($g->server['SERVER_PROTOCOL'] ?? '') === 'HTTP/1.1' && !isset($g->server['HTTP_HOST'])) {
            return $app->renderError(400);
        }

        // RFC 9110 §15.6.2 / Apache server/protocol.c:1253 — a method the server
        // does not recognise gets 501 Not Implemented, not 404. This distinguishes
        // "I don't know this verb" from "no such resource". Known methods (incl.
        // HEAD/OPTIONS/TRACE and the WebDAV verbs) fall through to normal routing,
        // where an unmatched-but-known method still resolves to 404/405/fallback.
        if (!in_array($method, App::KNOWN_METHODS, true)) {
            return $app->renderError(501);
        }

        // Apache LimitRequestFields — reject requests that carry more header
        // fields than the configured limit. Apache enforces this at
        // ap_get_mime_headers_core (protocol.c:930-940) with a 400 response.
        // We replicate it here at the PHP layer after OpenSwoole has parsed the
        // header array. A limit of 0 disables the check (unlimited).
        if (App::$limit_request_fields > 0) {
            $headerCount = 0;
            foreach (array_keys($g->server) as $sk) {
                if (str_starts_with((string)$sk, 'HTTP_')) {
                    $headerCount++;
                }
            }
            if ($headerCount > App::$limit_request_fields) {
                return $app->renderError(400);
            }
        }

        // Apache PATH_INFO — `/script.php/extra/path` exposes `/extra/path` to
        // the script and rewrites REQUEST_URI to just the script. Triggers
        // only when the literal `.php/` appears in the URL (WordPress/Drupal
        // permalink style); implicit-extension routing is unaffected.
        if (App::$path_info && strpos($path, '.php/') !== false) {
            [$scriptPath, $extra] = explode('.php/', $path, 2);
            $scriptPath .= '.php';
            $docRoot = App::resolveDocumentRoot();
            $abs = realpath($docRoot . $scriptPath);
            if ($abs && is_file($abs) && strpos($abs, $docRoot) === 0) {
                $g->server['PATH_INFO']       = '/' . $extra;
                $g->server['PATH_TRANSLATED'] = $docRoot . '/' . $extra;
                $g->server['SCRIPT_NAME']     = $scriptPath;
                $qs = parse_url($uri, PHP_URL_QUERY);
                // When ignore_php_ext is on, the `.php` URI would hit the 403-block
                // route — strip the extension so the implicit file route resolves it.
                $rewritten = App::$ignore_php_ext
                    ? substr($scriptPath, 0, -4)
                    : $scriptPath;
                $uri = $rewritten . ($qs ? '?' . $qs : '');
                $g->server['REQUEST_URI'] = $uri;
                // Keep $path in lock-step with the rewritten resource so the
                // App::when() path-scope match (below) sees exactly what the
                // router will dispatch — otherwise a `.php/extra` PATH_INFO URL
                // could be matched by `when()` against the pre-rewrite path and
                // a path-scoped auth guard could be evaded.
                $path = $rewritten;
            }
        }

        // TRACE — disabled by default (XST attack vector). Apache's compiled
        // default is On, but ZealPHP ships TraceEnable Off as a hardening choice.
        // Set App::traceEnabled(true) to opt into the Apache ap_send_http_trace()
        // behaviour: echo the request back as a message/http body.
        if ($method === 'TRACE') {
            if (!App::$trace_enabled) {
                response_set_status(405);
                response_add_header('Allow', 'GET, HEAD, POST, PUT, DELETE, OPTIONS, PATCH');
                return new Response('', 405);
            }
            $req = $g->zealphp_request;
            // Apache (non-extended TraceEnable On) refuses a request body with
            // 413 — only AP_TRACE_EXTENDED echoes it, and ZealPHP's boolean knob
            // maps to the non-extended mode (http_filters.c:1082).
            $bodyRaw = $req instanceof \ZealPHP\HTTP\Request ? $req->rawContent() : null;
            if (is_string($bodyRaw) && $bodyRaw !== '') {
                return $app->renderError(413);
            }
            $headers = ($req instanceof \ZealPHP\HTTP\Request && is_array($req->header))
                ? $req->header
                : [];
            $body = self::buildTraceEcho(
                $method,
                $uri,
                (string)($g->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1'),
                $headers
            );
            response_set_status(200);
            response_add_header('Content-Type', 'message/http');
            return new Response($body, 200);
        }

        // Apache: RewriteCond %{REQUEST_FILENAME} !-d
        //         RewriteRule ^(.+)/$ /$1 [R=301,L]
        // When stripTrailingSlash() is on and the URI ends in `/` but does not
        // map to a directory under document_root, 301-redirect to the no-slash
        // form. Directory URIs are left alone so the existing DirectorySlash
        // path (serveDirectory()) keeps working. Only safe for GET/HEAD per
        // RFC 9110 §15.4.2 — POST/PUT/DELETE pass through unchanged.
        if (App::$strip_trailing_slash
            && ($method === 'GET' || $method === 'HEAD')
            && $path !== '/'
            && substr($path, -1) === '/') {
            $docRoot = App::resolveDocumentRoot();
            $candidate = realpath($docRoot . rtrim($path, '/'));
            if ($candidate === false || !is_dir($candidate)) {
                $newPath = rtrim($path, '/');
                $qs = parse_url($uri, PHP_URL_QUERY);
                $location = $newPath . ($qs ? '?' . $qs : '');
                // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                $g->zealphp_response->redirect($location, 301);
                $g->_streaming = true;
                return new Response('', 301);
            }
        }

        // OPTIONS — return allowed methods for this URI without running a handler
        if ($method === 'OPTIONS') {
            // RFC 9110 §9.3.7 / Apache http_core.c:336 — `OPTIONS *` is a
            // server-wide capability probe ("HTTP pong"), not resource-specific:
            // 200 with an empty body and no Allow header. The request target `*`
            // arrives as the raw REQUEST_URI (query string never applies to `*`).
            if ($uri === '*') {
                response_set_status(200);
                return new Response('', 200);
            }
            $allowed = ['OPTIONS'];
            foreach ($app->routesByMethod() as $m => $routes) {
                foreach ($routes as $route) {
                    if (preg_match($route['pattern'], $uri)) {
                        $allowed[] = $m;
                        if ($m === 'GET') $allowed[] = 'HEAD';
                        break;
                    }
                }
            }
            $allowed = array_unique($allowed);
            response_set_status(204);
            response_add_header('Allow', implode(', ', $allowed));
            return new Response('', 204);
        }

        // App::when() path-scoped middleware wraps route match + dispatch. It
        // runs AFTER the OPTIONS/preflight handling above (so a `when` auth
        // guard never blocks a CORS preflight) and matches the NORMALIZED path.
        // Fast path: nothing registered or nothing matches → straight to
        // matchAndDispatch(), byte-identical to the pre-feature behaviour.
        $whenChain = App::resolveWhenMiddleware($path);
        if ($whenChain === []) {
            return $this->matchAndDispatch($request, $method);
        }
        $pathHandler = new PathDispatchHandler($this, $method);
        foreach (array_reverse($whenChain) as $mw) {
            $pathHandler = new MiddlewareFrame($mw, $pathHandler);
        }
        return $pathHandler->handle($request);
    }

    /**
     * Match the (normalized) request URI against the route table and dispatch:
     * exact-path match, then pattern match, else 405 / fallback / 404. Reads the
     * normalized URI from `$g->server['REQUEST_URI']`. Extracted from process()
     * so `App::when()` path-scoped middleware can wrap it — it is also the
     * terminal of that onion (`PathDispatchHandler`).
     */
    public function matchAndDispatch(ServerRequestInterface $request, string $method): ResponseInterface
    {
        $g = RequestContext::instance();
        $uri = (string)$g->server['REQUEST_URI'];
        $app = App::instance();
        assert($app !== null);

        // HEAD — match GET routes, run the handler, strip the body
        $matchMethod = ($method === 'HEAD') ? 'GET' : $method;

        $exactRoutes = $app->routesByExactMethod();
        if (isset($exactRoutes[$matchMethod][$uri])) {
            return $this->dispatchRoute($exactRoutes[$matchMethod][$uri], [], $method, $request);
        }

        foreach ($app->routesByMethod()[$matchMethod] ?? [] as $route) {
            if (preg_match($route['pattern'], $uri, $matches)) {
                $params = array_filter($matches, fn($k) => !is_numeric($k), ARRAY_FILTER_USE_KEY);
                return $this->dispatchRoute($route, $params, $method, $request);
            }
        }
        // RFC 9110 §15.5.6: the URI matches a registered route for some method
        // but not this one → 405 Method Not Allowed + an `Allow` header listing
        // the supported methods (distinct from 404 = "no such resource"). The
        // implicit static routes are GET/HEAD/POST-only, so PUT/DELETE/PATCH on a
        // file-style path correctly 405s (Apache's static handler does the same);
        // a path matching no route at all falls through to the fallback / 404.
        $allowed = [];
        foreach ($app->routesByMethod() as $m => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['pattern'], $uri)) {
                    $allowed[] = $m;
                    if ($m === 'GET') {
                        $allowed[] = 'HEAD';
                    }
                    break;
                }
            }
        }
        if ($allowed !== []) {
            $allowed[] = 'OPTIONS';
            response_add_header('Allow', implode(', ', array_values(array_unique($allowed))));
            return $app->renderError(405);
        }

        $fallback = App::getFallback();
        if ($fallback !== null) {
            return $this->dispatchRoute($fallback, [], $method);
        }
        return $app->renderError(404);
    }
}
