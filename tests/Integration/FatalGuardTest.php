<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * #338 — a worker-killing FATAL mid-request must answer the held connection
 * with HTTP 500 (mod_php parity) instead of leaving the client to time out
 * (the HTTP-000 mystery hang that made ext-zealphp#36 a multi-day chase).
 *
 * Requires the server to run with ZEALPHP_FATAL_TEST=1 (the /demo/fatal500
 * crash endpoint is gated off otherwise — these tests SKIP cleanly when the
 * gate is closed, e.g. in the default CI server boot).
 */
class FatalGuardTest extends TestCase
{
    private function gateOpen(): bool
    {
        $probe = $this->get('/demo/fatal500');

        return ($probe['status'] ?? 0) !== 403;
    }

    public function testFatalAnswersWith500NotTimeout(): void
    {
        if (! $this->gateOpen()) {
            $this->markTestSkipped('ZEALPHP_FATAL_TEST=1 not set on the server — crash endpoint gated off');
        }

        $t0 = microtime(true);
        $res = $this->get('/demo/fatal500');
        $elapsed = microtime(true) - $t0;

        $this->assertSame(500, $res['status'], 'fatal must yield HTTP 500, never HTTP 0/timeout');
        $this->assertLessThan(
            5.0,
            $elapsed,
            'the 500 must arrive promptly from the dying worker, not after a client-side timeout'
        );
        $this->assertStringContainsString('Internal Server Error', $res['body']);
        // No fatal detail leaks to the client — the message goes to the error log.
        $this->assertStringNotContainsString('must be compatible', $res['body']);
    }

    public function testWorkerRespawnsAndServiceContinues(): void
    {
        if (! $this->gateOpen()) {
            $this->markTestSkipped('ZEALPHP_FATAL_TEST=1 not set on the server — crash endpoint gated off');
        }

        $this->get('/demo/fatal500'); // kill a worker
        // OpenSwoole's manager respawns the worker; the very next requests
        // must be served normally.
        usleep(300_000);
        $ok = 0;
        for ($i = 0; $i < 5; $i++) {
            $res = $this->get('/demo/inject/request-only');
            if (($res['status'] ?? 0) === 200) {
                $ok++;
            }
        }

        $this->assertGreaterThanOrEqual(4, $ok, 'service must continue after the worker respawn');
    }
}
