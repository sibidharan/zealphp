<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * #338 — unit pins for the fatal→500 guard's registry + conservative
 * behaviors. The actual 500 emission is pinned by the integration test
 * (tests/Integration/FatalGuardTest.php): a REAL fatal kills the phpunit
 * process, so it cannot be exercised in-process here.
 */
class FatalGuardRegistryTest extends TestCase
{
    /** @return array<int, \OpenSwoole\Http\Response> */
    private function registry(): array
    {
        $p = new \ReflectionProperty(App::class, 'fatal_guard_inflight');

        /** @var array<int, \OpenSwoole\Http\Response> */
        return $p->getValue();
    }

    private function makeResponse(): \OpenSwoole\Http\Response
    {
        return $this->getMockBuilder(\OpenSwoole\Http\Response::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testTrackAndReleaseLifecycle(): void
    {
        $r = $this->makeResponse();
        $id = App::fatalGuardTrack($r);

        $this->assertSame(spl_object_id($r), $id, 'release key is the object id');
        $this->assertArrayHasKey($id, $this->registry());
        $this->assertSame($r, $this->registry()[$id]);

        App::fatalGuardRelease($id);
        $this->assertArrayNotHasKey($id, $this->registry());
    }

    public function testReleaseIsIdempotent(): void
    {
        $r = $this->makeResponse();
        $id = App::fatalGuardTrack($r);
        App::fatalGuardRelease($id);
        App::fatalGuardRelease($id); // second release must not error

        $this->assertArrayNotHasKey($id, $this->registry());
    }

    public function testConcurrentRequestsTrackIndependently(): void
    {
        $a = $this->makeResponse();
        $b = $this->makeResponse();
        $ida = App::fatalGuardTrack($a);
        $idb = App::fatalGuardTrack($b);

        $this->assertNotSame($ida, $idb);
        App::fatalGuardRelease($ida);
        $this->assertArrayNotHasKey($ida, $this->registry());
        $this->assertArrayHasKey($idb, $this->registry(), 'releasing one request must not drop a concurrent one');
        App::fatalGuardRelease($idb);
    }

    public function testGuardIsNoOpWithoutAFatal(): void
    {
        $r = $this->makeResponse();
        $r->expects($this->never())->method('status');
        $r->expects($this->never())->method('end');
        $id = App::fatalGuardTrack($r);

        // The last error in this process is at worst a non-fatal type —
        // make that deterministic, then run the guard.
        @trigger_error('non-fatal marker (#338 test)', E_USER_NOTICE);
        App::fatalResponseGuard();

        $this->assertArrayHasKey(
            $id,
            $this->registry(),
            'a non-fatal shutdown (normal request end, notices only) must leave in-flight entries untouched'
        );
        App::fatalGuardRelease($id);
    }

    public function testGuardIsNoOpOnEmptyRegistry(): void
    {
        $this->assertSame([], $this->registry());
        App::fatalResponseGuard(); // must not error with nothing in flight
        $this->assertSame([], $this->registry());
    }
}
