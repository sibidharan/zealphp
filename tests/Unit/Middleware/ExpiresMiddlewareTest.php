<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\ExpiresMiddleware;
use ZealPHP\Tests\TestCase;

class ExpiresMiddlewareTest extends TestCase
{
    public function testStampsExpiresForMatchingType(): void
    {
        $mw = new ExpiresMiddleware(['image/' => '+30 days']);
        $response = $this->invoke($mw, 'image/png');

        $expires = $response->getHeaderLine('Expires');
        $this->assertNotSame('', $expires);
        $this->assertMatchesRegularExpression(
            '/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/',
            $expires
        );
    }

    public function testLongestPrefixWins(): void
    {
        $mw = new ExpiresMiddleware([
            'image/'      => '+1 hour',
            'image/jpeg'  => '+30 days',
        ]);
        $response  = $this->invoke($mw, 'image/jpeg');
        $expiresTs = strtotime($response->getHeaderLine('Expires'));

        // +30 days lands well past +1 hour — use a generous margin.
        $this->assertGreaterThan(time() + 86400, $expiresTs);
    }

    public function testFallsBackToDefault(): void
    {
        $mw = new ExpiresMiddleware([], '+5 minutes');
        $response = $this->invoke($mw, 'text/plain');

        $this->assertNotSame('', $response->getHeaderLine('Expires'));
    }

    public function testSkipsUnmatchedWhenNoDefault(): void
    {
        $mw = new ExpiresMiddleware(['image/' => '+1 day']);
        $response = $this->invoke($mw, 'text/plain');

        $this->assertFalse($response->hasHeader('Expires'));
    }

    public function testRespectsExistingExpiresHeader(): void
    {
        $mw = new ExpiresMiddleware(['text/html' => '+1 year']);
        $response = $this->invoke($mw, 'text/html', ['Expires' => 'Wed, 01 Jan 2025 00:00:00 GMT']);

        $this->assertSame('Wed, 01 Jan 2025 00:00:00 GMT', $response->getHeaderLine('Expires'));
    }

    private function invoke(ExpiresMiddleware $mw, string $contentType, array $extra = []): ResponseInterface
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $headers = array_merge(['Content-Type' => $contentType], $extra);
        $handler = new class($headers) implements RequestHandlerInterface {
            public function __construct(private array $headers) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('body', 200, '', $this->headers);
            }
        };
        return $mw->process(new ServerRequest('/', 'GET'), $handler);
    }
}
