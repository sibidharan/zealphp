<?php

declare(strict_types=1);

namespace ZealPHP\Store;

/**
 * Thin adapter over phpredis (preferred when ext-redis is loaded) or
 * predis (pure-PHP fallback). The ONE place the client lib is referenced
 * by name in ZealPHP — every other class talks to this adapter.
 *
 * Construct with a redis URL; pass ['prefer' => 'phpredis'|'predis'|'auto']
 * to force the driver. 'auto' (default) picks phpredis when the ext is
 * loaded, predis when it isn't, throws when neither is available.
 */
final class RedisClient
{
    private RedisDriver $driver;

    /** @param array{prefer?: 'auto'|'phpredis'|'predis'} $opts */
    public function __construct(string $url, array $opts = [])
    {
        $this->driver = self::pickDriver($url, $opts['prefer'] ?? 'auto');
    }

    private static function pickDriver(string $url, string $prefer): RedisDriver
    {
        if ($prefer === 'phpredis') {
            return new PhpredisDriver($url);
        }
        if ($prefer === 'predis') {
            return new PredisDriver($url);
        }
        if ($prefer !== 'auto') {
            throw new StoreException("Unknown 'prefer' value: $prefer (use auto|phpredis|predis)");
        }
        if (extension_loaded('redis')) {
            return new PhpredisDriver($url);
        }
        if (class_exists(\Predis\Client::class)) {
            return new PredisDriver($url);
        }
        throw new StoreException(
            'No Redis client available — install ext-redis (pecl install redis) or `composer require predis/predis`'
        );
    }

    public function driverName(): string { return $this->driver->name(); }

    // ── string keys ─────────────────────────────────────────────────────
    public function set(string $key, string $value, ?int $ttlSeconds = null): bool { return $this->driver->set($key, $value, $ttlSeconds); }
    public function get(string $key): ?string { return $this->driver->get($key); }
    public function del(string ...$keys): int { return $this->driver->del(...$keys); }
    public function exists(string $key): bool { return $this->driver->exists($key); }
    public function expire(string $key, int $ttlSeconds): bool { return $this->driver->expire($key, $ttlSeconds); }

    // ── hashes ──────────────────────────────────────────────────────────
    /** @param array<string, string> $fields */
    public function hset(string $key, array $fields): int { return $this->driver->hset($key, $fields); }
    /** @return array<string, string> */
    public function hgetall(string $key): array { return $this->driver->hgetall($key); }
    /**
     * @param array<int, string> $fields
     * @return array<int, string|null>
     */
    public function hmget(string $key, array $fields): array { return $this->driver->hmget($key, $fields); }
    public function hincrby(string $key, string $field, int $by): int { return $this->driver->hincrby($key, $field, $by); }
    public function hincrbyfloat(string $key, string $field, float $by): float { return $this->driver->hincrbyfloat($key, $field, $by); }
    public function hdel(string $key, string ...$fields): int { return $this->driver->hdel($key, ...$fields); }

    // ── sets ────────────────────────────────────────────────────────────
    /** @param array<int, string> $members */
    public function sadd(string $key, array $members): int { return $this->driver->sadd($key, $members); }
    /** @param array<int, string> $members */
    public function srem(string $key, array $members): int { return $this->driver->srem($key, $members); }
    public function scard(string $key): int { return $this->driver->scard($key); }
    /** @return \Generator<int, string> */
    public function sscan(string $key, int $batch = 100): \Generator { yield from $this->driver->sscan($key, $batch); }

    // ── counters ────────────────────────────────────────────────────────
    public function incrby(string $key, int $by): int { return $this->driver->incrby($key, $by); }
    public function decrby(string $key, int $by): int { return $this->driver->decrby($key, $by); }

    // ── scripting + scan + lifecycle ────────────────────────────────────
    /**
     * @param array<int, string> $keys
     * @param array<int, string> $args
     */
    public function evalScript(string $script, array $keys, array $args): mixed { return $this->driver->evalScript($script, $keys, $args); }

    /** @return \Generator<int, string> */
    public function scanKeys(string $match, int $batch = 200): \Generator { yield from $this->driver->scanKeys($match, $batch); }

    public function ping(): bool { return $this->driver->ping(); }
    public function close(): void { $this->driver->close(); }
    /** @return list<mixed> */
    public function pipeline(callable $batch): array { return $this->driver->pipeline($batch); }
}
