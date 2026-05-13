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
