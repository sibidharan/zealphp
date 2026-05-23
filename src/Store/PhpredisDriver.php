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
    /** Original URL kept so subscribe() can spawn fresh per-coroutine clients. */
    private string $url;

    public function __construct(string $url)
    {
        if (!extension_loaded('redis')) {
            throw new StoreException('phpredis extension not loaded — install ext-redis or use predis');
        }
        $this->url = $url;
        try {
            $this->c = self::buildClient($url);
        } catch (\RedisException $e) {
            throw new StoreException('phpredis connect failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Build a fresh \Redis client connected per the URL. */
    private static function buildClient(string $url): \Redis
    {
        $parts = self::parseUrl($url);
        $r = new \Redis();
        if ($parts['tls']) {
            // C3: rediss:// or tls:// — pass an explicit stream context.
            // verify_peer=true by default; user can layer additional context
            // via the URL's reserved query string (NOT YET parsed — defer
            // to v0.2.42 when a real config surface lands). Default is the
            // secure path; weakening it requires explicit user action.
            $r->connect(
                $parts['host'],
                $parts['port'],
                2.0,
                null,
                0,
                0,
                ['stream' => ['verify_peer' => true, 'verify_peer_name' => true]],
            );
        } else {
            $r->connect($parts['host'], $parts['port'], 2.0);
        }
        if ($parts['pass'] !== null) { $r->auth($parts['pass']); }
        if ($parts['db'] !== 0)      { $r->select($parts['db']); }
        return $r;
    }

    /**
     * Parse a redis:// or rediss:// (TLS) URL. Predis also accepts tls://
     * as a synonym for rediss://; phpredis is consistent with that.
     *
     * @return array{scheme:string,host:string,port:int,pass:?string,db:int,tls:bool}
     */
    private static function parseUrl(string $url): array
    {
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host'])) {
            throw new StoreException("invalid redis url: $url (expected scheme://host[:port][/db])");
        }
        $scheme = strtolower((string) $p['scheme']);
        if (!in_array($scheme, ['redis', 'rediss', 'tls'], true)) {
            throw new StoreException("unsupported redis scheme: $scheme (expected redis | rediss | tls)");
        }
        return [
            'scheme' => $scheme,
            'host'   => (string) $p['host'],
            'port'   => (int)    ($p['port'] ?? 6379),
            'pass'   => isset($p['pass']) ? (string) $p['pass'] : null,
            'db'     => isset($p['path']) ? (int) ltrim((string) $p['path'], '/') : 0,
            'tls'    => $scheme === 'rediss' || $scheme === 'tls',
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

    public function sscanCursor(string $key, string $cursor, int $count): array
    {
        try {
            // phpredis sScan takes cursor by reference (int|null). Drive it
            // exactly once and return whatever cursor it leaves in place.
            $cur = $cursor === '0' || $cursor === '' ? null : (int) $cursor;
            $batchRes = $this->c->sScan($key, $cur, '*', $count);
            if ($batchRes === false) { return ['0', []]; }
            $out = [];
            foreach ($batchRes as $m) { $out[] = (string) $m; }
            // After the by-ref call, $cur is whatever phpredis set; coerce
            // through is_int() to satisfy phpstan + handle the null end-of-scan.
            return [is_int($cur) ? (string) $cur : '0', $out];
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

    public function scanCursor(string $match, string $cursor, int $count): array
    {
        try {
            $cur = $cursor === '0' || $cursor === '' ? null : (int) $cursor;
            $keys = $this->c->scan($cur, $match, $count);
            if ($keys === false) { return ['0', []]; }
            $out = [];
            foreach ($keys as $k) { $out[] = (string) $k; }
            return [is_int($cur) ? (string) $cur : '0', $out];
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
        try {
            $this->c->close();
        } catch (\Throwable $e) {
            // Tolerant on shutdown (most callers don't care), but emit at
            // debug level so genuine disconnect bugs are diagnosable in
            // production. H9 — pre-v0.2.41 this caught and dropped.
            if (function_exists('ZealPHP\\elog')) {
                \ZealPHP\elog('PhpredisDriver::close failed: ' . $e->getMessage(), 'debug');
            } elseif (function_exists('elog')) {
                elog('PhpredisDriver::close failed: ' . $e->getMessage(), 'debug');
            }
        }
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

    public function mhgetall(array $keys): array
    {
        if ($keys === []) { return []; }
        try {
            $p = $this->c->multi(\Redis::PIPELINE);
            foreach ($keys as $k) { $p->hGetAll($k); }
            $r = $p->exec();
            if (!is_array($r)) { return []; }
            $out = [];
            foreach ($r as $i => $row) {
                $coerced = [];
                if (is_array($row)) {
                    foreach ($row as $hk => $hv) {
                        $coerced[(string) $hk] = is_scalar($hv) ? (string) $hv : '';
                    }
                }
                $out[(int) $i] = $coerced;
            }
            return $out;
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function mhsetWithMembership(array $writes, ?string $setKey = null, ?int $ttl = null): void
    {
        if ($writes === []) { return; }
        try {
            $p = $this->c->multi(\Redis::PIPELINE);
            $newMembers = [];
            foreach ($writes as $w) {
                $p->hMSet($w['rk'], $w['fields']);
                if ($ttl !== null && $ttl > 0) {
                    $p->expire($w['rk'], $ttl);
                }
                if ($setKey !== null && isset($w['sk'])) {
                    $newMembers[] = $w['sk'];
                }
            }
            if ($setKey !== null && $newMembers !== []) {
                // sAdd is variadic in phpredis; pass each member as a separate arg.
                $p->sAdd($setKey, ...$newMembers);
            }
            $p->exec();
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function unlink(string ...$keys): int
    {
        if ($keys === []) { return 0; }
        try {
            $r = $this->c->unlink($keys);
            if (!is_int($r)) {
                throw new StoreException('phpredis unlink: expected int, got ' . get_debug_type($r));
            }
            return $r;
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function publish(string $channel, string $payload): int
    {
        try {
            $r = $this->c->publish($channel, $payload);
            return is_int($r) ? $r : 0;
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    /**
     * phpredis splits SUBSCRIBE / PSUBSCRIBE into two separate blocking
     * methods, each with its own callback shape. The driver unifies them
     * under the single RedisDriver::subscribe() contract by running both
     * in parallel coroutines whose callbacks push frames into a shared
     * Channel that the driver routes to the user-supplied consumer.
     */
    public function subscribe(array $exactChannels, array $patternChannels, callable $consumer): void
    {
        if ($exactChannels === [] && $patternChannels === []) {
            throw new StoreException('subscribe(): need at least one channel or pattern');
        }
        $url    = $this->url;
        $frames = new \OpenSwoole\Coroutine\Channel(64);
        // Cross-coroutine running flag — Atomic is the genuinely-correct
        // primitive (shared mutable state across forked coroutines) AND it
        // hides the bool literal from PHPStan's flow analyser which would
        // otherwise constant-fold the !== 0 check inside the inner closures.
        $running = new \OpenSwoole\Atomic(1);

        // Two parallel coroutines, each owning its OWN \Redis client. Sharing
        // $this->c between them would race on the underlying socket — phpredis
        // is not coroutine-safe on a single connection. Each cor connects via
        // self::buildClient() so subscribe + psubscribe own independent sockets.

        if ($exactChannels !== []) {
            go(function () use ($url, $frames, $exactChannels, $running): void {
                try {
                    $client = self::buildClient($url);
                    $client->subscribe($exactChannels, function ($_redis, string $channel, string $payload) use ($frames, $running): void {
                        $frames->push(['channel' => $channel, 'payload' => $payload, 'pattern' => null]);
                        if ($running->get() === 0) { throw new PubSubStopException(); }
                    });
                } catch (PubSubStopException) {
                    /* normal stop — sentinel propagated from inside the callback */
                } catch (\RedisException) {
                    /* phpredis re-throws the in-callback PubSubStopException as
                       a 'read error on connection' RedisException once the
                       subscribe socket closes. Same intent as the sentinel —
                       runner already saw the stop via $running; silently exit. */
                }
            });
        }
        if ($patternChannels !== []) {
            go(function () use ($url, $frames, $patternChannels, $running): void {
                try {
                    $client = self::buildClient($url);
                    $client->psubscribe($patternChannels, function ($_redis, string $pattern, string $channel, string $payload) use ($frames, $running): void {
                        $frames->push(['channel' => $channel, 'payload' => $payload, 'pattern' => $pattern]);
                        if ($running->get() === 0) { throw new PubSubStopException(); }
                    });
                } catch (PubSubStopException) {
                    /* normal stop */
                } catch (\RedisException) {
                    /* same wrap-as-RedisException case as subscribe (above) */
                }
            });
        }

        try {
            while ($running->get() === 1) {
                /** @var mixed $frame */
                $frame = $frames->pop(60.0);
                if ($frame === false) { continue; }
                if (!is_array($frame)) { continue; }
                $payload = isset($frame['payload']) && is_string($frame['payload']) ? $frame['payload'] : '';
                $channel = isset($frame['channel']) && is_string($frame['channel']) ? $frame['channel'] : '';
                $pattern = isset($frame['pattern']) && is_string($frame['pattern']) ? $frame['pattern'] : null;
                try { $consumer($payload, $channel, $pattern); }
                catch (PubSubStopException) { $running->set(0); break; }
            }
        } finally {
            $running->set(0);
        }
    }

    public function xadd(string $stream, array $fields, ?int $maxLen = null): string
    {
        if ($fields === []) { throw new StoreException('xadd(): fields must be non-empty'); }
        try {
            $id = $maxLen === null
                ? $this->c->xAdd($stream, '*', $fields)
                : $this->c->xAdd($stream, '*', $fields, $maxLen, true);
            if (is_string($id)) { return $id; }
            throw new StoreException('phpredis xAdd: unexpected return: ' . get_debug_type($id));
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function xgroupCreate(string $stream, string $group, string $id = '$', bool $mkStream = true): bool
    {
        try {
            $r = $this->c->xGroup('CREATE', $stream, $group, $id, $mkStream);
            if ($r === true) { return true; }
            if (is_int($r))   { return $r > 0; }
            return false;
        } catch (\RedisException $e) {
            if (str_contains($e->getMessage(), 'BUSYGROUP')) { return false; }
            throw $this->wrap($e);
        }
    }

    public function xreadGroup(string $group, string $consumer, array $streams, int $count, int $blockMs): array
    {
        if ($streams === []) { return []; }
        try {
            $streamsMap = [];
            foreach ($streams as $s) { $streamsMap[$s] = '>'; }
            $raw = $this->c->xReadGroup($group, $consumer, $streamsMap, $count, $blockMs);
            if (!is_array($raw)) { return []; }
            $out = [];
            foreach ($raw as $streamName => $entries) {
                if (!is_string($streamName) || !is_array($entries)) { continue; }
                $list = [];
                foreach ($entries as $id => $payload) {
                    $idStr = (string) $id;
                    if (!is_array($payload)) { continue; }
                    $coerced = [];
                    foreach ($payload as $k => $v) {
                        $coerced[(string) $k] = is_scalar($v) ? (string) $v : '';
                    }
                    $list[] = ['id' => $idStr, 'payload' => $coerced];
                }
                $out[$streamName] = $list;
            }
            return $out;
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function xack(string $stream, string $group, string ...$ids): int
    {
        if ($ids === []) { return 0; }
        try {
            $r = $this->c->xAck($stream, $group, $ids);
            return is_int($r) ? $r : 0;
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    public function xautoclaim(
        string $stream,
        string $group,
        string $consumer,
        int $minIdleMs,
        string $start = '0-0',
        int $count = 16,
    ): array {
        try {
            // phpredis exposes xAutoClaim($stream, $group, $consumer, $minIdle, $start, $count).
            $raw = $this->c->xAutoClaim($stream, $group, $consumer, $minIdleMs, $start, $count);
            if (!is_array($raw) || count($raw) < 2) {
                return ['0-0', []];
            }
            $nextCursor = is_string($raw[0]) ? $raw[0] : '0-0';
            $entries = $raw[1];
            if (!is_array($entries)) { return [$nextCursor, []]; }
            $list = [];
            foreach ($entries as $id => $payload) {
                $idStr = (string) $id;
                if (!is_array($payload)) { continue; }
                $coerced = [];
                foreach ($payload as $k => $v) {
                    $coerced[(string) $k] = is_scalar($v) ? (string) $v : '';
                }
                $list[] = ['id' => $idStr, 'payload' => $coerced];
            }
            return [$nextCursor, $list];
        } catch (\RedisException $e) { throw $this->wrap($e); }
    }

    private function wrap(\RedisException $e): StoreException
    {
        return new StoreException('phpredis: ' . $e->getMessage(), 0, $e);
    }
}
