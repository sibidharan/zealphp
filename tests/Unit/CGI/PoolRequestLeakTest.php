<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\CGI\WorkerPool;

/**
 * C9 — what a reused pool subprocess keeps must not carry one user's data into
 * the next user's request.
 *
 * The pool deliberately keeps some process state between requests (ini
 * values, statics of code that stays loaded) so traditional apps run
 * unmodified and fast; that policy is not widened here. These tests pin the
 * three channels that did carry user data: `_`-prefixed app globals, error /
 * exception handlers, and putenv().
 */
final class PoolRequestLeakTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zptest-poolleak-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    /**
     * Run `$first` then `$second` on the same subprocess; return the second body.
     */
    private function sequence(string $first, string $second): string
    {
        $a = $this->tmpDir . '/a.php';
        $b = $this->tmpDir . '/b.php';
        file_put_contents($a, "<?php\n" . $first);
        file_put_contents($b, "<?php\n" . $second);
        $pool = new WorkerPool(size: 1, maxRequestsPerWorker: 100);
        try {
            $pool->dispatch(['file' => $a, 'cookies' => ['sid' => 'alice-sid']], 5.0);
            $resp = $pool->dispatch(['file' => $b], 5.0);
            $this->assertSame([2], $pool->servedCounts(), 'both requests ran on one subprocess');
            return (string) $resp['body'];
        } finally {
            $pool->close();
        }
    }

    public function testUnderscoreGlobalsDoNotReachTheNextUser(): void
    {
        $body = $this->sequence(
            '$_current_user = "alice"; $_auth = ["token" => "alice-secret"]; $GLOBALS["_cache"] = "alice@example.test"; echo "a";',
            'echo json_encode([$_current_user ?? null, $_auth ?? null, $GLOBALS["_cache"] ?? null, isset($_GET), isset($_SERVER)]);'
        );
        $this->assertSame('[null,null,null,true,true]', $body, 'request-scope `_` globals are cleared; superglobals stay');
    }

    public function testErrorAndExceptionHandlersDoNotRunInTheNextRequest(): void
    {
        $body = $this->sequence(
            '$who = "alice"; set_error_handler(function () use ($who) { echo "[handler of {$who}]"; return true; });'
            . ' set_error_handler(function () use ($who) { echo "[second handler of {$who}]"; return true; });'
            . ' set_exception_handler(function () use ($who) { echo "[exception handler of {$who}]"; }); echo "a";',
            'trigger_error("notice", E_USER_NOTICE);'
            . ' echo json_encode([set_error_handler(null), set_exception_handler(null)]);'
        );
        $this->assertSame('[null,null]', $body, 'no handler of the previous request is left, at any stack depth');
    }

    public function testHandlersSetByTheRequestStillWorkWithinIt(): void
    {
        $body = $this->sequence(
            'echo "a";',
            'set_error_handler(function ($n, $s) { echo "[caught {$s}]"; return true; }); trigger_error("x", E_USER_NOTICE); echo "b";'
        );
        $this->assertSame('[caught x]b', $body);
    }

    public function testPutenvDoesNotReachTheNextUser(): void
    {
        $body = $this->sequence(
            'putenv("ZP_LAST_USER=alice"); putenv("PATH=/alice/bin"); putenv("HOME"); echo "a";',
            'echo json_encode([getenv("ZP_LAST_USER"), getenv("PATH") === "/alice/bin", getenv("HOME") === false]);'
        );
        $this->assertSame('[false,false,false]', $body, 'added, changed and removed variables are all restored');
    }

    public function testCleanupKeepsWorkingRequestAfterRequest(): void
    {
        // The first version of the C9 change unset the cleanup's own snapshot
        // on the first reset (it is not in its own key list), which silently
        // disabled the per-request $GLOBALS cleanup from the second request on.
        // Two-request tests could not see that; this one runs six.
        $file = $this->tmpDir . '/seq.php';
        file_put_contents($file, "<?php\n"
            . 'echo json_encode([$plain ?? null, $_under ?? null]);'
            . ' $plain = $_GET["n"]; $_under = $_GET["n"];');
        $pool = new WorkerPool(size: 1, maxRequestsPerWorker: 100);
        try {
            for ($n = 1; $n <= 6; $n++) {
                $resp = $pool->dispatch(['file' => $file, 'get' => ['n' => "user{$n}"]], 5.0);
                $this->assertSame('[null,null]', $resp['body'], "request {$n} sees nothing of request " . ($n - 1));
            }
            $this->assertSame([6], $pool->servedCounts());
        } finally {
            $pool->close();
        }
    }
}
