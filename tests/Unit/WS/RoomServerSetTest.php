<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\WS;

use OpenSwoole\Coroutine;
use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\WSRouter;

/**
 * B1 — per-room server-set maintenance (cross-node fan-out groundwork).
 *
 * `WSRouter::roomServers($room)` returns the server_ids holding >=1 member of a
 * room; the future B2 step publishes a room message only to those servers. The
 * set is maintained atomically (Lua) on Room::join/leave, riding the 0<->1
 * cardinality boundary of a per-(room,server) client set so it's race-correct
 * and idempotent.
 *
 * Different servers are simulated by reset()+init() with distinct server ids
 * between joins — the server-set lives in the SHARED Redis, so it accumulates
 * members from every simulated server. Requires Redis/Valkey (skips otherwise).
 */
final class RoomServerSetTest extends TestCase
{
    private string $url;
    /** @var list<string> */
    private array $rooms = [];

    protected function setUp(): void
    {
        $url = (string) getenv('ZEALPHP_REDIS_URL');
        $this->url = $url !== '' ? $url : 'redis://127.0.0.1:16379/0';
        Store::defaultBackend(Store::BACKEND_REDIS, $this->url);
        WSRouter::reset();
        $this->skipIfNoRedis();
    }

    protected function tearDown(): void
    {
        // Drop the absolute Redis keys this test created (server-sets +
        // per-(room,server) client sets) so cases stay independent.
        foreach ($this->rooms as $room) {
            try {
                Store::eval(
                    "local n=redis.call('KEYS', ARGV[1]); for i=1,#n do redis.call('DEL', n[i]) end; return 1",
                    [],
                    [WSRouter::roomChannelPrefix() . $room . ':*'],
                );
            } catch (\Throwable) { /* best-effort cleanup */ }
        }
        WSRouter::reset();
        Store::defaultBackend(Store::BACKEND_TABLE);
    }

    private function room(string $tag): string
    {
        $r = $tag . '-' . bin2hex(random_bytes(4));
        $this->rooms[] = $r;
        return $r;
    }

    public function testServerSetCollectsEveryServerHoldingMembers(): void
    {
        Coroutine::run(function (): void {
            $room = $this->room('rs');

            WSRouter::init('srvA');
            WSRouter::room($room)->join('alice');

            WSRouter::reset();
            WSRouter::init('srvB');
            WSRouter::room($room)->join('bob');

            $servers = WSRouter::roomServers($room);
            sort($servers);
            self::assertSame(['srvA', 'srvB'], $servers, 'both servers holding members are in the set');
        });
    }

    public function testServerDroppedWhenItsLastMemberLeaves(): void
    {
        Coroutine::run(function (): void {
            $room = $this->room('drop');

            WSRouter::init('srvA');
            WSRouter::room($room)->join('alice');
            WSRouter::reset();
            WSRouter::init('srvB');
            WSRouter::room($room)->join('bob');

            // srvA's only member leaves → srvA drops out of the set; srvB stays.
            WSRouter::reset();
            WSRouter::init('srvA');
            WSRouter::room($room)->leave('alice');

            self::assertSame(['srvB'], WSRouter::roomServers($room));
        });
    }

    public function testServerStaysWhileItStillHasOtherMembers(): void
    {
        Coroutine::run(function (): void {
            $room = $this->room('multi');

            WSRouter::init('srvA');
            $r = WSRouter::room($room);
            $r->join('alice');
            $r->join('carol');

            // One of two members on srvA leaves — srvA must remain (carol still here).
            $r->leave('alice');
            self::assertSame(['srvA'], WSRouter::roomServers($room), 'server stays while it has other members');

            // Last member leaves — now srvA drops out.
            $r->leave('carol');
            self::assertSame([], WSRouter::roomServers($room));
        });
    }

    public function testJoinIsIdempotentForTheServerSet(): void
    {
        Coroutine::run(function (): void {
            $room = $this->room('idem');
            WSRouter::init('srvA');
            $r = WSRouter::room($room);

            $r->join('alice');
            $r->join('alice'); // re-join is a no-op SADD
            $r->join('alice');
            self::assertSame(['srvA'], WSRouter::roomServers($room));

            // A single leave removes the (idempotent) single membership → drop.
            $r->leave('alice');
            self::assertSame([], WSRouter::roomServers($room));
        });
    }

    public function testRoomServersEmptyOnTableBackend(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        self::assertSame([], WSRouter::roomServers('anything'));
    }

    private function skipIfNoRedis(): void
    {
        try {
            $c = new \Predis\Client($this->url);
            $c->ping();
            $c->disconnect();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis/Valkey not available: ' . $e->getMessage());
        }
    }
}
