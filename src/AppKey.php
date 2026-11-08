<?php

declare(strict_types=1);

namespace ZealPHP;

/**
 * The application secret, `ZEALPHP_APP_KEY`.
 *
 * Format: `base64:` followed by the base64 of at least 32 random bytes
 * (`php app.php key:generate` prints one). A raw string of 32+ bytes is also
 * accepted. Old keys listed in `ZEALPHP_APP_KEY_PREVIOUS` (comma-separated)
 * are accepted for verifying, never for signing, so a rotation doesn't
 * invalidate pages that are already open.
 *
 * Callers never use the raw key: {@see derive()} gives each purpose its own
 * key via HKDF, so a value signed for one purpose can't be replayed as another.
 */
final class AppKey
{
    public const MIN_BYTES = 32;

    private static ?string $key = null;
    /** @var list<string>|null */
    private static ?array $previous = null;
    private static bool $ephemeral = false;

    /** The current key, or null when none is configured. */
    public static function current(): ?string
    {
        if (self::$key === null) {
            $env = getenv('ZEALPHP_APP_KEY');
            if (is_string($env) && $env !== '') {
                self::$key = self::decode($env);
            }
        }
        return self::$key;
    }

    /**
     * Old keys accepted for verification.
     *
     * @return list<string>
     */
    public static function previous(): array
    {
        if (self::$previous === null) {
            $list = [];
            $env = getenv('ZEALPHP_APP_KEY_PREVIOUS');
            if (is_string($env) && $env !== '') {
                foreach (explode(',', $env) as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $list[] = self::decode($part);
                    }
                }
            }
            self::$previous = $list;
        }
        return self::$previous;
    }

    /** Set the key in code (tests, or apps that load secrets themselves). */
    public static function set(string $key, string ...$previous): void
    {
        self::$key = self::decode($key);
        self::$previous = array_map(self::decode(...), array_values($previous));
        self::$ephemeral = false;
    }

    /**
     * Use a random key for this process. Call before workers fork so every
     * worker shares it. Values signed with it stop verifying after a restart.
     */
    public static function useEphemeral(): void
    {
        self::$key = random_bytes(self::MIN_BYTES);
        self::$previous = [];
        self::$ephemeral = true;
    }

    public static function isEphemeral(): bool
    {
        return self::$ephemeral;
    }

    /** Forget any cached or configured key (tests). */
    public static function reset(): void
    {
        self::$key = null;
        self::$previous = null;
        self::$ephemeral = false;
    }

    /** A new key in the `base64:` format, for `ZEALPHP_APP_KEY`. */
    public static function generate(): string
    {
        return 'base64:' . base64_encode(random_bytes(self::MIN_BYTES));
    }

    /**
     * The purpose-specific signing key derived from the current key.
     *
     * @throws \RuntimeException when no key is configured.
     */
    public static function derive(string $purpose): string
    {
        $key = self::current();
        if ($key === null) {
            throw new \RuntimeException(
                'ZEALPHP_APP_KEY is not set. Generate one with `php app.php key:generate`.'
            );
        }
        return hash_hkdf('sha256', $key, 32, 'zealphp:' . $purpose);
    }

    /**
     * Purpose-specific keys from the current key and every previous key,
     * current first — for verification.
     *
     * @return list<string>
     */
    public static function deriveAll(string $purpose): array
    {
        $keys = [self::derive($purpose)];
        foreach (self::previous() as $old) {
            $keys[] = hash_hkdf('sha256', $old, 32, 'zealphp:' . $purpose);
        }
        return $keys;
    }

    private static function decode(string $value): string
    {
        if (str_starts_with($value, 'base64:')) {
            $raw = base64_decode(substr($value, 7), true);
            if ($raw === false) {
                throw new \InvalidArgumentException('App key: invalid base64 after "base64:"');
            }
            $value = $raw;
        }
        if (strlen($value) < self::MIN_BYTES) {
            throw new \InvalidArgumentException(
                'App key must be at least ' . self::MIN_BYTES . ' bytes. Generate one with `php app.php key:generate`.'
            );
        }
        // Catch placeholders like "aaaa…" or "changeme…": random keys use far
        // more distinct bytes than this.
        if (count(array_unique(str_split($value))) < 10) {
            throw new \InvalidArgumentException(
                'App key looks like a placeholder (too few distinct characters). Generate one with `php app.php key:generate`.'
            );
        }
        return $value;
    }
}
