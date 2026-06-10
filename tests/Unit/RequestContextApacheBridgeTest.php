<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * #346 — Apache/mod_php bridge: the process-wide RequestContext singleton
 * (the path every non-OpenSwoole SAPI takes — no coroutine, superglobals
 * mode) must NOT let the declared, default-initialized typed properties
 * ("public array $server = []") shadow the __get superglobals proxy. The
 * singleton now unsets the request-input slots at construction so
 * $g->server / $g->get / $g->request are LIVE ALIASES of $_SERVER / $_GET /
 * $_REQUEST — reads see the SAPI-populated arrays, writes carry through.
 */
class RequestContextApacheBridgeTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedServer = [];
    /** @var array<string, mixed> */
    private array $savedGet = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedServer = $_SERVER;
        $this->savedGet = $_GET;
        App::superglobals(true);
        $this->resetSingleton();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_GET = $this->savedGet;
        $this->resetSingleton();
        parent::tearDown();
    }

    private function resetSingleton(): void
    {
        $prop = new \ReflectionProperty(RequestContext::class, 'instance');
        $prop->setValue(null, null);
    }

    public function testSingletonBridgesReadsToLiveSuperglobals(): void
    {
        $_SERVER['ZP346_HOST'] = 'apache.example';
        $_GET['zp346_q'] = 'find';

        $g = RequestContext::instance();
        $this->assertSame('apache.example', $g->server['ZP346_HOST'] ?? null,
            '#346: $g->server must read the SAPI-populated $_SERVER, not the empty typed slot');
        $this->assertSame('find', $g->get['zp346_q'] ?? null);
        // The bridge is the SAME array, not a copy: post-instance() SAPI-side
        // mutations are visible.
        $_SERVER['ZP346_LATE'] = 'late';
        $this->assertSame('late', $g->server['ZP346_LATE'] ?? null);
    }

    public function testSingletonBridgesWritesThrough(): void
    {
        $g = RequestContext::instance();
        $g->get['zp346_written'] = 'yes';
        $this->assertSame('yes', $_GET['zp346_written'] ?? null,
            '#346: writes through $g->get must land in the live $_GET');
    }

    public function testSingletonIsReusedAndStaysBridged(): void
    {
        $first = RequestContext::instance();
        $_SERVER['ZP346_TWICE'] = '2';
        $second = RequestContext::instance();
        $this->assertSame($first, $second);
        $this->assertSame('2', $second->server['ZP346_TWICE'] ?? null);
    }
}
