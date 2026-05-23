#!/usr/bin/env bash
# Start an isolated valkey-server for ZealPHP test runs.
# Lives at /tmp/zealphp-test-valkey, port 16379 (override via $ZEALPHP_TEST_VALKEY_PORT).
set -euo pipefail
PORT="${ZEALPHP_TEST_VALKEY_PORT:-16379}"
DATA="/tmp/zealphp-test-valkey"
PID="$DATA/valkey.pid"
mkdir -p "$DATA"

if [ -f "$PID" ] && kill -0 "$(cat "$PID")" 2>/dev/null; then
  echo "valkey already up on $PORT (pid $(cat "$PID"))"
  exit 0
fi

valkey-server \
  --port "$PORT" \
  --daemonize yes \
  --pidfile "$PID" \
  --dir "$DATA" \
  --save "" \
  --appendonly no \
  --logfile "$DATA/valkey.log"

for i in 1 2 3 4 5 6 7 8 9 10; do
  if valkey-cli -p "$PORT" ping >/dev/null 2>&1; then
    echo "valkey up on port $PORT (pid $(cat "$PID"))"
    exit 0
  fi
  sleep 0.2
done
echo "valkey failed to start (check $DATA/valkey.log)" >&2
exit 1
