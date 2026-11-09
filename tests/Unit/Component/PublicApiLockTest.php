<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\Tests\Support\ApiSurface;

/**
 * The components API is locked: any change to a public or protected
 * signature, constant, property or attribute in ZealPHP\Component (classes
 * not marked @internal) or to App::component*() fails here until the lock
 * file is updated on purpose. See docs/components.md#api-stability.
 *
 * Update after a deliberate, policy-following change:
 *   make api-lock   (ZEALPHP_UPDATE_API_LOCK=1 ./vendor/bin/phpunit --filter PublicApiLockTest)
 */
class PublicApiLockTest extends TestCase
{
    private const LOCK = ZEALPHP_ROOT . '/tests/fixtures/api/components.txt';

    /** @return list<class-string> every class in src/Component without a class-level @internal */
    private static function publicClasses(): array
    {
        $classes = [];
        foreach (glob(ZEALPHP_ROOT . '/src/Component/*.php') ?: [] as $file) {
            $class = 'ZealPHP\\Component\\' . basename($file, '.php');
            if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
                continue;
            }
            $doc = (new \ReflectionClass($class))->getDocComment();
            if ($doc === false || preg_match('/@internal\b/', $doc) !== 1) {
                $classes[] = $class;
            }
        }
        sort($classes);
        return $classes;
    }

    private static function surface(): string
    {
        return "# The components public API. Generated; see tests/Unit/Component/PublicApiLockTest.php.\n\n"
            . ApiSurface::describe(self::publicClasses()) . "\n"
            . ApiSurface::describeMethods(App::class, static fn(\ReflectionMethod $m): bool => str_starts_with($m->getName(), 'component'));
    }

    public function testThePublicApiMatchesTheLock(): void
    {
        $now = self::surface();
        if (getenv('ZEALPHP_UPDATE_API_LOCK') === '1') {
            file_put_contents(self::LOCK, $now);
        }
        $this->assertFileExists(self::LOCK);
        $this->assertSame(
            (string) file_get_contents(self::LOCK),
            $now,
            "The components public API changed. If that's deliberate, follow docs/components.md#api-stability "
            . '(deprecate before removing; no breaking change within a major), then run `make api-lock` and commit the diff.'
        );
    }

    public function testFrameworkPlumbingIsMarkedInternal(): void
    {
        $this->assertSame([], ApiSurface::unmarkedPlumbing(self::publicClasses()), 'public zc* methods must be @internal');
    }

    public function testTheLockListsWhatUsersWrite(): void
    {
        $lock = (string) file_get_contents(self::LOCK);
        foreach ([
            'abstract class ZealPHP\Component\Component',
            'abstract public function render(): mixed',
            'public function model(string $prop, bool $value = true): ZealPHP\SafeHtml',
            'protected function validate(?array $only = null): void',
            'public static function component(string $component, array $props = [], ?string $key = null): ZealPHP\SafeHtml',
            'final class ZealPHP\Component\Action',
            'final class ZealPHP\Component\Tester',
        ] as $line) {
            $this->assertStringContainsString($line, $lock);
        }
        $this->assertStringNotContainsString('zcMount', $lock, 'plumbing stays out');
        $this->assertStringNotContainsString('ZealPHP\Component\Push', $lock, 'internal classes stay out');
    }
}
