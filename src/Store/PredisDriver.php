<?php

declare(strict_types=1);

namespace ZealPHP\Store;

use Predis\Client as PredisClient;
use Predis\PredisException;

/**
 * predis-backed driver. Pure PHP — works without ext-redis. Slower than
 * phpredis but parity-tested against the same RedisClientTest cases.
 *
 * predis::__call() returns mixed; this driver narrows each return at the
 * boundary so StoreException is thrown if the wire shape doesn't match
 * expectations (vs casting mixed and hiding bugs).
 */
final class PredisDriver implements RedisDriver
{
    private PredisClient $c;

    public function __construct(string $url)
    {
        try {
            $this->c = new PredisClient($url);
            $this->c->connect();
        } catch (PredisException $e) {
            throw new StoreException('predis connect failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function name(): string { return 'predis'; }

    public function set(string $key, string $value, ?int $ttlSeconds = null): bool
    {
        try {
            /** @var mixed $r */
            $r = $ttlSeconds === null
                ? $this->c->set($key, $value)
                : $this->c->set($key, $value, 'EX', $ttlSeconds);
            return $this->isOkStatus($r);
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function get(string $key): ?string
    {
        try {
            /** @var mixed $v */
            $v = $this->c->get($key);
            if ($v === null) { return null; }
            return $this->asString($v, 'get');
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function del(string ...$keys): int
    {
        if ($keys === []) { return 0; }
        try {
            /** @var mixed $r */
            $r = $this->c->del($keys);
            return $this->asInt($r, 'del');
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function exists(string $key): bool
    {
        try {
            /** @var mixed $r */
            $r = $this->c->exists($key);
            return $this->asInt($r, 'exists') > 0;
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        try {
            /** @var mixed $r */
            $r = $this->c->expire($key, $ttlSeconds);
            return $this->asInt($r, 'expire') === 1;
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function hset(string $key, array $fields): int
    {
        if ($fields === []) { return 0; }
        try {
            $args = [];
            foreach ($fields as $f => $v) { $args[] = (string) $f; $args[] = (string) $v; }
            /** @var mixed $r */
            $r = $this->c->hset($key, ...$args);
            return $this->asInt($r, 'hset');
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function hgetall(string $key): array
    {
        try {
            /** @var mixed $raw */
            $raw = $this->c->hgetall($key);
            if (!is_array($raw)) {
                throw new StoreException('predis hgetall: expected array, got ' . get_debug_type($raw));
            }
            $out = [];
            foreach ($raw as $k => $v) {
                $out[$this->asString($k, 'hgetall.key')] = $this->asString($v, 'hgetall.value');
            }
            return $out;
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function hmget(string $key, array $fields): array
    {
        if ($fields === []) { return []; }
        try {
            /** @var mixed $vals */
            $vals = $this->c->hmget($key, $fields);
            if (!is_array($vals)) {
                throw new StoreException('predis hmget: expected array, got ' . get_debug_type($vals));
            }
            $out = [];
            foreach ($vals as $v) {
                $out[] = $v === null ? null : $this->asString($v, 'hmget.value');
            }
            return $out;
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function hincrby(string $key, string $field, int $by): int
    {
        try {
            /** @var mixed $r */
            $r = $this->c->hincrby($key, $field, $by);
            return $this->asInt($r, 'hincrby');
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function hincrbyfloat(string $key, string $field, float $by): float
    {
        try {
            /** @var mixed $r */
            $r = $this->c->hincrbyfloat($key, $field, $by);
            if (is_float($r) || is_int($r)) { return (float) $r; }
            if (is_string($r) && is_numeric($r)) { return (float) $r; }
            throw new StoreException('predis hincrbyfloat: non-numeric return: ' . get_debug_type($r));
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function hdel(string $key, string ...$fields): int
    {
        if ($fields === []) { return 0; }
        try {
            /** @var mixed $r */
            $r = $this->c->hdel($key, $fields);
            return $this->asInt($r, 'hdel');
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function sadd(string $key, array $members): int
    {
        if ($members === []) { return 0; }
        try {
            /** @var mixed $r */
            $r = $this->c->sadd($key, $members);
            return $this->asInt($r, 'sadd');
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function srem(string $key, array $members): int
    {
        if ($members === []) { return 0; }
        try {
            /** @var mixed $r */
            $r = $this->c->srem($key, $members);
            return $this->asInt($r, 'srem');
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function scard(string $key): int
    {
        try {
            /** @var mixed $r */
            $r = $this->c->scard($key);
            return $this->asInt($r, 'scard');
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function sscan(string $key, int $batch = 100): \Generator
    {
        $cursor = 0;
        try {
            do {
                /** @var mixed $res */
                $res = $this->c->sscan($key, $cursor, ['count' => $batch]);
                [$cursor, $members] = $this->scanResult($res, 'sscan');
                foreach ($members as $m) { yield $this->asString($m, 'sscan.member'); }
            } while ($cursor !== 0);
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function incrby(string $key, int $by): int
    {
        try {
            /** @var mixed $r */
            $r = $this->c->incrby($key, $by);
            return $this->asInt($r, 'incrby');
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function decrby(string $key, int $by): int
    {
        try {
            /** @var mixed $r */
            $r = $this->c->decrby($key, $by);
            return $this->asInt($r, 'decrby');
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function evalScript(string $script, array $keys, array $args): mixed
    {
        try {
            $merged = array_merge($keys, $args);
            /** @var mixed $r */
            $r = $this->c->eval($script, count($keys), ...$merged);
            return $r;
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function scanKeys(string $match, int $batch = 200): \Generator
    {
        $cursor = 0;
        try {
            do {
                /** @var mixed $res */
                $res = $this->c->scan($cursor, ['match' => $match, 'count' => $batch]);
                [$cursor, $keys] = $this->scanResult($res, 'scan');
                foreach ($keys as $k) { yield $this->asString($k, 'scan.key'); }
            } while ($cursor !== 0);
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function ping(): bool
    {
        try {
            /** @var mixed $r */
            $r = $this->c->ping();
            return $this->isOkStatus($r, 'PONG');
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function close(): void
    {
        try { $this->c->disconnect(); } catch (\Throwable $e) { /* tolerant */ }
    }

    public function pipeline(callable $batch): array
    {
        try {
            /** @var mixed $r */
            $r = $this->c->pipeline(function ($pipe) use ($batch): void { $batch($pipe); });
            if (!is_array($r)) {
                throw new StoreException('predis pipeline: expected array, got ' . get_debug_type($r));
            }
            return array_values($r);
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    // ── narrowing helpers ─────────────────────────────────────────────────

    private function isOkStatus(mixed $r, string $expect = 'OK'): bool
    {
        if (is_string($r)) { return $r === $expect; }
        if (is_object($r) && method_exists($r, '__toString')) { return (string) $r === $expect; }
        return false;
    }

    private function asInt(mixed $r, string $op): int
    {
        if (is_int($r)) { return $r; }
        if (is_string($r) && is_numeric($r)) { return (int) $r; }
        throw new StoreException("predis $op: expected int, got " . get_debug_type($r));
    }

    private function asString(mixed $r, string $op): string
    {
        if (is_string($r)) { return $r; }
        if (is_scalar($r)) { return (string) $r; }
        if (is_object($r) && method_exists($r, '__toString')) { return (string) $r; }
        throw new StoreException("predis $op: expected string, got " . get_debug_type($r));
    }

    /**
     * Normalize a SCAN/SSCAN result tuple into [int cursor, list<string>].
     *
     * @return array{0:int, 1:array<int|string, mixed>}
     */
    private function scanResult(mixed $res, string $op): array
    {
        if (!is_array($res) || count($res) < 2) {
            throw new StoreException("predis $op: malformed scan result");
        }
        $cursor = $res[0] ?? null;
        $items  = $res[1] ?? null;
        if (!is_int($cursor) && !(is_string($cursor) && is_numeric($cursor))) {
            throw new StoreException("predis $op: cursor not numeric: " . get_debug_type($cursor));
        }
        if (!is_array($items)) {
            throw new StoreException("predis $op: items not array: " . get_debug_type($items));
        }
        return [(int) $cursor, $items];
    }

    private function wrap(PredisException $e): StoreException
    {
        return new StoreException('predis: ' . $e->getMessage(), 0, $e);
    }
}
