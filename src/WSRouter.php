<?php

declare(strict_types=1);

namespace ZealPHP;

use ZealPHP\Store\StoreException;
use ZealPHP\WS\CapacityException;
use ZealPHP\WS\Room;
use ZealPHP\WS\WSAuthException;

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
    /**
     * WS-6 — max simultaneous push coroutines per room broadcast. Bounds the
     * fan-out so a large room + message burst can't spawn an unbounded number
     * of concurrent push coroutines (each push call is non-blocking, but the
     * `getClientInfo` + dispatch path is not free, and unbounded `go()` per
     * member is the keystone backpressure gap). Pushes still run concurrently
     * (per-coroutine isolation preserved) — only the SIMULTANEITY is capped via
     * a `Coroutine\Channel` token semaphore. Configure via `setFanoutConcurrency`.
     */
    private static int $fanoutConcurrency = 128;
    /** Per-channel HMAC secret (WS-3) — null disables. Set via `setChannelHmacSecret`. */
    private static ?string $channelHmacSecret = null;
    /** Per-worker counter struct. Surfaced via `WSRouter::stats()`. Lazily initialised on first access. */
    private static ?\ZealPHP\Store\Stats $stats = null;

    /** Cluster-unique server identifier — defaults to `hostname:pid`. Set by `init()`. */
    private static string $serverId = '';

    /**
     * Per-worker map of locally-owned WebSocket connections.
     * `client_id → ['fd' => int, 'conn_id' => string]`
     *
     * @var array<string, array{fd:int, conn_id:string}>
     */
    private static array $localFds = [];

    /**
     * Inbound-routed-message handler. Receives `($clientId, $fd, $payload)`
     * and is responsible for pushing `$payload` to the local fd.
     * Defaults to a backpressure-aware `$server->push()` installed by `init()`.
     *
     * @var ?callable(string $clientId, int $fd, string $payload): void
     */
    private static $clientSink = null;

    /** `true` once `init()` has wired the pub/sub subscribers and lifecycle hooks. */
    private static bool $initialized = false;

    /**
     * #234 — optional per-room authorizer. `fn(string $action, string $room,
     * string $clientId): bool` where `$action ∈ {join,leave,push,read}`. When
     * SET, every `Room` op consults it FAIL-CLOSED (a falsey return refuses);
     * when null (default) the room layer is unguarded as before (BC). Wire it to
     * your session auth — e.g. `WSRouter::roomAuthorizer(fn($a,$r,$c) =>
     * App::authChecker() && (App::authChecker())())`.
     *
     * @var (callable(string,string,string):bool)|null
     */
    private static $roomAuthorizer = null;

    /**
     * #234 — server-derived principal bound to a connection at
     * `ownAuthenticated()`, keyed by fd. Lets later `onMessage` handlers recover
     * the authenticated identity (`principalForFd($fd)`) without re-reading the
     * session, and ties a `client_id` to the authenticated session rather than a
     * client-supplied string. Reaped in `release()`.
     *
     * @var array<int, string>
     */
    private static array $principalByFd = [];

    /**
     * WS-7 — per-worker record of the window id each rate-limit counter is
     * currently serving. A sliding-window limiter rotates buckets every
     * `$windowSec`; the PRE-WS-7 code baked the bucket id into the counter
     * NAME (`..._{hash}_{bucket}`), so on the default Atomic backend every
     * elapsed window left a dead named Atomic forever → unbounded per-worker
     * memory growth. WS-7 keeps the counter name STABLE (one per distinct
     * room / client) and reuses it across windows: when this map shows the
     * counter has rolled into a new window, the limiter `reset()`s it to 0
     * before the increment. Bounds the live Atomic count to the number of
     * distinct rate-limited rooms + clients, not rooms × elapsed-windows.
     * `counter-name → window-id`
     *
     * @var array<string, int>
     */
    private static array $rateLimitWindows = [];

    // Room state — per-worker.
    //
    // `$localRoomMembership[$room][$clientId] = true` — clients in this room
    //   that ARE locally owned by this worker (push targets when a message
    //   arrives). Populated via presence events from the pattern subscriber.
    //
    // `$roomMessageHandlers[$room][]` = callable    (user-registered)
    // `$roomPresenceHandlers[$room][]` = callable   (user-registered)

    /**
     * Per-worker cache of locally-owned clients by room.
     * `room → [client_id => conn_id]`
     *
     * #246 — the value is the per-connection nonce (conn_id) captured from
     * `$localFds[$clientId]` at join time, NOT a bare `true`. The room push
     * loop re-checks it against the live `$localFds[$clientId]['conn_id']`
     * before pushing, so a reused fd (client reconnected under a fresh nonce
     * while a stale membership entry lingers) can't receive a prior owner's
     * room message — mirroring the C1 guard on the identity-delivery path.
     *
     * @var array<string, array<string, string>>
     */
    private static array $localRoomMembership = [];

    /**
     * Per-worker reverse index: which rooms each LOCALLY-OWNED client has
     * joined. Lets `release()` (ws onClose) leave every room a client was
     * in, so an abnormal disconnect doesn't leak `ws_room_members` rows /
     * per-room SET entries until the whole server is GC'd.
     * `client_id → [room => true]`
     *
     * @var array<string, array<string, true>>
     */
    private static array $localClientRooms = [];

    /**
     * User-registered message handlers per room. Each callable receives
     * `(array $msg, string $room)`.
     *
     * @var array<string, list<callable>>
     */
    private static array $roomMessageHandlers = [];

    /**
     * User-registered presence (join/leave) handlers per room. Each callable
     * receives `(array $event, string $room)`.
     *
     * @var array<string, list<callable>>
     */
    private static array $roomPresenceHandlers = [];

    /**
     * Bump the per-cluster capacity caps BEFORE `init()` — these size the
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

    /**
     * WS-6 — bound the per-broadcast push fan-out concurrency. Default 128.
     * A room broadcast spawns at most `$n` push coroutines at a time; the
     * rest queue on a `Coroutine\Channel` token semaphore and start as
     * in-flight pushes complete. Pass 0 to UNBOUND (restores the legacy
     * spawn-per-member behaviour — not recommended for large rooms).
     *
     *     WSRouter::setFanoutConcurrency(256);   // up to 256 simultaneous pushes
     *     WSRouter::setFanoutConcurrency(0);      // unbounded (legacy)
     */
    public static function setFanoutConcurrency(int $n): void
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('setFanoutConcurrency: $n must be >= 0');
        }
        self::$fanoutConcurrency = $n;
    }

    /** @internal — current per-broadcast fan-out concurrency cap (0 = unbounded). */
    public static function fanoutConcurrency(): int { return self::$fanoutConcurrency; }

    /** @internal — used by Room::push to gate per-client rate */
    public static function clientRateLimitN(): int { return self::$clientRateLimitN; }

    /**
     * Returns true when the client is under the rate limit (or limits
     * disabled). Increments the (window-stable) counter atomically.
     */
    public static function checkClientRate(string $clientId): bool
    {
        $n = self::$clientRateLimitN;
        if ($n === 0 || $clientId === '') { return true; }
        // WS-7: STABLE counter name (no per-window suffix) — see rateLimitAllow.
        $name  = '_wsrouter_cl_' . substr(sha1($clientId), 0, 16);
        $allow = self::rateLimitAllow($name, $n, self::$clientRateLimitWindowSec);
        if (!$allow) {
            self::stats()->inc('client_rate_limit_drops_total');
        }
        return $allow;
    }

    /**
     * @internal — WS-7 shared sliding-window rate-limit primitive. Bounds the
     * named-Atomic allocation on the default Counter backend: the counter name
     * is STABLE (`$name`, derived from the room/client, NOT the window), so a
     * given subject reuses ONE Atomic across every window instead of leaking a
     * fresh dead Atomic per elapsed window. When this worker observes that the
     * counter has rolled into a new `floor(time()/$windowSec)` bucket, it
     * `reset()`s the counter to 0 before incrementing — the window boundary
     * without a new allocation.
     *
     * On a SHARED Counter backend (Redis/Memcached) the per-worker window map
     * means each worker independently resets the shared counter on its first
     * touch of a new window; that's a benign over-reset (the first request of a
     * window in each worker re-zeroes, which can let slightly more than `$n`
     * through cluster-wide right at a boundary) and keeps the previous
     * cross-node behaviour otherwise. For exact cross-node windows, a
     * key-with-EXPIRE scheme on Redis is the upgrade path; this fix's mandate
     * is to STOP the unbounded Atomic growth without regressing correctness.
     *
     * Returns true when the post-increment count is within `$n`.
     */
    public static function rateLimitAllow(string $name, int $n, int $windowSec): bool
    {
        if ($n <= 0 || $windowSec <= 0) { return true; }
        $window = (int) (time() / $windowSec);
        $c      = new \ZealPHP\Counter(0, $name);
        // First touch in a fresh window (per worker) → reset the shared/atomic
        // counter so the stable name behaves like a rotating bucket.
        if ((self::$rateLimitWindows[$name] ?? null) !== $window) {
            self::$rateLimitWindows[$name] = $window;
            $c->reset();
        }
        return $c->increment() <= $n;
    }

    /** @internal — testing hook: current per-worker rate-limit window map size. */
    public static function rateLimitWindowCount(): int { return count(self::$rateLimitWindows); }

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

    /** @internal — true when a channel HMAC secret is configured (WS-3). */
    public static function hmacConfigured(): bool
    {
        return self::$channelHmacSecret !== null;
    }

    /**
     * @internal — true when the active Counter backend is SHARED across the
     * cluster (Redis / Memcached), false on the default per-worker Atomic
     * backend. The WS rate limiters (`setRoomRateLimit`, `setClientRateLimit`)
     * build named `Counter`s; on Atomic those are PER-WORKER (so the effective
     * limit is multiplied by worker count and is NOT cross-node) — `bootChecks`
     * surfaces that.
     */
    public static function counterBackendIsShared(): bool
    {
        return !(\ZealPHP\Counter::defaultBackend() instanceof \ZealPHP\Counter\AtomicBackend);
    }

    /**
     * Boot-time WS advisories — mirror of `App::redisBootChecks()`. Returns a
     * list of human-readable warning strings for misconfigurations that are
     * legal but surprising in production. `App::run()` (or app.php) can emit
     * these via `elog`/`error_log` at boot. Pure + side-effect-free so it's
     * unit-testable without booting a server.
     *
     * Covers:
     *  - WS-2: a WS rate limit (room or client) is configured but the Counter
     *    backend is the per-worker Atomic — limits are per-worker (× worker
     *    count), NOT cross-node. Flip to `Counter::defaultBackend('redis')`
     *    for a true cluster-wide cap.
     *  - WS-3: the router is Redis-backed (cross-node pub/sub) but NO channel
     *    HMAC secret is set — routed `ws:server:*` / `ws:room:*` messages are
     *    UNAUTHENTICATED, so any peer with Redis write access can forge a
     *    message onto a real client. Set `WSRouter::setChannelHmacSecret()`.
     *
     * @return list<string>
     */
    public static function bootChecks(): array
    {
        $warnings = [];

        $rateLimitOn = self::$roomRateLimitN > 0 || self::$clientRateLimitN > 0;
        if ($rateLimitOn && !self::counterBackendIsShared()) {
            $warnings[] = 'WSRouter(WS-2): WebSocket rate limiting is configured but the Counter backend is the ' .
                'per-worker Atomic — limits are PER-WORKER (effective cap ≈ configured × worker_count) and are ' .
                'NOT enforced cross-node. For a true cluster-wide cap, set Counter::defaultBackend(\'redis\') ' .
                '(or ZEALPHP_STORE_BACKEND=redis) before App::run().';
        }

        if (self::hasRedisBackend() && !self::hmacConfigured()) {
            $warnings[] = 'WSRouter(WS-3): the router is Redis-backed (cross-node pub/sub) but no channel HMAC ' .
                'secret is set — routed ws:server:* / ws:room:* messages are UNAUTHENTICATED, so any peer with ' .
                'Redis write access can forge a message onto a real client. Set ' .
                'WSRouter::setChannelHmacSecret(getenv(\'ZEALPHP_WS_HMAC\') ?: null) with a shared secret.';
        }

        return $warnings;
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
        //
        // #416 — defer the eager write only when it would actually fatal: on
        // the Redis backend the write opens a hooked stream_socket_client
        // (predis) that throws `API must be called in the coroutine` when init()
        // runs in the master (no coroutine). The Table backend write is always
        // coroutine-safe. So write eagerly UNLESS we're outside a coroutine on
        // the Redis backend — in which case the per-worker onWorkerStart hook
        // below writes the row (no lost registration).
        $deferForRedis = \OpenSwoole\Coroutine::getCid() < 0
            && \ZealPHP\Store::defaultBackend() instanceof \ZealPHP\Store\RedisBackend;
        if (!$deferForRedis) {
            self::writeServerRegistryRow();
        }

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
        // The framework invokes worker hooks as $fn($server, $workerId) (#415),
        // so the closure MUST accept the Server as arg #1 — typing the first
        // slot `int $workerId` made every worker crash-loop with a TypeError.
        App::onWorkerStart(function ($server, int $workerId): void {
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
        App::onWorkerStop(function ($server, int $workerId): void {
            if ($workerId === 0) {
                self::sweepThisServer();
            }
        });

        // Boot-time advisories (WS-2 per-worker rate limits / WS-3 missing
        // channel HMAC). Mirror App::redisBootChecks() — emit once at init so
        // production misconfigurations surface before workers fork. Rate
        // limits + the HMAC secret are conventionally set BEFORE init(), so
        // they're already resolved here.
        foreach (self::bootChecks() as $warning) {
            if (function_exists('elog')) {
                elog($warning, 'info');
            } else {
                error_log($warning);
            }
        }
    }

    /** Returns the configured server id (hostname:pid by default). */
    public static function serverId(): string { return self::$serverId; }

    // ───────────────────────────────────────────────────────────────────────
    // #234 — session-auth integration. WS routing/rooms follow the SAME auth
    // hooks the HTTP layer uses (App::authChecker / App::usernameProvider), so a
    // client can only own the identity it is authenticated as, and room ops can
    // be gated fail-closed by app policy. Opt-in: with no authorizer wired, the
    // room layer behaves exactly as before (BC).
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Register a per-room authorizer (or read it back). `fn(string $action,
     * string $room, string $clientId): bool`, `$action ∈ {join,leave,push,read}`.
     *
     * When SET, every `Room` op consults it FAIL-CLOSED: a falsey return refuses
     * the op (mutations throw {@see WSAuthException}; reads return empty). When
     * `null` (default) the room layer is unguarded (BC). This is the WS analog of
     * `App::authChecker()` — wire it to your session so WS follows your auth.
     *
     * @param (callable(string,string,string):bool)|null $fn
     */
    public static function roomAuthorizer(?callable $fn = null): ?callable
    {
        if (\func_num_args() > 0) {
            self::$roomAuthorizer = $fn;
        }
        return self::$roomAuthorizer;
    }

    /**
     * Resolve the authenticated principal for the CURRENT request/connection
     * from the session, via the same hooks the HTTP layer uses:
     * `App::authChecker()` (is the session authenticated?) then
     * `App::usernameProvider()` (who?). Returns `null` when unauthenticated, the
     * hooks aren't wired, or no username resolves.
     *
     * Call from a WS `onOpen` handler (the framework starts the session from the
     * upgrade cookie there) to derive a server-trusted identity — never trust a
     * client-supplied id for routing.
     */
    public static function sessionPrincipal(): ?string
    {
        $authChecker = \ZealPHP\App::authChecker();
        if (!\is_callable($authChecker) || $authChecker() !== true) {
            return null;
        }
        $usernameProvider = \ZealPHP\App::usernameProvider();
        if (\is_callable($usernameProvider)) {
            $name = $usernameProvider();
            if (\is_string($name) && $name !== '') {
                return $name;
            }
        }
        return null;
    }

    /**
     * Like {@see own()} but binds the connection to the authenticated session
     * principal instead of a caller-supplied id (#234). Resolves
     * {@see sessionPrincipal()}; throws {@see WSAuthException} when the session
     * isn't authenticated, so an attacker can't claim another user's routing id.
     * Records the principal for the fd ({@see principalForFd()}) so later
     * `onMessage` handlers can authorize without re-reading the session.
     *
     * @throws WSAuthException when no authenticated principal is available.
     */
    public static function ownAuthenticated(int $fd, ?string $connId = null): string
    {
        $principal = self::sessionPrincipal();
        if ($principal === null) {
            self::stats()->inc('own_auth_denied_total');
            throw new WSAuthException(
                'WSRouter::ownAuthenticated(): no authenticated session principal — ' .
                'wire App::authChecker()/App::usernameProvider() and start the session in onOpen.'
            );
        }
        $result = self::own($principal, $fd, $connId);
        self::$principalByFd[$fd] = $principal;
        return $result;
    }

    /**
     * The authenticated principal bound to this fd by {@see ownAuthenticated()},
     * or `null` if the connection wasn't authenticated that way. Use it to
     * authorize `onMessage`-time room ops without touching the session again.
     */
    public static function principalForFd(int $fd): ?string
    {
        return self::$principalByFd[$fd] ?? null;
    }

    /**
     * Internal: consult the room authorizer (fail-closed when set). Returns true
     * when there is no authorizer (BC) or it permits the op. {@see Room} calls
     * this for reads (members/size/isMember) and {@see requireRoomAuth()} for
     * mutations (join/leave/push).
     *
     * @internal
     */
    public static function authorizeRoom(string $action, string $room, string $clientId): bool
    {
        if (self::$roomAuthorizer === null) {
            return true;
        }
        return (self::$roomAuthorizer)($action, $room, $clientId) === true;
    }

    /**
     * Internal: like {@see authorizeRoom()} but throws {@see WSAuthException} on
     * refusal — used by the mutating Room ops (join/leave/push).
     *
     * @internal
     * @throws WSAuthException
     */
    public static function requireRoomAuth(string $action, string $room, string $clientId): void
    {
        if (!self::authorizeRoom($action, $room, $clientId)) {
            self::stats()->inc('room_authz_denied_total');
            throw new WSAuthException(
                "WSRouter: room '{$action}' on '{$room}' denied for client '{$clientId}' by roomAuthorizer."
            );
        }
    }

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
        // Leave every room this client joined on this worker BEFORE we drop
        // the local maps. Otherwise an abnormal disconnect (onClose without
        // an explicit Room::leave) leaks the cluster-wide ws_room_members
        // rows + per-room SET entries until the whole SERVER is GC'd, and
        // leaves the per-worker $localRoomMembership cache pointing at a dead
        // fd. $rooms is a value-copy, so Room::leave() mutating the static
        // mid-loop is safe.
        $rooms = self::$localClientRooms[$clientId] ?? [];
        foreach (array_keys($rooms) as $room) {
            try {
                self::room($room)->leave($clientId);
            } catch (\Throwable $e) {
                error_log("WSRouter::release leave({$room}, {$clientId}): " . $e->getMessage());
            }
            // Evict the local-membership cache directly: the leave presence
            // event we just published may not round-trip back to this worker
            // before the localFds unset below, and the leave handler is the
            // one that would otherwise clear it.
            unset(self::$localRoomMembership[$room][$clientId]);
            if (isset(self::$localRoomMembership[$room]) && self::$localRoomMembership[$room] === []) {
                unset(self::$localRoomMembership[$room]);
            }
        }
        unset(self::$localClientRooms[$clientId]);

        // #234 — reap the fd→principal binding (capture the fd before dropping
        // the local map, since $principalByFd is keyed by fd).
        $releasedFd = self::$localFds[$clientId]['fd'] ?? null;
        if (is_int($releasedFd)) {
            unset(self::$principalByFd[$releasedFd]);
        }

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
        self::$localClientRooms    = [];
        self::$roomMessageHandlers = [];
        self::$roomPresenceHandlers= [];
        self::$rateLimitWindows    = [];
        self::$roomAuthorizer      = null;
        self::$principalByFd       = [];
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
        // #247 — the room name is woven into Store keys (ws_room_members rows,
        // the per-room SET key) and the `ws:room:{name}` pub/sub channel. A `:`
        // (or other separator) in the name would alias one room's keys/channel
        // onto another's (compositeKey('chat:42','alice') === compositeKey(
        // 'chat','42:alice')). Restrict to a strict charset at the single
        // construction chokepoint so neither the keys NOR the PSUBSCRIBE pattern
        // can collide. (compositeKey() is additionally length-prefixed for
        // defence in depth on the client-id half.)
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new StoreException(
                "WSRouter::room(): invalid room name '{$name}' — must match /^[A-Za-z0-9_.-]+$/ " .
                "(no ':' or other separators, which would collide ws_room_members keys / the ws:room:* channel)"
            );
        }
        return new Room($name, self::$serverId);
    }

    /**
     * @internal — record that a locally-owned client joined a room, so
     * `release()` can leave it on disconnect. No-op for clients this worker
     * doesn't own (the owning worker tracks + cleans those up). Called by
     * `Room::join()`.
     */
    public static function noteLocalRoomJoin(string $clientId, string $room): void
    {
        if (!isset(self::$localFds[$clientId])) { return; }
        self::$localClientRooms[$clientId][$room] = true;
    }

    /**
     * @internal — drop a room from a client's local reverse index. Called by
     * `Room::leave()` (and indirectly by `release()` via the leave path).
     */
    public static function noteLocalRoomLeave(string $clientId, string $room): void
    {
        unset(self::$localClientRooms[$clientId][$room]);
        if (isset(self::$localClientRooms[$clientId]) && self::$localClientRooms[$clientId] === []) {
            unset(self::$localClientRooms[$clientId]);
        }
    }

    /**
     * @internal — current local client→rooms reverse index (for tests + debug).
     * @return array<string, array<string, true>>
     */
    public static function localClientRooms(): array { return self::$localClientRooms; }

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

    // ── Per-room server-set (B1: cross-node fan-out targeting groundwork) ──
    //
    // `ws:room:{room}:servers` is a Redis SET of the server_ids that hold >=1
    // member of {room}. The future B2 step will PUBLISH a room message only to
    // the servers in this set (instead of the cluster-wide ws:room:* broadcast),
    // so a node with no members of a room never gets woken.
    //
    // Maintenance must be refcount-correct under concurrency: a per-(room,server)
    // SET of client_ids tracks presence, and the server is SADD'd/SREM'd from the
    // servers-set exactly on the 0<->1 cardinality boundary — done ATOMICALLY in
    // one Lua call so a leave's SREM can't interleave a join's SADD and drop a
    // server that still has members. Using a client-SET (not a counter) also
    // makes join/leave idempotent: re-joining the same client is a no-op SADD.
    //
    // B1 is additive bookkeeping ONLY — it does not change message routing yet,
    // so any transient drift is toward OVER-inclusion (an extra server in the
    // set → a wasted message in B2), never under-inclusion (a dropped message).

    /** @internal — absolute SET key: the server_ids holding >=1 member of $room. */
    public static function roomServersKey(string $room): string
    {
        return self::ROOM_CHANNEL_PREFIX . $room . ':servers';
    }

    /** @internal — absolute SET key: the locally-owned client_ids of $room on $server. */
    public static function roomServerClientsKey(string $room, string $server): string
    {
        return self::ROOM_CHANNEL_PREFIX . $room . ':srv:' . $server . ':clients';
    }

    /**
     * @internal — record that THIS server now holds $clientId in $room. Adds the
     * client to the per-(room,server) presence set and, if it was this server's
     * FIRST member of the room, adds this server to the room's server-set —
     * atomically. Idempotent. No-op off the Redis backend. Called by Room::join.
     */
    public static function roomServerJoin(string $room, string $clientId): void
    {
        if (self::$serverId === '' || !self::hasRedisBackend()) { return; }
        // KEYS[1]=clients-set  KEYS[2]=servers-set   ARGV[1]=client  ARGV[2]=server
        $lua = "redis.call('SADD', KEYS[1], ARGV[1])\n"
             . "if redis.call('SCARD', KEYS[1]) == 1 then redis.call('SADD', KEYS[2], ARGV[2]) end\n"
             . "return 1";
        try {
            Store::eval(
                $lua,
                [self::roomServerClientsKey($room, self::$serverId), self::roomServersKey($room)],
                [$clientId, self::$serverId],
            );
        } catch (StoreException $e) {
            error_log("WSRouter::roomServerJoin({$room}, {$clientId}): " . $e->getMessage());
        }
    }

    /**
     * @internal — record that THIS server no longer holds $clientId in $room.
     * Removes the client from the presence set and, if that was this server's
     * LAST member of the room, removes this server from the room's server-set —
     * atomically. Idempotent. No-op off the Redis backend. Called by Room::leave.
     */
    public static function roomServerLeave(string $room, string $clientId): void
    {
        if (self::$serverId === '' || !self::hasRedisBackend()) { return; }
        $lua = "redis.call('SREM', KEYS[1], ARGV[1])\n"
             . "if redis.call('SCARD', KEYS[1]) == 0 then redis.call('SREM', KEYS[2], ARGV[2]) end\n"
             . "return 1";
        try {
            Store::eval(
                $lua,
                [self::roomServerClientsKey($room, self::$serverId), self::roomServersKey($room)],
                [$clientId, self::$serverId],
            );
        } catch (StoreException $e) {
            error_log("WSRouter::roomServerLeave({$room}, {$clientId}): " . $e->getMessage());
        }
    }

    /**
     * @internal — the server_ids currently holding >=1 member of $room. The B2
     * routing step publishes a room message only to these servers. Returns an
     * empty list off the Redis backend or on error.
     *
     * @return list<string>
     */
    public static function roomServers(string $room): array
    {
        if (!self::hasRedisBackend()) { return []; }
        try {
            $members = Store::eval("return redis.call('SMEMBERS', KEYS[1])", [self::roomServersKey($room)], []);
        } catch (StoreException $e) {
            error_log("WSRouter::roomServers({$room}): " . $e->getMessage());
            return [];
        }
        if (!is_array($members)) { return []; }
        $out = [];
        foreach ($members as $m) {
            if (is_string($m) && $m !== '') { $out[] = $m; }
        }
        return $out;
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
            if ($cid !== '') {
                if ($type === 'join') {
                    // Only cache clients THIS worker owns (they're the push
                    // targets); a join for a remote client isn't ours to hold.
                    // #246 — capture the connection's conn_id alongside the
                    // client id so the push loop can detect an fd-reuse drift.
                    if (isset(self::$localFds[$cid])) {
                        self::$localRoomMembership[$roomName][$cid] = self::$localFds[$cid]['conn_id'];
                    }
                } else {
                    // Always evict on leave — unsetting a non-member is a
                    // harmless no-op, and gating this on $localFds would leak
                    // the cache entry once release() has already cleared the
                    // fd (the disconnect path that needs the eviction most).
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

        // WS-6: resolve member ids to live fds first (re-validating connection
        // identity, #246), then fan out under a concurrency cap. Each push still
        // runs in its OWN coroutine (a slow consumer's back-pressure can't block
        // the others — per-coroutine isolation preserved), but at most
        // `$fanoutConcurrency` run simultaneously so a large room + burst can't
        // spawn unbounded push coroutines (the keystone backpressure gap).
        $fds = [];
        foreach ($localMembers as $cid => $cachedConnId) {
            $local = self::$localFds[$cid] ?? null;
            if ($local === null) { continue; }
            // #246 — re-validate connection identity before pushing. If the fd was
            // reused (the cached client id now maps to a DIFFERENT live connection,
            // a fresh conn_id), skip — the room message was destined for the
            // disconnected owner, not the new client on the reused fd. Same
            // guarantee as the identity-path C1 guard.
            if ($cachedConnId !== '' && $local['conn_id'] !== $cachedConnId) { continue; }
            $fds[] = $local['fd'];
        }
        if ($fds === []) { return; }
        self::boundedFanOut(
            $fds,
            self::$fanoutConcurrency,
            // Real spawner — one push coroutine per fd. `$release` MUST run in
            // the coroutine's finally so a token is returned even if the push
            // throws, otherwise the semaphore would deadlock the remaining fan-out.
            function (int $fd, callable $release) use ($server, $data): void {
                \OpenSwoole\Coroutine::create(function () use ($server, $fd, $data, $release): void {
                    try {
                        self::pushWithBackpressure($server, $fd, $data);
                    } finally {
                        $release();
                    }
                });
            },
        );
    }

    /**
     * @internal — WS-6 bounded fan-out driver. Spawns one task per item via
     * the injected `$spawn` callable while never letting more than
     * `$maxConcurrent` tasks be in flight at once. Every item is dispatched
     * EXACTLY ONCE.
     *
     * `$maxConcurrent === 0` means UNBOUNDED — every item is spawned
     * immediately (legacy spawn-per-member behaviour), and `$release` is a
     * no-op the spawner can ignore. Otherwise a `Coroutine\Channel` of
     * `$maxConcurrent` tokens acts as a counting semaphore: the driver blocks
     * (`pop`) for a free token before spawning the next item, and the spawned
     * task returns its token by calling the `$release` closure it was handed
     * (which `push`es back onto the channel) in its `finally`.
     *
     * The driver does NOT wait for in-flight tasks to finish after the last
     * item is dispatched — the channel reclaim is purely for admission
     * control, and the spawned push coroutines complete independently.
     *
     * Testable in isolation: pass a synchronous fake `$spawn` that records the
     * concurrency it observes (call `$release` to model completion) — the
     * driver's admission logic needs neither a live server nor real pushes.
     *
     * @param list<int>                            $items
     * @param callable(int $item, callable(): void $release): void $spawn
     */
    public static function boundedFanOut(array $items, int $maxConcurrent, callable $spawn): void
    {
        if ($items === []) { return; }
        if ($maxConcurrent <= 0) {
            // Unbounded: dispatch every item immediately; release is a no-op.
            $noop = static function (): void {};
            foreach ($items as $item) {
                $spawn($item, $noop);
            }
            return;
        }
        // Counting semaphore: a channel pre-filled with `$maxConcurrent` tokens.
        $tokens = new \OpenSwoole\Coroutine\Channel($maxConcurrent);
        for ($i = 0; $i < $maxConcurrent; $i++) {
            $tokens->push(true);
        }
        // Each release returns a token exactly once — `$returned` guards against
        // a double-release inflating the in-flight budget above the cap.
        $makeRelease = static function () use ($tokens): callable {
            $returned = false;
            return static function () use ($tokens, &$returned): void {
                if ($returned) { return; }
                $returned = true;
                $tokens->push(true);
            };
        };
        foreach ($items as $item) {
            // Block until a token is free — admission control. pop() yields the
            // current coroutine when the channel is empty, so the driver doesn't
            // busy-wait; an in-flight task's release() wakes it.
            $tokens->pop();
            $spawn($item, $makeRelease());
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
     * @return array<string, array<string, string>>
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
        // Collect-then-delete: deleting from an OpenSwoole\Table WHILE
        // iterating it advances the internal cursor into a freed slot and
        // silently skips ~28% of the remaining rows, so dead-server rows
        // would survive the sweep. Materialize the hit list first, then
        // delete after each iterator has fully drained.
        $ownerDel = [];
        foreach (Store::iterate(self::TABLE) as $clientId => $row) {
            if (in_array((string) ($row['server_id'] ?? ''), $dead, true)) {
                $ownerDel[] = (string) $clientId;
            }
        }
        $roomDel = [];
        foreach (Store::iterate(self::ROOM_TABLE) as $key => $row) {
            if (in_array((string) ($row['server_id'] ?? ''), $dead, true)) {
                $roomDel[] = (string) $key;
            }
        }
        foreach ($ownerDel as $clientId) { Store::del(self::TABLE, $clientId); $reaped++; }
        foreach ($roomDel as $key)       { Store::del(self::ROOM_TABLE, $key);  $reaped++; }
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
        // Collect-then-delete — see runStaleServerGC: deleting mid-iteration
        // on the Table backend skips rows, leaving our own footprint behind.
        $ownerDel = [];
        foreach (Store::iterate(self::TABLE) as $clientId => $row) {
            if (($row['server_id'] ?? '') === self::$serverId) {
                $ownerDel[] = (string) $clientId;
            }
        }
        $roomDel = [];
        foreach (Store::iterate(self::ROOM_TABLE) as $key => $row) {
            if (($row['server_id'] ?? '') === self::$serverId) {
                $roomDel[] = (string) $key;
            }
        }
        foreach ($ownerDel as $clientId) { Store::del(self::TABLE, $clientId); $reaped++; }
        foreach ($roomDel as $key)       { Store::del(self::ROOM_TABLE, $key);  $reaped++; }
        Store::del(self::SERVERS_TABLE, self::$serverId);
        return $reaped;
    }
}
