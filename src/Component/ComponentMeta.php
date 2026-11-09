<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/**
 * Reflected, read-only description of one component class: its state
 * properties, which of them accept input (`#[Model]`), its actions and their
 * parameters, and a version hash of that shape. Built once at boot.
 *
 * @internal
 */
final class ComponentMeta
{
    /** Template variables every component view receives. */
    private const RESERVED = ['c', 'errors', 'slot'];

    /**
     * @param class-string<Component>                  $class
     * @param array<string, Type>                      $props   every state property
     * @param array<string, Model>                     $models  the input-bound subset
     * @param array<string, list<array{name: string, type: Type, optional: bool, default: mixed}>> $actions
     * @param list<string>                             $reactive state properties a parent's props keep updating
     * @param array<string, list<string>>              $listeners event name → #[On] methods
     */
    private function __construct(
        public readonly string $name,
        public readonly string $class,
        public readonly array $props,
        public readonly array $models,
        public readonly array $actions,
        public readonly string $version,
        public readonly array $reactive = [],
        public readonly array $listeners = [],
    ) {
    }

    /**
     * @param class-string<Component> $class
     */
    public static function reflect(string $name, string $class): self
    {
        $rc = new \ReflectionClass($class);
        if (!$rc->isSubclassOf(Component::class)) {
            throw new \LogicException("{$class} must extend " . Component::class);
        }
        if ($rc->isAbstract()) {
            throw new \LogicException("{$class} is abstract");
        }
        $ctor = $rc->getConstructor();
        if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
            throw new \LogicException("{$class}: the constructor must not require arguments (use mount() instead)");
        }

        $props = [];
        $models = [];
        $reactive = [];
        foreach ($rc->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            if ($p->isStatic() || $p->getDeclaringClass()->getName() === Component::class) {
                continue;
            }
            $where = "{$class}::\${$p->getName()}";
            if (in_array($p->getName(), self::RESERVED, true) || str_starts_with($p->getName(), '_')) {
                throw new \LogicException("{$where}: the name is reserved (c, errors, slot, and names starting with _)");
            }
            if ($p->isReadOnly()) {
                throw new \LogicException("{$where}: component state can't be readonly");
            }
            $props[$p->getName()] = Type::fromReflection($p->getType(), $where);
            $attrs = $p->getAttributes(Model::class);
            if ($attrs !== []) {
                $models[$p->getName()] = $attrs[0]->newInstance();
            }
            if ($p->getAttributes(Reactive::class) !== []) {
                $reactive[] = $p->getName();
            }
        }

        foreach ($rc->getMethods(\ReflectionMethod::IS_PRIVATE) as $m) {
            if (str_starts_with($m->getName(), 'updated')) {
                throw new \LogicException("{$class}::{$m->getName()}(): updated hooks must be protected or public");
            }
        }

        $actions = [];
        foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getAttributes(Action::class) === []) {
                continue;
            }
            $where = "{$class}::{$m->getName()}()";
            if ($m->isStatic() || str_starts_with($m->getName(), '_')
                || $m->getDeclaringClass()->getName() === Component::class) {
                throw new \LogicException("{$where}: #[Action] must be on a public, non-static method of the component whose name doesn't start with _");
            }
            $params = [];
            foreach ($m->getParameters() as $param) {
                if ($param->isVariadic() || $param->isPassedByReference()) {
                    throw new \LogicException("{$where}: action parameters can't be variadic or by-reference");
                }
                $optional = $param->isDefaultValueAvailable();
                $params[] = [
                    'name' => $param->getName(),
                    'type' => Type::fromReflection($param->getType(), "{$where} \${$param->getName()}"),
                    'optional' => $optional,
                    'default' => $optional ? $param->getDefaultValue() : null,
                ];
            }
            $actions[$m->getName()] = $params;
        }

        $listeners = [];
        foreach ($rc->getMethods() as $m) {
            $on = $m->getAttributes(On::class);
            if ($on === []) {
                continue;
            }
            $where = "{$class}::{$m->getName()}()";
            if (!$m->isPublic() || $m->isStatic() || str_starts_with($m->getName(), '_')
                || $m->getDeclaringClass()->getName() === Component::class) {
                throw new \LogicException("{$where}: #[On] must be on a public, non-static method of the component whose name doesn't start with _");
            }
            if ($m->getNumberOfRequiredParameters() > 0) {
                throw new \LogicException("{$where}: an #[On] method takes no arguments (events carry no data)");
            }
            foreach ($on as $attr) {
                foreach ($attr->newInstance()->events as $event) {
                    $listeners[$event][] = $m->getName();
                }
            }
        }

        $shape = [$class];
        foreach ($props as $prop => $type) {
            $shape[] = "p:{$prop}:" . $type->describe() . (isset($models[$prop]) ? ':m' : '');
        }
        foreach ($actions as $action => $params) {
            $shape[] = "a:{$action}(" . implode(',', array_map(
                static fn(array $p): string => $p['name'] . ':' . $p['type']->describe(),
                $params
            )) . ')';
        }
        $version = substr(hash('sha256', implode("\n", $shape)), 0, 16);

        return new self($name, $class, $props, $models, $actions, $version, $reactive, $listeners);
    }
}
