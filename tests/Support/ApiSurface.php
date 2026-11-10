<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Support;

/**
 * The public API of a set of classes, one line per member, for the API lock
 * test: public and protected members (protected ones are what a subclass
 * overrides or calls), minus anything marked `@internal`. Deprecated members
 * are listed with `@deprecated`, so their removal shows in the diff too.
 */
final class ApiSurface
{
    /** @param list<class-string> $classes */
    public static function describe(array $classes): string
    {
        $out = [];
        foreach ($classes as $class) {
            $out[] = self::describeClass(new \ReflectionClass($class));
        }
        return implode("\n", $out);
    }

    /** @param \ReflectionClass<object> $rc */
    private static function describeClass(\ReflectionClass $rc, ?\Closure $methodFilter = null): string
    {
        $kind = match (true) {
            $rc->isEnum() => 'enum',
            $rc->isInterface() => 'interface',
            $rc->isTrait() => 'trait',
            default => trim(($rc->isAbstract() ? 'abstract ' : '') . ($rc->isFinal() ? 'final ' : '') . 'class'),
        };
        $head = "{$kind} {$rc->getName()}";
        $parent = $rc->getParentClass();
        if ($parent !== false) {
            $head .= ' extends ' . $parent->getName();
        }
        $own = array_values(array_filter(
            $rc->getInterfaceNames(),
            static fn(string $i): bool => $parent === false || !in_array($i, $parent->getInterfaceNames(), true)
        ));
        sort($own);
        if ($own !== [] && !$rc->isEnum()) {
            $head .= ($rc->isInterface() ? ' extends ' : ' implements ') . implode(', ', $own);
        }
        foreach ($rc->getAttributes(\Attribute::class) as $attr) {
            // Through the instance, so a named `flags:` argument counts too.
            $head = '#[Attribute(' . self::attributeFlags($attr->newInstance()->flags) . ')] ' . $head;
        }
        $lines = [$head];

        if ($rc->isEnum()) {
            foreach ((new \ReflectionEnum($rc->getName()))->getCases() as $case) {
                $value = $case instanceof \ReflectionEnumBackedCase ? ' = ' . var_export($case->getBackingValue(), true) : '';
                $lines[] = "  case {$case->getName()}{$value}";
            }
        }
        foreach ($rc->getReflectionConstants() as $const) {
            if ($const->getDeclaringClass()->getName() !== $rc->getName() || !$const->isPublic()
                || $const->isEnumCase() || self::internal($const->getDocComment())) {
                continue;
            }
            $lines[] = '  const ' . $const->getName() . ' = ' . self::export($const->getValue());
        }
        foreach ($rc->getProperties() as $prop) {
            if ($prop->getDeclaringClass()->getName() !== $rc->getName() || $prop->isPrivate()
                || self::internal($prop->getDocComment()) || ($rc->isEnum() && in_array($prop->getName(), ['name', 'value'], true))) {
                continue;
            }
            $line = '  ' . self::visibility($prop) . ($prop->isStatic() ? ' static' : '') . ($prop->isReadOnly() ? ' readonly' : '')
                . ($prop->hasType() ? ' ' . self::type($prop->getType(), $rc->getName()) : '') . ' $' . $prop->getName();
            if ($prop->hasDefaultValue() && !$prop->isPromoted()) {
                $line .= ' = ' . self::export($prop->getDefaultValue());
            }
            $lines[] = $line . self::deprecated($prop->getDocComment());
        }
        $methods = [];
        foreach ($rc->getMethods() as $m) {
            // An enum's cases()/from()/tryFrom() come from PHP, and their
            // reflected signatures differ between PHP versions.
            if ($m->getDeclaringClass()->getName() !== $rc->getName() || $m->isPrivate() || $m->isInternal()
                || self::internal($m->getDocComment()) || ($methodFilter !== null && !$methodFilter($m))) {
                continue;
            }
            $methods[$m->getName()] = '  ' . self::signature($m) . self::deprecated($m->getDocComment());
        }
        ksort($methods);
        return implode("\n", array_merge($lines, array_values($methods))) . "\n";
    }

    /**
     * Some methods of a class (a facade's slice of a bigger class).
     *
     * @param class-string $class
     * @param \Closure(\ReflectionMethod): bool $filter
     */
    public static function describeMethods(string $class, \Closure $filter): string
    {
        $text = self::describeClass(new \ReflectionClass($class), $filter);
        // Only the methods: the rest of the class isn't this surface.
        $keep = array_filter(explode("\n", $text), static fn(string $l): bool => str_starts_with($l, '  ') && str_contains($l, 'function '));
        return "methods of {$class}\n" . implode("\n", $keep) . "\n";
    }

    /**
     * Public methods named zc* (framework plumbing) without `@internal`:
     * they'd escape the lock by name alone.
     *
     * @param list<class-string> $classes
     * @return list<string>
     */
    public static function unmarkedPlumbing(array $classes): array
    {
        $bad = [];
        foreach ($classes as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if (str_starts_with($m->getName(), 'zc') && !self::internal($m->getDocComment())) {
                    $bad[] = $class . '::' . $m->getName();
                }
            }
        }
        return $bad;
    }

    /**
     * Attribute flags by name: their values move between PHP versions
     * (8.5 added TARGET_CONSTANT, which shifted IS_REPEATABLE).
     */
    private static function attributeFlags(int $flags): string
    {
        $targetAll = \Attribute::TARGET_ALL;
        $names = [];
        if (($flags & $targetAll) === $targetAll) {
            $names[] = 'TARGET_ALL';
        }
        foreach ((new \ReflectionClass(\Attribute::class))->getConstants() as $name => $value) {
            if ($name === 'TARGET_ALL' || !is_int($value) || ($flags & $value) !== $value) {
                continue;
            }
            if (str_starts_with($name, 'TARGET_') && ($flags & $targetAll) === $targetAll) {
                continue; // covered by TARGET_ALL
            }
            $names[] = $name;
        }
        return implode('|', $names);
    }

    private static function signature(\ReflectionMethod $m): string
    {
        $mods = trim(($m->isAbstract() && !$m->getDeclaringClass()->isInterface() ? 'abstract ' : '')
            . ($m->isFinal() ? 'final ' : '') . self::visibility($m) . ($m->isStatic() ? ' static' : ''));
        $params = [];
        foreach ($m->getParameters() as $p) {
            $s = ($p->hasType() ? self::type($p->getType(), $m->getDeclaringClass()->getName()) . ' ' : '') . ($p->isPassedByReference() ? '&' : '')
                . ($p->isVariadic() ? '...' : '') . '$' . $p->getName();
            if ($p->isDefaultValueAvailable()) {
                $s .= ' = ' . ($p->isDefaultValueConstant() ? (string) $p->getDefaultValueConstantName() : self::export($p->getDefaultValue()));
            }
            if ($p->isPromoted()) {
                $s = 'promoted ' . $s;
            }
            $params[] = $s;
        }
        $return = $m->hasReturnType() ? ': ' . self::type($m->getReturnType(), $m->getDeclaringClass()->getName()) : '';
        return "{$mods} function {$m->getName()}(" . implode(', ', $params) . "){$return}";
    }

    /**
     * A type as written, with the class's own name shown as `self`: PHP 8.5's
     * reflection resolves `self` to the class name, 8.3 and 8.4 don't, and
     * the two mean the same.
     */
    private static function type(?\ReflectionType $type, string $class): string
    {
        return (string) preg_replace('/(?<![\\\w])' . preg_quote($class, '/') . '(?![\\\w])/', 'self', (string) $type);
    }

    private static function visibility(\ReflectionMethod|\ReflectionProperty $r): string
    {
        return $r->isPublic() ? 'public' : 'protected';
    }

    private static function internal(string|false $doc): bool
    {
        return $doc !== false && preg_match('/@internal\b/', $doc) === 1;
    }

    private static function deprecated(string|false $doc): string
    {
        return $doc !== false && preg_match('/@deprecated\b/', $doc) === 1 ? '  @deprecated' : '';
    }

    private static function export(mixed $value): string
    {
        if (is_array($value)) {
            return $value === [] ? '[]' : (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        return $value === null ? 'null' : str_replace("\n", '', var_export($value, true));
    }
}
