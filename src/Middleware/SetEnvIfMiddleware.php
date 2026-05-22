<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

/**
 * SetEnvIf Middleware — Apache mod_setenvif parity.
 *
 * Sets request "environment" variables (into `$g->server`, where mod_php code
 * reads them as `$_SERVER`) when an attribute of the request matches a regex.
 * The classic use is tagging bots, internal IPs, or URL areas so downstream
 * middleware / handlers can branch on a simple flag.
 *
 * Attribute names mirror Apache: the special tokens `Remote_Addr`,
 * `Remote_Host`, `Server_Addr`, `Request_Method`, `Request_Protocol`,
 * `Request_URI`; any other name is treated as a request header (so
 * `User-Agent` gives `BrowserMatch` behavior).
 *
 * Apache equivalent:
 *
 * ```
 * SetEnvIf User-Agent "bot" IS_BOT=1
 * SetEnvIf Request_URI "^/admin" ADMIN_AREA=1
 * BrowserMatch "MSIE" old_browser=1
 * ```
 *
 * Usage in app.php:
 *
 * ```php
 * $app->addMiddleware(new \ZealPHP\Middleware\SetEnvIfMiddleware([
 *     ['attr' => 'User-Agent',  'regex' => '#bot#i',     'set' => ['IS_BOT' => '1']],
 *     ['attr' => 'Request_URI', 'regex' => '#^/admin#',  'set' => ['ADMIN_AREA' => '1']],
 * ]));
 * ```
 */
class SetEnvIfMiddleware implements MiddlewareInterface
{
    /** @var list<array{attr: string, regex: string, set: array<string, string>}> */
    private array $rules = [];

    /**
     * @param list<array<string, mixed>> $rules Each rule: `attr` (string),
     *        `regex` (PCRE string), `set` (map of env var => value).
     */
    public function __construct(array $rules)
    {
        foreach ($rules as $r) {
            $attr  = (isset($r['attr']) && is_string($r['attr'])) ? $r['attr'] : null;
            $regex = (isset($r['regex']) && is_string($r['regex'])) ? $r['regex'] : null;
            $set   = (isset($r['set']) && is_array($r['set'])) ? $r['set'] : null;
            if ($attr === null || $regex === null || $set === null) {
                continue;
            }
            $clean = [];
            foreach ($set as $k => $v) {
                $clean[(string) $k] = is_scalar($v) ? (string) $v : '';
            }
            $this->rules[] = ['attr' => $attr, 'regex' => $regex, 'set' => $clean];
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->rules !== []) {
            $g = RequestContext::instance();
            foreach ($this->rules as $rule) {
                $value = $this->attribute($request, $g, $rule['attr']);
                if (preg_match($rule['regex'], $value) === 1) {
                    foreach ($rule['set'] as $k => $v) {
                        $g->server[$k] = $v;
                    }
                }
            }
        }
        return $handler->handle($request);
    }

    private function attribute(ServerRequestInterface $request, RequestContext $g, string $attr): string
    {
        return match (strtolower($attr)) {
            'remote_addr'                    => self::str($g->server['REMOTE_ADDR'] ?? ''),
            'remote_host'                    => self::str($g->server['REMOTE_HOST'] ?? ''),
            'server_addr'                    => self::str($g->server['SERVER_ADDR'] ?? ''),
            'request_method'                 => $request->getMethod(),
            'request_uri'                    => $request->getUri()->getPath(),
            'request_protocol', 'server_protocol' => self::str($g->server['SERVER_PROTOCOL'] ?? ''),
            default                          => $request->getHeaderLine($attr),
        };
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
