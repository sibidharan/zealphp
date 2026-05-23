<?php

declare(strict_types=1);

namespace ZealPHP;

use OpenSwoole\Atomic;
use ZealPHP\Counter\AtomicBackend;
use ZealPHP\Counter\CounterBackend;
use ZealPHP\Counter\CounterBackendKind;
use ZealPHP\Counter\RedisCounterBackend;
use ZealPHP\Store\DriverPreference;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\StoreException;

/**
 * `Counter` — backend-agnostic atomic integer.
 *
 * Default backend is `OpenSwoole\Atomic` for lock-free cross-worker
 * sharing. Switch to Redis for cross-NODE atomicity:
 *
 * ```php
 * Counter::defaultBackend('redis');
 * ```
 *
 * The instance API stays exactly as it was; existing `new Counter(0)`
 * call sites in the codebase keep working unchanged.
 *
 * Usage:
 *
 * ```php
 * $hits = new Counter(0);
 * $hits->increment();          // +1, returns new value
 * $hits->increment(5);         // +5
 * $hits->compareAndSet(6, 0);  // atomic CAS (Lua on Redis backend)
 * ```
 */
class Counter
{
    // Backend kind constants — prefer over bare strings:
    //   Counter::defaultBackend(Counter::BACKEND_REDIS) ← IDE-autocompleted
    //   Counter::defaultBackend('redis')                ← also works (BC).
    public const BACKEND_ATOMIC = 'atomic';
    public const BACKEND_REDIS  = 'redis';

    private static ?CounterBackend $backend = null;
    /** @var array{kind:string, conn?: string|array<string,mixed>} */
    private static array $backendConfig = ['kind' => 'atomic'];

    /**
     * Monotonically increasing serial for anonymous counter names.
     * spl_object_id() would reuse IDs after GC, leaking state between
     * objects that happen to land on the same slot.
     */
    private static int $anonSerial = 0;

    private string $name;

    /**
     * @param int     $initial  starting value (defaults to 0)
     * @param ?string $name     optional shared name; auto-generated unique when null
     */
    public function __construct(int $initial = 0, ?string $name = null)
    {
        $this->name = $name ?? '__anon_' . (++self::$anonSerial);
        // Touch the backend only when we actually have state to write —
        // (a) any explicit name (force-reset to $initial), or
        // (b) anonymous with non-zero $initial.
        // Anonymous + 0 is a brand-new slot that the backend reads as 0
        // anyway, so we can skip the backend round-trip. This matters at
        // route-load time on the Redis backend: route/*.php often does
        // `new Counter(0)` in the master process (no coroutine context),
        // which would otherwise eagerly open a predis connection through
        // a hooked stream_socket_client and crash boot.
        if ($name !== null || $initial !== 0) {
            self::defaultBackend()->set($this->name, $initial);
        }
    }

    /** Atomically add `$by` and return the new value. */
    public function increment(int $by = 1): int
    {
        return self::defaultBackend()->incr($this->name, $by);
    }

    /** Atomically subtract `$by` and return the new value. */
    public function decrement(int $by = 1): int
    {
        return self::defaultBackend()->decr($this->name, $by);
    }

    /** Read the current value. */
    public function get(): int
    {
        return self::defaultBackend()->get($this->name);
    }

    /** Set the value (not atomic relative to concurrent add/sub). */
    public function set(int $value): void
    {
        self::defaultBackend()->set($this->name, $value);
    }

    /** Reset to zero. */
    public function reset(): void
    {
        self::defaultBackend()->reset($this->name);
    }

    /**
     * Compare-and-swap: if current value equals `$expected`, set to `$new`.
     * On the Redis backend this is one round-trip via a Lua script.
     */
    public function compareAndSet(int $expected, int $new): bool
    {
        return self::defaultBackend()->compareAndSet($this->name, $expected, $new);
    }

    /**
     * Return the raw `OpenSwoole\Atomic`. Only available on the atomic
     * backend; throws on the redis backend (no Atomic equivalent there).
     */
    public function raw(): Atomic
    {
        $b = self::defaultBackend();
        if (!($b instanceof AtomicBackend)) {
            throw new StoreException(
                "Counter::raw() returns OpenSwoole\\Atomic — only available on the 'atomic' backend (current: " . self::$backendConfig['kind'] . ")"
            );
        }
        return $b->atomicFor($this->name);
    }

    /**
     * Get or set the process-wide default counter backend.
     *
     * @param  ?string                          $kind  'atomic' (default) or 'redis'; null to read current
     * @param  string|array<string,mixed>       $conn  redis URL or ['url'=>, 'pool_size'=>, 'prefix'=>]
     */
    public static function defaultBackend(CounterBackendKind|string|null $kind = null, string|array $conn = []): CounterBackend
    {
        if ($kind !== null) {
            $kindStr = CounterBackendKind::coerce($kind)->value;
            self::$backendConfig = ['kind' => $kindStr, 'conn' => $conn];
            self::$backend = null;
        }
        return self::$backend ??= self::buildBackend(self::$backendConfig);
    }

    /** @param array{kind:string, conn?: string|array<string,mixed>} $cfg */
    private static function buildBackend(array $cfg): CounterBackend
    {
        if ($cfg['kind'] !== 'redis') {
            return new AtomicBackend();
        }
        $conn = $cfg['conn'] ?? [];
        $opts = self::poolOptsFromEnv();
        if (is_string($conn)) {
            $url = $conn !== '' ? $conn : self::redisUrlFromEnv();
            return new RedisCounterBackend(new RedisConnectionPool($url, 8, $opts));
        }
        $url    = isset($conn['url']) && is_string($conn['url']) ? $conn['url'] : self::redisUrlFromEnv();
        $size   = isset($conn['pool_size']) && is_int($conn['pool_size']) ? $conn['pool_size'] : 8;
        $prefix = isset($conn['prefix']) && is_string($conn['prefix']) ? $conn['prefix'] : 'zealstore';
        if (isset($conn['prefer'])) {
            try {
                $opts['prefer'] = DriverPreference::coerce(
                    $conn['prefer'] instanceof DriverPreference || is_string($conn['prefer'])
                        ? $conn['prefer']
                        : '',
                )->value;
            } catch (\InvalidArgumentException) {
                /* fall back to env-default */
            }
        }
        return new RedisCounterBackend(new RedisConnectionPool($url, $size, $opts), $prefix);
    }

    private static function redisUrlFromEnv(): string
    {
        $env = getenv('ZEALPHP_REDIS_URL');
        return is_string($env) && $env !== '' ? $env : 'redis://127.0.0.1:6379';
    }

    /** @return array{prefer?: 'auto'|'phpredis'|'predis'} */
    private static function poolOptsFromEnv(): array
    {
        $prefer = getenv('ZEALPHP_REDIS_PREFER');
        if (!is_string($prefer) || $prefer === '') { return []; }
        $prefer = strtolower($prefer);
        if (!in_array($prefer, ['auto', 'phpredis', 'predis'], true)) { return []; }
        return ['prefer' => $prefer];
    }
}
