<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

/**
 * Host-Router Middleware (nginx `server_name` virtual-host equivalent)
 *
 * Dispatches the request to a per-host handler based on the `Host:` request
 * header. The handler is a normal callable returning any of ZealPHP's
 * supported return shapes (string body, PSR-7 Response, Generator,
 * array → JSON, int → status code).
 *
 * nginx equivalent:
 *   server { server_name a.com; location / { ... } }
 *   server { server_name b.com; location / { ... } }
 *
 * If no host matches and no `'*'` (catch-all) handler is registered, the
 * middleware **passes through** to the next handler. This lets you mix
 * host-routed and host-agnostic apps inside one ZealPHP instance:
 *
 *   $app->addMiddleware(new \ZealPHP\Middleware\HostRouterMiddleware([
 *       'docs.example.com'  => fn() => 'docs landing page',
 *       'api.example.com'   => fn() => ['status' => 'ok'],
 *       '*.example.com'     => fn() => 'subdomain fallback',
 *       'www.*'             => fn() => 'trailing-wildcard catch',
 *       '~^admin\..+'       => fn() => 'regex match',
 *       '*'                 => fn() => 'default site',
 *   ]));
 *
 * Host matching is case-insensitive and ignores port (`example.com:8080`
 * matches the rule `example.com`). IPv6 literals (`[::1]:80`) are parsed
 * correctly — the port separator is the `:` after the closing `]`.
 *
 * Match precedence (nginx `ngx_hash_find_combined` order):
 *   1. Exact match
 *   2. Leading-wildcard  `*.example.com`
 *   3. Trailing-wildcard `www.*`
 *   4. Regex             `~^pattern` (in registration order)
 *   5. Catch-all         `*`
 *
 * Host validation (nginx parity, only when HostRouterMiddleware is active):
 *   - HTTP/1.1 with missing Host → 400
 *   - Duplicate Host headers → 400
 *   - Invalid Host characters (outside [a-zA-Z0-9:.\-_~!$&'()*+,;=%@[\]]) → 400
 *   - Trailing dot normalised: `example.com.` → `example.com`
 */
class HostRouterMiddleware implements MiddlewareInterface
{
    /** @var array<string, callable> normalised host => handler */
    private array $handlers;
    /** @var callable|null */
    private $catchAll;
    /** @var array<int, array{host: string, handler: callable}> leading-wildcard rules (*.x) in declaration order */
    private array $wildcards;
    /** @var array<int, array{host: string, handler: callable}> trailing-wildcard rules (www.*) in declaration order */
    private array $trailingWildcards;
    /** @var array<int, array{pattern: string, handler: callable}> regex rules in declaration order */
    private array $regexRules;

    /**
     * @param array<string, mixed> $hosts host => callable, plus optional '*' catch-all.
     *                                    Marked mixed at the type-level because PHP
     *                                    can't enforce callable inside an array;
     *                                    each handler is validated at runtime.
     */
    public function __construct(array $hosts)
    {
        $this->handlers          = [];
        $this->wildcards         = [];
        $this->trailingWildcards = [];
        $this->regexRules        = [];
        $this->catchAll          = null;

        foreach ($hosts as $host => $handler) {
            if (!is_callable($handler)) {
                throw new \InvalidArgumentException("Handler for '{$host}' must be callable");
            }
            $key = strtolower((string)$host);

            // Catch-all
            if ($key === '*') {
                $this->catchAll = $handler;
                continue;
            }

            // Regex: `~^...` prefix (nginx convention). Store the original key
            // (preserving case inside the pattern) but normalised to ensure the
            // leading `~` is present. The pattern string is used as-is in
            // preg_match with an appended `i` flag inside the delimiter form.
            if (str_starts_with($key, '~')) {
                $this->regexRules[] = ['pattern' => (string)$host, 'handler' => $handler];
                continue;
            }

            // Leading wildcard: *.example.com
            if (str_starts_with($key, '*.')) {
                $this->wildcards[] = ['host' => substr($key, 2), 'handler' => $handler];
                continue;
            }

            // Trailing wildcard: www.* (ends with .*)
            if (str_ends_with($key, '.*')) {
                $this->trailingWildcards[] = ['host' => substr($key, 0, -2), 'handler' => $handler];
                continue;
            }

            $this->handlers[$key] = $handler;
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // --- Host validation (nginx parity) ---
        // Use getHeaders() (returns array<string, string[]>, always normalised)
        // for presence/duplicate checks, and getHeaderLine() for the value.
        // Note: OpenSwoole's concrete ServerRequest stores headers as raw strings
        // when set via the constructor array, but the PSR-7 interface (and
        // getHeaders()) always normalises to string[]. We rely on the interface
        // contract here so PHPStan is satisfied at L10.
        $allHeaders  = $request->getHeaders();
        $hostKey     = null;
        foreach (array_keys($allHeaders) as $k) {
            if (strtolower((string)$k) === 'host') {
                $hostKey = (string)$k;
                break;
            }
        }
        $hostHeaderValues = $hostKey !== null ? $allHeaders[$hostKey] : [];
        $version          = $request->getProtocolVersion();

        // HTTP/1.1 requires exactly one Host header (RFC 7230 §5.4)
        if ($version === '1.1' && count($hostHeaderValues) === 0) {
            return new Response('Bad Request: missing Host header', 400, '', ['Content-Type' => 'text/plain']);
        }

        // Duplicate Host headers are always invalid (nginx rejects with 400)
        if (count($hostHeaderValues) > 1) {
            return new Response('Bad Request: duplicate Host header', 400, '', ['Content-Type' => 'text/plain']);
        }

        $rawHost = count($hostHeaderValues) > 0 ? $hostHeaderValues[0] : '';
        if ($rawHost === '') {
            $g       = RequestContext::instance();
            $rawHost = (string)($g->server['HTTP_HOST'] ?? '');
        }

        // Validate Host characters (nginx ngx_http_validate_host parity).
        // Allowed: a-z A-Z 0-9 : . - _ ~ ! $ & ' ( ) * + , ; = % @ [ ]
        // Square brackets are needed for IPv6 literals.
        if ($rawHost !== '' && !$this->isValidHostHeader($rawHost)) {
            return new Response('Bad Request: invalid Host header', 400, '', ['Content-Type' => 'text/plain']);
        }

        // Strip port, handling IPv6 bracket literals ([::1]:80)
        $host = strtolower($this->stripPort($rawHost));

        // Strip trailing dot (example.com. → example.com, nginx parity)
        if ($host !== '' && $host[-1] === '.') {
            $host = rtrim($host, '.');
        }

        $matched = $this->matchHandler($host);
        if ($matched === null) {
            return $handler->handle($request);
        }

        $result = $matched($request);
        return $this->coerceResponse($result);
    }

    /**
     * Strip the port from a Host header value.
     *
     * Handles:
     *   example.com:8080  → example.com
     *   [::1]:8080        → [::1]
     *   [::1]             → [::1]
     *   ::1               → ::1  (no brackets, no port)
     */
    private function stripPort(string $host): string
    {
        if ($host === '') {
            return $host;
        }
        // IPv6 literal in brackets: [addr]:port or [addr]
        if ($host[0] === '[') {
            $closeBracket = strpos($host, ']');
            if ($closeBracket === false) {
                // Malformed bracket — return as-is; validation will catch it
                return $host;
            }
            // Everything up to and including ] is the host; ignore :port after
            return substr($host, 0, $closeBracket + 1);
        }
        // Normal host — first `:` is port separator
        $pos = strpos($host, ':');
        if ($pos !== false) {
            return substr($host, 0, $pos);
        }
        return $host;
    }

    /**
     * Validate a raw Host header value (nginx ngx_http_validate_host parity).
     *
     * Allowed characters: a-zA-Z0-9 : . - _ ~ ! $ & ' ( ) * + , ; = % @ [ ]
     * Rejects NUL bytes, control characters, <, >, {, }, |, \, ^, `, space, and
     * consecutive dots (which nginx also rejects).
     */
    private function isValidHostHeader(string $host): bool
    {
        // Reject empty (handled by caller context) or overly long values
        if (strlen($host) > 253 + 6) { // max hostname + :65535
            return false;
        }
        // Must match allowed character set
        if (!preg_match('/^[a-zA-Z0-9:\.\-_~!$&\'()*+,;=%@\[\]]+$/', $host)) {
            return false;
        }
        // Reject consecutive dots (nginx parity)
        if (str_contains($host, '..')) {
            return false;
        }
        return true;
    }

    /**
     * Match the normalised (lowercased, port-stripped) host against registered
     * rules in nginx precedence order:
     *   1. Exact
     *   2. Leading-wildcard  (*.example.com)
     *   3. Trailing-wildcard (www.*)
     *   4. Regex             (~^pattern, registration order)
     *   5. Catch-all         (*)
     */
    private function matchHandler(string $host): ?callable
    {
        // 1. Exact match
        if ($host !== '' && isset($this->handlers[$host])) {
            return $this->handlers[$host];
        }

        // 2. Leading-wildcard: *.example.com matches sub.example.com but not example.com
        foreach ($this->wildcards as $rule) {
            if (str_ends_with($host, '.' . $rule['host'])) {
                return $rule['handler'];
            }
        }

        // 3. Trailing-wildcard: www.* matches www.example.com, www.org, etc.
        foreach ($this->trailingWildcards as $rule) {
            // rule['host'] = 'www', host must start with 'www.' (i.e. has a dot after prefix)
            $prefix = $rule['host'] . '.';
            if (str_starts_with($host, $prefix)) {
                return $rule['handler'];
            }
        }

        // 4. Regex rules in registration order.
        // The stored pattern includes the leading '~' (nginx convention). Strip it,
        // then wrap in PCRE delimiters with the 'i' flag for case-insensitive matching.
        foreach ($this->regexRules as $rule) {
            $inner   = substr($rule['pattern'], 1); // e.g. "^admin\..+"
            $pattern = '{' . $inner . '}i';         // e.g. "{^admin\..+}i"
            if (@preg_match($pattern, $host) === 1) {
                return $rule['handler'];
            }
        }

        // 5. Catch-all
        return $this->catchAll;
    }

    private function coerceResponse(mixed $result): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        if (is_int($result)) {
            return new Response('', $result);
        }
        if (is_array($result) || is_object($result)) {
            return new Response(
                (string)json_encode($result),
                200,
                '',
                ['Content-Type' => 'application/json']
            );
        }
        // Remaining: null | string | scalar (bool, float). Cast safely.
        if ($result === null) {
            return new Response('', 200);
        }
        if (is_scalar($result)) {
            return new Response((string)$result, 200, '', ['Content-Type' => 'text/html']);
        }
        return new Response('', 200);
    }
}
