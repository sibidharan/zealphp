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
 * IP Access Middleware
 *
 * Allow/deny request access by client IP. Supports literal IPv4/IPv6 and CIDR
 * notation (`10.0.0.0/8`, `2001:db8::/32`). The wildcard `'*'` matches any IP
 * — useful to express "allow everyone except deny list" or "deny by default".
 *
 * Apache 2.2 equivalent (legacy):
 *
 * ```
 * Order Deny,Allow
 * Deny from all
 * Allow from 10.0.0.0/8 127.0.0.1
 * ```
 *
 * Apache 2.4+ / nginx equivalent:
 *
 * ```
 * Require ip 10.0.0.0/8 127.0.0.1                  # Apache 2.4+
 * allow 10.0.0.0/8; allow 127.0.0.1; deny all;     # nginx
 * ```
 *
 * Resolution rules:
 *   1. If `deny` matches the IP → `403` (deny wins ties)
 *   2. Else if `allow` is non-empty and doesn't match → `403`
 *   3. Else → pass through
 *
 * So `['allow' => ['10.0.0.0/8'], 'deny' => []]` is "allow-list only";
 * `['allow' => ['*'], 'deny' => ['1.2.3.4']]` is "deny-list only";
 * `['allow' => ['10.0.0.0/8'], 'deny' => ['10.1.2.3']]` is "allow the
 * subnet except this specific host".
 *
 * Note on proxied apps: reads `$g->server['REMOTE_ADDR']`. If you're behind
 * Traefik/Caddy/nginx, that's the proxy IP, not the real client. Use
 * `App::clientIp()` (once available) and pass the value into a custom
 * middleware, or terminate the trust at the proxy layer.
 *
 * Usage in `app.php`:
 *
 * ```php
 * // Allow only office network and CI
 * $app->addMiddleware(new \ZealPHP\Middleware\IpAccessMiddleware([
 *     'allow' => ['203.0.113.0/24', '198.51.100.42'],
 *     'deny'  => [],
 * ]));
 *
 * // Block a specific abuser, allow the rest
 * $app->addMiddleware(new \ZealPHP\Middleware\IpAccessMiddleware([
 *     'allow' => ['*'],
 *     'deny'  => ['1.2.3.4'],
 * ]));
 * ```
 */
class IpAccessMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private array $allow;
    /** @var string[] */
    private array $deny;

    /**
     * @param array{allow?: string[], deny?: string[]} $config
     */
    public function __construct(array $config = [])
    {
        $this->allow = $config['allow'] ?? ['*'];
        $this->deny  = $config['deny']  ?? [];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $this->clientIp($request);

        if ($this->matchesAny($ip, $this->deny)) {
            return $this->forbidden();
        }
        if (!empty($this->allow) && !$this->matchesAny($ip, $this->allow)) {
            return $this->forbidden();
        }
        return $handler->handle($request);
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $g = RequestContext::instance();
        $ip = (string)($g->server['REMOTE_ADDR'] ?? '');
        if ($ip !== '') {
            return $this->normalizeIp($ip);
        }
        // Fallback for PSR-7 test contexts where REMOTE_ADDR isn't populated
        // on $g — read off the ServerRequest's server params.
        $params = $request->getServerParams();
        $remote = $params['REMOTE_ADDR'] ?? '';
        return is_scalar($remote) ? $this->normalizeIp((string)$remote) : '';
    }

    /**
     * Collapse an IPv4-mapped IPv6 address (`::ffff:a.b.c.d`) to its IPv4 form.
     * On a dual-stack listener the kernel presents IPv4 peers in this mapped
     * form, so without this an IPv4 allow/deny rule would silently never match
     * the peer — a deny-list bypass. Non-mapped addresses are returned as-is.
     */
    private function normalizeIp(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16
            && str_starts_with($bin, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            $v4 = @inet_ntop(substr($bin, 12));
            if ($v4 !== false) {
                return $v4;
            }
        }
        return $ip;
    }

    /**
     * @param string[] $rules
     */
    private function matchesAny(string $ip, array $rules): bool
    {
        if ($ip === '') {
            return false;
        }
        foreach ($rules as $rule) {
            if ($rule === '*' || $this->normalizeIp($rule) === $ip) {
                return true;
            }
            if (str_contains($rule, '/') && $this->cidrMatch($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    private function cidrMatch(string $ip, string $cidr): bool
    {
        $parts  = explode('/', $cidr, 2);
        $subnet = $parts[0];
        $prefix = $parts[1] ?? '';
        // Fail CLOSED on a missing/malformed prefix. `(int)"abc"`/`(int)""` → 0,
        // and a /0 mask matches every address — so `10.0.0.0/abc`, a bare
        // `10.0.0.0/`, or `10.0.0.0` with no slash would allow/deny every client.
        if ($prefix === '' || !ctype_digit($prefix)) {
            return false;
        }
        $bits = (int)$prefix;

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($this->normalizeIp($subnet));
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false; // mixed v4/v6
        }
        if ($bits > strlen($ipBin) * 8) {
            return false; // prefix wider than the address family
        }

        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xff << (8 - $rem)) & 0xff);
        return (($ipBin[$bytes] & $mask) === ($subnetBin[$bytes] & $mask));
    }

    private function forbidden(): ResponseInterface
    {
        $g = RequestContext::instance();
        $g->status = 403;
        return new Response('Forbidden', 403, '', ['Content-Type' => 'text/plain']);
    }
}
