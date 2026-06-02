<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\CGI\IPC;

/**
 * Functional proof for the fork-per-request CGI runner (src/fork_master.php).
 *
 * Spawns a real fork-master subprocess over a UNIX socket and sends it request
 * frames — each forks a FRESH child. Proves the value proposition at low cost:
 *   - the include runs at TRUE global scope (top-level vars become $GLOBALS —
 *     the issue #167 wp-admin fix),
 *   - a class declaration does NOT "Cannot redeclare" across requests (every
 *     request is a fresh process — the whole point of fork mode),
 *   - php://input (POST body) reaches the target,
 *   - the universal return-value contract rides back.
 *
 * Low memory by design: one fork-master + a few short-lived children.
 */
final class ForkServerTest extends TestCase
{
    /** @var resource|null */
    private $proc = null;
    /** @var array<int,resource> */
    private array $pipes = [];
    private string $sock = '';
    private string $tmpDir = '';

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            $this->markTestSkipped('fork mode requires pcntl + posix');
        }
        $this->tmpDir = sys_get_temp_dir() . '/zealphp_fork_' . getmypid() . '_' . uniqid();
        @mkdir($this->tmpDir, 0777, true);
        $this->sock = $this->tmpDir . '/fm.sock';

        $env = array_merge($_ENV, [
            'ZEALPHP_FORK_SOCK' => $this->sock,
            'ZEALPHP_CWD'       => ZEALPHP_ROOT,
        ]);
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, ZEALPHP_ROOT . '/src/fork_master.php'], $desc, $this->pipes, ZEALPHP_ROOT, $env);
        if (!is_resource($proc)) {
            $this->markTestSkipped('could not spawn fork_master');
        }
        $this->proc = $proc;

        // Wait for the READY line on stderr (bounds the dispatch-after-spawn window).
        stream_set_blocking($this->pipes[2], false);
        $ready = false;
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $line = fgets($this->pipes[2]);
            if (is_string($line) && strpos($line, 'ZEALPHP_FORK_SERVER_READY') !== false) {
                $ready = true;
                break;
            }
            usleep(20000);
        }
        $this->assertTrue($ready, 'fork_master must signal READY (pcntl/posix + socket bind ok)');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) {
            @proc_terminate($this->proc, 15); // SIGTERM → graceful loop exit
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline) {
                $st = proc_get_status($this->proc);
                if (!$st['running']) {
                    break;
                }
                usleep(20000);
            }
            $st = proc_get_status($this->proc);
            if ($st['running']) {
                @proc_terminate($this->proc, 9);
            }
            foreach ($this->pipes as $p) {
                if (is_resource($p)) {
                    @fclose($p);
                }
            }
            @proc_close($this->proc);
        }
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
    }

    private function fixture(string $name, string $php): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $php);
        return $path;
    }

    /**
     * Send one request frame; the master forks a child to handle it.
     *
     * @param array<string,mixed> $frame
     * @return array<mixed,mixed>|null
     */
    private function request(array $frame): ?array
    {
        $errno = 0;
        $errstr = '';
        $c = @stream_socket_client('unix://' . $this->sock, $errno, $errstr, 5.0);
        $this->assertIsResource($c, "connect to fork_master socket: $errstr");
        IPC::writeFrame($c, $frame);
        $resp = IPC::readFrame($c, 10.0);
        @fclose($c);
        return $resp;
    }

    private function frameFor(string $file, string $body = ''): array
    {
        return [
            'file'    => $file,
            'server'  => ['REQUEST_METHOD' => $body === '' ? 'GET' : 'POST', 'REQUEST_URI' => '/'],
            'get'     => [],
            'post'    => [],
            'cookies' => [],
            'files'   => [],
            'body'    => $body,
        ];
    }

    public function testEchoedBodyIsReturned(): void
    {
        $f = $this->fixture('echo.php', "<?php\necho 'FORK-OK';\n");
        $resp = $this->request($this->frameFor($f));
        $this->assertIsArray($resp);
        $this->assertSame('FORK-OK', $resp['body'] ?? null);
    }

    public function testClassDeclarationDoesNotRedeclareAcrossRequests(): void
    {
        // THE proof: a top-level class declaration. A REUSED process would fatal
        // "Cannot redeclare class ForkReuseProof" on the 2nd request; fork mode
        // gives a fresh process each time, so both succeed.
        $f = $this->fixture('cls.php', "<?php\nclass ForkReuseProof { public int \$v = 1; }\necho 'declared:' . (new ForkReuseProof)->v;\n");
        $r1 = $this->request($this->frameFor($f));
        $r2 = $this->request($this->frameFor($f));
        $this->assertSame('declared:1', $r1['body'] ?? null, 'request 1 declares the class');
        $this->assertSame('declared:1', $r2['body'] ?? null, 'request 2 re-declares in a FRESH process — no fatal');
    }

    public function testTopLevelVarBecomesGlobalScope(): void
    {
        // Mirrors WP wp-admin's `$menu` (top-level) + `global $menu; uksort($menu)`.
        // If the include ran inside a function, $arr would be a local and
        // `global $arr` would resolve to null. At true global scope it resolves.
        $f = $this->fixture('global.php', <<<'PHP'
<?php
$arr = ['b' => 2, 'a' => 1];
function fork_probe() { global $arr; return $arr === null ? 'NULL' : implode(',', array_keys($arr)); }
echo fork_probe();
PHP);
        $resp = $this->request($this->frameFor($f));
        $this->assertSame('b,a', $resp['body'] ?? null, 'top-level $arr must be a real global (the #167 fix)');
    }

    public function testPostBodyReachesPhpInput(): void
    {
        $f = $this->fixture('input.php', "<?php\necho file_get_contents('php://input');\n");
        $resp = $this->request($this->frameFor($f, 'hello=world&x=1'));
        $this->assertSame('hello=world&x=1', $resp['body'] ?? null);
    }

    public function testReturnValueRidesTheContract(): void
    {
        $f = $this->fixture('ret.php', "<?php\nreturn 404;\n");
        $resp = $this->request($this->frameFor($f));
        $this->assertIsArray($resp);
        $this->assertSame(404, $resp['return_value'] ?? null);
        $this->assertTrue($resp['has_return'] ?? false);
    }
}
