<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\RequestContext;

/**
 * Pins the v0.2.41 WP-on-proc regression fix (issue #18).
 *
 * The Composer autoloader load inside `cgi_worker.php` (added between v0.2.0
 * and v0.2.20 for issue #17) costs ~30 ms on every subprocess spawn. That
 * cost causes WordPress's `wp_cron()` non-blocking HTTP self-call (10 ms
 * timeout) to queue at the parent faster than workers can drain — deadlocking
 * the OpenSwoole worker pool on the 2nd request onwards.
 *
 * The fix: gate the autoloader load on `ZEALPHP_CGI_AUTOLOAD=1` env var,
 * which is set by `App::buildCgiEnv()` ONLY when `App::cgiSubprocessAutoload()`
 * is opted in. Default: OFF. Restores v0.2.0 zero-overhead subprocess start.
 *
 * Tests:
 *   1. Default is `false` (the regression fix — pre-v0.2.20 parity)
 *   2. Setter round-trips bool
 *   3. `buildCgiEnv()` omits the env var when off
 *   4. `buildCgiEnv()` sets the env var to '1' when on
 *   5. `cgi_worker.php` source still has the env-var guard
 *      (canary against a future refactor accidentally removing it)
 */
final class CgiSubprocessAutoloadTest extends TestCase
{
    private bool $origAutoload;
    private bool $origCwdSet = false;

    protected function setUp(): void
    {
        $this->origAutoload = App::$cgi_subprocess_autoload;
        App::$cgi_subprocess_autoload = false;
        // App::$cwd is typed non-nullable; needed by buildCgiEnv() (resolves
        // PATH_TRANSLATED). Set if uninitialised — tests don't boot App::init().
        $rp = new \ReflectionProperty(App::class, 'cwd');
        if (!$rp->isInitialized()) {
            App::$cwd = sys_get_temp_dir();
            $this->origCwdSet = true;
        }
    }

    protected function tearDown(): void
    {
        App::$cgi_subprocess_autoload = $this->origAutoload;
        // Leave $cwd set — other tests may rely on it being initialised by now.
    }

    public function testDefaultIsFalse(): void
    {
        // The whole point of the fix — DEFAULT must be off so unmodified
        // WordPress / Drupal / Joomla work out-of-the-box on cgiMode('proc').
        // If a future change flips the default to true, this test fails loud
        // and the WP regression returns.
        $rc = new \ReflectionClass(App::class);
        $defaults = $rc->getDefaultProperties();
        $this->assertFalse(
            $defaults['cgi_subprocess_autoload'] ?? null,
            'App::$cgi_subprocess_autoload default MUST stay false (issue #18 — WP-on-proc regression fix)'
        );
    }

    public function testSetterRoundTripsBool(): void
    {
        $this->assertFalse(App::cgiSubprocessAutoload(), 'starts off');
        App::cgiSubprocessAutoload(true);
        $this->assertTrue(App::cgiSubprocessAutoload(), 'on after true');
        App::cgiSubprocessAutoload(false);
        $this->assertFalse(App::cgiSubprocessAutoload(), 'off after false');
    }

    public function testBuildCgiEnvOmitsEnvVarWhenAutoloadIsOff(): void
    {
        App::cgiSubprocessAutoload(false);
        $env = $this->callBuildCgiEnv(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'],
            '{}'
        );
        $this->assertArrayNotHasKey(
            'ZEALPHP_CGI_AUTOLOAD',
            $env,
            'env var must NOT be set when autoload is off (the regression fix)'
        );
    }

    public function testBuildCgiEnvSetsEnvVarToOneWhenAutoloadIsOn(): void
    {
        App::cgiSubprocessAutoload(true);
        $env = $this->callBuildCgiEnv(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'],
            '{}'
        );
        $this->assertSame(
            '1',
            $env['ZEALPHP_CGI_AUTOLOAD'] ?? null,
            'opt-in must set ZEALPHP_CGI_AUTOLOAD=1 in subprocess env'
        );
    }

    /**
     * Source-level canary: if a future refactor accidentally removes the
     * env-var guard in cgi_worker.php, the WP regression returns silently.
     * This test makes that breakage visible at PHPUnit time.
     */
    public function testCgiWorkerStillGuardsAutoloadOnEnvVar(): void
    {
        $cgiWorker = file_get_contents(__DIR__ . '/../../src/cgi_worker.php');
        $this->assertIsString($cgiWorker, 'src/cgi_worker.php must be readable');
        $this->assertStringContainsString(
            "getenv('ZEALPHP_CGI_AUTOLOAD') === '1'",
            $cgiWorker,
            'cgi_worker.php must keep the autoload guard (issue #18 regression fix)'
        );
    }

    /**
     * Drive `buildCgiEnv()` via reflection (private static). Pure logic —
     * no I/O, no subprocess spawn — so safe to call directly.
     *
     * @param array<string,mixed> $server
     * @return array<string,string>
     */
    private function callBuildCgiEnv(array $server, string $ctx): array
    {
        $rm = new \ReflectionMethod(App::class, 'buildCgiEnv');
        $rm->setAccessible(true);
        /** @var array<string,string> $env */
        $env = $rm->invoke(null, $server, $ctx);
        return $env;
    }
}
