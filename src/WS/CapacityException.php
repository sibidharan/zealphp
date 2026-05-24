<?php

declare(strict_types=1);

namespace ZealPHP\WS;

use ZealPHP\Store\StoreException;

/**
 * Thrown when WSRouter's shared `OpenSwoole\Table` segments (ws_owner,
 * ws_room_members) are full. App handlers catching this should respond
 * with a clear "server at capacity" close to the WebSocket client
 * (close code 1013 — "Try Again Later" — is the standard).
 *
 * Extends StoreException so existing `catch (StoreException)` blocks
 * still work; new code that wants to react specifically to capacity
 * (vs. transient Redis failures) catches this typed subclass.
 *
 *     try {
 *         WSRouter::own($clientId, $fd);
 *     } catch (\ZealPHP\WS\CapacityException $e) {
 *         $server->disconnect($fd, 1013, 'server at capacity');
 *         return;
 *     }
 *
 * Bump capacity via:
 *     WSRouter::initOptions(ownerCapacity: 200_000, roomMembersCapacity: 1_000_000);
 *     WSRouter::init();
 */
final class CapacityException extends StoreException
{
}
