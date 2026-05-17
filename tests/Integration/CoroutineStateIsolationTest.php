<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Critical invariant: under coroutine mode, $g->get / $g->post / etc. are
 * per-coroutine — writes inside one request must NOT leak to another. This
 * is the load-bearing property that lets routes and includes manipulate
 * request state safely without serialising every worker.
 *
 * The route /_contract/co-state reads ?cid=N, awaits (forcing concurrent
 * scheduling on the worker), re-writes $g->get['cid'], then App::include()s
 * a fixture that echoes "CID:N" back. Under 100-way concurrent load every
 * response must match its own cid.
 *
 * The companion /coroutines#state-parity page documents this rule for
 * humans; this test is the executable proof.
 */
class CoroutineStateIsolationTest extends TestCase
{
    public function testGStateIsPerCoroutineUnderConcurrentLoad(): void
    {
        $n = 100;
        $multi = curl_multi_init();
        $handles = [];

        for ($i = 0; $i < $n; $i++) {
            $ch = curl_init(self::$baseUrl . '/_contract/co-state?cid=' . $i);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => false,
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$i] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 0.05);
            }
        } while ($running > 0);

        $mismatches = [];
        foreach ($handles as $i => $ch) {
            $body = curl_multi_getcontent($ch);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            $expected = "CID:$i";
            if (trim((string)$body) !== $expected) {
                $mismatches[$i] = $body;
            }
        }
        curl_multi_close($multi);

        $this->assertEmpty(
            $mismatches,
            "g state leaked between coroutines for " . count($mismatches) . "/$n requests. "
                . "First 5 mismatches: " . json_encode(array_slice($mismatches, 0, 5, true))
        );
    }
}
