<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for issue #17: the proc-mode CGI worker
 * (src/cgi_worker.php) must load the Composer autoloader so that files
 * dispatched through it have the same class / global-function surface they
 * get in fork mode (which inherits the warm worker's autoloader via COW).
 *
 * Before the fix, `class_exists(\ZealPHP\App::class)` was false inside a
 * proc-mode CGI include because the worker subprocess never required
 * vendor/autoload.php.
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
     */
    private function runWorker(string $probeBody): string
    {
        $probe = tempnam(sys_get_temp_dir(), 'zeal_cgi_probe_') . '.php';
        file_put_contents($probe, $probeBody);
        try {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open(
                PHP_BINARY . ' ' . escapeshellarg($this->worker) . ' ' . escapeshellarg($probe),
                $descriptors,
                $pipes,
                null,
                ['ZEALPHP_REQUEST_CONTEXT' => '{}']
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

    public function testWorkerLoadsComposerAutoloader(): void
    {
        $out = $this->runWorker(
            '<?php echo class_exists(\\ZealPHP\\App::class) ? "AUTOLOAD_OK" : "AUTOLOAD_MISSING";'
        );
        $this->assertStringContainsString('AUTOLOAD_OK', $out);
        $this->assertStringNotContainsString('AUTOLOAD_MISSING', $out);
    }

    public function testWorkerStillEchoesBodyForPlainFile(): void
    {
        // The autoloader require must not disturb the normal body-capture
        // path — a plain echo file still streams its output to stdout.
        $out = $this->runWorker('<?php echo "plain-body-ok";');
        $this->assertStringContainsString('plain-body-ok', $out);
    }
}
