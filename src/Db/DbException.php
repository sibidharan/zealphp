<?php

declare(strict_types=1);

namespace ZealPHP\Db;

/**
 * Thrown by the DB layer — pool acquire timeouts, exhausted retries, and
 * misconfiguration. Mirrors `ZealPHP\Store\StoreException` for the Store
 * primitives so callers can catch a ZealPHP-namespaced type rather than a
 * raw `\PDOException` for pool-level (not query-level) failures.
 */
final class DbException extends \RuntimeException
{
}
