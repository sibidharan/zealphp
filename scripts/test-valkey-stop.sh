#!/usr/bin/env bash
# Stop the test-only valkey-server (no-op if not running).
set -euo pipefail
PID="/tmp/zealphp-test-valkey/valkey.pid"
if [ -f "$PID" ] && kill -0 "$(cat "$PID")" 2>/dev/null; then
  kill "$(cat "$PID")" 2>/dev/null || true
  rm -f "$PID"
  echo "valkey stopped"
else
  echo "valkey not running"
fi
