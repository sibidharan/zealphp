<?php
/**
 * WebSocket Routes
 *
 * $app->ws($path, $onMessage, $onOpen, $onClose)
 *
 * The server upgrades HTTP connections at $path to WebSocket automatically.
 * Each callback receives ($server, $frame/$request, $g).
 *
 * Test with wscat:  npm install -g wscat
 *   wscat -c ws://localhost:8080/ws/echo
 *   wscat -c ws://localhost:8080/ws/broadcast
 *
 * Or open the browser demo at http://localhost:8080/ws
 */

use OpenSwoole\Coroutine as co;
use ZealPHP\App;
use ZealPHP\Store;

$app = App::instance();

// ---------------------------------------------------------------------------
// Shared cross-worker client registry for the /ws/rooms endpoint.
// MUST be created here (before $server->start()) so all forked workers
// inherit the same OpenSwoole\Table shared-memory segment.
// ---------------------------------------------------------------------------
Store::make('ws_rooms', 4096, [
    'room' => [\OpenSwoole\Table::TYPE_STRING, 64],
    'uid'  => [\OpenSwoole\Table::TYPE_STRING, 128],
]);

// ---------------------------------------------------------------------------
// 1. Echo — sends back exactly what it receives
// ---------------------------------------------------------------------------
$app->ws(
    '/ws/echo',
    onMessage: function($server, $frame, $g) {
        $server->push($frame->fd, 'echo: ' . $frame->data);
    },
    onOpen: function($server, $request, $g) {
        $server->push($request->fd, json_encode([
            'event'   => 'connected',
            'path'    => '/ws/echo',
            'message' => 'Send anything — I echo it back.',
        ]));
    },
    onClose: function($server, $fd, $g) {
        // nothing to clean up for echo
    }
);

// ---------------------------------------------------------------------------
// 2. Broadcast — every message goes to ALL connected clients on this path
// ---------------------------------------------------------------------------
$broadcastClients = [];   // fd → true

$app->ws(
    '/ws/broadcast',
    onMessage: function($server, $frame, $g) use (&$broadcastClients) {
        $payload = json_encode([
            'from'    => $frame->fd,
            'message' => $frame->data,
            'time'    => date('H:i:s'),
        ]);
        foreach (array_keys($broadcastClients) as $fd) {
            if ($server->isEstablished($fd)) {
                $server->push($fd, $payload);
            }
        }
    },
    onOpen: function($server, $request, $g) use (&$broadcastClients) {
        $broadcastClients[$request->fd] = true;
        $server->push($request->fd, json_encode([
            'event'   => 'connected',
            'clients' => count($broadcastClients),
            'message' => 'You are in the broadcast room. Messages go to everyone.',
        ]));
    },
    onClose: function($server, $fd, $g) use (&$broadcastClients) {
        unset($broadcastClients[$fd]);
    }
);

// ---------------------------------------------------------------------------
// 3. Ticker — server pushes a counter every second until client disconnects
// ---------------------------------------------------------------------------
$app->ws(
    '/ws/ticker',
    onMessage: function($server, $frame, $g) {
        // Client can send "stop" to close
        if (trim($frame->data) === 'stop') {
            $server->push($frame->fd, json_encode(['event' => 'stopped']));
            $server->close($frame->fd);
        }
    },
    onOpen: function($server, $request, $g) {
        $fd = $request->fd;
        $server->push($fd, json_encode(['event' => 'connected', 'message' => 'Ticking every second. Send "stop" to end.']));
        // Spawn a coroutine that ticks while the connection is alive
        go(function() use ($server, $fd) {
            $i = 0;
            while ($server->isEstablished($fd)) {
                co::sleep(1);
                if (!$server->isEstablished($fd)) break;
                $server->push($fd, json_encode(['tick' => ++$i, 'time' => date('H:i:s')]));
            }
        });
    }
);

// ---------------------------------------------------------------------------
// 4. Rooms — cross-worker chat using Store (OpenSwoole\Table)
//
//    Every client's fd is recorded in shared memory along with its room name
//    and uid. Because Store uses OpenSwoole\Table (shared-memory spinlocks),
//    all worker processes see the same data — clients on different workers
//    can exchange messages.
//
//    wscat -c "ws://localhost:8080/ws/rooms?room=general&uid=alice"
// ---------------------------------------------------------------------------
$app->ws(
    '/ws/rooms',
    onOpen: function($server, $request, $g) {
        $room = $request->get['room'] ?? 'general';
        $uid  = $request->get['uid']  ?? ($g->session['UNIQUE_REQUEST_ID'] ?? ('guest_' . $request->fd));
        Store::set('ws_rooms', (string)$request->fd, ['room' => $room, 'uid' => $uid]);
        $server->push($request->fd, json_encode([
            'event'  => 'joined',
            'room'   => $room,
            'uid'    => $uid,
            'online' => Store::count('ws_rooms'),
        ]));
    },
    onMessage: function($server, $frame, $g) {
        $me = Store::get('ws_rooms', (string)$frame->fd);
        if (!$me) return;
        $myRoom = $me['room'];
        $payload = json_encode([
            'from'  => $me['uid'],
            'msg'   => $frame->data,
            'room'  => $myRoom,
            'time'  => date('H:i:s'),
        ]);
        // Iterate ALL clients in shared memory — works across workers
        foreach (Store::table('ws_rooms') as $fd => $info) {
            if ($info['room'] === $myRoom && $server->isEstablished((int)$fd)) {
                $server->push((int)$fd, $payload);
            }
        }
    },
    onClose: function($server, $fd, $g) {
        Store::del('ws_rooms', (string)$fd);
    }
);

// ---------------------------------------------------------------------------
// 5. Auth on upgrade — validate session cookie / query token before accepting.
//    WebSocket upgrade bypasses PSR-15 middleware; check auth manually in onOpen.
//
//    wscat -c "ws://localhost:8080/ws/auth"                # rejected (4001)
//    wscat -c "ws://localhost:8080/ws/auth?token=secret"   # accepted
// ---------------------------------------------------------------------------
$app->ws(
    '/ws/auth',
    onOpen: function($server, $request, $g) {
        $token  = $request->get['token'] ?? null;
        $sessid = $request->cookie['PHPSESSID'] ?? null;
        $authed = ($token === 'secret') || ($sessid && strlen($sessid) >= 10);

        if (!$authed) {
            $server->push($request->fd, json_encode([
                'error' => 'Unauthorized — pass ?token=secret or hold a valid session cookie',
            ]));
            $server->disconnect($request->fd, 4001, 'Unauthorized');
            return;
        }
        $server->push($request->fd, json_encode([
            'event'   => 'authenticated',
            'via'     => $token ? 'token' : 'session',
            'uid'     => $sessid ? substr($sessid, 0, 6) . '…' : 'token-user',
            'message' => 'Connected securely. Send anything.',
        ]));
    },
    onMessage: function($server, $frame, $g) {
        $server->push($frame->fd, json_encode([
            'secure_echo' => $frame->data,
            'time'        => date('H:i:s'),
        ]));
    }
);

// ---------------------------------------------------------------------------
// 6. Binary frames — shows opcode-aware handling.
//    The framework filters PING/PONG/CONTINUATION automatically.
//    TEXT(1) and BINARY(2) both arrive in onMessage; check $frame->opcode.
//
//    Browser: public/ws.php → Binary tab
// ---------------------------------------------------------------------------
$app->ws(
    '/ws/binary',
    onOpen: function($server, $request, $g) {
        $server->push($request->fd, json_encode([
            'info'    => 'Send text or binary. Binary frames are echoed back as binary.',
            'opcodes' => ['TEXT=1', 'BINARY=2'],
        ]));
    },
    onMessage: function($server, $frame, $g) {
        if ($frame->opcode === \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_BINARY) {
            $server->push($frame->fd, $frame->data, \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_BINARY);
        } else {
            $server->push($frame->fd, json_encode([
                'text_echo' => $frame->data,
                'bytes'     => strlen($frame->data),
            ]));
        }
    }
);

// ---------------------------------------------------------------------------
// 7. Heartbeat — server sends a ping to all connected clients every 30 seconds.
//    Registered via App::onWorkerStart() so each worker runs its own timer.
// ---------------------------------------------------------------------------
App::onWorkerStart(function($server, $workerId) {
    // OpenSwoole caps getClientList find_count at 100 — paginate to cover all fds
    App::tick(30000, function() use ($server) {
        $startFd = 0;
        do {
            $fds = $server->getClientList($startFd, 100);
            if (!$fds) break;
            foreach ($fds as $fd) {
                if ($server->isEstablished($fd)) {
                    $server->push($fd, json_encode([
                        'type'   => 'heartbeat',
                        'ts'     => time(),
                        'worker' => getmypid(),
                    ]));
                }
            }
            $startFd = max($fds) + 1;
        } while (count($fds) === 100);
    });
});
