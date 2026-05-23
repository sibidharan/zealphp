<?php

declare(strict_types=1);

namespace ZealPHP;

use ZealPHP\Store\StoreException;
use ZealPHP\WS\CapacityException;
use ZealPHP\WS\Room;

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
 * App::subscribe.
 */
final class WSRouter
{
    private const TABLE = 'ws_owner';
    private const ROOM_TABLE = 'ws_room_members';
    private const SERVERS_TABLE = 'ws_servers';
    private const ROOM_CHANNEL_PREFIX = 'ws:room:';
    private const SERVER_HEARTBEAT_INTERVAL_MS = 30_000;
    private const SERVER_GC_INTERVAL_MS        = 60_000;
    private const SERVER_STALE_AFTER_SEC       = 90;

    // ── WS-5: WebSocket close-code constants ────────────────────────────
    //
    // Standard codes (1000-1099 — RFC 6455 + IANA) for normal lifecycle:
    /** Normal closure (peer is closing as expected). */
    public const CLOSE_NORMAL              = 1000;
    /** Server is going down (or client is navigating away). */
    public const CLOSE_GOING_AWAY          = 1001;
    /** Peer sent a malformed frame / protocol violation. */
    public const CLOSE_PROTOCOL_ERROR      = 1002;
    /** Peer sent a frame of a type the endpoint can't accept. */
    public const CLOSE_UNSUPPORTED         = 1003;
    /** Peer sent a message that violates server policy. */
    public const CLOSE_POLICY_VIOLATION    = 1008;
    /** Message too big for the receiver to process. */
    public const CLOSE_MESSAGE_TOO_BIG     = 1009;
    /** Server is temporarily overloaded — client should retry later. */
    public const CLOSE_TRY_AGAIN_LATER     = 1013;
    /** Server hit an internal error processing the request. */
    public const CLOSE_INTERNAL_ERROR      = 1011;
    //
    // Application range (4000-4999) — owned by ZealPHP / your app. Use
    // these for semantic close reasons clients can react to specifically:
    /** Auth required: client connected but never authenticated. */
    public const CLOSE_AUTH_REQUIRED       = 4001;
    /** Auth failed: bad token, expired session, etc. */
    public const CLOSE_AUTH_INVALID        = 4002;
    /** Authenticated but lacking permission for this operation. */
    public const CLOSE_FORBIDDEN           = 4003;
    /** Server is over capacity (paired with CapacityException). */
    public const CLOSE_CAPACITY            = 4013;
    /** Client breached the per-client rate limit (WS-4). */
    public const CLOSE_RATE_LIMITED        = 4029;
    /** Connection idle / heartbeat missed beyond threshold. */
    public const CLOSE_IDLE                = 4040;

    /** Max connections per cluster — bump via initOptions() for prod. */
    private static int $ownerCapacity = 4096;
    /** Max (room × member) pairs per cluster — bump via initOptions() for prod. */
    private static int $roomMembersCapacity = 16384;
    /**
     * Slow-consumer threshold. If `$server->getClientInfo($fd)['send_queue_bytes']`
     * exceeds this, the framework DROPS the message for that fd instead of
     * letting the kernel/OpenSwoole buffer grow unbounded. Tracked in
     * `stats()['pushes_dropped_slow_consumer']`. Default 4 MB; tune via
     * `initOptions(slowConsumerBytes: N)`.
     */
    private static int $slowConsumerBytes = 4 * 1024 * 1024;
    /** Per-room rate limit — 0 disables. Set via `setRoomRateLimit($n, $windowSec)`. */
    private static int $roomRateLimitN = 0;
    private static int $roomRateLimitWindowSec = 60;
    /** Per-client rate limit (WS-4) — 0 disables. Set via `setClientRateLimit`. */
    private static int $clientRateLimitN = 0;
    private static int $clientRateLimitWindowSec = 60;
    /** Per-channel HMAC secret (WS-3) — null disables. Set via `setChannelHmacSecret`. */
    private static ?string $channelHmacSecret = null;
    /** Per-worker counter struct. Surfaced via `WSRouter::stats()`. */
    private static ?\ZealPHP\Store\Stats $stats = null;

    private static string $serverId = '';
    /** @var array<string, array{fd:int, conn_id:string}> client_id → {fd, conn_id} */
    private static array $localFds = [];
    /** @var ?callable(string $clientId, int $fd, string $payload): void */
    private static $clientSink = null;
    /** True once init() has wired the subscriber. */
    private static bool $initialized = false;

    // Room state — per-worker.
    //
    // $localRoomMembership[$room][$clientId] = true   (clients in this room
    //   that ARE locally owned by this worker — push targets when a
    //   message arrives). Populated via presence events delivered to the
    //   pattern subscriber.
    //
    // $roomMessageHandlers[$room][] = callable    (user-registered)
    // $roomPresenceHandlers[$room][] = callable   (user-registered)
    /** @var array<string, array<string, true>> */
    private static array $localRoomMembership = [];
    /** @var array<string, list<callable>> */
    private static array $roomMessageHandlers = [];
    /** @var array<string, list<callable>> */
    private static array $roomPresenceHandlers = [];

    /**
     * One-time setup. Pass a server id (defaults to hostname:pid) +
     * an optional callable that handles inbound routed messages.
     * The default sink looks up the local fd map and pushes via
     * `App::getServer()->push($fd, $payload)` (skipping with elog
     * when the client isn't local OR the fd is no longer established).
     */
    /**
     * Bump the per-cluster capacity caps BEFORE init() — these size the
     * underlying `OpenSwoole\Table` segments allocated at master fork.
     * Defaults (4096 owners / 16384 room members) are demo-grade; production
     * deployments should size these against expected peak.
     *
     * Example:
     *     WSRouter::initOptions(ownerCapacity: 200_000, roomMembersCapacity: 1_000_000);
     *     WSRouter::init();
     */
    public static function initOptions(
        ?int $ownerCapacity = null,
        ?int $roomMembersCapacity = null,
        ?int $slowConsumerBytes = null,
    ): void {
        if ($ownerCapacity !== null) {
            if ($ownerCapacity < 1) {
                throw new \InvalidArgumentException('WSRouter::initOptions: $ownerCapacity must be >= 1');
            }
            self::$ownerCapacity = $ownerCapacity;
        }
        if ($roomMembersCapacity !== null) {
            if ($roomMembersCapacity < 1) {
                throw new \InvalidArgumentException('WSRouter::initOptions: $roomMembersCapacity must be >= 1');
            }
            self::$roomMembersCapacity = $roomMembersCapacity;
        }
        if ($slowConsumerBytes !== null) {
            if ($slowConsumerBytes < 1024) {
                throw new \InvalidArgumentException('WSRouter::initOptions: $slowConsumerBytes must be >= 1024');
            }
            self::$slowConsumerBytes = $slowConsumerBytes;
        }
    }

    /**
     * Configure per-room push rate limiting. Default is unlimited.
     *
     *     WSRouter::setRoomRateLimit(100, 60);   // 100 pushes / 60s / room
     *     WSRouter::setRoomRateLimit(0);          // disable
     *
     * Counts live in a Store counter keyed `{room}:rl:{window}` — cluster-wide
     * when the Store backend is Redis. Returns true when push allowed.
     * Drops increment `rate_limit_drops_total` in `stats()`.
     */
    public static function setRoomRateLimit(int $n, int $windowSec = 60): void
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('setRoomRateLimit: $n must be >= 0');
        }
        if ($windowSec < 1) {
            throw new \InvalidArgumentException('setRoomRateLimit: $windowSec must be >= 1');
        }
        self::$roomRateLimitN = $n;
        self::$roomRateLimitWindowSec = $windowSec;
    }

    /**
     * WS-4 — per-client rate limit. Throttles `sendToClient` AND `Room::push`
     * attributed to a single client. Sliding-window backed by `Counter`.
     * 0 (default) disables. Pair with the `setRoomRateLimit` per-room cap.
     *
     *     WSRouter::setClientRateLimit(50, 10);  // 50 ops / 10s / client
     */
    public static function setClientRateLimit(int $n, int $windowSec = 60): void
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('setClientRateLimit: $n must be >= 0');
        }
        if ($windowSec < 1) {
            throw new \InvalidArgumentException('setClientRateLimit: $windowSec must be >= 1');
        }
        self::$clientRateLimitN = $n;
        self::$clientRateLimitWindowSec = $windowSec;
    }

    /** @internal — used by Room::push to gate per-client rate */
    public static function clientRateLimitN(): int { return self::$clientRateLimitN; }

    /**
     * Returns true when the client is under the rate limit (or limits
     * disabled). Increments the bucket counter atomically.
     */
    public static function checkClientRate(string $clientId): bool
    {
        $n = self::$clientRateLimitN;
        if ($n === 0 || $clientId === '') { return true; }
        $window = self::$clientRateLimitWindowSec;
        $bucket = (int) (time() / $window);
        $name   = '_wsrouter_cl_' . substr(sha1($clientId . ':' . $bucket), 0, 16);
        $c      = new \ZealPHP\Counter(0, $name);
        $now    = $c->increment();
        if ($now > $n) {
            self::stats()->inc('client_rate_limit_drops_total');
            return false;
        }
        return true;
    }

    /**
     * WS-3 — set a shared HMAC secret for pub/sub channel authentication.
     * Every server in the cluster MUST share the same secret. Once set:
     *   - `sendToClient` + `Room::push` publishes are wrapped in a signed
     *     envelope `{v:1, hmac, payload}`.
     *   - Receivers (the per-server + per-room subscribers) verify the
     *     HMAC and silently drop messages with bad/missing signatures
     *     (bumps `hmac_verify_failures_total` in stats).
     *
     * Defeats the "anyone with Redis write access can forge a routed
     * message" attack: a peer that doesn't know the secret cannot
     * spoof messages onto `ws:server:*` or `ws:room:*` channels.
     *
     * Pass `null` to disable (default). Read via env in your app.php:
     *
     *     WSRouter::setChannelHmacSecret(getenv('ZEALPHP_WS_HMAC') ?: null);
     */
    public static function setChannelHmacSecret(?string $secret): void
    {
        self::$channelHmacSecret = ($secret === '' || $secret === null) ? null : $secret;
    }

    /** @internal — sign a payload for publish. Passthrough when secret unset. */
    public static function signPayload(string $payload): string
    {
        if (self::$channelHmacSecret === null) { return $payload; }
        $mac = substr(hash_hmac('sha256', $payload, self::$channelHmacSecret), 0, 32);
        return (string) json_encode(['v' => 1, 'hmac' => $mac, 'payload' => $payload]);
    }

    /**
     * @internal — verify a published envelope. Returns the inner payload
     * when valid (or when no secret is configured — passthrough); returns
     * NULL when the envelope is signed-but-mismatched and the caller
     * should drop the message.
     */
    public static function verifyPayload(string $envelope): ?string
    {
        if (self::$channelHmacSecret === null) { return $envelope; }
        $msg = json_decode($envelope, true);
        if (!is_array($msg) || ($msg['v'] ?? null) !== 1) {
            self::stats()->inc('hmac_verify_failures_total');
            return null;
        }
        $mac   = is_string($msg['hmac']    ?? null) ? $msg['hmac']    : '';
        $inner = is_string($msg['payload'] ?? null) ? $msg['payload'] : '';
        if ($mac === '' || $inner === '') {
            self::stats()->inc('hmac_verify_failures_total');
            return null;
        }
        $expected = substr(hash_hmac('sha256', $inner, self::$channelHmacSecret), 0, 32);
        if (!hash_equals($expected, $mac)) {
            self::stats()->inc('hmac_verify_failures_total');
            return null;
        }
        return $inner;
    }

    /**
     * One-time WSRouter bootstrap. Wires the cluster-wide ownership table,
     * room-membership table, per-server identity subscriber, and per-worker
     * lifecycle hooks (heartbeat + GC + graceful sweep). Idempotent —
     * subsequent calls are no-ops.
     *
     * MUST be called BEFORE `App::run()` so the Store tables can be
     * allocated in the master process before workers fork. The Redis
     * backend is also acceptable (state lives in Redis instead of Table).
     *
     * Capacity + tunable options can be passed inline; alternatively call
     * `WSRouter::initOptions()` BEFORE `init()` (same setters, different
     * ergonomic shape — pick whichever reads cleaner).
     *
     * ```php
     * // Inline (most concise):
     * WSRouter::init(
     *     ownerCapacity:        200_000,
     *     roomMembersCapacity:  1_000_000,
     *     slowConsumerBytes:    8 * 1024 * 1024,
     * );
     *
     * // Or split (when you want a custom serverId / sink + tuning):
     * WSRouter::initOptions(ownerCapacity: 200_000, roomMembersCapacity: 1_000_000);
     * WSRouter::init('node-A', $myCustomSink);
     * ```
     *
     * @param ?string        $serverId             Cluster-unique server id; defaults to `hostname:pid`.
     * @param ?callable      $clientSink           Inbound-routed-message handler; defaults to a backpressure-aware `$server->push`.
     * @param ?int           $ownerCapacity        Max concurrent WS connections cluster-wide (default 4096, Table HARD CAP).
     * @param ?int           $roomMembersCapacity  Max `(room, member)` pairs cluster-wide (default 16384, Table HARD CAP).
     * @param ?int           $slowConsumerBytes    Per-fd send_queue_bytes threshold above which `pushWithBackpressure` drops + tracks (default 4 MB).
     */
    public static function init(
        ?string   $serverId = null,
        ?callable $clientSink = null,
        ?int      $ownerCapacity = null,
        ?int      $roomMembersCapacity = null,
        ?int      $slowConsumerBytes = null,
    ): void {
        // Inline options route through initOptions() for validation + storage —
        // single source of truth, no logic duplication.
        if ($ownerCapacity !== null || $roomMembersCapacity !== null || $slowConsumerBytes !== null) {
            self::initOptions(
                ownerCapacity:       $ownerCapacity,
                roomMembersCapacity: $roomMembersCapacity,
                slowConsumerBytes:   $slowConsumerBytes,
            );
        }
        self::$serverId   = $serverId ?? gethostname() . ':' . getmypid();
        self::$stats     ??= new \ZealPHP\Store\Stats();
        self::$clientSink = $clientSink ?? function (string $clientId, int $fd, string $payload): void {
            $server = App::getServer();
            // Only the WebSocket\Server variant has isEstablished + push;
            // the HTTP-only return is unreachable here in practice (WS routes
            // require the WS server) but the instanceof keeps PHPStan honest.
            if (!($server instanceof \OpenSwoole\WebSocket\Server)) { return; }
            // WS-1: route through the backpressure-aware push. A slow / dead
            // consumer's accumulated TCP send queue can't push the server
            // toward OOM, and the drop is surfaced in stats
            // (pushes_dropped_slow_consumer). Pre-WS-1, sendToClient'd
            // payloads bypassed this guard — only Room::push fan-out used it.
            self::pushWithBackpressure($server, $fd, $payload);
        };

        if (self::$initialized) { return; }
        self::$initialized = true;

        // Shared ownership table. conn_id is a per-connection nonce — see
        // own() + sendToClient() for the FD-reuse-race fix (C1).
        // server_id is `hostname:pid` by default; FQDNs + k8s pod names
        // + long PIDs blow past 64 chars — size at 192 to cover common
        // production cases (k8s pod hash + namespace + cluster suffix +
        // pid easily reaches 100+).
        Store::make(self::TABLE, self::$ownerCapacity, [
            'server_id' => [Store::TYPE_STRING, 192],
            'conn_id'   => [Store::TYPE_STRING, 32],
        ]);

        // Room membership table — cluster-wide. Keyed by `{room}:{client_id}`
        // so Store::iterate + filter-by-row['room'] gives a roster + size
        // per room. See WSRouter\Room for the user-facing API.
        Store::make(self::ROOM_TABLE, self::$roomMembersCapacity, [
            'room'      => [Store::TYPE_STRING, 64],
            'client_id' => [Store::TYPE_STRING, 128],
            'server_id' => [Store::TYPE_STRING, 192],
            'joined_at' => [Store::TYPE_INT, 8],
        ]);

        // Server registry — each server writes its own row at boot + refreshes
        // it every SERVER_HEARTBEAT_INTERVAL_MS. The GC sweep drops rows older
        // than SERVER_STALE_AFTER_SEC (default 90s) and reaps the ws_owner /
        // ws_room_members rows that referenced them. Covers BOTH graceful
        // shutdown (via onWorkerStop) AND hard crashes (via the periodic GC).
        Store::make(self::SERVERS_TABLE, 256, [
            'last_seen' => [Store::TYPE_INT, 8],
            'host'      => [Store::TYPE_STRING, 128],
            'pid'       => [Store::TYPE_INT, 8],
        ]);

        // Land our own row IMMEDIATELY so callers (e.g. an explicit
        // App::stats() between init() and run()) see a populated registry.
        // The onWorkerStart hook below adds a periodic refresh; this just
        // closes the boot-window gap.
        self::writeServerRegistryRow();

        // Single PSUBSCRIBE pattern covers every room — no per-room
        // subscriber proliferation. Handler dispatches to user-registered
        // message/presence handlers + maintains the per-worker local
        // membership cache.
        App::subscribe(self::ROOM_CHANNEL_PREFIX . '*', function (string $envelope, string $channel): void {
            // WS-3: unwrap signed envelope when a secret is configured;
            // drop silently (with stats bump) on bad/missing signature.
            $payload = self::verifyPayload($envelope);
            if ($payload === null) { return; }
            self::handleRoomMessage($channel, $payload);
        });

        // Subscribe to OUR identity channel. Inbound messages have
        // {client_id, conn_id, payload}; the configured sink does the
        // local push iff the conn_id matches the currently-held connection.
        App::subscribe('ws:server:' . self::$serverId, function (string $envelope): void {
            // WS-3 verification (mirror of the room subscriber above).
            $payload = self::verifyPayload($envelope);
            if ($payload === null) { return; }
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

        // Per-worker lifecycle hooks — heartbeat + GC + graceful sweep.
        // onWorkerStart runs IN a coroutine (HOOK_ALL active) so Store ops yield.
        App::onWorkerStart(function (int $workerId): void {
            // Register THIS process in the server registry. Refresh row on
            // every heartbeat tick — GC drops rows older than SERVER_STALE_AFTER_SEC.
            self::writeServerRegistryRow();
            App::tick(self::SERVER_HEARTBEAT_INTERVAL_MS, function (): void {
                self::writeServerRegistryRow();
            });
            // ONE worker per server runs the GC sweep — avoid N workers all
            // scanning simultaneously. Worker 0 by convention.
            if ($workerId === 0) {
                App::tick(self::SERVER_GC_INTERVAL_MS, function (): void {
                    self::runStaleServerGC();
                });
            }
        });

        // Graceful-stop sweeper — drop this server's rows AND its server-
        // registry row when worker 0 shuts down cleanly. Hard crashes are
        // covered by the periodic GC above.
        App::onWorkerStop(function (int $workerId): void {
            if ($workerId === 0) {
                self::sweepThisServer();
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
        $ok = Store::set(self::TABLE, $clientId, [
            'server_id' => self::$serverId,
            'conn_id'   => $connId,
        ]);
        if (!$ok) {
            // Two cases yield Store::set === false on Table backend:
            //   (a) table actually full — Table::count() at $ownerCapacity.
            //   (b) row didn't fit — a column value exceeded its declared
            //       size (TableRow::set_value logs "string value is too
            //       long" to stderr; OpenSwoole returns false rather than
            //       truncating). Common cause: $serverId derives from
            //       gethostname() . ':' . getmypid() and FQDNs / k8s pod
            //       names blow past the column's declared length.
            // Distinguish the two so the error message is actionable.
            unset(self::$localFds[$clientId]);   // roll back local map
            self::stats()->inc('capacity_exceeded_owner_total');
            $count = Store::count(self::TABLE);
            $isFull = $count >= self::$ownerCapacity;
            $detail = $isFull
                ? "table full at " . self::$ownerCapacity . " connections — " .
                  "bump via WSRouter::init(ownerCapacity: N), WSRouter::initOptions(), or flip to Redis backend"
                : "row didn't fit (table at $count/" . self::$ownerCapacity . ") — " .
                  "likely a column value exceeded its declared size. server_id='" . self::$serverId .
                  "' (length " . strlen(self::$serverId) . "). On Table backend the column is 192 chars; " .
                  "very long pod names + namespaces may exceed this. Flip to Redis backend " .
                  "(`Store::defaultBackend(Store::BACKEND_REDIS)`) for unlimited row sizing.";
            throw new CapacityException("WSRouter: ws_owner $detail");
        }
        self::stats()->inc('owns_total');
        return $connId;
    }

    /** Clean up when a client disconnects (call from ws onClose). */
    public static function release(string $clientId): void
    {
        unset(self::$localFds[$clientId]);
        Store::del(self::TABLE, $clientId);
        self::stats()->inc('releases_total');
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
        // WS-4: per-client rate limit check BEFORE the lookup. Drops loud
        // clients early without spending a Store::get round-trip on every
        // throttled send.
        if (!self::checkClientRate($clientId)) {
            return false;
        }
        $owner = Store::get(self::TABLE, $clientId);
        if (!is_array($owner)) {
            self::stats()->inc('sendToClient_owner_missing');
            return false;
        }
        $serverId = is_string($owner['server_id'] ?? null) ? $owner['server_id'] : '';
        $connId   = is_string($owner['conn_id']   ?? null) ? $owner['conn_id']   : '';
        if ($serverId === '') {
            self::stats()->inc('sendToClient_owner_missing');
            return false;
        }
        $json = (string) json_encode([
            'client_id' => $clientId,
            'conn_id'   => $connId,
            'payload'   => $payload,
        ]);
        // WS-3: sign the envelope if a channel HMAC secret is configured.
        Store::publish('ws:server:' . $serverId, self::signPayload($json));
        self::stats()->inc('sendToClient_total');
        return true;
    }

    /**
     * Publish to a room channel. Every server with a matching
     * `App::subscribe($channel, ...)` handler fans out to its local
     * clients. Returns the receiver count Redis reported.
     */
    public static function broadcast(string $channel, string $payload): int
    {
        return Store::publish($channel, $payload);
    }

    /**
     * Sugar over `App::subscribe($channel, ...)` — use when you want
     * the room-channel registration to read more naturally alongside
     * the routing calls above.
     */
    public static function onRoom(string $channel, callable $handler): void
    {
        App::subscribe($channel, $handler);
    }

    /** @internal — testing hook to reset module state between cases. */
    public static function reset(): void
    {
        self::$serverId            = '';
        self::$localFds            = [];
        self::$clientSink          = null;
        self::$initialized         = false;
        self::$localRoomMembership = [];
        self::$roomMessageHandlers = [];
        self::$roomPresenceHandlers= [];
    }

    /**
     * @internal — current local fd-map snapshot (for tests + debug).
     * @return array<string, array{fd:int, conn_id:string}>
     */
    public static function localFds(): array { return self::$localFds; }

    /**
     * Construct a Room handle. The Room itself is a value object that
     * delegates to WSRouter's static state for actual lifecycle.
     */
    public static function room(string $name): Room
    {
        if (self::$serverId === '') {
            throw new StoreException('WSRouter::init() must be called before WSRouter::room()');
        }
        return new Room($name, self::$serverId);
    }

    /**
     * Total connected clients across the cluster.
     *
     * O(1) on both backends — `Store::count()` reads the membership-set
     * cardinality (Atomic counter on Table, SCARD on Redis). Cheap enough
     * to call from `/healthz` on every request.
     *
     * NOTE: a "client" here is one row in `ws_owner` — one logical
     * connection. A user with 3 open tabs counts as 3.
     */
    public static function onlineCount(): int
    {
        if (self::$serverId === '') {
            throw new StoreException('WSRouter::init() must be called before onlineCount()');
        }
        return Store::count(self::TABLE);
    }

    /**
     * Per-server connection counts. Iterates `ws_owner` once and groups by
     * `server_id` — O(N_total_clients). Useful for dashboards / debugging
     * load-balancer imbalance; pricier than `onlineCount()`, so don't call
     * on every request.
     *
     * @return array<string, int>  server_id → count
     */
    public static function onlineByServer(): array
    {
        if (self::$serverId === '') {
            throw new StoreException('WSRouter::init() must be called before onlineByServer()');
        }
        $out = [];
        foreach (Store::iterate(self::TABLE) as $_clientId => $row) {
            $sid = is_string($row['server_id'] ?? null) ? $row['server_id'] : '';
            if ($sid === '') { continue; }
            $out[$sid] = ($out[$sid] ?? 0) + 1;
        }
        return $out;
    }

    /** @internal — name of the cluster-wide membership table. */
    public static function roomTable(): string { return self::ROOM_TABLE; }

    /** @internal — name of the cluster-wide ownership table (client_id → server_id, conn_id). */
    public static function ownerTable(): string { return self::TABLE; }

    /** @internal — name of the server-registry table (server_id → last_seen, host, pid). */
    public static function serverTable(): string { return self::SERVERS_TABLE; }

    /** @internal — channel prefix used for room pub/sub. */
    public static function roomChannelPrefix(): string { return self::ROOM_CHANNEL_PREFIX; }

    /**
     * @internal — WS-2: per-room Redis SET key for O(1) SCARD + paginated
     * SSCAN. Maintained alongside the ws_room_members metadata table when
     * the active Store backend is Redis (or Tiered). Falls back gracefully
     * on Table backend (Room::size/members iterate the metadata table).
     */
    public static function roomMembersSetKey(string $room): string
    {
        return 'ws_room:' . $room . ':members';
    }

    /**
     * @internal — true when the active Store backend supports direct
     * Redis SET ops (Redis or Tiered). Room class uses this to choose
     * the per-room SET fast path vs the iterate fallback.
     */
    public static function hasRedisBackend(): bool
    {
        $b = Store::defaultBackend();
        return $b instanceof \ZealPHP\Store\RedisBackend
            || $b instanceof \ZealPHP\Store\TieredBackend;
    }

    /** @internal — rate-limit threshold accessor (used by WSRouter\Room::push). */
    public static function roomRateLimitN(): int { return self::$roomRateLimitN; }

    /** @internal — rate-limit window accessor (used by WSRouter\Room::push). */
    public static function roomRateLimitWindowSec(): int { return self::$roomRateLimitWindowSec; }

    /**
     * @internal — register a user-side handler for ROOM messages.
     * Called from `Room::onMessage()`.
     * @param callable(array<string,mixed> $msg, string $room): void $handler
     */
    public static function registerRoomMessageHandler(string $room, callable $handler): void
    {
        self::$roomMessageHandlers[$room][] = $handler;
    }

    /**
     * @internal — register a user-side handler for join/leave events.
     * Called from `Room::onPresence()`.
     * @param callable(array<string,mixed> $event, string $room): void $handler
     */
    public static function registerRoomPresenceHandler(string $room, callable $handler): void
    {
        self::$roomPresenceHandlers[$room][] = $handler;
    }

    /**
     * @internal — dispatch a received room-channel pub/sub message. The
     * pattern subscriber spawned in `init()` calls this with the resolved
     * channel name + raw payload.
     */
    public static function handleRoomMessage(string $channel, string $payload): void
    {
        $prefix = self::ROOM_CHANNEL_PREFIX;
        if (!str_starts_with($channel, $prefix)) { return; }
        $roomName = substr($channel, strlen($prefix));

        $msg = json_decode($payload, true);
        if (!is_array($msg)) { return; }

        $type = is_string($msg['type'] ?? null) ? $msg['type'] : 'message';

        if ($type === 'join' || $type === 'leave') {
            $cid = is_string($msg['client_id'] ?? null) ? $msg['client_id'] : '';
            if ($cid !== '' && isset(self::$localFds[$cid])) {
                if ($type === 'join') {
                    self::$localRoomMembership[$roomName][$cid] = true;
                } else {
                    unset(self::$localRoomMembership[$roomName][$cid]);
                    if (isset(self::$localRoomMembership[$roomName]) && self::$localRoomMembership[$roomName] === []) {
                        unset(self::$localRoomMembership[$roomName]);
                    }
                }
            }
            // Fire user presence handlers.
            foreach (self::$roomPresenceHandlers[$roomName] ?? [] as $fn) {
                try { $fn($msg, $roomName); }
                catch (\Throwable $e) {
                    error_log("WSRouter room presence handler for {$roomName}: " . $e->getMessage());
                }
            }
            return;
        }

        // Regular room message — fire user handlers + push to local members.
        foreach (self::$roomMessageHandlers[$roomName] ?? [] as $fn) {
            try { $fn($msg, $roomName); }
            catch (\Throwable $e) {
                error_log("WSRouter room message handler for {$roomName}: " . $e->getMessage());
            }
        }

        // Push to local members of this room (this worker's local fds).
        $localMembers = self::$localRoomMembership[$roomName] ?? [];
        if ($localMembers === []) { return; }
        $server = App::getServer();
        if (!($server instanceof \OpenSwoole\WebSocket\Server)) { return; }
        $data = (string) json_encode($msg);
        foreach (array_keys($localMembers) as $cid) {
            $local = self::$localFds[$cid] ?? null;
            if ($local === null) { continue; }
            $fd = $local['fd'];
            // Each push runs in its own coroutine so a slow consumer's
            // back-pressure (TCP buffer full → push waits) can't block the
            // others. The push call itself is non-blocking in OpenSwoole,
            // but getClientInfo + the dispatch path are not free; the go()
            // wrap keeps fan-out parallel.
            \OpenSwoole\Coroutine::create(function () use ($server, $fd, $data): void {
                self::pushWithBackpressure($server, $fd, $data);
            });
        }
    }

    /**
     * Backpressure-aware push. Drops the message for this fd when the
     * outbound send queue is over `$slowConsumerBytes` — a slow / dead
     * consumer can't drag the server into OOM by accumulating per-fd
     * buffers forever. Tracks drops in stats.
     *
     * @internal — also used by sendToClient + future Room push paths.
     */
    public static function pushWithBackpressure(\OpenSwoole\WebSocket\Server $server, int $fd, string $data): bool
    {
        if (!$server->isEstablished($fd)) {
            self::stats()->inc('pushes_to_dead_fd_total');
            return false;
        }
        $info = $server->getClientInfo($fd);
        $queued = is_array($info) && isset($info['send_queue_bytes']) && is_numeric($info['send_queue_bytes'])
            ? (int) $info['send_queue_bytes']
            : 0;
        if ($queued > self::$slowConsumerBytes) {
            self::stats()->inc('pushes_dropped_slow_consumer');
            return false;
        }
        $ok = $server->push($fd, $data);
        self::stats()->inc($ok ? 'pushes_total' : 'pushes_failed_total');
        return (bool) $ok;
    }

    /**
     * Per-worker WSRouter counter struct. Call `->snapshot()` to read
     * the current counters as `array<string,int>`. Pair with
     * `Store::stats()` for pool/pubsub-side metrics + `App::stats()` for
     * worker/memory state.
     *
     * Counters surfaced:
     *   owns_total / releases_total
     *   sendToClient_total / sendToClient_owner_missing
     *   room_joins_total / room_leaves_total / room_pushes_total
     *   pushes_total / pushes_failed_total
     *   pushes_dropped_slow_consumer / pushes_to_dead_fd_total
     *   capacity_exceeded_owner_total / capacity_exceeded_room_total
     *   rate_limit_drops_total
     */
    public static function stats(): \ZealPHP\Store\Stats
    {
        return self::$stats ??= new \ZealPHP\Store\Stats();
    }

    /**
     * @internal — current per-worker local-room cache snapshot (for tests).
     * @return array<string, array<string, true>>
     */
    public static function localRoomMembership(): array { return self::$localRoomMembership; }

    // ── ServerRegistry: stale-server GC for hard-crash recovery ───────────
    //
    // Each ZealPHP process registers itself in `ws_servers` (server_id →
    // last_seen). A per-worker tick refreshes that row every 30s. The GC
    // tick (one server, worker 0) scans for rows older than 90s, drops them,
    // and reaps the ws_owner + ws_room_members rows that referenced those
    // dead server_ids.
    //
    // Covers:
    //   - planned shutdown — onWorkerStop's sweep drops own + server rows
    //   - hard crash       — GC eventually notices + reaps within ~2× heartbeat
    //
    // Skipped on Table backend (single-server; if the master is dead the
    // shared memory dies with it, no cleanup possible or needed).

    /** @internal — refresh this server's row in the registry. */
    public static function writeServerRegistryRow(): void
    {
        if (self::$serverId === '') { return; }
        Store::set(self::SERVERS_TABLE, self::$serverId, [
            'last_seen' => time(),
            'host'      => (string) gethostname(),
            'pid'       => getmypid(),
        ]);
    }

    /** @internal — drop rows for servers we haven't heard from in $staleAfter sec. */
    public static function runStaleServerGC(int $staleAfter = self::SERVER_STALE_AFTER_SEC): int
    {
        $threshold = time() - $staleAfter;
        $dead      = [];
        foreach (Store::iterate(self::SERVERS_TABLE) as $serverId => $row) {
            $seen = is_numeric($row['last_seen'] ?? null) ? (int) $row['last_seen'] : 0;
            if ($seen < $threshold) {
                $dead[] = (string) $serverId;
            }
        }
        if ($dead === []) { return 0; }
        $reaped = 0;
        // Drop dependent rows in ws_owner + ws_room_members.
        foreach (Store::iterate(self::TABLE) as $clientId => $row) {
            if (in_array((string) ($row['server_id'] ?? ''), $dead, true)) {
                Store::del(self::TABLE, (string) $clientId);
                $reaped++;
            }
        }
        foreach (Store::iterate(self::ROOM_TABLE) as $key => $row) {
            if (in_array((string) ($row['server_id'] ?? ''), $dead, true)) {
                Store::del(self::ROOM_TABLE, (string) $key);
                $reaped++;
            }
        }
        // Drop the server-registry rows themselves last.
        foreach ($dead as $serverId) {
            Store::del(self::SERVERS_TABLE, $serverId);
        }
        if (function_exists('elog')) {
            elog("WSRouter GC: reaped " . count($dead) . " dead servers, $reaped dependent rows", 'info');
        }
        return $reaped;
    }

    /** @internal — graceful onWorkerStop sweep: drop THIS server's footprint. */
    public static function sweepThisServer(): int
    {
        if (self::$serverId === '') { return 0; }
        $reaped = 0;
        foreach (Store::iterate(self::TABLE) as $clientId => $row) {
            if (($row['server_id'] ?? '') === self::$serverId) {
                Store::del(self::TABLE, (string) $clientId);
                $reaped++;
            }
        }
        foreach (Store::iterate(self::ROOM_TABLE) as $key => $row) {
            if (($row['server_id'] ?? '') === self::$serverId) {
                Store::del(self::ROOM_TABLE, (string) $key);
                $reaped++;
            }
        }
        Store::del(self::SERVERS_TABLE, self::$serverId);
        return $reaped;
    }
}
