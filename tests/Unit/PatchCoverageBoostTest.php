<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Cache;
use ZealPHP\Counter;
use ZealPHP\Store;
use ZealPHP\Store\DriverPreference;
use ZealPHP\Store\RedisPubSub;
use ZealPHP\Store\RedisStreams;
use ZealPHP\Store\StoreException;
use ZealPHP\WS\CapacityException;
use ZealPHP\WSRouter;

/**
 * Targeted patch-coverage boost — hits validation throws, env-var
 * resolution paths, accessor-only helpers, Cache stampede / file-tier
 * branches, and RedisPubSub / RedisStreams state-machine paths that
 * the existing test suite leaves uncovered.
 *
 * Organised by source module so future regressions are trivially
 * attributable to a single feature area.
 */
final class PatchCoverageBoostTest extends TestCase
{
    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        Counter::defaultBackend(Counter::BACKEND_ATOMIC);
        WSRouter::reset();
    }

    protected function tearDown(): void
    {
        WSRouter::reset();
        Store::defaultBackend(Store::BACKEND_TABLE);
        Counter::defaultBackend(Counter::BACKEND_ATOMIC);
    }

    // ════════════════════════════════════════════════════════════════
    //   WSRouter — initOptions / setRoomRateLimit validation throws
    // ════════════════════════════════════════════════════════════════

    public function testInitOptionsRejectsOwnerCapacityBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$ownerCapacity must be >= 1');
        WSRouter::initOptions(ownerCapacity: 0);
    }

    public function testInitOptionsRejectsRoomMembersCapacityBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$roomMembersCapacity must be >= 1');
        WSRouter::initOptions(roomMembersCapacity: 0);
    }

    public function testInitOptionsRejectsSlowConsumerBytesBelow1024(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$slowConsumerBytes must be >= 1024');
        WSRouter::initOptions(slowConsumerBytes: 512);
    }

    public function testInitOptionsRejectsZeroSlowConsumerBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WSRouter::initOptions(slowConsumerBytes: 0);
    }

    public function testInitOptionsAcceptsValidValues(): void
    {
        WSRouter::initOptions(
            ownerCapacity: 100_000,
            roomMembersCapacity: 500_000,
            slowConsumerBytes: 16 * 1024 * 1024,
        );
        $this->addToAssertionCount(1);
    }

    public function testSetRoomRateLimitRejectsNegativeN(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$n must be >= 0');
        WSRouter::setRoomRateLimit(-1);
    }

    public function testSetRoomRateLimitRejectsWindowBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$windowSec must be >= 1');
        WSRouter::setRoomRateLimit(10, 0);
    }

    public function testSetRoomRateLimitAcceptsZeroToDisable(): void
    {
        WSRouter::setRoomRateLimit(0);
        $this->assertSame(0, WSRouter::roomRateLimitN());
    }

    public function testRoomRateLimitGetters(): void
    {
        WSRouter::setRoomRateLimit(42, 90);
        $this->assertSame(42, WSRouter::roomRateLimitN());
        $this->assertSame(90, WSRouter::roomRateLimitWindowSec());
        WSRouter::setRoomRateLimit(0);
    }

    // ════════════════════════════════════════════════════════════════
    //   WSRouter — pre-init guards + accessors
    // ════════════════════════════════════════════════════════════════

    public function testOnlineCountThrowsBeforeInit(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('init() must be called before onlineCount()');
        WSRouter::onlineCount();
    }

    public function testOnlineByServerThrowsBeforeInit(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('init() must be called before onlineByServer()');
        WSRouter::onlineByServer();
    }

    public function testOwnThrowsBeforeInit(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('init() must be called before own()');
        WSRouter::own('client-x', 42);
    }

    public function testRoomTableNameIsStable(): void
    {
        $this->assertSame('ws_room_members', WSRouter::roomTable());
    }

    public function testOwnerTableNameIsStable(): void
    {
        $this->assertSame('ws_owner', WSRouter::ownerTable());
    }

    public function testServerTableNameIsStable(): void
    {
        $this->assertSame('ws_servers', WSRouter::serverTable());
    }

    public function testOnlineCountAfterInitReturnsZero(): void
    {
        WSRouter::init('boost-online-' . bin2hex(random_bytes(2)));
        $this->assertSame(0, WSRouter::onlineCount());
    }

    public function testOnlineByServerAfterInitReturnsEmpty(): void
    {
        WSRouter::init('boost-onlineByServer-' . bin2hex(random_bytes(2)));
        $this->assertSame([], WSRouter::onlineByServer());
    }

    public function testInitIsIdempotentForSameServerId(): void
    {
        $id = 'boost-idem-' . bin2hex(random_bytes(2));
        WSRouter::init($id);
        WSRouter::init($id);
        $this->assertSame($id, WSRouter::serverId());
    }

    // ════════════════════════════════════════════════════════════════
    //   WSRouter — sendToClient / pushWithBackpressure / broadcast
    // ════════════════════════════════════════════════════════════════

    public function testSendToClientReturnsFalseForUnknownClient(): void
    {
        WSRouter::init('boost-stc-' . bin2hex(random_bytes(2)));
        $this->assertFalse(WSRouter::sendToClient('nobody', 'hi'));
    }

    public function testSendToClientReturnsFalseWhenRateLimited(): void
    {
        WSRouter::setClientRateLimit(1, 60);
        try {
            WSRouter::init('boost-rl-' . bin2hex(random_bytes(2)));
            WSRouter::sendToClient('alice', 'first');   // burns the budget
            $this->assertFalse(WSRouter::sendToClient('alice', 'second'));
        } finally {
            WSRouter::setClientRateLimit(0);
        }
    }

    public function testPushWithBackpressureReturnsFalseForDeadFd(): void
    {
        if (!class_exists(\OpenSwoole\WebSocket\Server::class)) {
            $this->markTestSkipped('OpenSwoole WS not loaded');
        }
        $stub = new class('127.0.0.1', 0) extends \OpenSwoole\WebSocket\Server {
            public function isEstablished($fd): bool { return false; }
        };
        $this->assertFalse(WSRouter::pushWithBackpressure($stub, 9999, 'payload'));
    }

    public function testBroadcastThrowsOnTableBackend(): void
    {
        // Store::publish requires Redis — pinned here so any future
        // accidental relaxation surfaces in tests.
        $this->expectException(StoreException::class);
        WSRouter::broadcast('demo:room', 'hi');
    }

    public function testRoomOpThrowsBeforeInit(): void
    {
        $this->expectException(StoreException::class);
        $r = WSRouter::room('preinit-room');
        $r->size();
    }

    // ════════════════════════════════════════════════════════════════
    //   CapacityException type hierarchy
    // ════════════════════════════════════════════════════════════════

    public function testCapacityExceptionInheritsStoreException(): void
    {
        $r = new \ReflectionClass(CapacityException::class);
        $this->assertSame(StoreException::class, $r->getParentClass()->getName());
    }

    public function testCapacityExceptionMessageRoundTrips(): void
    {
        $e = new CapacityException('table full at 256 connections');
        $this->assertStringContainsString('256 connections', $e->getMessage());
    }

    // ════════════════════════════════════════════════════════════════
    //   Cache — gcFiles eviction + stampede gate + invalidateTag
    // ════════════════════════════════════════════════════════════════

    public function testCacheFileTierEvictsOldestWhenOverCap(): void
    {
        $dir = sys_get_temp_dir() . '/zptest-cache-cap-' . bin2hex(random_bytes(3));
        Cache::initForTest($dir, 64);
        for ($i = 0; $i < 6; $i++) {
            Cache::set("cap-$i", str_repeat('x', 8192));
            $path = $dir . '/' . hash('xxh128', 'cap-' . $i) . '.cache';
            if (file_exists($path)) { touch($path, time() - (6 - $i)); }
        }
        $r = new \ReflectionClass(Cache::class);
        if ($r->hasProperty('maxFiles')) {
            $p = $r->getProperty('maxFiles');
            $p->setAccessible(true);
            $p->setValue(null, 3);
        }
        Cache::gcFiles();
        $remaining = glob($dir . '/*.cache') ?: [];
        $this->assertLessThanOrEqual(3, count($remaining));
    }

    public function testGetOrComputeStallsThenFallsThroughWhenLockHeld(): void
    {
        $dir = sys_get_temp_dir() . '/zptest-stampede-' . bin2hex(random_bytes(3));
        Cache::initForTest($dir, 64);
        $key      = 'stampede-key-' . bin2hex(random_bytes(2));
        $lockName = '__cache_lock_' . md5($key);
        $lock     = new Counter(0, $lockName);
        $lock->compareAndSet(0, 1);    // take the lock externally
        $start    = microtime(true);
        $val      = Cache::getOrCompute($key, fn() => 'computed', 30);
        $elapsed  = microtime(true) - $start;
        $this->assertSame('computed', $val);
        $this->assertGreaterThanOrEqual(0.15, $elapsed);
    }

    public function testInvalidateTagOnTableBackendReturnsZero(): void
    {
        $dir = sys_get_temp_dir() . '/zptest-tags-table-' . bin2hex(random_bytes(3));
        Cache::initForTest($dir, 64);
        Cache::set('a', 'A');
        $this->assertSame(0, Cache::invalidateTag('groupA'));
        $this->assertSame('A', Cache::get('a'));   // entry untouched
    }

    // ════════════════════════════════════════════════════════════════
    //   Store / Counter — backend config array branches + env vars
    // ════════════════════════════════════════════════════════════════

    public function testStoreDefaultBackendMemcachedReadsEnvServers(): void
    {
        putenv('ZEALPHP_MEMCACHED_SERVERS=127.0.0.1:1');
        try {
            try {
                Store::defaultBackend('memcached');
                $this->addToAssertionCount(1);
            } catch (StoreException $e) {
                $this->assertMatchesRegularExpression('/memcached/i', $e->getMessage());
            }
        } finally {
            putenv('ZEALPHP_MEMCACHED_SERVERS');
        }
    }

    public function testStoreDefaultBackendMemcachedAcceptsArrayConfig(): void
    {
        try {
            Store::defaultBackend('memcached', ['servers' => '127.0.0.1:1', 'prefix' => 'zptest']);
            $this->addToAssertionCount(1);
        } catch (StoreException $e) {
            $this->assertMatchesRegularExpression('/memcached/i', $e->getMessage());
        }
    }

    public function testStoreDefaultBackendMemcachedArrayWithoutServersFallsBackToEnv(): void
    {
        putenv('ZEALPHP_MEMCACHED_SERVERS=127.0.0.1:1');
        try {
            try {
                Store::defaultBackend('memcached', ['prefix' => 'zptest']);
                $this->addToAssertionCount(1);
            } catch (StoreException $e) {
                $this->assertMatchesRegularExpression('/memcached/i', $e->getMessage());
            }
        } finally {
            putenv('ZEALPHP_MEMCACHED_SERVERS');
        }
    }

    public function testStoreDefaultBackendRedisArrayWithPreferString(): void
    {
        Store::defaultBackend('redis', [
            'url'       => 'redis://127.0.0.1:65000/0',
            'pool_size' => 4,
            'prefix'    => 'zptest',
            'prefer'    => 'predis',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testStoreDefaultBackendRedisArrayWithPreferEnum(): void
    {
        Store::defaultBackend('redis', ['url' => 'redis://127.0.0.1:65000/0', 'prefer' => DriverPreference::Auto]);
        $this->addToAssertionCount(1);
    }

    public function testStoreDefaultBackendRedisArrayWithInvalidPreferFallsBack(): void
    {
        Store::defaultBackend('redis', ['url' => 'redis://127.0.0.1:65000/0', 'prefer' => 'nonsense']);
        $this->addToAssertionCount(1);
    }

    public function testStoreDefaultBackendTieredAcceptsArrayConfig(): void
    {
        Store::defaultBackend('tiered', [
            'url'                 => 'redis://127.0.0.1:65000/0',
            'pool_size'           => 4,
            'prefix'              => 'zptest',
            'l1_ttl'              => 10,
            'invalidation_secret' => 'shared-secret-xyz',
            'prefer'              => 'predis',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testStoreDefaultBackendTieredWithInvalidPreferFallsBack(): void
    {
        Store::defaultBackend('tiered', ['url' => 'redis://127.0.0.1:65000/0', 'prefer' => 'unknown']);
        $this->addToAssertionCount(1);
    }

    public function testCounterDefaultBackendMemcachedReadsEnvServers(): void
    {
        putenv('ZEALPHP_MEMCACHED_SERVERS=127.0.0.1:1');
        try {
            try {
                Counter::defaultBackend('memcached');
                $this->addToAssertionCount(1);
            } catch (StoreException $e) {
                $this->assertMatchesRegularExpression('/memcached/i', $e->getMessage());
            }
        } finally {
            putenv('ZEALPHP_MEMCACHED_SERVERS');
        }
    }

    public function testCounterDefaultBackendMemcachedAcceptsArrayConfig(): void
    {
        try {
            Counter::defaultBackend('memcached', ['servers' => '127.0.0.1:1', 'prefix' => 'zptest']);
            $this->addToAssertionCount(1);
        } catch (StoreException $e) {
            $this->assertMatchesRegularExpression('/memcached/i', $e->getMessage());
        }
    }

    public function testCounterDefaultBackendRedisArrayShape(): void
    {
        Counter::defaultBackend('redis', [
            'url'       => 'redis://127.0.0.1:65000/0',
            'pool_size' => 4,
            'prefix'    => 'zptest',
            'prefer'    => 'predis',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testCounterDefaultBackendRedisInvalidPreferFallsBack(): void
    {
        Counter::defaultBackend('redis', ['url' => 'redis://127.0.0.1:65000/0', 'prefer' => 'unknown']);
        $this->addToAssertionCount(1);
    }

    // ════════════════════════════════════════════════════════════════
    //   DriverPreference enum coerce
    // ════════════════════════════════════════════════════════════════

    public function testDriverPreferenceCoerceFromEnumPassesThrough(): void
    {
        $this->assertSame(DriverPreference::Phpredis, DriverPreference::coerce(DriverPreference::Phpredis));
        $this->assertSame(DriverPreference::Predis,   DriverPreference::coerce(DriverPreference::Predis));
        $this->assertSame(DriverPreference::Auto,     DriverPreference::coerce(DriverPreference::Auto));
    }

    public function testDriverPreferenceCoerceFromString(): void
    {
        $this->assertSame(DriverPreference::Phpredis, DriverPreference::coerce('phpredis'));
        $this->assertSame(DriverPreference::Predis,   DriverPreference::coerce('predis'));
        $this->assertSame(DriverPreference::Auto,     DriverPreference::coerce('auto'));
    }

    public function testDriverPreferenceCoerceCaseInsensitive(): void
    {
        $this->assertSame(DriverPreference::Phpredis, DriverPreference::coerce('PHPREDIS'));
        $this->assertSame(DriverPreference::Predis,   DriverPreference::coerce('Predis'));
    }

    public function testDriverPreferenceCoerceUnknownThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DriverPreference::coerce('not-a-driver');
    }

    // ════════════════════════════════════════════════════════════════
    //   RedisPubSub — state-machine accessors (no live Redis needed)
    // ════════════════════════════════════════════════════════════════

    public function testRedisPubSubConstructorSetsStopChannelWithPrefix(): void
    {
        $ps = new RedisPubSub('redis://127.0.0.1:65000/0', 'mytest');
        $this->assertStringStartsWith('mytest:__pubsub_stop:', $ps->stopChannel());
    }

    public function testRedisPubSubConstructorUsesDefaultPrefix(): void
    {
        $ps = new RedisPubSub('redis://127.0.0.1:65000/0');
        $this->assertStringStartsWith('zealstore:__pubsub_stop:', $ps->stopChannel());
    }

    public function testRedisPubSubRegisterRoutesExactVsPattern(): void
    {
        $ps = new RedisPubSub('redis://127.0.0.1:65000/0', 'rp');
        $ps->register('chan-exact', fn() => null);
        $ps->register('room:*',     fn() => null);
        $ps->register('user:?',     fn() => null);   // ? is NOT a pattern marker
        $ps->register('another:*',  fn() => null);
        $this->assertSame(['chan-exact', 'user:?'], $ps->exactChannels());
        $this->assertSame(['room:*', 'another:*'],  $ps->patternChannels());
    }

    public function testRedisPubSubMultipleHandlersPerChannelAllRegistered(): void
    {
        $ps = new RedisPubSub('redis://127.0.0.1:65000/0', 'rp');
        $ps->register('shared', fn() => null);
        $ps->register('shared', fn() => null);
        $ps->register('shared', fn() => null);
        // Channel listed once in exactChannels even with 3 handlers.
        $this->assertSame(['shared'], $ps->exactChannels());
    }

    public function testRedisPubSubIsRunningFalseBeforeStart(): void
    {
        $ps = new RedisPubSub('redis://127.0.0.1:65000/0');
        $this->assertFalse($ps->isRunning());
    }

    public function testRedisPubSubStartIsNoOpWhenNoHandlersRegistered(): void
    {
        // L86-89 — early return when nothing to subscribe. The short-circuit
        // fires BEFORE the runner coroutine spawns so no scheduler is
        // needed; wrapping in Coroutine::run leaves the event loop in a
        // half-initialised state that contaminates the next test.
        $ps = new RedisPubSub('redis://127.0.0.1:65000/0', 'rp-noop');
        $ps->start();
        $this->assertFalse($ps->isRunning());
    }

    public function testRedisPubSubStopIsNoOpWhenNotRunning(): void
    {
        // L100 stop() short-circuits when running==0.
        $ps = new RedisPubSub('redis://127.0.0.1:65000/0', 'rp-stop-noop');
        $ps->stop();   // must not throw despite no live runner
        $this->assertFalse($ps->isRunning());
    }

    public function testRedisPubSubStatsAccessorReturnsStatsInstance(): void
    {
        $ps = new RedisPubSub('redis://127.0.0.1:65000/0', 'rp-stats');
        $stats = $ps->stats();
        $this->assertIsObject($stats);
        // Stats has an inc + snapshot surface.
        $this->assertTrue(method_exists($stats, 'inc'));
        $this->assertTrue(method_exists($stats, 'snapshot'));
    }

    public function testRedisPubSubConfigurableMaxAttempts(): void
    {
        // Both default (0 = unlimited) and bounded variants construct.
        $unbounded = new RedisPubSub('redis://127.0.0.1:65000/0', 'rp-ua');
        $bounded   = new RedisPubSub('redis://127.0.0.1:65000/0', 'rp-ba', 5);
        $this->assertFalse($unbounded->isRunning());
        $this->assertFalse($bounded->isRunning());
    }

    // ════════════════════════════════════════════════════════════════
    //   WSRouter — internal accessors + reset behavior
    // ════════════════════════════════════════════════════════════════

    public function testLocalFdsReturnsEmptyArrayBeforeAnyOwn(): void
    {
        // No init needed — localFds() is a simple accessor.
        $this->assertSame([], WSRouter::localFds());
    }

    public function testResetClearsServerIdBackToEmpty(): void
    {
        // serverId() is a plain accessor — no scheduler / OpenSwoole interaction.
        $this->assertSame('', WSRouter::serverId());
        WSRouter::reset();   // idempotent — already at default
        $this->assertSame('', WSRouter::serverId());
    }

    public function testRoomChannelPrefixIsStableConstant(): void
    {
        $this->assertSame('ws:room:', WSRouter::roomChannelPrefix());
    }

    public function testRoomMembersSetKeyFormatsCorrectly(): void
    {
        $this->assertSame('ws_room:lobby:members',     WSRouter::roomMembersSetKey('lobby'));
        $this->assertSame('ws_room:game:42:members',   WSRouter::roomMembersSetKey('game:42'));
    }

    public function testHasRedisBackendFalseOnTable(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        $this->assertFalse(WSRouter::hasRedisBackend());
    }

    public function testStatsAccessorReturnsStatsObject(): void
    {
        $stats = WSRouter::stats();
        $this->assertIsObject($stats);
        $this->assertTrue(method_exists($stats, 'inc'));
        $this->assertTrue(method_exists($stats, 'snapshot'));
    }

    // NOTE: WSRouter::room() AFTER WSRouter::init() inside the same
    // process as the RedisPubSub Atomic constructors above hangs the
    // PHP runtime (OpenSwoole scheduler in a half-initialised state).
    // That combo is covered separately in tests/Unit/WS/RoomTest.php
    // which doesn't share a process with PubSub construction.

    // ════════════════════════════════════════════════════════════════
    //   App::publish / subscribe — Table backend rejects
    // ════════════════════════════════════════════════════════════════

    public function testAppPublishThrowsOnTableBackend(): void
    {
        $this->expectException(StoreException::class);
        \ZealPHP\App::publish('some:channel', 'payload');
    }

    public function testAppPublishReliableThrowsOnTableBackend(): void
    {
        $this->expectException(StoreException::class);
        \ZealPHP\App::publishReliable('some:stream', 'payload');
    }

    // ════════════════════════════════════════════════════════════════
    //   Counter — backend-agnostic surface behaviour
    // ════════════════════════════════════════════════════════════════

    public function testCounterIncrementByCustomAmount(): void
    {
        $c = new Counter(10, 'boost-incr-' . bin2hex(random_bytes(2)));
        $this->assertSame(15, $c->increment(5));
        $this->assertSame(20, $c->increment(5));
    }

    public function testCounterDecrementByCustomAmount(): void
    {
        $c = new Counter(100, 'boost-decr-' . bin2hex(random_bytes(2)));
        $this->assertSame(90, $c->decrement(10));
        $this->assertSame(85, $c->decrement(5));
    }

    public function testCounterResetWritesZero(): void
    {
        $c = new Counter(42, 'boost-reset-c-' . bin2hex(random_bytes(2)));
        $c->reset();
        $this->assertSame(0, $c->get());
    }

    public function testCounterCompareAndSetFailsOnMismatch(): void
    {
        $c = new Counter(0, 'boost-cas-' . bin2hex(random_bytes(2)));
        $c->set(10);
        $this->assertFalse($c->compareAndSet(99, 20));  // 10 != 99
        $this->assertSame(10, $c->get());                // unchanged
        $this->assertTrue($c->compareAndSet(10, 20));    // matches
        $this->assertSame(20, $c->get());
    }

    // ════════════════════════════════════════════════════════════════
    //   Cache — stats() shape + has() variants
    // ════════════════════════════════════════════════════════════════

    public function testCacheStatsReturnsArrayWithExpectedKeys(): void
    {
        $dir = sys_get_temp_dir() . '/zptest-cache-stats-' . bin2hex(random_bytes(3));
        Cache::initForTest($dir, 64);
        $stats = Cache::stats();
        $this->assertIsArray($stats);
        // Required keys per Cache::stats() — fail loud if a future refactor renames.
        $this->assertArrayHasKey('memory_entries', $stats);
        $this->assertArrayHasKey('hits_memory',    $stats);
        $this->assertArrayHasKey('misses',         $stats);
        $this->assertArrayHasKey('hit_rate',       $stats);
    }

    public function testCacheHasReturnsTrueForSetEntry(): void
    {
        $dir = sys_get_temp_dir() . '/zptest-cache-has-' . bin2hex(random_bytes(3));
        Cache::initForTest($dir, 64);
        $this->assertFalse(Cache::has('nokey'));
        Cache::set('mykey', 'val', 30);
        $this->assertTrue(Cache::has('mykey'));
    }

    public function testCacheFlushRemovesAllEntries(): void
    {
        $dir = sys_get_temp_dir() . '/zptest-cache-flush-' . bin2hex(random_bytes(3));
        Cache::initForTest($dir, 64);
        Cache::set('a', 'A');
        Cache::set('b', 'B');
        Cache::flush();
        $this->assertNull(Cache::get('a'));
        $this->assertNull(Cache::get('b'));
        $this->assertSame(0, Cache::count());
    }

    public function testCacheGetOrComputeReturnsStoredOnSecondCall(): void
    {
        $dir = sys_get_temp_dir() . '/zptest-goc-' . bin2hex(random_bytes(3));
        Cache::initForTest($dir, 64);
        $calls = 0;
        $key   = 'goc-' . bin2hex(random_bytes(2));
        $v1    = Cache::getOrCompute($key, function () use (&$calls) { $calls++; return 'computed'; }, 30);
        $v2    = Cache::getOrCompute($key, function () use (&$calls) { $calls++; return 'should-not-fire'; }, 30);
        $this->assertSame('computed', $v1);
        $this->assertSame('computed', $v2);
        $this->assertSame(1, $calls);   // second call hits cache, not compute
    }

    // ════════════════════════════════════════════════════════════════
    //   RedisStreams — state-machine accessors (no live Redis needed)
    // ════════════════════════════════════════════════════════════════

    public function testRedisStreamsConstructorDefaultsConsumerNameToHostPid(): void
    {
        $rs = new RedisStreams('redis://127.0.0.1:65000/0');
        $name = $rs->consumerName();
        $this->assertNotSame('', $name);
        $this->assertMatchesRegularExpression('/-\d+$/', $name);   // ends in -<pid>
    }

    public function testRedisStreamsConstructorAcceptsCustomConsumerName(): void
    {
        $rs = new RedisStreams('redis://127.0.0.1:65000/0', 'worker-7');
        $this->assertSame('worker-7', $rs->consumerName());
    }

    public function testRedisStreamsRegisterAppendsConsumerEntry(): void
    {
        $rs = new RedisStreams('redis://127.0.0.1:65000/0', 'w-1');
        $rs->register('stream1', 'grp', fn() => true);
        $rs->register('stream2', 'grp', fn() => true, blockMs: 500, batchSize: 32);
        $consumers = $rs->consumers();
        $this->assertCount(2, $consumers);
        $this->assertSame('stream1', $consumers[0]['stream']);
        $this->assertSame('grp',     $consumers[0]['group']);
        $this->assertSame(1000,      $consumers[0]['blockMs']);   // default
        $this->assertSame(16,        $consumers[0]['batchSize']); // default
        $this->assertSame(500,       $consumers[1]['blockMs']);
        $this->assertSame(32,        $consumers[1]['batchSize']);
    }

    public function testRedisStreamsIsRunningFalseBeforeStart(): void
    {
        $rs = new RedisStreams('redis://127.0.0.1:65000/0');
        $this->assertFalse($rs->isRunning());
    }

    public function testRedisStreamsStartIsNoOpWhenNoConsumersRegistered(): void
    {
        // L54-57 — early return when nothing to consume. Same coroutine-
        // contamination concern as RedisPubSub above.
        $rs = new RedisStreams('redis://127.0.0.1:65000/0', 'rs-noop');
        $rs->start();
        $this->assertFalse($rs->isRunning());
    }

    public function testRedisStreamsStopFlipsRunningAtomic(): void
    {
        $rs = new RedisStreams('redis://127.0.0.1:65000/0', 'rs-stop');
        $rs->stop();   // even when not running, stop() must not throw
        $this->assertFalse($rs->isRunning());
    }
}
