<?php

declare(strict_types=1);

// Lesson 22 — multi-room group chat (PHP + SQLite, no Redis required).
//
// WS handler: each frame is JSON: {type: 'join'|'message', room, body?, username?}
//   - On join: store {fd → room, username} in the SHARED Store table; send history (last 50 from
//     SQLite) to joining client; broadcast a 'system' presence message to the room.
//   - On message: persist + fan out to every fd in the same room across all workers.
//   - On close: drop the fd row + broadcast a 'system' leave message.
//
// Why a shared Store table (not a worker-local array)?
//   `$server->push($fd, ...)` works cross-worker — any worker can push to any fd, BUT the worker
//   handling an inbound frame needs to KNOW which other fds are in the same room. With a
//   worker-local PHP array, worker A only sees fds it accepted itself — so messages from a tab
//   on worker A never reach a tab on worker B. Solution: keep the fd → room map in shared
//   memory (OpenSwoole\Table via Store::make), iterate it from any worker to find broadcast
//   targets. Same pattern as the /ws/rooms example in route/ws.php.
//
// On the default Table backend this is in-process shared memory (ns reads). On the Redis
// backend it's cluster-wide automatically — the SAME code becomes federated across hosts. No
// extra plumbing. SQLite still owns durable message history either way.
//
// REST sidecars (used by the popout widget for initial paint):
//   GET  /api/learn/chatroom/recent?room=<name>     → JSON array of messages
//   GET  /api/learn/chatroom/lobby                  → JSON array of active rooms

use ZealPHP\App;
use ZealPHP\HTTP\Request;
use ZealPHP\Learn\Chatroom;
use ZealPHP\Store;

// Cluster-wide fd map — keyed by stringified fd, columns: room + username.
// Must run BEFORE App::run() forks workers; route files load at boot-time so
// this is the right place.
Store::make('chatroom_fds', 4096, [
    'room'     => [Store::TYPE_STRING, 64],
    'username' => [Store::TYPE_STRING, 64],
]);

$app->ws('/ws/learn/chatroom', function ($server, $frame) {
    $msg = json_decode((string) $frame->data, true);
    if (!is_array($msg)) { return; }
    $type = is_string($msg['type'] ?? null) ? $msg['type'] : '';

    if ($type === 'join') {
        $room     = is_string($msg['room'] ?? null) ? $msg['room'] : 'general';
        $username = is_string($msg['username'] ?? null) ? $msg['username'] : 'anonymous';

        // Record this fd's room membership in shared memory.
        Store::set('chatroom_fds', (string) $frame->fd, [
            'room'     => $room,
            'username' => $username,
        ]);

        // Send last 50 messages to the joining client ONLY (history).
        $server->push($frame->fd, (string) json_encode([
            'type'  => 'history',
            'room'  => $room,
            'items' => Chatroom::recent($room, 50),
        ]));

        // Persist + broadcast a system "X joined" line to every fd in the room.
        $sys = Chatroom::saveMessage($room, $username, "joined #{$room}", 'system');
        broadcast_to_room($server, $room, ['type' => 'message', 'message' => $sys]);
        return;
    }

    if ($type === 'message') {
        $meta = Store::get('chatroom_fds', (string) $frame->fd);
        if (!is_array($meta)) { return; }
        $body = is_string($msg['body'] ?? null) ? $msg['body'] : '';
        if (trim($body) === '') { return; }
        $row = Chatroom::saveMessage((string) $meta['room'], (string) $meta['username'], $body);
        broadcast_to_room($server, (string) $meta['room'], ['type' => 'message', 'message' => $row]);
        return;
    }
}, onClose: function ($server, $fd) {
    $meta = Store::get('chatroom_fds', (string) $fd);
    Store::del('chatroom_fds', (string) $fd);
    if (!is_array($meta)) { return; }
    try {
        $sys = Chatroom::saveMessage((string) $meta['room'], (string) $meta['username'], "left #{$meta['room']}", 'system');
        broadcast_to_room($server, (string) $meta['room'], ['type' => 'message', 'message' => $sys]);
    } catch (\Throwable $e) { /* tolerant — close path */ }
});

/**
 * Fan-out helper: iterate the cluster-wide fd map and push to every fd whose
 * row's `room` matches. Works across workers (any worker can $server->push
 * any fd) AND across the cluster when the Store backend is Redis (the table
 * iteration spans every node — federated chat for free).
 *
 * @param array<string, mixed> $payload
 */
function broadcast_to_room($server, string $room, array $payload): void
{
    if (!($server instanceof \OpenSwoole\WebSocket\Server)) { return; }
    $data = (string) json_encode($payload);
    foreach (Store::iterate('chatroom_fds') as $fd => $info) {
        if (($info['room'] ?? null) !== $room) { continue; }
        $fdInt = (int) $fd;
        if ($server->isEstablished($fdInt)) {
            $server->push($fdInt, $data);
        }
    }
}

// REST sidecar endpoints — used by the popout widget to render initial state.
$app->route('/api/learn/chatroom/recent', ['methods' => ['GET']], function (Request $request) {
    $room = is_string($request->get['room'] ?? null) ? (string) $request->get['room'] : 'general';
    return ['ok' => true, 'room' => $room, 'items' => Chatroom::recent($room, 50)];
});

$app->route('/api/learn/chatroom/lobby', ['methods' => ['GET']], function () {
    return ['ok' => true, 'rooms' => Chatroom::listRooms()];
});
