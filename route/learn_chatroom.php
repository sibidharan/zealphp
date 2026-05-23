<?php

declare(strict_types=1);

// Lesson 22 — multi-room group chat (PHP + SQLite, no Redis).
//
// Single-server WS room implementation:
//   - Each WS frame is JSON: {type: 'join'|'message'|'leave', room, body?}
//   - On join: send history (last 50 from SQLite) + announce presence
//   - On message: persist + broadcast to local room members
//   - Fan-out: per-room fd map maintained in this file's worker memory
//
// REST endpoints (used by the demo widget):
//   GET  /api/learn/chatroom/recent?room=<name>     → JSON array of messages
//   GET  /api/learn/chatroom/lobby                  → JSON array of active rooms

use ZealPHP\App;
use ZealPHP\HTTP\Request;
use ZealPHP\Learn\Chatroom;

// Per-worker: room → fd-set (a fd is a worker-local integer; a chat that only
// runs on one server is fine — fd → connection. To go cross-server, swap this
// for WSRouter::room() in Lesson 23.)
/** @var array<string, array<int, true>> */
$roomFds = [];

/** @var array<int, array{room:string, username:string}> */
$fdMeta = [];

$app->ws('/ws/learn/chatroom', function ($server, $frame) use (&$roomFds, &$fdMeta) {
    $msg = json_decode((string)$frame->data, true);
    if (!is_array($msg)) { return; }
    $type = is_string($msg['type'] ?? null) ? $msg['type'] : '';

    switch ($type) {
        case 'join':
            $room     = is_string($msg['room'] ?? null) ? $msg['room'] : 'general';
            $username = is_string($msg['username'] ?? null) ? $msg['username'] : 'anonymous';
            $fdMeta[$frame->fd] = ['room' => $room, 'username' => $username];
            $roomFds[$room][$frame->fd] = true;

            // Send history (last 50) to the joining client only.
            $server->push($frame->fd, (string) json_encode([
                'type'   => 'history',
                'room'   => $room,
                'items'  => Chatroom::recent($room, 50),
            ]));

            // Persist + broadcast a system "X joined" line to all room members.
            $sys = Chatroom::saveMessage($room, $username, "joined #{$room}", 'system');
            broadcast_to_room($server, $roomFds, $room, [
                'type'   => 'message',
                'message'=> $sys,
            ]);
            break;

        case 'message':
            $meta = $fdMeta[$frame->fd] ?? null;
            $body = is_string($msg['body'] ?? null) ? $msg['body'] : '';
            if ($meta === null || trim($body) === '') { return; }
            $row = Chatroom::saveMessage($meta['room'], $meta['username'], $body);
            broadcast_to_room($server, $roomFds, $meta['room'], [
                'type'    => 'message',
                'message' => $row,
            ]);
            break;
    }
}, onOpen: function ($server, $request) use (&$fdMeta) {
    // Initial connection — meta is populated by the first 'join' frame.
}, onClose: function ($server, $fd) use (&$roomFds, &$fdMeta) {
    $meta = $fdMeta[$fd] ?? null;
    if ($meta !== null) {
        unset($roomFds[$meta['room']][$fd]);
        if (isset($roomFds[$meta['room']]) && $roomFds[$meta['room']] === []) {
            unset($roomFds[$meta['room']]);
        }
        try {
            $sys = Chatroom::saveMessage($meta['room'], $meta['username'], "left #{$meta['room']}", 'system');
            broadcast_to_room($server, $roomFds, $meta['room'], [
                'type'    => 'message',
                'message' => $sys,
            ]);
        } catch (\Throwable $e) { /* tolerant — close path */ }
        unset($fdMeta[$fd]);
    }
});

/**
 * Per-room fan-out helper. In a single-server app, "broadcast to room X" is
 * just "push to every fd in this worker that's joined room X". Lesson 23
 * shows the cross-server upgrade via WSRouter::room().
 *
 * @param array<string, array<int, true>> $roomFds
 * @param array<string, mixed>            $payload
 */
function broadcast_to_room($server, array &$roomFds, string $room, array $payload): void
{
    if (!isset($roomFds[$room])) { return; }
    $data = (string) json_encode($payload);
    if (!($server instanceof \OpenSwoole\WebSocket\Server)) { return; }
    foreach (array_keys($roomFds[$room]) as $fd) {
        if ($server->isEstablished((int)$fd)) {
            $server->push((int)$fd, $data);
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
