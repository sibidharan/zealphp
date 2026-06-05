<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\WS;

use OpenSwoole\Coroutine;
use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\Store\StoreException;
use ZealPHP\WSRouter;
use ZealPHP\WS\Room;

/**
 * #246 (room fan-out conn_id fd-reuse guard) + #247 (room-name / compositeKey
 * collision). Both need the Redis backend (rooms use cluster-wide membership +
 * pub/sub); skipped if Valkey on :16379 isn't reachable.
 */
final class RoomKeyAndConnIdTest extends TestCase
{
    private string $url;

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
        WSRouter::reset();
        Store::defaultBackend(Store::BACKEND_TABLE);
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

    // ── #247: room-name validation rejects separator-bearing names ─────────

    public function testRoomNameWithColonIsRejected(): void
    {
        Coroutine::run(function (): void {
            WSRouter::init('test-server');
            $threw = false;
            try {
                WSRouter::room('chat:42');
            } catch (StoreException $e) {
                $threw = true;
                self::assertStringContainsString('invalid room name', $e->getMessage());
            }
            self::assertTrue($threw, "room('chat:42') must be rejected (':' collides keys/channel)");
        });
    }

    public function testRoomNameWithOtherSeparatorsRejected(): void
    {
        Coroutine::run(function (): void {
            WSRouter::init('test-server');
            foreach (['a b', 'a/b', 'a:b', 'a*b', "a\nb", ''] as $bad) {
                $threw = false;
                try {
                    WSRouter::room($bad);
                } catch (StoreException) {
                    $threw = true;
                }
                self::assertTrue($threw, "room('{$bad}') must be rejected");
            }
        });
    }

    public function testValidRoomNamesAccepted(): void
    {
        Coroutine::run(function (): void {
            WSRouter::init('test-server');
            foreach (['chat42', 'chat-42', 'chat_42', 'chat.42', 'Room-1.2_3'] as $ok) {
                $room = WSRouter::room($ok);
                self::assertSame($ok, $room->name());
            }
        });
    }

    public function testCompositeKeyDisambiguatesAmbiguousPairs(): void
    {
        // The original collision: compositeKey('chat:42','alice') ===
        // compositeKey('chat','42:alice'). The length-prefix makes the key an
        // unambiguous function of the pair. (Reflected because compositeKey is
        // private — the room name itself is now charset-validated upstream, but
        // the prefix defends the client-id half too.)
        $ref = new \ReflectionMethod(Room::class, 'compositeKey');

        $a = (string) $ref->invoke(null, 'chat:42', 'alice');
        $b = (string) $ref->invoke(null, 'chat', '42:alice');
        self::assertNotSame($a, $b, 'length-prefixed composite keys must differ');

        // And the same pair is still stable.
        $a2 = (string) $ref->invoke(null, 'chat:42', 'alice');
        self::assertSame($a, $a2);
    }

    // ── #246: room push cache captures conn_id; fd-reuse drift is detectable ─

    public function testJoinCachesConnIdNotBareTrue(): void
    {
        Coroutine::run(function (): void {
            $roomName = 'r-' . bin2hex(random_bytes(3));
            WSRouter::init('test-server');

            // Own alice with an explicit nonce, then deliver her join event.
            WSRouter::own('alice', 5, 'connAAA');
            WSRouter::handleRoomMessage('ws:room:' . $roomName, (string) json_encode([
                'type'      => 'join',
                'client_id' => 'alice',
                'ts'        => 1,
            ]));

            $cache = WSRouter::localRoomMembership();
            self::assertArrayHasKey($roomName, $cache);
            self::assertArrayHasKey('alice', $cache[$roomName]);
            // The value is the captured conn_id (#246), not a bare `true`.
            self::assertSame('connAAA', $cache[$roomName]['alice']);
        });
    }

    public function testFdReuseProducesConnIdDriftTheGuardCatches(): void
    {
        Coroutine::run(function (): void {
            $roomName = 'r-' . bin2hex(random_bytes(3));
            WSRouter::init('test-server');

            // alice joins on fd 5 / connAAA → cached as connAAA.
            WSRouter::own('alice', 5, 'connAAA');
            WSRouter::handleRoomMessage('ws:room:' . $roomName, (string) json_encode([
                'type'      => 'join',
                'client_id' => 'alice',
                'ts'        => 1,
            ]));
            self::assertSame('connAAA', WSRouter::localRoomMembership()[$roomName]['alice']);

            // alice's onClose was lost; the SAME fd 5 is reassigned to a fresh
            // connection that reuses the client id but gets a NEW nonce. The
            // membership cache still holds the STALE connAAA (no new join event
            // fired for it yet) while localFds now maps to connBBB.
            WSRouter::own('alice', 5, 'connBBB');

            $cachedConnId = WSRouter::localRoomMembership()[$roomName]['alice'];
            $liveConnId   = WSRouter::localFds()['alice']['conn_id'];

            self::assertSame('connAAA', $cachedConnId, 'cache holds the stale nonce');
            self::assertSame('connBBB', $liveConnId, 'live fd holds the fresh nonce');

            // This is exactly the guard condition in the room push loop:
            //   $cachedConnId !== '' && $local['conn_id'] !== $cachedConnId  → skip
            // so the stale-destined room message is NOT pushed to the new client.
            self::assertTrue(
                $cachedConnId !== '' && $liveConnId !== $cachedConnId,
                'conn_id drift must be detectable so the room push skips the reused fd'
            );
        });
    }

    public function testMatchingConnIdDoesNotDrift(): void
    {
        Coroutine::run(function (): void {
            $roomName = 'r-' . bin2hex(random_bytes(3));
            WSRouter::init('test-server');

            WSRouter::own('bob', 9, 'connBOB');
            WSRouter::handleRoomMessage('ws:room:' . $roomName, (string) json_encode([
                'type'      => 'join',
                'client_id' => 'bob',
                'ts'        => 1,
            ]));

            $cachedConnId = WSRouter::localRoomMembership()[$roomName]['bob'];
            $liveConnId   = WSRouter::localFds()['bob']['conn_id'];

            // No reuse → cached === live → the guard does NOT skip (push proceeds).
            self::assertSame($cachedConnId, $liveConnId);
            self::assertFalse($cachedConnId !== '' && $liveConnId !== $cachedConnId);
        });
    }
}
