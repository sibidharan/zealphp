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

    public function publish(string $channel, string $payload): int
    {
        try {
            /** @var mixed $r */
            $r = $this->c->publish($channel, $payload);
            return $this->asInt($r, 'publish');
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function subscribe(array $exactChannels, array $patternChannels, callable $consumer): void
    {
        if ($exactChannels === [] && $patternChannels === []) {
            throw new StoreException('subscribe(): need at least one channel or pattern');
        }
        try {
            $loop = $this->c->pubSubLoop();
            if ($loop === null) { throw new StoreException('predis pubSubLoop returned null'); }
            if ($exactChannels   !== []) { $loop->subscribe(...$exactChannels); }
            if ($patternChannels !== []) { $loop->psubscribe(...$patternChannels); }
            foreach ($loop as $frame) {
                /** @var mixed $frame */
                if (!is_object($frame) || !property_exists($frame, 'kind')) { continue; }
                $kind = is_scalar($frame->kind) ? (string) $frame->kind : '';
                if ($kind !== 'message' && $kind !== 'pmessage') { continue; }
                $channel = property_exists($frame, 'channel') && is_scalar($frame->channel) ? (string) $frame->channel : '';
                $payload = property_exists($frame, 'payload') && is_scalar($frame->payload) ? (string) $frame->payload : '';
                $pattern = null;
                if ($kind === 'pmessage' && property_exists($frame, 'pattern') && is_scalar($frame->pattern)) {
                    $pattern = (string) $frame->pattern;
                }
                try { $consumer($payload, $channel, $pattern); }
                catch (PubSubStopException) { $loop->unsubscribe(); return; }
            }
        } catch (PubSubStopException) {
            return;
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function xadd(string $stream, array $fields, ?int $maxLen = null): string
    {
        if ($fields === []) {
            throw new StoreException('xadd(): fields must be non-empty');
        }
        try {
            $cmd = ['XADD', $stream];
            if ($maxLen !== null) { array_push($cmd, 'MAXLEN', '~', (string) $maxLen); }
            $cmd[] = '*';
            foreach ($fields as $k => $v) { $cmd[] = (string) $k; $cmd[] = (string) $v; }
            /** @var mixed $id */
            $id = $this->c->executeRaw($cmd);
            return $this->asString($id, 'xadd');
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function xgroupCreate(string $stream, string $group, string $id = '$', bool $mkStream = true): bool
    {
        try {
            $cmd = ['XGROUP', 'CREATE', $stream, $group, $id];
            if ($mkStream) { $cmd[] = 'MKSTREAM'; }
            $this->c->executeRaw($cmd);
            return true;
        } catch (PredisException $e) {
            if (str_contains($e->getMessage(), 'BUSYGROUP')) { return false; }
            throw $this->wrap($e);
        }
    }

    public function xreadGroup(string $group, string $consumer, array $streams, int $count, int $blockMs): array
    {
        if ($streams === []) { return []; }
        try {
            $cmd = ['XREADGROUP', 'GROUP', $group, $consumer, 'COUNT', (string) $count, 'BLOCK', (string) $blockMs, 'STREAMS'];
            foreach ($streams as $s) { $cmd[] = $s; }
            foreach ($streams as $_) { $cmd[] = '>'; }
            /** @var mixed $raw */
            $raw = $this->c->executeRaw($cmd);
            // raw: null on timeout, otherwise [ [ <stream>, [ [<id>, [<f>,<v>,<f>,<v>...]], ... ] ], ... ]
            if (!is_array($raw)) { return []; }
            $out = [];
            foreach ($raw as $streamBlock) {
                if (!is_array($streamBlock) || count($streamBlock) < 2) { continue; }
                $streamName = is_scalar($streamBlock[0]) ? (string) $streamBlock[0] : '';
                $entries = $streamBlock[1];
                if (!is_array($entries) || $streamName === '') { continue; }
                $list = [];
                foreach ($entries as $entry) {
                    if (!is_array($entry) || count($entry) < 2) { continue; }
                    $id = is_scalar($entry[0]) ? (string) $entry[0] : '';
                    $flat = $entry[1];
                    if ($id === '' || !is_array($flat)) { continue; }
                    $payload = [];
                    $kv = array_values($flat);
                    for ($i = 0; $i + 1 < count($kv); $i += 2) {
                        $k = is_scalar($kv[$i])     ? (string) $kv[$i]     : null;
                        $v = is_scalar($kv[$i + 1]) ? (string) $kv[$i + 1] : null;
                        if ($k === null || $v === null) { continue; }
                        $payload[$k] = $v;
                    }
                    $list[] = ['id' => $id, 'payload' => $payload];
                }
                $out[$streamName] = $list;
            }
            return $out;
        } catch (PredisException $e) { throw $this->wrap($e); }
    }

    public function xack(string $stream, string $group, string ...$ids): int
    {
        if ($ids === []) { return 0; }
        try {
            $cmd = ['XACK', $stream, $group];
            foreach ($ids as $id) { $cmd[] = $id; }
            /** @var mixed $r */
            $r = $this->c->executeRaw($cmd);
            return $this->asInt($r, 'xack');
        } catch (PredisException $e) { throw $this->wrap($e); }
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
