<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\IpAccessMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class IpAccessMiddlewareTest extends TestCase
{
    public function testAllowsExactIpMatch(): void
    {
        $response = $this->invoke(
            new IpAccessMiddleware(['allow' => ['203.0.113.5']]),
            '203.0.113.5'
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDeniesUnmatchedIpWhenAllowListSet(): void
    {
        $response = $this->invoke(
            new IpAccessMiddleware(['allow' => ['203.0.113.5']]),
            '198.51.100.42'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDenyListBlocksMatchingIp(): void
    {
        $response = $this->invoke(
            new IpAccessMiddleware(['allow' => ['*'], 'deny' => ['1.2.3.4']]),
            '1.2.3.4'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDenyListLetsOtherIpsThrough(): void
    {
        $response = $this->invoke(
            new IpAccessMiddleware(['allow' => ['*'], 'deny' => ['1.2.3.4']]),
            '5.6.7.8'
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCidrAllow(): void
    {
        $response = $this->invoke(
            new IpAccessMiddleware(['allow' => ['10.0.0.0/8']]),
            '10.42.99.7'
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCidrDeniesOutsideSubnet(): void
    {
        $response = $this->invoke(
            new IpAccessMiddleware(['allow' => ['10.0.0.0/8']]),
            '11.0.0.1'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testIpv6CidrMatch(): void
    {
        $response = $this->invoke(
            new IpAccessMiddleware(['allow' => ['2001:db8::/32']]),
            '2001:db8:1234::1'
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDenyTakesPrecedenceOverAllow(): void
    {
        $response = $this->invoke(
            new IpAccessMiddleware(['allow' => ['10.0.0.0/8'], 'deny' => ['10.1.2.3']]),
            '10.1.2.3'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    private function invoke(IpAccessMiddleware $mw, string $ip): ResponseInterface
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $g = RequestContext::instance();
        $g->server['REMOTE_ADDR'] = $ip;

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('ok', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process(
            (new ServerRequest('/', 'GET'))->withHeader('Host', 'example.com'),
            $handler
        );
    }
}
