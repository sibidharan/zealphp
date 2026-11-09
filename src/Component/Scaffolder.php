<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/**
 * `php app.php make:component Name [--stateless]`: the files for a new
 * component. Pure — {@see self::plan()} returns paths and contents, and the
 * CLI writes them (refusing to overwrite).
 *
 * @internal
 */
final class Scaffolder
{
    /**
     * @return array{files: array<string, string>, next: list<string>}
     *         files: path relative to $root → content; next: what to do after
     * @throws \InvalidArgumentException for a name that isn't a class name (or kebab-case, for --stateless)
     */
    public static function plan(string $root, string $name, bool $stateless = false): array
    {
        if (preg_match('/^(?:[A-Z][A-Za-z0-9]*|[a-z][a-z0-9-]*)$/D', $name) !== 1) {
            throw new \InvalidArgumentException("Component name '{$name}': use a class name (Counter) or kebab-case (user-card)");
        }
        $kebab = self::kebab($name);
        $template = "template/components/{$kebab}.php";
        if ($stateless) {
            return [
                'files' => [$template => self::statelessTemplate($kebab)],
                'next' => ["Render it: <?= App::component('{$kebab}', ['title' => 'Hello']) ?>"],
            ];
        }
        $class = self::studly($name);
        $namespace = self::namespaceFor($root) . 'Components';
        return [
            'files' => [
                "src/Components/{$class}.php" => self::classFile($namespace, $class, $kebab),
                $template => self::reactiveTemplate(),
            ],
            'next' => [
                "Register it at boot (e.g. route/components.php): App::components([\\{$namespace}\\{$class}::class]);",
                "Render it: <?= App::component(\\{$namespace}\\{$class}::class) ?>",
                'Reactive components need ZEALPHP_APP_KEY (php app.php key:generate), or --dev.',
            ],
        ];
    }

    /** `UserCard` / `user-card` → `user-card`. */
    public static function kebab(string $name): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '-$1', $name));
    }

    /** `user-card` / `UserCard` → `UserCard`. */
    public static function studly(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $name)));
    }

    /**
     * The app's namespace for `src/`, from composer.json's PSR-4 map (first
     * match), with its trailing backslash; `App\` when there is none.
     */
    public static function namespaceFor(string $root): string
    {
        $json = is_file($root . '/composer.json') ? file_get_contents($root . '/composer.json') : false;
        $composer = is_string($json) ? json_decode($json, true) : null;
        $psr4 = is_array($composer) && is_array($composer['autoload'] ?? null) && is_array($composer['autoload']['psr-4'] ?? null)
            ? $composer['autoload']['psr-4']
            : [];
        foreach ($psr4 as $prefix => $dirs) {
            foreach ((array) $dirs as $dir) {
                if (is_string($prefix) && is_string($dir) && rtrim($dir, '/') === 'src') {
                    return $prefix;
                }
            }
        }
        return 'App\\';
    }

    private static function classFile(string $namespace, string $class, string $kebab): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use ZealPHP\\Component\\Action;
use ZealPHP\\Component\\Component;

/**
 * A reactive component: public properties are its state (kept in the page,
 * signed), #[Action] methods are what its buttons call.
 */
final class {$class} extends Component
{
    public int \$count = 0;

    #[Action]
    public function increment(int \$by = 1): void
    {
        \$this->count += \$by;
    }

    public function render(): mixed
    {
        return \$this->view('components/{$kebab}');
    }
}

PHP;
    }

    private static function reactiveTemplate(): string
    {
        return <<<'PHP'
<?php use function ZealPHP\e; ?>
<span><?= e($count) ?></span>
<button <?= $c->action('increment') ?>>+1</button>

PHP;
    }

    private static function statelessTemplate(string $kebab): string
    {
        return <<<PHP
<?php use function ZealPHP\\e; ?>
<div class="{$kebab}">
  <?= e(\$title ?? '') ?>
</div>

PHP;
    }
}
