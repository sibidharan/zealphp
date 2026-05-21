<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\Middleware\RefererMiddleware;
use ZealPHP\Tests\TestCase;

class RefererMiddlewareTest extends TestCase
{
    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('ok', 200);
            }
        };
    }

    /** @param list<string> $referers */
    private function check(array $referers, string $refererHeader, bool $allowNone = true, bool $allowBlocked = true): int
    {
        $req = new ServerRequest('/img.png', 'GET');
        if ($refererHeader !== '__absent__') {
            $req = $req->withHeader('Referer', $refererHeader);
        }
        $mw = new RefererMiddleware($referers, $allowNone, $allowBlocked);
        return $mw->process($req, $this->handler())->getStatusCode();
    }

    public function testExactHostAllowed(): void
    {
        $this->assertSame(200, $this->check(['example.com'], 'https://example.com/page'));
    }

    public function testForeignHostBlocked(): void
    {
        $this->assertSame(403, $this->check(['example.com'], 'https://evil.com/steal'));
    }

    public function testWildcardSubdomain(): void
    {
        $this->assertSame(200, $this->check(['*.example.com'], 'https://cdn.example.com/x'));
        $this->assertSame(200, $this->check(['*.example.com'], 'https://example.com/x')); // bare too
    }

    public function testWildcardSuffix(): void
    {
        $this->assertSame(200, $this->check(['example.*'], 'http://example.org/x'));
    }

    public function testRegexSpec(): void
    {
        $this->assertSame(200, $this->check(['~\.google\.'], 'https://www.google.com/'));
        $this->assertSame(403, $this->check(['~\.google\.'], 'https://bing.com/'));
    }

    public function testPathPrefix(): void
    {
        $this->assertSame(200, $this->check(['example.org/galleries/'], 'https://example.org/galleries/a.jpg'));
        $this->assertSame(403, $this->check(['example.org/galleries/'], 'https://example.org/private/a.jpg'));
    }

    public function testMissingRefererAllowedByDefault(): void
    {
        $this->assertSame(200, $this->check(['example.com'], '__absent__'));
    }

    public function testMissingRefererBlockedWhenNoneDisallowed(): void
    {
        $this->assertSame(403, $this->check(['example.com'], '__absent__', allowNone: false));
    }

    public function testBlockedRefererAllowedByDefault(): void
    {
        // Present but scheme-less (proxy-stripped) ⇒ "blocked" ⇒ allowed by default.
        $this->assertSame(200, $this->check(['example.com'], 'android-app://com.example'));
    }

    public function testPortIgnoredInHostMatch(): void
    {
        $this->assertSame(200, $this->check(['example.com'], 'https://example.com:8443/x'));
    }
}
