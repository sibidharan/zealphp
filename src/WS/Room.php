<?php

declare(strict_types=1);

namespace ZealPHP\WS;

use ZealPHP\App;
use ZealPHP\Store;
use ZealPHP\Store\StoreException;
use ZealPHP\WSRouter;
use ZealPHP\WS\CapacityException;

/**
 * A first-class WebSocket room — cluster-wide membership, presence,
 * fan-out + handler registration. Built on the existing v0.2.40 Store +
 * pub/sub fabric:
 *
 *   - Membership lives in the shared `ws_room_members` Store table
 *     (visible to every worker on every node).
 *   - Push / presence events flow over `ws:room:{name}` pub/sub channel.
 *   - One PSUBSCRIBE per worker covers EVERY room (no per-room subscriber
 *     proliferation).
 *
 * Construct via `WSRouter::room('chat:42')` — don't `new` directly
 * (instances need WSRouter::init() to have wired the PSUBSCRIBE).
 *
 * Usage:
 *
 *     $room = WSRouter::room('chat:42');
 *     $room->join('alice');                            // SADD-equivalent + presence broadcast
 *     $room->push(['from' => 'alice', 'msg' => 'hi']); // fans out across cluster
 *     $room->size();                                   // cluster-wide member count
 *     $room->members();                                // cluster-wide roster
 *     $room->onMessage(function (array $msg) { ... }); // user-side handler
 *     $room->onPresence(function (array $event) { ... }); // join/leave events
 *     $room->leave('alice');                           // SREM-equivalent + presence broadcast
 *
 * Federation: a publish from server-A reaches every server's PSUBSCRIBE.
 * Each worker pushes to its local members (those in WSRouter's per-worker
 * fd map). No double-delivery: workers without local members of this room
 * skip the push entirely.
 */
final class Room
{
    public function __construct(
        private string $name,
        private string $serverId,
    ) {
        if (WSRouter::serverId() === '') {
            throw new StoreException('WSRouter::init() must be called before constructing a Room');
        }
    }

    public function name(): string { return $this->name; }

    /**
     * Add a client to this room. Idempotent — re-joining is cheap.
     * Broadcasts a presence event to every server's room subscriber so
     * each worker can refresh its local-membership cache.
     */
    public function join(string $clientId): void
    {
        $ok = Store::set(WSRouter::roomTable(), self::compositeKey($this->name, $clientId), [
            'room'      => $this->name,
            'client_id' => $clientId,
            'server_id' => $this->serverId,
            'joined_at' => time(),
        ]);
        if (!$ok) {
            WSRouter::stats()->inc('capacity_exceeded_room_total');
            throw new CapacityException(
                "WSRouter\\Room({$this->name}): ws_room_members table full — " .
                "bump via WSRouter::initOptions(roomMembersCapacity: N) BEFORE init(), or flip to Redis backend"
            );
        }
        // WS-2: also maintain a per-room Redis SET so size()/members() can
        // use O(1) SCARD + paginated SSCAN instead of scanning the full
        // ws_room_members table. Best-effort: a SADD failure (Redis blip)
        // doesn't roll back the metadata write — eventual reconciliation
        // happens on the next presence event or via the iterate fallback.
        if (WSRouter::hasRedisBackend()) {
            try { Store::sadd(WSRouter::roomMembersSetKey($this->name), $clientId); }
            catch (StoreException) { /* keep join — metadata is authoritative */ }
        }
        // B1: maintain the per-room server-set so a future targeted-publish step
        // (B2) only wakes servers that actually hold members. Atomic + idempotent;
        // no-op off the Redis backend.
        WSRouter::roomServerJoin($this->name, $clientId);
        // Track the join in this worker's reverse index so WSRouter::release()
        // (ws onClose) can leave the room on an abnormal disconnect. No-op for
        // clients this worker doesn't own.
        WSRouter::noteLocalRoomJoin($clientId, $this->name);
        WSRouter::stats()->inc('room_joins_total');
        $this->publish([
            'type'      => 'join',
            'client_id' => $clientId,
            'ts'        => time(),
        ]);
    }

    /**
     * Remove a client from this room. Idempotent. Broadcasts the leave
     * event so peers update their local caches.
     */
    public function leave(string $clientId): void
    {
        Store::del(WSRouter::roomTable(), self::compositeKey($this->name, $clientId));
        // WS-2: keep the per-room SET in sync. Idempotent SREM.
        if (WSRouter::hasRedisBackend()) {
            try { Store::srem(WSRouter::roomMembersSetKey($this->name), $clientId); }
            catch (StoreException) { /* metadata table already removed */ }
        }
        // B1: keep the per-room server-set in sync (drops this server when its
        // last member of the room leaves). Atomic + idempotent.
        WSRouter::roomServerLeave($this->name, $clientId);
        WSRouter::noteLocalRoomLeave($clientId, $this->name);
        WSRouter::stats()->inc('room_leaves_total');
        $this->publish([
            'type'      => 'leave',
            'client_id' => $clientId,
            'ts'        => time(),
        ]);
    }

    /** True if `$clientId` is a current member (cluster-wide check). */
    public function isMember(string $clientId): bool
    {
        return Store::exists(WSRouter::roomTable(), self::compositeKey($this->name, $clientId));
    }

    /**
     * Cluster-wide member count (WS-2 hardened).
     *
     * Redis / Tiered backend: O(1) SCARD on the per-room SET maintained
     * by join/leave. Sub-millisecond regardless of cluster size.
     *
     * Table backend: O(total members across ALL rooms) — iterates the
     * ws_room_members metadata table and filters by room. Fine for
     * single-node deployments where the iterate is in-process.
     */
    public function size(): int
    {
        if (WSRouter::hasRedisBackend()) {
            try { return Store::scard(WSRouter::roomMembersSetKey($this->name)); }
            catch (StoreException) { /* fall through to the iterate path */ }
        }
        $count = 0;
        foreach (Store::iterate(WSRouter::roomTable()) as $row) {
            if (($row['room'] ?? '') === $this->name) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Cluster-wide roster — list of client ids currently joined.
     *
     * Redis / Tiered backend: drains the per-room SET via SSCAN. For very
     * large rooms (>10k members), use `membersPaged()` instead to avoid
     * loading the full roster into memory.
     *
     * Table backend: iterates the metadata table.
     *
     * @return list<string>
     */
    public function members(): array
    {
        if (WSRouter::hasRedisBackend()) {
            try {
                $key   = WSRouter::roomMembersSetKey($this->name);
                $next  = '0';
                $out   = [];
                do {
                    $page  = Store::sscanCursor($key, $next, 500);
                    $out   = array_merge($out, $page['members']);
                    $next  = $page['cursor'];
                } while ($next !== '0');
                return $out;
            } catch (StoreException) { /* fall through */ }
        }
        $out = [];
        foreach (Store::iterate(WSRouter::roomTable()) as $row) {
            if (($row['room'] ?? '') === $this->name) {
                $cid = $row['client_id'] ?? '';
                if (is_string($cid) && $cid !== '') { $out[] = $cid; }
            }
        }
        return $out;
    }

    /**
     * Paginated roster — returns one SSCAN batch + an opaque next-cursor
     * (WS-2). Use for very large rooms where `members()` would be too
     * heavy. Cursor `'0'` starts a fresh walk; the returned cursor `'0'`
     * signals end-of-scan.
     *
     * Only useful on Redis / Tiered backends — on Table backend the
     * iterate path returns the full roster in one batch (cursor '0').
     *
     * @return array{cursor: string, members: list<string>}
     */
    public function membersPaged(string $cursor = '0', int $count = 100): array
    {
        if (WSRouter::hasRedisBackend()) {
            try { return Store::sscanCursor(WSRouter::roomMembersSetKey($this->name), $cursor, $count); }
            catch (StoreException) { /* fall through */ }
        }
        return ['cursor' => '0', 'members' => $this->members()];
    }

    /**
     * Broadcast a message to every member of this room across the
     * cluster. Returns the receivers count Redis reported (1 per
     * subscribed worker × every server with a pattern subscriber).
     *
     * Payload is JSON-encoded when array; sent as-is when string.
     *
     * @param array<string,mixed>|string $payload
     */
    public function push(array|string $payload, ?string $fromClientId = null): int
    {
        // Per-room rate limit (configured via WSRouter::setRoomRateLimit).
        // Sliding-window counter keyed by `{room}:{window-id}` — increments
        // an atomic counter (Atomic on Table backend, Redis INCR on Redis).
        if (!$this->checkRoomRateLimit()) {
            WSRouter::stats()->inc('rate_limit_drops_total');
            return 0;
        }
        // WS-4: when a from-client is attributed AND a client rate limit is
        // configured, apply it. Lets apps gate per-user message floods.
        // Calls without $fromClientId are server-originated broadcasts —
        // no per-client cap applied (only the per-room one above).
        if ($fromClientId !== null && !WSRouter::checkClientRate($fromClientId)) {
            return 0;
        }
        WSRouter::stats()->inc('room_pushes_total');
        return $this->publish(is_array($payload) ? $payload : ['type' => 'message', 'data' => $payload]);
    }

    /**
     * Per-room rate limit check. True = allowed, false = drop.
     *
     * Sliding window via floor(time() / window) bucket. WS-7: the counter
     * NAME is STABLE per room (no per-window suffix) — the window roll is
     * handled by WSRouter::rateLimitAllow() resetting the SAME counter on
     * boundary crossing, instead of allocating a brand-new named Atomic every
     * window (which leaked one dead Atomic per elapsed window on the default
     * Atomic Counter backend). Disabled when WSRouter::$roomRateLimitN === 0.
     */
    private function checkRoomRateLimit(): bool
    {
        $n = WSRouter::roomRateLimitN();
        if ($n === 0) { return true; }
        $name = '_wsrouter_rl_' . substr(sha1($this->name), 0, 16);
        return WSRouter::rateLimitAllow($name, $n, WSRouter::roomRateLimitWindowSec());
    }

    /**
     * Register a handler for messages broadcast to this room.
     * Multiple handlers per room are allowed; all fire in order.
     *
     * @param callable(array<string,mixed> $msg, string $room): void $handler
     */
    public function onMessage(callable $handler): void
    {
        WSRouter::registerRoomMessageHandler($this->name, $handler);
    }

    /**
     * Register a handler for join/leave events on this room. The handler
     * is called with the full event object: `{type, client_id, ts}`.
     *
     * @param callable(array<string,mixed> $event, string $room): void $handler
     */
    public function onPresence(callable $handler): void
    {
        WSRouter::registerRoomPresenceHandler($this->name, $handler);
    }

    /** @param array<string,mixed> $envelope */
    private function publish(array $envelope): int
    {
        // WS-3: sign the envelope when a channel HMAC secret is configured;
        // passthrough otherwise. Subscribers verify on receive (init()).
        $signed = WSRouter::signPayload((string) json_encode($envelope));
        return Store::publish(WSRouter::roomChannelPrefix() . $this->name, $signed);
    }

    /** Composite key shape used in the ws_room_members Store table. */
    private static function compositeKey(string $room, string $clientId): string
    {
        return $room . ':' . $clientId;
    }
}
