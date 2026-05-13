<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\App;

/**
 * Tests for App::buildParamMap() — parameter injection reflection.
 *
 * The method is private, so we register a temp route and inspect the
 * stored param_map via App::instance()->routes().
 */
class BuildParamMapTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        // Bootstrap a minimal App instance for reflection tests
        // (no server started)
        App::superglobals(true);
        self::$app = App::init('0.0.0.0', 19999, ZEALPHP_ROOT);
    }

    private function registerAndGetMap(callable $handler): array
    {
        $path = '/test-param-map-' . uniqid();
        self::$app->route($path, $handler);
        $routes = self::$app->routes();
        $route  = end($routes);
        return $route['param_map'];
    }

    public function testNoParams(): void
    {
        $map = $this->registerAndGetMap(function() {});
        $this->assertSame([], $map);
    }

    public function testUrlParamOnly(): void
    {
        $map = $this->registerAndGetMap(function($id) {});
        $this->assertCount(1, $map);
        $this->assertSame('id', $map[0]['name']);
        $this->assertFalse($map[0]['has_default']);
    }

    public function testRequestParam(): void
    {
        $map = $this->registerAndGetMap(function($request) {});
        $this->assertSame('request', $map[0]['name']);
    }

    public function testResponseParam(): void
    {
        $map = $this->registerAndGetMap(function($response) {});
        $this->assertSame('response', $map[0]['name']);
    }

    public function testAppParam(): void
    {
        $map = $this->registerAndGetMap(function($app) {});
        $this->assertSame('app', $map[0]['name']);
    }

    public function testDefaultValue(): void
    {
        $map = $this->registerAndGetMap(function($id, $page = 1) {});
        $this->assertCount(2, $map);
        $this->assertFalse($map[0]['has_default']);
        $this->assertTrue($map[1]['has_default']);
        $this->assertSame(1, $map[1]['default']);
    }

    public function testMultipleUrlParams(): void
    {
        $map = $this->registerAndGetMap(function($userId, $postId) {});
        $this->assertCount(2, $map);
        $this->assertSame('userId', $map[0]['name']);
        $this->assertSame('postId', $map[1]['name']);
    }

    public function testAllCombined(): void
    {
        $map = $this->registerAndGetMap(function($id, $request, $response, $page = 1) {});
        $this->assertCount(4, $map);
        $this->assertSame('id',       $map[0]['name']);
        $this->assertSame('request',  $map[1]['name']);
        $this->assertSame('response', $map[2]['name']);
        $this->assertSame('page',     $map[3]['name']);
        $this->assertTrue($map[3]['has_default']);
    }

    public function testStringDefault(): void
    {
        $map = $this->registerAndGetMap(function($format = 'json') {});
        $this->assertSame('json', $map[0]['default']);
    }

    public function testNullDefault(): void
    {
        $map = $this->registerAndGetMap(function($filter = null) {});
        $this->assertNull($map[0]['default']);
    }
}
