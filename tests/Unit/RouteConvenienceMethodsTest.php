<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * Per-verb route convenience helpers — App::get()/post()/put()/patch()/
 * delete()/options()/any() and the same set on RouteGroup. Each delegates to
 * route() with the matching HTTP method; any() registers the full verb set.
 */
final class RouteConvenienceMethodsTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        self::$app = App::init('0.0.0.0', 19987, ZEALPHP_ROOT);
    }

    /** @return list<string> methods registered for the given path (first match). */
    private function methodsFor(string $path): array
    {
        foreach (self::$app->describeRoutes()['routes'] as $route) {
            if ($route['path'] === $path) {
                return $route['methods'];
            }
        }
        return [];
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function verbProvider(): array
    {
        return [
            'get'     => ['get',     ['GET']],
            'post'    => ['post',    ['POST']],
            'put'     => ['put',     ['PUT']],
            'patch'   => ['patch',   ['PATCH']],
            'delete'  => ['delete',  ['DELETE']],
            'options' => ['options', ['OPTIONS']],
        ];
    }

    /**
     * @param list<string> $expected
     * @dataProvider verbProvider
     */
    public function testAppVerbHelperRegistersSingleMethod(string $verb, array $expected): void
    {
        $path = "/conv-app-$verb-" . uniqid();
        self::$app->{$verb}($path, fn() => 'ok');
        self::assertSame($expected, $this->methodsFor($path), "App::$verb() must register $verb[0]");
    }

    public function testAppAnyRegistersAllMethods(): void
    {
        $path = '/conv-app-any-' . uniqid();
        self::$app->any($path, fn() => 'ok');
        self::assertSame(
            ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            $this->methodsFor($path)
        );
    }

    /**
     * @param list<string> $expected
     * @dataProvider verbProvider
     */
    public function testGroupVerbHelperRegistersSingleMethodWithPrefix(string $verb, array $expected): void
    {
        $token = uniqid();
        self::$app->group("/conv-grp-$verb-$token", function ($g) use ($verb) {
            $g->{$verb}('/leaf', fn() => 'ok');
        });
        self::assertSame(
            $expected,
            $this->methodsFor("/conv-grp-$verb-$token/leaf"),
            "RouteGroup::$verb() must register $verb[0] under the group prefix"
        );
    }

    public function testGroupAnyRegistersAllMethodsWithPrefix(): void
    {
        $token = uniqid();
        self::$app->group("/conv-grp-any-$token", function ($g) {
            $g->any('/leaf', fn() => 'ok');
        });
        self::assertSame(
            ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            $this->methodsFor("/conv-grp-any-$token/leaf")
        );
    }

    public function testAppVerbHelperAcceptsArrayHandler(): void
    {
        // The widened $handler type accepts the [object, 'method'] callable-array
        // form, not just closures.
        $path = '/conv-app-arrayhandler-' . uniqid();
        $controller = new class {
            public function show(): string { return 'shown'; }
        };
        self::$app->get($path, [$controller, 'show']);
        self::assertSame(['GET'], $this->methodsFor($path));
    }
}
