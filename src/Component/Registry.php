<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/**
 * The boot-time map of component names to classes. Requests can only reach
 * components registered here; a class name from the request is never used.
 *
 * Populate it before `App::run()` (via `App::components()`); it's read-only
 * afterwards, so workers share it copy-on-write like the route table.
 *
 * @internal
 */
final class Registry
{
    /** @var array<string, ComponentMeta> name → meta */
    private static array $byName = [];
    /** @var array<class-string<Component>, string> class → name */
    private static array $byClass = [];

    /**
     * @param class-string<Component> $class
     */
    public static function register(string $class, ?string $name = null): ComponentMeta
    {
        $name ??= self::defaultName($class);
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $name) !== 1) {
            throw new \InvalidArgumentException(
                "Component name '{$name}' must be lowercase letters, digits and dashes, starting with a letter"
            );
        }
        if (isset(self::$byName[$name]) && self::$byName[$name]->class !== $class) {
            throw new \LogicException("Component name '{$name}' is already used by " . self::$byName[$name]->class);
        }
        if (isset(self::$byClass[$class]) && self::$byClass[$class] !== $name) {
            throw new \LogicException("{$class} is already registered as '" . self::$byClass[$class] . "'");
        }
        $meta = ComponentMeta::reflect($name, $class);
        self::$byName[$name] = $meta;
        self::$byClass[$class] = $name;
        return $meta;
    }

    public static function byName(string $name): ?ComponentMeta
    {
        return self::$byName[$name] ?? null;
    }

    /**
     * @param class-string<Component> $class
     */
    public static function byClass(string $class): ?ComponentMeta
    {
        $name = self::$byClass[$class] ?? null;
        return $name === null ? null : self::$byName[$name];
    }

    public static function isEmpty(): bool
    {
        return self::$byName === [];
    }

    /** @return array<string, class-string<Component>> name → class */
    public static function all(): array
    {
        return array_map(static fn(ComponentMeta $m): string => $m->class, self::$byName);
    }

    /** Forget every registration (tests). */
    public static function reset(): void
    {
        self::$byName = [];
        self::$byClass = [];
    }

    /** `App\Components\TodoList` → `todo-list`. */
    public static function defaultName(string $class): string
    {
        $short = substr($class, (int) strrpos('\\' . $class, '\\'));
        $kebab = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $short));
        return $kebab;
    }
}
