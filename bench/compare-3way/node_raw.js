// Raw Node.js HTTP server — matches /raw/bench and /json endpoints from ZealPHP demo
const http = require('http');
const cluster = require('cluster');

const WORKERS = parseInt(process.env.NODE_WORKERS || '4', 10);
const PORT    = parseInt(process.env.NODE_PORT    || '18081', 10);

function respond(res, status, type, body) {
  res.writeHead(status, {
    'Content-Type':   type,
    'Content-Length': Buffer.byteLength(body),
    'Connection':     'keep-alive',
  });
  res.end(body);
}

if (cluster.isPrimary) {
  console.log(`Raw Node on :${PORT} with ${WORKERS} workers`);
  for (let i = 0; i < WORKERS; i++) cluster.fork();
  cluster.on('exit', () => cluster.fork());
} else {
  http.createServer((req, res) => {
    const url = req.url.split('?')[0];
    if (url.startsWith('/raw/')) {
      const rest = url.slice(5);
      return respond(res, 200, 'text/plain', `You requested: ${rest}`);
    }
    if (url === '/json') {
      return respond(res, 200, 'application/json',
        JSON.stringify({ __start_time: Date.now(), UNIQUE_REQUEST_ID: Math.random().toString(36).slice(2) }));
    }
    respond(res, 404, 'text/plain', 'not found');
  }).listen(PORT);
}
