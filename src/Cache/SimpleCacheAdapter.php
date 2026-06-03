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

        if ($this->isExpiredTtl($ttl)) {
            Cache::del($key);
            return true;
        }

        return Cache::set($key, $value, $this->normalizeTtl($ttl));
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
        $expired    = $this->isExpiredTtl($ttl);
        $ttlSeconds = $this->normalizeTtl($ttl);
        $success = true;

        foreach ($values as $key => $value) {
            $this->validateKey($key);
            if ($expired) {
                Cache::del($key);
            } else {
                if (!Cache::set($key, $value, $ttlSeconds)) {
                    $success = false;
                }
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
     * PSR-16 distinguishes a null TTL ("use default / persist") from an explicit
     * non-positive TTL ("expire immediately"). normalizeTtl() collapses null to 0,
     * so we must consult the ORIGINAL $ttl to tell the two apart: null persists,
     * an explicit 0 / negative / non-positive DateInterval expires now (#187).
     */
    private function isExpiredTtl(null|int|\DateInterval $ttl): bool
    {
        if ($ttl === null) {
            return false;
        }
        return $this->normalizeTtl($ttl) <= 0;
    }

    private function normalizeTtl(null|int|\DateInterval $ttl): int
    {
        if ($ttl === null) {
            return 0;
        }

        if (is_int($ttl)) {
            return $ttl;
        }

        return (new \DateTime())->add($ttl)->getTimestamp() - time();
    }
}
