<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>ZealPHP · WebSocket Demo</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:system-ui,sans-serif;background:#0d1117;color:#c9d1d9;display:flex;flex-direction:column;height:100vh}
    header{background:#161b22;border-bottom:1px solid #30363d;padding:1rem 1.5rem;display:flex;align-items:center;gap:1rem}
    header h1{font-size:1.2rem;color:#f0f6fc}
    .badge{border-radius:4px;padding:2px 8px;font-size:.75rem;font-weight:600}
    .badge.echo{background:#0a3069;color:#58a6ff}
    .badge.bc{background:#2d1f00;color:#e3b341}
    .badge.tick{background:#0d2d0d;color:#3fb950}
    .tabs{background:#161b22;border-bottom:1px solid #30363d;display:flex;gap:0}
    .tab{padding:.6rem 1.2rem;cursor:pointer;font-size:.9rem;border-bottom:2px solid transparent;color:#8b949e}
    .tab.active{color:#f0f6fc;border-bottom-color:#58a6ff}
    .main{flex:1;display:flex;flex-direction:column;padding:1rem;gap:.7rem;overflow:hidden}
    #log{flex:1;background:#0d1117;border:1px solid #30363d;border-radius:6px;padding:.8rem;overflow-y:auto;font-family:monospace;font-size:.82rem;min-height:0}
    .msg{padding:2px 0;border-bottom:1px solid #1c2128}
    .msg.sent{color:#58a6ff}.msg.recv{color:#3fb950}.msg.sys{color:#8b949e;font-style:italic}.msg.err{color:#f85149}
    .input-row{display:flex;gap:.5rem}
    input[type=text]{flex:1;background:#161b22;border:1px solid #30363d;border-radius:6px;color:#f0f6fc;padding:.5rem .8rem;font-size:.9rem;outline:none}
    input[type=text]:focus{border-color:#58a6ff}
    button{padding:.5rem 1rem;border-radius:6px;border:none;cursor:pointer;font-size:.88rem;font-weight:500}
    .btn-connect{background:#238636;color:#fff}.btn-connect:hover{background:#2ea043}
    .btn-disconnect{background:#da3633;color:#fff}.btn-disconnect:hover{background:#f85149}
    .btn-send{background:#1f6feb;color:#fff}.btn-send:hover{background:#388bfd}
    .btn-clear{background:#30363d;color:#c9d1d9}.btn-clear:hover{background:#3c444d}
    .status{font-size:.8rem;color:#8b949e}
    .status.connected{color:#3fb950}.status.disconnected{color:#f85149}
  </style>
</head>
<body>

<header>
  <h1>ZealPHP WebSocket Demo</h1>
  <span class="badge echo" id="mode-badge">echo</span>
</header>

<div class="tabs">
  <div class="tab active" onclick="switchMode('echo',this)">Echo <small>/ws/echo</small></div>
  <div class="tab" onclick="switchMode('broadcast',this)">Broadcast <small>/ws/broadcast</small></div>
  <div class="tab" onclick="switchMode('ticker',this)">Ticker <small>/ws/ticker</small></div>
</div>

<div class="main">
  <div style="display:flex;align-items:center;gap:.7rem;flex-wrap:wrap">
    <button class="btn-connect"    onclick="connect()">Connect</button>
    <button class="btn-disconnect" onclick="disconnect()">Disconnect</button>
    <button class="btn-clear"      onclick="clearLog()">Clear</button>
    <span class="status disconnected" id="status">Disconnected</span>
  </div>

  <div id="log"></div>

  <div class="input-row">
    <input type="text" id="msg" placeholder="Type a message…" onkeydown="if(event.key==='Enter')send()">
    <button class="btn-send" onclick="send()">Send</button>
  </div>
</div>

<script>
  let ws = null;
  let mode = 'echo';

  const badges = {echo:'#0a3069', broadcast:'#2d1f00', ticker:'#0d2d0d'};
  const badgeText = {echo:'echo', broadcast:'broadcast', ticker:'ticker'};

  function switchMode(m, el) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    mode = m;
    const b = document.getElementById('mode-badge');
    b.textContent = badgeText[m];
    b.style.background = badges[m];
    if (ws) { disconnect(); }
  }

  function connect() {
    if (ws) ws.close();
    const path = `/ws/${mode}`;
    const url  = `ws://${location.host}${path}`;
    log(`Connecting to ${url}…`, 'sys');
    ws = new WebSocket(url);

    ws.onopen  = () => { setStatus(true); log('Connected ✓', 'sys'); };
    ws.onclose = () => { setStatus(false); log('Disconnected', 'sys'); ws = null; };
    ws.onerror = ()  => log('WebSocket error', 'err');
    ws.onmessage = e => {
      try { log(JSON.stringify(JSON.parse(e.data), null, 2), 'recv'); }
      catch { log(e.data, 'recv'); }
    };
  }

  function disconnect() {
    if (ws) ws.close();
  }

  function send() {
    const input = document.getElementById('msg');
    const text  = input.value.trim();
    if (!text || !ws || ws.readyState !== WebSocket.OPEN) return;
    ws.send(text);
    log(text, 'sent');
    input.value = '';
  }

  function log(text, cls) {
    const box = document.getElementById('log');
    const el  = document.createElement('div');
    el.className = 'msg ' + (cls || '');
    const ts = new Date().toLocaleTimeString();
    el.textContent = `[${ts}] ${text}`;
    box.appendChild(el);
    box.scrollTop = box.scrollHeight;
  }

  function clearLog() { document.getElementById('log').innerHTML = ''; }

  function setStatus(connected) {
    const el = document.getElementById('status');
    el.textContent  = connected ? 'Connected' : 'Disconnected';
    el.className    = 'status ' + (connected ? 'connected' : 'disconnected');
  }
</script>
</body>
</html>
