# WebSocket

## Overview

ZealPHP exposes WebSocket via `App::ws()`, built on OpenSwoole's WebSocket server. The server runs on the same port as your HTTP routes — incoming requests are upgraded to WebSocket only when they target a path registered with `ws()`. Every other request flows through the normal PSR-15 middleware stack and the implicit/explicit route table.

Because the underlying server is `OpenSwoole\WebSocket\Server` (a subclass of `HTTP\Server`), HTTP and WebSocket endpoints coexist without a second port, a sidecar process, or a reverse-proxy split.

## Quick start

A minimal echo server:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use ZealPHP\App;

App::superglobals(false);
$app = App::init('0.0.0.0', 8080);

$app->ws(
    '/ws/echo',
    onMessage: function($server, $frame, $g) {
        $server->push($frame->fd, 'echo: ' . $frame->data);
    },
    onOpen: function($server, $request, $g) {
        $server->push($request->fd, json_encode(['event' => 'connected']));
    },
    onClose: function($server, $fd, $g) {
        // optional cleanup
    }
);

$app->run();
```

Test with `wscat -c ws://localhost:8080/ws/echo`. A larger working example with broadcast, joins, and leaves lives in `examples/websocket-chat/`.

## Lifecycle

1. **HTTP request** arrives at the server with an `Upgrade: websocket` header on a path registered via `ws()`.
2. **Handshake** is performed by OpenSwoole. PSR-15 middleware is bypassed for upgrade requests — any auth must happen in `onOpen`.
3. **`onOpen($server, $request, $g)`** fires once. `$request` is an `OpenSwoole\Http\Request` with `fd`, `cookie`, `header`, `get`, and `server` properties. Use `$server->push($fd, $data)` to send the first message.
4. **`onMessage($server, $frame, $g)`** fires for every TEXT or BINARY frame. `$frame->fd` identifies the client, `$frame->data` is the payload, `$frame->opcode` is the frame type.
5. **`onClose($server, $fd, $g)`** fires when either side closes the connection. The `fd` is no longer writable after this returns — use this hook to remove the client from any registry you maintain.

`$g` is the per-coroutine `G` instance — the same object route handlers receive.

To send: `$server->push($fd, $data, $opcode = WEBSOCKET_OPCODE_TEXT)`.
To close from the server: `$server->disconnect($fd, $code = 1000, $reason = '')`.
To check liveness before sending: `if ($server->isEstablished($fd)) { ... }`.

## Frame types

OpenSwoole exposes four opcodes:

| Opcode constant | Value | Delivered to `onMessage`? |
|---|---|---|
| `WEBSOCKET_OPCODE_CONTINUATION` | 0 | No |
| `WEBSOCKET_OPCODE_TEXT` | 1 | Yes |
| `WEBSOCKET_OPCODE_BINARY` | 2 | Yes |
| `WEBSOCKET_OPCODE_PING` | 9 | No |
| `WEBSOCKET_OPCODE_PONG` | 10 | No |

ZealPHP silently drops `PING`, `PONG`, and `CONTINUATION` frames before invoking the route handler. PING/PONG belong to the transport-level keepalive that OpenSwoole answers internally — exposing them to user code would mean every handler had to filter them. CONTINUATION frames are reassembled into the original TEXT or BINARY message by OpenSwoole; surfacing the fragments separately would force handlers to track buffers per `fd`. Only the assembled TEXT and BINARY messages reach `onMessage`.

The CLOSE frame (opcode 8) is handled by OpenSwoole's own close event — `App::ws()` does **not** need to drop it in `onMessage`; `onClose` fires automatically when either side sends a close frame.

To distinguish text from binary inside the handler, check `$frame->opcode`:

```php
onMessage: function($server, $frame, $g) {
    if ($frame->opcode === \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_BINARY) {
        $server->push($frame->fd, $frame->data, \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_BINARY);
    } else {
        $server->push($frame->fd, "text: {$frame->data}");
    }
}
```

## Broadcasting

Within a single worker, an in-memory `fd → state` array is enough. Across workers, use `Store` (an `OpenSwoole\Table` adapter) so every worker sees the same registry:

```php
use ZealPHP\Store;

Store::make('ws_rooms', 4096, [
    'room' => [Store::TYPE_STRING, 64],
    'uid'  => [Store::TYPE_STRING, 128],
]);

$app->ws('/ws/rooms',
    onOpen: function($server, $request, $g) {
        $room = $request->get['room'] ?? 'general';
        Store::set('ws_rooms', (string)$request->fd, ['room' => $room, 'uid' => 'guest_'.$request->fd]);
    },
    onMessage: function($server, $frame, $g) {
        $me = Store::get('ws_rooms', (string)$frame->fd);
        foreach (Store::table('ws_rooms') as $fd => $info) {
            if ($info['room'] === $me['room'] && $server->isEstablished((int)$fd)) {
                $server->push((int)$fd, $frame->data);
            }
        }
    },
    onClose: function($server, $fd, $g) {
        Store::del('ws_rooms', (string)$fd);
    }
);
```

### Federated rooms (cross-host)

The manual Store-iteration example above routes messages across workers on the same server. For cross-host fan-out (multiple nodes behind a load balancer) use the first-class `WSRouter` + `Room` abstraction, which handles cluster-wide membership and pub/sub routing automatically. It requires `Store::defaultBackend(Store::BACKEND_REDIS)` because cross-node pub/sub needs a shared broker.

```php
use ZealPHP\App;
use ZealPHP\Store;
use ZealPHP\WSRouter;

// Boot — before App::run()
Store::defaultBackend(Store::BACKEND_REDIS, 'redis://127.0.0.1:6379');
WSRouter::init();           // registers the per-worker PSUBSCRIBE for all rooms

$app->ws('/ws/rooms',
    onOpen: function($server, $request, $g) {
        $clientId = $request->cookie['PHPSESSID'] ?? 'anon_' . $request->fd;
        $roomName = $request->get['room'] ?? 'general';

        WSRouter::own($clientId, $request->fd);      // register fd→clientId cluster-wide
        WSRouter::room($roomName)->join($clientId);   // add to room, broadcasts presence event

        // persist identity so onMessage/onClose can resolve it from any worker
        Store::set('ws_rooms', (string)$request->fd, [
            'room' => $roomName,
            'uid'  => $clientId,
        ]);
    },
    onMessage: function($server, $frame, $g) {
        $info = Store::get('ws_rooms', (string)$frame->fd);
        if (!$info) return;
        WSRouter::room($info['room'])->push($frame->data); // fan-out to every node
    },
    onClose: function($server, $fd, $g) {
        $info = Store::get('ws_rooms', (string)$fd);
        if ($info) {
            WSRouter::room($info['room'])->leave($info['uid']);
            WSRouter::release($info['uid']);
        }
        Store::del('ws_rooms', (string)$fd);
    }
);
```

`WSRouter::room($name)` returns a `Room` instance with `join()`, `leave()`, `push()`, `isMember()`, `size()`, `members()`, `membersPaged()`, `onMessage()`, and `onPresence()`. Messages published from any node reach all workers via a single `ws:room:*` PSUBSCRIBE per worker — there is no per-room subscriber proliferation.

> **Large rooms:** `members()` drains the full roster into memory (SSCAN loop on Redis). For rooms with more than ~10 000 members, use `membersPaged(string $cursor = '0', int $count = 100)` instead — it returns one SSCAN batch plus an opaque next-cursor. Cursor `'0'` starts a fresh walk; a returned cursor of `'0'` signals end-of-scan. On the Table backend, `membersPaged()` falls back to returning the full roster in one batch.
>
> ```php
> $cursor = '0';
> do {
>     ['cursor' => $cursor, 'members' => $batch] = WSRouter::room('general')->membersPaged($cursor, 200);
>     foreach ($batch as $clientId) { /* process */ }
> } while ($cursor !== '0');
> ```

To send directly to one client by id (not a room broadcast) across workers and hosts:

```php
WSRouter::sendToClient($clientId, json_encode(['type' => 'dm', 'text' => 'hello']));
```

For a server-wide fan-out where you don't keep your own registry, `$server->getClientList($startFd, $pageSize)` enumerates connected fds. OpenSwoole caps `find_count` at **100**, so paginate:

```php
$startFd = 0;
do {
    $fds = $server->getClientList($startFd, 100);
    if (!$fds) break;
    foreach ($fds as $fd) {
        if ($server->isEstablished($fd)) {
            $server->push($fd, $payload);
        }
    }
    $startFd = max($fds) + 1;
} while (count($fds) === 100);
```

## Origin checks for security

**`App::ws()` does not validate `Origin`.** Browsers send the `Origin` header during the upgrade handshake but the WebSocket protocol does not require the server to enforce it. Any origin can attempt to connect, which exposes you to cross-site WebSocket hijacking if your endpoint relies on cookie auth alone.

Validate `Origin` explicitly inside `onOpen`:

```php
$app->ws('/ws/secure',
    onOpen: function($server, $request, $g) {
        $origin  = $request->header['origin'] ?? '';
        $allowed = ['https://app.example.com', 'https://admin.example.com'];
        if (!in_array($origin, $allowed, true)) {
            $server->disconnect($request->fd, 4003, 'Origin not allowed');
            return;
        }
        $server->push($request->fd, json_encode(['event' => 'connected']));
    },
    onMessage: function($server, $frame, $g) { /* ... */ }
);
```

See `SECURITY.md` for the full threat model and reporting guidelines.

## Auth

WebSocket upgrades bypass the PSR-15 middleware stack, so any auth must happen in `onOpen`. The session cookie and any query-string token are both available on `$request`:

```php
$app->ws('/ws/auth',
    onOpen: function($server, $request, $g) {
        $token  = $request->get['token'] ?? null;
        $sessid = $request->cookie['PHPSESSID'] ?? null;
        $authed = ($token === 'secret') || ($sessid && strlen($sessid) >= 10);

        if (!$authed) {
            $server->disconnect($request->fd, 4001, 'Unauthorized');
            return;
        }
        // store identity for later messages — fd → user id
        $g->ws_user[$request->fd] = $sessid ? 'session-user' : 'token-user';
    },
    onMessage: function($server, $frame, $g) {
        $user = $g->ws_user[$frame->fd] ?? 'anonymous';
        $server->push($frame->fd, "[$user] {$frame->data}");
    }
);
```

For multi-worker setups, persist the `fd → user` map in `Store` instead of `$g` so any worker that handles a subsequent message can resolve the identity. To route a message to a specific client by id across workers or hosts, use `WSRouter::own($clientId, $fd)` in `onOpen`, `WSRouter::release($clientId)` in `onClose`, and `WSRouter::sendToClient($clientId, $payload)` from anywhere — see the [Federated rooms](#federated-rooms-cross-host) section.

## Heartbeats

OpenSwoole has a built-in idle disconnector configured via two server settings:

```php
$app->run([
    'heartbeat_check_interval' => 30,  // run the sweep every 30s
    'heartbeat_idle_time'      => 90,  // disconnect clients silent for >90s
]);
```

For application-level keepalive (e.g., refreshing a JWT or pushing a `tick` so the client knows the connection is healthy), use `App::onWorkerStart()` to register a per-worker timer:

```php
App::onWorkerStart(function($server, $workerId) {
    App::tick(30000, function() use ($server) {
        $startFd = 0;
        do {
            $fds = $server->getClientList($startFd, 100);
            if (!$fds) break;
            foreach ($fds as $fd) {
                if ($server->isEstablished($fd)) {
                    $server->push($fd, json_encode(['type' => 'heartbeat', 'ts' => time()]));
                }
            }
            $startFd = max($fds) + 1;
        } while (count($fds) === 100);
    });
});
```

## Graceful shutdown

When the server stops (SIGTERM, `php app.php stop`, or `Ctrl+C`), ZealPHP's `shutdown` handler sends a WebSocket CLOSE frame with code **1001 Going Away** to every connected client before the process exits. Browser-side `ws.onclose` handlers receive `event.code === 1001`, which is the standard signal to back off and reconnect rather than treat the disconnect as an error.

`App::onWorkerStart()` is also where you'd warm up shared state, start producer coroutines, or pre-populate `Store` tables — anything that should run once per worker before the first connection arrives.
