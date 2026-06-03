<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\CGI\Dispatcher;
use ZealPHP\RequestContext;

/**
 * Framework-level proof for cgiMode('fork'): drives Dispatcher::cgiFork() end to
 * end — App lazily spawns the ForkPool, builds the request frame, the fork-master
 * forks a child at global scope, and the shared response-apply rides back. Covers
 * the wiring App::cgiMode('fork') routes to.
 */
final class DispatcherForkTest extends TestCase
{
    private static string $tmpDir = '';
    /** @var int|bool|null */
    private $origPi;

    public static function setUpBeforeClass(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        self::$tmpDir = sys_get_temp_dir() . '/zealphp_dispfork_' . getmypid();
        @mkdir(self::$tmpDir, 0777, true);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$tmpDir !== '' && is_dir(self::$tmpDir)) {
            foreach (glob(self::$tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir(self::$tmpDir);
        }
    }

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            $this->markTestSkipped('fork mode requires pcntl + posix');
        }
        $this->origPi = App::$process_isolation;
        App::$process_isolation = false; // keep mintCgiSession() a no-op

        $g = RequestContext::instance();
        $g->server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        $g->get = [];
        $g->post = [];
        $g->cookie = [];
        $g->files = [];
        $g->env = [];
        $g->status = null;
        $g->zealphp_response = new class {
            /** @var array<string,string> */
            public array $headers = [];
            public function header(mixed $k, mixed $v): void { $this->headers[(string) $k] = (string) $v; }
            public function cookie(mixed ...$a): void {}
            public function rawCookie(mixed ...$a): void {}
            public function __call(string $n, array $a): mixed { return true; }
        };
        $g->zealphp_request = new class {
            public object $parent;
            public function __construct()
            {
                $this->parent = new class {
                    public function getContent(): string { return ''; }
                };
            }
        };
    }

    protected function tearDown(): void
    {
        App::$cgi_fork_instance?->close();
        App::$cgi_fork_instance = null;
        App::$process_isolation = $this->origPi;
    }

    private function fixture(string $name, string $php): string
    {
        $path = self::$tmpDir . '/' . $name;
        file_put_contents($path, $php);
        return $path;
    }

    public function testCgiForkReturnsBody(): void
    {
        $f = $this->fixture('echo.php', "<?php\necho 'DISPATCH-FORK';\n");
        $this->assertSame('DISPATCH-FORK', Dispatcher::cgiFork($f));
        $this->assertInstanceOf(\ZealPHP\CGI\ForkPool::class, App::$cgi_fork_instance);
    }

    public function testCgiForkNoRedeclareAcrossRequests(): void
    {
        // The framework-level proof: routing a class-declaring file through
        // cgiFork repeatedly never "Cannot redeclare" — fresh fork each time.
        $f = $this->fixture('cls.php', "<?php\nclass DispatchForkProof {}\necho 'ok';\n");
        $this->assertSame('ok', Dispatcher::cgiFork($f));
        $this->assertSame('ok', Dispatcher::cgiFork($f));
    }

    public function testCgiForkAppliesStatusAndHeaders(): void
    {
        $f = $this->fixture('hdr.php', "<?php\nhttp_response_code(201);\nheader('X-DF: 1');\necho 'made';\n");
        $out = Dispatcher::cgiFork($f);
        $this->assertSame('made', $out);
        $this->assertSame(201, RequestContext::instance()->status, 'status from the fork response frame is applied');
    }

    public function testCgiForkExplicitReturnValue(): void
    {
        $f = $this->fixture('ret.php', "<?php\nreturn 404;\n");
        $this->assertSame(404, Dispatcher::cgiFork($f));
    }
}
