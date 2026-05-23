<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Patch-coverage for the v0.2.40 App pub/sub rename. The canonical
 * front-door names (`App::subscribe`, `App::unsubscribe`,
 * `App::subscribeReliable`, `App::publish`, `App::publishReliable`,
 * `App::addProcess`) ship alongside BC aliases (`onPubSub`/`offPubSub`/
 * `onReliableMessage`/`onProcess`). These tests verify the methods exist
 * on the App class so neither path silently breaks.
 *
 * Behavioral testing of the underlying machinery lives in the integration
 * smoke (scripts/smoke-v0.2.40.php) + the existing AppRedisBootChecksTest
 * which already exercises onPubSub end-to-end (deliberately uses the BC
 * alias to verify the alias path).
 */
final class AppPubSubAliasesTest extends TestCase
{
    private \ReflectionClass $r;

    protected function setUp(): void
    {
        $this->r = new \ReflectionClass(App::class);
    }

    public function testCanonicalSubscribeNamesExist(): void
    {
        $this->assertTrue($this->r->hasMethod('subscribe'),         'App::subscribe (canonical) missing');
        $this->assertTrue($this->r->hasMethod('unsubscribe'),       'App::unsubscribe (canonical) missing');
        $this->assertTrue($this->r->hasMethod('subscribeReliable'), 'App::subscribeReliable (canonical) missing');
    }

    public function testCanonicalPublishNamesExist(): void
    {
        $this->assertTrue($this->r->hasMethod('publish'),         'App::publish (canonical) missing — symmetric with subscribe');
        $this->assertTrue($this->r->hasMethod('publishReliable'), 'App::publishReliable (canonical) missing');
    }

    public function testBcAliasesStillExist(): void
    {
        // Existing apps + tests deliberately use these names; they MUST keep working.
        $this->assertTrue($this->r->hasMethod('onPubSub'),         'BC alias App::onPubSub missing');
        $this->assertTrue($this->r->hasMethod('offPubSub'),        'BC alias App::offPubSub missing');
        $this->assertTrue($this->r->hasMethod('onReliableMessage'),'BC alias App::onReliableMessage missing');
    }

    public function testCanonicalAddProcessExists(): void
    {
        $this->assertTrue($this->r->hasMethod('addProcess'), 'App::addProcess (canonical) missing — mirrors $server->addProcess');
    }

    public function testOnProcessBcAliasExists(): void
    {
        $this->assertTrue($this->r->hasMethod('onProcess'), 'BC alias App::onProcess missing');
    }

    public function testStatsReturnsAggregateSnapshot(): void
    {
        $s = App::stats();
        // X-4 — aggregated subsystem snapshot. Every top-level key should be present.
        foreach (['workers', 'store', 'cache', 'ws_router', 'memory', 'uptime_sec', 'php', 'backends'] as $key) {
            $this->assertArrayHasKey($key, $s, "App::stats missing key '$key'");
        }
        $this->assertIsArray($s['backends']);
        $this->assertArrayHasKey('store_kind',   $s['backends']);
        $this->assertArrayHasKey('counter_kind', $s['backends']);
    }

    public function testStatsSubsystemErrorsDontCrashSnapshot(): void
    {
        // safeStats wraps each subsystem so a single failure doesn't take down
        // the whole snapshot. With WSRouter uninitialised, stats() should
        // still return an array (possibly with _error keys), not throw.
        $s = App::stats();
        $this->assertIsArray($s['ws_router'], 'ws_router slot is an array even when uninitialised');
    }
}
