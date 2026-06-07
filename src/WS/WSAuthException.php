<?php
declare(strict_types=1);

namespace ZealPHP\WS;

use ZealPHP\Store\StoreException;

/**
 * Thrown when a WebSocket routing/room operation is refused by authorization
 * (#234): an unauthenticated `WSRouter::ownAuthenticated()`, or a `Room`
 * mutation (`join`/`leave`/`push`) denied by the registered
 * `WSRouter::roomAuthorizer()`.
 *
 * Extends `StoreException` so existing `catch (StoreException)` blocks around
 * the WS fabric keep working; catch `WSAuthException` specifically to map a
 * refusal to a WS close code (`WSRouter::CLOSE_FORBIDDEN`) or a 403.
 */
final class WSAuthException extends StoreException
{
}
