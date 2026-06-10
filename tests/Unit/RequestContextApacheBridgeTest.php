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
 * ("public array $server = []") shadow the __get superglobals proxy.
 * RequestContext::bridgeSuperglobalSlots() unsets the request-input slots so
 * $g->server / $g->get / $g->request become LIVE ALIASES of $_SERVER / $_GET
 * / $_REQUEST — reads see the SAPI-populated arrays, writes carry through.
 *
 * Tested on DETACHED instances (reflection-constructed, the private helper
 * invoked directly) so the suite's real singleton — which other tests and
 * the override bookkeeping depend on — is never touched.
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
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_GET = $this->savedGet;
        parent::tearDown();
    }

    private function makeDetached(bool $bridged): RequestContext
    {
        $rc = new \ReflectionClass(RequestContext::class);
        $instance = $rc->newInstanceWithoutConstructor();
        if ($bridged) {
            $m = $rc->getMethod('bridgeSuperglobalSlots');
            $m->invoke(null, $instance);
        }
        return $instance;
    }

    public function testUnbridgedTypedSlotsShadowTheProxy(): void
    {
        // The #346 failure mode itself: without the bridge, the declared
        // empty arrays win and the SAPI-populated superglobals are invisible.
        $_SERVER['ZP346_HOST'] = 'apache.example';
        $g = $this->makeDetached(false);
        $this->assertSame([], $g->server,
            'control: a declared typed slot shadows __get (the pre-fix behaviour)');
    }

    public function testBridgedReadsSeeLiveSuperglobals(): void
    {
        $_SERVER['ZP346_HOST'] = 'apache.example';
        $_GET['zp346_q'] = 'find';

        $g = $this->makeDetached(true);
        $this->assertSame('apache.example', $g->server['ZP346_HOST'] ?? null,
            '#346: $g->server must read the SAPI-populated $_SERVER, not the empty typed slot');
        $this->assertSame('find', $g->get['zp346_q'] ?? null);
        // The bridge is the SAME array, not a copy: post-bridge SAPI-side
        // mutations are visible.
        $_SERVER['ZP346_LATE'] = 'late';
        $this->assertSame('late', $g->server['ZP346_LATE'] ?? null);
    }

    public function testBridgedWritesCarryThrough(): void
    {
        $g = $this->makeDetached(true);
        $g->get['zp346_written'] = 'yes';
        $this->assertSame('yes', $_GET['zp346_written'] ?? null,
            '#346: writes through $g->get must land in the live $_GET');
        unset($_GET['zp346_written']);
    }
}
