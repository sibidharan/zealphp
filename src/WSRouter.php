<?php

declare(strict_types=1);

namespace ZealPHP;

use ZealPHP\Store\StoreException;

/**
 * Cross-server WebSocket routing helper.
 *
 * Bundles the "owner of fd does the push; Redis routes by client_id"
 * pattern into a small ergonomic API on top of the existing
 * Store/pub/sub primitives. Requires `Store::defaultBackend('redis')`
 * (Table backend rejects pub/sub anyway).
 *
 * Usage (in app.php — before $app->run()):
 *
 *     use ZealPHP\WSRouter;
 *     Store::defaultBackend(Store::BACKEND_REDIS);
 *     WSRouter::init('my-app');                // server id auto-derived from hostname:pid
 *
 *     $app->ws('/ws', function ($server, $frame) { ... });
 *     // In your onOpen:    WSRouter::own($clientId, $request->fd);
 *     // In your onClose:   WSRouter::release($clientId);
 *
 *     // Anywhere: send to a specific client, regardless of which server holds them.
 *     WSRouter::sendToClient($clientId, json_encode($payload));
 *
 *     // Or broadcast to a room channel (every subscribed server fans out locally).
 *     WSRouter::onRoom('chat:42', function (string $payload) use ($localClients) {
 *         foreach ($localClients as $fd) { $server->push($fd, $payload); }
 *     });
 *     WSRouter::broadcast('chat:42', json_encode($payload));
 *
 * Implementation:
 * - Per-cluster: `ws_owner` Store table holds (client_id → server_id).
 * - Per-server: WSRouter spawns ONE subscriber for `ws:server:{ID}`
 *   on first init() call inside onWorkerStart; messages route into the
 *   user-registered handler (default: push to local fd if one is found).
 * - Room broadcasts are plain pub/sub on a user-chosen channel.
 *
 * Lifecycle hooks must be wired before App::run() — same constraint as
 * App::onPubSub.
 */
final class WSRouter
{
    private const TABLE = 'ws_owner';

    private static string $serverId = '';
    /** @var array<string, int> client_id → local fd */
    private static array $localFds = [];
    /** @var ?callable(string $clientId, int $fd, string $payload): void */
    private static $clientSink = null;
    /** True once init() has wired the subscriber. */
    private static bool $initialized = false;

    /**
     * One-time setup. Pass a server id (defaults to hostname:pid) +
     * an optional callable that handles inbound routed messages.
     * The default sink looks up the local fd map and pushes via
     * `App::getServer()->push($fd, $payload)` (skipping with elog
     * when the client isn't local OR the fd is no longer established).
     */
    public static function init(?string $serverId = null, ?callable $clientSink = null): void
    {
        self::$serverId   = $serverId ?? gethostname() . ':' . getmypid();
        self::$clientSink = $clientSink ?? function (string $clientId, int $fd, string $payload): void {
            $server = App::getServer();
            // Only the WebSocket\Server variant has isEstablished + push;
            // the HTTP-only return is unreachable here in practice (WS routes
            // require the WS server) but the instanceof keeps PHPStan honest.
            if (!($server instanceof \OpenSwoole\WebSocket\Server)) { return; }
            if (!$server->isEstablished($fd)) { return; }
            $server->push($fd, $payload);
        };

        if (self::$initialized) { return; }
        self::$initialized = true;

        // Shared ownership table.
        Store::make(self::TABLE, 4096, [
            'server_id' => [Store::TYPE_STRING, 64],
        ]);

        // Subscribe to OUR identity channel. Inbound messages have
        // {client_id, payload}; the configured sink does the local push.
        App::onPubSub('ws:server:' . self::$serverId, function (string $payload): void {
            $msg = json_decode($payload, true);
            if (!is_array($msg)) { return; }
            $clientId = is_string($msg['client_id'] ?? null) ? $msg['client_id'] : '';
            $data     = is_string($msg['payload']   ?? null) ? $msg['payload']   : '';
            if ($clientId === '') { return; }
            $fd = self::$localFds[$clientId] ?? null;
            if ($fd === null) { return; }   // client not local to this worker
            if (self::$clientSink !== null) {
                (self::$clientSink)($clientId, $fd, $data);
            }
        });
    }

    /** Returns the configured server id (hostname:pid by default). */
    public static function serverId(): string { return self::$serverId; }

    /**
     * Record that this server now owns this client's WS connection.
     * Call from your ws onOpen handler — needs the assigned fd locally
     * AND the cluster-wide mapping in Store.
     */
    public static function own(string $clientId, int $fd): void
    {
        if (self::$serverId === '') {
            throw new StoreException('WSRouter::init() must be called before own()');
        }
        self::$localFds[$clientId] = $fd;
        Store::set(self::TABLE, $clientId, ['server_id' => self::$serverId]);
    }

    /** Clean up when a client disconnects (call from ws onClose). */
    public static function release(string $clientId): void
    {
        unset(self::$localFds[$clientId]);
        Store::del(self::TABLE, $clientId);
    }

    /**
     * Send to a specific client by id, regardless of which server holds
     * them. Returns true if Redis accepted the publish (delivery still
     * best-effort), false if the client isn't connected anywhere.
     */
    public static function sendToClient(string $clientId, string $payload): bool
    {
        $owner = Store::get(self::TABLE, $clientId, 'server_id');
        if (!is_string($owner) || $owner === '') { return false; }
        Store::publish('ws:server:' . $owner, (string) json_encode([
            'client_id' => $clientId,
            'payload'   => $payload,
        ]));
        return true;
    }

    /**
     * Publish to a room channel. Every server with a matching
     * `App::onPubSub($channel, ...)` handler fans out to its local
     * clients. Returns the receiver count Redis reported.
     */
    public static function broadcast(string $channel, string $payload): int
    {
        return Store::publish($channel, $payload);
    }

    /**
     * Sugar over `App::onPubSub($channel, ...)` — use when you want
     * the room-channel registration to read more naturally alongside
     * the routing calls above.
     */
    public static function onRoom(string $channel, callable $handler): void
    {
        App::onPubSub($channel, $handler);
    }

    /** @internal — testing hook to reset module state between cases. */
    public static function reset(): void
    {
        self::$serverId    = '';
        self::$localFds    = [];
        self::$clientSink  = null;
        self::$initialized = false;
    }

    /** @internal — current local fd-map snapshot (for tests + debug). */
    /** @return array<string, int> */
    public static function localFds(): array { return self::$localFds; }
}
