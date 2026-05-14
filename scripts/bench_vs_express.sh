#!/usr/bin/env bash
set -euo pipefail
#
# ZealPHP vs Express.js — Fair Benchmark
#
# Both run with equivalent middleware:
#   ZealPHP:  CORS + ETag + sessions + PSR-7 + routing + templates
#   Express:  cors + etag + express-session + file-store + ejs + json
#
# Usage:
#   scripts/bench_vs_express.sh
#   WORKERS=8 CONCURRENCY=500 REQUESTS=100000 scripts/bench_vs_express.sh
#

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKERS="${WORKERS:-4}"
CONCURRENCY="${CONCURRENCY:-200}"
REQUESTS="${REQUESTS:-50000}"
DURATION="${DURATION:-}"
ZEAL_PORT="${ZEAL_PORT:-18080}"
EXPRESS_PORT="${EXPRESS_PORT:-18084}"
SWOOLE_PORT="${SWOOLE_PORT:-18083}"
NODE_PORT="${NODE_PORT:-18081}"

die() { echo "ERROR: $*" >&2; exit 1; }
have() { command -v "$1" >/dev/null 2>&1; }

have ab || die "Apache Bench (ab) not found. Install: apt install apache2-utils"
have php || die "PHP not found"
have node || die "Node.js not found"

cleanup() {
    echo ""
    echo "Cleaning up..."
    kill $ZEAL_PID $SWOOLE_PID $NODE_PID $EXPRESS_PID 2>/dev/null || true
    wait 2>/dev/null || true
}
trap cleanup EXIT

echo "============================================================"
echo "  ZealPHP vs Express.js — Fair Benchmark"
echo "  ${WORKERS} workers | c=${CONCURRENCY} | n=${REQUESTS} | keep-alive"
echo "============================================================"
echo ""

# --- Install Express deps if needed ---
if [ ! -d /tmp/node_modules/express ]; then
    echo "Installing Express dependencies..."
    (cd /tmp && npm install express cors express-session session-file-store ejs >/dev/null 2>&1)
fi

# --- Create Express server ---
cat > /tmp/_bench_express.js << 'EXPRESSJS'
const cluster = require('cluster');
const WORKERS = Number.parseInt(process.env.NODE_WORKERS || 4, 10);
const PORT = Number.parseInt(process.env.NODE_PORT || 18084, 10);
if (cluster.isPrimary) {
    for (let i = 0; i < WORKERS; i++) cluster.fork();
    cluster.on('exit', () => cluster.fork());
} else {
    const express = require('express');
    const cors = require('cors');
    const session = require('express-session');
    const FileStore = require('session-file-store')(session);
    const app = express();
    app.set('view engine', 'ejs');
    app.set('views', '/tmp/_bench_views');
    app.use(cors());
    app.set('etag', 'weak');
    app.use(express.json());
    app.use(session({
        store: new FileStore({ path: '/tmp/express-bench-sessions', ttl: 86400, retries: 0, logFn: () => {} }),
        secret: 'bench', resave: false, saveUninitialized: false,
    }));
    app.get('/raw/bench', (req, res) => res.type('text/plain').send('You requested: bench'));
    app.get('/json', (req, res) => res.json({ t: Date.now(), id: Math.random().toString(36).slice(2) }));
    app.get('/template', (req, res) => res.render('page', {
        title: 'Benchmark', items: [
            { name: 'Routing', desc: 'Flask-style' }, { name: 'Streaming', desc: 'SSR yield' },
            { name: 'WebSocket', desc: 'Built-in' }, { name: 'Store', desc: 'Shared mem' },
            { name: 'Coroutines', desc: 'go()+Channel' },
        ]
    }));
    app.listen(PORT);
}
EXPRESSJS

# --- Create EJS template ---
mkdir -p /tmp/_bench_views
cat > /tmp/_bench_views/page.ejs << 'EJS'
<!doctype html><html><head><title><%= title %></title></head><body>
<h1><%= title %></h1><ul><% items.forEach(function(i) { %>
<li><strong><%= i.name %></strong> — <%= i.desc %></li><% }); %></ul>
<p><%= new Date().toISOString() %></p></body></html>
EJS

# --- Create OpenSwoole raw server ---
cat > /tmp/_bench_swoole.php << 'SWOOLE'
<?php
$w = (int)($argv[1] ?? 4); $p = (int)($argv[2] ?? 18083);
$s = new OpenSwoole\HTTP\Server('0.0.0.0', $p);
$s->set(['worker_num' => $w, 'log_level' => SWOOLE_LOG_ERROR]);
$s->on('request', function($req, $res) {
    $uri = $req->server['request_uri'] ?? '/';
    if ($uri === '/raw/bench') { $res->header('Content-Type','text/plain'); $res->end('You requested: bench'); }
    elseif ($uri === '/json') { $res->header('Content-Type','application/json'); $res->end(json_encode(['t'=>microtime(true),'id'=>bin2hex(random_bytes(7))])); }
    else { $res->status(404); $res->end('Not Found'); }
});
$s->start();
SWOOLE

# --- Create Node raw server ---
cat > /tmp/_bench_node.js << 'NODEJS'
const cluster = require('cluster'), http = require('http');
const W = Number.parseInt(process.env.NODE_WORKERS || 4, 10);
const P = Number.parseInt(process.env.NODE_PORT || 18081, 10);
if (cluster.isPrimary) { for (let i = 0; i < W; i++) cluster.fork(); cluster.on('exit', () => cluster.fork()); }
else { http.createServer((req, res) => {
    const u = req.url.split('?')[0];
    if (u === '/raw/bench') { res.writeHead(200, {'Content-Type':'text/plain','Content-Length':20,'Connection':'keep-alive'}); res.end('You requested: bench'); }
    else if (u === '/json') { const b = JSON.stringify({t:Date.now(),id:Math.random().toString(36).slice(2)}); res.writeHead(200, {'Content-Type':'application/json','Content-Length':Buffer.byteLength(b),'Connection':'keep-alive'}); res.end(b); }
    else { res.writeHead(404); res.end('Not Found'); }
}).listen(P); }
NODEJS

# --- Start all servers ---
echo "Starting servers..."

ZEALPHP_WORKERS=$WORKERS ZEALPHP_TASK_WORKERS=0 ZEALPHP_ACCESS_LOG=0 ZEALPHP_PORT=$ZEAL_PORT \
    php "$ROOT_DIR/app.php" &
ZEAL_PID=$!

php /tmp/_bench_swoole.php $WORKERS $SWOOLE_PORT &
SWOOLE_PID=$!

NODE_WORKERS=$WORKERS NODE_PORT=$NODE_PORT node /tmp/_bench_node.js &
NODE_PID=$!

NODE_WORKERS=$WORKERS NODE_PORT=$EXPRESS_PORT node /tmp/_bench_express.js &
EXPRESS_PID=$!

sleep 4

# Verify
for name_port in "ZealPHP:$ZEAL_PORT" "OpenSwoole:$SWOOLE_PORT" "Node.js:$NODE_PORT" "Express:$EXPRESS_PORT"; do
    name="${name_port%%:*}"; port="${name_port##*:}"
    code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/raw/bench" 2>/dev/null || echo "000")
    [ "$code" = "200" ] || die "$name not responding on :$port"
done
echo "All servers ready."
echo ""

run_bench() {
    local label="$1" url="$2"
    ab -n "$REQUESTS" -c "$CONCURRENCY" -k "$url" 2>&1 | grep "Requests per second" | awk -v l="$label" '{printf "  %-28s %s\n", l, $0}'
}

# Warmup
for port in $ZEAL_PORT $SWOOLE_PORT $NODE_PORT $EXPRESS_PORT; do
    ab -n 5000 -c 100 -k "http://127.0.0.1:$port/raw/bench" > /dev/null 2>&1
    ab -n 5000 -c 100 -k "http://127.0.0.1:$port/json" > /dev/null 2>&1
done
ab -n 5000 -c 100 -k "http://127.0.0.1:$ZEAL_PORT/bench/template" > /dev/null 2>&1
ab -n 5000 -c 100 -k "http://127.0.0.1:$EXPRESS_PORT/template" > /dev/null 2>&1

echo "--- RAW TEXT ---"
run_bench "OpenSwoole raw"    "http://127.0.0.1:$SWOOLE_PORT/raw/bench"
run_bench "Node.js raw"       "http://127.0.0.1:$NODE_PORT/raw/bench"
run_bench "ZealPHP (full MW)" "http://127.0.0.1:$ZEAL_PORT/raw/bench"
run_bench "Express (full MW)" "http://127.0.0.1:$EXPRESS_PORT/raw/bench"

echo ""
echo "--- JSON API ---"
run_bench "OpenSwoole raw"    "http://127.0.0.1:$SWOOLE_PORT/json"
run_bench "Node.js raw"       "http://127.0.0.1:$NODE_PORT/json"
run_bench "ZealPHP (full MW)" "http://127.0.0.1:$ZEAL_PORT/json"
run_bench "Express (full MW)" "http://127.0.0.1:$EXPRESS_PORT/json"

echo ""
echo "--- TEMPLATE RENDERING ---"
run_bench "ZealPHP (full MW)" "http://127.0.0.1:$ZEAL_PORT/bench/template"
run_bench "Express+EJS (full)" "http://127.0.0.1:$EXPRESS_PORT/template"

echo ""
echo "Done. Servers will be stopped automatically."
