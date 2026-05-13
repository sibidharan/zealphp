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

$app = App::instance();

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
