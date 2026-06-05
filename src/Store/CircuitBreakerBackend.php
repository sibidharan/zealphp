<?php

declare(strict_types=1);

namespace ZealPHP\Store;

/**
 * 3-state circuit breaker decorator for `StoreBackend`. Opt-in only:
 * applications that want "Redis down → degrade to Table cache" wrap their
 * RedisBackend in this decorator at boot. Default behaviour is unchanged
 * — apps that don't wrap see the same throw-on-Redis-failure semantics
 * they had before.
 *
 * State machine:
 *   closed     — every request reaches the primary; failures incrementally
 *                tracked within `$failureWindowSec`. Exceeding
 *                `$failureThreshold` consecutive failures trips → OPEN.
 *   open       — every request fails fast for `$openDurationSec`:
 *                reads → return from the fallback backend (if configured)
 *                writes → throw immediately (no fallback semantics for writes)
 *                After the cooldown elapses, the next call transitions
 *                to HALF_OPEN and acts as a probe.
 *   half-open  — exactly one probe is sent to the primary, enforced by an
 *                atomic `cmpset(HALF_OPEN_READY → HALF_OPEN_PROBING)`: the lone
 *                CAS winner probes, every concurrent caller is turned away
 *                (fallback for reads, fail-fast for writes — no thundering
 *                herd). Probe success → CLOSED; failure → OPEN (timer resets).
 *
 * Reads vs writes:
 *   READS (get, exists, count, iterate, mget, names) — can fall back to
 *     the optional secondary backend. The fallback's contents may be
 *     stale or empty; that's strictly better than failing the request
 *     when caching is acceptable.
 *   WRITES (set, del, incr, decr, mset, clear) — NEVER fall back. A
 *     Table fallback write would diverge from the cluster-wide truth;
 *     when the primary recovers, the writes during open would be lost.
 *     Better to surface the failure to the caller via StoreException.
 *
 * Control (make/clear) is idempotent and propagates to BOTH backends:
 *   - make: schemas land in both so reads can serve from fallback when
 *     primary is open. Failure on primary increments the breaker's
 *     failure counter but doesn't throw to the caller — the schema still
 *     exists on the fallback. The failed primary make() is REMEMBERED and
 *     retried on the next write to that table (#241), so writes recover once
 *     the primary is reachable instead of throwing "table not registered"
 *     forever.
 *   - clear: a "destroy table" write; throws on open (drop semantics).
 *
 * Concurrency: all state lives in `OpenSwoole\Atomic` slots so transitions
 * are visible across coroutines AND across workers (Atomic is shared
 * memory). Two cors crossing the threshold simultaneously may BOTH
 * write OPEN to the same value — idempotent, harmless.
 *
 * Caveat — failure classification: this version treats every
 * `StoreException` from the primary as a "Redis unavailable" signal.
 * That includes schema-violation errors that aren't transport failures.
 * Recommend setting `$failureThreshold` high enough that legitimate
 * schema errors don't cumulatively trip the breaker. A typed
 * `StoreUnavailableException` for finer-grained classification is on
 * the v0.2.42 roadmap.
 */
final class CircuitBreakerBackend implements StoreBackend
{
    private const CLOSED = 0;
    private const OPEN = 1;
    /**
     * Half-open, awaiting a probe. The FIRST caller to win the atomic
     * `cmpset(HALF_OPEN_READY, HALF_OPEN_PROBING)` is admitted as the lone
     * probe (#255); concurrent callers lose the CAS and fall back / fail-fast
     * exactly like OPEN, so the primary sees a single probe — not a herd.
     */
    private const HALF_OPEN_READY = 2;
    /** Half-open, a probe is in flight. Concurrent callers are turned away. */
    private const HALF_OPEN_PROBING = 3;

    private \OpenSwoole\Atomic $state;
    private \OpenSwoole\Atomic $failureCount;
    private \OpenSwoole\Atomic $firstFailureAt;
    private \OpenSwoole\Atomic $openedAt;
    private Stats $stats;

    /**
     * Per-table args of a primary `make()` that FAILED — keyed by table name,
     * value `[maxRows, columns, opts]`. #241: the breaker stays CLOSED after a
     * single make() failure (threshold default 5), but the primary never had
     * the table registered, so every later write would throw "table not
     * registered" forever. The write entrypoints retry the pending make() (when
     * not OPEN) and clear the entry on success.
     *
     * @var array<string, array{0:int, 1:array<string, array{0:int, 1?:int}>, 2:array<string, mixed>}>
     */
    private array $primaryMakePending = [];

    public function __construct(
        private StoreBackend $primary,
        private ?StoreBackend $fallback = null,
        private int $failureThreshold = 5,
        private int $failureWindowSec = 10,
        private int $openDurationSec = 30,
    ) {
        if ($failureThreshold < 1) {
            throw new StoreException('CircuitBreakerBackend: $failureThreshold must be >= 1');
        }
        $this->state          = new \OpenSwoole\Atomic(self::CLOSED);
        $this->failureCount   = new \OpenSwoole\Atomic(0);
        $this->firstFailureAt = new \OpenSwoole\Atomic(0);
        $this->openedAt       = new \OpenSwoole\Atomic(0);
        $this->stats          = new Stats();
    }

    /** Returns 'closed', 'open', or 'half-open' — for introspection + tests. */
    public function state(): string
    {
        return match ($this->state->get()) {
            self::CLOSED           => 'closed',
            self::OPEN             => 'open',
            self::HALF_OPEN_READY,
            self::HALF_OPEN_PROBING => 'half-open',
            default                => '?',
        };
    }

    /** True while the breaker is in either half-open sub-state (ready or probing). */
    private function isHalfOpen(): bool
    {
        $s = $this->state->get();
        return $s === self::HALF_OPEN_READY || $s === self::HALF_OPEN_PROBING;
    }

    /**
     * #255 — admit exactly one half-open probe. Atomically claims the probe
     * slot via `cmpset(HALF_OPEN_READY → HALF_OPEN_PROBING)`: the single winner
     * gets `true` and proceeds to the primary; every concurrent loser gets
     * `false` and is turned away (fallback for reads, fail-fast for writes).
     * Returns `false` when the breaker isn't half-open-ready (e.g. another
     * coroutine already took the probe slot).
     */
    private function admitHalfOpenProbe(): bool
    {
        return $this->state->cmpset(self::HALF_OPEN_READY, self::HALF_OPEN_PROBING);
    }

    /** Per-worker breaker stats — breaker_opened_total, breaker_closed_total, breaker_short_circuited_total. */
    public function stats(): Stats { return $this->stats; }

    /** Force the breaker back to CLOSED — operational override (e.g. after
     *  ops fixes Redis and wants to skip the cooldown). */
    public function reset(): void
    {
        $this->state->set(self::CLOSED);
        $this->failureCount->set(0);
        $this->firstFailureAt->set(0);
        $this->openedAt->set(0);
    }

    /** Maybe transition OPEN → HALF_OPEN_READY if the cooldown has elapsed. */
    private function probeStateMaybe(): void
    {
        if ($this->state->get() !== self::OPEN) { return; }
        if (time() - $this->openedAt->get() >= $this->openDurationSec) {
            // CAS so only one of N concurrent callers flips OPEN → READY; the
            // admission CAS in callRead/callWrite then picks the single prober.
            $this->state->cmpset(self::OPEN, self::HALF_OPEN_READY);
        }
    }

    private function recordSuccess(): void
    {
        if ($this->isHalfOpen()) {
            $this->state->set(self::CLOSED);
            $this->stats->inc('breaker_closed_total');
        }
        $this->failureCount->set(0);
        $this->firstFailureAt->set(0);
    }

    private function recordFailure(): void
    {
        $now = time();
        $first = $this->firstFailureAt->get();
        // Sliding window: if the oldest failure is outside the window,
        // reset the counter before incrementing.
        if ($first > 0 && ($now - $first) > $this->failureWindowSec) {
            $this->failureCount->set(0);
            $this->firstFailureAt->set(0);
        }
        if ($this->firstFailureAt->get() === 0) {
            $this->firstFailureAt->set($now);
        }
        $count = $this->failureCount->add(1);

        // Half-open probe failed → straight back to open with fresh cooldown.
        if ($this->isHalfOpen()) {
            $this->state->set(self::OPEN);
            $this->openedAt->set($now);
            $this->stats->inc('breaker_opened_total');
            return;
        }
        if ($count >= $this->failureThreshold) {
            $this->state->set(self::OPEN);
            $this->openedAt->set($now);
            $this->stats->inc('breaker_opened_total');
        }
    }

    /**
     * @template T
     * @param  callable(): T $primary
     * @param  callable(): T $fallback
     * @return T
     */
    private function callRead(callable $primary, callable $fallback): mixed
    {
        $this->probeStateMaybe();
        // OPEN, or HALF_OPEN but this caller lost the single-probe CAS (#255):
        // short-circuit to the fallback. Only the admitted prober reaches the
        // primary; a herd of concurrent half-open readers serves from fallback.
        if ($this->state->get() === self::OPEN
            || ($this->isHalfOpen() && !$this->admitHalfOpenProbe())) {
            $this->stats->inc('breaker_short_circuited_total');
            if ($this->fallback === null) {
                throw new StoreException('CircuitBreakerBackend: primary OPEN and no fallback configured');
            }
            return $fallback();
        }
        try {
            $r = $primary();
            $this->recordSuccess();
            return $r;
        } catch (StoreException $e) {
            $this->recordFailure();
            if ($this->fallback === null) {
                throw $e;
            }
            return $fallback();
        }
    }

    /**
     * @template T
     * @param  callable(): T $primary
     * @param  string|null   $table  When set, a pending primary make() for this
     *                               table is retried before the write (#241).
     * @return T
     */
    private function callWrite(callable $primary, ?string $table = null): mixed
    {
        $this->probeStateMaybe();
        // OPEN, or HALF_OPEN but this caller lost the single-probe CAS (#255):
        // writes never fall back — fail fast, just like OPEN.
        if ($this->state->get() === self::OPEN
            || ($this->isHalfOpen() && !$this->admitHalfOpenProbe())) {
            $this->stats->inc('breaker_short_circuited_total');
            throw new StoreException('CircuitBreakerBackend: primary OPEN — refusing write (no fallback semantics for writes)');
        }
        // #241: if the primary's make() failed earlier, the table isn't
        // registered on it — retry now (we're CLOSED or the admitted probe) so
        // the write doesn't hit "table not registered" forever. A retry failure
        // is recorded like any other primary failure and the write still throws.
        if ($table !== null) {
            $this->retryPrimaryMake($table);
        }
        try {
            $r = $primary();
            $this->recordSuccess();
            return $r;
        } catch (StoreException $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    /**
     * #241 — retry a primary `make()` that failed during `make()`. Re-runs the
     * original args; on success the pending entry is cleared so subsequent
     * writes take the fast path. On failure the entry is kept (so the next
     * write retries again) and the exception propagates — the caller's write
     * then surfaces it through the normal failure path.
     */
    private function retryPrimaryMake(string $name): void
    {
        if (!isset($this->primaryMakePending[$name])) { return; }
        [$maxRows, $columns, $opts] = $this->primaryMakePending[$name];
        $this->primary->make($name, $maxRows, $columns, $opts);
        unset($this->primaryMakePending[$name]);
    }

    public function make(string $name, int $maxRows, array $columns, array $opts = []): void
    {
        // make() lands the schema on BOTH backends so reads can serve from
        // fallback when primary is open. Failure on primary counts as a
        // breaker failure but doesn't propagate — the schema still exists
        // on the fallback.
        try {
            $this->primary->make($name, $maxRows, $columns, $opts);
            $this->recordSuccess();
            unset($this->primaryMakePending[$name]);
        } catch (StoreException) {
            $this->recordFailure();
            // #241: remember the args so the next write can retry the make()
            // (it didn't land on the primary — without this the table stays
            // unregistered and every later write throws "table not registered").
            $this->primaryMakePending[$name] = [$maxRows, $columns, $opts];
        }
        $this->fallback?->make($name, $maxRows, $columns, $opts);
    }

    public function set(string $name, string $key, array $row): bool
    {
        return (bool) $this->callWrite(fn(): bool => $this->primary->set($name, $key, $row), $name);
    }

    public function get(string $name, string $key, ?string $field = null): mixed
    {
        return $this->callRead(
            fn() => $this->primary->get($name, $key, $field),
            fn() => $this->fallback?->get($name, $key, $field),
        );
    }

    public function del(string $name, string $key): bool
    {
        return (bool) $this->callWrite(fn(): bool => $this->primary->del($name, $key), $name);
    }

    public function exists(string $name, string $key): bool
    {
        return (bool) $this->callRead(
            fn(): bool => $this->primary->exists($name, $key),
            fn(): bool => $this->fallback?->exists($name, $key) ?? false,
        );
    }

    public function incr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        /** @var int|float $r */
        $r = $this->callWrite(fn(): int|float => $this->primary->incr($name, $key, $col, $by), $name);
        return $r;
    }

    public function decr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        /** @var int|float $r */
        $r = $this->callWrite(fn(): int|float => $this->primary->decr($name, $key, $col, $by), $name);
        return $r;
    }

    public function count(string $name): int
    {
        return (int) $this->callRead(
            fn(): int => $this->primary->count($name),
            fn(): int => $this->fallback?->count($name) ?? 0,
        );
    }

    public function names(): array
    {
        /** @var list<string> $r */
        $r = $this->callRead(
            fn(): array => $this->primary->names(),
            fn(): array => $this->fallback?->names() ?? [],
        );
        return $r;
    }

    public function iterate(string $name): \Generator
    {
        $this->probeStateMaybe();
        // OPEN, or HALF_OPEN but this caller lost the single-probe CAS (#255).
        if ($this->state->get() === self::OPEN
            || ($this->isHalfOpen() && !$this->admitHalfOpenProbe())) {
            $this->stats->inc('breaker_short_circuited_total');
            if ($this->fallback !== null) {
                yield from $this->fallback->iterate($name);
                return;
            }
            throw new StoreException('CircuitBreakerBackend: primary OPEN and no fallback configured');
        }
        try {
            yield from $this->primary->iterate($name);
            $this->recordSuccess();
        } catch (StoreException $e) {
            $this->recordFailure();
            if ($this->fallback === null) { throw $e; }
            yield from $this->fallback->iterate($name);
        }
    }

    public function iteratePaged(string $name, string $cursor = '0', int $count = 100): array
    {
        /** @var array{cursor: string, rows: array<string, array<string, scalar>>} $r */
        $r = $this->callRead(
            fn(): array => $this->primary->iteratePaged($name, $cursor, $count),
            fn(): array => $this->fallback?->iteratePaged($name, $cursor, $count) ?? ['cursor' => '0', 'rows' => []],
        );
        return $r;
    }

    public function clear(string $name): void
    {
        $this->callWrite(function () use ($name): bool {
            $this->primary->clear($name);
            return true;
        }, $name);
    }

    public function mget(string $name, array $keys): array
    {
        /** @var array<string, array<string, scalar>|null> $r */
        $r = $this->callRead(
            fn(): array => $this->primary->mget($name, $keys),
            fn(): array => $this->fallback?->mget($name, $keys) ?? [],
        );
        return $r;
    }

    public function mset(string $name, array $rows): bool
    {
        return (bool) $this->callWrite(fn(): bool => $this->primary->mset($name, $rows), $name);
    }
}
