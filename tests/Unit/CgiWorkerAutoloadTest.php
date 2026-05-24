<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pins the proc-mode CGI worker's autoload contract — UPDATED for issue #18.
 *
 * Issue #17 (original): cgi_worker.php must support loading the Composer
 * autoloader so files dispatched through it can use `\ZealPHP\App` and
 * framework classes.
 *
 * Issue #18 (fix shipped on top of #17): the autoload load costs ~30 ms per
 * subprocess spawn, which deadlocks WordPress's wp_cron 10 ms-timeout self-
 * call pattern. So autoload is now GATED on `ZEALPHP_CGI_AUTOLOAD=1` env var
 * (default off, opt in via `App::cgiSubprocessAutoload(true)`).
 *
 * This file now pins BOTH contracts:
 *   - autoload OFF by default (issue #18 fix — WordPress works out-of-box)
 *   - autoload ON when env var is set (issue #17 preserved for opt-in apps)
 */
final class CgiWorkerAutoloadTest extends TestCase
{
    private string $worker;

    protected function setUp(): void
    {
        $this->worker = dirname(__DIR__, 2) . '/src/cgi_worker.php';
        if (!is_file($this->worker)) {
            $this->markTestSkipped('cgi_worker.php not found');
        }
    }

    /**
     * Run a probe file through the CGI worker and return its stdout body.
     *
     * @param array<string,string> $extraEnv  Extra env vars merged after the default minimal env.
     */
    private function runWorker(string $probeBody, array $extraEnv = []): string
    {
        $probe = tempnam(sys_get_temp_dir(), 'zeal_cgi_probe_') . '.php';
        file_put_contents($probe, $probeBody);
        try {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $env = array_merge(['ZEALPHP_REQUEST_CONTEXT' => '{}'], $extraEnv);
            $proc = proc_open(
                PHP_BINARY . ' ' . escapeshellarg($this->worker) . ' ' . escapeshellarg($probe),
                $descriptors,
                $pipes,
                null,
                $env
            );
            $this->assertIsResource($proc, 'failed to start cgi_worker subprocess');
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);   // drain stderr metadata channel
            proc_close($proc);
            return $stdout;
        } finally {
            @unlink($probe);
        }
    }

    /**
     * Issue #18 fix: by DEFAULT (no env var), the autoloader does not load.
     * `\ZealPHP\App` is NOT available inside the subprocess. This is the
     * v0.2.0 behaviour, restored to fix the WP-on-proc regression.
     */
    public function testWorkerDoesNotLoadAutoloaderByDefault(): void
    {
        $out = $this->runWorker(
            '<?php echo class_exists(\\ZealPHP\\App::class) ? "AUTOLOAD_OK" : "AUTOLOAD_MISSING";'
        );
        $this->assertStringContainsString('AUTOLOAD_MISSING', $out, 'default must be NO autoload (issue #18 fix)');
        $this->assertStringNotContainsString('AUTOLOAD_OK', $out);
    }

    /**
     * Issue #17 preserved: when the parent sets ZEALPHP_CGI_AUTOLOAD=1
     * (which `App::buildCgiEnv()` does iff `cgiSubprocessAutoload(true)`),
     * the autoloader loads and ZealPHP classes are available inside the
     * subprocess.
     */
    public function testWorkerLoadsAutoloaderWhenEnvVarIsSet(): void
    {
        $out = $this->runWorker(
            '<?php echo class_exists(\\ZealPHP\\App::class) ? "AUTOLOAD_OK" : "AUTOLOAD_MISSING";',
            ['ZEALPHP_CGI_AUTOLOAD' => '1']
        );
        $this->assertStringContainsString('AUTOLOAD_OK', $out, 'opt-in autoload must load framework');
        $this->assertStringNotContainsString('AUTOLOAD_MISSING', $out);
    }

    public function testWorkerStillEchoesBodyForPlainFile(): void
    {
        // The autoload gate must not disturb the normal body-capture path —
        // a plain echo file still streams its output to stdout regardless.
        $out = $this->runWorker('<?php echo "plain-body-ok";');
        $this->assertStringContainsString('plain-body-ok', $out, 'body capture works without autoload');
        $outWithAutoload = $this->runWorker(
            '<?php echo "plain-body-ok-with-autoload";',
            ['ZEALPHP_CGI_AUTOLOAD' => '1']
        );
        $this->assertStringContainsString('plain-body-ok-with-autoload', $outWithAutoload, 'body capture works WITH autoload');
    }
}
