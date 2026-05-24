<?php

declare(strict_types=1);

namespace ZealPHP\Store;

/**
 * Per-worker counter struct for Store/Counter/Pool/Subscriber observability.
 *
 * Stats are PER-WORKER — each OpenSwoole worker has its own pool and
 * its own subscriber runners, so the counts here are local to one
 * process. For cluster-wide aggregation, poll each worker's snapshot
 * (e.g. expose `/health/stats` and have Prometheus scrape every server).
 *
 * Why no Atomic: stats are observability, not correctness. A lost
 * increment under high contention is acceptable; the alternative is
 * spending shared-memory budget on Atomic slots that we'd otherwise
 * use for app state. Per-worker plain ints are honest about what they
 * provide.
 */
final class Stats
{
    /** @var array<string, int> */
    private array $counters = [];

    public function inc(string $key, int $by = 1): void
    {
        $this->counters[$key] = ($this->counters[$key] ?? 0) + $by;
    }

    public function get(string $key): int
    {
        return $this->counters[$key] ?? 0;
    }

    /** @return array<string, int> */
    public function snapshot(): array
    {
        return $this->counters;
    }

    public function reset(): void
    {
        $this->counters = [];
    }
}
