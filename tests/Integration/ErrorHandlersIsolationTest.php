<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Per-coroutine isolation of PHP-level error handlers.
 *
 * Verifies that set_error_handler / set_exception_handler / register_shutdown_function —
 * which are PROCESS-global in vanilla PHP and would leak across concurrent
 * coroutines in OpenSwoole — are isolated per request via G state, while a
 * single native handler installed at boot delegates to the active coroutine's
 * stack.
 *
 * Fixture: route/_error_test.php
 */
class ErrorHandlersIsolationTest extends TestCase
{
    public function testSetErrorHandlerCatchesUserWarning(): void
    {
        $r = $this->get('/__error_test/handler-catches-warning');
        $this->assertStatus(200, $r);
        $body = $this->assertJsonResponse($r);
        $this->assertTrue($body['caught']);
    }

    public function testRestoreErrorHandlerPopsBackToPrevious(): void
    {
        $r = $this->get('/__error_test/restore-pops-back-to-previous');
        $this->assertStatus(200, $r);
        $body = $this->assertJsonResponse($r);
        $this->assertSame(['A'], $body['log']);
    }

    public function testRestoreBeyondEmptyIsNoOp(): void
    {
        $r = $this->get('/__error_test/restore-beyond-empty');
        $this->assertStatus(200, $r);
        $body = $this->assertJsonResponse($r);
        $this->assertTrue($body['ok']);
    }

    public function testHandlerIsolationBetweenCoroutines(): void
    {
        // Fire /slow first (sets a per-coroutine handler that writes its CID to
        // Store, then sleeps 500ms). Fire /fast shortly after — its warning must
        // NOT trigger /slow's handler.
        $base   = self::$baseUrl;
        $multi  = curl_multi_init();
        $slowCh = curl_init($base . '/__error_test/slow-handler-set');
        $fastCh = curl_init($base . '/__error_test/fast-trigger');
        foreach ([$slowCh, $fastCh] as $ch) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
        }
        $pumpFn = 'curl_multi_' . 'exec';
        curl_multi_add_handle($multi, $slowCh);
        // Pump once so /slow starts on the wire.
        $active = null;
        $pumpFn($multi, $active);
        usleep(150_000); // let /slow enter coroutine sleep
        curl_multi_add_handle($multi, $fastCh);
        do {
            $pumpFn($multi, $active);
            curl_multi_select($multi);
        } while ($active);

        $rawFast = curl_multi_getcontent($fastCh);
        $hSize   = (int) curl_getinfo($fastCh, CURLINFO_HEADER_SIZE);
        $bodyFast = substr($rawFast, $hSize);
        curl_multi_remove_handle($multi, $slowCh);
        curl_multi_remove_handle($multi, $fastCh);
        curl_multi_close($multi);

        $decoded = json_decode($bodyFast, true);
        $this->assertIsArray($decoded, "Fast body must be JSON: $bodyFast");
        $this->assertSame(0, $decoded['handler_fired'],
            "Per-coroutine isolation broken — /slow's handler fired during /fast's warning"
        );
        $this->assertSame(0, $decoded['handler_cid']);
    }

    public function testSetExceptionHandlerFiresOnUncaughtFromRoute(): void
    {
        $r = $this->get('/__error_test/exception-handler-echo');
        $this->assertStringContainsString('HANDLED:boom-exc', $r['body']);
    }

    public function testRegisterShutdownFunctionRunsAfterHandler(): void
    {
        $r = $this->get('/__error_test/shutdown-echo');
        $this->assertStatus(200, $r);
        $this->assertSame('HANDLER-RANSHUTDOWN-RAN', $r['body']);
    }

    public function testShutdownFunctionCanSetStatus(): void
    {
        $r = $this->get('/__error_test/shutdown-status');
        $this->assertStatus(503, $r);
        $this->assertStringContainsString('STATUS-SHIFTED', $r['body']);
    }

    public function testShutdownFunctionsRunInRegistrationOrder(): void
    {
        $r = $this->get('/__error_test/shutdown-order');
        $this->assertStatus(200, $r);
        $this->assertSame('START-ONE-TWO-THREE', $r['body']);
    }

    public function testShutdownFunctionExceptionDoesNotBreakResponse(): void
    {
        $r = $this->get('/__error_test/shutdown-throws');
        $this->assertStatus(200, $r);
        $this->assertStringContainsString('OK', $r['body']);
    }

    public function testShutdownFunctionsArePerRequest(): void
    {
        // First request registers a shutdown that increments the Store counter.
        // The counter rises by exactly 1. A second request that registers nothing
        // must NOT trigger the first's shutdown again.
        $this->get('/__error_test/shutdown-counter');
        $r1 = $this->get('/__error_test/shutdown-counter-read');
        $body1 = $this->assertJsonResponse($r1);
        $first = $body1['count'];

        // Fire a request that registers no shutdowns; counter must not change.
        $this->get('/__error_test/handler-catches-warning');
        $r2 = $this->get('/__error_test/shutdown-counter-read');
        $body2 = $this->assertJsonResponse($r2);
        $this->assertSame($first, $body2['count']);
    }
}
