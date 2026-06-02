<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\CGI\ForkPool;

/**
 * Host-side proof for ForkPool — spawns a real fork_master and dispatches
 * request frames through it. Low memory (one master + a few short-lived forks).
 */
final class ForkPoolTest extends TestCase
{
    private static string $tmpDir = '';
    private ?ForkPool $pool = null;

    public static function setUpBeforeClass(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        self::$tmpDir = sys_get_temp_dir() . '/zealphp_forkpool_' . getmypid();
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
        try {
            $this->pool = new ForkPool(maxConcurrent: 8);
        } catch (\Throwable $e) {
            $this->markTestSkipped('ForkPool could not start: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->pool?->close();
        $this->pool = null;
    }

    private function fixture(string $name, string $php): string
    {
        $path = self::$tmpDir . '/' . $name;
        file_put_contents($path, $php);
        return $path;
    }

    /** @return array<string,mixed> */
    private function frameFor(string $file, string $body = ''): array
    {
        return [
            'file'    => $file,
            'server'  => ['REQUEST_METHOD' => $body === '' ? 'GET' : 'POST'],
            'get'     => [],
            'post'    => [],
            'cookies' => [],
            'files'   => [],
            'body'    => $body,
        ];
    }

    public function testDispatchReturnsBody(): void
    {
        $f = $this->fixture('echo.php', "<?php\necho 'POOL-FORK-OK';\n");
        $resp = $this->pool->dispatch($this->frameFor($f), 10.0);
        $this->assertSame('POOL-FORK-OK', $resp['body'] ?? null);
        $this->assertSame(200, $resp['status'] ?? null);
    }

    public function testFreshProcessPerDispatchNoRedeclare(): void
    {
        // The whole point: a class-declaring file dispatched repeatedly through
        // ForkPool never "Cannot redeclare" — each dispatch is a fresh fork.
        $f = $this->fixture('cls.php', "<?php\nclass ForkPoolProof {}\necho class_exists('ForkPoolProof') ? 'ok' : 'no';\n");
        for ($i = 0; $i < 3; $i++) {
            $resp = $this->pool->dispatch($this->frameFor($f), 10.0);
            $this->assertSame('ok', $resp['body'] ?? null, "dispatch #$i must succeed in a fresh process");
        }
    }

    public function testHeaderAndStatusCaptured(): void
    {
        $f = $this->fixture('hdr.php', "<?php\nhttp_response_code(201);\nheader('X-Fork: yes');\necho 'made';\n");
        $resp = $this->pool->dispatch($this->frameFor($f), 10.0);
        $this->assertSame('made', $resp['body'] ?? null);
        $this->assertSame(201, $resp['status'] ?? null);
        // Header capture needs uopz/ext-zealphp; assert only when present.
        if (\function_exists('uopz_set_return') || \function_exists('zealphp_override')) {
            $names = array_map(static fn ($h) => strtolower((string) ($h[0] ?? '')), (array) ($resp['headers'] ?? []));
            $this->assertContains('x-fork', $names, 'header() must be captured into the response frame');
        }
    }

    public function testIsAliveReflectsMasterState(): void
    {
        $this->assertTrue($this->pool->isAlive());
        $this->pool->close();
        $this->assertFalse($this->pool->isAlive());
    }
}
