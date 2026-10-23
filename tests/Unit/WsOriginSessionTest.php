<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * A WebSocket upgrade only loads the visitor's cookie session when its Origin
 * is the app's own host (or allowlisted). Otherwise a page on another origin
 * could open the socket as the visitor (cross-site WebSocket hijacking).
 */
class WsOriginSessionTest extends TestCase
{
    protected function tearDown(): void
    {
        App::wsAllowedOrigins([]);
        parent::tearDown();
    }

    public function testSameHostOriginMayUseTheSession(): void
    {
        $this->assertTrue(App::wsOriginMayUseSession('https://app.example.test', 'app.example.test'));
        $this->assertTrue(App::wsOriginMayUseSession('http://app.example.test:8080', 'app.example.test:8080'));
        $this->assertTrue(App::wsOriginMayUseSession('https://APP.example.test:443', 'app.example.test'));
    }

    public function testNonBrowserClientWithoutOriginMayUseTheSession(): void
    {
        $this->assertTrue(App::wsOriginMayUseSession(null, 'app.example.test'));
    }

    public function testForeignOriginsMayNot(): void
    {
        $this->assertFalse(App::wsOriginMayUseSession('https://evil.example.test', 'app.example.test'));
        $this->assertFalse(App::wsOriginMayUseSession('https://app.example.test.evil.test', 'app.example.test'));
        $this->assertFalse(App::wsOriginMayUseSession('http://app.example.test:9999', 'app.example.test:8080'));
        $this->assertFalse(App::wsOriginMayUseSession('null', 'app.example.test'));
        $this->assertFalse(App::wsOriginMayUseSession('https://app.example.test', null));
    }

    public function testAllowlistedOriginMayUseTheSession(): void
    {
        App::wsAllowedOrigins(['https://Front.example.test/']);
        $this->assertTrue(App::wsOriginMayUseSession('https://front.example.test', 'api.example.test'));
        $this->assertFalse(App::wsOriginMayUseSession('https://other.example.test', 'api.example.test'));
        App::wsAllowedOrigins(['*']);
        $this->assertTrue(App::wsOriginMayUseSession('https://other.example.test', 'api.example.test'));
    }
}
