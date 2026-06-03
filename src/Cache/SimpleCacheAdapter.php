<?php
namespace ZealPHP\Cache;

use Psr\SimpleCache\CacheInterface;
use ZealPHP\Cache;

class SimpleCacheAdapter implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        return Cache::get($key, $default);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        return $this->writeOne($key, $value, $this->normalizeTtl($ttl));
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        return Cache::del($key);
    }

    public function clear(): bool
    {
        Cache::flush();
        return true;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        return Cache::has($key);
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $this->validateKey($key);
            $result[$key] = Cache::get($key, $default);
        }
        return $result;
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $ttlSeconds = $this->normalizeTtl($ttl);
        $success = true;

        foreach ($values as $key => $value) {
            $this->validateKey($key);
            if (!$this->writeOne($key, $value, $ttlSeconds)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            $this->validateKey($key);
            if (!Cache::del($key)) {
                $success = false;
            }
        }
        return $success;
    }

    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidCacheKeyException('Cache key must not be empty.');
        }

        if (preg_match('/[{}()\\/\\\\@:]/', $key)) {
            throw new InvalidCacheKeyException(
                "Cache key \"{$key}\" contains reserved characters: {}()/\\@:"
            );
        }
    }

    /**
     * Write a single value, applying PSR-16 TTL semantics. Shared by set()
     * and setMultiple() so they can't drift apart.
     *
     * @param ?int $ttlSeconds null = persist (use default); an explicit value
     *                         of <= 0 means the item is already expired.
     */
    private function writeOne(string $key, mixed $value, ?int $ttlSeconds): bool
    {
        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            Cache::del($key);
            return true;
        }

        return Cache::set($key, $value, $ttlSeconds ?? 0);
    }

    /**
     * Resolve a PSR-16 TTL to a second count.
     *
     * Returns null for a null TTL ("use the default" — Cache persists with no
     * expiry) so callers can tell it apart from an explicit 0/negative TTL,
     * which PSR-16 treats as "already expired".
     */
    private function normalizeTtl(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if (is_int($ttl)) {
            return $ttl;
        }

        return (new \DateTime())->add($ttl)->getTimestamp() - time();
    }
}
