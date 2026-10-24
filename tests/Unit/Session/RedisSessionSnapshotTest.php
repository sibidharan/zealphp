<?php

declare(strict_types=1);

namespace {
    if (!extension_loaded('redis') && !class_exists('Redis', false)) {
        /**
         * Minimal in-memory stand-in for ext-redis — only what
         * RedisSessionHandler calls, including WATCH/MULTI/EXEC conflict
         * detection. Declared only when ext-redis is absent.
         */
        class Redis
        {
            /** @var array<string, string> */
            public static array $data = [];
            /** @var array<string, int> */
            public static array $version = [];
            /** @var array<string, int> */
            private array $watched = [];
            /** @var list<array{0: string, 1: string}> */
            private array $queue = [];
            private bool $inMulti = false;

            public function connect(string $host, int $port): bool { return true; }
            public function isConnected(): bool { return true; }
            public function close(): bool { return true; }
            public function watch(string $key): bool
            {
                $this->watched[$key] = self::$version[$key] ?? 0;
                return true;
            }
            public function unwatch(): bool { $this->watched = []; return true; }
            public function get(string $key): string|false { return self::$data[$key] ?? false; }
            /** @var array<string, int> TTL set by the last expire() per key */
            public static array $expired = [];
            /** @var array<string, int> remaining TTL ttl() reports per key */
            public static array $ttlLeft = [];
            public function ttl(string $key): int|false
            {
                if (!isset(self::$data[$key])) {
                    return -2;
                }
                return self::$ttlLeft[$key] ?? -1;
            }
            public function expire(string $key, int $ttl): bool
            {
                self::$expired[$key] = $ttl;
                return isset(self::$data[$key]);
            }
            public function multi(): self { $this->inMulti = true; $this->queue = []; return $this; }
            public function setex(string $key, int $ttl, string $value): self|bool
            {
                if ($this->inMulti) {
                    $this->queue[] = [$key, $value];
                    return $this;
                }
                self::$data[$key] = $value;
                self::$version[$key] = (self::$version[$key] ?? 0) + 1;
                return true;
            }
            /** @return list<bool>|false */
            public function exec(): array|false
            {
                $this->inMulti = false;
                foreach ($this->watched as $key => $v) {
                    if ((self::$version[$key] ?? 0) !== $v) {
                        $this->watched = [];
                        $this->queue = [];
                        return false;
                    }
                }
                $this->watched = [];
                $out = [];
                foreach ($this->queue as [$key, $value]) {
                    self::$data[$key] = $value;
                    self::$version[$key] = (self::$version[$key] ?? 0) + 1;
                    $out[] = true;
                }
                $this->queue = [];
                return $out;
            }
            public function del(string $key): int
            {
                $had = isset(self::$data[$key]);
                unset(self::$data[$key]);
                self::$version[$key] = (self::$version[$key] ?? 0) + 1;
                return $had ? 1 : 0;
            }
        }
    }
}

namespace ZealPHP\Tests\Unit\Session {

    use OpenSwoole\Coroutine;
    use PHPUnit\Framework\TestCase;
    use ZealPHP\Session\Handler\RedisSessionHandler;

    /**
     * RedisSessionHandler keeps a read snapshot per session for its 3-way
     * merge. It used to be one per-worker map that only write() pruned, so
     * every id read and never written (rotated, forged, read-only) stayed in
     * it for the worker's lifetime; and a write whose key was deleted after
     * the read (logout) recreated the session.
     */
    final class RedisSessionSnapshotTest extends TestCase
    {
        protected function setUp(): void
        {
            if (extension_loaded('redis')) {
                $this->markTestSkipped('needs the in-memory Redis stand-in (ext-redis is loaded)');
            }
            \Redis::$data = [];
            \Redis::$version = [];
            // The decoder's @-suppressed unserialize() notices still reach
            // PHPUnit's handler, which cannot run inside a coroutine.
            set_error_handler(static fn(): bool => true);
        }

        protected function tearDown(): void
        {
            if (!extension_loaded('redis')) {
                restore_error_handler();
            }
        }

        private function handler(): RedisSessionHandler
        {
            return new RedisSessionHandler('127.0.0.1', 6379, 'T:');
        }

        /** @return array<string, string> */
        private function baseData(RedisSessionHandler $h): array
        {
            $v = (new \ReflectionProperty(RedisSessionHandler::class, 'baseData'))->getValue($h);
            $this->assertIsArray($v);
            /** @var array<string, string> $v */
            return $v;
        }

        /**
         * A session that is only read keeps sliding, once less than half its TTL
         * is left; a fresh one is not touched (an EXPIRE would abort concurrent
         * readers' WATCH, so refreshing on every read made parallel requests on
         * one session fail their writes).
         */
        public function testReadRefreshesTheTtlOnlyPastHalfLife(): void
        {
            \Redis::$data['T:abc'] = 'user|s:5:"alice";';
            \Redis::$expired = [];
            $h = new RedisSessionHandler('127.0.0.1', 6379, 'T:', 900);

            \Redis::$ttlLeft['T:abc'] = 800;
            $this->assertSame('user|s:5:"alice";', $h->read('abc'));
            $this->assertSame([], \Redis::$expired, 'a fresh TTL is left alone');

            \Redis::$ttlLeft['T:abc'] = 300;
            $h->read('abc');
            $this->assertSame(['T:abc' => 900], \Redis::$expired);
        }

        public function testIdsReadButNeverWrittenDoNotAccumulate(): void
        {
            $h = $this->handler();
            Coroutine::run(static function () use ($h): void {
                for ($i = 0; $i < 300; $i++) {
                    Coroutine::create(static function () use ($h, $i): void {
                        $h->read('forged' . $i);
                    });
                }
            });
            $this->assertSame([], $this->baseData($h), 'snapshots of finished requests were kept');

            for ($i = 0; $i < 300; $i++) {
                $h->read('cli' . $i);
            }
            $this->assertLessThanOrEqual(64, count($this->baseData($h)), 'the non-coroutine map is unbounded');
            $h->close();
            $this->assertSame([], $this->baseData($h));
        }

        public function testWriteDoesNotRecreateAKeyDeletedSinceTheRead(): void
        {
            $h = $this->handler();
            \Redis::$data['T:s1'] = 'user|s:5:"alice";';
            $written = null;
            Coroutine::run(static function () use ($h, &$written): void {
                Coroutine::create(static function () use ($h, &$written): void {
                    $h->read('s1');
                    // Another request logs out between our read and our write.
                    unset(\Redis::$data['T:s1']);
                    \Redis::$version['T:s1'] = 99;
                    $written = $h->write('s1', 'user|s:5:"alice";seen|i:1;');
                });
            });
            $this->assertFalse($written);
            $this->assertArrayNotHasKey('T:s1', \Redis::$data, 'the destroyed session was recreated');
        }

        public function testConcurrentWriteStillMerges(): void
        {
            $h = $this->handler();
            \Redis::$data['T:s2'] = 'a|i:1;';
            $written = null;
            Coroutine::run(static function () use ($h, &$written): void {
                Coroutine::create(static function () use ($h, &$written): void {
                    $h->read('s2');
                    \Redis::$data['T:s2'] = 'a|i:1;b|i:2;';
                    \Redis::$version['T:s2'] = 99;
                    $written = $h->write('s2', 'a|i:1;c|i:3;');
                });
            });
            $this->assertTrue($written);
            $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], RedisSessionHandler::parseSession(\Redis::$data['T:s2']));
        }
    }
}
