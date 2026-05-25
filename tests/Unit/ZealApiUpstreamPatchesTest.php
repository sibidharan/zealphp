<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Tests\TestCase;
use ZealPHP\ZealAPI;

/**
 * Pins the three upstream-from-labs patches: resolveClubParam() (club/group
 * alias), failAs() (Throwable → 400 JSON), and json() (now public so handler
 * closures can call $this->json() directly).
 */
class ZealApiUpstreamPatchesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $g = G::instance();
        $g->server = ['REQUEST_METHOD' => 'GET'];
        $g->get = [];
        $g->post = [];
        $g->zealphp_request = new \stdClass();
        $g->zealphp_response = new class {
            /** @var array<string, mixed> */
            public array $headers = [];
            public int $status = 200;

            public function header(string $name, mixed $value, bool $ucwords = true): void
            {
                $this->headers[$name] = $value;
            }

            public function status(int $status): void
            {
                $this->status = $status;
            }
        };
    }

    protected function tearDown(): void
    {
        $g = G::instance();
        $g->zealphp_request = null;
        $g->zealphp_response = null;
        $g->server = [];
        $g->get = [];
        $g->post = [];
        parent::tearDown();
    }

    private function api(): ZealAPI
    {
        return new ZealAPI(null, null, sys_get_temp_dir());
    }

    private function captureStatus(callable $fn): array
    {
        $g = G::instance();
        ob_start();
        $fn();
        $echo = (string) ob_get_clean();
        return ['echo' => $echo, 'status' => $g->zealphp_response->status];
    }

    // ── resolveClubParam() ─────────────────────────────────────────────

    public function testResolveClubParamReturnsClubWhenPresent(): void
    {
        G::instance()->get = ['club' => 'club-42'];
        $this->assertSame('club-42', $this->api()->resolveClubParam());
    }

    public function testResolveClubParamFallsBackToGroup(): void
    {
        G::instance()->get = ['group' => 'group-7'];
        $this->assertSame('group-7', $this->api()->resolveClubParam());
    }

    public function testResolveClubParamPrefersClubOverGroup(): void
    {
        G::instance()->get = ['club' => 'club-1', 'group' => 'group-2'];
        $this->assertSame('club-1', $this->api()->resolveClubParam());
    }

    public function testResolveClubParamReturnsNullWhenNeitherPresent(): void
    {
        G::instance()->get = ['other' => 'x'];
        $this->assertNull($this->api()->resolveClubParam());
    }

    // ── failAs() ───────────────────────────────────────────────────────

    public function testFailAsEmits400WithErrorMessageEnvelope(): void
    {
        $api = $this->api();
        $r = $this->captureStatus(fn() => $api->failAs(new \RuntimeException('club not found')));
        $this->assertSame(400, $r['status']);
        $body = json_decode($r['echo'], true);
        $this->assertSame(['error' => 'club not found'], $body);
    }

    public function testFailAsAcceptsAnyThrowable(): void
    {
        $api = $this->api();
        // \Error (not just \Exception) — Throwable contract.
        $r = $this->captureStatus(fn() => $api->failAs(new \Error('boom')));
        $this->assertSame(400, $r['status']);
        $this->assertSame(['error' => 'boom'], json_decode($r['echo'], true));
    }

    // ── json() public visibility ───────────────────────────────────────

    public function testJsonIsCallableFromOutsideTheClass(): void
    {
        $rm = new \ReflectionMethod(ZealAPI::class, 'json');
        $this->assertTrue($rm->isPublic(), 'json() must be public so handler closures can use $this->json()');
    }

    public function testJsonEncodesArrayAsPrettyJson(): void
    {
        $out = $this->api()->json(['k' => 'v']);
        $this->assertJson($out);
        $this->assertSame(['k' => 'v'], json_decode($out, true));
    }

    public function testJsonReturnsEmptyObjectForNonArray(): void
    {
        $this->assertSame('{}', $this->api()->json('not-an-array'));
        $this->assertSame('{}', $this->api()->json(null));
        $this->assertSame('{}', $this->api()->json(42));
    }
}
