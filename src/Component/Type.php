<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/**
 * A component property or action-parameter type, restricted to values that
 * can safely cross the wire: string, int, float, bool, array (of scalars,
 * nested), or a backed enum, each optionally nullable. Objects are never
 * built from client input.
 *
 * @internal
 */
final class Type
{
    private const ARRAY_DEPTH = 4;

    /**
     * @param 'string'|'int'|'float'|'bool'|'array'|'enum' $kind
     * @param class-string<\BackedEnum>|null $enum
     */
    private function __construct(
        public readonly string $kind,
        public readonly bool $nullable,
        public readonly ?string $enum = null,
    ) {
    }

    /** Build from reflection; throws when the declared type isn't allowed. */
    public static function fromReflection(?\ReflectionType $type, string $where): self
    {
        if (!$type instanceof \ReflectionNamedType) {
            throw new \LogicException(
                "{$where}: must declare a single type (string, int, float, bool, array, or a backed enum)"
            );
        }
        $name = $type->getName();
        $nullable = $type->allowsNull();
        if (in_array($name, ['string', 'int', 'float', 'bool', 'array'], true)) {
            return new self($name, $nullable);
        }
        if (enum_exists($name) && is_subclass_of($name, \BackedEnum::class)) {
            return new self('enum', $nullable, $name);
        }
        throw new \LogicException(
            "{$where}: type {$name} is not allowed (use string, int, float, bool, array, or a backed enum)"
        );
    }

    /** A stable string form, used in the class-shape version hash. */
    public function describe(): string
    {
        return ($this->nullable ? '?' : '') . ($this->enum ?? $this->kind);
    }

    /**
     * Convert a request value (strings and arrays from a form or query) to
     * this type.
     *
     * @throws \InvalidArgumentException with a user-facing message.
     */
    public function fromInput(mixed $value): mixed
    {
        if ($value === null || ($value === '' && $this->nullable && $this->kind !== 'string')) {
            if ($this->nullable) {
                return null;
            }
            if ($value === null) {
                throw new \InvalidArgumentException('is required');
            }
        }
        switch ($this->kind) {
            case 'string':
                if (!is_scalar($value)) {
                    throw new \InvalidArgumentException('must be text');
                }
                $value = (string) $value;
                if (!mb_check_encoding($value, 'UTF-8')) {
                    throw new \InvalidArgumentException('must be valid UTF-8 text');
                }
                return $value;
            case 'int':
                if (is_int($value)) {
                    return $value;
                }
                if (is_string($value) && preg_match('/^\s*-?\d{1,18}\s*$/', $value) === 1) {
                    return (int) trim($value);
                }
                throw new \InvalidArgumentException('must be a whole number');
            case 'float':
                $f = null;
                if (is_int($value) || is_float($value)) {
                    $f = (float) $value;
                } elseif (is_string($value) && is_numeric(trim($value))) {
                    $f = (float) trim($value);
                }
                // `1e999` is numeric but becomes INF, which JSON can't encode.
                if ($f === null || !is_finite($f)) {
                    throw new \InvalidArgumentException('must be a number');
                }
                return $f;
            case 'bool':
                if (is_bool($value)) {
                    return $value;
                }
                if (is_int($value)) {
                    return $value !== 0;
                }
                if (is_string($value)) {
                    $v = strtolower(trim($value));
                    if (in_array($v, ['1', 'true', 'on', 'yes'], true)) {
                        return true;
                    }
                    if (in_array($v, ['0', 'false', 'off', 'no', ''], true)) {
                        return false;
                    }
                }
                throw new \InvalidArgumentException('must be true or false');
            case 'array':
                if (!is_array($value) || !self::isScalarTree($value, self::ARRAY_DEPTH)) {
                    throw new \InvalidArgumentException('must be a list of values');
                }
                return $value;
            default: // enum
                $enum = $this->enum;
                assert($enum !== null);
                if ($value instanceof $enum) {
                    return $value;
                }
                if (!is_int($value) && !is_string($value)) {
                    throw new \InvalidArgumentException('is not a valid choice');
                }
                $case = self::enumFrom($enum, $value);
                if ($case === null) {
                    throw new \InvalidArgumentException('is not a valid choice');
                }
                return $case;
        }
    }

    /**
     * Encode a property value for the snapshot (JSON-safe).
     *
     * @throws \LogicException when the value doesn't fit the type.
     */
    public function toSnapshot(mixed $value, string $where): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_array($value)) {
            if (!self::isScalarTree($value, self::ARRAY_DEPTH)) {
                throw new \LogicException("{$where}: arrays in component state may only hold scalars (valid UTF-8, finite) and nested arrays");
            }
            return $value;
        }
        if (is_float($value) && !is_finite($value)) {
            throw new \LogicException("{$where}: INF/NAN cannot be stored in component state");
        }
        if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            throw new \LogicException("{$where}: strings in component state must be valid UTF-8");
        }
        if (is_scalar($value)) {
            return $value;
        }
        throw new \LogicException("{$where}: " . get_debug_type($value) . ' cannot be stored in component state');
    }

    /** Decode a snapshot value back to this type. */
    public function fromSnapshot(mixed $value): mixed
    {
        if ($value === null) {
            if (!$this->nullable) {
                throw new \InvalidArgumentException('null for a non-nullable property');
            }
            return null;
        }
        if ($this->kind === 'float' && is_int($value)) {
            return (float) $value; // JSON writes 1.0 as 1
        }
        return $this->fromInput($value);
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    private static function enumFrom(string $enum, int|string $value): ?\BackedEnum
    {
        $backing = (new \ReflectionEnum($enum))->getBackingType();
        if ($backing instanceof \ReflectionNamedType && $backing->getName() === 'int') {
            if (is_string($value)) {
                if (preg_match('/^-?\d{1,18}$/D', $value) !== 1) {
                    return null;
                }
                $value = (int) $value;
            }
            return $enum::tryFrom($value);
        }
        return $enum::tryFrom((string) $value);
    }

    /**
     * @param array<mixed> $value
     */
    private static function isScalarTree(array $value, int $depth): bool
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && !mb_check_encoding($key, 'UTF-8')) {
                return false;
            }
            if (is_array($item)) {
                if ($depth <= 1 || !self::isScalarTree($item, $depth - 1)) {
                    return false;
                }
            } elseif ($item !== null && !is_scalar($item)) {
                return false;
            } elseif (is_string($item) && !mb_check_encoding($item, 'UTF-8')) {
                return false;
            } elseif (is_float($item) && !is_finite($item)) {
                return false;
            }
        }
        return true;
    }
}
