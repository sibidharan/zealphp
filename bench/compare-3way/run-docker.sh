#!/usr/bin/env bash
# Docker-adapted 3-way bench: ZealPHP vs raw Node vs Express
set -uo pipefail

ROOT="/app"
OUT="/tmp/bench/results-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$OUT"

ZEAL_PORT=18080
RAW_PORT=18081
EXPRESS_PORT=18082
PATHX="/raw/bench"
WORKERS=4
CONNECTIONS=200
DURATION=10
ITERATIONS=10
GAP=20
AUTOCANNON="node /tmp/node_modules/autocannon/autocannon.js"

ZEAL_PID=""
RAW_PID=""
EXPRESS_PID=""

cleanup() {
  echo
  echo "Cleaning up..."
  [ -n "$ZEAL_PID" ]    && kill -TERM -- -$ZEAL_PID 2>/dev/null
  [ -n "$RAW_PID" ]     && kill -TERM -- -$RAW_PID 2>/dev/null
  [ -n "$EXPRESS_PID" ] && kill -TERM -- -$EXPRESS_PID 2>/dev/null
  sleep 1
  [ -n "$ZEAL_PID" ]    && kill -KILL -- -$ZEAL_PID 2>/dev/null
  [ -n "$RAW_PID" ]     && kill -KILL -- -$RAW_PID 2>/dev/null
  [ -n "$EXPRESS_PID" ] && kill -KILL -- -$EXPRESS_PID 2>/dev/null
}
trap cleanup EXIT INT TERM

wait_ready() {
  local port="$1"
  for i in $(seq 1 60); do
    curl -fsS --max-time 1 "http://127.0.0.1:$port$PATHX" >/dev/null 2>&1 && return 0
    sleep 0.3
  done
  return 1
}

echo "Starting ZealPHP on :$ZEAL_PORT ($WORKERS workers)..."
(
  cd "$ROOT"
  setsid env ZEALPHP_HOST=127.0.0.1 ZEALPHP_PORT=$ZEAL_PORT ZEALPHP_WORKERS=$WORKERS \
    ZEALPHP_TASK_WORKERS=0 ZEALPHP_BENCH_MODE=1 ZEALPHP_LOG_ASYNC=1 \
    ZEALPHP_DEBUG_LOG=0 ZEALPHP_ACCESS_LOG=0 ZEALPHP_LOG_DIR=/tmp/zealphp \
    ZEALPHP_PID_FILE=/tmp/zealphp/bench_zeal_$ZEAL_PORT.pid \
    php app.php >"$OUT/zeal.log" 2>&1 &
  echo $! > /tmp/bench/zeal.pgid
) &
sleep 0.3
ZEAL_PID=$(cat /tmp/bench/zeal.pgid)
wait_ready $ZEAL_PORT || { echo "ZealPHP failed to start" >&2; cat "$OUT/zeal.log"; exit 1; }

echo "Starting raw Node on :$RAW_PORT ($WORKERS workers)..."
setsid env NODE_WORKERS=$WORKERS NODE_PORT=$RAW_PORT node /tmp/bench/node_raw.js \
  >"$OUT/raw.log" 2>&1 &
RAW_PID=$!
wait_ready $RAW_PORT || { echo "Raw Node failed to start" >&2; cat "$OUT/raw.log"; exit 1; }

echo "Starting Express on :$EXPRESS_PORT ($WORKERS workers)..."
setsid env NODE_WORKERS=$WORKERS NODE_PORT=$EXPRESS_PORT node /tmp/bench/node_express.js \
  >"$OUT/express.log" 2>&1 &
EXPRESS_PID=$!
wait_ready $EXPRESS_PORT || { echo "Express failed to start" >&2; cat "$OUT/express.log"; exit 1; }

echo
echo "All three servers ready."
echo "Path: $PATHX | workers/stack: $WORKERS | wrk-equivalent: c=$CONNECTIONS, d=${DURATION}s, iterations=$ITERATIONS, gap=${GAP}s between"
echo

CSV="$OUT/samples.csv"
echo "iteration,stack,req_per_sec,avg_latency_ms,p50_ms,p90_ms,p99_ms,total_2xx" > "$CSV"

bench_one() {
  local stack="$1" port="$2" iter="$3"
  local raw_file="$OUT/iter${iter}_${stack}.json"
  $AUTOCANNON -c $CONNECTIONS -d $DURATION --json --no-progress \
    "http://127.0.0.1:$port$PATHX" > "$raw_file" 2>/dev/null

  node -e "
    const r = JSON.parse(require('fs').readFileSync('$raw_file', 'utf8'));
    const rps = r.requests.average;
    const lat = r.latency;
    const ok = r['2xx'] || 0;
    process.stdout.write(\`\${rps.toFixed(0)},\${lat.average.toFixed(3)},\${lat.p50.toFixed(3)},\${lat.p90.toFixed(3)},\${lat.p99.toFixed(3)},\${ok}\`);
  "
}

printf '%-4s %-10s %12s %10s %10s %10s %10s %10s\n' "#" "stack" "req/s" "avg_ms" "p50_ms" "p90_ms" "p99_ms" "2xx"

for i in $(seq 1 $ITERATIONS); do
  for stack in zeal raw express; do
    case $stack in
      zeal)    port=$ZEAL_PORT ;;
      raw)     port=$RAW_PORT ;;
      express) port=$EXPRESS_PORT ;;
    esac

    # 1s warmup
    $AUTOCANNON -c 100 -d 1 "http://127.0.0.1:$port$PATHX" >/dev/null 2>&1 || true

    line=$(bench_one "$stack" "$port" "$i")
    echo "$i,$stack,$line" >> "$CSV"

    IFS=',' read -r rps avg p50 p90 p99 ok <<< "$line"
    printf '%-4d %-10s %12s %10s %10s %10s %10s %10s\n' \
      "$i" "$stack" "$rps" "$avg" "$p50" "$p90" "$p99" "$ok"
  done
  if [ $i -lt $ITERATIONS ]; then
    sleep $GAP
  fi
done

echo
echo "=== Aggregate (mean ± stddev across $ITERATIONS samples) ==="
node -e "
const fs = require('fs');
const lines = fs.readFileSync('$CSV', 'utf8').trim().split('\n').slice(1);
const by = {};
for (const l of lines) {
  const [it, stack, rps, avg, p50, p90, p99, ok] = l.split(',');
  if (!by[stack]) by[stack] = { rps: [], avg: [], p50: [], p90: [], p99: [] };
  by[stack].rps.push(+rps);
  by[stack].avg.push(+avg);
  by[stack].p50.push(+p50);
  by[stack].p90.push(+p90);
  by[stack].p99.push(+p99);
}
function stat(arr) {
  const n = arr.length;
  const mean = arr.reduce((a,b)=>a+b,0)/n;
  const sd = Math.sqrt(arr.reduce((a,b)=>a+(b-mean)**2,0)/n);
  return { mean, sd };
}
console.log('');
console.log('Stack       req/s mean   req/s sd    avg lat (ms)  p50    p90    p99');
console.log('----------- ------------ ----------- ------------- ------ ------ ------');
for (const stack of ['zeal','raw','express']) {
  const s = by[stack];
  if (!s) continue;
  const r = stat(s.rps), a = stat(s.avg), p50 = stat(s.p50), p90 = stat(s.p90), p99 = stat(s.p99);
  console.log(\`\${stack.padEnd(11)} \${r.mean.toFixed(0).padStart(12)} \${r.sd.toFixed(0).padStart(11)} \${a.mean.toFixed(2).padStart(13)} \${p50.mean.toFixed(2).padStart(6)} \${p90.mean.toFixed(2).padStart(6)} \${p99.mean.toFixed(2).padStart(6)}\`);
}
console.log('');
const zeal = by.zeal ? stat(by.zeal.rps).mean : 0;
const raw  = by.raw ? stat(by.raw.rps).mean : 0;
const exp  = by.express ? stat(by.express.rps).mean : 0;
if (zeal && raw)  console.log(\`ZealPHP vs raw Node:  \${(zeal/raw).toFixed(2)}x\`);
if (zeal && exp)  console.log(\`ZealPHP vs Express:   \${(zeal/exp).toFixed(2)}x\`);
if (raw && exp)   console.log(\`Raw Node vs Express:  \${(raw/exp).toFixed(2)}x\`);
"

echo
echo "Output dir: $OUT"
echo "CSV: $CSV"

# Copy CSV to mounted results dir if available
if [ -d /app/bench/compare-3way/results ]; then
  cp "$CSV" /app/bench/compare-3way/results/samples-docker-$(date +%Y%m%d-%H%M%S).csv
  echo "CSV copied to /app/bench/compare-3way/results/"
fi
