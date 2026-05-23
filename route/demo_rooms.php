<?php

declare(strict_types=1);

// Live demos for WSRouter::room() — federated WebSocket rooms (v0.3.0 P1.1).
//
// Only wires when the Store backend is Redis (rooms require pub/sub).
// Routes:
//   GET /demo/rooms/join?room=<name>&client=<id>     → Room::join
//   GET /demo/rooms/leave?room=<name>&client=<id>    → Room::leave
//   GET /demo/rooms/members?room=<name>              → roster + size
//   GET /demo/rooms/push?room=<name>&msg=<payload>   → broadcast to room
//   GET /demo/rooms/state                            → local cache snapshot

use ZealPHP\App;
use ZealPHP\HTTP\Request;
use ZealPHP\Store;
use ZealPHP\WSRouter;

if (!(Store::defaultBackend() instanceof \ZealPHP\Store\RedisBackend)) {
    // Table backend → demo routes throw a clear "needs Redis" message rather
    // than silently 404. Still safe to load the file (no init side-effects).
    $app->route('/demo/rooms/{action}', ['methods' => ['GET']], function (string $action) {
        return ['ok' => false, 'reason' => 'WSRouter::room demos require Redis backend (Store::defaultBackend(StoreBackendKind::Redis) OR ZEALPHP_STORE_BACKEND=redis)'];
    });
    return;
}

// Init WSRouter once — must run before App::run() spawns workers.
// Routes are loaded BEFORE worker fork (per App::run() boot order), so this
// is the right place. Idempotent.
WSRouter::init();

$app->route('/demo/rooms/join', ['methods' => ['GET']], function (Request $request) {
    $room   = is_string($request->get['room']   ?? null) ? (string) $request->get['room']   : '';
    $client = is_string($request->get['client'] ?? null) ? (string) $request->get['client'] : '';
    if ($room === '' || $client === '') {
        return ['ok' => false, 'reason' => 'usage: ?room=<name>&client=<id>'];
    }
    WSRouter::room($room)->join($client);
    return ['ok' => true, 'room' => $room, 'client' => $client, 'action' => 'joined'];
});

$app->route('/demo/rooms/leave', ['methods' => ['GET']], function (Request $request) {
    $room   = is_string($request->get['room']   ?? null) ? (string) $request->get['room']   : '';
    $client = is_string($request->get['client'] ?? null) ? (string) $request->get['client'] : '';
    if ($room === '' || $client === '') {
        return ['ok' => false, 'reason' => 'usage: ?room=<name>&client=<id>'];
    }
    WSRouter::room($room)->leave($client);
    return ['ok' => true, 'room' => $room, 'client' => $client, 'action' => 'left'];
});

$app->route('/demo/rooms/members', ['methods' => ['GET']], function (Request $request) {
    $room = is_string($request->get['room'] ?? null) ? (string) $request->get['room'] : '';
    if ($room === '') {
        return ['ok' => false, 'reason' => 'usage: ?room=<name>'];
    }
    $r = WSRouter::room($room);
    return [
        'ok'      => true,
        'room'    => $room,
        'size'    => $r->size(),
        'members' => $r->members(),
    ];
});

$app->route('/demo/rooms/push', ['methods' => ['GET']], function (Request $request) {
    $room = is_string($request->get['room'] ?? null) ? (string) $request->get['room'] : '';
    $msg  = is_string($request->get['msg']  ?? null) ? (string) $request->get['msg']  : 'hello';
    if ($room === '') {
        return ['ok' => false, 'reason' => 'usage: ?room=<name>&msg=<payload>'];
    }
    $receivers = WSRouter::room($room)->push(['type' => 'message', 'from' => 'demo', 'data' => $msg, 'ts' => time()]);
    return ['ok' => true, 'room' => $room, 'msg' => $msg, 'receivers' => $receivers];
});

$app->route('/demo/rooms/state', ['methods' => ['GET']], function () {
    return [
        'ok'                     => true,
        'server_id'              => WSRouter::serverId(),
        'local_room_membership'  => WSRouter::localRoomMembership(),
        'local_fds'              => WSRouter::localFds(),
    ];
});
