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
    <p style="font-size:.85rem;margin-bottom:.75rem"><span class="badge badge-ws">WS</span> <code>/ws/rooms?room=general</code> — cross-worker rooms via <code>Store</code> (OpenSwoole\Table). Every worker shares the same client registry.</p>
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

	<h2 style="margin:2rem 0 1rem">Browser JavaScript</h2>
	<?php App::render('/components/_code', [
	    'label' => 'HTTPS-aware browser client',
	    'lang'  => 'javascript',
	    'code'  => <<<'JS'
	const endpoint = '/ws/echo';
	const scheme = location.protocol === 'https:' ? 'wss://' : 'ws://';
	const socket = new WebSocket(scheme + location.host + endpoint);

	socket.addEventListener('open', () => {
	    socket.send('Hello from the browser');
	});

	socket.addEventListener('message', event => {
	    console.log('received:', event.data);
	});

	function sendWhenReady(message) {
	    if (socket.readyState === WebSocket.OPEN) {
	        socket.send(message);
	        return;
	    }

	    socket.addEventListener('open', () => socket.send(message), { once: true });
	}
	JS]); ?>

		<h2 style="margin:2rem 0 1rem">Live browser client</h2>
	<div class="ws-shell">
	  <div class="ws-topbar">
	    <div class="ws-status" id="ws-status" data-state="closed">
	      <span class="ws-dot" aria-hidden="true"></span>
	      <span id="ws-state-text">Disconnected</span>
	    </div>
	    <div class="ws-meta">
	      <code id="ws-url">/ws/echo</code>
	      <span id="ws-counts">0 sent / 0 received</span>
	    </div>
	  </div>
	  <div class="ws-log" id="ws-log"><div class="ws-msg sys">Choose an endpoint and connect. Send will auto-connect if needed.</div></div>
	  <div class="ws-quick">
	    <button class="ws-chip" type="button" data-msg="Hello from the browser">Hello</button>
	    <button class="ws-chip" type="button" data-msg="Broadcast test">Broadcast</button>
	    <button class="ws-chip" type="button" data-msg="stop">Stop ticker</button>
	    <button class="ws-chip" type="button" data-msg="Binary test payload">Binary text</button>
	    <button class="ws-chip" type="button" data-action="clear">Clear log</button>
	  </div>
	  <div class="ws-controls">
	    <select id="ws-mode" class="ws-select" onchange="wsUpdateHint()">
	      <option value="echo">Echo (/ws/echo)</option>
	      <option value="broadcast">Broadcast (/ws/broadcast)</option>
	      <option value="ticker">Ticker (/ws/ticker)</option>
      <option value="rooms">Rooms (/ws/rooms?room=general)</option>
      <option value="auth?token=secret">Auth w/ token (/ws/auth?token=secret)</option>
      <option value="auth">Auth NO token (/ws/auth — rejected)</option>
      <option value="binary">Binary (/ws/binary)</option>
	    </select>
	    <button class="btn btn-primary btn-sm" id="ws-connect" onclick="wsConnect()">Connect</button>
	    <button class="btn btn-ghost btn-sm" id="ws-disconnect" onclick="wsDisconnect()">Disconnect</button>
	    <input class="ws-input" id="ws-msg" placeholder="Type a message…" onkeydown="if(event.key==='Enter')wsSend()">
	    <button class="btn btn-primary btn-sm" id="ws-send" onclick="wsSend()">Send</button>
	  </div>
	</div>
	</div>
	</section>
	<script>
	let ws2 = null;
	let wsQueuedMessage = '';
	const wsStats = { sent: 0, recv: 0 };

	function wsEndpoint() {
	  const mode = document.getElementById('ws-mode').value;
	  const scheme = location.protocol === 'https:' ? 'wss://' : 'ws://';
	  return scheme + location.host + '/ws/' + mode;
	}

	function wsSetState(state, text) {
	  const status = document.getElementById('ws-status');
	  status.dataset.state = state;
	  document.getElementById('ws-state-text').textContent = text;
	  document.getElementById('ws-connect').disabled = state === 'open' || state === 'connecting';
	  document.getElementById('ws-disconnect').disabled = state !== 'open' && state !== 'connecting';
	}

	function wsUpdateCounts() {
	  document.getElementById('ws-counts').textContent = wsStats.sent + ' sent / ' + wsStats.recv + ' received';
	}

	function wsLog(text, cls) {
	  const box = document.getElementById('ws-log');
	  const el = document.createElement('div');
	  el.className = 'ws-msg ' + (cls||'');
	  el.textContent = '[' + new Date().toLocaleTimeString() + '] ' + text;
  box.appendChild(el);
  box.scrollTop = box.scrollHeight;
	}
	function wsConnect() {
	  if (ws2 && (ws2.readyState === WebSocket.OPEN || ws2.readyState === WebSocket.CONNECTING)) return;
	  const url = wsEndpoint();
	  wsSetState('connecting', 'Connecting');
	  wsLog('Connecting -> ' + url, 'sys');
	  ws2 = new WebSocket(url);
	  ws2.binaryType = 'arraybuffer';
	  ws2.onopen  = () => {
	    wsSetState('open', 'Connected');
	    wsLog('Connected', 'sys');
	    if (wsQueuedMessage) {
	      const queued = wsQueuedMessage;
	      wsQueuedMessage = '';
	      wsSendText(queued);
	    }
	  };
	  ws2.onclose = e => {
	    wsSetState('closed', 'Disconnected');
	    wsLog('Closed (' + e.code + ')', 'sys');
	    ws2 = null;
	  };
	  ws2.onerror = () => {
	    wsSetState('error', 'Error');
	    wsLog('Connection error', 'err');
	  };
	  ws2.onmessage = e => {
	    if (e.data instanceof ArrayBuffer) {
	      wsLog('[binary ' + e.data.byteLength + ' bytes]', 'recv');
	    } else {
	      try { wsLog(JSON.stringify(JSON.parse(e.data), null, 2), 'recv'); }
	      catch { wsLog(e.data, 'recv'); }
	    }
	    wsStats.recv++;
	    wsUpdateCounts();
	  };
	}
	function wsDisconnect() {
	  wsQueuedMessage = '';
	  if (ws2) ws2.close();
	  else wsSetState('closed', 'Disconnected');
	}
	function wsSend() {
	  const input = document.getElementById('ws-msg');
	  const text = input.value.trim();
	  if (!text) {
	    wsLog('Type a message or choose a quick message.', 'err');
	    input.focus();
	    return;
	  }
	  if (!ws2 || ws2.readyState !== WebSocket.OPEN) {
	    wsQueuedMessage = text;
	    wsLog('Queued until connected: ' + text, 'sys');
	    wsConnect();
	    input.value = '';
	    return;
	  }
	  wsSendText(text);
	  input.value = '';
	}
	function wsSendText(text) {
	  if (!ws2 || ws2.readyState !== WebSocket.OPEN) return;
	  ws2.send(text);
	  wsLog(text, 'sent');
	  wsStats.sent++;
	  wsUpdateCounts();
	}
	function wsUpdateHint() {
	  document.getElementById('ws-url').textContent = wsEndpoint().replace(location.origin.replace(/^http/, 'ws'), '');
	  const input = document.getElementById('ws-msg');
	  const mode = document.getElementById('ws-mode').value;
	  input.placeholder = mode.startsWith('ticker') ? 'Send "stop" to close ticker...' : 'Type a message...';
	  if (ws2 && ws2.readyState === WebSocket.OPEN) {
	    wsDisconnect();
	    wsLog('Endpoint changed. Reconnect to use the new route.', 'sys');
	  }
	}
	document.querySelectorAll('.ws-chip').forEach(btn => {
	  btn.addEventListener('click', () => {
	    if (btn.dataset.action === 'clear') {
	      document.getElementById('ws-log').innerHTML = '';
	      return;
	    }
	    const input = document.getElementById('ws-msg');
	    input.value = btn.dataset.msg || '';
	    wsSend();
	  });
	});
	wsUpdateHint();
	wsSetState('closed', 'Disconnected');
	wsUpdateCounts();
	</script>
