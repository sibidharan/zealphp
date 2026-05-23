<?php

declare(strict_types=1);

namespace ZealPHP\Store;

/**
 * Thrown by every public method in the ZealPHP\Store namespace.
 *
 * Wraps phpredis \RedisException and \Predis\PredisException so user code
 * never imports a client-lib symbol; catch StoreException everywhere.
 *
 * NOT final — typed subclasses (e.g. `WSRouter\CapacityException`) extend
 * this so existing `catch (StoreException)` blocks still catch them while
 * new code can pattern-match on the specific subtype for differentiated
 * recovery (cap-exceeded → 1013 close; transient Redis failure → retry).
 */
class StoreException extends \RuntimeException
{
}
