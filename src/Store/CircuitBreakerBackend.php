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
 *   half-open  — exactly one probe is sent to the primary. Success →
 *                back to CLOSED; failure → back to OPEN (timer resets).
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
 *     exists on the fallback.
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
    private const HALF_OPEN = 2;

    private \OpenSwoole\Atomic $state;
    private \OpenSwoole\Atomic $failureCount;
    private \OpenSwoole\Atomic $firstFailureAt;
    private \OpenSwoole\Atomic $openedAt;
    private Stats $stats;

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
            self::CLOSED    => 'closed',
            self::OPEN      => 'open',
            self::HALF_OPEN => 'half-open',
            default         => '?',
        };
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

    /** Maybe transition OPEN → HALF_OPEN if the cooldown has elapsed. */
    private function probeStateMaybe(): void
    {
        if ($this->state->get() !== self::OPEN) { return; }
        if (time() - $this->openedAt->get() >= $this->openDurationSec) {
            $this->state->set(self::HALF_OPEN);
        }
    }

    private function recordSuccess(): void
    {
        if ($this->state->get() === self::HALF_OPEN) {
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
        if ($this->state->get() === self::HALF_OPEN) {
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
        if ($this->state->get() === self::OPEN) {
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
     * @return T
     */
    private function callWrite(callable $primary): mixed
    {
        $this->probeStateMaybe();
        if ($this->state->get() === self::OPEN) {
            $this->stats->inc('breaker_short_circuited_total');
            throw new StoreException('CircuitBreakerBackend: primary OPEN — refusing write (no fallback semantics for writes)');
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

    public function make(string $name, int $maxRows, array $columns, array $opts = []): void
    {
        // make() lands the schema on BOTH backends so reads can serve from
        // fallback when primary is open. Failure on primary counts as a
        // breaker failure but doesn't propagate — the schema still exists
        // on the fallback.
        try {
            $this->primary->make($name, $maxRows, $columns, $opts);
            $this->recordSuccess();
        } catch (StoreException) {
            $this->recordFailure();
        }
        $this->fallback?->make($name, $maxRows, $columns, $opts);
    }

    public function set(string $name, string $key, array $row): bool
    {
        return (bool) $this->callWrite(fn(): bool => $this->primary->set($name, $key, $row));
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
        return (bool) $this->callWrite(fn(): bool => $this->primary->del($name, $key));
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
        $r = $this->callWrite(fn(): int|float => $this->primary->incr($name, $key, $col, $by));
        return $r;
    }

    public function decr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        /** @var int|float $r */
        $r = $this->callWrite(fn(): int|float => $this->primary->decr($name, $key, $col, $by));
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
        if ($this->state->get() === self::OPEN) {
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

    public function clear(string $name): void
    {
        $this->callWrite(function () use ($name): bool {
            $this->primary->clear($name);
            return true;
        });
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
        return (bool) $this->callWrite(fn(): bool => $this->primary->mset($name, $rows));
    }
}
