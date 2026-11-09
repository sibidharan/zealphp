<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/**
 * A worker's push registry. Kept in an object rather than in static arrays:
 * coroutine-legacy isolates class-static arrays per coroutine, which would
 * hide one request's connections from another's invalidate().
 *
 * @internal
 */
final class PushState
{
    /** @var array<string, array<string, true>> topic → connection ids */
    public array $byTopic = [];

    /** @var array<string, array{send: \Closure(string): bool, topics: array<string, list<string>>, pending: array<string, true>, scheduled: bool}> */
    public array $connections = [];

    /** @var array<string, true> topics waiting to be sent to other workers and nodes */
    public array $outbox = [];

    public bool $outboxScheduled = false;
}
