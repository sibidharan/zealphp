<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\BasicAuthMiddleware;
use ZealPHP\Tests\TestCase;

class BasicAuthMiddlewareTest extends TestCase
{
    public function testRejectsMissingAuthorizationHeader(): void
    {
        $mw = new BasicAuthMiddleware(verify: fn() => true);
        $response = $mw->process($this->req(), $this->okHandler());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('Basic realm=', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testAcceptsValidCallback(): void
    {
        $mw = new BasicAuthMiddleware(
            verify: fn(string $u, string $p): bool => $u === 'alice' && $p === 'secret',
        );

        $response = $mw->process($this->reqWithBasic('alice', 'secret'), $this->okHandler());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRejectsBadCallback(): void
    {
        $mw = new BasicAuthMiddleware(
            verify: fn(string $u, string $p): bool => $u === 'alice' && $p === 'secret',
        );

        $response = $mw->process($this->reqWithBasic('alice', 'wrong'), $this->okHandler());
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testRejectsMalformedAuthorizationHeader(): void
    {
        $mw = new BasicAuthMiddleware(verify: fn() => true);
        $request = $this->req()->withHeader('Authorization', 'Bearer xxx');

        $response = $mw->process($request, $this->okHandler());
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testRejectsBlankUsername(): void
    {
        $mw = new BasicAuthMiddleware(verify: fn() => true);
        $request = $this->req()->withHeader('Authorization', 'Basic ' . base64_encode(':pw'));

        $response = $mw->process($request, $this->okHandler());
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testHtpasswdBcryptAccepts(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $file = $this->writeHtpasswd("alice:{$hash}\n");

        $mw = new BasicAuthMiddleware(htpasswdFile: $file);
        $response = $mw->process($this->reqWithBasic('alice', 'secret'), $this->okHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testHtpasswdBcryptRejectsWrongPassword(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $file = $this->writeHtpasswd("alice:{$hash}\n");

        $mw = new BasicAuthMiddleware(htpasswdFile: $file);
        $response = $mw->process($this->reqWithBasic('alice', 'wrong'), $this->okHandler());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testHtpasswdSha1Accepts(): void
    {
        $hash = '{SHA}' . base64_encode(sha1('secret', true));
        $file = $this->writeHtpasswd("bob:{$hash}\n");

        $mw = new BasicAuthMiddleware(htpasswdFile: $file);
        $response = $mw->process($this->reqWithBasic('bob', 'secret'), $this->okHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testHtpasswdSkipsCommentsAndBlankLines(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $file = $this->writeHtpasswd("# admin users\n\nalice:{$hash}\n# trailing comment\n");

        $mw = new BasicAuthMiddleware(htpasswdFile: $file);
        $response = $mw->process($this->reqWithBasic('alice', 'secret'), $this->okHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testConstructorRequiresOneSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BasicAuthMiddleware();
    }

    public function testCustomRealmAppearsInChallenge(): void
    {
        $mw = new BasicAuthMiddleware(verify: fn() => true, realm: 'Admin Area');
        $response = $mw->process($this->req(), $this->okHandler());

        $this->assertSame('Basic realm="Admin Area"', $response->getHeaderLine('WWW-Authenticate'));
    }

    private function req(): ServerRequestInterface
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        return new ServerRequest('/', 'GET');
    }

    private function reqWithBasic(string $user, string $pass): ServerRequestInterface
    {
        return $this->req()->withHeader('Authorization', 'Basic ' . base64_encode("{$user}:{$pass}"));
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('granted', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
    }

    private function writeHtpasswd(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'zealphp_htpasswd_');
        file_put_contents($file, $contents);
        // Schedule cleanup at the end of the test via teardown.
        $this->cleanup[] = $file;
        return $file;
    }

    /** @var string[] */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            @unlink($file);
        }
        $this->cleanup = [];
        parent::tearDown();
    }
}
