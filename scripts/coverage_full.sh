#!/usr/bin/env bash
# Full coverage = unit tests (in-process) + integration tests (server process).
#
# In-process unit tests can't reach the long-running OpenSwoole event loop
# (OnRequest, routing, middleware, session managers, WebSocket, CLI). The
# integration suite exercises all of it over HTTP, but in a SEPARATE process,
# so the phpunit coverage driver never sees it. This instruments the server too
# (app.php's gated ZEALPHP_COVERAGE_DIR hook, dumping on App::onWorkerStop) and
# merges both into one report — the true total coverage.
#
# Requires a pcov build (PCOV_SO env, or the default local build path).
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
PCOV="${PCOV_SO:-/tmp/pcov-build/pcov-1.0.12/modules/pcov.so}"
[ -f "$PCOV" ] || { echo "pcov.so not found at $PCOV (set PCOV_SO)"; exit 1; }

COVDIR="${COVDIR:-/tmp/zealphp_cov}"
# Dedicated coverage port (override with COV_PORT). Must be free — the script
# does NOT kill other servers (dev server, other instances stay untouched).
PORT="${COV_PORT:-8093}"
rm -rf "$COVDIR"; mkdir -p "$COVDIR"

if curl -sf -m1 "http://127.0.0.1:$PORT/" >/dev/null 2>&1; then
    echo "port :$PORT is busy — set COV_PORT to a free port"; exit 1
fi

echo "== 1/5 unit coverage (in-process) =="
php -d extension="$PCOV" -d pcov.enabled=1 -d pcov.directory=src \
    vendor/bin/phpunit tests/Unit/ --coverage-php "$COVDIR/unit.cov" >/dev/null 2>&1

echo "== 2/5 (using dedicated coverage port :$PORT — other servers untouched) =="
rm -f "/tmp/zealphp/zealphp_${PORT}.pid" 2>/dev/null || true

echo "== 3/5 start instrumented server on :$PORT (1 worker, sync logging) =="
# ZEALPHP_WORKERS=1: one .cov, deterministic. LOG_ASYNC=0 + logs off: avoid the
# coroutine-channel logging path that fatals during the shutdown we rely on.
ZEALPHP_COVERAGE_DIR="$COVDIR" ZEALPHP_WORKERS=1 ZEALPHP_TASK_WORKERS=0 ZEALPHP_PORT="$PORT" \
    ZEALPHP_LOG_ASYNC=0 ZEALPHP_ACCESS_LOG=0 ZEALPHP_DEBUG_LOG=0 ZEALPHP_RECYCLE_LOG=0 \
    php -d extension="$PCOV" -d pcov.enabled=1 -d pcov.directory=src app.php \
    >"$COVDIR/server.log" 2>&1 &
SRV=$!
up=no
for i in $(seq 1 60); do
    if curl -sf -m1 "http://127.0.0.1:$PORT/json" >/dev/null 2>&1; then up=yes; break; fi
    sleep 0.5
done
echo "   server up: $up"
[ "$up" = yes ] || { echo "   server failed to start; log:"; tail -15 "$COVDIR/server.log"; kill -9 "$SRV" 2>/dev/null; exit 1; }

echo "== 4/5 integration tests against instrumented server =="
ZEALPHP_TEST_PORT="$PORT" php vendor/bin/phpunit tests/Integration/ 2>&1 | tail -3 || true

echo "== graceful shutdown (workerStop dumps .cov) =="
kill -TERM "$SRV" 2>/dev/null || true
# wait for the worker to flush its .cov
for i in $(seq 1 30); do
    ls "$COVDIR"/server-*.cov >/dev/null 2>&1 && break
    sleep 0.5
done
wait "$SRV" 2>/dev/null || true

echo "== 5/5 merge =="
ls -1 "$COVDIR"/*.cov 2>/dev/null | sed 's#.*/#   #'
php scripts/merge_coverage.php "$COVDIR"
echo "clover: $COVDIR/clover.xml"
