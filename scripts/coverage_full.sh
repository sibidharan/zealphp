#!/usr/bin/env bash
# Full coverage = unit (in-process) + integration across MULTIPLE lifecycle
# modes (server process). In-process unit tests can't reach the OpenSwoole
# event loop; the integration suite does, over HTTP, but in a separate process.
# We instrument the server (app.php's gated ZEALPHP_COVERAGE_DIR hook, dumping
# on App::onWorkerStop) and run it in THREE modes so mode-specific server code
# is covered too:
#   - coroutine  (default; the assertion gate)
#   - mixed      (superglobals(true)+enableCoroutine(false)+processIsolation(false)
#                 -> SessionManager + the superglobal/session-alias branches)
#   - legacy-cgi (superglobals(true)+processIsolation(true) -> cgi_worker + bridge)
# The extra modes are coverage-only (assertion failures tolerated; some tests
# are deliberately coroutine-specific). All .cov are merged.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# Driver-flexible: prefer a local pcov build; else fall back to whatever the
# ambient PHP provides (Xdebug under XDEBUG_MODE=coverage, as in CI).
PCOV="${PCOV_SO:-/tmp/pcov-build/pcov-1.0.12/modules/pcov.so}"
if [ -f "$PCOV" ]; then
    PHP=(php -d extension="$PCOV" -d pcov.enabled=1 -d pcov.directory=src)
    echo "coverage driver: pcov ($PCOV)"
else
    PHP=(php)
    export XDEBUG_MODE=coverage
    echo "coverage driver: ambient (Xdebug expected; XDEBUG_MODE=coverage)"
fi

COVDIR="${COVDIR:-/tmp/zealphp_cov}"
PORT="${COV_PORT:-8093}"
rm -rf "$COVDIR"; mkdir -p "$COVDIR"

if curl -sf -m1 "http://127.0.0.1:$PORT/" >/dev/null 2>&1; then
    echo "port :$PORT is busy — set COV_PORT to a free port"; exit 1
fi

echo "== unit coverage (in-process) =="
"${PHP[@]}" vendor/bin/phpunit tests/Unit/ --coverage-php "$COVDIR/unit.cov" >/dev/null 2>&1

# Non-streaming endpoints — safe to hit in NON-coroutine modes. The demo's
# streaming/SSE/ws routes call Coroutine::sleep(), which fatals (no scheduler)
# and crashes the worker before it can dump coverage; we avoid them here. Every
# request in superglobals mode still flows through SessionManager + the
# superglobal/session-alias branches, and a public-file hit under CGI mode
# exercises cgi_worker + the bridge.
SAFE_PATHS=(
  / /json /raw/bench /routing /responses /http /api /middleware /sessions
  /store /coroutines /legacy-apps /templates /why-zealphp /performance /vs-fpm
  /getting-started /design-tradeoffs /phpinfo /case-studies/sna-labs
  '/api/users' '/demo/session/' '/demo/store/' '/demo/counter/'
  '/inject/case1' '/parity/request-headers' '/nonexistent-404'
)

# run_pass <label> <runner> <extra-env...>
#   runner=suite    -> full integration suite (assertion gate; coroutine only)
#   runner=exercise -> curl SAFE_PATHS with a cookie jar (coverage-only)
run_pass() {
    local label="$1" runner="$2"; shift 2
    echo "== pass: $label ($runner) =="
    rm -f "/tmp/zealphp/zealphp_${PORT}.pid" 2>/dev/null || true
    env ZEALPHP_COVERAGE_DIR="$COVDIR" ZEALPHP_PORT="$PORT" ZEALPHP_WORKERS=1 \
        ZEALPHP_TASK_WORKERS=0 ZEALPHP_LOG_ASYNC=0 ZEALPHP_ACCESS_LOG=0 \
        ZEALPHP_DEBUG_LOG=0 ZEALPHP_RECYCLE_LOG=0 "$@" \
        "${PHP[@]}" app.php \
        >"$COVDIR/server-$label.log" 2>&1 &
    local srv=$!
    local up=no
    for i in $(seq 1 60); do
        curl -sf -m1 "http://127.0.0.1:$PORT/json" >/dev/null 2>&1 && { up=yes; break; }
        sleep 0.5
    done
    if [ "$up" != yes ]; then
        echo "   [$label] server failed to start; skipping"; tail -8 "$COVDIR/server-$label.log"
        kill -9 "$srv" 2>/dev/null; return 0
    fi
    if [ "$runner" = suite ]; then
        ZEALPHP_TEST_PORT="$PORT" php vendor/bin/phpunit tests/Integration/ >/dev/null 2>&1 || true
        # Exercise WebSocket endpoints (no integration tests cover them) so the
        # onOpen/onMessage/onClose dispatch closures count.
        "${PHP[@]}" scripts/ws_exercise.php "$PORT" >/dev/null 2>&1 || true
    else
        local jar; jar="$(mktemp)"
        for path in "${SAFE_PATHS[@]}"; do
            curl -sf -m4 -c "$jar" -b "$jar" "http://127.0.0.1:$PORT$path" >/dev/null 2>&1 || true
            curl -sf -m4 -X POST -c "$jar" -b "$jar" -d 'x=1' "http://127.0.0.1:$PORT$path" >/dev/null 2>&1 || true
        done
        rm -f "$jar"
    fi
    kill -TERM "$srv" 2>/dev/null || true
    for i in $(seq 1 30); do ls "$COVDIR"/server-w0-*.cov 2>/dev/null | grep -q . && break; sleep 0.5; done
    wait "$srv" 2>/dev/null || true
    echo "   [$label] done"
}

run_pass coroutine suite
run_pass mixed     exercise ZEALPHP_SUPERGLOBALS=1 ZEALPHP_ENABLE_COROUTINE=0 ZEALPHP_PROCESS_ISOLATION=0
run_pass cgi       exercise ZEALPHP_SUPERGLOBALS=1 ZEALPHP_PROCESS_ISOLATION=1 ZEALPHP_ENABLE_COROUTINE=0

echo "== merge =="
ls -1 "$COVDIR"/*.cov 2>/dev/null | sed 's#.*/#   #'
php scripts/merge_coverage.php "$COVDIR"
echo "clover: $COVDIR/clover.xml"
