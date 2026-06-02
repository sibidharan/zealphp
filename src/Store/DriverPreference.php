<?php

declare(strict_types=1);

namespace ZealPHP\Store;

/**
 * Type-safe enum for `RedisClient`'s driver selection. Accepted by
 * `Store::defaultBackend()` via the `'prefer'` opt and by
 * `RedisClient::__construct()`'s opts array.
 *
 * ```php
 * Store::defaultBackend(StoreBackendKind::Redis, [
 *     'prefer' => DriverPreference::Predis,   // typed form
 * ]);
 * // or the BC string form:
 * Store::defaultBackend('redis', ['prefer' => 'predis']);
 * ```
 */
enum DriverPreference: string
{
    /** Auto-detect: prefer `ext-redis` (phpredis) when loaded, fall back to predis. */
    case Auto     = 'auto';
    /** Force the `ext-redis` (phpredis) extension. Throws if not loaded. */
    case Phpredis = 'phpredis';
    /** Force the pure-PHP predis library. */
    case Predis   = 'predis';

    /**
     * Coerce a string or existing `DriverPreference` case to a `DriverPreference`.
     *
     * Accepts `'auto'`, `'phpredis'`, or `'predis'` (case-insensitive).
     *
     * @throws \InvalidArgumentException on an unrecognised string.
     */
    public static function coerce(self|string $pref): self
    {
        if ($pref instanceof self) { return $pref; }
        $enum = self::tryFrom(strtolower($pref));
        if ($enum === null) {
            throw new \InvalidArgumentException("Unknown driver preference: '$pref' (use 'auto', 'phpredis', or 'predis')");
        }
        return $enum;
    }
}
