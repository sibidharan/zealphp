<?php

declare(strict_types=1);

namespace ZealPHP\Store;

/**
 * Thrown by every public method in the ZealPHP\Store namespace.
 *
 * Wraps phpredis \RedisException and \Predis\PredisException so user code
 * never imports a client-lib symbol; catch StoreException everywhere.
 */
final class StoreException extends \RuntimeException
{
}
