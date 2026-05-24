<?php

declare(strict_types=1);

namespace ZealPHP\Store;

/**
 * Type-safe enum for `RedisClient`'s driver selection. Accepted by
 * `Store::defaultBackend()` via the `prefer` opt and by
 * `RedisClient::__construct`'s opts.
 *
 *   Store::defaultBackend(StoreBackendKind::Redis, [
 *       'prefer' => DriverPreference::Predis,        // ← typed
 *   ]);
 *   // or the BC string form:
 *   Store::defaultBackend('redis', ['prefer' => 'predis']);
 */
enum DriverPreference: string
{
    case Auto     = 'auto';
    case Phpredis = 'phpredis';
    case Predis   = 'predis';

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
