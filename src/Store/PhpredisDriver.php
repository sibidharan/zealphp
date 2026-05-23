<?php

declare(strict_types=1);

namespace ZealPHP\Store;

/**
 * phpredis-backed driver. Only instantiable when the `redis` PHP extension
 * is loaded; the adapter falls back to predis otherwise. Tests skip the
 * phpredis-specific path when the ext isn't present (covered by CI matrix).
 */
final class PhpredisDriver implements RedisDriver
{
    private \Redis $c;

    public function __construct(string $url)
    {
        if (!extension_loaded('redis')) {
            throw new StoreException('phpredis extension not loaded — install ext-redis or use predis');
        }
        $parts = self::parseUrl($url);
        try {
            $this->c = new \Redis();
            $this->c->connect($parts['host'], $parts['port'], 2.0);
            if ($parts['pass'] !== null) { $this->c->auth($parts['pass']); }
            if ($parts['db'] !== 0)      { $this->c->select($parts['db']); }
        } catch (\RedisException $e) {
            throw new StoreException('phpredis connect failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @return array{host:string,port:int,pass:?string,db:int} */
    private static function parseUrl(string $url): array
    {
        $p = parse_url($url);
        if ($p === false) { throw new StoreException("invalid redis url: $url"); }
        return [
            'host' => (string) ($p['host'] ?? '127.0.0.1'),
            'port' => (int)    ($p['port'] ?? 6379),
            'pass' => isset($p['pass']) ? (string) $p['pass'] : null,
            'db'   => isset($p['path']) ? (int) ltrim((string) $p['path'], '/') : 0,
        ];
    }

    public function name(): string { return 'phpredis'; }

    public function set(string $key, string $value, ?int $ttlSeconds = null): bool
    {
        try {
            $r = $ttlSeconds === null
                ? $this->c->set($key, $value)
                : $this->c->set($key, $value, ['EX' => $ttlSeconds]);
            return $r === true || $r === 'OK';
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function get(string $key): ?string
    {
        try {
            /** @var mixed $v */
            $v = $this->c->get($key);
            if ($v === false || $v === null) { return null; }
            if (is_string($v)) { return $v; }
            if (is_scalar($v)) { return (string) $v; }
            throw new StoreException('phpredis get: non-scalar return: ' . get_debug_type($v));
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function del(string ...$keys): int
    {
        if ($keys === []) { return 0; }
        try { return $this->c->del($keys); }
        catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function exists(string $key): bool
    {
        try {
            $r = $this->c->exists($key);
            return is_int($r) ? $r > 0 : $r;
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        try { return $this->c->expire($key, $ttlSeconds); }
        catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function hset(string $key, array $fields): int
    {
        if ($fields === []) { return 0; }
        try {
            $ok = $this->c->hMSet($key, $fields);
            return $ok ? count($fields) : 0;
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function hgetall(string $key): array
    {
        try {
            $out = [];
            foreach ($this->c->hGetAll($key) as $k => $v) { $out[(string) $k] = (string) $v; }
            return $out;
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function hmget(string $key, array $fields): array
    {
        if ($fields === []) { return []; }
        try {
            $raw = $this->c->hMGet($key, $fields);
            $out = [];
            foreach ($fields as $f) {
                $v = $raw[$f] ?? false;
                if ($v === false) { $out[] = null; continue; }
                if (is_string($v)) { $out[] = $v; continue; }
                if (is_scalar($v)) { $out[] = (string) $v; continue; }
                throw new StoreException('phpredis hmget: non-scalar field value: ' . get_debug_type($v));
            }
            return $out;
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function hincrby(string $key, string $field, int $by): int
    {
        try { return $this->c->hIncrBy($key, $field, $by); }
        catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function hincrbyfloat(string $key, string $field, float $by): float
    {
        try { return $this->c->hIncrByFloat($key, $field, $by); }
        catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function hdel(string $key, string ...$fields): int
    {
        if ($fields === []) { return 0; }
        try { return $this->c->hDel($key, ...$fields); }
        catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function sadd(string $key, array $members): int
    {
        if ($members === []) { return 0; }
        try { return $this->c->sAdd($key, ...$members); }
        catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function srem(string $key, array $members): int
    {
        if ($members === []) { return 0; }
        try { return $this->c->sRem($key, ...$members); }
        catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function scard(string $key): int
    {
        try { return $this->c->sCard($key); }
        catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function sscan(string $key, int $batch = 100): \Generator
    {
        try {
            $cursor = null;
            do {
                $batchRes = $this->c->sScan($key, $cursor, '*', $batch);
                if ($batchRes === false) { break; }
                foreach ($batchRes as $m) { yield (string) $m; }
            } while ($cursor > 0);
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function incrby(string $key, int $by): int
    {
        try { return $this->c->incrBy($key, $by); }
        catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function decrby(string $key, int $by): int
    {
        try { return $this->c->decrBy($key, $by); }
        catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function evalScript(string $script, array $keys, array $args): mixed
    {
        try { return $this->c->eval($script, array_merge($keys, $args), count($keys)); }
        catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function scanKeys(string $match, int $batch = 200): \Generator
    {
        try {
            $cursor = null;
            do {
                $keys = $this->c->scan($cursor, $match, $batch);
                if ($keys === false) { break; }
                foreach ($keys as $k) { yield (string) $k; }
            } while ($cursor > 0);
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function ping(): bool
    {
        try {
            $r = $this->c->ping();
            return $r === true || $r === 'PONG' || $r === '+PONG';
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function close(): void
    {
        try { $this->c->close(); } catch (\Throwable $e) { /* tolerant */ }
    }

    public function pipeline(callable $batch): array
    {
        try {
            $p = $this->c->multi(\Redis::PIPELINE);
            $batch($p);
            $r = $p->exec();
            return is_array($r) ? array_values($r) : [];
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    private function wrap(\RedisException $e): StoreException
    {
        return new StoreException('phpredis: ' . $e->getMessage(), 0, $e);
    }
}
