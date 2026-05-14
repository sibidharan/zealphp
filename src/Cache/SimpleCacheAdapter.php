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
        $ttlSeconds = $this->normalizeTtl($ttl);

        if ($ttlSeconds < 0) {
            Cache::del($key);
            return true;
        }

        return Cache::set($key, $value, $ttlSeconds);
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

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $ttlSeconds = $this->normalizeTtl($ttl);
        $success = true;

        foreach ($values as $key => $value) {
            $this->validateKey($key);
            if ($ttlSeconds < 0) {
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
