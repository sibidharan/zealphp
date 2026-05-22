// Page-scoped script for /websocket — relocated from the inline <script> block.
// Functions are declared at top level so the inline on* handlers in the markup
// (onclick="wsConnect()", onchange="wsUpdateHint()", etc.) still resolve them globally.

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
      // Clear the log (equivalent to the original innerHTML = '')
      document.getElementById('ws-log').replaceChildren();
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
