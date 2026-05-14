#!/usr/bin/env bash
set -uo pipefail
#
# Full isolated bench: each stack runs ALONE with full CPU/RAM.
# Matches homepage table: text, JSON, template across 4 stacks.
# Uses ab (Apache Bench) for consistency with PERF.md methodology.
#

ROOT="/app"
OUT="/tmp/bench/results-full-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$OUT" /tmp/zealphp /tmp/express-bench-sessions

WORKERS=4
CONCURRENCY=200
REQUESTS=50000
PORT=18080

die() { echo "ERROR: $*" >&2; exit 1; }

echo "============================================================"
echo "  ZealPHP Full Isolated Benchmark"
echo "  $(nproc) cores | ${WORKERS} workers | c=${CONCURRENCY} | n=${REQUESTS}"
echo "  $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "============================================================"
echo ""

# --- Create Express server with full middleware (matches bench_vs_express.sh) ---
cat > /tmp/_bench_express.js << 'EXPRESSJS'
const cluster = require('cluster');
const WORKERS = Number.parseInt(process.env.NODE_WORKERS || 4, 10);
const PORT = Number.parseInt(process.env.NODE_PORT || 18080, 10);
if (cluster.isPrimary) {
    for (let i = 0; i < WORKERS; i++) cluster.fork();
    cluster.on('exit', () => cluster.fork());
} else {
    const express = require('/tmp/node_modules/express');
    const cors = require('/tmp/node_modules/cors');
    const session = require('/tmp/node_modules/express-session');
    const FileStore = require('/tmp/node_modules/session-file-store')(session);
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
$w = (int)($argv[1] ?? 4); $p = (int)($argv[2] ?? 18080);
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
const P = Number.parseInt(process.env.NODE_PORT || 18080, 10);
if (cluster.isPrimary) { for (let i = 0; i < W; i++) cluster.fork(); cluster.on('exit', () => cluster.fork()); }
else { http.createServer((req, res) => {
    const u = req.url.split('?')[0];
    if (u === '/raw/bench') { res.writeHead(200, {'Content-Type':'text/plain','Content-Length':20,'Connection':'keep-alive'}); res.end('You requested: bench'); }
    else if (u === '/json') { const b = JSON.stringify({t:Date.now(),id:Math.random().toString(36).slice(2)}); res.writeHead(200, {'Content-Type':'application/json','Content-Length':Buffer.byteLength(b),'Connection':'keep-alive'}); res.end(b); }
    else { res.writeHead(404); res.end('Not Found'); }
}).listen(P); }
NODEJS

wait_ready() {
  local port="$1" path="$2"
  for i in $(seq 1 30); do
    curl -fsS --max-time 1 "http://127.0.0.1:$port$path" >/dev/null 2>&1 && return 0
    sleep 0.3
  done
  return 1
}

run_ab() {
  local label="$1" url="$2" outfile="$3"
  # Warmup
  ab -n 5000 -c 100 -k -l "$url" > /dev/null 2>&1 || true
  sleep 1
  # Actual run
  ab -n "$REQUESTS" -c "$CONCURRENCY" -k -l "$url" 2>&1 | tee "$outfile"
}

extract_rps() {
  grep "Requests per second" "$1" | awk '{printf "%.0f", $4}'
}

extract_latency() {
  grep "Time per request.*\(mean\)" "$1" | head -1 | awk '{printf "%.2f", $4}'
}

extract_p50() {
  grep "^ *50%" "$1" | awk '{print $2}'
}
extract_p90() {
  grep "^ *90%" "$1" | awk '{print $2}'
}
extract_p99() {
  grep "^ *99%" "$1" | awk '{print $2}'
}
extract_failed() {
  grep "Failed requests" "$1" | awk '{print $3}'
}

CSV="$OUT/full-results.csv"
echo "stack,endpoint,req_per_sec,mean_latency_ms,p50_ms,p90_ms,p99_ms,failed" > "$CSV"

parse_and_log() {
  local stack="$1" endpoint="$2" file="$3"
  local rps lat p50 p90 p99 failed
  rps=$(extract_rps "$file")
  lat=$(extract_latency "$file")
  p50=$(extract_p50 "$file")
  p90=$(extract_p90 "$file")
  p99=$(extract_p99 "$file")
  failed=$(extract_failed "$file")
  echo "$stack,$endpoint,$rps,$lat,$p50,$p90,$p99,$failed" >> "$CSV"
  printf "  %-30s %8s req/s  avg %6s ms  p50 %4s  p90 %4s  p99 %4s  fail %s\n" \
    "$stack ($endpoint)" "$rps" "$lat" "$p50" "$p90" "$p99" "$failed"
}

# ===================================================================
# 1. OpenSwoole raw — alone
# ===================================================================
echo ">>> [1/4] OpenSwoole raw (no framework) — full machine"
php /tmp/_bench_swoole.php $WORKERS $PORT &
SPID=$!
wait_ready $PORT "/raw/bench" || die "OpenSwoole failed to start"

run_ab "swoole-text" "http://127.0.0.1:$PORT/raw/bench" "$OUT/swoole-text.txt" > /dev/null
parse_and_log "openswoole_raw" "text" "$OUT/swoole-text.txt"

run_ab "swoole-json" "http://127.0.0.1:$PORT/json" "$OUT/swoole-json.txt" > /dev/null
parse_and_log "openswoole_raw" "json" "$OUT/swoole-json.txt"

kill $SPID 2>/dev/null; wait $SPID 2>/dev/null || true
sleep 2
echo ""

# ===================================================================
# 2. Node.js raw — alone
# ===================================================================
echo ">>> [2/4] Node.js raw http (no framework) — full machine"
NODE_WORKERS=$WORKERS NODE_PORT=$PORT node /tmp/_bench_node.js &
NPID=$!
wait_ready $PORT "/raw/bench" || die "Node.js failed to start"

run_ab "node-text" "http://127.0.0.1:$PORT/raw/bench" "$OUT/node-text.txt" > /dev/null
parse_and_log "node_raw" "text" "$OUT/node-text.txt"

run_ab "node-json" "http://127.0.0.1:$PORT/json" "$OUT/node-json.txt" > /dev/null
parse_and_log "node_raw" "json" "$OUT/node-json.txt"

kill $NPID 2>/dev/null; wait $NPID 2>/dev/null || true
sleep 2
echo ""

# ===================================================================
# 3. ZealPHP full stack — alone
# ===================================================================
echo ">>> [3/4] ZealPHP full PSR-15 middleware stack — full machine"
cd "$ROOT"
ZEALPHP_HOST=127.0.0.1 ZEALPHP_PORT=$PORT ZEALPHP_WORKERS=$WORKERS \
  ZEALPHP_TASK_WORKERS=0 ZEALPHP_BENCH_MODE=1 ZEALPHP_LOG_ASYNC=1 \
  ZEALPHP_DEBUG_LOG=0 ZEALPHP_ACCESS_LOG=0 ZEALPHP_LOG_DIR=/tmp/zealphp \
  ZEALPHP_PID_FILE=/tmp/zealphp/bench_zeal.pid \
  php app.php &
ZPID=$!
wait_ready $PORT "/raw/bench" || die "ZealPHP failed to start"

run_ab "zeal-text" "http://127.0.0.1:$PORT/raw/bench" "$OUT/zeal-text.txt" > /dev/null
parse_and_log "zealphp" "text" "$OUT/zeal-text.txt"

run_ab "zeal-json" "http://127.0.0.1:$PORT/json" "$OUT/zeal-json.txt" > /dev/null
parse_and_log "zealphp" "json" "$OUT/zeal-json.txt"

run_ab "zeal-template" "http://127.0.0.1:$PORT/bench/template" "$OUT/zeal-template.txt" > /dev/null
parse_and_log "zealphp" "template" "$OUT/zeal-template.txt"

kill $ZPID 2>/dev/null; wait $ZPID 2>/dev/null || true
sleep 2
echo ""

# ===================================================================
# 4. Express.js full stack — alone
# ===================================================================
echo ">>> [4/4] Express.js full middleware stack — full machine"
NODE_WORKERS=$WORKERS NODE_PORT=$PORT node /tmp/_bench_express.js &
EPID=$!
wait_ready $PORT "/raw/bench" || die "Express failed to start"

run_ab "express-text" "http://127.0.0.1:$PORT/raw/bench" "$OUT/express-text.txt" > /dev/null
parse_and_log "express" "text" "$OUT/express-text.txt"

run_ab "express-json" "http://127.0.0.1:$PORT/json" "$OUT/express-json.txt" > /dev/null
parse_and_log "express" "json" "$OUT/express-json.txt"

run_ab "express-template" "http://127.0.0.1:$PORT/template" "$OUT/express-template.txt" > /dev/null
parse_and_log "express" "template" "$OUT/express-template.txt"

kill $EPID 2>/dev/null; wait $EPID 2>/dev/null || true
echo ""

# ===================================================================
# Summary
# ===================================================================
echo "============================================================"
echo "  RESULTS SUMMARY"
echo "============================================================"
echo ""
printf "%-30s %10s %10s %10s\n" "Stack" "Text" "JSON" "Template"
printf "%-30s %10s %10s %10s\n" "-----" "----" "----" "--------"

for stack in openswoole_raw node_raw zealphp express; do
  txt=$(grep "^${stack},text," "$CSV" | cut -d, -f3)
  json=$(grep "^${stack},json," "$CSV" | cut -d, -f3)
  tmpl=$(grep "^${stack},template," "$CSV" | cut -d, -f3)
  [ -z "$tmpl" ] && tmpl="—"
  printf "%-30s %10s %10s %10s\n" "$stack" "$txt" "$json" "$tmpl"
done

echo ""

# Compute ratios
z_txt=$(grep "^zealphp,text," "$CSV" | cut -d, -f3)
z_json=$(grep "^zealphp,json," "$CSV" | cut -d, -f3)
z_tmpl=$(grep "^zealphp,template," "$CSV" | cut -d, -f3)
e_txt=$(grep "^express,text," "$CSV" | cut -d, -f3)
e_json=$(grep "^express,json," "$CSV" | cut -d, -f3)
e_tmpl=$(grep "^express,template," "$CSV" | cut -d, -f3)

if [ -n "$z_txt" ] && [ -n "$e_txt" ] && [ "$e_txt" -gt 0 ] 2>/dev/null; then
  echo "ZealPHP vs Express:"
  echo "  text:     +$(( (z_txt - e_txt) * 100 / e_txt ))%  ($z_txt vs $e_txt)"
  echo "  json:     +$(( (z_json - e_json) * 100 / e_json ))%  ($z_json vs $e_json)"
  echo "  template: +$(( (z_tmpl - e_tmpl) * 100 / e_tmpl ))%  ($z_tmpl vs $e_tmpl)"
fi

echo ""
echo "CSV: $CSV"
echo "Raw ab output: $OUT/"

# Copy results to mounted volume
if [ -d /app/bench/compare-3way/results ]; then
  cp "$CSV" "/app/bench/compare-3way/results/full-$(date +%Y%m%d-%H%M%S).csv"
  echo "CSV copied to /app/bench/compare-3way/results/"
fi
