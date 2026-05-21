<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

/**
 * Request Header Middleware — Apache mod_headers `RequestHeader` parity.
 *
 * Manipulates the request headers the application sees, before handlers run.
 * Headers are written into `$g->server` using the mod_php CGI convention
 * (`HTTP_<NAME>`, uppercased, dashes → underscores), so `apache_request_headers()`,
 * `getallheaders()`, and `$g->server['HTTP_*']` reflect the change — the same
 * place mod_php exposes inbound headers.
 *
 * Operations (Apache `RequestHeader <op> Name [value]`):
 *   - `set`            — replace (or create) the header
 *   - `append` / `add` — append to the existing value (comma-joined) or create
 *   - `unset`          — remove the header
 *
 * Usage in app.php:
 *   $app->addMiddleware(new \ZealPHP\Middleware\RequestHeaderMiddleware([
 *       ['op' => 'set',    'name' => 'X-Forwarded-Proto', 'value' => 'https'],
 *       ['op' => 'unset',  'name' => 'X-Debug'],
 *   ]));
 */
class RequestHeaderMiddleware implements MiddlewareInterface
{
    /** @var list<array{op: string, name: string, value: string}> */
    private array $rules = [];

    /**
     * @param list<array<string, mixed>> $rules Each: `op` (set|append|add|unset),
     *        `name` (header name), `value` (required except for unset).
     */
    public function __construct(array $rules)
    {
        foreach ($rules as $r) {
            $op   = (isset($r['op']) && is_string($r['op'])) ? strtolower($r['op']) : null;
            $name = (isset($r['name']) && is_string($r['name'])) ? $r['name'] : null;
            if ($op === null || $name === null) {
                continue;
            }
            $value = (isset($r['value']) && is_scalar($r['value'])) ? (string) $r['value'] : '';
            $this->rules[] = ['op' => $op, 'name' => $name, 'value' => $value];
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->rules !== []) {
            $g = RequestContext::instance();
            foreach ($this->rules as $rule) {
                $key = 'HTTP_' . strtoupper(strtr($rule['name'], '-', '_'));
                switch ($rule['op']) {
                    case 'unset':
                        unset($g->server[$key]);
                        break;
                    case 'append':
                    case 'add':
                        $existing = $g->server[$key] ?? null;
                        $g->server[$key] = (is_string($existing) && $existing !== '')
                            ? $existing . ', ' . $rule['value']
                            : $rule['value'];
                        break;
                    case 'set':
                    default:
                        $g->server[$key] = $rule['value'];
                        break;
                }
            }
        }
        return $handler->handle($request);
    }
}
