#!/bin/bash
# Cross-mode isolation matrix — boots probe-server.php under each lifecycle mode
# and runs the concurrent isolation driver against it. Standalone / ad-hoc
# companion to the committed PHPUnit tests:
#   tests/Integration/CoroutineIsolationContractTest.php
#   tests/Integration/TrustBarIsolationTest.php
#
# Requires: PHP with OpenSwoole + the local ext-zealphp build.
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
SO="${ZEALPHP_SO:-$REPO/ext/zealphp/modules/zealphp.so}"
PHP="${PHP_BIN:-$(command -v php)}"
N="${N:-40}"

[ -f "$SO" ] || { echo "ext-zealphp .so not found at $SO (set ZEALPHP_SO). Build it: cd ext/zealphp && phpize && ./configure && make"; exit 1; }
"$PHP" -m 2>/dev/null | grep -qi openswoole || { echo "OpenSwoole not loaded in $PHP"; exit 1; }

PORT=9820
echo "Cross-mode isolation matrix ($N concurrent interleaved requests per mode)"
echo "ext: $SO"
for MODE in coroutine-legacy coroutine mixed legacy-cgi; do
  PORT=$((PORT+1))
  echo; echo "════ MODE=$MODE (port $PORT) ════"
  MODE="$MODE" PORT="$PORT" "$PHP" -d extension="$SO" -d opcache.enable_cli=0 \
    "$REPO/scripts/isolation/probe-server.php" >/tmp/iso-$MODE.log 2>&1 &
  SRV=$!
  up=0
  for t in $(seq 1 40); do
    curl -s -o /dev/null --max-time 1 "http://127.0.0.1:$PORT/ping" 2>/dev/null && { up=1; break; }
    kill -0 $SRV 2>/dev/null || break
    sleep 0.2
  done
  if [ "$up" = "1" ]; then
    sleep 0.3
    "$PHP" "$REPO/scripts/isolation/concurrent-driver.php" "$PORT" "$N" 2>/dev/null
  else
    echo "  BOOT FAILED (see /tmp/iso-$MODE.log)"; grep -aiE "fatal|uncaught|runtime" /tmp/iso-$MODE.log | head -2 | sed 's/^/    /'
  fi
  kill -9 $SRV 2>/dev/null; wait $SRV 2>/dev/null
done
echo
echo "Contract = request-state (superglobals, response, class statics, \$GLOBALS,"
echo "constants, ini, bootstrap, putenv) must be ISOLATED. fn_static is ALSO"
echo "isolated in coroutine-legacy here (Stage 5 — App::coroutineStaticsIsolation(true));"
echo "with Stage 5 off it leaks by design."
