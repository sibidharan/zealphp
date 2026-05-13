<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>ZealPHP · WebSocket Demo</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:system-ui,sans-serif;background:#0d1117;color:#c9d1d9;display:flex;flex-direction:column;height:100vh}
    header{background:#161b22;border-bottom:1px solid #30363d;padding:.8rem 1.5rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
    header h1{font-size:1.1rem;color:#f0f6fc}
    .badge{border-radius:4px;padding:2px 8px;font-size:.72rem;font-weight:600}
    .tabs{background:#161b22;border-bottom:1px solid #30363d;display:flex;gap:0;overflow-x:auto}
    .tab{padding:.55rem 1rem;cursor:pointer;font-size:.85rem;border-bottom:2px solid transparent;color:#8b949e;white-space:nowrap}
    .tab.active{color:#f0f6fc;border-bottom-color:#58a6ff}
    .main{flex:1;display:flex;flex-direction:column;padding:.8rem;gap:.6rem;overflow:hidden}
    #log{flex:1;background:#0d1117;border:1px solid #30363d;border-radius:6px;padding:.7rem;overflow-y:auto;font-family:monospace;font-size:.78rem;min-height:0}
    .msg{padding:2px 0;border-bottom:1px solid #1c2128}
    .msg.sent{color:#58a6ff}.msg.recv{color:#3fb950}.msg.sys{color:#8b949e;font-style:italic}.msg.err{color:#f85149}.msg.hb{color:#444}
    .ctrl{display:flex;gap:.4rem;flex-wrap:wrap;align-items:center}
    input[type=text]{flex:1;min-width:120px;background:#161b22;border:1px solid #30363d;border-radius:6px;color:#f0f6fc;padding:.4rem .7rem;font-size:.85rem;outline:none}
    input[type=text]:focus{border-color:#58a6ff}
    button{padding:.4rem .9rem;border-radius:6px;border:none;cursor:pointer;font-size:.82rem;font-weight:500}
    .btn-g{background:#238636;color:#fff}.btn-r{background:#da3633;color:#fff}.btn-b{background:#1f6feb;color:#fff}.btn-d{background:#30363d;color:#c9d1d9}
    .status{font-size:.78rem;color:#8b949e}.status.on{color:#3fb950}.status.off{color:#f85149}
    .hint{font-size:.75rem;color:#484f58;margin-top:.3rem}
  </style>
</head>
<body>

<header>
  <h1>ZealPHP WebSocket</h1>
  <span class="badge" id="mode-badge" style="background:#0a3069;color:#58a6ff">echo</span>
  <span class="status off" id="status">Disconnected</span>
</header>

<div class="tabs">
  <div class="tab active"  onclick="sw('echo',this)">Echo <small>/ws/echo</small></div>
  <div class="tab"         onclick="sw('broadcast',this)">Broadcast <small>/ws/broadcast</small></div>
  <div class="tab"         onclick="sw('ticker',this)">Ticker <small>/ws/ticker</small></div>
  <div class="tab"         onclick="sw('rooms',this)">Rooms <small>/ws/rooms</small></div>
  <div class="tab"         onclick="sw('auth',this)">Auth <small>/ws/auth</small></div>
  <div class="tab"         onclick="sw('binary',this)">Binary <small>/ws/binary</small></div>
</div>

<div class="main">
  <div class="ctrl">
    <button class="btn-g" onclick="connect()">Connect</button>
    <button class="btn-r" onclick="disconnect()">Disconnect</button>
    <button class="btn-d" onclick="clearLog()">Clear</button>
    <!-- Rooms extras -->
    <span id="rooms-extras" style="display:none;gap:.4rem;display:none">
      Room: <input type="text" id="room-name" value="general" style="width:90px">
      Nick: <input type="text" id="room-uid"  value="guest" style="width:80px">
    </span>
    <!-- Auth extras -->
    <span id="auth-extras" style="display:none">
      Token: <input type="text" id="auth-token" value="secret" style="width:80px">
    </span>
  </div>

  <div id="log"></div>

  <div class="ctrl">
    <input type="text" id="msg" placeholder="Type a message…" onkeydown="if(event.key==='Enter')send()">
    <button class="btn-b" onclick="send()">Send</button>
    <!-- Binary tab extra -->
    <button class="btn-d" id="send-binary-btn" style="display:none" onclick="sendBinary()">Send Binary</button>
  </div>
  <p class="hint" id="hint"></p>
</div>

<script>
let ws = null, mode = 'echo';

const colors  = {echo:'#0a3069',broadcast:'#2d1f00',ticker:'#0d2d0d',rooms:'#1a0a4a',auth:'#2d0a0a',binary:'#0a2d1a'};
const hints   = {
  echo:      'Each message is echoed back verbatim.',
  broadcast: 'Every message goes to ALL connected clients.',
  ticker:    'Server pushes every 1s. Send "stop" to end.',
  rooms:     'Messages reach everyone in the same room (cross-worker via Table).',
  auth:      'Upgrade rejected if no token=secret param or valid session cookie.',
  binary:    'Text frames get JSON echo. Binary frames get binary echo.',
};

function sw(m, el) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  if (ws) disconnect();
  mode = m;
  document.getElementById('mode-badge').textContent = m;
  document.getElementById('mode-badge').style.background = colors[m];
  document.getElementById('rooms-extras').style.display = m === 'rooms' ? 'flex' : 'none';
  document.getElementById('auth-extras').style.display  = m === 'auth'  ? 'inline-flex' : 'none';
  document.getElementById('send-binary-btn').style.display = m === 'binary' ? '' : 'none';
  document.getElementById('hint').textContent = hints[m] || '';
  clearLog();
}

function wsUrl() {
  if (mode === 'rooms') {
    const room = document.getElementById('room-name').value || 'general';
    const uid  = document.getElementById('room-uid').value  || 'guest';
    return `ws://${location.host}/ws/rooms?room=${encodeURIComponent(room)}&uid=${encodeURIComponent(uid)}`;
  }
  if (mode === 'auth') {
    const tok = document.getElementById('auth-token').value;
    return `ws://${location.host}/ws/auth` + (tok ? `?token=${encodeURIComponent(tok)}` : '');
  }
  return `ws://${location.host}/ws/${mode}`;
}

function connect() {
  if (ws) ws.close();
  const url = wsUrl();
  log(`Connecting → ${url}`, 'sys');
  ws = new WebSocket(url);
  ws.binaryType = 'arraybuffer';

  ws.onopen  = () => { setStatus(true); log('WebSocket open ✓', 'sys'); };
  ws.onclose = e  => { setStatus(false); log(`Closed (${e.code}) ${e.reason}`, 'sys'); ws = null; };
  ws.onerror = ()  => log('Connection error', 'err');
  ws.onmessage = e => {
    if (e.data instanceof ArrayBuffer) {
      const view = new Uint8Array(e.data);
      log(`[binary ${view.length} bytes] ${Array.from(view.slice(0,12)).map(b=>b.toString(16).padStart(2,'0')).join(' ')}…`, 'recv');
    } else {
      try { log(JSON.stringify(JSON.parse(e.data), null, 2), 'recv'); }
      catch { log(e.data, 'recv'); }
    }
  };
}

function disconnect() { if (ws) ws.close(); }

function send() {
  const input = document.getElementById('msg');
  const text  = input.value.trim();
  if (!text || !ws || ws.readyState !== 1) return;
  ws.send(text);
  log(text, 'sent');
  input.value = '';
}

function sendBinary() {
  if (!ws || ws.readyState !== 1) return;
  // Send a small binary payload: 0x00..0x0F
  const buf = new Uint8Array(16).map((_, i) => i);
  ws.send(buf.buffer);
  log(`[sent binary ${buf.length} bytes]`, 'sent');
}

function log(text, cls) {
  if (cls === 'hb') return; // suppress heartbeat noise — comment out to show
  const box = document.getElementById('log');
  const el  = document.createElement('div');
  el.className = 'msg ' + (cls || '');
  const ts = new Date().toLocaleTimeString();
  el.textContent = `[${ts}] ${text}`;
  box.appendChild(el);
  box.scrollTop = box.scrollHeight;
}

function clearLog() { document.getElementById('log').innerHTML = ''; }

function setStatus(on) {
  const el = document.getElementById('status');
  el.textContent = on ? 'Connected' : 'Disconnected';
  el.className   = 'status ' + (on ? 'on' : 'off');
}

// Initialize hint
document.getElementById('hint').textContent = hints['echo'];
</script>
</body>
</html>
