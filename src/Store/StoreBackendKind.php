<?php

declare(strict_types=1);

namespace ZealPHP\Store;

/**
 * Type-safe enum for the Store backend kind.
 *
 * `Store::defaultBackend()` accepts BOTH this enum AND the string literal
 * forms — `Store::defaultBackend(StoreBackendKind::Redis)` is equivalent
 * to `Store::defaultBackend('redis')`. The string `Store::BACKEND_REDIS`
 * constant remains for back-compat; it just happens to equal
 * `StoreBackendKind::Redis->value`.
 *
 * Prefer this in new code — IDE autocomplete + refactor-safe + the
 * value is verified at the type-system boundary instead of at runtime.
 */
enum StoreBackendKind: string
{
    case Table  = 'table';
    case Redis  = 'redis';
    case Tiered = 'tiered';

    /**
     * Normalise a mixed-typed input (enum, string, or null) into an
     * enum case. Throws InvalidArgumentException on an unknown string.
     */
    public static function coerce(self|string $kind): self
    {
        if ($kind instanceof self) { return $kind; }
        $enum = self::tryFrom(strtolower($kind));
        if ($enum === null) {
            throw new \InvalidArgumentException("Unknown Store backend kind: '$kind' (use 'table', 'redis', or 'tiered')");
        }
        return $enum;
    }
}
