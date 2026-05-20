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

/**
 * Extra htpasswd-file coverage for BasicAuthMiddleware that the existing
 * BasicAuthMiddlewareTest.php does not reach: the APR1 ($apr1$) verification
 * path, the crypt() fallback ($6$ / $1$), file-missing / unreadable handling,
 * the htpasswd mtime cache, multiple users in one file, the unknown-user miss,
 * and realm quoting in the challenge.
 *
 * Avoids name collision with BasicAuthMiddlewareTest by living in its own
 * class. Temp htpasswd files are tracked and unlinked in tearDown so the
 * ordering-sensitive full suite leaks nothing.
 */
class BasicAuthFileTest extends TestCase
{
    /** @var string[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            @unlink($file);
        }
        $this->cleanup = [];
        parent::tearDown();
    }

    // ── APR1 ($apr1$) — htpasswd -m, Apache default ──────────────────

    public function testHtpasswdApr1Accepts(): void
    {
        // The middleware ships its own APR1 implementation; generate a hash
        // through that same implementation so the file carries a value the
        // verifier will accept (openssl/apache APR1 output differs in salt
        // packing). Exercises the $apr1$ branch + crypt_apr1_md5().
        $hash = $this->apr1('secret', 'abcdefgh');
        $file = $this->writeHtpasswd("carol:{$hash}\n");

        $mw = new BasicAuthMiddleware(htpasswdFile: $file);
        $response = $mw->process($this->reqWithBasic('carol', 'secret'), $this->okHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testHtpasswdApr1RejectsWrongPassword(): void
    {
        $hash = $this->apr1('secret', 'abcdefgh');
        $file = $this->writeHtpasswd("carol:{$hash}\n");

        $mw = new BasicAuthMiddleware(htpasswdFile: $file);
        $response = $mw->process($this->reqWithBasic('carol', 'nope'), $this->okHandler());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testHtpasswdApr1ShortPasswordSinglePass(): void
    {
        // Empty-ish / very short password exercises the inner length loops of
        // crypt_apr1_md5 with $len <= 16 (single substr pass).
        $hash = $this->apr1('x', 'saltsalt');
        $file = $this->writeHtpasswd("dave:{$hash}\n");

        $mw = new BasicAuthMiddleware(htpasswdFile: $file);
        $this->assertSame(200, $mw->process($this->reqWithBasic('dave', 'x'), $this->okHandler())->getStatusCode());
    }

    // ── crypt() fallback ($6$ SHA-512, $1$ MD5) ──────────────────────

    public function testHtpasswdCryptSha512Fallback(): void
    {
        // No $2y$ / $apr1$ / {SHA} prefix → crypt() fallback branch.
        $hash = crypt('secret', '$6$rounds=1000$abcdefgh$');
        $file = $this->writeHtpasswd("erin:{$hash}\n");

        $mw = new BasicAuthMiddleware(htpasswdFile: $file);
        $this->assertSame(200, $mw->process($this->reqWithBasic('erin', 'secret'), $this->okHandler())->getStatusCode());
    }

    public function testHtpasswdCryptMd5Fallback(): void
    {
        $hash = crypt('secret', '$1$abcdefgh$');
        $file = $this->writeHtpasswd("frank:{$hash}\n");

        $mw = new BasicAuthMiddleware(htpasswdFile: $file);
        $this->assertSame(200, $mw->process($this->reqWithBasic('frank', 'secret'), $this->okHandler())->getStatusCode());
        $this->assertSame(401, $mw->process($this->reqWithBasic('frank', 'bad'), $this->okHandler())->getStatusCode());
    }

    // ── multiple users + unknown-user miss ───────────────────────────

    public function testHtpasswdMultipleUsers(): void
    {
        $aliceHash = password_hash('alicepw', PASSWORD_BCRYPT);
        $bobHash   = '{SHA}' . base64_encode(sha1('bobpw', true));
        $file = $this->writeHtpasswd("alice:{$aliceHash}\nbob:{$bobHash}\n");

        $mw = new BasicAuthMiddleware(htpasswdFile: $file);
        $this->assertSame(200, $mw->process($this->reqWithBasic('alice', 'alicepw'), $this->okHandler())->getStatusCode());
        $this->assertSame(200, $mw->process($this->reqWithBasic('bob', 'bobpw'), $this->okHandler())->getStatusCode());
        // Cross-credentials rejected.
        $this->assertSame(401, $mw->process($this->reqWithBasic('alice', 'bobpw'), $this->okHandler())->getStatusCode());
    }

    public function testHtpasswdUnknownUserRejected(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $file = $this->writeHtpasswd("alice:{$hash}\n");

        $mw = new BasicAuthMiddleware(htpasswdFile: $file);
        $this->assertSame(401, $mw->process($this->reqWithBasic('mallory', 'secret'), $this->okHandler())->getStatusCode());
    }

    // ── file-missing / unreadable → loadHtpasswd returns null → reject ─

    public function testMissingHtpasswdFileRejects(): void
    {
        $missing = sys_get_temp_dir() . '/zealphp_no_such_htpasswd_' . uniqid() . '.txt';
        $mw = new BasicAuthMiddleware(htpasswdFile: $missing);

        // filemtime() on a missing file → false → loadHtpasswd null → 401.
        $response = $mw->process($this->reqWithBasic('alice', 'secret'), $this->okHandler());
        $this->assertSame(401, $response->getStatusCode());
    }

    // ── mtime cache: second verify reuses parsed map ─────────────────

    public function testHtpasswdCacheReusedAcrossRequests(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $file = $this->writeHtpasswd("alice:{$hash}\n");

        $mw = new BasicAuthMiddleware(htpasswdFile: $file);
        // First call parses + caches; second call hits the mtime-equal cache
        // branch (htpasswdCache !== null && htpasswdMtime === mtime).
        $this->assertSame(200, $mw->process($this->reqWithBasic('alice', 'secret'), $this->okHandler())->getStatusCode());
        $this->assertSame(200, $mw->process($this->reqWithBasic('alice', 'secret'), $this->okHandler())->getStatusCode());
    }

    // ── realm quoting in the challenge ───────────────────────────────

    public function testRealmWithQuotesIsEscapedInChallenge(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $file = $this->writeHtpasswd("alice:{$hash}\n");

        $mw = new BasicAuthMiddleware(htpasswdFile: $file, realm: 'My "Secure" Zone');
        $response = $mw->process($this->reqWithBasic('alice', 'wrong'), $this->okHandler());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            'Basic realm="My \\"Secure\\" Zone"',
            $response->getHeaderLine('WWW-Authenticate')
        );
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function apr1(string $password, string $salt): string
    {
        // Drive the middleware's own crypt_apr1_md5() so the resulting hash is
        // exactly what verifyHtpasswd() will compare against.
        $mw = new BasicAuthMiddleware(verify: fn() => true);
        $ref = new \ReflectionMethod($mw, 'crypt_apr1_md5');
        $ref->setAccessible(true);
        /** @var string $hash */
        $hash = $ref->invoke($mw, $password, '$apr1$' . $salt . '$');
        return $hash;
    }

    private function req(): ServerRequestInterface
    {
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
        $file = tempnam(sys_get_temp_dir(), 'zealphp_htpasswd_extra_');
        file_put_contents($file, $contents);
        $this->cleanup[] = $file;
        return $file;
    }
}
