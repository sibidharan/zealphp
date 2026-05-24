<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\RequestContext;

/**
 * Exercise the `App::cgiPool()` dispatch path end-to-end (real subprocess
 * pool, real IPC, real response application via RequestContext stub).
 *
 * cgiPool() is `private static` so we drive it via Reflection — that's the
 * coverage path codecov/patch needs to count the cgiPool method body as
 * exercised. The 16 WorkerPool unit tests already pin the IPC/pool layer;
 * these focus on the App-side glue (request frame build, response shape
 * coercion, header / cookie / status capture-and-apply).
 */
final class CgiPoolDispatchTest extends TestCase
{
    private static string $tmpDir = '';
    private ?\ReflectionMethod $cgiPool = null;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/zealphp-cgipool-test-' . bin2hex(random_bytes(3));
        if (!is_dir(self::$tmpDir)) {
            mkdir(self::$tmpDir, 0775, true);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (App::$cgi_pool_instance !== null) {
            App::$cgi_pool_instance->close();
            App::$cgi_pool_instance = null;
        }
        if (is_dir(self::$tmpDir)) {
            foreach (glob(self::$tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir(self::$tmpDir);
        }
    }

    protected function setUp(): void
    {
        // Fresh pool per test — tests use small pool sizes.
        if (App::$cgi_pool_instance !== null) {
            App::$cgi_pool_instance->close();
            App::$cgi_pool_instance = null;
        }
        App::$cgi_pool_size         = 1;
        App::$cgi_pool_max_requests = 50;

        // Reset RequestContext fields that cgiPool reads.
        $g = RequestContext::instance();
        $g->server  = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        $g->get     = [];
        $g->post    = [];
        $g->cookie  = [];
        $g->files   = [];
        $g->status  = 200;
        $g->zealphp_response = $this->makeResponseStub();

        // Bind reflection to private static cgiPool.
        $rm = new \ReflectionMethod(App::class, 'cgiPool');
        $rm->setAccessible(true);
        $this->cgiPool = $rm;
    }

    /**
     * Minimal response stub — accepts the same header()/cookie()/rawCookie()
     * shape as ZealPHP\HTTP\Response and records calls for assertions.
     */
    private function makeResponseStub(): object
    {
        return new class {
            /** @var list<array{0:string,1:string}> */
            public array $headers = [];
            /** @var list<array<int,mixed>> */
            public array $cookies = [];
            /** @var list<array<int,mixed>> */
            public array $rawCookies = [];

            public function header(string $name, string $value): void
            {
                $this->headers[] = [$name, $value];
            }

            public function cookie(...$args): void
            {
                $this->cookies[] = $args;
            }

            public function rawCookie(...$args): void
            {
                $this->rawCookies[] = $args;
            }
        };
    }

    private function fixture(string $name, string $body): string
    {
        $path = self::$tmpDir . '/' . $name;
        file_put_contents($path, "<?php\n" . $body);
        return $path;
    }

    public function testSimpleEchoDispatchReturnsBody(): void
    {
        $file = $this->fixture('echo.php', 'echo "hello pool";');
        $result = $this->cgiPool->invoke(null, $file);
        $this->assertSame('hello pool', $result);
    }

    public function testHeaderCaptureFlowsToResponseStub(): void
    {
        $file = $this->fixture('header.php', 'header("X-Pool-Test: yes"); echo "ok";');
        $stub = RequestContext::instance()->zealphp_response;
        $this->cgiPool->invoke(null, $file);
        $headerNames = array_column((array) $stub->headers, 0);
        $this->assertContains('X-Pool-Test', $headerNames);
    }

    public function testCookieCaptureFlowsToResponseStub(): void
    {
        $file = $this->fixture('cookie.php', 'setcookie("sid", "abc123", time() + 3600, "/"); echo "ok";');
        $stub = RequestContext::instance()->zealphp_response;
        $this->cgiPool->invoke(null, $file);
        // The first positional arg of each captured cookie call is the name.
        $names = array_map(static fn (array $a) => (string) ($a[0] ?? ''), (array) $stub->cookies);
        $this->assertContains('sid', $names);
    }

    public function testStatusFromHttpResponseCodeFlowsThrough(): void
    {
        $file = $this->fixture('status.php', 'http_response_code(404); echo "not found";');
        $g    = RequestContext::instance();
        $g->status = 200;
        $result = $this->cgiPool->invoke(null, $file);
        $this->assertSame(404, $g->status);
        $this->assertSame('not found', $result);
    }

    public function testArrayReturnFlowsViaUniversalReturnContract(): void
    {
        $file = $this->fixture('json.php', 'return ["status" => "ok", "n" => 7];');
        $result = $this->cgiPool->invoke(null, $file);
        $this->assertSame(['status' => 'ok', 'n' => 7], $result);
    }

    public function testMissingFileResultsIn404Or500FromPool(): void
    {
        // pool_worker handles missing files with 404; cgiPool surfaces that
        // shape via the universal return contract (body string OR status).
        $g = RequestContext::instance();
        $g->status = 200;
        $result = $this->cgiPool->invoke(null, '/nonexistent/missing.php');
        $this->assertSame(404, $g->status);
        $this->assertIsString($result);
    }

    public function testGetSuperglobalReachesSubprocess(): void
    {
        $file = $this->fixture('get.php', 'echo $_GET["q"] ?? "missing";');
        $g    = RequestContext::instance();
        $g->get = ['q' => 'fromparent'];
        $result = $this->cgiPool->invoke(null, $file);
        $this->assertSame('fromparent', $result);
    }

    // ── Setter coverage: cgiPoolSize / cgiPoolMaxRequests ────────────

    public function testCgiPoolSizeSetterRoundtrips(): void
    {
        $original = \ZealPHP\App::cgiPoolSize();
        \ZealPHP\App::cgiPoolSize(8);
        $this->assertSame(8, \ZealPHP\App::cgiPoolSize());
        \ZealPHP\App::cgiPoolSize($original);
    }

    public function testCgiPoolSizeClampsToOneMinimum(): void
    {
        $original = \ZealPHP\App::cgiPoolSize();
        \ZealPHP\App::cgiPoolSize(0);   // setter has max(1, ...) guard
        $this->assertSame(1, \ZealPHP\App::cgiPoolSize());
        \ZealPHP\App::cgiPoolSize(-5);  // negative also clamps to 1
        $this->assertSame(1, \ZealPHP\App::cgiPoolSize());
        \ZealPHP\App::cgiPoolSize($original);
    }

    public function testCgiPoolMaxRequestsSetterRoundtrips(): void
    {
        $original = \ZealPHP\App::cgiPoolMaxRequests();
        \ZealPHP\App::cgiPoolMaxRequests(1000);
        $this->assertSame(1000, \ZealPHP\App::cgiPoolMaxRequests());
        \ZealPHP\App::cgiPoolMaxRequests($original);
    }

    public function testCgiPoolMaxRequestsClampsToOneMinimum(): void
    {
        $original = \ZealPHP\App::cgiPoolMaxRequests();
        \ZealPHP\App::cgiPoolMaxRequests(0);
        $this->assertSame(1, \ZealPHP\App::cgiPoolMaxRequests());
        \ZealPHP\App::cgiPoolMaxRequests($original);
    }

    // ── Cookie capture: associative shape via setcookie($options) ───

    public function testSetcookieAssociativeShapeFlowsToResponseStub(): void
    {
        // PHP 7.3+ setcookie($name, $value, ['expires' => …, 'path' => …, 'samesite' => 'Lax', …])
        // — captured by pool_worker as an associative array. cgiPool's
        // applyCookie helper must narrow it back to the positional shape
        // ZealPHP\HTTP\Response::cookie() expects.
        $file = $this->fixture('cookie-assoc.php', <<<'PHP'
setcookie('sid', 'xyz789', [
    'expires'  => time() + 3600,
    'path'     => '/',
    'domain'   => '',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
echo "assoc-ok";
PHP);
        $stub = RequestContext::instance()->zealphp_response;
        $result = $this->cgiPool->invoke(null, $file);
        $this->assertSame('assoc-ok', $result);
        // First arg of the cookie() call should be the name 'sid'
        $this->assertNotEmpty($stub->cookies);
        $this->assertSame('sid', $stub->cookies[0][0] ?? null);
    }

}
