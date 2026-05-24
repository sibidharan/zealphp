<?php

declare(strict_types=1);

namespace ZealPHP\Counter;

/**
 * Type-safe enum for the Counter backend kind. Symmetric with
 * `ZealPHP\Store\StoreBackendKind` — accepted directly by
 * `Counter::defaultBackend()`.
 */
enum CounterBackendKind: string
{
    case Atomic    = 'atomic';
    case Redis     = 'redis';
    case Memcached = 'memcached';

    public static function coerce(self|string $kind): self
    {
        if ($kind instanceof self) { return $kind; }
        $enum = self::tryFrom(strtolower($kind));
        if ($enum === null) {
            throw new \InvalidArgumentException(
                "Unknown Counter backend kind: '$kind' (use 'atomic', 'redis', or 'memcached')"
            );
        }
        return $enum;
    }
}
