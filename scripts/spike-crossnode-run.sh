#!/usr/bin/env bash
# Drive the Phase 3 cross-host pub/sub spike — boot two ZealPHP servers
# on different ports against one valkey, exchange PUBLISHes through it,
# verify deliveries via the per-server log files.

set -euo pipefail

REDIS_URL="${ZEALPHP_REDIS_URL:-redis://127.0.0.1:16379/0}"
LOG_DIR="/tmp/zealphp-spike-crossnode"
SERVER_SCRIPT="$(cd "$(dirname "$0")" && pwd)/spike-crossnode-server.php"

PORT_A=8090
PORT_B=8091

rm -rf "$LOG_DIR" && mkdir -p "$LOG_DIR"
echo "log dir: $LOG_DIR"

cleanup() {
  for p in "$PID_A" "$PID_B"; do
    if [ -n "${p:-}" ] && kill -0 "$p" 2>/dev/null; then kill "$p" 2>/dev/null || true; fi
  done
  sleep 0.3
  for p in "$PID_A" "$PID_B"; do
    if [ -n "${p:-}" ] && kill -0 "$p" 2>/dev/null; then kill -9 "$p" 2>/dev/null || true; fi
  done
}
trap cleanup EXIT

# Boot server A on :8090
SERVER_ID=A PORT=$PORT_A SPIKE_LOG_DIR=$LOG_DIR ZEALPHP_REDIS_URL="$REDIS_URL" \
  php "$SERVER_SCRIPT" >"$LOG_DIR/A.stdout" 2>&1 &
PID_A=$!
echo "server A pid=$PID_A → :$PORT_A"

# Boot server B on :8091
SERVER_ID=B PORT=$PORT_B SPIKE_LOG_DIR=$LOG_DIR ZEALPHP_REDIS_URL="$REDIS_URL" \
  php "$SERVER_SCRIPT" >"$LOG_DIR/B.stdout" 2>&1 &
PID_B=$!
echo "server B pid=$PID_B → :$PORT_B"

# Wait until both /id endpoints return 200
for port in $PORT_A $PORT_B; do
  for i in {1..40}; do
    if curl -fsS "http://127.0.0.1:$port/id" >/dev/null 2>&1; then echo "server on :$port up"; break; fi
    sleep 0.1
    if [ "$i" -eq 40 ]; then
      echo "server on :$port FAILED to come up"
      tail -20 "$LOG_DIR/${port}.stdout" || true
      exit 1
    fi
  done
done

# Wait for both subscribers to register (one extra tick)
sleep 0.4

echo
echo "--- /id sanity ---"
curl -s http://127.0.0.1:$PORT_A/id
echo
curl -s http://127.0.0.1:$PORT_B/id
echo
echo

echo "--- A → B publish ---"
curl -s "http://127.0.0.1:$PORT_A/publish?to=B&msg=hi-from-A"
echo

echo "--- B → A publish ---"
curl -s "http://127.0.0.1:$PORT_B/publish?to=A&msg=hi-from-B"
echo

echo "--- B → B (self) publish, sanity ---"
curl -s "http://127.0.0.1:$PORT_B/publish?to=B&msg=self-test"
echo

# Give the subscribers a beat
sleep 0.3

echo
echo "================ server A log ================"
cat "$LOG_DIR/A.log" || true
echo "================ server B log ================"
cat "$LOG_DIR/B.log" || true

echo
echo "--- assertions ---"
fail=0

# A's log must have a 'hi-from-B' receipt (cross-node B→A)
if grep -q "received on ws:server:A:.*hi-from-B" "$LOG_DIR/A.log"; then
  echo "[PASS] A received B's message"
else
  echo "[FAIL] A did NOT receive B's message"; fail=1
fi

# B's log must have a 'hi-from-A' receipt (cross-node A→B)
if grep -q "received on ws:server:B:.*hi-from-A" "$LOG_DIR/B.log"; then
  echo "[PASS] B received A's message"
else
  echo "[FAIL] B did NOT receive A's message"; fail=1
fi

# B's log must have the self-test (intra-node B→B)
if grep -q "received on ws:server:B:.*self-test" "$LOG_DIR/B.log"; then
  echo "[PASS] B received its own self-test message"
else
  echo "[FAIL] B did NOT receive its own self-test message"; fail=1
fi

# Publisher reported >0 receivers for each cross-server send
if grep -q "receivers=1" "$LOG_DIR/A.log" && grep -q "receivers=1" "$LOG_DIR/B.log"; then
  echo "[PASS] publish receiver counts >0 on both sides"
else
  echo "[FAIL] publish receiver counts wrong"; fail=1
fi

exit "$fail"
