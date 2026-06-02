<?php

declare(strict_types=1);

namespace ZealPHP\Store;

/**
 * Sentinel thrown from inside a `SUBSCRIBE` consumer to signal clean shutdown.
 *
 * The driver's `subscribe()` implementation MUST catch this exception,
 * unsubscribe, and return without rethrowing.
 *
 * Used by `RedisPubSub::stop()` and `RedisStreams::stop()` to wake the runner
 * coroutine out of a blocking read.
 */
final class PubSubStopException extends \RuntimeException
{
}
