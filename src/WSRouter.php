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
    /** @var array<string, array{fd:int, conn_id:string}> client_id → {fd, conn_id} */
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

        // Shared ownership table. conn_id is a per-connection nonce — see
        // own() + sendToClient() for the FD-reuse-race fix (C1).
        Store::make(self::TABLE, 4096, [
            'server_id' => [Store::TYPE_STRING, 64],
            'conn_id'   => [Store::TYPE_STRING, 32],
        ]);

        // Subscribe to OUR identity channel. Inbound messages have
        // {client_id, conn_id, payload}; the configured sink does the
        // local push iff the conn_id matches the currently-held connection.
        App::onPubSub('ws:server:' . self::$serverId, function (string $payload): void {
            $msg = json_decode($payload, true);
            if (!is_array($msg)) { return; }
            $clientId = is_string($msg['client_id'] ?? null) ? $msg['client_id'] : '';
            $connId   = is_string($msg['conn_id']   ?? null) ? $msg['conn_id']   : '';
            $data     = is_string($msg['payload']   ?? null) ? $msg['payload']   : '';
            if ($clientId === '') { return; }
            $local = self::$localFds[$clientId] ?? null;
            if ($local === null) { return; }
            // C1: drop if the local connection's conn_id has drifted from
            // the publisher's view (client reconnected; fd may have been
            // reused). Without this check, the new owner of the fd would
            // receive a message intended for the old (now-disconnected)
            // client — a cross-tenant data-leakage vector.
            if ($connId !== '' && $local['conn_id'] !== $connId) {
                if (function_exists('elog')) {
                    elog("WSRouter: dropped stale publish for {$clientId} (conn_id mismatch)", 'debug');
                }
                return;
            }
            if (self::$clientSink !== null) {
                (self::$clientSink)($clientId, $local['fd'], $data);
            }
        });
    }

    /** Returns the configured server id (hostname:pid by default). */
    public static function serverId(): string { return self::$serverId; }

    /**
     * Record that this server now owns this client's WS connection.
     * Call from your ws onOpen handler — needs the assigned fd locally
     * AND the cluster-wide mapping in Store.
     *
     * Returns the per-connection nonce (conn_id) — a random 16-byte hex
     * string the framework uses to defeat FD-reuse races. Callers
     * usually don't need it; the framework manages it internally.
     */
    public static function own(string $clientId, int $fd, ?string $connId = null): string
    {
        if (self::$serverId === '') {
            throw new StoreException('WSRouter::init() must be called before own()');
        }
        $connId ??= bin2hex(random_bytes(8));

        // C1 — fd-coherence invariant. If another client in this worker
        // is mapped to the same fd (the symptom of a lost onClose where
        // the OS reaped the fd and OpenSwoole already reassigned it),
        // drop the stale entry first. OpenSwoole binds at most one
        // connection per fd at any instant; the previous holder is dead.
        foreach (self::$localFds as $otherId => $row) {
            if ($otherId !== $clientId && $row['fd'] === $fd) {
                unset(self::$localFds[$otherId]);
                Store::del(self::TABLE, $otherId);
            }
        }

        self::$localFds[$clientId] = ['fd' => $fd, 'conn_id' => $connId];
        Store::set(self::TABLE, $clientId, [
            'server_id' => self::$serverId,
            'conn_id'   => $connId,
        ]);
        return $connId;
    }

    /** Clean up when a client disconnects (call from ws onClose). */
    public static function release(string $clientId): void
    {
        unset(self::$localFds[$clientId]);
        Store::del(self::TABLE, $clientId);
    }

    /**
     * Send to a specific client by id, regardless of which server holds
     * them. Returns true if the publish was issued (delivery still
     * best-effort + subject to the conn_id verification on the receiver),
     * false if the client isn't connected anywhere we know about.
     *
     * The payload carries the conn_id captured at the time of THIS
     * lookup. If the client reconnects between this publish and its
     * delivery, the subscriber on the owning server detects the conn_id
     * mismatch and silently drops the stale message (C1 fix).
     */
    public static function sendToClient(string $clientId, string $payload): bool
    {
        $owner = Store::get(self::TABLE, $clientId);
        if (!is_array($owner)) { return false; }
        $serverId = is_string($owner['server_id'] ?? null) ? $owner['server_id'] : '';
        $connId   = is_string($owner['conn_id']   ?? null) ? $owner['conn_id']   : '';
        if ($serverId === '') { return false; }
        Store::publish('ws:server:' . $serverId, (string) json_encode([
            'client_id' => $clientId,
            'conn_id'   => $connId,
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

    /**
     * @internal — current local fd-map snapshot (for tests + debug).
     * @return array<string, array{fd:int, conn_id:string}>
     */
    public static function localFds(): array { return self::$localFds; }
}
