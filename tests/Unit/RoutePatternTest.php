<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\App;

/**
 * Tests for route pattern generation — {param} → named regex conversion.
 */
class RoutePatternTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        App::superglobals(true);
        self::$app = App::init('0.0.0.0', 19998, ZEALPHP_ROOT);
    }

    private function lastPattern(): string
    {
        $routes = self::$app->routes();
        return end($routes)['pattern'];
    }

    /** @return array<string, mixed> */
    private function lastRoute(): array
    {
        $routes = self::$app->routes();
        $last = end($routes);
        return is_array($last) ? $last : [];
    }

    // ── named-argument options: methods: / raw: (compose with the array form) ──

    public function testNamedMethodsArg(): void
    {
        self::$app->route('/na-methods', methods: ['GET', 'POST'], handler: fn() => '');
        $this->assertSame(['GET', 'POST'], $this->lastRoute()['methods']);
    }

    public function testNamedMethodsLowercaseNormalised(): void
    {
        self::$app->route('/na-lower', methods: ['get', 'delete'], handler: fn() => '');
        $this->assertSame(['GET', 'DELETE'], $this->lastRoute()['methods']);
    }

    public function testNamedRawArg(): void
    {
        self::$app->route('/na-raw', raw: true, handler: fn() => '');
        $r = $this->lastRoute();
        $this->assertTrue($r['raw']);
        $this->assertSame(['GET'], $r['methods']);
    }

    public function testArrayOptionsFormStillWorks(): void
    {
        self::$app->route('/bc-array', ['methods' => ['PUT'], 'raw' => true], fn() => '');
        $r = $this->lastRoute();
        $this->assertSame(['PUT'], $r['methods']);
        $this->assertTrue($r['raw']);
    }

    public function testTwoArgHandlerFormDefaultsToGet(): void
    {
        self::$app->route('/bc-2arg', fn() => '');
        $r = $this->lastRoute();
        $this->assertSame(['GET'], $r['methods']);
        $this->assertFalse($r['raw']);
    }

    public function testNamedArgsOnNsRoute(): void
    {
        self::$app->nsRoute('napi', '/u', methods: ['DELETE'], handler: fn() => '');
        $r = $this->lastRoute();
        $this->assertSame('/napi/u', $r['path']);
        $this->assertSame(['DELETE'], $r['methods']);
    }

    public function testNamedArgsOnPatternRoute(): void
    {
        self::$app->patternRoute('#^/np/(?P<x>\d+)$#', methods: ['GET', 'HEAD'], handler: fn() => '');
        $this->assertSame(['GET', 'HEAD'], $this->lastRoute()['methods']);
    }

    public function testStaticRoute(): void
    {
        self::$app->route('/hello', fn() => '');
        $pattern = $this->lastPattern();
        $this->assertMatchesRegularExpression($pattern, '/hello');
        $this->assertDoesNotMatchRegularExpression($pattern, '/hello/world');
    }

    public function testSingleParam(): void
    {
        self::$app->route('/users/{id}', fn($id) => '');
        $pattern = $this->lastPattern();
        $this->assertMatchesRegularExpression($pattern, '/users/42');
        $this->assertMatchesRegularExpression($pattern, '/users/abc');
        $this->assertDoesNotMatchRegularExpression($pattern, '/users/');
    }

    public function testMultipleParams(): void
    {
        self::$app->route('/users/{userId}/posts/{postId}', fn($userId, $postId) => '');
        $pattern = $this->lastPattern();
        $this->assertMatchesRegularExpression($pattern, '/users/1/posts/99');
        $this->assertDoesNotMatchRegularExpression($pattern, '/users/1/posts/');
    }

    public function testParamDoesNotMatchSlash(): void
    {
        self::$app->route('/a/{b}', fn($b) => '');
        $pattern = $this->lastPattern();
        $this->assertDoesNotMatchRegularExpression($pattern, '/a/x/y'); // {b} shouldn't match slash
    }

    public function testPatternRoute(): void
    {
        self::$app->patternRoute('/raw/(?P<rest>.*)', fn($rest) => '');
        $pattern = $this->lastPattern();
        $this->assertMatchesRegularExpression($pattern, '/raw/anything/at/all');
    }

    public function testMethodsStoredUppercase(): void
    {
        self::$app->route('/method-test', ['methods' => ['get', 'post']], fn() => '');
        $routes  = self::$app->routes();
        $methods = end($routes)['methods'];
        $this->assertContains('GET', $methods);
        $this->assertContains('POST', $methods);
    }

    public function testNsRoutePrefix(): void
    {
        self::$app->nsRoute('admin', '/users', fn() => '');
        $pattern = $this->lastPattern();
        $this->assertMatchesRegularExpression($pattern, '/admin/users');
    }

    public function testRoutesIndexed(): void
    {
        $countBefore = count(self::$app->routes());
        self::$app->route('/indexed-' . uniqid(), fn() => '');
        $this->assertCount($countBefore + 1, self::$app->routes());
    }
}
