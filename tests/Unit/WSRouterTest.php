<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\WSRouter;

/**
 * Exercises the state-management + delegation surface of WSRouter.
 * The actual `$server->push($fd, ...)` path requires a real OpenSwoole
 * WebSocket server (live in integration tests, not here) — the unit
 * tests inject a synthetic sink callable to assert the message routes
 * to the right fd.
 */
final class WSRouterTest extends TestCase
{
    private string $url;

    protected function setUp(): void
    {
        $url = (string) getenv('ZEALPHP_REDIS_URL');
        $this->url = $url !== '' ? $url : 'redis://127.0.0.1:16379/0';
        Store::defaultBackend(Store::BACKEND_REDIS, $this->url);
        WSRouter::reset();
    }

    protected function tearDown(): void
    {
        WSRouter::reset();
        Store::defaultBackend(Store::BACKEND_TABLE);
    }

    public function testInitDerivesServerIdAndCreatesOwnerTable(): void
    {
        $this->skipIfNoRedis();
        Coroutine::run(function (): void {
            WSRouter::init();
            $id = WSRouter::serverId();
            $this->assertNotSame('', $id);
            $this->assertStringContainsString(':', $id, 'default id is hostname:pid');
        });
    }

    public function testInitAcceptsCustomServerIdAndCustomSink(): void
    {
        $this->skipIfNoRedis();
        Coroutine::run(function (): void {
            $calls = [];
            WSRouter::init('node-A', function (string $clientId, int $fd, string $payload) use (&$calls): void {
                $calls[] = compact('clientId', 'fd', 'payload');
            });
            $this->assertSame('node-A', WSRouter::serverId());

            // own() returns the conn_id; localFds carries {fd, conn_id}.
            $connId = WSRouter::own('alice', 42);
            $this->assertNotSame('', $connId);
            $this->assertSame(
                ['alice' => ['fd' => 42, 'conn_id' => $connId]],
                WSRouter::localFds(),
            );

            // The Store row is also visible across the cluster
            $row = Store::get('ws_owner', 'alice');
            $this->assertIsArray($row);
            $this->assertSame('node-A', $row['server_id']);
            $this->assertSame($connId, $row['conn_id']);
        });
    }

    public function testReleaseRemovesFromLocalFdsAndOwnerTable(): void
    {
        $this->skipIfNoRedis();
        Coroutine::run(function (): void {
            WSRouter::init('node-B');
            $bobConn   = WSRouter::own('bob', 17);
            $carolConn = WSRouter::own('carol', 99);
            $this->assertCount(2, WSRouter::localFds());

            WSRouter::release('bob');
            $this->assertSame(
                ['carol' => ['fd' => 99, 'conn_id' => $carolConn]],
                WSRouter::localFds(),
            );
            $this->assertFalse(Store::get('ws_owner', 'bob'));
            $this->assertIsArray(Store::get('ws_owner', 'carol'));
        });
    }

    public function testOwnEvictsStaleEntryWithSameFd(): void
    {
        // C1: FD-reuse race. If 'alice' is locally mapped to fd=12 because
        // her onClose was lost, and OpenSwoole reassigns fd=12 to 'bob',
        // own('bob', 12) must drop the stale 'alice' entry — both locally
        // and in the cluster-wide ws_owner table — so subsequent sends to
        // alice don't accidentally land on bob's connection.
        $this->skipIfNoRedis();
        Coroutine::run(function (): void {
            WSRouter::init('node-C');
            WSRouter::own('alice', 12);
            $this->assertArrayHasKey('alice', WSRouter::localFds());

            // Alice's onClose is missed → bob takes fd=12
            $bobConn = WSRouter::own('bob', 12);
            $fds = WSRouter::localFds();
            $this->assertArrayNotHasKey('alice', $fds, 'stale alice entry must be evicted');
            $this->assertArrayHasKey('bob', $fds);
            $this->assertSame($bobConn, $fds['bob']['conn_id']);

            // Cluster-wide ws_owner must also have dropped alice.
            $this->assertFalse(Store::get('ws_owner', 'alice'));
            $this->assertIsArray(Store::get('ws_owner', 'bob'));
        });
    }

    public function testOwnAcceptsExplicitConnIdForTesting(): void
    {
        $this->skipIfNoRedis();
        Coroutine::run(function (): void {
            WSRouter::init('node-D');
            $returned = WSRouter::own('alice', 7, 'test-conn-id');
            $this->assertSame('test-conn-id', $returned);
            $this->assertSame('test-conn-id', WSRouter::localFds()['alice']['conn_id']);
            $this->assertSame('test-conn-id', Store::get('ws_owner', 'alice', 'conn_id'));
        });
    }

    public function testSendToClientLooksUpOwnerAndPublishes(): void
    {
        // Note: the full publish-then-handler-fires round-trip lives inside
        // an integration test (it requires App::run() to wire the subscriber
        // via onWorkerStart). Here we drive only the publish-half: confirm
        // sendToClient reads the owner from Store + publishes successfully
        // (receiver count is 0 because no subscriber is registered in this
        // bare unit context).
        $this->skipIfNoRedis();
        Coroutine::run(function (): void {
            WSRouter::init('node-A');
            WSRouter::own('alice', 42);

            $sent = WSRouter::sendToClient('alice', '{"greet":"hi"}');
            $this->assertTrue($sent, 'returns true when the owner row exists');

            // Owner-lookup uses Store::get; sanity-check the actual lookup value.
            $this->assertSame('node-A', Store::get('ws_owner', 'alice', 'server_id'));
        });
    }

    public function testSendToClientReturnsFalseForUnknownClient(): void
    {
        $this->skipIfNoRedis();
        Coroutine::run(function (): void {
            WSRouter::init('node-A');
            $sent = WSRouter::sendToClient('nobody-here', 'x');
            $this->assertFalse($sent);
        });
    }

    public function testBroadcastDelegatesToStorePublish(): void
    {
        $this->skipIfNoRedis();
        Coroutine::run(function (): void {
            // With no subscribers on the channel, broadcast returns 0.
            WSRouter::init('node-A');
            $this->assertSame(0, WSRouter::broadcast('unit-test:no-listeners', 'hello'));
        });
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
