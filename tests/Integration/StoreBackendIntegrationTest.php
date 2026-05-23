<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Integration smoke for the Store backend through the full HTTP request
 * lifecycle. Exercises the demo /demo/store-roundtrip route which uses
 * Store::set/get/incr/exists/del through whichever backend the running
 * server has configured (env var ZEALPHP_STORE_BACKEND or app.php).
 *
 * This is the single-node case — the spec's two-node visibility test
 * (one writer + one reader on a shared valkey) is a Phase-2 follow-up
 * that needs the multi-process test harness scaffolding.
 */
final class StoreBackendIntegrationTest extends TestCase
{
    public function testStoreRoundtripThroughTheRequestLifecycle(): void
    {
        $r = $this->get('/demo/store-roundtrip');
        $this->assertStatus(200, $r);
        $body = $this->assertJsonResponse($r);

        $this->assertIsString($body['backend'], 'backend class reported in JSON');
        $this->assertStringContainsString('Backend', (string) $body['backend']);

        // SET → row visible
        $this->assertIsArray($body['set']);
        $this->assertSame(42, $body['set']['score']);
        $this->assertStringStartsWith('rt_', (string) $body['set']['name']);

        // INCR by 8 → 42 + 8 = 50
        $this->assertIsArray($body['incr']);
        $this->assertSame(50, $body['incr']['score']);

        // EXISTS true before DEL
        $this->assertTrue($body['existed']);

        // DEL → next get returns the BC `false`
        $this->assertFalse($body['after_del']);
    }
}
