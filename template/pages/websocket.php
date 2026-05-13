<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">WebSocket</h1>
<p class="section-desc"><code>App::ws($path, $onMessage, $onOpen, $onClose)</code> — register a WebSocket endpoint. ZealPHP uses <code>OpenSwoole\WebSocket\Server</code> which is backward-compatible with HTTP routes on the same port.</p>

<?php App::render('/components/_code', [
    'label' => 'WebSocket registration',
    'code'  => <<<'PHP'
$app->ws(
    '/ws/chat',
    onMessage: function($server, $frame, $g) {
        // $frame->data   — message text
        // $frame->fd     — connection id
        // $frame->opcode — 1=TEXT, 2=BINARY (PING/PONG filtered automatically)
        $server->push($frame->fd, 'echo: ' . $frame->data);
    },
    onOpen: function($server, $request, $g) {
        // $request->fd     — connection id
        // $request->cookie — cookies from upgrade request
        // $request->get    — query params from ws://host/path?key=val
        $server->push($request->fd, json_encode(['event' => 'connected']));
    },
    onClose: function($server, $fd, $g) {
        // clean up per-connection state
    }
);
PHP]); ?>

<h2 style="margin:2rem 0 1rem">Live demo — 6 endpoints</h2>

<div class="tabs" data-group="ws"><div class="tab-btn active" data-tab="ws-echo" data-group="ws">Echo</div><div class="tab-btn" data-tab="ws-broadcast" data-group="ws">Broadcast</div><div class="tab-btn" data-tab="ws-ticker" data-group="ws">Ticker</div><div class="tab-btn" data-tab="ws-rooms" data-group="ws">Rooms</div><div class="tab-btn" data-tab="ws-auth" data-group="ws">Auth</div><div class="tab-btn" data-tab="ws-binary" data-group="ws">Binary</div></div>
<div data-panel-group="ws">
  <div class="tab-panel active" id="ws-echo">
    <p style="font-size:.85rem;margin-bottom:.75rem"><span class="badge badge-ws">WS</span> <code>/ws/echo</code> — mirrors every message back verbatim.</p>
    <?php App::render('/components/_code', ['code' => '$app->ws(\'/ws/echo\', onMessage: fn($server,$frame) => $server->push($frame->fd, \'echo: \'.$frame->data));']); ?>
  </div>
  <div class="tab-panel" id="ws-broadcast">
    <p style="font-size:.85rem;margin-bottom:.75rem"><span class="badge badge-ws">WS</span> <code>/ws/broadcast</code> — every message goes to ALL connected clients.</p>
    <?php App::render('/components/_code', ['code' => <<<'PHP'
$broadcastClients = [];
$app->ws('/ws/broadcast',
    onMessage: function($server, $frame, $g) use (&$broadcastClients) {
        foreach (array_keys($broadcastClients) as $fd) {
            if ($server->isEstablished($fd))
                $server->push($fd, json_encode(['from'=>$frame->fd,'msg'=>$frame->data]));
        }
    },
    onOpen:  fn($s,$req) => $broadcastClients[$req->fd] = true,
    onClose: fn($s,$fd)  => unset($broadcastClients[$fd])
);
PHP]); ?>
  </div>
  <div class="tab-panel" id="ws-ticker">
    <p style="font-size:.85rem;margin-bottom:.75rem"><span class="badge badge-ws">WS</span> <code>/ws/ticker</code> — server pushes every 1s using a spawned coroutine.</p>
    <?php App::render('/components/_code', ['code' => <<<'PHP'
$app->ws('/ws/ticker',
    onMessage: fn($s,$f) => trim($f->data)==='stop' ? $s->close($f->fd) : null,
    onOpen: function($server, $request, $g) {
        $fd = $request->fd;
        go(function() use ($server, $fd) {
            $i = 0;
            while ($server->isEstablished($fd)) {
                co::sleep(1);
                $server->push($fd, json_encode(['tick' => ++$i, 'time' => date('H:i:s')]));
            }
        });
    }
);
PHP]); ?>
  </div>
  <div class="tab-panel" id="ws-rooms">
    <p style="font-size:.85rem;margin-bottom:.75rem"><span class="badge badge-ws">WS</span> <code>/ws/rooms?room=general</code> — cross-worker rooms via <code>Store</code> (OpenSwoole\Table). All 24 workers share the same client registry.</p>
    <?php App::render('/components/_code', ['code' => <<<'PHP'
// Shared across all workers — created before run()
Store::make('ws_rooms', 4096, [
    'room' => [\OpenSwoole\Table::TYPE_STRING, 64],
    'uid'  => [\OpenSwoole\Table::TYPE_STRING, 128],
]);

$app->ws('/ws/rooms',
    onOpen: fn($server, $request, $g) => Store::set('ws_rooms', (string)$request->fd, [
        'room' => $request->get['room'] ?? 'general',
        'uid'  => $request->get['uid']  ?? 'guest_'.$request->fd,
    ]),
    onMessage: function($server, $frame, $g) {
        $me = Store::get('ws_rooms', (string)$frame->fd);
        foreach (Store::table('ws_rooms') as $fd => $info)
            if ($info['room'] === $me['room'] && $server->isEstablished((int)$fd))
                $server->push((int)$fd, json_encode(['from'=>$me['uid'],'msg'=>$frame->data]));
    },
    onClose: fn($server, $fd, $g) => Store::del('ws_rooms', (string)$fd)
);
PHP]); ?>
  </div>
  <div class="tab-panel" id="ws-auth">
    <p style="font-size:.85rem;margin-bottom:.75rem"><span class="badge badge-ws">WS</span> <code>/ws/auth?token=secret</code> — validates token in onOpen, disconnects with code 4001 if invalid.</p>
    <?php App::render('/components/_code', ['code' => <<<'PHP'
$app->ws('/ws/auth',
    onOpen: function($server, $request, $g) {
        $token = $request->get['token'] ?? null;
        if ($token !== 'secret') {
            $server->push($request->fd, json_encode(['error' => 'Unauthorized']));
            $server->disconnect($request->fd, 4001, 'Unauthorized');
            return;
        }
        $server->push($request->fd, json_encode(['event' => 'authenticated']));
    },
    onMessage: fn($server, $frame) => $server->push($frame->fd, 'secure: '.$frame->data)
);
PHP]); ?>
  </div>
  <div class="tab-panel" id="ws-binary">
    <p style="font-size:.85rem;margin-bottom:.75rem"><span class="badge badge-ws">WS</span> <code>/ws/binary</code> — checks <code>$frame->opcode</code>, echoes binary as binary. PING/PONG filtered automatically by ZealPHP.</p>
    <?php App::render('/components/_code', ['code' => <<<'PHP'
$app->ws('/ws/binary',
    onMessage: function($server, $frame, $g) {
        if ($frame->opcode === \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_BINARY) {
            // Echo raw bytes back as a binary frame
            $server->push($frame->fd, $frame->data, \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_BINARY);
        } else {
            $server->push($frame->fd, json_encode(['bytes' => strlen($frame->data)]));
        }
    }
);
PHP]); ?>
  </div>
</div>

<h2 style="margin:2rem 0 1rem">Browser client</h2>
<div class="ws-shell">
  <div class="ws-log" id="ws-log"><span style="color:#8b949e;font-style:italic">Click Connect above to start…</span></div>
  <div class="ws-controls">
    <select id="ws-mode" style="padding:.4rem .7rem;border:1px solid var(--border);border-radius:5px;font-size:.85rem" onchange="wsUpdateHint()">
      <option value="echo">Echo (/ws/echo)</option>
      <option value="broadcast">Broadcast (/ws/broadcast)</option>
      <option value="ticker">Ticker (/ws/ticker)</option>
      <option value="rooms">Rooms (/ws/rooms?room=general)</option>
      <option value="auth?token=secret">Auth w/ token (/ws/auth?token=secret)</option>
      <option value="auth">Auth NO token (/ws/auth — rejected)</option>
      <option value="binary">Binary (/ws/binary)</option>
    </select>
    <button class="btn btn-primary btn-sm" onclick="wsConnect()">Connect</button>
    <button class="btn btn-ghost btn-sm" onclick="wsDisconnect()">Disconnect</button>
    <input class="ws-input" id="ws-msg" placeholder="Type a message…" onkeydown="if(event.key==='Enter')wsSend()">
    <button class="btn btn-primary btn-sm" onclick="wsSend()">Send</button>
  </div>
</div>
</div>
</section>
<script>
let ws2 = null;
function wsLog(text, cls) {
  const box = document.getElementById('ws-log');
  const el = document.createElement('div');
  el.className = 'ws-msg ' + (cls||'');
  el.textContent = '[' + new Date().toLocaleTimeString() + '] ' + text;
  box.appendChild(el);
  box.scrollTop = box.scrollHeight;
}
function wsConnect() {
  if (ws2) ws2.close();
  const mode = document.getElementById('ws-mode').value;
  const scheme = location.protocol === 'https:' ? 'wss://' : 'ws://';
  const url = scheme + location.host + '/ws/' + mode;
  wsLog('Connecting → ' + url, 'sys');
  ws2 = new WebSocket(url);
  ws2.binaryType = 'arraybuffer';
  ws2.onopen  = () => wsLog('Connected ✓', 'sys');
  ws2.onclose = e => { wsLog('Closed (' + e.code + ')', 'sys'); ws2 = null; };
  ws2.onerror = () => wsLog('Error', 'err');
  ws2.onmessage = e => {
    if (e.data instanceof ArrayBuffer) {
      wsLog('[binary ' + e.data.byteLength + ' bytes]', 'recv');
    } else {
      try { wsLog(JSON.stringify(JSON.parse(e.data), null, 2), 'recv'); }
      catch { wsLog(e.data, 'recv'); }
    }
  };
}
function wsDisconnect() { if (ws2) ws2.close(); }
function wsSend() {
  const input = document.getElementById('ws-msg');
  const text = input.value.trim();
  if (!text || !ws2 || ws2.readyState !== 1) return;
  ws2.send(text);
  wsLog(text, 'sent');
  input.value = '';
}
function wsUpdateHint() {}
</script>
